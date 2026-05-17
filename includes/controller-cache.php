<?php
/**
 * SB_Cache_Controller — purge кэшей всех популярных WP cache-плагинов.
 *
 * Поддерживается:
 *   - WP Rocket
 *   - LiteSpeed Cache
 *   - W3 Total Cache
 *   - WP Super Cache
 *   - Cache Enabler (KeyCDN)
 *   - WP Fastest Cache
 *   - Hummingbird (WPMU DEV)
 *   - SG Optimizer (SiteGround)
 *   - Swift Performance
 *   - Comet Cache
 *   - Autoptimize (asset cache, не page)
 *   - Seraphinite Accelerator
 *   - WP Object Cache (нативный)
 *
 * Endpoint: POST /cache/purge
 * Body JSON:
 *   {
 *     "targets": ["rocket", "litespeed", ...] | null   // null = все которые установлены
 *     "url":     "https://...page"                       // опционально, очистка конкретной страницы
 *   }
 *
 * Безопасно: если плагин не активен, его блок просто пропускается ("not installed").
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SB_Cache_Controller {

	/** Список всех поддерживаемых cache-таргетов. */
	const ALL_TARGETS = [
		'rocket', 'litespeed', 'w3tc', 'wp_super_cache', 'cache_enabler',
		'wp_fastest_cache', 'hummingbird', 'sg_optimizer', 'swift_performance',
		'comet_cache', 'autoptimize', 'seraphinite', 'wp',
	];

	public static function purge( WP_REST_Request $request ) {
		$payload = $request->get_json_params() ?: [];
		$targets = isset( $payload['targets'] ) && is_array( $payload['targets'] )
			? $payload['targets']
			: self::ALL_TARGETS;
		$url = isset( $payload['url'] ) ? esc_url_raw( $payload['url'] ) : null;

		$results = [];

		// 1. WP Rocket
		if ( in_array( 'rocket', $targets, true ) ) {
			$results['rocket'] = self::purge_rocket( $url );
		}

		// 2. LiteSpeed Cache
		if ( in_array( 'litespeed', $targets, true ) ) {
			$results['litespeed'] = self::purge_litespeed( $url );
		}

		// 3. W3 Total Cache
		if ( in_array( 'w3tc', $targets, true ) ) {
			$results['w3tc'] = self::purge_w3tc( $url );
		}

		// 4. WP Super Cache
		if ( in_array( 'wp_super_cache', $targets, true ) ) {
			$results['wp_super_cache'] = self::purge_wp_super_cache();
		}

		// 5. Cache Enabler
		if ( in_array( 'cache_enabler', $targets, true ) ) {
			$results['cache_enabler'] = self::purge_cache_enabler();
		}

		// 6. WP Fastest Cache
		if ( in_array( 'wp_fastest_cache', $targets, true ) ) {
			$results['wp_fastest_cache'] = self::purge_wp_fastest_cache();
		}

		// 7. Hummingbird
		if ( in_array( 'hummingbird', $targets, true ) ) {
			$results['hummingbird'] = self::purge_hummingbird( $url );
		}

		// 8. SG Optimizer
		if ( in_array( 'sg_optimizer', $targets, true ) ) {
			$results['sg_optimizer'] = self::purge_sg_optimizer();
		}

		// 9. Swift Performance
		if ( in_array( 'swift_performance', $targets, true ) ) {
			$results['swift_performance'] = self::purge_swift_performance();
		}

		// 10. Comet Cache
		if ( in_array( 'comet_cache', $targets, true ) ) {
			$results['comet_cache'] = self::purge_comet_cache();
		}

		// 11. Autoptimize (asset cache)
		if ( in_array( 'autoptimize', $targets, true ) ) {
			$results['autoptimize'] = self::purge_autoptimize();
		}

		// 12. Seraphinite Accelerator
		if ( in_array( 'seraphinite', $targets, true ) ) {
			$results['seraphinite'] = self::purge_seraphinite();
		}

		// 13. WP Object Cache (нативный)
		if ( in_array( 'wp', $targets, true ) ) {
			wp_cache_flush();
			$results['wp'] = 'wp_cache_flush() called';
		}

		SB_Audit::log_dangerous_op( 'cache.purge', [ 'targets' => $targets, 'url' => $url ] );

		return SB_Response::ok( [ 'purged' => true, 'results' => $results ] );
	}

	// ───────────────────────────────────────────────────────────
	// Per-plugin implementations — каждая проверяет наличие плагина
	// и возвращает короткий статус-string.
	// ───────────────────────────────────────────────────────────

	private static function purge_rocket( $url ) {
		if ( ! function_exists( 'rocket_clean_domain' ) ) return 'not installed';
		try {
			if ( $url && function_exists( 'rocket_clean_files' ) ) {
				rocket_clean_files( [ $url ] );
				return 'cleaned URL';
			}
			rocket_clean_domain();
			return 'cleaned domain';
		} catch ( \Throwable $e ) {
			return 'error: ' . $e->getMessage();
		}
	}

	private static function purge_litespeed( $url ) {
		$active = defined( 'LSCWP_V' ) || class_exists( 'LiteSpeed_Cache' )
			|| class_exists( '\LiteSpeed\Core' ) || has_action( 'litespeed_purge_all' );
		if ( ! $active ) return 'not installed';
		do_action( 'litespeed_purge_all' );
		if ( $url ) do_action( 'litespeed_purge_url', $url );
		return $url ? 'purged all + url' : 'purged all';
	}

	private static function purge_w3tc( $url ) {
		if ( ! function_exists( 'w3tc_flush_all' ) ) return 'not installed';
		try {
			if ( $url && function_exists( 'w3tc_flush_url' ) ) {
				w3tc_flush_url( $url );
				return 'flushed URL';
			}
			w3tc_flush_all();
			return 'flushed all';
		} catch ( \Throwable $e ) {
			return 'error: ' . $e->getMessage();
		}
	}

	private static function purge_wp_super_cache() {
		if ( ! function_exists( 'wp_cache_clear_cache' ) && ! function_exists( 'prune_super_cache' ) ) return 'not installed';
		try {
			if ( function_exists( 'wp_cache_clear_cache' ) ) {
				wp_cache_clear_cache();
			}
			if ( function_exists( 'prune_super_cache' ) && function_exists( 'get_supercache_dir' ) ) {
				prune_super_cache( get_supercache_dir(), true );
				prune_super_cache( WP_CONTENT_DIR . '/cache/', true );
			}
			return 'cleared';
		} catch ( \Throwable $e ) {
			return 'error: ' . $e->getMessage();
		}
	}

	private static function purge_cache_enabler() {
		if ( ! class_exists( 'Cache_Enabler' ) ) return 'not installed';
		try {
			if ( method_exists( 'Cache_Enabler', 'clear_complete_cache' ) ) {
				Cache_Enabler::clear_complete_cache();
				return 'cleared (clear_complete_cache)';
			}
			if ( method_exists( 'Cache_Enabler', 'clear_total_cache' ) ) {
				Cache_Enabler::clear_total_cache();
				return 'cleared (clear_total_cache)';
			}
			return 'unsupported version';
		} catch ( \Throwable $e ) {
			return 'error: ' . $e->getMessage();
		}
	}

	private static function purge_wp_fastest_cache() {
		if ( ! class_exists( 'WpFastestCache' ) ) return 'not installed';
		try {
			$wpfc = new WpFastestCache();
			if ( method_exists( $wpfc, 'deleteCache' ) ) {
				$wpfc->deleteCache( true );
				return 'cleared';
			}
			do_action( 'wpfc_clear_all_cache' );
			return 'cleared via action';
		} catch ( \Throwable $e ) {
			return 'error: ' . $e->getMessage();
		}
	}

	private static function purge_hummingbird( $url ) {
		// Hummingbird WPMU DEV
		if ( ! class_exists( '\\Hummingbird\\WP_Hummingbird' ) && ! has_action( 'wphb_clear_page_cache' ) ) return 'not installed';
		do_action( 'wphb_clear_page_cache' );
		do_action( 'wphb_clear_cache' );
		return 'cleared';
	}

	private static function purge_sg_optimizer() {
		// SiteGround Optimizer
		if ( ! has_action( 'siteground_optimizer_flush_cache' ) && ! class_exists( '\\SiteGround_Optimizer\\Supercacher\\Supercacher' ) ) return 'not installed';
		do_action( 'siteground_optimizer_flush_cache' );
		return 'cleared';
	}

	private static function purge_swift_performance() {
		if ( ! class_exists( 'Swift_Performance_Cache' ) ) return 'not installed';
		try {
			if ( method_exists( 'Swift_Performance_Cache', 'clear_all_cache' ) ) {
				Swift_Performance_Cache::clear_all_cache();
			} else {
				return 'unsupported version';
			}
			return 'cleared';
		} catch ( \Throwable $e ) {
			return 'error: ' . $e->getMessage();
		}
	}

	private static function purge_comet_cache() {
		// Comet Cache (бывший ZenCache)
		if ( ! class_exists( '\\WebSharks\\CometCache\\Plugin' ) && ! function_exists( 'comet_cache_clear' ) ) return 'not installed';
		try {
			if ( function_exists( 'comet_cache_clear' ) ) {
				comet_cache_clear();
				return 'cleared';
			}
			return 'class exists but no public clear function';
		} catch ( \Throwable $e ) {
			return 'error: ' . $e->getMessage();
		}
	}

	private static function purge_autoptimize() {
		// Autoptimize — asset cache (CSS/JS combine), не page cache
		if ( ! class_exists( 'autoptimizeCache' ) ) return 'not installed';
		try {
			if ( method_exists( 'autoptimizeCache', 'clearall' ) ) {
				autoptimizeCache::clearall();
				return 'cleared asset cache';
			}
			return 'unsupported version';
		} catch ( \Throwable $e ) {
			return 'error: ' . $e->getMessage();
		}
	}

	private static function purge_seraphinite() {
		if ( ! has_action( 'seraph_accel_action_cache_clear' ) ) return 'not installed';
		do_action( 'seraph_accel_action_cache_clear' );
		return 'purged';
	}
}
