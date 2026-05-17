<?php
/**
 * SB_Auth — HMAC signature verification and surrounding protections.
 *
 * Signature scheme:
 *   message = TIMESTAMP + "\n" + METHOD + "\n" + PATH + "\n" + sha256_hex(BODY)
 *   signature_hex = HMAC-SHA256(secret, message)
 *
 * Request headers:
 *   X-SB-Timestamp:  Unix timestamp (integer seconds)
 *   X-SB-Signature:  hex string of the signature (lowercase)
 *
 * Signing input notes:
 *   - PATH — the REST route without the /wp-json prefix (e.g. "/sb/v1/pages/1580")
 *   - BODY — the raw request body bytes; empty string for GET
 *
 * Checks performed:
 *   1. Killswitch (handled in site-bridge.php bootstrap; this class assumes it's off)
 *   2. Secret defined
 *   3. IP whitelist (if configured)
 *   4. Rate limit (auth-failure bucket)
 *   5. Headers present
 *   6. Timestamp within tolerance window
 *   7. Signature valid against current OR previous secret
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SB_Auth {

	const HEADER_TIMESTAMP = 'X-SB-Timestamp';
	const HEADER_SIGNATURE = 'X-SB-Signature';

	/** Cache the result so we don't validate twice in the same request. */
	private static $verified = null;

	/**
	 * Main permission_callback for all sb/v1 routes.
	 *
	 * @param WP_REST_Request $request
	 * @return true|WP_Error
	 */
	public static function check( WP_REST_Request $request ) {
		if ( self::$verified !== null ) {
			return self::$verified;
		}
		$res = self::do_check( $request );
		self::$verified = $res;
		return $res;
	}

	private static function do_check( WP_REST_Request $request ) {
		$ip = SB_Config::get_remote_ip();

		// 1. Secret defined?
		$secret = SB_Config::get_secret();
		if ( $secret === null ) {
			SB_Audit::log_auth_failure( $request, $ip, 'no_secret_configured' );
			return new WP_Error( 'sb_no_secret',
				'Site Bridge is installed but SITE_BRIDGE_SECRET is not defined in wp-config.php.',
				[ 'status' => 503 ] );
		}

		// 2. IP whitelist
		if ( ! SB_Config::is_ip_allowed( $ip ) ) {
			SB_Audit::log_auth_failure( $request, $ip, 'ip_blocked' );
			return new WP_Error( 'sb_ip_blocked', 'Forbidden.', [ 'status' => 403 ] );
		}

		// 3. Rate limit (checked before headers, so empty requests count too)
		if ( self::is_rate_limited( $ip ) ) {
			SB_Audit::log_auth_failure( $request, $ip, 'rate_limited' );
			return new WP_Error( 'sb_rate_limited', 'Too many failed auth attempts.', [ 'status' => 429 ] );
		}

		// 4. Headers present
		$ts  = $request->get_header( self::HEADER_TIMESTAMP );
		$sig = $request->get_header( self::HEADER_SIGNATURE );
		if ( ! $ts || ! $sig ) {
			self::register_failure( $ip );
			SB_Audit::log_auth_failure( $request, $ip, 'missing_headers' );
			return new WP_Error( 'sb_missing_headers', 'Missing X-SB-Timestamp or X-SB-Signature.', [ 'status' => 401 ] );
		}

		// 5. Timestamp valid and within tolerance
		$ts_int = (int) $ts;
		if ( $ts_int <= 0 ) {
			self::register_failure( $ip );
			SB_Audit::log_auth_failure( $request, $ip, 'invalid_timestamp' );
			return new WP_Error( 'sb_invalid_timestamp', 'Invalid X-SB-Timestamp.', [ 'status' => 401 ] );
		}
		$now = time();
		$tol = SB_Config::get_timestamp_tolerance();
		if ( abs( $now - $ts_int ) > $tol ) {
			self::register_failure( $ip );
			SB_Audit::log_auth_failure( $request, $ip, 'expired_timestamp' );
			return new WP_Error( 'sb_expired_timestamp',
				sprintf( 'Timestamp out of window (server time=%d, request=%d, tolerance=%d).', $now, $ts_int, $tol ),
				[ 'status' => 401 ] );
		}

		// 6. Compute signature
		$method     = strtoupper( $request->get_method() );
		$path       = self::get_canonical_path( $request );
		$body_bytes = self::get_raw_body( $request );
		$body_hash  = hash( 'sha256', $body_bytes );
		$message    = $ts . "\n" . $method . "\n" . $path . "\n" . $body_hash;
		$expected   = hash_hmac( 'sha256', $message, $secret );

		// hash_equals — timing-safe comparison
		if ( hash_equals( $expected, strtolower( $sig ) ) ) {
			self::reset_failures( $ip );
			return true;
		}

		// Try previous secret (rotation grace period)
		$prev = SB_Config::get_secret_previous();
		if ( $prev !== null ) {
			$expected_prev = hash_hmac( 'sha256', $message, $prev );
			if ( hash_equals( $expected_prev, strtolower( $sig ) ) ) {
				self::reset_failures( $ip );
				return true;
			}
		}

		self::register_failure( $ip );
		SB_Audit::log_auth_failure( $request, $ip, 'invalid_signature' );
		return new WP_Error( 'sb_invalid_signature', 'Invalid signature.', [ 'status' => 401 ] );
	}

	/**
	 * Canonical PATH for signing: REST route without the /wp-json prefix.
	 * Query string is NOT included — put parameters in the body or sign the full URI in v2.
	 * In v1, query is unsigned, but the original URI (with query) is preserved in the audit log.
	 */
	private static function get_canonical_path( WP_REST_Request $request ) {
		return '/' . trim( $request->get_route(), '/' );
	}

	/** Raw request body bytes (empty string for GET). */
	private static function get_raw_body( WP_REST_Request $request ) {
		$body = $request->get_body();
		return $body === null ? '' : (string) $body;
	}

	// === Rate limiting via WP transients (no extra table — simpler, persistence not critical) ===

	const RL_LIMIT  = 5;     // 5 failures
	const RL_WINDOW = 300;   // within 5 minutes
	const RL_BAN    = 3600;  // → 1-hour ban

	private static function rl_key( $ip, $suffix = 'fail' ) {
		return 'sb_rl_' . $suffix . '_' . md5( $ip );
	}

	public static function is_rate_limited( $ip ) {
		return (bool) get_transient( self::rl_key( $ip, 'ban' ) );
	}

	private static function register_failure( $ip ) {
		$k = self::rl_key( $ip, 'fail' );
		$current = (int) get_transient( $k );
		$current++;
		set_transient( $k, $current, self::RL_WINDOW );
		if ( $current >= self::RL_LIMIT ) {
			set_transient( self::rl_key( $ip, 'ban' ), 1, self::RL_BAN );
			delete_transient( $k );
			// Email alert about the ban
			SB_Email_Alerter::auth_failure_burst( $ip, $current );
		}
	}

	private static function reset_failures( $ip ) {
		delete_transient( self::rl_key( $ip, 'fail' ) );
	}
}
