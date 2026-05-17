<?php
/**
 * SB_Meta — direct access to the {prefix}postmeta table, bypassing the WP layer.
 *
 * Why: WP functions update_post_meta() / add_post_meta() / delete_post_meta()
 * invoke wp_unslash() and apply the sanitize_meta_$key filter. For large JSON
 * payloads (e.g. `_breakdance_data` ≈ 500 KB with `\uXXXX` escapes) this strips
 * one backslash layer and silently corrupts the stored bytes.
 *
 * These helpers go straight through $wpdb->update / insert / delete — no
 * unslash, no sanitize, no maybe_serialize.
 *
 * WARNING: by using these you assume responsibility for data validity. Use only
 * for trusted API inputs (HMAC already verified them) and only where the WP layer
 * is documented to corrupt data.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SB_Meta {

	/**
	 * Write a meta value "as is": UPDATE if the key already exists (single instance),
	 * INSERT otherwise. If the post has multiple values under this key, all are
	 * removed and a single new one is inserted.
	 */
	public static function set( $post_id, $key, $value ) {
		global $wpdb;
		$rows = $wpdb->get_col( $wpdb->prepare(
			"SELECT meta_id FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s",
			$post_id, $key
		) );
		if ( count( $rows ) > 1 ) {
			// clean up the duplicates
			$ids = implode( ',', array_map( 'intval', $rows ) );
			$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_id IN ($ids)" );
			$rows = [];
		}
		if ( ! empty( $rows ) ) {
			$wpdb->update(
				$wpdb->postmeta,
				[ 'meta_value' => $value ],
				[ 'meta_id'    => (int) $rows[0] ],
				[ '%s' ],
				[ '%d' ]
			);
		} else {
			$wpdb->insert(
				$wpdb->postmeta,
				[
					'post_id'    => (int) $post_id,
					'meta_key'   => (string) $key,
					'meta_value' => $value,
				],
				[ '%d', '%s', '%s' ]
			);
		}
	}

	/** Insert a new meta row (without removing existing). For multi-value meta. */
	public static function insert( $post_id, $key, $value ) {
		global $wpdb;
		$wpdb->insert(
			$wpdb->postmeta,
			[
				'post_id'    => (int) $post_id,
				'meta_key'   => (string) $key,
				'meta_value' => $value,
			],
			[ '%d', '%s', '%s' ]
		);
	}

	/** Delete all values of a specific key for a post. */
	public static function delete( $post_id, $key ) {
		global $wpdb;
		$wpdb->delete(
			$wpdb->postmeta,
			[ 'post_id' => (int) $post_id, 'meta_key' => (string) $key ],
			[ '%d', '%s' ]
		);
	}

	/** Delete ALL meta fields of a post. Used by restore_backup. */
	public static function delete_all_for_post( $post_id ) {
		global $wpdb;
		$wpdb->delete(
			$wpdb->postmeta,
			[ 'post_id' => (int) $post_id ],
			[ '%d' ]
		);
	}

	/**
	 * Trigger rebuild of cached data for every known builder after a post's content changes.
	 *
	 * Removes meta caches and physical CSS files for:
	 *  - Breakdance (`_breakdance_css_file_paths_cache`, `_breakdance_dependency_cache`,
	 *    wp-content/uploads/breakdance/css/post-{id}.css)
	 *  - Elementor (`_elementor_css`, wp-content/uploads/elementor/css/post-{id}.css)
	 *  - WPBakery / VC (`_wpb_shortcodes_custom_css`)
	 *
	 * Also invalidates the post cache in WP, WP Rocket per-post, and LiteSpeed per-post.
	 *
	 * Safe to call on any site: if a builder isn't in use, the operations are
	 * silent no-ops (delete on missing meta, unlink on missing files).
	 */
	public static function invalidate_builder_caches( $post_id ) {
		// — Breakdance —
		self::delete( $post_id, '_breakdance_css_file_paths_cache' );
		self::delete( $post_id, '_breakdance_dependency_cache' );
		$bd_dir = WP_CONTENT_DIR . '/uploads/breakdance/css/';
		if ( is_dir( $bd_dir ) ) {
			foreach ( [ "post-{$post_id}.css", "post-{$post_id}-defaults.css" ] as $fname ) {
				$full = $bd_dir . $fname;
				if ( is_file( $full ) ) @unlink( $full );
			}
		}

		// — Elementor —
		self::delete( $post_id, '_elementor_css' );
		$el_dir = WP_CONTENT_DIR . '/uploads/elementor/css/';
		if ( is_dir( $el_dir ) ) {
			$el_file = $el_dir . "post-{$post_id}.css";
			if ( is_file( $el_file ) ) @unlink( $el_file );
		}

		// — WPBakery / Visual Composer —
		self::delete( $post_id, '_wpb_shortcodes_custom_css' );
		self::delete( $post_id, '_wpb_post_custom_css' );

		// Standard WP cache invalidation
		clean_post_cache( $post_id );

		// WP Rocket per-post
		if ( function_exists( 'rocket_clean_post' ) ) {
			rocket_clean_post( $post_id );
		}

		// LiteSpeed Cache per-post
		if ( has_action( 'litespeed_purge_post' ) ) {
			do_action( 'litespeed_purge_post', $post_id );
		}
	}

	/**
	 * Backward-compatible alias (function used to be named this way).
	 * @deprecated 1.0.2 Use invalidate_builder_caches() instead.
	 */
	public static function invalidate_breakdance_caches( $post_id ) {
		self::invalidate_builder_caches( $post_id );
	}
}
