<?php
/**
 * Contact enrichment provider interface.
 *
 * @package GPTG
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Interface GPTG_Contact_Provider
 */
interface GPTG_Contact_Provider {

	/**
	 * Provider slug.
	 *
	 * @return string
	 */
	public function get_id();

	/**
	 * Enrich contact data by domain.
	 *
	 * @param string $domain Domain without protocol.
	 * @return array|WP_Error Normalized contact fields.
	 */
	public function enrich_by_domain( $domain );
}
