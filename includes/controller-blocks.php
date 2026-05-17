<?php
/**
 * SB_Blocks_Controller — Gutenberg block-level API.
 *
 *  GET  /pages/{id}/blocks         — распарсенные блоки страницы (parse_blocks)
 *  PUT  /pages/{id}/blocks         — полная замена блоков (serialize_blocks → post_content)
 *
 * Структура одного блока (нативный WP-формат):
 *   {
 *     "blockName": "core/paragraph",                      // null для обычного HTML/freeform
 *     "attrs": {"align": "center", ...},                  // атрибуты блока (JSON)
 *     "innerBlocks": [...],                                // вложенные блоки (рекурсивно)
 *     "innerHTML": "<p>текст</p>",                         // HTML самого блока
 *     "innerContent": ["<p>текст</p>"]                     // массив фрагментов (между inner blocks)
 *   }
 *
 * Работает на сайтах где Gutenberg используется. На Breakdance/Elementor-страницах
 * `post_content` обычно пустой (или содержит fallback) — будет вернётся пустой массив
 * или один блок типа `null` с минимальным content.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SB_Blocks_Controller {

	/** GET /pages/{id}/blocks */
	public static function get_blocks( WP_REST_Request $request ) {
		$id   = (int) $request->get_param( 'id' );
		$post = get_post( $id );
		if ( ! $post ) {
			return SB_Response::not_found( 'Page' );
		}

		// parse_blocks — нативная WP-функция, доступна с WP 5.0+
		$blocks = parse_blocks( $post->post_content );

		// Удалим пустые "freeform" блоки которые parse_blocks вставляет между
		// настоящими блоками (это служебные null-blocks с пробельным content).
		$clean = array_values( array_filter( $blocks, function( $b ) {
			if ( $b['blockName'] !== null ) return true;  // настоящий блок
			$inner = trim( $b['innerHTML'] ?? '' );
			return $inner !== '';                          // не-пустой freeform
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
	 * Замена ПОЛНОГО списка блоков → serialize_blocks() → post_content (direct SQL).
	 * Auto-backup перед записью (если skip_backup !== true).
	 */
	public static function put_blocks( WP_REST_Request $request ) {
		$id   = (int) $request->get_param( 'id' );
		$post = get_post( $id );
		if ( ! $post ) {
			return SB_Response::not_found( 'Page' );
		}
		$payload = $request->get_json_params();
		if ( ! is_array( $payload ) || ! isset( $payload['blocks'] ) || ! is_array( $payload['blocks'] ) ) {
			return SB_Response::validation( 'Body must include "blocks": [...].' );
		}

		// Валидация каждого блока — должен иметь хотя бы blockName или innerHTML
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

		// Сериализация
		$new_content = serialize_blocks( $payload['blocks'] );

		// Прямой SQL UPDATE wp_posts.post_content — минует wp_unslash и kses
		$ok = SB_Post::set_content_raw( $id, $new_content );
		if ( ! $ok ) {
			return SB_Response::internal( 'Direct UPDATE wp_posts failed.' );
		}

		// Инвалидация кэшей всех билдеров — на всякий случай (Breakdance/Elementor on the same site)
		SB_Meta::invalidate_builder_caches( $id );

		// Re-parse чтобы вернуть user'у — он увидит то, что в итоге сохранилось
		$reparsed = parse_blocks( $new_content );

		return SB_Response::ok( [
			'updated'        => true,
			'post_id'        => $id,
			'new_size'       => strlen( $new_content ),
			'blocks_written' => count( $payload['blocks'] ),
			'blocks_parsed'  => count( array_filter( $reparsed, fn( $b ) => $b['blockName'] !== null || trim( $b['innerHTML'] ?? '' ) !== '' ) ),
		] );
	}
}
