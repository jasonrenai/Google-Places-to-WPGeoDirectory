<?php
/**
 * OpenAI provider.
 *
 * @package GPTG
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once GPTG_PLUGIN_DIR . 'includes/ai/interface-gptg-ai-provider.php';

/**
 * OpenAI API integration.
 */
class GPTG_AI_OpenAI implements GPTG_AI_Provider {

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
	public function __construct( $api_key, $model = 'gpt-4o-mini' ) {
		$this->api_key = $api_key;
		$this->model   = $model ? $model : 'gpt-4o-mini';
	}

	/**
	 * @inheritDoc
	 */
	public function get_id() {
		return 'openai';
	}

	/**
	 * @inheritDoc
	 */
	public function complete( $system, $user ) {
		if ( empty( $this->api_key ) ) {
			return new WP_Error( 'no_ai_key', __( 'OpenAI API key is not set.', 'gptg' ) );
		}

		$response = wp_remote_post(
			'https://api.openai.com/v1/chat/completions',
			array(
				'timeout' => 90,
				'headers' => array(
					'Authorization' => 'Bearer ' . $this->api_key,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'model'       => $this->model,
						'messages'    => array(
							array( 'role' => 'system', 'content' => $system ),
							array( 'role' => 'user', 'content' => $user ),
						),
						'temperature' => 0.2,
						'max_tokens'  => 2048,
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
			$msg = isset( $body['error']['message'] ) ? $body['error']['message'] : __( 'OpenAI request failed.', 'gptg' );
			return new WP_Error( 'openai_error', $msg );
		}

		if ( empty( $body['choices'][0]['message']['content'] ) ) {
			return new WP_Error( 'openai_error', __( 'Empty response from OpenAI.', 'gptg' ) );
		}

		return trim( $body['choices'][0]['message']['content'] );
	}
}
