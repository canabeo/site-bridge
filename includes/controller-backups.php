<?php
/**
 * SB_Backups_Controller — ручные операции с снапшотами страниц.
 *
 * Авто-снапшоты создаются в SB_Pages_Controller перед каждым PATCH /pages/{id}.
 * Здесь — ручное создание, список и восстановление.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SB_Backups_Controller {

	const TABLE = 'sb_page_backups';

	/** POST /pages/{id}/backup */
	public static function create_backup( WP_REST_Request $request ) {
		$id   = (int) $request->get_param( 'id' );
		$post = get_post( $id );
		if ( ! $post ) {
			return SB_Response::not_found( 'Page' );
		}
		$payload = $request->get_json_params() ?: [];
		$notes   = isset( $payload['notes'] ) ? (string) $payload['notes'] : 'manual';

		$backup_id = SB_Pages_Controller::create_snapshot( $post, 'manual', $notes );

		return SB_Response::ok( [
			'created'    => true,
			'backup_id'  => $backup_id,
			'page_id'    => $id,
			'notes'      => $notes,
		] );
	}

	/** GET /pages/{id}/backups */
	public static function list_backups( WP_REST_Request $request ) {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;
		$id    = (int) $request->get_param( 'id' );

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, page_id, created_at, triggered_by, notes,
				LENGTH(content_snapshot) AS content_size,
				LENGTH(meta_snapshot) AS meta_size,
				title_snapshot
			FROM `$table`
			WHERE page_id = %d
			ORDER BY id DESC",
			$id
		), ARRAY_A );

		return SB_Response::ok( [
			'page_id' => $id,
			'count'   => count( $rows ),
			'items'   => $rows,
		] );
	}

	/** POST /pages/{id}/restore/{backup_id} */
	public static function restore_backup( WP_REST_Request $request ) {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;
		$id        = (int) $request->get_param( 'id' );
		$backup_id = (int) $request->get_param( 'backup_id' );

		$post = get_post( $id );
		if ( ! $post ) {
			return SB_Response::not_found( 'Page' );
		}
		$backup = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM `$table` WHERE id = %d AND page_id = %d", $backup_id, $id
		), ARRAY_A );
		if ( ! $backup ) {
			return SB_Response::not_found( 'Backup' );
		}

		// Создаём pre-restore snapshot — чтобы можно было откатить и сам restore
		SB_Pages_Controller::create_snapshot( $post, 'auto-pre-restore', 'pre-restore #' . $backup_id );

		// Обновляем post-поля
		$res = wp_update_post( [
			'ID'           => $id,
			'post_title'   => $backup['title_snapshot'],
			'post_content' => $backup['content_snapshot'],
		], true );
		if ( is_wp_error( $res ) ) {
			return SB_Response::internal( 'wp_update_post failed: ' . $res->get_error_message() );
		}

		// Восстанавливаем meta через прямой SQL (см. SB_Meta — почему).
		$meta = json_decode( $backup['meta_snapshot'], true );
		$restored_keys = [];
		if ( is_array( $meta ) ) {
			// Удаляем все meta которые есть сейчас (включая добавленные ПОСЛЕ snapshot'a)
			SB_Meta::delete_all_for_post( $id );

			// Восстанавливаем из snapshot — значение пишется как есть, без unslash и фильтров
			foreach ( $meta as $key => $values ) {
				if ( ! is_array( $values ) ) {
					$values = [ $values ];
				}
				foreach ( $values as $v ) {
					// $v — строка из json_decode, точно такой же байт-в-байт, как был в БД
					SB_Meta::insert( $id, (string) $key, (string) $v );
				}
				$restored_keys[] = $key;
			}
		}

		// Инвалидация Breakdance-кэшей после восстановления — на случай если в кэше остались
		// артефакты от испорченных правок
		SB_Meta::invalidate_breakdance_caches( $id );

		clean_post_cache( $id );

		SB_Audit::log_dangerous_op( 'page.restore_backup', [
			'page_id' => $id, 'backup_id' => $backup_id, 'restored_keys' => $restored_keys,
		] );

		return SB_Response::ok( [
			'restored'      => true,
			'page_id'       => $id,
			'backup_id'     => $backup_id,
			'restored_keys' => $restored_keys,
		] );
	}
}
