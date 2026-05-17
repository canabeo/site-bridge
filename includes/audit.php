<?php
/**
 * SB_Audit — журналирование всех запросов.
 *
 * Таблица {prefix}sb_audit заполняется на каждом запросе к /wp-json/sb/v1/*.
 * При log_level=info — записывается только метаинформация + sha256(body).
 * При log_level=debug — дополнительно сохраняется первые 64KB тела запроса/ответа
 * в JSON-поле details (для разработки).
 *
 * Записи иммутабельны: REST-эндпоинт audit-log только GET (нет DELETE).
 * Чистка — через WP-Cron автоматически удаляет записи старше 90 дней.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SB_Audit {

	const TABLE       = 'sb_audit';
	const MAX_BODY_KB = 64;        // в debug-режиме сохраняем не больше 64KB тела
	const RETENTION_DAYS = 90;

	private static $request_start_time = null;

	/** Запоминаем время начала для расчёта duration. Вызывается из rest.php. */
	public static function mark_request_start() {
		self::$request_start_time = microtime( true );
	}

	/**
	 * Лог успешного запроса. Вызывается из обвязки REST после выполнения callback.
	 *
	 * @param WP_REST_Request $request
	 * @param mixed           $response  WP_REST_Response | WP_Error | array | scalar
	 * @param int             $status    HTTP code
	 */
	public static function log_request( WP_REST_Request $request, $response, $status ) {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;

		$body         = (string) $request->get_body();
		$body_hash    = hash( 'sha256', $body );
		$response_str = self::stringify_response( $response );

		$duration_ms = self::$request_start_time
			? (int) round( ( microtime( true ) - self::$request_start_time ) * 1000 )
			: 0;

		$auth_status = $status >= 200 && $status < 400 ? 'ok' : 'error';

		$details = null;
		if ( SB_Config::get_log_level() === 'debug' ) {
			$details = wp_json_encode( [
				'request_body'    => self::truncate_kb( $body, self::MAX_BODY_KB ),
				'response_body'   => self::truncate_kb( $response_str, self::MAX_BODY_KB ),
				'request_headers' => self::safe_headers( $request ),
			], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		}

		$wpdb->insert(
			$table,
			[
				'created_at'        => current_time( 'mysql', true ),
				'remote_ip'         => SB_Config::get_remote_ip(),
				'user_agent'        => substr( (string) ( $_SERVER['HTTP_USER_AGENT'] ?? '' ), 0, 255 ),
				'method'            => $request->get_method(),
				'route'             => substr( $request->get_route(), 0, 255 ),
				'query'             => substr( http_build_query( $request->get_query_params() ?: [] ), 0, 255 ),
				'status_code'       => (int) $status,
				'request_body_hash' => $body_hash,
				'request_body_size' => strlen( $body ),
				'response_summary'  => substr( $response_str, 0, 255 ),
				'duration_ms'       => $duration_ms,
				'auth_status'       => $auth_status,
				'details'           => $details,
			],
			[ '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%d', '%s', '%d', '%s', '%s' ]
		);
	}

	/**
	 * Отдельный шорткат на лог auth-провалов (когда permission_callback вернул WP_Error).
	 * Здесь нельзя получить полное тело и т.д. — пишем минимум.
	 */
	public static function log_auth_failure( WP_REST_Request $request, $ip, $reason ) {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;

		$body         = (string) $request->get_body();
		$body_hash    = hash( 'sha256', $body );

		$details = null;
		if ( SB_Config::get_log_level() === 'debug' ) {
			$details = wp_json_encode( [
				'auth_failure_reason' => $reason,
				'request_headers'     => self::safe_headers( $request ),
				'request_body'        => self::truncate_kb( $body, 4 ),
			], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		}

		$wpdb->insert(
			$table,
			[
				'created_at'        => current_time( 'mysql', true ),
				'remote_ip'         => $ip,
				'user_agent'        => substr( (string) ( $_SERVER['HTTP_USER_AGENT'] ?? '' ), 0, 255 ),
				'method'            => $request->get_method(),
				'route'             => substr( $request->get_route(), 0, 255 ),
				'query'             => substr( http_build_query( $request->get_query_params() ?: [] ), 0, 255 ),
				'status_code'       => 401,
				'request_body_hash' => $body_hash,
				'request_body_size' => strlen( $body ),
				'response_summary'  => substr( $reason, 0, 255 ),
				'duration_ms'       => 0,
				'auth_status'       => $reason,
				'details'           => $details,
			],
			[ '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%d', '%s', '%d', '%s', '%s' ]
		);
	}

	/**
	 * Лог «опасной операции» — пишется явным вызовом из контроллеров.
	 * Например: загрузка плагина, перезапись файла, восстановление бэкапа.
	 * Дополнительно шлётся email-алерт.
	 */
	public static function log_dangerous_op( $action, array $context = [] ) {
		// Пишется в общую таблицу как часть текущего запроса — отдельной записи не нужно.
		// Этот метод служит точкой для email-алерта.
		SB_Email_Alerter::dangerous_op( $action, $context );
	}

	/**
	 * Выборка записей для GET /audit-log.
	 *
	 * @param array $args  ['limit' => int, 'since' => 'YYYY-MM-DD HH:MM:SS', 'auth_status' => string, 'route' => string]
	 * @return array
	 */
	public static function fetch( array $args = [] ) {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;
		$limit = isset( $args['limit'] ) ? max( 1, min( 1000, (int) $args['limit'] ) ) : 100;

		$where  = [ '1=1' ];
		$params = [];
		if ( ! empty( $args['since'] ) ) {
			$where[]  = 'created_at >= %s';
			$params[] = $args['since'];
		}
		if ( ! empty( $args['auth_status'] ) ) {
			$where[]  = 'auth_status = %s';
			$params[] = $args['auth_status'];
		}
		if ( ! empty( $args['route'] ) ) {
			$where[]  = 'route LIKE %s';
			$params[] = '%' . $wpdb->esc_like( $args['route'] ) . '%';
		}

		$sql = "SELECT * FROM `$table` WHERE " . implode( ' AND ', $where ) . " ORDER BY id DESC LIMIT $limit";
		if ( ! empty( $params ) ) {
			$sql = $wpdb->prepare( $sql, $params );
		}
		return $wpdb->get_results( $sql, ARRAY_A );
	}

	/** Запускается WP-Cron — чистит старые записи. */
	public static function cleanup_old_records() {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;
		$wpdb->query( $wpdb->prepare(
			"DELETE FROM `$table` WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
			self::RETENTION_DAYS
		) );
	}

	// === Helpers ===

	private static function stringify_response( $response ) {
		if ( is_wp_error( $response ) ) {
			return 'WP_Error: ' . $response->get_error_code() . ' — ' . $response->get_error_message();
		}
		if ( $response instanceof WP_REST_Response ) {
			$data = $response->get_data();
			return wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		}
		if ( is_array( $response ) || is_object( $response ) ) {
			return wp_json_encode( $response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		}
		return (string) $response;
	}

	private static function truncate_kb( $s, $kb ) {
		$max = $kb * 1024;
		if ( strlen( $s ) <= $max ) {
			return $s;
		}
		return substr( $s, 0, $max ) . "\n…[truncated " . ( strlen( $s ) - $max ) . " bytes]";
	}

	private static function safe_headers( WP_REST_Request $request ) {
		$h = $request->get_headers();
		// Не пишем тело Authorization-заголовков
		foreach ( [ 'x_sb_signature', 'authorization' ] as $k ) {
			if ( isset( $h[ $k ] ) ) {
				$h[ $k ] = [ '<redacted>' ];
			}
		}
		return $h;
	}
}
