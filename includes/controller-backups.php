<?php
/**
 * SB_Backups_Controller — manual operations on page snapshots.
 *
 * Auto-snapshots are created in SB_Pages_Controller before every PATCH /pages/{id}.
 * This controller handles manual create, list, and restore.
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

		// Create a pre-restore snapshot so the restore itself can be rolled back
		SB_Pages_Controller::create_snapshot( $post, 'auto-pre-restore', 'pre-restore #' . $backup_id );

		// Write post fields via direct SQL so wp_unslash doesn't corrupt post_content
		// that contains backslashes (Gutenberg block attributes etc.)
		$ok = SB_Post::set_fields_raw( $id, [
			'post_title'   => $backup['title_snapshot'],
			'post_content' => $backup['content_snapshot'],
		] );
		if ( ! $ok ) {
			return SB_Response::internal( 'Direct UPDATE wp_posts failed.' );
		}

		// Restore meta via direct SQL (see SB_Meta for the rationale).
		$meta = json_decode( $backup['meta_snapshot'], true );
		$restored_keys = [];
		if ( is_array( $meta ) ) {
			// Drop all current meta (including keys added AFTER the snapshot was taken)
			SB_Meta::delete_all_for_post( $id );

			// Restore from snapshot — values are written as-is, no unslash, no filters
			foreach ( $meta as $key => $values ) {
				if ( ! is_array( $values ) ) {
					$values = [ $values ];
				}
				foreach ( $values as $v ) {
					// $v is the json_decode'd string — byte-for-byte identical to what was in the DB
					SB_Meta::insert( $id, (string) $key, (string) $v );
				}
				$restored_keys[] = $key;
			}
		}

		// Invalidate all known builder caches after restore — in case stale artifacts
		// from the bad edit are still in their caches
		SB_Meta::invalidate_builder_caches( $id );

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
