<?php
/**
 * SB_Plugins_Controller — plugin management.
 *
 * Capabilities:
 *   GET    /plugins                       — list all plugins
 *   POST   /plugins/upload                — upload ZIP (multipart or base64 in JSON), install, optionally activate
 *   POST   /plugins/{slug}/activate
 *   POST   /plugins/{slug}/deactivate
 *   DELETE /plugins/{slug}
 *
 * NOTE: deactivating Site Bridge itself is forbidden — subsequent requests would otherwise break.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SB_Plugins_Controller {

	public static function list_plugins( WP_REST_Request $request ) {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$active = (array) get_option( 'active_plugins', [] );
		$all    = get_plugins();

		$items = [];
		foreach ( $all as $file => $meta ) {
			$items[] = [
				'file'        => $file,
				'slug'        => dirname( $file ),
				'name'        => $meta['Name']        ?? '',
				'version'     => $meta['Version']     ?? '',
				'author'      => $meta['Author']      ?? '',
				'description' => $meta['Description'] ?? '',
				'plugin_uri'  => $meta['PluginURI']   ?? '',
				'active'      => in_array( $file, $active, true ),
			];
		}
		return SB_Response::ok( [ 'count' => count( $items ), 'items' => $items ] );
	}

	/**
	 * POST /plugins/upload
	 *
	 * Accepts JSON:
	 *   {
	 *     "filename": "my-plugin-1.0.0.zip",
	 *     "zip_base64": "<base64-encoded ZIP bytes>",
	 *     "activate": true,
	 *     "overwrite": true        // reinstall on top of existing
	 *   }
	 *
	 * Alternatively — multipart/form-data with a 'zip' field (for large ZIPs).
	 */
	public static function upload_plugin( WP_REST_Request $request ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

		$payload = $request->get_json_params() ?: [];
		$files   = $request->get_file_params();

		$zip_path = null;
		$filename = null;

		// Multipart
		if ( isset( $files['zip']['tmp_name'] ) && is_uploaded_file( $files['zip']['tmp_name'] ) ) {
			$filename = $files['zip']['name'];
			$zip_path = $files['zip']['tmp_name'];
		}
		// Base64 in JSON
		elseif ( ! empty( $payload['zip_base64'] ) ) {
			$bytes = base64_decode( $payload['zip_base64'], true );
			if ( $bytes === false ) {
				return SB_Response::validation( 'zip_base64 is not valid base64.' );
			}
			$filename = isset( $payload['filename'] ) ? basename( (string) $payload['filename'] ) : 'upload.zip';
			$tmp_dir  = get_temp_dir();
			$zip_path = wp_tempnam( $filename, $tmp_dir );
			if ( file_put_contents( $zip_path, $bytes ) === false ) {
				return SB_Response::internal( 'Cannot write temp ZIP.' );
			}
		} else {
			return SB_Response::validation( 'Provide multipart "zip" or JSON "zip_base64".' );
		}

		$activate  = ! empty( $payload['activate'] );
		$overwrite = ! empty( $payload['overwrite'] );

		// install_plugins_upload is used for install/upgrade
		WP_Filesystem();
		$upgrader = new Plugin_Upgrader( new WP_Ajax_Upgrader_Skin() );
		$result   = $upgrader->install( $zip_path, [ 'overwrite_package' => $overwrite ] );

		// Remove tmp if we created it ourselves
		if ( ! isset( $files['zip']['tmp_name'] ) && $zip_path && file_exists( $zip_path ) ) {
			@unlink( $zip_path );
		}

		if ( is_wp_error( $result ) ) {
			return SB_Response::internal( 'Install failed: ' . $result->get_error_message() );
		}
		if ( ! $result ) {
			return SB_Response::internal( 'Install failed (unknown).' );
		}

		$installed_file = $upgrader->plugin_info();
		if ( ! $installed_file ) {
			return SB_Response::internal( 'Could not determine installed plugin file.' );
		}

		SB_Audit::log_dangerous_op( 'plugin.install', [
			'file'      => $installed_file,
			'filename'  => $filename,
			'overwrite' => $overwrite,
		] );

		$activated = false;
		if ( $activate ) {
			$res = activate_plugin( $installed_file );
			if ( is_wp_error( $res ) ) {
				return SB_Response::ok( [
					'installed'      => true,
					'plugin_file'    => $installed_file,
					'activated'      => false,
					'activate_error' => $res->get_error_message(),
				] );
			}
			$activated = true;
		}

		return SB_Response::ok( [
			'installed'   => true,
			'plugin_file' => $installed_file,
			'activated'   => $activated,
		] );
	}

	public static function activate_plugin( WP_REST_Request $request ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		$slug = sanitize_text_field( $request->get_param( 'slug' ) );
		$file = self::resolve_plugin_file_by_slug( $slug );
		if ( ! $file ) {
			return SB_Response::not_found( 'Plugin "' . $slug . '"' );
		}

		$res = activate_plugin( $file );
		if ( is_wp_error( $res ) ) {
			return SB_Response::internal( 'Activation failed: ' . $res->get_error_message() );
		}
		SB_Audit::log_dangerous_op( 'plugin.activate', [ 'file' => $file ] );
		return SB_Response::ok( [ 'activated' => true, 'plugin_file' => $file ] );
	}

	public static function deactivate_plugin( WP_REST_Request $request ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		$slug = sanitize_text_field( $request->get_param( 'slug' ) );
		$file = self::resolve_plugin_file_by_slug( $slug );
		if ( ! $file ) {
			return SB_Response::not_found( 'Plugin "' . $slug . '"' );
		}
		// Self-deactivation guard
		if ( $file === SITE_BRIDGE_BASENAME ) {
			return SB_Response::validation( 'Cannot deactivate site-bridge itself via API.' );
		}

		deactivate_plugins( $file );
		SB_Audit::log_dangerous_op( 'plugin.deactivate', [ 'file' => $file ] );
		return SB_Response::ok( [ 'deactivated' => true, 'plugin_file' => $file ] );
	}

	public static function delete_plugin( WP_REST_Request $request ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';

		$slug = sanitize_text_field( $request->get_param( 'slug' ) );
		$file = self::resolve_plugin_file_by_slug( $slug );
		if ( ! $file ) {
			return SB_Response::not_found( 'Plugin "' . $slug . '"' );
		}
		if ( $file === SITE_BRIDGE_BASENAME ) {
			return SB_Response::validation( 'Cannot delete site-bridge itself via API.' );
		}

		// Deactivate before delete
		if ( is_plugin_active( $file ) ) {
			deactivate_plugins( $file );
		}
		WP_Filesystem();
		$res = delete_plugins( [ $file ] );
		if ( is_wp_error( $res ) ) {
			return SB_Response::internal( 'Delete failed: ' . $res->get_error_message() );
		}
		if ( $res === false ) {
			return SB_Response::internal( 'Delete failed (false).' );
		}
		SB_Audit::log_dangerous_op( 'plugin.delete', [ 'file' => $file ] );
		return SB_Response::ok( [ 'deleted' => true, 'plugin_file' => $file ] );
	}

	// === Helpers ===

	/** Resolve plugin "file" (e.g. "foo-bar/foo-bar.php") from a slug ("foo-bar"). */
	private static function resolve_plugin_file_by_slug( $slug ) {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$all = get_plugins();
		foreach ( array_keys( $all ) as $file ) {
			if ( dirname( $file ) === $slug ) {
				return $file;
			}
			// Single-file plugins — slug = basename without extension
			if ( dirname( $file ) === '.' && pathinfo( $file, PATHINFO_FILENAME ) === $slug ) {
				return $file;
			}
		}
		return null;
	}
}
