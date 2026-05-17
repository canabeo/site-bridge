<?php
/**
 * Uninstall handler — invoked by WordPress when the plugin is deleted via wp-admin.
 *
 * Drops tables, removes options and cron hooks. Does not delete plugin files —
 * WordPress handles that itself.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once __DIR__ . '/includes/audit.php';      // needed for the TABLE constant
require_once __DIR__ . '/includes/installer.php';

SB_Installer::drop_all();
