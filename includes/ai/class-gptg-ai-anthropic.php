<?php
/**
 * Anthropic provider.
 *
 * @package GPTG
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once GPTG_PLUGIN_DIR . 'includes/ai/interface-gptg-ai-provider.php';

/**
 * Anthropic Claude API integration.
 */
class GPTG_AI_Anthropic implements GPTG_AI_Provider {

	/**
	 * @var string
	 */
	private $api_key;

	/**
	 * @var string
	 */
	private $model;

	/**
	 * Constructor.
	 *
	 * @param string $api_key API key.
	 * @param string $model   Model name.
	 */
	public function __construct( $api_key, $model = 'claude-3-5-haiku-latest' ) {
		$this->api_key = $api_key;
		$this->model   = $model ? $model : 'claude-3-5-haiku-latest';
	}

	/**
	 * @inheritDoc
	 */
	public function get_id() {
		return 'anthropic';
	}

	/**
	 * @inheritDoc
	 */
	public function complete( $system, $user ) {
		if ( empty( $this->api_key ) ) {
			return new WP_Error( 'no_ai_key', __( 'Anthropic API key is not set.', 'gptg' ) );
		}

		$response = wp_remote_post(
			'https://api.anthropic.com/v1/messages',
			array(
				'timeout' => 90,
				'headers' => array(
					'x-api-key'         => $this->api_key,
					'anthropic-version' => '2023-06-01',
					'Content-Type'      => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'model'      => $this->model,
						'max_tokens' => 2048,
						'system'     => $system,
						'messages'   => array(
							array( 'role' => 'user', 'content' => $user ),
						),
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code !== 200 ) {
			$msg = isset( $body['error']['message'] ) ? $body['error']['message'] : __( 'Anthropic request failed.', 'gptg' );
			return new WP_Error( 'anthropic_error', $msg );
		}

		if ( empty( $body['content'][0]['text'] ) ) {
			return new WP_Error( 'anthropic_error', __( 'Empty response from Anthropic.', 'gptg' ) );
		}

		return trim( $body['content'][0]['text'] );
	}
}
