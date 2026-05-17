<?php
/**
 * SB_Config — централизованное чтение конфигурации из wp-config.php.
 *
 * Никаких настроек в админке. Всё через константы — потому что админка может
 * быть скомпрометирована, а wp-config — нет (если хостинг не пробит).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SB_Config {

	/** Длина секрета в hex/base64 после декодирования (минимум). */
	const MIN_SECRET_BYTES = 32;

	/**
	 * Текущий активный секрет (raw bytes после base64url-decode либо как есть).
	 * @return string|null  null если не задан или слишком короткий.
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
	 * Предыдущий секрет — действителен в течение grace-периода ротации.
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
	 * Список разрешённых IP/CIDR (CSV). Пустой массив = любой IP допустим.
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
	 * Допуск по времени timestamp в секундах (по умолчанию 300).
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
	 * Email для алертов. По умолчанию — admin_email сайта.
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
	 * Уровень логирования: 'info' (по умолчанию) или 'debug'.
	 * При 'debug' логируются тела запросов и ответов (только во время разработки).
	 * @return string
	 */
	public static function get_log_level() {
		if ( defined( 'SITE_BRIDGE_LOG_LEVEL' ) ) {
			$v = strtolower( (string) SITE_BRIDGE_LOG_LEVEL );
			if ( in_array( $v, [ 'info', 'debug' ], true ) ) {
				return $v;
			}
		}
		// Дефолт на время разработки — debug. После стабилизации сменим на info через wp-config.
		return 'debug';
	}

	/**
	 * Текущий REMOTE_ADDR с учётом возможного прокси (Cloudflare).
	 * @return string
	 */
	public static function get_remote_ip() {
		// Приоритет: CF-Connecting-IP (Cloudflare), X-Real-IP, X-Forwarded-For (первый), REMOTE_ADDR.
		// ВНИМАНИЕ: эти заголовки тривиально подделываются, если запрос идёт напрямую к Apache,
		// минуя CF. Поэтому в продакшене на сайтах БЕЗ Cloudflare надёжнее всего REMOTE_ADDR.
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
	 * Проверка IP против whitelist (поддержка IPv4 + IPv4-CIDR).
	 * Если whitelist пуст — true (любой IP разрешён).
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

	/** Проверка IPv4 в CIDR. IPv6 не поддерживаем в v1. */
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
