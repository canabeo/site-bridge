<?php
/**
 * Plugin Name: Site Bridge
 * Plugin URI:  https://alumservis.com.ua/
 * Description: Безопасный программный доступ к WordPress-сайту через HMAC-подписанный REST API. Pages, plugins, files, cache, forms.
 * Version:     1.0.0
 * Author:      Canabeo
 * License:     GPL v2 or later
 * Text Domain: site-bridge
 * Requires PHP: 7.4
 * Requires at least: 6.2
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SITE_BRIDGE_VERSION', '1.0.0' );
define( 'SITE_BRIDGE_FILE',    __FILE__ );
define( 'SITE_BRIDGE_DIR',     plugin_dir_path( __FILE__ ) );
define( 'SITE_BRIDGE_URL',     plugin_dir_url( __FILE__ ) );
define( 'SITE_BRIDGE_BASENAME', plugin_basename( __FILE__ ) );
define( 'SITE_BRIDGE_REST_NAMESPACE', 'sb/v1' );

// Killswitch — мгновенно выключает всю логику плагина, оставляя только заглушки на REST.
// Включается через `define('SITE_BRIDGE_DISABLED', true);` в wp-config.php.
if ( defined( 'SITE_BRIDGE_DISABLED' ) && SITE_BRIDGE_DISABLED === true ) {
	add_action( 'rest_api_init', function() {
		register_rest_route( SITE_BRIDGE_REST_NAMESPACE, '/(?P<any>.*)', [
			'methods'             => [ 'GET', 'POST', 'PUT', 'PATCH', 'DELETE' ],
			'permission_callback' => '__return_true',
			'callback'            => function() {
				return new WP_Error( 'site_bridge_disabled', 'Site Bridge is disabled via SITE_BRIDGE_DISABLED constant.', [ 'status' => 503 ] );
			},
		] );
	} );
	return;
}

// Подключение модулей
require_once SITE_BRIDGE_DIR . 'includes/config.php';
require_once SITE_BRIDGE_DIR . 'includes/installer.php';
require_once SITE_BRIDGE_DIR . 'includes/audit.php';
require_once SITE_BRIDGE_DIR . 'includes/email-alerter.php';
require_once SITE_BRIDGE_DIR . 'includes/auth.php';
require_once SITE_BRIDGE_DIR . 'includes/response.php';
require_once SITE_BRIDGE_DIR . 'includes/rest.php';
require_once SITE_BRIDGE_DIR . 'includes/controller-system.php';
require_once SITE_BRIDGE_DIR . 'includes/controller-pages.php';
require_once SITE_BRIDGE_DIR . 'includes/controller-backups.php';
require_once SITE_BRIDGE_DIR . 'includes/controller-plugins.php';
require_once SITE_BRIDGE_DIR . 'includes/controller-files.php';
require_once SITE_BRIDGE_DIR . 'includes/controller-cache.php';
require_once SITE_BRIDGE_DIR . 'includes/controller-forms.php';

// Хуки активации/деактивации
register_activation_hook( __FILE__, [ 'SB_Installer', 'activate' ] );
register_deactivation_hook( __FILE__, [ 'SB_Installer', 'deactivate' ] );

// Регистрация REST-маршрутов
add_action( 'rest_api_init', [ 'SB_REST', 'register_routes' ] );

// Гарантия, что таблицы существуют (на случай обновления плагина)
add_action( 'plugins_loaded', [ 'SB_Installer', 'maybe_upgrade' ] );

// Регистрируем breakdance_data в REST API meta (требуется для PATCH meta через cc)
// Не используем show_in_rest=true в register_post_meta — мы читаем/пишем сами через get_post_meta/update_post_meta.
// Этот блок остаётся как комментарий: смотри SB_Pages_Controller::update_meta().
