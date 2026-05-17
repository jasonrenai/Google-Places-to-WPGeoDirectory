<?php
/**
 * Third-person listing descriptions for GeoDirectory imports.
 *
 * @package GPTG
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once GPTG_PLUGIN_DIR . 'includes/ai/class-gptg-ai-tagger.php';
require_once GPTG_PLUGIN_DIR . 'includes/enrichment/class-gptg-website-content.php';

/**
 * Generate business-neutral, customer-facing descriptions from Google Place data.
 */
class GPTG_AI_Description {

	/**
	 * Google place types that are too generic for marketing copy.
	 *
	 * @var string[]
	 */
	private static $generic_types = array(
		'point_of_interest',
		'establishment',
		'geocode',
		'premise',
		'political',
	);

	/**
	 * Last description generation error message.
	 *
	 * @var string
	 */
	private static $last_error = '';

	/**
	 * Whether description generation is enabled.
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		return '1' === get_option( 'gptg_ai_generate_description', '1' );
	}

	/**
	 * Whether to prefer AI over Google editorial summary.
	 *
	 * @return bool
	 */
	public static function prefer_ai_over_google() {
		return '1' === get_option( 'gptg_ai_prefer_description', '' );
	}

	/**
	 * Last error from AI description generation (empty if none).
	 *
	 * @return string
	 */
	public static function get_last_error() {
		return self::$last_error;
	}

	/**
	 * Clear stored last error.
	 */
	public static function clear_last_error() {
		self::$last_error = '';
	}

	/**
	 * Status message for description generation on the AI settings tab.
	 *
	 * @return string
	 */
	public static function get_description_mode_message() {
		if ( ! self::is_enabled() ) {
			return __( 'Description generation is disabled.', 'gptg' );
		}

		$provider = GPTG_AI_Tagger::get_provider();
		if ( $provider ) {
			return __( 'Descriptions will use AI synthesis (~300 words) from reviews, website text, and Google profile data. No star ratings or review counts are inserted.', 'gptg' );
		}

		$stored_error = get_transient( 'gptg_last_description_error' );
		if ( self::get_last_error() ) {
			return sprintf(
				/* translators: %s: error message */
				__( 'Last AI description attempt failed: %s Template fallback was used.', 'gptg' ),
				self::get_last_error()
			);
		}
		if ( $stored_error ) {
			return sprintf(
				/* translators: %s: error message */
				__( 'Last import AI description error: %s', 'gptg' ),
				$stored_error
			);
		}

		return __( 'No AI provider is active — a plain-English template is used. Select OpenAI or Anthropic and save your API key.', 'gptg' );
	}

	/**
	 * Convert a Google type slug to plain English.
	 *
	 * @param string $type Type slug.
	 * @return string
	 */
	public static function humanize_place_type( $type ) {
		$type = trim( (string) $type );
		if ( '' === $type ) {
			return '';
		}
		$type = str_replace( array( '_', '-' ), ' ', $type );
		return ucwords( $type );
	}

	/**
	 * Human-readable business category line (never raw API type lists).
	 *
	 * @param array $place Place data.
	 * @return string
	 */
	public static function format_business_categories( $place ) {
		$labels = array();

		if ( ! empty( $place['primaryType'] ) ) {
			$primary = self::humanize_place_type( $place['primaryType'] );
			if ( $primary ) {
				$labels[] = $primary;
			}
		}

		if ( ! empty( $place['types'] ) && is_array( $place['types'] ) ) {
			foreach ( $place['types'] as $type ) {
				if ( in_array( $type, self::$generic_types, true ) ) {
					continue;
				}
				if ( ! empty( $place['primaryType'] ) && $type === $place['primaryType'] ) {
					continue;
				}
				$label = self::humanize_place_type( $type );
				if ( $label && ! in_array( $label, $labels, true ) ) {
					$labels[] = $label;
				}
				if ( count( $labels ) >= 3 ) {
					break;
				}
			}
		}

		return implode( ', ', $labels );
	}

	/**
	 * Extract review summary text from place payload.
	 *
	 * @param array $place Place data.
	 * @return string
	 */
	private static function extract_review_summary( $place ) {
		if ( empty( $place['reviewSummary'] ) || ! is_array( $place['reviewSummary'] ) ) {
			return '';
		}
		$summary = $place['reviewSummary'];
		if ( ! empty( $summary['text']['text'] ) ) {
			return $summary['text']['text'];
		}
		if ( ! empty( $summary['overview']['text'] ) ) {
			return $summary['overview']['text'];
		}
		if ( is_string( $summary ) ) {
			return $summary;
		}
		return '';
	}

	/**
	 * Qualitative reputation hint for AI (no numbers in output).
	 *
	 * @param array $place Place data.
	 * @return string
	 */
	private static function build_reputation_hint( $place ) {
		$rating = isset( $place['rating'] ) ? (float) $place['rating'] : 0;
		if ( $rating >= 4.5 ) {
			return __( 'Strong reputation for quality and service', 'gptg' );
		}
		if ( $rating >= 4.0 ) {
			return __( 'Generally well regarded by visitors', 'gptg' );
		}
		if ( $rating > 0 ) {
			return __( 'Mixed but often positive reputation', 'gptg' );
		}
		return '';
	}

	/**
	 * Field groups used when fetching place details for descriptions.
	 *
	 * @return array
	 */
	private static function get_description_field_groups() {
		$groups = get_option(
			'gptg_details_field_groups',
			array( 'basic', 'contact', 'hours', 'content', 'photos' )
		);
		if ( ! is_array( $groups ) ) {
			$groups = array( 'basic', 'contact', 'hours', 'content' );
		}
		return array_values(
			array_unique(
				array_merge( $groups, array( 'reviews', 'content', 'photos' ) )
			)
		);
	}

	/**
	 * Attach website excerpt to place when enabled.
	 *
	 * @param array $place Place data.
	 * @return array
	 */
	private static function attach_website_excerpt( $place ) {
		if ( ! empty( $place['gptgWebsiteExcerpt'] ) ) {
			return $place;
		}
		if ( ! GPTG_Website_Content::is_enabled() || empty( $place['websiteUri'] ) ) {
			return $place;
		}
		$place['gptgWebsiteExcerpt'] = GPTG_Website_Content::fetch_excerpt( $place['websiteUri'] );
		return $place;
	}

	/**
	 * Build context array from place data for prompts.
	 *
	 * @param array $place Place data.
	 * @return array
	 */
	public static function build_place_context( $place ) {
		$name = GPTG_Google_Places_API::get_place_name( $place );
		$summary = '';
		if ( ! empty( $place['editorialSummary']['text'] ) ) {
			$summary = $place['editorialSummary']['text'];
		} elseif ( ! empty( $place['generativeSummary']['overview']['text'] ) ) {
			$summary = $place['generativeSummary']['overview']['text'];
		}

		$reviews = array();
		if ( ! empty( $place['reviews'] ) && is_array( $place['reviews'] ) ) {
			foreach ( array_slice( $place['reviews'], 0, 5 ) as $review ) {
				$text = '';
				if ( ! empty( $review['text']['text'] ) ) {
					$text = $review['text']['text'];
				} elseif ( ! empty( $review['originalText']['text'] ) ) {
					$text = $review['originalText']['text'];
				}
				if ( $text ) {
					$sentence = self::first_complete_sentence( $text );
					if ( $sentence ) {
						$reviews[] = $sentence;
					}
				}
			}
		}

		$website_excerpt = '';
		if ( ! empty( $place['gptgWebsiteExcerpt'] ) && GPTG_Website_Content::is_quality_excerpt( $place['gptgWebsiteExcerpt'] ) ) {
			$website_excerpt = $place['gptgWebsiteExcerpt'];
		}

		$reputation_hint = self::build_reputation_hint( $place );

		return array(
			'name'              => $name,
			'address'           => isset( $place['formattedAddress'] ) ? $place['formattedAddress'] : '',
			'business_focus'    => self::format_business_categories( $place ),
			'google_summary'    => $summary,
			'review_summary'    => self::extract_review_summary( $place ),
			'review_snippets'   => $reviews,
			'reputation_hint'   => $reputation_hint,
			'website_excerpt'   => $website_excerpt,
			'hours'             => isset( $place['gptgHoursText'] ) ? $place['gptgHoursText'] : '',
			'phone'             => isset( $place['nationalPhoneNumber'] ) ? $place['nationalPhoneNumber'] : ( isset( $place['internationalPhoneNumber'] ) ? $place['internationalPhoneNumber'] : '' ),
		);
	}

	/**
	 * Merge full place details into a sparse search result for description generation.
	 *
	 * @param array $place Place data.
	 * @return array
	 */
	public static function enrich_place_for_description( $place ) {
		if ( empty( $place['id'] ) || ! empty( $place['gptgDescriptionEnriched'] ) ) {
			return self::attach_website_excerpt( $place );
		}

		$api     = new GPTG_Google_Places_API();
		$groups  = self::get_description_field_groups();
		$details = $api->get_place_details( $place['id'], array(), $groups );

		if ( is_wp_error( $details ) || empty( $details ) ) {
			$place['gptgDescriptionEnriched'] = true;
			return self::attach_website_excerpt( $place );
		}

		$merged = array_merge( $details, $place );
		foreach ( array( 'gptgContact', 'gptgTaxonomy', 'gptgAiDescription', 'gptgHoursText' ) as $key ) {
			if ( ! empty( $place[ $key ] ) ) {
				$merged[ $key ] = $place[ $key ];
			}
		}
		$merged['gptgDescriptionEnriched'] = true;

		return self::attach_website_excerpt( $merged );
	}

	/**
	 * Generate description for a place.
	 *
	 * @param array  $place     Place data.
	 * @param string $post_type Post type (unused, reserved).
	 * @return string
	 */
	public static function generate( $place, $post_type = '' ) {
		self::clear_last_error();

		if ( ! empty( $place['gptgAiDescription'] ) ) {
			return $place['gptgAiDescription'];
		}

		$place   = self::enrich_place_for_description( $place );
		$context = self::build_place_context( $place );

		$provider = GPTG_AI_Tagger::get_provider();
		if ( $provider && self::is_enabled() ) {
			$result = self::generate_via_ai( $context, $provider );
			if ( ! is_wp_error( $result ) && ! empty( $result ) ) {
				return self::polish_description_output( $result, $context['name'] );
			}
			if ( is_wp_error( $result ) ) {
				self::$last_error = $result->get_error_message();
				set_transient( 'gptg_last_description_error', self::$last_error, HOUR_IN_SECONDS );
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
					error_log( 'GPTG description AI: ' . self::$last_error );
				}
			}
		}

		return self::polish_description_output( self::generate_template( $context ), $context['name'] );
	}

	/**
	 * Format context as labeled notes for the AI (not raw JSON field dump).
	 *
	 * @param array $context Place context.
	 * @return string
	 */
	private static function format_context_for_prompt( $context ) {
		$lines = array();

		if ( ! empty( $context['name'] ) ) {
			$lines[] = 'Business name: ' . $context['name'];
		}
		if ( ! empty( $context['business_focus'] ) ) {
			$lines[] = 'Business focus: ' . $context['business_focus'];
		}
		if ( ! empty( $context['address'] ) ) {
			$lines[] = 'Location: ' . $context['address'];
		}
		if ( ! empty( $context['google_summary'] ) ) {
			$lines[] = 'Google profile summary: ' . $context['google_summary'];
		}
		if ( ! empty( $context['website_excerpt'] ) && GPTG_Website_Content::is_quality_excerpt( $context['website_excerpt'] ) ) {
			$lines[] = 'Website content: ' . $context['website_excerpt'];
		}
		if ( ! empty( $context['review_summary'] ) ) {
			$lines[] = 'Review overview: ' . $context['review_summary'];
		}
		if ( ! empty( $context['review_snippets'] ) ) {
			$lines[] = 'Review themes (synthesize into description; do not quote or attribute to guests):';
			foreach ( $context['review_snippets'] as $snippet ) {
				$lines[] = '- ' . $snippet;
			}
		}
		if ( ! empty( $context['reputation_hint'] ) ) {
			$lines[] = 'Reputation tone: ' . $context['reputation_hint'];
		}
		if ( ! empty( $context['hours'] ) ) {
			$lines[] = 'Hours: ' . $context['hours'];
		}
		if ( ! empty( $context['phone'] ) ) {
			$lines[] = 'Phone: ' . $context['phone'];
		}

		return implode( "\n", $lines );
	}

	/**
	 * AI description generation.
	 *
	 * @param array            $context  Place context.
	 * @param GPTG_AI_Provider $provider Provider.
	 * @return string|WP_Error
	 */
	private static function generate_via_ai( $context, $provider ) {
		$system = 'You write short business listing descriptions for a public directory. '
			. 'Use third person only (the business name, "they", "this shop", "this restaurant"). Never use first person ("we", "our", "I"). '
			. 'Write approximately 300 words in plain, neutral, customer-facing English—informative and welcoming, not salesy. '
			. 'Synthesize the business profile, review themes, and website content into one cohesive description. '
			. 'Never attribute statements to guests, visitors, or reviewers (do not write "guests say", "guests mention", "visitors note", "reviews praise", or "according to reviews"). '
			. 'Weave review themes into factual statements about the business itself (for example "The roti selection is a highlight" not "Guests say the roti is great"). '
			. 'Do not start sentences with the business name followed by a colon. '
			. 'Highlight the business in a positive, honest light—only mention services, qualities, or experiences supported by the notes. '
			. 'Never mention numeric star ratings, review counts, scores, or statistics (for example do not write "4.9 stars" or "120 reviews"). '
			. 'Never use API field names, snake_case type slugs, variable names, bullet lists, JSON, or markdown. '
			. 'Write only complete sentences. Never end with an ellipsis or cut off mid-thought. Do not paste website copy verbatim—synthesize it. '
			. 'Reply with the description text only.';

		$user = self::format_context_for_prompt( $context );

		$raw = $provider->complete( $system, $user );
		if ( is_wp_error( $raw ) ) {
			return $raw;
		}

		$raw = trim( preg_replace( '/^```\w*\s*|\s*```$/', '', trim( $raw ) ) );
		return wp_strip_all_tags( $raw );
	}

	/**
	 * Final cleanup on generated description text.
	 *
	 * @param string $text Description.
	 * @param string $name Business name.
	 * @return string
	 */
	private static function polish_description_output( $text, $name ) {
		$text = self::strip_guest_attribution_phrases( $text );
		if ( $name && preg_match( '/^' . preg_quote( $name, '/' ) . '\s*:\s*/iu', $text ) ) {
			$text = preg_replace( '/^' . preg_quote( $name, '/' ) . '\s*:\s*/iu', '', $text );
			$text = ucfirst( trim( $text ) );
		}
		return self::ensure_complete_sentences( $text );
	}

	/**
	 * Remove guest-attribution phrasing if the model leaks it.
	 *
	 * @param string $text Description text.
	 * @return string
	 */
	private static function strip_guest_attribution_phrases( $text ) {
		$patterns = array(
			'/guests?\s+often\s+(?:say|mention|note|report)\s+that\s+/i',
			'/guests?\s+(?:say|mention|note|report|praise)\s+that\s+/i',
			'/visitors?\s+often\s+(?:say|mention|note)\s+that\s+/i',
			'/visitors?\s+(?:say|mention|note)\s+that\s+/i',
			'/according\s+to\s+(?:guest\s+)?reviews?,?\s*/i',
			'/reviews?\s+(?:often\s+)?(?:say|mention|note|praise)\s+that\s+/i',
		);
		foreach ( $patterns as $pattern ) {
			$text = preg_replace( $pattern, '', $text );
		}
		return trim( preg_replace( '/\s+/', ' ', $text ) );
	}

	/**
	 * Extract the first complete sentence from text (no mid-sentence truncation).
	 *
	 * @param string $text Source text.
	 * @return string
	 */
	private static function first_complete_sentence( $text ) {
		$text = trim( preg_replace( '/\.{3,}|…+/u', '', trim( $text ) ) );
		if ( '' === $text ) {
			return '';
		}
		if ( preg_match( '/^(.+?[.!?])(?:\s+|$)/us', $text, $m ) ) {
			return trim( $m[1] );
		}
		if ( strlen( $text ) <= 220 ) {
			return $text;
		}
		return '';
	}

	/**
	 * Normalize description text: complete sentences, no trailing ellipsis.
	 *
	 * @param string $text Raw text.
	 * @return string
	 */
	private static function ensure_complete_sentences( $text ) {
		$text = trim( preg_replace( '/\.{3,}|…+/u', '', $text ) );
		$text = preg_replace( '/\s+/', ' ', $text );
		if ( '' === $text ) {
			return '';
		}
		if ( ! preg_match( '/[.!?]$/', $text ) ) {
			$text .= '.';
		}
		return $text;
	}

	/**
	 * Rough check whether two text blobs are largely the same.
	 *
	 * @param string $a First text.
	 * @param string $b Second text.
	 * @return bool
	 */
	private static function texts_are_similar( $a, $b ) {
		$a = strtolower( preg_replace( '/\s+/', ' ', trim( $a ) ) );
		$b = strtolower( preg_replace( '/\s+/', ' ', trim( $b ) ) );
		if ( '' === $a || '' === $b ) {
			return false;
		}
		if ( $a === $b ) {
			return true;
		}
		similar_text( $a, $b, $percent );
		return $percent > 70;
	}

	/**
	 * Optional second sentence with synthesized reputation (no guest quotes).
	 *
	 * @param array  $context Place context.
	 * @param string $primary Primary paragraph already chosen.
	 * @return string
	 */
	private static function build_sentiment_addon( $context, $primary ) {
		if ( empty( $context['reputation_hint'] ) ) {
			return '';
		}
		if ( self::texts_are_similar( $primary, $context['reputation_hint'] ) ) {
			return '';
		}
		return self::ensure_complete_sentences( $context['reputation_hint'] );
	}

	/**
	 * Embed summary or excerpt as a sentence about the business (no "Name:" colon framing).
	 *
	 * @param string $name Business name.
	 * @param string $text Source text.
	 * @return string
	 */
	private static function embed_as_business_sentence( $name, $text ) {
		$text = trim( $text );
		if ( '' === $text ) {
			return '';
		}
		if ( preg_match( '/\b(we|our)\b/i', $text ) ) {
			$text = preg_replace( '/\b[Ww]e\b/u', $name, $text );
			$text = preg_replace( '/\b[Oo]ur\b/u', $name . "'s", $text );
		}
		if ( 0 === stripos( $text, $name ) ) {
			return $text;
		}
		return sprintf(
			/* translators: 1: business name, 2: summary or excerpt */
			__( 'At %1$s, %2$s', 'gptg' ),
			$name,
			lcfirst( $text )
		);
	}

	/**
	 * Build one primary paragraph from the best single source.
	 *
	 * @param array  $context Place context.
	 * @param string $name    Business name.
	 * @return string
	 */
	private static function build_primary_paragraph( $context, $name ) {
		if ( ! empty( $context['google_summary'] ) ) {
			return self::ensure_complete_sentences(
				self::embed_as_business_sentence( $name, trim( $context['google_summary'] ) )
			);
		}
		if ( ! empty( $context['website_excerpt'] ) && GPTG_Website_Content::is_quality_excerpt( $context['website_excerpt'] ) ) {
			$excerpt = self::first_complete_sentence( $context['website_excerpt'] );
			if ( ! $excerpt ) {
				$excerpt = wp_trim_words( $context['website_excerpt'], 45, '' );
			}
			if ( $excerpt && ! GPTG_Website_Content::is_junk_text( $excerpt ) ) {
				return self::ensure_complete_sentences(
					self::embed_as_business_sentence( $name, $excerpt )
				);
			}
		}
		if ( ! empty( $context['business_focus'] ) ) {
			$text = sprintf(
				/* translators: 1: business name, 2: humanized business type */
				__( '%1$s is a %2$s', 'gptg' ),
				$name,
				$context['business_focus']
			);
		} else {
			$text = sprintf(
				/* translators: %s: business name */
				__( '%s is a local business', 'gptg' ),
				$name
			);
		}
		if ( ! empty( $context['address'] ) ) {
			$text .= ' ' . sprintf(
				/* translators: %s: street address */
				__( 'located at %s', 'gptg' ),
				$context['address']
			);
		}
		return self::ensure_complete_sentences( $text );
	}

	/**
	 * Template fallback when AI is unavailable.
	 *
	 * @param array $context Place context.
	 * @return string
	 */
	private static function generate_template( $context ) {
		$name    = ! empty( $context['name'] ) ? $context['name'] : __( 'This business', 'gptg' );
		$primary = self::build_primary_paragraph( $context, $name );
		$addon   = self::build_sentiment_addon( $context, $primary );

		if ( $addon ) {
			return self::ensure_complete_sentences( $primary . ' ' . $addon );
		}

		return $primary;
	}
}
