<?php
/**
 * SB_System_Controller — health/info/audit-log/error-log.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SB_System_Controller {

	public static function ping( WP_REST_Request $request ) {
		return SB_Response::ok( [
			'status'     => 'ok',
			'plugin'     => 'site-bridge',
			'version'    => SITE_BRIDGE_VERSION,
			'wp_version' => $GLOBALS['wp_version'] ?? null,
			'php_version'=> PHP_VERSION,
			'time_utc'   => gmdate( 'c' ),
			'site_url'   => home_url(),
		] );
	}

	public static function info( WP_REST_Request $request ) {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$active = get_option( 'active_plugins', [] );
		$all    = get_plugins();
		$plugins = [];
		foreach ( $all as $file => $meta ) {
			$plugins[] = [
				'file'    => $file,
				'slug'    => dirname( $file ),
				'name'    => $meta['Name']    ?? '',
				'version' => $meta['Version'] ?? '',
				'active'  => in_array( $file, $active, true ),
			];
		}

		$mu = function_exists( 'get_mu_plugins' ) ? array_keys( get_mu_plugins() ) : [];

		$theme = wp_get_theme();
		return SB_Response::ok( [
			'site_url'      => home_url(),
			'admin_email'   => get_option( 'admin_email' ),
			'language'      => get_locale(),
			'wp_version'    => $GLOBALS['wp_version'] ?? null,
			'php_version'   => PHP_VERSION,
			'php_sapi'      => php_sapi_name(),
			'server'        => $_SERVER['SERVER_SOFTWARE'] ?? null,
			'theme'         => [ 'name' => $theme->get( 'Name' ), 'version' => $theme->get( 'Version' ) ],
			'plugins'       => $plugins,
			'mu_plugins'    => $mu,
			'plugin_self'   => [
				'version'   => SITE_BRIDGE_VERSION,
				'log_level' => SB_Config::get_log_level(),
				'allowed_ips' => SB_Config::get_allowed_ips(),
				'tolerance'  => SB_Config::get_timestamp_tolerance(),
			],
		] );
	}

	public static function audit_log( WP_REST_Request $request ) {
		$rows = SB_Audit::fetch( [
			'limit'       => $request->get_param( 'limit' ),
			'since'       => $request->get_param( 'since' ),
			'auth_status' => $request->get_param( 'auth_status' ),
			'route'       => $request->get_param( 'route' ),
		] );
		return SB_Response::ok( [ 'count' => count( $rows ), 'records' => $rows ] );
	}

	/**
	 * Возвращает хвост error_log сайта.
	 * Параметры: lines (1..2000, default 200).
	 */
	public static function error_log( WP_REST_Request $request ) {
		$lines = (int) ( $request->get_param( 'lines' ) ?: 200 );
		$lines = max( 1, min( 2000, $lines ) );

		// Поиск типичных мест error_log
		$candidates = [
			ABSPATH . 'error_log',
			ABSPATH . '../error_log',
			WP_CONTENT_DIR . '/debug.log',
		];

		// Также берём из ini_get('error_log') если задан
		$ini_log = ini_get( 'error_log' );
		if ( $ini_log && is_readable( $ini_log ) ) {
			array_unshift( $candidates, $ini_log );
		}

		foreach ( $candidates as $path ) {
			if ( is_readable( $path ) ) {
				$tail = self::tail_file( $path, $lines );
				return SB_Response::ok( [
					'path'  => $path,
					'lines' => $lines,
					'size'  => filesize( $path ),
					'content' => $tail,
				] );
			}
		}
		return SB_Response::not_found( 'No accessible error_log' );
	}

	/** Чтение последних N строк файла без полной загрузки. */
	private static function tail_file( $path, $lines ) {
		$fp = fopen( $path, 'rb' );
		if ( ! $fp ) {
			return '';
		}
		$buffer_size = 4096;
		$pos         = -1;
		$collected   = '';
		$line_count  = 0;
		$file_size   = filesize( $path );
		$offset      = $file_size;

		while ( $offset > 0 && $line_count <= $lines ) {
			$read = min( $buffer_size, $offset );
			$offset -= $read;
			fseek( $fp, $offset );
			$chunk     = fread( $fp, $read );
			$collected = $chunk . $collected;
			$line_count = substr_count( $collected, "\n" );
		}
		fclose( $fp );

		$arr = explode( "\n", $collected );
		if ( count( $arr ) > $lines ) {
			$arr = array_slice( $arr, -$lines );
		}
		return implode( "\n", $arr );
	}
}
