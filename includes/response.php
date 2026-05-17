<?php
/**
 * SB_Response — helpers для REST-ответов.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SB_Response {

	/** Успех. */
	public static function ok( $data = [], $status = 200 ) {
		return new WP_REST_Response( $data, $status );
	}

	/** Ошибка с консистентным форматом. */
	public static function error( $code, $message, $status = 400, $extra = [] ) {
		return new WP_Error( $code, $message, array_merge( [ 'status' => $status ], $extra ) );
	}

	/** 404. */
	public static function not_found( $what = 'Resource' ) {
		return self::error( 'sb_not_found', $what . ' not found.', 404 );
	}

	/** 422 — валидация. */
	public static function validation( $message, $errors = [] ) {
		return self::error( 'sb_validation', $message, 422, [ 'errors' => $errors ] );
	}

	/** 500 — внутренняя. */
	public static function internal( $message = 'Internal error.' ) {
		return self::error( 'sb_internal', $message, 500 );
	}
}
