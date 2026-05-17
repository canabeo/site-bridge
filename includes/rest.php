<?php
/**
 * SB_REST — registration of all REST routes under the sb/v1 namespace.
 *
 * Every route uses permission_callback = [SB_Auth, 'check'] (HMAC).
 * After the callback runs, the audit logger is invoked.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SB_REST {

	public static function register_routes() {
		$auth = [ 'SB_Auth', 'check' ];

		// === System ===
		register_rest_route( SITE_BRIDGE_REST_NAMESPACE, '/ping', [
			'methods'             => 'GET',
			'permission_callback' => $auth,
			'callback'            => [ 'SB_System_Controller', 'ping' ],
		] );

		register_rest_route( SITE_BRIDGE_REST_NAMESPACE, '/info', [
			'methods'             => 'GET',
			'permission_callback' => $auth,
			'callback'            => [ 'SB_System_Controller', 'info' ],
		] );

		register_rest_route( SITE_BRIDGE_REST_NAMESPACE, '/audit-log', [
			'methods'             => 'GET',
			'permission_callback' => $auth,
			'callback'            => [ 'SB_System_Controller', 'audit_log' ],
		] );

		register_rest_route( SITE_BRIDGE_REST_NAMESPACE, '/error-log', [
			'methods'             => 'GET',
			'permission_callback' => $auth,
			'callback'            => [ 'SB_System_Controller', 'error_log' ],
		] );

		// === Pages ===
		register_rest_route( SITE_BRIDGE_REST_NAMESPACE, '/pages', [
			'methods'             => 'GET',
			'permission_callback' => $auth,
			'callback'            => [ 'SB_Pages_Controller', 'list_pages' ],
		] );

		register_rest_route( SITE_BRIDGE_REST_NAMESPACE, '/pages/(?P<id>\d+)', [
			[
				'methods'             => 'GET',
				'permission_callback' => $auth,
				'callback'            => [ 'SB_Pages_Controller', 'get_page' ],
			],
			[
				'methods'             => 'PATCH',
				'permission_callback' => $auth,
				'callback'            => [ 'SB_Pages_Controller', 'update_page' ],
			],
		] );

		// === Blocks (Gutenberg) ===
		register_rest_route( SITE_BRIDGE_REST_NAMESPACE, '/pages/(?P<id>\d+)/blocks', [
			[
				'methods'             => 'GET',
				'permission_callback' => $auth,
				'callback'            => [ 'SB_Blocks_Controller', 'get_blocks' ],
			],
			[
				'methods'             => 'PUT',
				'permission_callback' => $auth,
				'callback'            => [ 'SB_Blocks_Controller', 'put_blocks' ],
			],
		] );

		// === Backups ===
		register_rest_route( SITE_BRIDGE_REST_NAMESPACE, '/pages/(?P<id>\d+)/backup', [
			'methods'             => 'POST',
			'permission_callback' => $auth,
			'callback'            => [ 'SB_Backups_Controller', 'create_backup' ],
		] );

		register_rest_route( SITE_BRIDGE_REST_NAMESPACE, '/pages/(?P<id>\d+)/backups', [
			'methods'             => 'GET',
			'permission_callback' => $auth,
			'callback'            => [ 'SB_Backups_Controller', 'list_backups' ],
		] );

		register_rest_route( SITE_BRIDGE_REST_NAMESPACE, '/pages/(?P<id>\d+)/restore/(?P<backup_id>\d+)', [
			'methods'             => 'POST',
			'permission_callback' => $auth,
			'callback'            => [ 'SB_Backups_Controller', 'restore_backup' ],
		] );

		// === Plugins ===
		register_rest_route( SITE_BRIDGE_REST_NAMESPACE, '/plugins', [
			'methods'             => 'GET',
			'permission_callback' => $auth,
			'callback'            => [ 'SB_Plugins_Controller', 'list_plugins' ],
		] );

		register_rest_route( SITE_BRIDGE_REST_NAMESPACE, '/plugins/upload', [
			'methods'             => 'POST',
			'permission_callback' => $auth,
			'callback'            => [ 'SB_Plugins_Controller', 'upload_plugin' ],
		] );

		register_rest_route( SITE_BRIDGE_REST_NAMESPACE, '/plugins/(?P<slug>[^/]+)/activate', [
			'methods'             => 'POST',
			'permission_callback' => $auth,
			'callback'            => [ 'SB_Plugins_Controller', 'activate_plugin' ],
		] );

		register_rest_route( SITE_BRIDGE_REST_NAMESPACE, '/plugins/(?P<slug>[^/]+)/deactivate', [
			'methods'             => 'POST',
			'permission_callback' => $auth,
			'callback'            => [ 'SB_Plugins_Controller', 'deactivate_plugin' ],
		] );

		register_rest_route( SITE_BRIDGE_REST_NAMESPACE, '/plugins/(?P<slug>[^/]+)', [
			'methods'             => 'DELETE',
			'permission_callback' => $auth,
			'callback'            => [ 'SB_Plugins_Controller', 'delete_plugin' ],
		] );

		// === Files ===
		register_rest_route( SITE_BRIDGE_REST_NAMESPACE, '/files', [
			[
				'methods'             => 'GET',
				'permission_callback' => $auth,
				'callback'            => [ 'SB_Files_Controller', 'read_file' ],
			],
			[
				'methods'             => 'PUT',
				'permission_callback' => $auth,
				'callback'            => [ 'SB_Files_Controller', 'write_file' ],
			],
			[
				'methods'             => 'DELETE',
				'permission_callback' => $auth,
				'callback'            => [ 'SB_Files_Controller', 'delete_file' ],
			],
		] );

		register_rest_route( SITE_BRIDGE_REST_NAMESPACE, '/files/list', [
			'methods'             => 'GET',
			'permission_callback' => $auth,
			'callback'            => [ 'SB_Files_Controller', 'list_directory' ],
		] );

		// === Cache ===
		register_rest_route( SITE_BRIDGE_REST_NAMESPACE, '/cache/purge', [
			'methods'             => 'POST',
			'permission_callback' => $auth,
			'callback'            => [ 'SB_Cache_Controller', 'purge' ],
		] );

		// === Forms (optional integration with custom-forms-sms plugin) ===
		register_rest_route( SITE_BRIDGE_REST_NAMESPACE, '/forms', [
			'methods'             => 'GET',
			'permission_callback' => $auth,
			'callback'            => [ 'SB_Forms_Controller', 'list_forms' ],
		] );

		register_rest_route( SITE_BRIDGE_REST_NAMESPACE, '/forms/submissions', [
			'methods'             => 'GET',
			'permission_callback' => $auth,
			'callback'            => [ 'SB_Forms_Controller', 'list_submissions' ],
		] );

		register_rest_route( SITE_BRIDGE_REST_NAMESPACE, '/forms/submissions/(?P<id>\d+)', [
			'methods'             => 'GET',
			'permission_callback' => $auth,
			'callback'            => [ 'SB_Forms_Controller', 'get_submission' ],
		] );

		// === Audit-log writer on every response ===
		add_filter( 'rest_post_dispatch', [ __CLASS__, 'maybe_log_response' ], 99, 3 );
		add_filter( 'rest_pre_dispatch',  [ __CLASS__, 'mark_request_start' ], 1, 3 );
	}

	public static function mark_request_start( $result, $server, $request ) {
		if ( strpos( $request->get_route(), '/' . SITE_BRIDGE_REST_NAMESPACE ) === 0 ) {
			SB_Audit::mark_request_start();
		}
		return $result;
	}

	public static function maybe_log_response( $response, $server, $request ) {
		if ( strpos( $request->get_route(), '/' . SITE_BRIDGE_REST_NAMESPACE ) !== 0 ) {
			return $response;
		}
		$status = $response instanceof WP_REST_Response ? $response->get_status() : 200;
		SB_Audit::log_request( $request, $response, $status );
		return $response;
	}
}
