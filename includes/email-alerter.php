<?php
/**
 * SB_Email_Alerter — email notifications for critical events.
 *
 * Recipient — `SITE_BRIDGE_ALERT_EMAIL` constant or the site's admin_email.
 * Anti-spam: throttle via transients (max one email per event type per N minutes).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SB_Email_Alerter {

	const THROTTLE_SECONDS = 600;  // 10 minutes between identical alerts

	/** Burst of failed auth attempts (IP ban triggered). */
	public static function auth_failure_burst( $ip, $count ) {
		self::send(
			'auth_burst',
			'[Site Bridge] Burst of failed auth attempts',
			"Site Bridge: {$count} failed auth attempts detected from IP {$ip} within the rate-limit window. The IP has been banned for 1 hour.\n\n"
			. "Site: " . home_url() . "\n"
			. "Time: " . current_time( 'mysql', true ) . " UTC"
		);
	}

	/** Dangerous operation (plugin install, file write, backup restore). */
	public static function dangerous_op( $action, array $context = [] ) {
		$ip = SB_Config::get_remote_ip();
		$body = "Site Bridge: a dangerous operation was performed.\n\n"
			. "Action: {$action}\n"
			. "IP:     {$ip}\n"
			. "Site:   " . home_url() . "\n"
			. "Time:   " . current_time( 'mysql', true ) . " UTC\n\n";
		if ( ! empty( $context ) ) {
			$body .= "Context:\n" . wp_json_encode( $context, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		}
		self::send(
			'dangerous_op_' . md5( $action ),
			"[Site Bridge] {$action}",
			$body
		);
	}

	/**
	 * Internal send with throttle.
	 *
	 * @param string $event_key   unique key for throttling
	 * @param string $subject
	 * @param string $body
	 */
	private static function send( $event_key, $subject, $body ) {
		$to = SB_Config::get_alert_email();
		if ( ! $to ) {
			return; // nowhere to send
		}
		$throttle_key = 'sb_alert_throttle_' . md5( $event_key );
		if ( get_transient( $throttle_key ) ) {
			return; // skip if recently sent
		}
		set_transient( $throttle_key, 1, self::THROTTLE_SECONDS );

		// Silent send — if wp_mail throws, don't break the current REST request
		try {
			@wp_mail( $to, $subject, $body, [ 'Content-Type: text/plain; charset=UTF-8' ] );
		} catch ( \Throwable $e ) {
			error_log( '[site-bridge] wp_mail failed: ' . $e->getMessage() );
		}
	}
}
