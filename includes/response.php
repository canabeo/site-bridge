<?php
/**
 * SB_Response — helpers for consistent REST responses.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SB_Response {

	/** Success. */
	public static function ok( $data = [], $status = 200 ) {
		return new WP_REST_Response( $data, $status );
	}

	/** Error in a consistent format. */
	public static function error( $code, $message, $status = 400, $extra = [] ) {
		return new WP_Error( $code, $message, array_merge( [ 'status' => $status ], $extra ) );
	}

	/** 404 not found. */
	public static function not_found( $what = 'Resource' ) {
		return self::error( 'sb_not_found', $what . ' not found.', 404 );
	}

	/** 422 validation error. */
	public static function validation( $message, $errors = [] ) {
		return self::error( 'sb_validation', $message, 422, [ 'errors' => $errors ] );
	}

	/** 500 internal error. */
	public static function internal( $message = 'Internal error.' ) {
		return self::error( 'sb_internal', $message, 500 );
	}
}
