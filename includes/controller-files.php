<?php
/**
 * SB_Files_Controller — file operations restricted to whitelisted directories.
 *
 * Whitelist (relative to ABSPATH):
 *   - wp-content/plugins/*       (read+write+delete, EXCEPT wp-content/plugins/site-bridge/*)
 *   - wp-content/themes/*        (read+write+delete)
 *   - wp-content/uploads/*       (read+write+delete)
 *   - wp-content/mu-plugins/*    (read+write+delete) — YES, allowed; otherwise we can't fix scenarios like
 *                                  "a mu-plugin is blocking auth".
 *
 * Denied:
 *   - wp-admin/*
 *   - wp-includes/*
 *   - wp-config.php
 *   - .htaccess (at any level) and any dotfile at the root
 *   - The site-bridge folder itself — can't edit our own code through this API.
 *     For self-update use /plugins/upload with overwrite=true.
 *
 * File size ≤ FILES_MAX_BYTES.
 * An automatic backup is written to `wp-content/uploads/site-bridge-backups/{YYYY}/{MM}/{path}_{ts}.bak` before any write or delete.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SB_Files_Controller {

	const FILES_MAX_BYTES = 20971520; // 20MB

	/** Whitelist (paths relative to ABSPATH, always end with '/'). */
	private static function allowed_prefixes() {
		return [
			'wp-content/plugins/',
			'wp-content/themes/',
			'wp-content/uploads/',
			'wp-content/mu-plugins/',
		];
	}

	/** Denied prefix — our own plugin directory. */
	private static function denied_self_prefix() {
		return 'wp-content/plugins/' . dirname( SITE_BRIDGE_BASENAME ) . '/';
	}

	/** GET /files?path=... */
	public static function read_file( WP_REST_Request $request ) {
		$rel = self::normalize_path( $request->get_param( 'path' ) );
		$check = self::validate_path( $rel, false );
		if ( is_wp_error( $check ) ) {
			return $check;
		}
		$abs = ABSPATH . $rel;
		if ( ! is_file( $abs ) ) {
			return SB_Response::not_found( 'File' );
		}
		$size = filesize( $abs );
		if ( $size > self::FILES_MAX_BYTES ) {
			return SB_Response::error( 'sb_too_large', 'File too large (' . $size . ' bytes).', 413 );
		}
		$content = file_get_contents( $abs );
		$is_binary = self::looks_binary( substr( $content, 0, 8000 ) );

		return SB_Response::ok( [
			'path'        => $rel,
			'size'        => $size,
			'mtime'       => filemtime( $abs ),
			'sha256'      => hash( 'sha256', $content ),
			'binary'      => $is_binary,
			'content'     => $is_binary ? null : $content,
			'content_b64' => $is_binary ? base64_encode( $content ) : null,
		] );
	}

	/**
	 * PUT /files?path=...
	 * Body JSON: { "content": "...", "content_b64": "...", "create_dirs": true, "mode": 0644 }
	 */
	public static function write_file( WP_REST_Request $request ) {
		$rel = self::normalize_path( $request->get_param( 'path' ) );
		$check = self::validate_path( $rel, true );
		if ( is_wp_error( $check ) ) {
			return $check;
		}
		$abs = ABSPATH . $rel;

		$payload = $request->get_json_params() ?: [];
		if ( isset( $payload['content_b64'] ) ) {
			$bytes = base64_decode( (string) $payload['content_b64'], true );
			if ( $bytes === false ) {
				return SB_Response::validation( 'content_b64 is not valid base64.' );
			}
		} elseif ( isset( $payload['content'] ) ) {
			$bytes = (string) $payload['content'];
		} else {
			return SB_Response::validation( 'Provide "content" or "content_b64".' );
		}

		if ( strlen( $bytes ) > self::FILES_MAX_BYTES ) {
			return SB_Response::error( 'sb_too_large', 'Body too large.', 413 );
		}

		$dir = dirname( $abs );
		if ( ! is_dir( $dir ) ) {
			if ( ! empty( $payload['create_dirs'] ) ) {
				wp_mkdir_p( $dir );
			} else {
				return SB_Response::validation( 'Directory does not exist (pass create_dirs=true to create).' );
			}
		}

		// Back up if the file already exists
		$backup_path = null;
		if ( is_file( $abs ) ) {
			$backup_path = self::backup_file( $abs, $rel );
		}

		$bytes_written = file_put_contents( $abs, $bytes );
		if ( $bytes_written === false ) {
			return SB_Response::internal( 'file_put_contents failed.' );
		}

		// Optional chmod
		if ( isset( $payload['mode'] ) ) {
			@chmod( $abs, (int) $payload['mode'] );
		}

		SB_Audit::log_dangerous_op( 'files.write', [
			'path' => $rel, 'size' => $bytes_written, 'backup' => $backup_path,
		] );

		return SB_Response::ok( [
			'written'     => true,
			'path'        => $rel,
			'size'        => $bytes_written,
			'sha256'      => hash( 'sha256', $bytes ),
			'backup_path' => $backup_path,
		] );
	}

	/** DELETE /files?path=... */
	public static function delete_file( WP_REST_Request $request ) {
		$rel = self::normalize_path( $request->get_param( 'path' ) );
		$check = self::validate_path( $rel, true );
		if ( is_wp_error( $check ) ) {
			return $check;
		}
		$abs = ABSPATH . $rel;
		if ( ! is_file( $abs ) ) {
			return SB_Response::not_found( 'File' );
		}
		$backup_path = self::backup_file( $abs, $rel );
		if ( ! @unlink( $abs ) ) {
			return SB_Response::internal( 'unlink failed.' );
		}
		SB_Audit::log_dangerous_op( 'files.delete', [ 'path' => $rel, 'backup' => $backup_path ] );
		return SB_Response::ok( [ 'deleted' => true, 'path' => $rel, 'backup_path' => $backup_path ] );
	}

	/** GET /files/list?path=... */
	public static function list_directory( WP_REST_Request $request ) {
		$rel = self::normalize_path( $request->get_param( 'path' ) ?: 'wp-content/plugins' );
		// For directory listing we allow reading dirs — append trailing slash before the check
		$rel_for_check = rtrim( $rel, '/' ) . '/';
		$check = self::validate_path_prefix( $rel_for_check );
		if ( is_wp_error( $check ) ) {
			return $check;
		}
		$abs = rtrim( ABSPATH . $rel, '/' );
		if ( ! is_dir( $abs ) ) {
			return SB_Response::not_found( 'Directory' );
		}
		$entries = [];
		foreach ( scandir( $abs ) as $entry ) {
			if ( $entry === '.' || $entry === '..' ) continue;
			$full = $abs . '/' . $entry;
			$entries[] = [
				'name'  => $entry,
				'type'  => is_dir( $full ) ? 'dir' : 'file',
				'size'  => is_file( $full ) ? filesize( $full ) : null,
				'mtime' => filemtime( $full ),
			];
		}
		return SB_Response::ok( [ 'path' => $rel, 'count' => count( $entries ), 'entries' => $entries ] );
	}

	// === Validation & helpers ===

	private static function normalize_path( $path ) {
		$p = (string) $path;
		// strip NUL bytes, normalise slashes, drop leading slashes, defuse '../' traversals
		$p = str_replace( "\0", '', $p );
		$p = str_replace( '\\', '/', $p );
		$p = ltrim( $p, '/' );
		// '..' is handled separately below
		return $p;
	}

	/** Verify the path falls under one of the allowed prefixes and isn't denied. */
	private static function validate_path( $rel, $for_write ) {
		if ( $rel === '' ) {
			return SB_Response::validation( 'Empty path.' );
		}
		if ( strpos( $rel, '..' ) !== false ) {
			return SB_Response::validation( 'Path traversal denied.' );
		}
		return self::validate_path_prefix( $rel );
	}

	private static function validate_path_prefix( $rel ) {
		// Block .htaccess / wp-config / dotfiles at root
		$basename = basename( $rel );
		if ( in_array( strtolower( $basename ), [ 'wp-config.php', '.htaccess', '.htpasswd', '.env' ], true ) ) {
			return SB_Response::validation( 'Editing this file is denied by Site Bridge policy.' );
		}
		// Forbid writes into our own plugin directory
		$self = self::denied_self_prefix();
		if ( strpos( $rel, $self ) === 0 ) {
			return SB_Response::validation( 'Cannot edit site-bridge own files via /files. Use /plugins/upload for self-update.' );
		}
		// Whitelist
		foreach ( self::allowed_prefixes() as $prefix ) {
			if ( strpos( $rel, $prefix ) === 0 ) {
				return true;
			}
		}
		return SB_Response::validation( 'Path outside whitelist. Allowed prefixes: ' . implode( ', ', self::allowed_prefixes() ) );
	}

	private static function backup_file( $abs, $rel ) {
		$ts  = gmdate( 'Y/m' );
		$base = wp_upload_dir()['basedir'] . '/site-bridge-backups/' . $ts . '/';
		wp_mkdir_p( $base );
		$safe = str_replace( '/', '__', $rel );
		$path = $base . $safe . '_' . time() . '.bak';
		@copy( $abs, $path );
		return $path;
	}

	private static function looks_binary( $sample ) {
		// Simple heuristic: if many non-printable bytes appear in the first 8 KB → binary
		if ( $sample === '' ) {
			return false;
		}
		$len = strlen( $sample );
		$non_printable = 0;
		for ( $i = 0; $i < $len; $i++ ) {
			$o = ord( $sample[ $i ] );
			if ( $o === 9 || $o === 10 || $o === 13 ) continue;
			if ( $o < 32 || $o === 127 ) $non_printable++;
		}
		return ( $non_printable / max( 1, $len ) ) > 0.10;
	}
}
