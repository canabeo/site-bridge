<?php
/**
 * SB_Pages_Controller — operations on WP posts/pages.
 *
 * Supports any post_type (default 'page'); 'post', 'page' and any public custom
 * post type are accepted. Builder content (Breakdance `_breakdance_data`,
 * Elementor `_elementor_data`) lives in post meta and is passed via the `meta`
 * field of the request payload.
 *
 * An automatic backup is written to {prefix}sb_page_backups before every PATCH.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SB_Pages_Controller {

	/** Maximum snapshots kept per page — older ones are pruned. */
	const MAX_BACKUPS_PER_PAGE = 20;

	/** Post types that may be listed/read by this controller. */
	private static function readable_post_types() {
		// All public types plus 'page' and 'post' guaranteed
		$types = get_post_types( [], 'names' );
		return array_values( array_unique( array_merge( [ 'page', 'post' ], (array) $types ) ) );
	}

	public static function list_pages( WP_REST_Request $request ) {
		$args = [
			'post_type'      => $request->get_param( 'post_type' ) ?: 'page',
			'post_status'    => $request->get_param( 'status' ) ?: 'any',
			's'              => $request->get_param( 'search' ) ?: '',
			'posts_per_page' => min( 100, max( 1, (int) ( $request->get_param( 'per_page' ) ?: 20 ) ) ),
			'paged'          => max( 1, (int) ( $request->get_param( 'page' ) ?: 1 ) ),
			'orderby'        => $request->get_param( 'orderby' ) ?: 'modified',
			'order'          => strtoupper( $request->get_param( 'order' ) ?: 'DESC' ),
		];

		// Validate post_type
		$allowed_types = self::readable_post_types();
		if ( ! in_array( $args['post_type'], $allowed_types, true ) ) {
			return SB_Response::validation( 'Invalid post_type.', [ 'allowed' => $allowed_types ] );
		}

		$q = new WP_Query( $args );
		$out = [];
		foreach ( $q->posts as $p ) {
			$out[] = self::summarize( $p );
		}

		return SB_Response::ok( [
			'count'      => count( $out ),
			'total'      => (int) $q->found_posts,
			'page'       => $args['paged'],
			'per_page'   => $args['posts_per_page'],
			'pages_total'=> (int) $q->max_num_pages,
			'items'      => $out,
		] );
	}

	public static function get_page( WP_REST_Request $request ) {
		$id   = (int) $request->get_param( 'id' );
		$post = get_post( $id );
		if ( ! $post ) {
			return SB_Response::not_found( 'Page' );
		}
		return SB_Response::ok( self::full_post( $post ) );
	}

	/**
	 * PATCH /pages/{id} — partial update.
	 *
	 * Accepts JSON:
	 * {
	 *   "title":   "...",            // optional
	 *   "slug":    "...",            // optional
	 *   "status":  "publish|draft",  // optional
	 *   "content": "...",            // optional, post_content
	 *   "excerpt": "...",            // optional
	 *   "meta":    { "breakdance_data": "...", "any_other_key": "..." },  // optional
	 *   "skip_backup": false,        // default false — auto-backup is enabled
	 *   "notes":   "..."             // comment recorded with the backup
	 * }
	 */
	public static function update_page( WP_REST_Request $request ) {
		$id   = (int) $request->get_param( 'id' );
		$post = get_post( $id );
		if ( ! $post ) {
			return SB_Response::not_found( 'Page' );
		}

		$payload = $request->get_json_params();
		if ( ! is_array( $payload ) ) {
			return SB_Response::validation( 'Body must be a JSON object.' );
		}

		// Figure out what we're actually changing — drives the auto-backup decision
		$post_field_map = [
			'title'   => 'post_title',
			'slug'    => 'post_name',
			'status'  => 'post_status',
			'content' => 'post_content',
			'excerpt' => 'post_excerpt',
		];
		$post_fields_to_write = [];
		foreach ( $post_field_map as $api_key => $db_field ) {
			if ( array_key_exists( $api_key, $payload ) ) {
				$post_fields_to_write[ $db_field ] = $payload[ $api_key ];
			}
		}
		$has_meta_writes = ! empty( $payload['meta'] ) && is_array( $payload['meta'] );
		$has_any_write   = ! empty( $post_fields_to_write ) || $has_meta_writes;

		// 1. Auto-backup — ALWAYS before any write, unless skip_backup === true.
		// (Earlier versions only backed up before meta changes — that was a bug.)
		if ( $has_any_write && empty( $payload['skip_backup'] ) ) {
			$notes = isset( $payload['notes'] ) ? (string) $payload['notes'] : 'auto-pre-edit';
			self::create_snapshot( $post, 'auto-pre-edit', $notes );
		}

		// 2. wp_posts updates — direct SQL via SB_Post, bypassing wp_unslash + kses.
		// Critical for Gutenberg block HTML with JSON attributes in comments
		// (`<!-- wp:image {"id":123,"linkDestination":"..."} -->`) and for any
		// post_content containing backslashes that wp_update_post would otherwise mangle.
		$changed = [];
		if ( ! empty( $post_fields_to_write ) ) {
			$ok = SB_Post::set_fields_raw( $id, $post_fields_to_write );
			if ( ! $ok ) {
				return SB_Response::internal( 'Direct UPDATE wp_posts failed.' );
			}
			foreach ( $post_field_map as $api_key => $db_field ) {
				if ( array_key_exists( $db_field, $post_fields_to_write ) ) {
					$changed[] = $api_key;
				}
			}
		}

		// 3. Meta updates — ALWAYS via direct SQL (SB_Meta), bypassing the WP layer.
		// Reason: update_post_meta() invokes wp_unslash(), which strips one backslash layer.
		// For large JSON-in-meta blobs (`_breakdance_data`, `_elementor_data` with \uXXXX)
		// this silently corrupts the stored bytes.
		$meta_changed   = [];
		$builder_touched = ! empty( $post_fields_to_write['post_content'] );  // post_content changed — could be Gutenberg
		if ( $has_meta_writes ) {
			foreach ( $payload['meta'] as $key => $value ) {
				$key_clean = sanitize_key( $key );
				if ( $key_clean === '' ) {
					continue;
				}
				$store = is_scalar( $value ) || $value === null
					? (string) $value
					: maybe_serialize( $value );
				SB_Meta::set( $id, $key_clean, $store );
				$meta_changed[] = $key_clean;
				// Builder meta keys — Breakdance, Elementor, WPBakery
				if ( strpos( $key_clean, '_breakdance' ) === 0
				  || strpos( $key_clean, 'breakdance' ) === 0
				  || strpos( $key_clean, '_elementor' ) === 0
				  || strpos( $key_clean, '_wpb_' ) === 0 ) {
					$builder_touched = true;
				}
			}
		}

		// 3b. Invalidate builder caches if we touched their meta OR post_content
		if ( $builder_touched ) {
			SB_Meta::invalidate_builder_caches( $id );
		}

		// 4. Return the updated post
		clean_post_cache( $id );
		$updated = get_post( $id );

		return SB_Response::ok( [
			'updated'      => true,
			'changed'      => $changed,
			'meta_changed' => $meta_changed,
			'post'         => self::full_post( $updated ),
		] );
	}

	/**
	 * Create a snapshot — also called from SB_Backups_Controller for manual backups.
	 */
	public static function create_snapshot( WP_Post $post, $triggered_by, $notes = '' ) {
		global $wpdb;
		$table = $wpdb->prefix . 'sb_page_backups';

		$meta_all = get_post_meta( $post->ID );
		// $meta_all has shape [key => [val, val, ...]]; for single-value meta we take the first.
		// We serialize as JSON — more compact and human-readable than PHP serialize().
		$wpdb->insert(
			$table,
			[
				'page_id'          => $post->ID,
				'created_at'       => current_time( 'mysql', true ),
				'triggered_by'     => substr( $triggered_by, 0, 40 ),
				'title_snapshot'   => $post->post_title,
				'content_snapshot' => $post->post_content,
				'meta_snapshot'    => wp_json_encode( $meta_all, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
				'notes'            => substr( $notes, 0, 255 ),
			],
			[ '%d', '%s', '%s', '%s', '%s', '%s', '%s' ]
		);
		$new_id = (int) $wpdb->insert_id;

		// Prune old snapshots — keep only the last MAX_BACKUPS_PER_PAGE
		$rows_to_delete = $wpdb->get_col( $wpdb->prepare(
			"SELECT id FROM `$table` WHERE page_id = %d ORDER BY id DESC LIMIT 1000 OFFSET %d",
			$post->ID, self::MAX_BACKUPS_PER_PAGE
		) );
		if ( ! empty( $rows_to_delete ) ) {
			$ids_in = implode( ',', array_map( 'intval', $rows_to_delete ) );
			$wpdb->query( "DELETE FROM `$table` WHERE id IN ($ids_in)" );
		}

		return $new_id;
	}

	// === Helpers ===

	private static function summarize( WP_Post $p ) {
		return [
			'id'         => $p->ID,
			'title'      => $p->post_title,
			'slug'       => $p->post_name,
			'status'     => $p->post_status,
			'type'       => $p->post_type,
			'link'       => get_permalink( $p ),
			'modified'   => $p->post_modified_gmt,
			'created'    => $p->post_date_gmt,
		];
	}

	private static function full_post( WP_Post $p ) {
		$meta = get_post_meta( $p->ID );
		// Unwrap single-value meta (WP returns values as arrays of length 1).
		$meta_flat = [];
		foreach ( $meta as $key => $values ) {
			$meta_flat[ $key ] = count( $values ) === 1 ? maybe_unserialize( $values[0] ) : array_map( 'maybe_unserialize', $values );
		}
		return [
			'id'         => $p->ID,
			'title'      => $p->post_title,
			'slug'       => $p->post_name,
			'status'     => $p->post_status,
			'type'       => $p->post_type,
			'link'       => get_permalink( $p ),
			'modified'   => $p->post_modified_gmt,
			'created'    => $p->post_date_gmt,
			'author'     => (int) $p->post_author,
			'content'    => $p->post_content,
			'excerpt'    => $p->post_excerpt,
			'meta'       => $meta_flat,
		];
	}
}
