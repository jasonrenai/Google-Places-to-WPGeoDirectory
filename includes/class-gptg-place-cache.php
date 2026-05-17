<?php
/**
 * Place details transient cache.
 *
 * @package GPTG
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cache helper for Google Place payloads.
 */
class GPTG_Place_Cache {

	const PREFIX = 'gptg_place_';
	const TTL    = DAY_IN_SECONDS;

	/**
	 * Cache key for place ID.
	 *
	 * @param string $place_id Google place ID.
	 * @return string
	 */
	public static function key( $place_id ) {
		return self::PREFIX . md5( (string) $place_id );
	}

	/**
	 * Get cached place.
	 *
	 * @param string $place_id Place ID.
	 * @return array|false
	 */
	public static function get( $place_id ) {
		$data = get_transient( self::key( $place_id ) );
		return is_array( $data ) ? $data : false;
	}

	/**
	 * Store place in cache.
	 *
	 * @param string $place_id Place ID.
	 * @param array  $place    Place data.
	 */
	public static function set( $place_id, $place ) {
		set_transient( self::key( $place_id ), $place, self::TTL );
	}
}
