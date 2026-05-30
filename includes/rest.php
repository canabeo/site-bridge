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

		// === Snippets (Code Snippets plugin integration) ===
		register_rest_route( SITE_BRIDGE_REST_NAMESPACE, '/snippets', [
			[
				'methods'             => 'GET',
				'permission_callback' => $auth,
				'callback'            => [ 'SB_Snippets_Controller', 'list_snippets' ],
			],
			[
				'methods'             => 'POST',
				'permission_callback' => $auth,
				'callback'            => [ 'SB_Snippets_Controller', 'create_snippet' ],
			],
		] );

		register_rest_route( SITE_BRIDGE_REST_NAMESPACE, '/snippets/(?P<id>\d+)', [
			[
				'methods'             => 'GET',
				'permission_callback' => $auth,
				'callback'            => [ 'SB_Snippets_Controller', 'get_snippet' ],
			],
			[
				'methods'             => 'PATCH',
				'permission_callback' => $auth,
				'callback'            => [ 'SB_Snippets_Controller', 'update_snippet' ],
			],
			[
				'methods'             => 'DELETE',
				'permission_callback' => $auth,
				'callback'            => [ 'SB_Snippets_Controller', 'delete_snippet' ],
			],
		] );

		register_rest_route( SITE_BRIDGE_REST_NAMESPACE, '/snippets/(?P<id>\d+)/activate', [
			'methods'             => 'POST',
			'permission_callback' => $auth,
			'callback'            => [ 'SB_Snippets_Controller', 'activate' ],
		] );

		register_rest_route( SITE_BRIDGE_REST_NAMESPACE, '/snippets/(?P<id>\d+)/deactivate', [
			'methods'             => 'POST',
			'permission_callback' => $auth,
			'callback'            => [ 'SB_Snippets_Controller', 'deactivate' ],
		] );

		// === Audit-log writer on every response ===
		add_filter( 'rest_post_dispatch', [ __CLASS__, 'maybe_log_response' ], 99, 3 );
		add_filter( 'rest_pre_dispatch',  [ __CLASS__, 'mark_request_start' ], 1, 3 );

		// === REST whitelist for /sb/v1/* — overrides plugins that block REST API
		// (Disable REST API, iThemes/Solid Security, WP Hide & Security Enhancer, etc.)
		// by clearing any rest_authentication_errors decision for our namespace. We
		// run at PHP_INT_MAX so we land AFTER any blocker plugin has filtered.
		add_filter( 'rest_authentication_errors', [ __CLASS__, 'allow_sb_namespace' ], PHP_INT_MAX );

		// Some plugins also enforce a 301 redirect via the `rest_pre_serve_request`
		// or `rest_pre_dispatch` filter. Strip those decisions for our namespace as
		// well — same logic, same priority.
		add_filter( 'rest_pre_dispatch', [ __CLASS__, 'clear_sb_pre_dispatch_block' ], PHP_INT_MAX, 3 );
	}

	/**
	 * If the current request is for /sb/v1/*, force the rest_authentication_errors
	 * filter result back to null (= "no opinion") regardless of what an earlier
	 * filter set. The plugin's own SB_Auth::check() runs later as
	 * permission_callback and enforces HMAC, so this is safe.
	 *
	 * @param mixed $result Whatever upstream filter returned (WP_Error|null|true|...)
	 * @return mixed Original $result for non-sb requests, null for sb/v1/*.
	 */
	public static function allow_sb_namespace( $result ) {
		// REST_REQUEST is true and the route is known via current REST request — but
		// at the auth-errors filter stage the request is fully parsed only when called
		// from rest_dispatch. We use the request URI as the cheapest, earliest check.
		if ( self::current_uri_is_sb() ) {
			return null;
		}
		return $result;
	}

	/**
	 * If a blocker plugin returns a non-null value from `rest_pre_dispatch` for our
	 * routes (e.g. a WP_Error or a 301 redirect WP_REST_Response), clear it. We
	 * return null which means "continue normal dispatch" — our permission_callback
	 * then authenticates via HMAC as usual.
	 */
	public static function clear_sb_pre_dispatch_block( $result, $server, $request ) {
		if ( $result !== null && strpos( $request->get_route(), '/' . SITE_BRIDGE_REST_NAMESPACE ) === 0 ) {
			return null;
		}
		return $result;
	}

	/** True iff REQUEST_URI looks like a /wp-json/sb/v1/* path (no trailing-slash assumption). */
	private static function current_uri_is_sb() {
		if ( empty( $_SERVER['REQUEST_URI'] ) ) {
			return false;
		}
		$uri = (string) $_SERVER['REQUEST_URI'];
		// match both pretty (/wp-json/sb/v1/…) and ugly (/?rest_route=/sb/v1/…) forms
		if ( strpos( $uri, '/wp-json/' . SITE_BRIDGE_REST_NAMESPACE ) !== false ) {
			return true;
		}
		if ( strpos( $uri, 'rest_route=/' . SITE_BRIDGE_REST_NAMESPACE ) !== false
		  || strpos( $uri, 'rest_route=%2F' . str_replace( '/', '%2F', SITE_BRIDGE_REST_NAMESPACE ) ) !== false ) {
			return true;
		}
		return false;
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
