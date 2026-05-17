<?php
/**
 * Hunter.io contact enrichment.
 *
 * @package GPTG
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once GPTG_PLUGIN_DIR . 'includes/enrichment/interface-gptg-contact-provider.php';

/**
 * Hunter.io API client.
 */
class GPTG_Contact_Hunter implements GPTG_Contact_Provider {

	/**
	 * @var string
	 */
	private $api_key;

	/**
	 * Last API error message for diagnostics.
	 *
	 * @var string
	 */
	private $last_error = '';

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
		return 'hunter';
	}

	/**
	 * Get last error message.
	 *
	 * @return string
	 */
	public function get_last_error() {
		return $this->last_error;
	}

	/**
	 * @inheritDoc
	 */
	public function enrich_by_domain( $domain ) {
		$this->last_error = '';

		if ( empty( $this->api_key ) ) {
			$this->last_error = __( 'Hunter API key is not set.', 'gptg' );
			return new WP_Error( 'no_hunter_key', $this->last_error );
		}

		$contact = array(
			'email'     => '',
			'facebook'  => '',
			'twitter'   => '',
			'instagram' => '',
			'linkedin'  => '',
			'source'    => 'hunter',
		);

		$search = $this->request( 'https://api.hunter.io/v2/domain-search', array( 'domain' => $domain ) );
		if ( is_wp_error( $search ) ) {
			$this->last_error = $search->get_error_message();
		} elseif ( ! empty( $search['data']['emails'] ) && is_array( $search['data']['emails'] ) ) {
			$contact['email'] = $this->pick_best_email( $search['data']['emails'] );
		}

		$company = $this->request( 'https://api.hunter.io/v2/companies/find', array( 'domain' => $domain ) );
		if ( is_wp_error( $company ) ) {
			if ( empty( $this->last_error ) ) {
				$this->last_error = $company->get_error_message();
			}
		} elseif ( ! empty( $company['data'] ) && is_array( $company['data'] ) ) {
			$contact = array_merge( $contact, $this->map_company_social( $company['data'] ) );
		}

		if ( $this->has_any_contact_field( $contact ) ) {
			return $contact;
		}

		if ( empty( $this->last_error ) ) {
			$this->last_error = __( 'Hunter could not find contact data for this domain.', 'gptg' );
		}

		return new WP_Error( 'hunter_not_found', $this->last_error );
	}

	/**
	 * Check if contact has any tracked field populated.
	 *
	 * @param array $contact Contact data.
	 * @return bool
	 */
	private function has_any_contact_field( $contact ) {
		foreach ( array( 'email', 'facebook', 'twitter', 'instagram', 'linkedin' ) as $field ) {
			if ( ! empty( $contact[ $field ] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * HTTP GET to Hunter API.
	 *
	 * @param string $url    Base URL.
	 * @param array  $params Query params.
	 * @return array|WP_Error Empty array on 404; WP_Error on hard failures.
	 */
	private function request( $url, $params ) {
		$params['api_key'] = $this->api_key;
		$url               = add_query_arg( $params, $url );

		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 30,
				'headers' => array( 'Accept' => 'application/json' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 404 === $code ) {
			return array();
		}

		if ( $code < 200 || $code >= 300 ) {
			$message = __( 'Hunter API request failed.', 'gptg' );
			if ( is_array( $body ) ) {
				if ( ! empty( $body['errors'][0]['details'] ) ) {
					$message = $body['errors'][0]['details'];
				} elseif ( ! empty( $body['errors'][0]['id'] ) ) {
					$message = $body['errors'][0]['id'];
				} elseif ( ! empty( $body['message'] ) ) {
					$message = $body['message'];
				}
			}
			return new WP_Error( 'hunter_api_error', $message, array( 'status' => $code ) );
		}

		return is_array( $body ) ? $body : array();
	}

	/**
	 * Pick best email from domain search results.
	 *
	 * @param array $emails Email list.
	 * @return string
	 */
	private function pick_best_email( $emails ) {
		$generic   = null;
		$best      = null;
		$best_conf = -1;

		foreach ( $emails as $email ) {
			if ( ! is_array( $email ) ) {
				continue;
			}
			$address = '';
			if ( ! empty( $email['value'] ) ) {
				$address = $email['value'];
			} elseif ( ! empty( $email['email'] ) ) {
				$address = $email['email'];
			}
			if ( empty( $address ) ) {
				continue;
			}
			$type = isset( $email['type'] ) ? $email['type'] : '';
			$conf = isset( $email['confidence'] ) ? (int) $email['confidence'] : 0;
			if ( 'generic' === $type && ! $generic ) {
				$generic = $address;
			}
			if ( $conf > $best_conf ) {
				$best_conf = $conf;
				$best      = $address;
			}
		}

		return $generic ? $generic : ( $best ? $best : '' );
	}

	/**
	 * Map Hunter company social handles to URLs.
	 *
	 * @param array $data Company data.
	 * @return array
	 */
	private function map_company_social( $data ) {
		$out = array();

		if ( ! empty( $data['linkedin']['handle'] ) ) {
			$out['linkedin'] = $this->social_url( 'linkedin', $data['linkedin']['handle'] );
		}
		if ( ! empty( $data['twitter']['handle'] ) ) {
			$out['twitter'] = $this->social_url( 'twitter', $data['twitter']['handle'] );
		}
		if ( ! empty( $data['facebook']['handle'] ) ) {
			$out['facebook'] = $this->social_url( 'facebook', $data['facebook']['handle'] );
		}
		if ( ! empty( $data['instagram']['handle'] ) ) {
			$out['instagram'] = $this->social_url( 'instagram', $data['instagram']['handle'] );
		}

		return $out;
	}

	/**
	 * Build social profile URL from handle.
	 *
	 * @param string $network Network key.
	 * @param string $handle  Handle or path.
	 * @return string
	 */
	private function social_url( $network, $handle ) {
		$handle = trim( (string) $handle, '/' );
		if ( preg_match( '#^https?://#i', $handle ) ) {
			return esc_url_raw( $handle );
		}

		switch ( $network ) {
			case 'linkedin':
				if ( 0 === strpos( $handle, 'company/' ) || 0 === strpos( $handle, 'in/' ) ) {
					return 'https://www.linkedin.com/' . $handle;
				}
				return 'https://www.linkedin.com/company/' . $handle;
			case 'twitter':
				return 'https://twitter.com/' . ltrim( $handle, '@' );
			case 'facebook':
				return 'https://www.facebook.com/' . $handle;
			case 'instagram':
				return 'https://www.instagram.com/' . ltrim( $handle, '@' );
			default:
				return '';
		}
	}
}
