<?php
/**
 * SB_Pages_Controller — операции со страницами WP.
 *
 * Поддерживает работу с любым post_type (по умолчанию 'page'), доступны 'post', 'page',
 * а также любой публичный custom post type. Контент Breakdance хранится в post meta
 * `breakdance_data` — он передаётся через поле `meta` в payload.
 *
 * Перед каждым PATCH автоматически создаётся бэкап в {prefix}sb_page_backups.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SB_Pages_Controller {

	/** Максимум снапшотов на одну страницу — старшие удаляются. */
	const MAX_BACKUPS_PER_PAGE = 20;

	/** Ключи post meta, которые мы возвращаем при чтении (всё что зарегистрировано в WP). */
	private static function readable_post_types() {
		// Все публичные + 'page' и 'post' гарантированно
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

		// Валидация post_type
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
	 * Принимает JSON:
	 * {
	 *   "title":   "...",            // optional
	 *   "slug":    "...",            // optional
	 *   "status":  "publish|draft",  // optional
	 *   "content": "...",            // optional, post_content
	 *   "excerpt": "...",            // optional
	 *   "meta":    { "breakdance_data": "...", "any_other_key": "..." },  // optional
	 *   "skip_backup": false,        // ⚠️ по умолчанию false — авто-бэкап включён
	 *   "notes":   "..."             // комментарий для бэкапа
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

		// 1. Авто-бэкап (если не явно отключён)
		if ( empty( $payload['skip_backup'] ) ) {
			$notes = isset( $payload['notes'] ) ? (string) $payload['notes'] : 'auto-pre-edit';
			self::create_snapshot( $post, 'auto-pre-edit', $notes );
		}

		// 2. Обновление wp_posts
		$post_update = [ 'ID' => $id ];
		$changed = [];
		foreach ( [ 'title', 'slug', 'status', 'content', 'excerpt' ] as $field ) {
			if ( array_key_exists( $field, $payload ) ) {
				$map = [
					'title'   => 'post_title',
					'slug'    => 'post_name',
					'status'  => 'post_status',
					'content' => 'post_content',
					'excerpt' => 'post_excerpt',
				];
				$post_update[ $map[ $field ] ] = $payload[ $field ];
				$changed[] = $field;
			}
		}

		if ( count( $post_update ) > 1 ) {
			$res = wp_update_post( $post_update, true );
			if ( is_wp_error( $res ) ) {
				return SB_Response::internal( 'wp_update_post failed: ' . $res->get_error_message() );
			}
		}

		// 3. Обновление meta — ВСЕГДА через прямой SQL (SB_Meta), минуя WP-layer.
		// Причина: update_post_meta() вызывает wp_unslash(), который снимает один слой эскейпов.
		// Для больших JSON-строк (`_breakdance_data` с \uXXXX) это тихо портит данные.
		$meta_changed       = [];
		$breakdance_touched = false;
		if ( ! empty( $payload['meta'] ) && is_array( $payload['meta'] ) ) {
			foreach ( $payload['meta'] as $key => $value ) {
				$key_clean = sanitize_key( $key );
				if ( $key_clean === '' ) {
					continue;
				}
				// Для массивов/объектов используем maybe_serialize (WP-конвенция хранения).
				// Для строк/чисел — кладём как есть, БЕЗ unslash.
				$store = is_scalar( $value ) || $value === null
					? (string) $value
					: maybe_serialize( $value );
				SB_Meta::set( $id, $key_clean, $store );
				$meta_changed[] = $key_clean;
				if ( strpos( $key_clean, '_breakdance' ) === 0 || strpos( $key_clean, 'breakdance' ) === 0 ) {
					$breakdance_touched = true;
				}
			}
		}

		// 3b. Инвалидация Breakdance-кэшей если меняли что-то из его меты
		if ( $breakdance_touched ) {
			SB_Meta::invalidate_breakdance_caches( $id );
		}

		// 4. Возвращаем обновлённую страницу
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
	 * Создание снапшота — вызывается также из SB_Backups_Controller для ручного бэкапа.
	 */
	public static function create_snapshot( WP_Post $post, $triggered_by, $notes = '' ) {
		global $wpdb;
		$table = $wpdb->prefix . 'sb_page_backups';

		$meta_all = get_post_meta( $post->ID );
		// $meta_all — массив [key => [val, val, ...]]; для single-meta берём первый элемент.
		// Сериализуем как JSON — компактнее и читаемее, чем serialize().
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

		// Чистка старых — оставляем только последние MAX_BACKUPS_PER_PAGE
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
		// Распаковываем single-value meta (значения — массивы по 1 элементу).
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
