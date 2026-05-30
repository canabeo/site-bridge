<?php
/**
 * SB_Installer — creates/upgrades database tables and options on plugin activation.
 *
 * Tables:
 *   {prefix}sb_audit          — request log
 *   {prefix}sb_page_backups   — page snapshots taken before edits
 *
 * Option:
 *   site_bridge_db_version    — current schema version (for future migrations)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SB_Installer {

	const DB_VERSION_OPT = 'site_bridge_db_version';
	const DB_VERSION     = '1.0.0';

	const CRON_HOOK_CLEANUP = 'site_bridge_cleanup_audit';

	public static function activate() {
		self::install_or_upgrade();
		// Schedule daily audit log cleanup
		if ( ! wp_next_scheduled( self::CRON_HOOK_CLEANUP ) ) {
			wp_schedule_event( time() + 600, 'daily', self::CRON_HOOK_CLEANUP );
		}
		// Generate an option-based secret when no constant is set in wp-config.php.
		// This makes the plugin installable through wp-admin Upload Plugin alone, on
		// hosts where editing wp-config.php is not possible.
		self::maybe_generate_option_secret();
	}

	/**
	 * If neither SITE_BRIDGE_SECRET constant nor sb_secret option exists, generate
	 * a 64-byte random secret (hex-encoded) and store it in wp_options with
	 * autoload disabled. Also flips a one-shot flag that the admin-notice handler
	 * reads to reveal the secret once to the site administrator.
	 */
	public static function maybe_generate_option_secret() {
		if ( defined( 'SITE_BRIDGE_SECRET' )
		  && strlen( (string) SITE_BRIDGE_SECRET ) >= SB_Config::MIN_SECRET_BYTES ) {
			return;  // user already configured a constant — nothing to do
		}
		$existing = get_option( SB_Config::OPT_SECRET );
		if ( is_string( $existing ) && strlen( $existing ) >= SB_Config::MIN_SECRET_BYTES ) {
			return;  // option already present
		}
		// 32 random bytes → 64 hex chars
		$secret = bin2hex( random_bytes( 32 ) );
		// autoload = no — secret only needed during /wp-json/sb/v1/* requests
		add_option( SB_Config::OPT_SECRET, $secret, '', 'no' );
		// Flag for admin-notice (shown once until the user dismisses it)
		update_option( 'sb_secret_show', 1, false );
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( self::CRON_HOOK_CLEANUP );
	}

	public static function maybe_upgrade() {
		$current = get_option( self::DB_VERSION_OPT );
		if ( $current !== self::DB_VERSION ) {
			self::install_or_upgrade();
		}
		// Wire up the cron handler (in case activation didn't run, e.g. manual install)
		add_action( self::CRON_HOOK_CLEANUP, [ 'SB_Audit', 'cleanup_old_records' ] );
	}

	private static function install_or_upgrade() {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();
		$audit_table     = $wpdb->prefix . SB_Audit::TABLE;
		$backups_table   = $wpdb->prefix . 'sb_page_backups';

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$sql_audit = "CREATE TABLE $audit_table (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			created_at DATETIME NOT NULL,
			remote_ip VARCHAR(45) NOT NULL,
			user_agent VARCHAR(255) NOT NULL DEFAULT '',
			method VARCHAR(10) NOT NULL,
			route VARCHAR(255) NOT NULL,
			query VARCHAR(255) NOT NULL DEFAULT '',
			status_code INT NOT NULL DEFAULT 0,
			request_body_hash CHAR(64) NOT NULL DEFAULT '',
			request_body_size INT NOT NULL DEFAULT 0,
			response_summary VARCHAR(255) NOT NULL DEFAULT '',
			duration_ms INT NOT NULL DEFAULT 0,
			auth_status VARCHAR(32) NOT NULL DEFAULT '',
			details LONGTEXT NULL,
			PRIMARY KEY (id),
			KEY created_at (created_at),
			KEY remote_ip (remote_ip),
			KEY auth_status (auth_status),
			KEY route (route)
		) $charset_collate;";

		$sql_backups = "CREATE TABLE $backups_table (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			page_id BIGINT UNSIGNED NOT NULL,
			created_at DATETIME NOT NULL,
			triggered_by VARCHAR(40) NOT NULL DEFAULT '',
			title_snapshot TEXT NULL,
			content_snapshot LONGTEXT NULL,
			meta_snapshot LONGTEXT NULL,
			notes VARCHAR(255) NOT NULL DEFAULT '',
			PRIMARY KEY (id),
			KEY page_id (page_id),
			KEY created_at (created_at)
		) $charset_collate;";

		dbDelta( $sql_audit );
		dbDelta( $sql_backups );

		update_option( self::DB_VERSION_OPT, self::DB_VERSION );
	}

	/** Full removal — called only from uninstall.php. */
	public static function drop_all() {
		global $wpdb;
		$audit_table   = $wpdb->prefix . SB_Audit::TABLE;
		$backups_table = $wpdb->prefix . 'sb_page_backups';

		$wpdb->query( "DROP TABLE IF EXISTS `$audit_table`" );
		$wpdb->query( "DROP TABLE IF EXISTS `$backups_table`" );
		delete_option( self::DB_VERSION_OPT );
		wp_clear_scheduled_hook( self::CRON_HOOK_CLEANUP );
	}
}
