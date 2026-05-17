<?php
/**
 * SB_Post — прямой доступ к {prefix}posts минуя WP-layer.
 *
 * Зачем: wp_update_post() вызывает wp_unslash() и kses-фильтры, которые меняют
 * содержимое. Для случаев когда мы хотим записать точно те байты что передали
 * (например, Gutenberg block HTML с атрибутами в JSON-форме, или большой
 * pre-rendered HTML), нужен прямой SQL.
 *
 * Используется в SB_Pages_Controller для контентной правки. Для большинства
 * обычных операций (изменение title/status) всё ещё корректно работать через
 * wp_update_post() — он триггерит хуки save_post, чистит ревизии и т.д.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SB_Post {

	/**
	 * Прямая запись поля post_content в wp_posts. Минует wp_unslash и kses.
	 * Также обновляет post_modified*.
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
	 * Прямая запись произвольных полей wp_posts (title, slug, status, content, excerpt).
	 * Каждое поле проходит без unslash и фильтров. Только то что переданo.
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
