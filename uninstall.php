<?php
/**
 * Uninstall handler — вызывается WP при удалении плагина через wp-admin.
 *
 * Дропает таблицы, удаляет опции и cron-хуки. Не удаляет файлы — это делает WP сам.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once __DIR__ . '/includes/audit.php';      // нужно для константы TABLE
require_once __DIR__ . '/includes/installer.php';

SB_Installer::drop_all();
