<?php
/**
 * Fetch and cache business website text for description generation.
 *
 * @package GPTG
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Homepage excerpt helper.
 */
class GPTG_Website_Content {

	const CACHE_PREFIX = 'gptg_website_';
	const TTL          = DAY_IN_SECONDS;
	const MAX_WORDS    = 400;

	/**
	 * Hosts that are not business websites (social, maps, video).
	 *
	 * @var string[]
	 */
	private static $blocked_hosts = array(
		'facebook.com',
		'fb.com',
		'fb.me',
		'm.facebook.com',
		'instagram.com',
		'twitter.com',
		'x.com',
		'linkedin.com',
		'google.com',
		'maps.google.com',
		'goo.gl',
		'youtube.com',
		'youtu.be',
		'tiktok.com',
		'yelp.com',
		'tripadvisor.com',
	);

	/**
	 * Phrases that indicate login walls or platform boilerplate, not business copy.
	 *
	 * @var string[]
	 */
	private static $junk_phrases = array(
		'not available on this browser',
		'get one of the browsers below',
		'meta ©',
		'learn moreenglish',
		'log in to facebook',
		'log in to continue',
		'sign up for facebook',
		'create new account',
		'javascript is disabled',
		'cookies are disabled',
	);

	/**
	 * Whether website fetching is enabled.
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		return '1' === get_option( 'gptg_description_fetch_website', '1' );
	}

	/**
	 * Whether a URL points at a blocked (non-business) host.
	 *
	 * @param string $url URL.
	 * @return bool
	 */
	public static function is_blocked_host( $url ) {
		$parts = wp_parse_url( $url );
		if ( empty( $parts['host'] ) ) {
			return true;
		}

		$host = strtolower( $parts['host'] );
		if ( 0 === strpos( $host, 'www.' ) ) {
			$host = substr( $host, 4 );
		}

		foreach ( self::$blocked_hosts as $blocked ) {
			if ( $host === $blocked || substr( $host, -strlen( '.' . $blocked ) ) === '.' . $blocked ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether text looks like platform/login junk rather than business copy.
	 *
	 * @param string $text Text.
	 * @return bool
	 */
	public static function is_junk_text( $text ) {
		$text = strtolower( preg_replace( '/\s+/', ' ', trim( $text ) ) );
		if ( '' === $text ) {
			return true;
		}

		foreach ( self::$junk_phrases as $phrase ) {
			if ( false !== strpos( $text, $phrase ) ) {
				return true;
			}
		}

		if ( preg_match( '/\bfacebook\b.*\bbrowser\b/i', $text ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Whether excerpt is long enough and not junk.
	 *
	 * @param string $text Text.
	 * @return bool
	 */
	public static function is_quality_excerpt( $text ) {
		if ( self::is_junk_text( $text ) ) {
			return false;
		}

		$text = trim( $text );
		if ( strlen( $text ) < 40 ) {
			return false;
		}

		$words = preg_split( '/\s+/', $text );
		return count( $words ) >= 8;
	}

	/**
	 * Fetch plain-text excerpt from a business website (cached).
	 *
	 * @param string $url Website URL.
	 * @return string
	 */
	public static function fetch_excerpt( $url ) {
		$url = self::normalize_url( $url );
		if ( '' === $url || self::is_blocked_host( $url ) ) {
			return '';
		}

		$cached = get_transient( self::CACHE_PREFIX . md5( $url ) );
		if ( is_string( $cached ) ) {
			return self::is_quality_excerpt( $cached ) ? $cached : '';
		}

		$response = wp_remote_get(
			$url,
			array(
				'timeout'     => 10,
				'redirection' => 3,
				'user-agent'  => 'GPTG-WordPress-Plugin/2.0 (description-enrichment)',
				'headers'     => array(
					'Accept' => 'text/html,application/xhtml+xml',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			set_transient( self::CACHE_PREFIX . md5( $url ), '', HOUR_IN_SECONDS );
			return '';
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 400 ) {
			set_transient( self::CACHE_PREFIX . md5( $url ), '', HOUR_IN_SECONDS );
			return '';
		}

		$body = wp_remote_retrieve_body( $response );
		if ( '' === $body ) {
			set_transient( self::CACHE_PREFIX . md5( $url ), '', HOUR_IN_SECONDS );
			return '';
		}

		$excerpt = self::extract_text_from_html( $body );
		if ( ! self::is_quality_excerpt( $excerpt ) ) {
			$excerpt = '';
		}

		set_transient( self::CACHE_PREFIX . md5( $url ), $excerpt, self::TTL );

		return $excerpt;
	}

	/**
	 * Validate and normalize URL.
	 *
	 * @param string $url Raw URL.
	 * @return string
	 */
	private static function normalize_url( $url ) {
		$url = trim( (string) $url );
		if ( '' === $url ) {
			return '';
		}

		if ( ! preg_match( '#^https?://#i', $url ) ) {
			$url = 'https://' . $url;
		}

		$parts = wp_parse_url( $url );
		if ( empty( $parts['host'] ) ) {
			return '';
		}

		$host = strtolower( $parts['host'] );
		if ( 'localhost' === $host || ( strlen( $host ) > 6 && '.local' === substr( $host, -6 ) ) ) {
			return '';
		}

		if ( self::is_blocked_host( $url ) ) {
			return '';
		}

		return esc_url_raw( $url );
	}

	/**
	 * Pull meta description, title, and body text from HTML.
	 *
	 * @param string $html HTML document.
	 * @return string
	 */
	private static function extract_text_from_html( $html ) {
		if ( preg_match( '/<meta[^>]+name=["\']description["\'][^>]+content=["\']([^"\']+)["\']/i', $html, $m ) ) {
			$text = trim( html_entity_decode( $m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
			if ( self::is_quality_excerpt( $text ) ) {
				return $text;
			}
		}
		if ( preg_match( '/<meta[^>]+property=["\']og:description["\'][^>]+content=["\']([^"\']+)["\']/i', $html, $m ) ) {
			$text = trim( html_entity_decode( $m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
			if ( self::is_quality_excerpt( $text ) ) {
				return $text;
			}
		}

		$html = preg_replace( '/<script\b[^>]*>[\s\S]*?<\/script>/i', ' ', $html );
		$html = preg_replace( '/<style\b[^>]*>[\s\S]*?<\/style>/i', ' ', $html );
		$html = preg_replace( '/<nav\b[^>]*>[\s\S]*?<\/nav>/i', ' ', $html );
		$html = preg_replace( '/<footer\b[^>]*>[\s\S]*?<\/footer>/i', ' ', $html );

		$body_text = wp_strip_all_tags( $html );
		$body_text = preg_replace( '/\s+/', ' ', $body_text );
		$body_text = trim( $body_text );
		if ( $body_text && self::is_quality_excerpt( $body_text ) ) {
			return wp_trim_words( $body_text, 60, '' );
		}

		return '';
	}
}
