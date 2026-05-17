<?php
/**
 * SB_Email_Alerter — email-уведомления на критичные события.
 *
 * Получатель — `SITE_BRIDGE_ALERT_EMAIL` или `admin_email` из настроек сайта.
 * Защита от спама: throttle через transients (не больше одного email на тип события в N минут).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SB_Email_Alerter {

	const THROTTLE_SECONDS = 600;  // 10 минут — минимум между однотипными письмами

	/** Burst неудачных попыток auth (выход на бан). */
	public static function auth_failure_burst( $ip, $count ) {
		self::send(
			'auth_burst',
			'[Site Bridge] Burst неудачных auth-попыток',
			"Site Bridge: на IP {$ip} зафиксировано {$count} неудачных попыток auth за окно. IP забанен на 1 час.\n\n"
			. "Сайт: " . home_url() . "\n"
			. "Время: " . current_time( 'mysql', true ) . " UTC"
		);
	}

	/** Опасная операция (загрузка плагина, перезапись файла, восстановление). */
	public static function dangerous_op( $action, array $context = [] ) {
		$ip = SB_Config::get_remote_ip();
		$body = "Site Bridge: выполнена опасная операция.\n\n"
			. "Действие: {$action}\n"
			. "IP:       {$ip}\n"
			. "Сайт:     " . home_url() . "\n"
			. "Время:    " . current_time( 'mysql', true ) . " UTC\n\n";
		if ( ! empty( $context ) ) {
			$body .= "Контекст:\n" . wp_json_encode( $context, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		}
		self::send(
			'dangerous_op_' . md5( $action ),
			"[Site Bridge] {$action}",
			$body
		);
	}

	/**
	 * Внутренняя отправка с throttle.
	 *
	 * @param string $event_key   уникальный ключ для троттлинга
	 * @param string $subject
	 * @param string $body
	 */
	private static function send( $event_key, $subject, $body ) {
		$to = SB_Config::get_alert_email();
		if ( ! $to ) {
			return; // некуда слать
		}
		$throttle_key = 'sb_alert_throttle_' . md5( $event_key );
		if ( get_transient( $throttle_key ) ) {
			return; // не слать слишком часто
		}
		set_transient( $throttle_key, 1, self::THROTTLE_SECONDS );

		// Тихая отправка — если wp_mail отвалится, не валим текущий REST-запрос
		try {
			@wp_mail( $to, $subject, $body, [ 'Content-Type: text/plain; charset=UTF-8' ] );
		} catch ( \Throwable $e ) {
			error_log( '[site-bridge] wp_mail failed: ' . $e->getMessage() );
		}
	}
}
