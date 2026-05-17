<?php
/**
 * SB_Meta — прямой доступ к {prefix}postmeta минуя WP-layer.
 *
 * Зачем: WP-функции update_post_meta() / add_post_meta() / delete_post_meta() выполняют
 * wp_unslash() и применяют фильтр sanitize_meta_$key. Для больших JSON-строк
 * (`_breakdance_data` ~ 500KB с экранированными \uXXXX) это приводит к потере
 * слэшей и тихой порче данных.
 *
 * Эти helper-функции работают через $wpdb->update / insert / delete напрямую —
 * без unslash, без sanitize, без maybe_serialize.
 *
 * ВНИМАНИЕ: используя эти функции, мы берём на себя ответственность за валидность
 * данных. Применять только для доверенных API-входов (HMAC уже проверил), и только
 * там где WP-layer документировано портит данные.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SB_Meta {

	/**
	 * Запись meta-значения «как есть»: UPDATE если ключ существует (single instance), INSERT иначе.
	 * Если у поста уже несколько значений с этим ключом — удаляет ВСЕ и вставляет одно.
	 */
	public static function set( $post_id, $key, $value ) {
		global $wpdb;
		$rows = $wpdb->get_col( $wpdb->prepare(
			"SELECT meta_id FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s",
			$post_id, $key
		) );
		if ( count( $rows ) > 1 ) {
			// чистим лишние
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

	/** Вставка нового meta-значения (без удаления существующих). Для multi-value meta. */
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

	/** Удаление всех значений конкретного ключа для поста. */
	public static function delete( $post_id, $key ) {
		global $wpdb;
		$wpdb->delete(
			$wpdb->postmeta,
			[ 'post_id' => (int) $post_id, 'meta_key' => (string) $key ],
			[ '%d', '%s' ]
		);
	}

	/** Удаление ВСЕХ meta-полей поста. Используется в restore_backup. */
	public static function delete_all_for_post( $post_id ) {
		global $wpdb;
		$wpdb->delete(
			$wpdb->postmeta,
			[ 'post_id' => (int) $post_id ],
			[ '%d' ]
		);
	}

	/**
	 * Триггер пересборки Breakdance после изменения _breakdance_data.
	 *
	 * Breakdance кэширует pre-rendered CSS в файлах wp-content/uploads/breakdance/css/post-{ID}.css
	 * и хранит их пути в meta `_breakdance_css_file_paths_cache`. После изменения дерева
	 * нужно очистить кэш-meta + дать Breakdance возможность перегенерировать.
	 *
	 * Также инвалидируем post cache в object cache и сторонних кэшах.
	 */
	public static function invalidate_breakdance_caches( $post_id ) {
		// Удалим CSS-кэш meta — Breakdance пересоберёт на следующем рендере
		self::delete( $post_id, '_breakdance_css_file_paths_cache' );
		self::delete( $post_id, '_breakdance_dependency_cache' );

		// Удалим физические CSS-файлы — самое надёжное
		$uploads_dir = WP_CONTENT_DIR . '/uploads/breakdance/css/';
		if ( is_dir( $uploads_dir ) ) {
			foreach ( [ "post-{$post_id}.css", "post-{$post_id}-defaults.css" ] as $fname ) {
				$full = $uploads_dir . $fname;
				if ( is_file( $full ) ) {
					@unlink( $full );
				}
			}
		}

		// Стандартный WP cache invalidation
		clean_post_cache( $post_id );

		// WP Rocket — очистим страницу по URL если плагин активен
		if ( function_exists( 'rocket_clean_post' ) ) {
			rocket_clean_post( $post_id );
		}

		// LiteSpeed Cache
		if ( has_action( 'litespeed_purge_post' ) ) {
			do_action( 'litespeed_purge_post', $post_id );
		}
	}
}
