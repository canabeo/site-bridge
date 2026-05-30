<?php
/**
 * SB_Blocks_Controller — Gutenberg block-level API.
 *
 *  GET  /pages/{id}/blocks         — parsed blocks of the page (parse_blocks)
 *  PUT  /pages/{id}/blocks         — full block replacement (serialize_blocks → post_content)
 *
 * Block structure (native WP format):
 *   {
 *     "blockName": "core/paragraph",                      // null for plain HTML/freeform
 *     "attrs": {"align": "center", ...},                  // block attributes (JSON)
 *     "innerBlocks": [...],                                // nested blocks (recursive)
 *     "innerHTML": "<p>text</p>",                          // HTML of the block itself
 *     "innerContent": ["<p>text</p>"]                     // array of fragments (between inner blocks)
 *   }
 *
 * Works on sites using Gutenberg. On Breakdance/Elementor-only pages `post_content`
 * is typically empty (or contains a fallback) — the endpoint returns an empty array
 * or a single freeform block.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SB_Blocks_Controller {

	/** GET /pages/{id}/blocks */
	public static function get_blocks( WP_REST_Request $request ) {
		if ( ! function_exists( 'parse_blocks' ) ) {
			return SB_Response::error(
				'sb_dep_missing',
				'parse_blocks() requires WordPress 5.0+. This endpoint is unavailable.',
				503
			);
		}
		$id   = (int) $request->get_param( 'id' );
		$post = get_post( $id );
		if ( ! $post ) {
			return SB_Response::not_found( 'Page' );
		}

		// parse_blocks — native WP function, available since WP 5.0
		$blocks = parse_blocks( $post->post_content );

		// Drop empty "freeform" blocks that parse_blocks inserts between real blocks
		// (these are auxiliary null-blocks with whitespace-only content).
		$clean = array_values( array_filter( $blocks, function( $b ) {
			if ( $b['blockName'] !== null ) return true;  // real block
			$inner = trim( $b['innerHTML'] ?? '' );
			return $inner !== '';                          // non-empty freeform
		} ) );

		return SB_Response::ok( [
			'post_id'      => $id,
			'count'        => count( $clean ),
			'raw_count'    => count( $blocks ),
			'blocks'       => $clean,
			'has_content'  => trim( $post->post_content ) !== '',
		] );
	}

	/**
	 * PUT /pages/{id}/blocks
	 *
	 * Body JSON:
	 *   {
	 *     "blocks": [ {blockName, attrs, innerBlocks, innerHTML, innerContent}, ... ],
	 *     "skip_backup": false,
	 *     "notes": "..."
	 *   }
	 *
	 * Full block-list replacement → serialize_blocks() → post_content (direct SQL).
	 * Auto-backup before write (unless skip_backup === true).
	 */
	public static function put_blocks( WP_REST_Request $request ) {
		if ( ! function_exists( 'serialize_blocks' ) || ! function_exists( 'parse_blocks' ) ) {
			return SB_Response::error(
				'sb_dep_missing',
				'serialize_blocks() / parse_blocks() require WordPress 5.0+. This endpoint is unavailable.',
				503
			);
		}
		$id   = (int) $request->get_param( 'id' );
		$post = get_post( $id );
		if ( ! $post ) {
			return SB_Response::not_found( 'Page' );
		}
		$payload = $request->get_json_params();
		if ( ! is_array( $payload ) || ! isset( $payload['blocks'] ) || ! is_array( $payload['blocks'] ) ) {
			return SB_Response::validation( 'Body must include "blocks": [...].' );
		}

		// Validate each block — must have at least blockName or innerHTML
		foreach ( $payload['blocks'] as $i => $b ) {
			if ( ! is_array( $b ) ) {
				return SB_Response::validation( "Block #{$i} is not an object." );
			}
			if ( ! array_key_exists( 'blockName', $b ) && ! array_key_exists( 'innerHTML', $b ) ) {
				return SB_Response::validation( "Block #{$i} must have 'blockName' or 'innerHTML'." );
			}
		}

		// Auto-backup
		if ( empty( $payload['skip_backup'] ) ) {
			$notes = isset( $payload['notes'] ) ? (string) $payload['notes'] : 'block-edit';
			SB_Pages_Controller::create_snapshot( $post, 'auto-pre-edit', $notes );
		}

		// Serialize
		$new_content = serialize_blocks( $payload['blocks'] );

		// Direct SQL UPDATE on wp_posts.post_content — bypasses wp_unslash and kses
		$ok = SB_Post::set_content_raw( $id, $new_content );
		if ( ! $ok ) {
			return SB_Response::internal( 'Direct UPDATE wp_posts failed.' );
		}

		// Invalidate all builder caches just in case (Breakdance/Elementor on the same site)
		SB_Meta::invalidate_builder_caches( $id );

		// Re-parse so the caller sees what was actually stored
		$reparsed = parse_blocks( $new_content );

		return SB_Response::ok( [
			'updated'        => true,
			'post_id'        => $id,
			'new_size'       => strlen( $new_content ),
			'blocks_written' => count( $payload['blocks'] ),
			'blocks_parsed'  => count( array_filter( $reparsed, function ( $b ) {
				return $b['blockName'] !== null || trim( isset( $b['innerHTML'] ) ? $b['innerHTML'] : '' ) !== '';
			} ) ),
		] );
	}
}
