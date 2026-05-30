<?php
/**
 * Plugin Name: Site Bridge
 * Plugin URI:  https://github.com/canabeo/site-bridge
 * Description: Designed to let any AI agent safely manage and edit your WordPress site. Exposes an HMAC-signed REST API for pages, plugins, files, cache and forms. Builder-agnostic — works with Breakdance, Elementor, Gutenberg, and WPBakery.
 * Version:     1.0.3-php72
 * Author:      Canabeo
 * Author URI:  https://github.com/canabeo
 * License:     GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: site-bridge
 * Requires PHP: 7.2
 * Requires at least: 6.2
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SITE_BRIDGE_VERSION', '1.0.3-php72' );
define( 'SITE_BRIDGE_FILE',    __FILE__ );
define( 'SITE_BRIDGE_DIR',     plugin_dir_path( __FILE__ ) );
define( 'SITE_BRIDGE_URL',     plugin_dir_url( __FILE__ ) );
define( 'SITE_BRIDGE_BASENAME', plugin_basename( __FILE__ ) );
define( 'SITE_BRIDGE_REST_NAMESPACE', 'sb/v1' );

// Killswitch — instantly disables all plugin logic, leaving only stub REST routes.
// Toggle via `define('SITE_BRIDGE_DISABLED', true);` in wp-config.php.
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

// Module includes
require_once SITE_BRIDGE_DIR . 'includes/config.php';
require_once SITE_BRIDGE_DIR . 'includes/installer.php';
require_once SITE_BRIDGE_DIR . 'includes/audit.php';
require_once SITE_BRIDGE_DIR . 'includes/meta-helper.php';
require_once SITE_BRIDGE_DIR . 'includes/post-helper.php';
require_once SITE_BRIDGE_DIR . 'includes/email-alerter.php';
require_once SITE_BRIDGE_DIR . 'includes/auth.php';
require_once SITE_BRIDGE_DIR . 'includes/response.php';
require_once SITE_BRIDGE_DIR . 'includes/rest.php';
require_once SITE_BRIDGE_DIR . 'includes/controller-system.php';
require_once SITE_BRIDGE_DIR . 'includes/controller-pages.php';
require_once SITE_BRIDGE_DIR . 'includes/controller-backups.php';
require_once SITE_BRIDGE_DIR . 'includes/controller-blocks.php';
require_once SITE_BRIDGE_DIR . 'includes/controller-plugins.php';
require_once SITE_BRIDGE_DIR . 'includes/controller-files.php';
require_once SITE_BRIDGE_DIR . 'includes/controller-cache.php';
require_once SITE_BRIDGE_DIR . 'includes/controller-forms.php';
require_once SITE_BRIDGE_DIR . 'includes/controller-snippets.php';
require_once SITE_BRIDGE_DIR . 'includes/admin-bootstrap.php';

// Activation / deactivation hooks
register_activation_hook( __FILE__, [ 'SB_Installer', 'activate' ] );
register_deactivation_hook( __FILE__, [ 'SB_Installer', 'deactivate' ] );

// REST route registration
add_action( 'rest_api_init', [ 'SB_REST', 'register_routes' ] );

// Ensure tables exist (in case the plugin was upgraded by file replacement)
add_action( 'plugins_loaded', [ 'SB_Installer', 'maybe_upgrade' ] );

// Note: we do NOT use register_post_meta(show_in_rest=true) — meta is read/written
// directly via get_post_meta / SB_Meta. See SB_Pages_Controller::update_page().
