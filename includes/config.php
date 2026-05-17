<?php
/**
 * SB_Config — centralised reader of plugin configuration from wp-config.php.
 *
 * No admin-UI settings. Everything is read from PHP constants — because the
 * wp-admin session could be compromised, but wp-config.php usually isn't
 * (unless the hosting itself is breached).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SB_Config {

	/** Minimum secret length (in bytes / chars of the constant value). */
	const MIN_SECRET_BYTES = 32;

	/**
	 * Current active secret.
	 * @return string|null  null if not defined or too short.
	 */
	public static function get_secret() {
		if ( ! defined( 'SITE_BRIDGE_SECRET' ) ) {
			return null;
		}
		$secret = (string) SITE_BRIDGE_SECRET;
		if ( strlen( $secret ) < self::MIN_SECRET_BYTES ) {
			return null;
		}
		return $secret;
	}

	/**
	 * Previous secret — valid during a rotation grace period.
	 * @return string|null
	 */
	public static function get_secret_previous() {
		if ( ! defined( 'SITE_BRIDGE_SECRET_PREVIOUS' ) ) {
			return null;
		}
		$secret = (string) SITE_BRIDGE_SECRET_PREVIOUS;
		if ( strlen( $secret ) < self::MIN_SECRET_BYTES ) {
			return null;
		}
		return $secret;
	}

	/**
	 * Allowed IPs / IPv4 CIDRs (CSV). Empty array = any IP allowed.
	 * @return array
	 */
	public static function get_allowed_ips() {
		if ( ! defined( 'SITE_BRIDGE_ALLOWED_IPS' ) ) {
			return [];
		}
		$raw = trim( (string) SITE_BRIDGE_ALLOWED_IPS );
		if ( $raw === '' ) {
			return [];
		}
		return array_filter( array_map( 'trim', explode( ',', $raw ) ) );
	}

	/**
	 * Timestamp tolerance in seconds (default 300).
	 * @return int
	 */
	public static function get_timestamp_tolerance() {
		if ( defined( 'SITE_BRIDGE_TIMESTAMP_TOLERANCE' ) ) {
			$v = (int) SITE_BRIDGE_TIMESTAMP_TOLERANCE;
			if ( $v >= 30 && $v <= 3600 ) {
				return $v;
			}
		}
		return 300;
	}

	/**
	 * Alert email recipient. Defaults to the site's admin_email.
	 * @return string|null
	 */
	public static function get_alert_email() {
		if ( defined( 'SITE_BRIDGE_ALERT_EMAIL' ) ) {
			$email = (string) SITE_BRIDGE_ALERT_EMAIL;
			if ( is_email( $email ) ) {
				return $email;
			}
		}
		$admin_email = get_option( 'admin_email' );
		return is_email( $admin_email ) ? $admin_email : null;
	}

	/**
	 * Log level: 'info' (default) or 'debug'.
	 * With 'debug', full request/response bodies are stored in the audit log
	 * (truncated to 64 KB). Useful during initial setup, switch to 'info' in production.
	 * @return string
	 */
	public static function get_log_level() {
		if ( defined( 'SITE_BRIDGE_LOG_LEVEL' ) ) {
			$v = strtolower( (string) SITE_BRIDGE_LOG_LEVEL );
			if ( in_array( $v, [ 'info', 'debug' ], true ) ) {
				return $v;
			}
		}
		// Default during development is 'debug'. Switch via wp-config once stable.
		return 'debug';
	}

	/**
	 * Current REMOTE_ADDR, accounting for known proxies (Cloudflare etc.).
	 * @return string
	 */
	public static function get_remote_ip() {
		// Priority: CF-Connecting-IP (Cloudflare), X-Real-IP, X-Forwarded-For (first), REMOTE_ADDR.
		// WARNING: these headers are trivially spoofable if the request bypasses CF and hits Apache directly.
		// On sites WITHOUT Cloudflare in front, REMOTE_ADDR is the only reliable source.
		$candidates = [
			$_SERVER['HTTP_CF_CONNECTING_IP'] ?? '',
			$_SERVER['HTTP_X_REAL_IP']        ?? '',
			isset( $_SERVER['HTTP_X_FORWARDED_FOR'] )
				? trim( explode( ',', $_SERVER['HTTP_X_FORWARDED_FOR'] )[0] )
				: '',
			$_SERVER['REMOTE_ADDR']           ?? '',
		];
		foreach ( $candidates as $ip ) {
			$ip = trim( $ip );
			if ( $ip !== '' && filter_var( $ip, FILTER_VALIDATE_IP ) ) {
				return $ip;
			}
		}
		return '0.0.0.0';
	}

	/**
	 * Match $ip against the whitelist (supports IPv4 and IPv4 CIDR).
	 * Empty whitelist returns true (any IP allowed).
	 * @param string $ip
	 * @return bool
	 */
	public static function is_ip_allowed( $ip ) {
		$allowed = self::get_allowed_ips();
		if ( empty( $allowed ) ) {
			return true;
		}
		foreach ( $allowed as $rule ) {
			if ( strpos( $rule, '/' ) !== false ) {
				if ( self::ip_in_cidr( $ip, $rule ) ) {
					return true;
				}
			} else {
				if ( $ip === $rule ) {
					return true;
				}
			}
		}
		return false;
	}

	/** IPv4 CIDR membership test. IPv6 is not supported in v1. */
	private static function ip_in_cidr( $ip, $cidr ) {
		list( $subnet, $bits ) = array_pad( explode( '/', $cidr ), 2, 32 );
		$bits   = (int) $bits;
		$ip_l   = ip2long( $ip );
		$net_l  = ip2long( $subnet );
		if ( $ip_l === false || $net_l === false || $bits < 0 || $bits > 32 ) {
			return false;
		}
		$mask = $bits === 0 ? 0 : ( -1 << ( 32 - $bits ) );
		return ( $ip_l & $mask ) === ( $net_l & $mask );
	}
}
