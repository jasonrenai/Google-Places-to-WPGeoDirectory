<?php
/**
 * Contact enrichment transient cache (by domain).
 *
 * @package GPTG
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cache helper for Hunter/Apollo contact payloads.
 */
class GPTG_Contact_Cache {

	const PREFIX = 'gptg_contact_';
	const TTL    = DAY_IN_SECONDS;

	/**
	 * Cache key for domain.
	 *
	 * @param string $domain Domain name.
	 * @return string
	 */
	public static function key( $domain ) {
		return self::PREFIX . md5( strtolower( (string) $domain ) );
	}

	/**
	 * Get cached contact data.
	 *
	 * @param string $domain Domain.
	 * @return array|false
	 */
	public static function get( $domain ) {
		$data = get_transient( self::key( $domain ) );
		return is_array( $data ) ? $data : false;
	}

	/**
	 * Store contact data.
	 *
	 * @param string $domain  Domain.
	 * @param array  $contact Contact payload.
	 */
	public static function set( $domain, $contact ) {
		set_transient( self::key( $domain ), $contact, self::TTL );
	}
}
