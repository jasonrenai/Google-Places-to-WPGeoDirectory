<?php
/**
 * Apollo.io contact enrichment.
 *
 * @package GPTG
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once GPTG_PLUGIN_DIR . 'includes/enrichment/interface-gptg-contact-provider.php';

/**
 * Apollo.io organization enrichment.
 */
class GPTG_Contact_Apollo implements GPTG_Contact_Provider {

	/**
	 * @var string
	 */
	private $api_key;

	/**
	 * Constructor.
	 *
	 * @param string $api_key API key.
	 */
	public function __construct( $api_key ) {
		$this->api_key = $api_key;
	}

	/**
	 * @inheritDoc
	 */
	public function get_id() {
		return 'apollo';
	}

	/**
	 * @inheritDoc
	 */
	public function enrich_by_domain( $domain ) {
		if ( empty( $this->api_key ) ) {
			return new WP_Error( 'no_apollo_key', __( 'Apollo API key is not set.', 'gptg' ) );
		}

		$url = add_query_arg( 'domain', $domain, 'https://api.apollo.io/api/v1/organizations/enrich' );

		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 30,
				'headers' => array(
					'Accept'    => 'application/json',
					'x-api-key' => $this->api_key,
					'Cache-Control' => 'no-cache',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 404 === $code || empty( $body['organization'] ) ) {
			return new WP_Error( 'apollo_not_found', __( 'Apollo could not find organization data for this domain.', 'gptg' ) );
		}

		if ( $code < 200 || $code >= 300 ) {
			$message = isset( $body['error'] ) ? $body['error'] : __( 'Apollo API request failed.', 'gptg' );
			return new WP_Error( 'apollo_api_error', $message );
		}

		$org = $body['organization'];
		$contact = array(
			'email'     => '',
			'facebook'  => ! empty( $org['facebook_url'] ) ? esc_url_raw( $org['facebook_url'] ) : '',
			'twitter'   => ! empty( $org['twitter_url'] ) ? esc_url_raw( $org['twitter_url'] ) : '',
			'instagram' => '',
			'linkedin'  => ! empty( $org['linkedin_url'] ) ? esc_url_raw( $org['linkedin_url'] ) : '',
			'source'    => 'apollo',
		);

		if ( ! empty( $org['primary_email'] ) ) {
			$contact['email'] = sanitize_email( $org['primary_email'] );
		} elseif ( ! empty( $org['email'] ) ) {
			$contact['email'] = sanitize_email( $org['email'] );
		}

		if ( empty( $contact['email'] ) && empty( $contact['facebook'] ) && empty( $contact['twitter'] ) && empty( $contact['linkedin'] ) ) {
			return new WP_Error( 'apollo_not_found', __( 'Apollo returned no usable contact fields for this domain.', 'gptg' ) );
		}

		return $contact;
	}
}
