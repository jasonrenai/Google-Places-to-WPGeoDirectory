<?php
/**
 * Contact enrichment orchestrator (Hunter or Apollo).
 *
 * @package GPTG
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once GPTG_PLUGIN_DIR . 'includes/class-gptg-contact-cache.php';
require_once GPTG_PLUGIN_DIR . 'includes/enrichment/interface-gptg-contact-provider.php';
require_once GPTG_PLUGIN_DIR . 'includes/enrichment/class-gptg-contact-hunter.php';
require_once GPTG_PLUGIN_DIR . 'includes/enrichment/class-gptg-contact-apollo.php';

/**
 * Enrich places with email and social URLs.
 */
class GPTG_Contact_Enricher {

	/**
	 * Last enrichment error message.
	 *
	 * @var string
	 */
	private static $last_error = '';

	/**
	 * Hosts that are not valid business website domains for Hunter lookup.
	 *
	 * @var array
	 */
	private static $blocked_hosts = array(
		'facebook.com',
		'fb.com',
		'instagram.com',
		'twitter.com',
		'x.com',
		'linkedin.com',
		'youtube.com',
		'google.com',
		'goo.gl',
		'bit.ly',
		'linktr.ee',
	);

	/**
	 * Tracked contact field keys.
	 *
	 * @return array
	 */
	public static function tracked_fields() {
		return array( 'email', 'facebook', 'twitter', 'instagram', 'linkedin' );
	}

	/**
	 * Whether contact enrichment is enabled.
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		if ( defined( 'GPTG_CONTACT_ENABLED' ) && GPTG_CONTACT_ENABLED ) {
			return true;
		}
		return '1' === get_option( 'gptg_contact_enabled', '' );
	}

	/**
	 * Get API key for provider.
	 *
	 * @param string $provider hunter|apollo.
	 * @return string
	 */
	public static function get_api_key( $provider ) {
		if ( 'hunter' === $provider ) {
			if ( defined( 'GPTG_HUNTER_API_KEY' ) && GPTG_HUNTER_API_KEY ) {
				return GPTG_HUNTER_API_KEY;
			}
			return get_option( 'gptg_hunter_api_key', '' );
		}
		if ( 'apollo' === $provider ) {
			if ( defined( 'GPTG_APOLLO_API_KEY' ) && GPTG_APOLLO_API_KEY ) {
				return GPTG_APOLLO_API_KEY;
			}
			return get_option( 'gptg_apollo_api_key', '' );
		}
		return '';
	}

	/**
	 * Active provider slug.
	 *
	 * @return string
	 */
	public static function get_provider_id() {
		$provider = get_option( 'gptg_contact_provider', 'hunter' );
		return in_array( $provider, array( 'hunter', 'apollo' ), true ) ? $provider : 'hunter';
	}

	/**
	 * Get provider instance.
	 *
	 * @return GPTG_Contact_Provider|null
	 */
	public static function get_provider() {
		$id  = self::get_provider_id();
		$key = self::get_api_key( $id );
		if ( empty( $key ) ) {
			return null;
		}
		if ( 'apollo' === $id ) {
			return new GPTG_Contact_Apollo( $key );
		}
		return new GPTG_Contact_Hunter( $key );
	}

	/**
	 * Extract domain from website URI.
	 *
	 * @param string $website_uri Website URL.
	 * @return string
	 */
	public static function extract_domain( $website_uri ) {
		if ( empty( $website_uri ) ) {
			return '';
		}

		if ( ! preg_match( '#^https?://#i', $website_uri ) ) {
			$website_uri = 'https://' . ltrim( $website_uri, '/' );
		}

		$host = wp_parse_url( $website_uri, PHP_URL_HOST );
		if ( ! $host ) {
			return '';
		}

		$host = strtolower( $host );
		if ( 0 === strpos( $host, 'www.' ) ) {
			$host = substr( $host, 4 );
		}

		$port = strpos( $host, ':' );
		if ( false !== $port ) {
			$host = substr( $host, 0, $port );
		}

		foreach ( self::$blocked_hosts as $blocked ) {
			if ( $host === $blocked || self::str_ends_with( $host, '.' . $blocked ) ) {
				return '';
			}
		}

		return $host;
	}

	/**
	 * Get last enrichment error.
	 *
	 * @return string
	 */
	public static function get_last_enrichment_error() {
		return self::$last_error;
	}

	/**
	 * Clear cached contact data for a domain.
	 *
	 * @param string $domain Domain name.
	 */
	public static function clear_domain_cache( $domain ) {
		if ( ! empty( $domain ) ) {
			delete_transient( GPTG_Contact_Cache::key( $domain ) );
		}
	}

	/**
	 * Whether contact array has any non-empty tracked field.
	 *
	 * @param array $contact Contact data.
	 * @return bool
	 */
	public static function has_contact_data( $contact ) {
		if ( ! is_array( $contact ) ) {
			return false;
		}
		foreach ( self::tracked_fields() as $field ) {
			if ( ! empty( $contact[ $field ] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Polyfill for str_ends_with on older PHP.
	 *
	 * @param string $haystack Haystack.
	 * @param string $needle   Needle.
	 * @return bool
	 */
	private static function str_ends_with( $haystack, $needle ) {
		if ( function_exists( 'str_ends_with' ) ) {
			return str_ends_with( $haystack, $needle );
		}
		$len = strlen( $needle );
		if ( 0 === $len ) {
			return true;
		}
		return substr( $haystack, -$len ) === $needle;
	}

	/**
	 * Get gptgContact from place (initialized).
	 *
	 * @param array $place Place data.
	 * @return array
	 */
	public static function get_contact( $place ) {
		if ( ! isset( $place['gptgContact'] ) || ! is_array( $place['gptgContact'] ) ) {
			return array();
		}
		return $place['gptgContact'];
	}

	/**
	 * Whether any tracked field still needs enrichment.
	 *
	 * @param array $place Place data.
	 * @return bool
	 */
	public static function needs_enrichment( $place ) {
		if ( ! self::is_enabled() ) {
			return false;
		}
		$domain = self::extract_domain( isset( $place['websiteUri'] ) ? $place['websiteUri'] : '' );
		if ( empty( $domain ) ) {
			return false;
		}
		$contact = self::get_contact( $place );
		foreach ( self::tracked_fields() as $field ) {
			if ( empty( $contact[ $field ] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Merge incoming contact into place without overwriting non-empty values.
	 *
	 * @param array $place   Place.
	 * @param array $incoming New contact fields.
	 * @return array Updated place.
	 */
	public static function merge_contact( $place, $incoming ) {
		if ( ! isset( $place['gptgContact'] ) || ! is_array( $place['gptgContact'] ) ) {
			$place['gptgContact'] = array();
		}
		foreach ( self::tracked_fields() as $field ) {
			if ( ! empty( $incoming[ $field ] ) && empty( $place['gptgContact'][ $field ] ) ) {
				$place['gptgContact'][ $field ] = $incoming[ $field ];
			}
		}
		if ( ! empty( $incoming['source'] ) ) {
			$place['gptgContact']['source'] = $incoming['source'];
		}
		$place['gptgContact']['fetched_at'] = current_time( 'mysql' );
		return $place;
	}

	/**
	 * Enrich place contact fields (merge only empty fields).
	 *
	 * @param array $place Place data.
	 * @return array Updated place or original on failure.
	 */
	public static function enrich( $place, $force = false ) {
		self::$last_error = '';

		if ( ! self::needs_enrichment( $place ) ) {
			return $place;
		}

		$domain = self::extract_domain( $place['websiteUri'] );
		if ( empty( $domain ) ) {
			self::$last_error = __( 'Website URL is not a valid business domain for contact lookup.', 'gptg' );
			return $place;
		}

		if ( $force ) {
			self::clear_domain_cache( $domain );
		}

		$cached = GPTG_Contact_Cache::get( $domain );
		if ( is_array( $cached ) && self::has_contact_data( $cached ) ) {
			return self::merge_contact( $place, $cached );
		}

		$provider = self::get_provider();
		if ( ! $provider ) {
			self::$last_error = __( 'Contact provider API key is not configured.', 'gptg' );
			return $place;
		}

		$result = $provider->enrich_by_domain( $domain );
		if ( is_wp_error( $result ) ) {
			self::$last_error = $result->get_error_message();
			if ( $provider instanceof GPTG_Contact_Hunter && $provider->get_last_error() ) {
				self::$last_error = $provider->get_last_error();
			}
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'GPTG contact enrich (' . $domain . '): ' . self::$last_error );
			}
			return $place;
		}

		if ( self::has_contact_data( $result ) ) {
			GPTG_Contact_Cache::set( $domain, $result );
		}

		return self::merge_contact( $place, $result );
	}

	/**
	 * Apply gptgContact to GeoDirectory postarr for active fields.
	 *
	 * @param array  $postarr   Post array.
	 * @param array  $place     Place with gptgContact.
	 * @param string $post_type Post type.
	 * @return array
	 */
	public static function apply_to_postarr( $postarr, $place, $post_type ) {
		$contact = self::get_contact( $place );
		if ( empty( $contact ) ) {
			return $postarr;
		}

		$map = array(
			'email'     => 'email',
			'facebook'  => 'facebook',
			'twitter'   => 'twitter',
			'instagram' => 'instagram',
			'linkedin'  => 'linkedin',
		);

		foreach ( $map as $key => $gd_field ) {
			if ( empty( $contact[ $key ] ) ) {
				continue;
			}
			if ( self::geodir_field_active( $gd_field, $post_type ) ) {
				$postarr[ $gd_field ] = $contact[ $key ];
			}
		}

		return $postarr;
	}

	/**
	 * Check if a GeoDirectory custom field is active on post type.
	 *
	 * @param string $htmlvar   Field htmlvar_name.
	 * @param string $post_type Post type.
	 * @return bool
	 */
	public static function geodir_field_active( $htmlvar, $post_type ) {
		$defaults = array( 'email', 'facebook', 'twitter', 'instagram', 'linkedin', 'website', 'phone' );
		if ( ! function_exists( 'geodir_get_custom_fields' ) ) {
			return in_array( $htmlvar, $defaults, true );
		}
		$fields = geodir_get_custom_fields( $post_type, 'all' );
		if ( empty( $fields ) ) {
			return in_array( $htmlvar, $defaults, true );
		}
		foreach ( $fields as $field ) {
			if ( isset( $field['htmlvar_name'] ) && $field['htmlvar_name'] === $htmlvar ) {
				return ! isset( $field['is_active'] ) || (int) $field['is_active'] === 1;
			}
		}
		return in_array( $htmlvar, $defaults, true );
	}

	/**
	 * Sanitize contact array from POST/JSON.
	 *
	 * @param array $contact Raw contact.
	 * @return array
	 */
	public static function sanitize_contact( $contact ) {
		if ( ! is_array( $contact ) ) {
			return array();
		}
		$out = array();
		if ( ! empty( $contact['email'] ) ) {
			$out['email'] = sanitize_email( $contact['email'] );
		}
		foreach ( array( 'facebook', 'twitter', 'instagram', 'linkedin' ) as $url_field ) {
			if ( ! empty( $contact[ $url_field ] ) ) {
				$out[ $url_field ] = esc_url_raw( $contact[ $url_field ] );
			}
		}
		if ( ! empty( $contact['source'] ) ) {
			$out['source'] = sanitize_text_field( $contact['source'] );
		}
		if ( ! empty( $contact['fetched_at'] ) ) {
			$out['fetched_at'] = sanitize_text_field( $contact['fetched_at'] );
		}
		return $out;
	}
}
