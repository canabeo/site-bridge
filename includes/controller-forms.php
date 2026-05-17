<?php
/**
 * SB_Forms_Controller — reads data from the optional custom-forms-sms plugin.
 *
 * Reads {prefix}cf_forms and {prefix}cf_submissions tables created by the
 * Custom Forms with SMS plugin. If those tables don't exist, returns an empty
 * result with `available: false`.
 *
 * READ-only operations in v1. Don't delete or edit submissions — they're a
 * historical lead record that should remain as a backup channel in case CRM
 * sync fails.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SB_Forms_Controller {

	private static function tables_exist() {
		global $wpdb;
		$forms = $wpdb->prefix . 'cf_forms';
		$subs  = $wpdb->prefix . 'cf_submissions';
		$ok_f = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $forms ) ) === $forms;
		$ok_s = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $subs  ) ) === $subs;
		return $ok_f && $ok_s;
	}

	public static function list_forms( WP_REST_Request $request ) {
		global $wpdb;
		if ( ! self::tables_exist() ) {
			return SB_Response::ok( [ 'available' => false, 'reason' => 'custom-forms-sms tables not found' ] );
		}
		$table = $wpdb->prefix . 'cf_forms';
		$rows = $wpdb->get_results( "SELECT * FROM `$table` ORDER BY id DESC", ARRAY_A );
		return SB_Response::ok( [ 'available' => true, 'count' => count( $rows ), 'items' => $rows ] );
	}

	public static function list_submissions( WP_REST_Request $request ) {
		global $wpdb;
		if ( ! self::tables_exist() ) {
			return SB_Response::ok( [ 'available' => false, 'reason' => 'custom-forms-sms tables not found' ] );
		}
		$table = $wpdb->prefix . 'cf_submissions';

		$form_id = (int) $request->get_param( 'form_id' );
		$since   = $request->get_param( 'since' );
		$limit   = max( 1, min( 500, (int) ( $request->get_param( 'limit' ) ?: 100 ) ) );
		$offset  = max( 0, (int) ( $request->get_param( 'offset' ) ?: 0 ) );

		$where  = [ '1=1' ];
		$params = [];
		if ( $form_id > 0 ) {
			$where[]  = 'form_id = %d';
			$params[] = $form_id;
		}
		if ( $since ) {
			$where[]  = 'created_at >= %s';
			$params[] = $since;
		}
		$sql = "SELECT * FROM `$table` WHERE " . implode( ' AND ', $where )
			. " ORDER BY id DESC LIMIT %d OFFSET %d";
		$params[] = $limit;
		$params[] = $offset;
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );

		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `$table`" );
		return SB_Response::ok( [
			'available' => true,
			'count'     => count( $rows ),
			'total'     => $total,
			'limit'     => $limit,
			'offset'    => $offset,
			'items'     => $rows,
		] );
	}

	public static function get_submission( WP_REST_Request $request ) {
		global $wpdb;
		if ( ! self::tables_exist() ) {
			return SB_Response::ok( [ 'available' => false, 'reason' => 'custom-forms-sms tables not found' ] );
		}
		$table = $wpdb->prefix . 'cf_submissions';
		$id    = (int) $request->get_param( 'id' );

		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `$table` WHERE id = %d", $id ), ARRAY_A );
		if ( ! $row ) {
			return SB_Response::not_found( 'Submission' );
		}
		return SB_Response::ok( [ 'available' => true, 'item' => $row ] );
	}
}
