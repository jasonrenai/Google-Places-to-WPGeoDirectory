<?php
/**
 * AI provider interface.
 *
 * @package GPTG
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Interface GPTG_AI_Provider
 */
interface GPTG_AI_Provider {

	/**
	 * Provider slug.
	 *
	 * @return string
	 */
	public function get_id();

	/**
	 * Complete a chat prompt and return raw text.
	 *
	 * @param string $system System message.
	 * @param string $user   User message.
	 * @return string|WP_Error
	 */
	public function complete( $system, $user );
}
