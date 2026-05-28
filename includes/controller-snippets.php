<?php
/**
 * SB_Snippets_Controller — CRUD for the Code Snippets plugin (codesnippetspro/code-snippets).
 *
 * Endpoints:
 *   GET    /snippets                      — list all snippets (filter: scope, active)
 *   GET    /snippets/{id}                 — one snippet
 *   POST   /snippets                      — create
 *   PATCH  /snippets/{id}                 — partial update
 *   DELETE /snippets/{id}                 — delete
 *   POST   /snippets/{id}/activate        — set active=1
 *   POST   /snippets/{id}/deactivate      — set active=0
 *
 * Implementation strategy:
 *   Calls into Code Snippets' own public functions (save_snippet, activate_snippet,
 *   deactivate_snippet, delete_snippet, get_snippet, get_snippets). They handle:
 *     - PHP code validation (Validator class)
 *     - <?php / ?> tag stripping
 *     - Cache invalidation (clean_snippets_cache)
 *     - recently-active list maintenance
 *     - do_action() hooks for other plugins listening on snippet changes
 *
 *   If the plugin is not installed/active → 503 sb_dep_missing.
 *
 * Valid scope values (from Code_Snippets\Model\Snippet::get_all_scopes()):
 *   PHP:  global, admin, front-end, single-use
 *   HTML: content, head-content, body-content, footer-content
 *   CSS:  admin-css, site-css
 *   JS:   site-head-js, site-footer-js
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SB_Snippets_Controller {

	const VALID_SCOPES = [
		'global', 'admin', 'front-end', 'single-use',
		'content', 'head-content', 'body-content', 'footer-content',
		'admin-css', 'site-css',
		'site-head-js', 'site-footer-js',
	];

	/** Verify Code Snippets plugin is installed and its functions are loaded. */
	private static function ensure_plugin() {
		if ( ! function_exists( 'Code_Snippets\\save_snippet' )
		  || ! function_exists( 'Code_Snippets\\get_snippets' )
		  || self::snippet_class() === null ) {
			return SB_Response::error(
				'sb_dep_missing',
				'Code Snippets plugin is not installed or not active. Install/activate code-snippets to use this endpoint.',
				503
			);
		}
		return true;
	}

	/**
	 * Resolve the Snippet class name across Code Snippets versions:
	 *   v3.x stable:  Code_Snippets\Snippet
	 *   master/dev:   Code_Snippets\Model\Snippet
	 */
	private static function snippet_class() {
		if ( class_exists( 'Code_Snippets\\Snippet' ) ) {
			return 'Code_Snippets\\Snippet';
		}
		if ( class_exists( 'Code_Snippets\\Model\\Snippet' ) ) {
			return 'Code_Snippets\\Model\\Snippet';
		}
		return null;
	}

	/** GET /snippets[?scope=global&active=1] */
	public static function list_snippets( WP_REST_Request $request ) {
		$check = self::ensure_plugin();
		if ( is_wp_error( $check ) ) {
			return $check;
		}

		$scope_filter  = $request->get_param( 'scope' );
		$active_filter = $request->get_param( 'active' );
		$active_filter = ( $active_filter === null || $active_filter === '' ) ? null : (int) $active_filter;

		$snippets = \Code_Snippets\get_snippets();
		$items = [];
		foreach ( $snippets as $s ) {
			if ( $scope_filter && $s->scope !== $scope_filter ) {
				continue;
			}
			if ( $active_filter !== null && (int) $s->active !== $active_filter ) {
				continue;
			}
			$items[] = self::summarize( $s );
		}

		return SB_Response::ok( [ 'count' => count( $items ), 'items' => $items ] );
	}

	/** GET /snippets/{id} */
	public static function get_snippet( WP_REST_Request $request ) {
		$check = self::ensure_plugin();
		if ( is_wp_error( $check ) ) {
			return $check;
		}
		$id = (int) $request->get_param( 'id' );
		$snippet = \Code_Snippets\get_snippet( $id );
		if ( ! $snippet || (int) $snippet->id === 0 ) {
			return SB_Response::not_found( 'Snippet' );
		}
		return SB_Response::ok( self::full( $snippet ) );
	}

	/** POST /snippets */
	public static function create_snippet( WP_REST_Request $request ) {
		$check = self::ensure_plugin();
		if ( is_wp_error( $check ) ) {
			return $check;
		}
		$payload = $request->get_json_params();
		if ( ! is_array( $payload ) ) {
			return SB_Response::validation( 'Body must be a JSON object.' );
		}

		$validation = self::validate_payload( $payload, /*for_update=*/false );
		if ( is_wp_error( $validation ) ) {
			return $validation;
		}

		$data = self::payload_to_snippet_array( $payload );
		$snippet_class = self::snippet_class();
		$snippet = new $snippet_class( $data );

		$saved = \Code_Snippets\save_snippet( $snippet );
		if ( ! $saved || (int) $saved->id === 0 ) {
			return SB_Response::internal( 'save_snippet() returned null.' );
		}

		SB_Audit::log_dangerous_op( 'snippet.create', [
			'id'     => (int) $saved->id,
			'name'   => $saved->name,
			'scope'  => $saved->scope,
			'active' => (int) $saved->active,
		] );

		return SB_Response::ok( self::full( $saved ), 201 );
	}

	/** PATCH /snippets/{id} */
	public static function update_snippet( WP_REST_Request $request ) {
		$check = self::ensure_plugin();
		if ( is_wp_error( $check ) ) {
			return $check;
		}
		$id = (int) $request->get_param( 'id' );
		$existing = \Code_Snippets\get_snippet( $id );
		if ( ! $existing || (int) $existing->id === 0 ) {
			return SB_Response::not_found( 'Snippet' );
		}

		$payload = $request->get_json_params();
		if ( ! is_array( $payload ) ) {
			return SB_Response::validation( 'Body must be a JSON object.' );
		}

		$validation = self::validate_payload( $payload, /*for_update=*/true );
		if ( is_wp_error( $validation ) ) {
			return $validation;
		}

		// Merge new fields on top of the existing snippet
		$existing_arr = self::full( $existing );  // includes 'desc', not 'description'
		$merge = self::payload_to_snippet_array( $payload );
		// Start from the existing field set
		$data = [
			'id'           => (int) $existing->id,
			'name'         => $existing->name,
			'desc'         => $existing->desc,
			'code'         => $existing->code,
			'tags'         => $existing->tags,
			'scope'        => $existing->scope,
			'condition_id' => (int) $existing->condition_id,
			'priority'     => (int) $existing->priority,
			'active'       => (bool) $existing->active,
			'network'      => $existing->network,
		];
		foreach ( $merge as $k => $v ) {
			$data[ $k ] = $v;
		}

		$snippet_class = self::snippet_class();
		$snippet = new $snippet_class( $data );

		$saved = \Code_Snippets\save_snippet( $snippet );
		if ( ! $saved || (int) $saved->id === 0 ) {
			return SB_Response::internal( 'save_snippet() returned null.' );
		}

		SB_Audit::log_dangerous_op( 'snippet.update', [
			'id'      => (int) $saved->id,
			'name'    => $saved->name,
			'scope'   => $saved->scope,
			'active'  => (int) $saved->active,
			'changed' => array_keys( $merge ),
		] );

		return SB_Response::ok( self::full( $saved ) );
	}

	/** DELETE /snippets/{id} */
	public static function delete_snippet( WP_REST_Request $request ) {
		$check = self::ensure_plugin();
		if ( is_wp_error( $check ) ) {
			return $check;
		}
		$id = (int) $request->get_param( 'id' );
		$snippet = \Code_Snippets\get_snippet( $id );
		if ( ! $snippet || (int) $snippet->id === 0 ) {
			return SB_Response::not_found( 'Snippet' );
		}
		if ( ! empty( $snippet->locked ) ) {
			return SB_Response::error( 'sb_locked', 'Snippet is locked and cannot be deleted.', 423 );
		}

		$ok = \Code_Snippets\delete_snippet( $id );
		if ( ! $ok ) {
			return SB_Response::internal( 'delete_snippet() returned false.' );
		}

		SB_Audit::log_dangerous_op( 'snippet.delete', [
			'id' => $id, 'name' => $snippet->name, 'scope' => $snippet->scope,
		] );

		return SB_Response::ok( [ 'deleted' => true, 'id' => $id ] );
	}

	/** POST /snippets/{id}/activate */
	public static function activate( WP_REST_Request $request ) {
		$check = self::ensure_plugin();
		if ( is_wp_error( $check ) ) {
			return $check;
		}
		$id = (int) $request->get_param( 'id' );
		$result = \Code_Snippets\activate_snippet( $id );
		// activate_snippet returns Snippet on success, string error message on failure
		if ( is_string( $result ) ) {
			return SB_Response::error( 'sb_activate_failed', $result, 422 );
		}
		if ( ! $result ) {
			return SB_Response::internal( 'activate_snippet() returned nothing.' );
		}
		SB_Audit::log_dangerous_op( 'snippet.activate', [
			'id' => (int) $result->id, 'name' => $result->name, 'scope' => $result->scope,
		] );
		return SB_Response::ok( self::full( $result ) );
	}

	/** POST /snippets/{id}/deactivate */
	public static function deactivate( WP_REST_Request $request ) {
		$check = self::ensure_plugin();
		if ( is_wp_error( $check ) ) {
			return $check;
		}
		$id = (int) $request->get_param( 'id' );
		$result = \Code_Snippets\deactivate_snippet( $id );
		if ( ! $result ) {
			return SB_Response::internal( 'deactivate_snippet() returned null (snippet may not exist).' );
		}
		SB_Audit::log_dangerous_op( 'snippet.deactivate', [
			'id' => (int) $result->id, 'name' => $result->name, 'scope' => $result->scope,
		] );
		return SB_Response::ok( self::full( $result ) );
	}

	// === Helpers ===

	/** Validate fields before save. */
	private static function validate_payload( array $p, bool $for_update ) {
		// For create: name and (code OR code_b64) required
		if ( ! $for_update ) {
			if ( ! isset( $p['name'] ) || trim( (string) $p['name'] ) === '' ) {
				return SB_Response::validation( 'Field "name" is required for new snippets.' );
			}
			if ( ! array_key_exists( 'code', $p ) && ! array_key_exists( 'code_b64', $p ) ) {
				return SB_Response::validation( 'Field "code" or "code_b64" is required for new snippets.' );
			}
		}
		if ( isset( $p['code_b64'] ) ) {
			if ( ! is_string( $p['code_b64'] ) || base64_decode( $p['code_b64'], true ) === false ) {
				return SB_Response::validation( '"code_b64" is not valid base64.' );
			}
		}

		if ( isset( $p['scope'] ) ) {
			if ( ! in_array( $p['scope'], self::VALID_SCOPES, true ) ) {
				return SB_Response::validation(
					'Invalid scope.',
					[ 'allowed' => self::VALID_SCOPES, 'got' => $p['scope'] ]
				);
			}
		}
		if ( isset( $p['priority'] ) && ! is_numeric( $p['priority'] ) ) {
			return SB_Response::validation( '"priority" must be numeric.' );
		}
		if ( isset( $p['tags'] ) && ! ( is_array( $p['tags'] ) || is_string( $p['tags'] ) ) ) {
			return SB_Response::validation( '"tags" must be an array or comma-separated string.' );
		}
		return true;
	}

	/** Translate API payload (with 'description', optional base64 code) into Snippet array (with 'desc'). */
	private static function payload_to_snippet_array( array $p ) {
		$out = [];
		$map = [
			'name'         => 'name',
			'description'  => 'desc',  // alias
			'desc'         => 'desc',
			'code'         => 'code',
			'tags'         => 'tags',
			'scope'        => 'scope',
			'priority'     => 'priority',
			'active'       => 'active',
			'condition_id' => 'condition_id',
		];
		foreach ( $map as $api_key => $field ) {
			if ( array_key_exists( $api_key, $p ) ) {
				$out[ $field ] = $p[ $api_key ];
			}
		}
		// Base64-encoded code (lets clients bypass WAFs that scan request bodies for
		// `<script>`, `document.createElement`, etc.). When present, overrides 'code'.
		if ( isset( $p['code_b64'] ) && is_string( $p['code_b64'] ) ) {
			$decoded = base64_decode( $p['code_b64'], true );
			if ( $decoded !== false ) {
				$out['code'] = $decoded;
			}
		}
		return $out;
	}

	/** Compact representation for list responses. */
	private static function summarize( $s ) {
		return [
			'id'       => (int) $s->id,
			'name'     => (string) $s->name,
			'scope'    => (string) $s->scope,
			'type'     => $s->type,
			'active'   => (bool) $s->active,
			'priority' => (int) $s->priority,
			'modified' => (string) $s->modified,
			'tags'     => array_values( (array) $s->tags ),
			'has_code' => $s->code !== '',
		];
	}

	/** Full representation including code. */
	private static function full( $s ) {
		return [
			'id'           => (int) $s->id,
			'name'         => (string) $s->name,
			'description'  => (string) $s->desc,
			'code'         => (string) $s->code,
			'tags'         => array_values( (array) $s->tags ),
			'scope'        => (string) $s->scope,
			'type'         => $s->type,
			'condition_id' => (int) $s->condition_id,
			'priority'     => (int) $s->priority,
			'active'       => (bool) $s->active,
			'trashed'      => (bool) ( $s->trashed ?? false ),
			'locked'       => (bool) ( $s->locked ?? false ),
			'modified'     => (string) $s->modified,
			'revision'     => (int) ( $s->revision ?? 1 ),
		];
	}
}
