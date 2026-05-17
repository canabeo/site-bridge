<?php
/**
 * SB_Auth — проверка HMAC-подписи и сопутствующих защит.
 *
 * Схема подписи:
 *   message = TIMESTAMP + "\n" + METHOD + "\n" + PATH + "\n" + sha256_hex(BODY)
 *   signature_hex = HMAC-SHA256(secret, message)
 *
 * Заголовки запроса:
 *   X-SB-Timestamp:  unix timestamp (целое число секунд)
 *   X-SB-Signature:  hex-строка подписи (lowercase)
 *
 * Параметры подписи:
 *   - PATH — это REST route без префикса /wp-json (например, "/sb/v1/pages/1580")
 *   - BODY — сырое тело запроса как байты; для GET — пустая строка
 *
 * Проверки:
 *   1. Killswitch
 *   2. Заголовки присутствуют
 *   3. Timestamp в окне ±tolerance
 *   4. IP whitelist (если задан)
 *   5. Rate limit (auth-fail bucket)
 *   6. Подпись валидна (current ИЛИ previous secret)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SB_Auth {

	const HEADER_TIMESTAMP = 'X-SB-Timestamp';
	const HEADER_SIGNATURE = 'X-SB-Signature';

	/** Кешируем результат, чтобы не валидировать дважды. */
	private static $verified = null;

	/**
	 * Главная permission_callback для всех роутов sb/v1.
	 *
	 * @param WP_REST_Request $request
	 * @return true|WP_Error
	 */
	public static function check( WP_REST_Request $request ) {
		// Если уже проверили в рамках этого запроса — вернуть кеш
		if ( self::$verified !== null ) {
			return self::$verified;
		}
		$res = self::do_check( $request );
		self::$verified = $res;
		return $res;
	}

	private static function do_check( WP_REST_Request $request ) {
		$ip = SB_Config::get_remote_ip();

		// 1. Secret определён?
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

		// 3. Rate limit (до проверки заголовков — чтобы пустые запросы тоже считались)
		if ( self::is_rate_limited( $ip ) ) {
			SB_Audit::log_auth_failure( $request, $ip, 'rate_limited' );
			return new WP_Error( 'sb_rate_limited', 'Too many failed auth attempts.', [ 'status' => 429 ] );
		}

		// 4. Заголовки
		$ts  = $request->get_header( self::HEADER_TIMESTAMP );
		$sig = $request->get_header( self::HEADER_SIGNATURE );
		if ( ! $ts || ! $sig ) {
			self::register_failure( $ip );
			SB_Audit::log_auth_failure( $request, $ip, 'missing_headers' );
			return new WP_Error( 'sb_missing_headers', 'Missing X-SB-Timestamp or X-SB-Signature.', [ 'status' => 401 ] );
		}

		// 5. Timestamp валиден и в окне
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

		// 6. Считаем подпись
		$method     = strtoupper( $request->get_method() );
		$path       = self::get_canonical_path( $request );
		$body_bytes = self::get_raw_body( $request );
		$body_hash  = hash( 'sha256', $body_bytes );
		$message    = $ts . "\n" . $method . "\n" . $path . "\n" . $body_hash;
		$expected   = hash_hmac( 'sha256', $message, $secret );

		// hash_equals — защита от timing-атак
		if ( hash_equals( $expected, strtolower( $sig ) ) ) {
			self::reset_failures( $ip );
			return true;
		}

		// Попытаемся с предыдущим секретом (grace-период ротации)
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
	 * Канонический путь для подписи: REST route без префикса /wp-json.
	 * Query string НЕ включаем — кладите параметры в body или подписывайте отдельно (v2).
	 * Для query-based GET-эндпоинтов подпись пути остаётся одинаковой; чтобы заполнить gap
	 * — клиент должен включать query в тело либо подписывать полный URI. В v1 мы НЕ подписываем query,
	 * но в audit-log оригинальный URI с query сохраняется.
	 */
	private static function get_canonical_path( WP_REST_Request $request ) {
		return '/' . trim( $request->get_route(), '/' );
	}

	/** Сырые байты тела запроса (для GET вернёт пустую строку). */
	private static function get_raw_body( WP_REST_Request $request ) {
		$body = $request->get_body();
		return $body === null ? '' : (string) $body;
	}

	// === Rate limiting через WP transients (без таблицы — proще, persistence не критичен) ===

	const RL_LIMIT  = 5;     // 5 неудач
	const RL_WINDOW = 300;   // за 5 минут
	const RL_BAN    = 3600;  // → бан на 1 час

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
			// Email alert о бане
			SB_Email_Alerter::auth_failure_burst( $ip, $current );
		}
	}

	private static function reset_failures( $ip ) {
		delete_transient( self::rl_key( $ip, 'fail' ) );
	}
}
