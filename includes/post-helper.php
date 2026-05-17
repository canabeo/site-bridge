<?php
/**
 * SB_Post — direct access to the {prefix}posts table, bypassing the WP layer.
 *
 * Why: wp_update_post() invokes wp_unslash() and kses filters, which mutate
 * the stored content. For cases where we need to write exactly the bytes we
 * received (e.g. Gutenberg block HTML with JSON attributes in HTML comments,
 * or large pre-rendered HTML), direct SQL is required.
 *
 * Used by SB_Pages_Controller for content edits. For typical operations
 * (changing only title/status), wp_update_post() is still preferable as it
 * triggers the save_post hook, manages revisions, etc.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SB_Post {

	/**
	 * Direct write of the post_content field in wp_posts. Bypasses wp_unslash and kses.
	 * Also updates post_modified*.
	 *
	 * @param int    $post_id
	 * @param string $content
	 * @return bool
	 */
	public static function set_content_raw( $post_id, $content ) {
		global $wpdb;
		$now_gmt = current_time( 'mysql', true );
		$now     = current_time( 'mysql', false );

		$res = $wpdb->update(
			$wpdb->posts,
			[
				'post_content'      => $content,
				'post_modified'     => $now,
				'post_modified_gmt' => $now_gmt,
			],
			[ 'ID' => (int) $post_id ],
			[ '%s', '%s', '%s' ],
			[ '%d' ]
		);
		clean_post_cache( $post_id );
		return $res !== false;
	}

	/**
	 * Direct write of arbitrary wp_posts fields (title, slug, status, content, excerpt).
	 * Every field is written verbatim, without unslash or filters.
	 *
	 * @param int   $post_id
	 * @param array $fields  ['post_title'=>..., 'post_content'=>..., 'post_name'=>..., 'post_status'=>..., 'post_excerpt'=>...]
	 * @return bool
	 */
	public static function set_fields_raw( $post_id, array $fields ) {
		global $wpdb;
		$allowed = [ 'post_title', 'post_content', 'post_name', 'post_status', 'post_excerpt' ];
		$update  = [];
		$formats = [];
		foreach ( $allowed as $f ) {
			if ( array_key_exists( $f, $fields ) ) {
				$update[ $f ]  = (string) $fields[ $f ];
				$formats[]     = '%s';
			}
		}
		if ( empty( $update ) ) {
			return true;
		}
		$update['post_modified']     = current_time( 'mysql', false );
		$update['post_modified_gmt'] = current_time( 'mysql', true );
		$formats[] = '%s';
		$formats[] = '%s';

		$res = $wpdb->update( $wpdb->posts, $update, [ 'ID' => (int) $post_id ], $formats, [ '%d' ] );
		clean_post_cache( $post_id );
		return $res !== false;
	}
}
