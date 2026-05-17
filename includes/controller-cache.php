<?php
/**
 * SB_Cache_Controller — очистка кэша.
 *
 * Поддерживает WP Rocket, LiteSpeed Cache, Seraphinite Accelerator, нативный WP object cache.
 * Триггерится через POST /cache/purge с параметрами:
 *   {
 *     "targets": ["rocket","litespeed","seraphinite","wp"],   // null = всё что доступно
 *     "url": "https://...page"                                 // опционально — очистка конкретной страницы
 *   }
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SB_Cache_Controller {

	public static function purge( WP_REST_Request $request ) {
		$payload = $request->get_json_params() ?: [];
		$targets = isset( $payload['targets'] ) && is_array( $payload['targets'] )
			? $payload['targets']
			: [ 'rocket', 'litespeed', 'seraphinite', 'wp' ];
		$url = isset( $payload['url'] ) ? esc_url_raw( $payload['url'] ) : null;

		$results = [];

		if ( in_array( 'rocket', $targets, true ) && function_exists( 'rocket_clean_domain' ) ) {
			try {
				if ( $url && function_exists( 'rocket_clean_files' ) ) {
					rocket_clean_files( [ $url ] );
					$results['rocket'] = 'cleaned URL';
				} else {
					rocket_clean_domain();
					$results['rocket'] = 'cleaned domain';
				}
			} catch ( \Throwable $e ) {
				$results['rocket'] = 'error: ' . $e->getMessage();
			}
		}

		if ( in_array( 'litespeed', $targets, true ) ) {
			if ( defined( 'LSCWP_V' ) || class_exists( 'LiteSpeed_Cache' ) || class_exists( 'LiteSpeed\Core' ) ) {
				do_action( 'litespeed_purge_all' );
				if ( $url ) {
					do_action( 'litespeed_purge_url', $url );
				}
				$results['litespeed'] = $url ? 'purged all + url' : 'purged all';
			} else {
				$results['litespeed'] = 'not installed';
			}
		}

		if ( in_array( 'seraphinite', $targets, true ) ) {
			// Seraphinite Accelerator: action 'seraph_accel_action_cache_clear'
			if ( has_action( 'seraph_accel_action_cache_clear' ) ) {
				do_action( 'seraph_accel_action_cache_clear' );
				$results['seraphinite'] = 'purged';
			} else {
				$results['seraphinite'] = 'not installed';
			}
		}

		if ( in_array( 'wp', $targets, true ) ) {
			wp_cache_flush();
			$results['wp'] = 'wp_cache_flush() called';
		}

		SB_Audit::log_dangerous_op( 'cache.purge', [ 'targets' => $targets, 'url' => $url ] );

		return SB_Response::ok( [ 'purged' => true, 'results' => $results ] );
	}
}
