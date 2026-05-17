<?php
/**
 * AI + rule-based taxonomy tagging.
 *
 * @package GPTG
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once GPTG_PLUGIN_DIR . 'includes/ai/interface-gptg-ai-provider.php';
require_once GPTG_PLUGIN_DIR . 'includes/ai/class-gptg-ai-openai.php';
require_once GPTG_PLUGIN_DIR . 'includes/ai/class-gptg-ai-anthropic.php';
require_once GPTG_PLUGIN_DIR . 'includes/ai/class-gptg-ai-description.php';

/**
 * Suggest GeoDirectory categories and tags for a place.
 */
class GPTG_AI_Tagger {

	/**
	 * Resolve OpenAI API key (option or wp-config constant).
	 *
	 * @return string
	 */
	public static function get_openai_api_key() {
		if ( defined( 'GPTG_OPENAI_API_KEY' ) && GPTG_OPENAI_API_KEY ) {
			return GPTG_OPENAI_API_KEY;
		}
		return get_option( 'gptg_openai_api_key', '' );
	}

	/**
	 * Resolve Anthropic API key (option or wp-config constant).
	 *
	 * @return string
	 */
	public static function get_anthropic_api_key() {
		if ( defined( 'GPTG_ANTHROPIC_API_KEY' ) && GPTG_ANTHROPIC_API_KEY ) {
			return GPTG_ANTHROPIC_API_KEY;
		}
		return get_option( 'gptg_anthropic_api_key', '' );
	}

	/**
	 * Get configured AI provider instance.
	 *
	 * @return GPTG_AI_Provider|null
	 */
	public static function get_provider() {
		$provider = get_option( 'gptg_ai_provider', 'rules' );
		if ( 'openai' === $provider ) {
			$key = self::get_openai_api_key();
			if ( empty( $key ) ) {
				return null;
			}
			return new GPTG_AI_OpenAI( $key, get_option( 'gptg_openai_model', 'gpt-4o-mini' ) );
		}
		if ( 'anthropic' === $provider ) {
			$key = self::get_anthropic_api_key();
			if ( empty( $key ) ) {
				return null;
			}
			return new GPTG_AI_Anthropic( $key, get_option( 'gptg_anthropic_model', 'claude-3-5-haiku-latest' ) );
		}
		return null;
	}

	/**
	 * Human-readable AI configuration status for admin UI.
	 *
	 * @return array { status: ready|warning|inactive, message: string }
	 */
	public static function get_provider_status() {
		$provider_id = get_option( 'gptg_ai_provider', 'rules' );

		if ( 'rules' === $provider_id ) {
			return array(
				'status'  => 'inactive',
				'message' => __( 'AI provider is disabled — select OpenAI or Anthropic to generate descriptions on import.', 'gptg' ),
			);
		}

		if ( 'openai' === $provider_id ) {
			$key   = self::get_openai_api_key();
			$model = get_option( 'gptg_openai_model', 'gpt-4o-mini' );
			if ( empty( $key ) ) {
				return array(
					'status'  => 'warning',
					'message' => __( 'OpenAI is selected but no API key is configured.', 'gptg' ),
				);
			}
			return array(
				'status'  => 'ready',
				'message' => sprintf(
					/* translators: %s: model name */
					__( 'OpenAI: ready (%s).', 'gptg' ),
					$model
				),
			);
		}

		if ( 'anthropic' === $provider_id ) {
			$key   = self::get_anthropic_api_key();
			$model = get_option( 'gptg_anthropic_model', 'claude-3-5-haiku-latest' );
			if ( empty( $key ) ) {
				return array(
					'status'  => 'warning',
					'message' => __( 'Anthropic is selected but no API key is configured.', 'gptg' ),
				);
			}
			return array(
				'status'  => 'ready',
				'message' => sprintf(
					/* translators: %s: model name */
					__( 'Anthropic: ready (%s).', 'gptg' ),
					$model
				),
			);
		}

		return array(
			'status'  => 'warning',
			'message' => __( 'Unknown AI provider selected.', 'gptg' ),
		);
	}

	/**
	 * List taxonomy terms for prompt / UI.
	 *
	 * @param string $post_type Post type.
	 * @return array { categories: array, tags: array }
	 */
	public static function get_taxonomy_lists( $post_type ) {
		$cat_tax = $post_type . 'category';
		$tag_tax = $post_type . '_tags';

		$categories = array();
		$tags       = array();

		if ( taxonomy_exists( $cat_tax ) ) {
			$terms = get_terms(
				array(
					'taxonomy'   => $cat_tax,
					'hide_empty' => false,
				)
			);
			if ( ! is_wp_error( $terms ) ) {
				foreach ( $terms as $term ) {
					$categories[] = array(
						'id'   => (int) $term->term_id,
						'name' => $term->name,
						'slug' => $term->slug,
					);
				}
			}
		}

		if ( taxonomy_exists( $tag_tax ) ) {
			$terms = get_terms(
				array(
					'taxonomy'   => $tag_tax,
					'hide_empty' => false,
				)
			);
			if ( ! is_wp_error( $terms ) ) {
				foreach ( $terms as $term ) {
					$tags[] = array(
						'id'   => (int) $term->term_id,
						'name' => $term->name,
						'slug' => $term->slug,
					);
				}
			}
		}

		return array(
			'categories' => $categories,
			'tags'       => $tags,
		);
	}

	/**
	 * Whether the admin prefers AI-first taxonomy suggestions.
	 *
	 * @return bool
	 */
	public static function ai_tagging_preferred() {
		return (bool) get_option( 'gptg_ai_enabled', false );
	}

	/**
	 * Suggest category and tag term IDs for a place.
	 *
	 * @param array  $place     Google place payload.
	 * @param string $post_type GeoDirectory post type.
	 * @return array { category_ids: int[], tag_ids: int[], source: string }
	 */
	public static function suggest( $place, $post_type ) {
		$lists    = self::get_taxonomy_lists( $post_type );
		$provider = self::get_provider();

		if ( ! $provider ) {
			$result = self::suggest_via_rules( $place, $post_type, $lists );
			return self::apply_required_category( $result, $place, $post_type, $lists );
		}

		if ( ! self::ai_tagging_preferred() ) {
			$rules = self::suggest_via_rules( $place, $post_type, $lists );
			if ( ! empty( $rules['category_ids'] ) && ! empty( $rules['tag_ids'] ) ) {
				return self::apply_required_category( $rules, $place, $post_type, $lists );
			}
			$ai = self::suggest_via_ai( $place, $lists, $provider );
			if ( ! is_wp_error( $ai ) ) {
				$merged = self::merge_suggestions( $rules, $ai, $provider->get_id() );
				return self::apply_required_category( $merged, $place, $post_type, $lists );
			}
			return self::apply_required_category( $rules, $place, $post_type, $lists );
		}

		$ai = self::suggest_via_ai( $place, $lists, $provider );
		if ( is_wp_error( $ai ) ) {
			$result = self::suggest_via_rules( $place, $post_type, $lists );
			return self::apply_required_category( $result, $place, $post_type, $lists );
		}

		$rules  = self::suggest_via_rules( $place, $post_type, $lists );
		$merged = self::merge_suggestions( $ai, $rules, $provider->get_id() );
		return self::apply_required_category( $merged, $place, $post_type, $lists );
	}

	/**
	 * Guarantee exactly one valid category ID from existing GeoDirectory terms.
	 *
	 * @param array  $category_ids Proposed category IDs.
	 * @param array  $place        Place data.
	 * @param string $post_type    Post type.
	 * @return array|WP_Error Validated category IDs (one element) or error if no categories exist.
	 */
	public static function require_category_ids( $category_ids, $place, $post_type ) {
		$lists = self::get_taxonomy_lists( $post_type );

		if ( empty( $lists['categories'] ) ) {
			return new WP_Error(
				'no_categories',
				__( 'No GeoDirectory categories exist. Create at least one category before importing listings.', 'gptg' )
			);
		}

		$valid_ids = array_map( 'intval', wp_list_pluck( $lists['categories'], 'id' ) );
		$filtered  = array_values(
			array_intersect(
				array_map( 'intval', (array) $category_ids ),
				$valid_ids
			)
		);

		if ( count( $filtered ) > 1 ) {
			$filtered = array( $filtered[0] );
		}

		if ( ! empty( $filtered ) ) {
			return $filtered;
		}

		$result = self::apply_required_category(
			array(
				'category_ids' => array(),
				'tag_ids'      => array(),
				'source'       => 'required',
			),
			$place,
			$post_type,
			$lists
		);

		if ( empty( $result['category_ids'] ) ) {
			return new WP_Error(
				'no_category_match',
				__( 'Could not assign a category to this listing.', 'gptg' )
			);
		}

		return array( (int) $result['category_ids'][0] );
	}

	/**
	 * Merge two suggestion payloads, filling gaps from the secondary source.
	 *
	 * @param array  $primary  Primary suggestion.
	 * @param array  $fallback Fallback suggestion.
	 * @param string $source   Primary source id for labeling.
	 * @return array
	 */
	private static function merge_suggestions( $primary, $fallback, $source ) {
		$category_ids = ! empty( $primary['category_ids'] ) ? (array) $primary['category_ids'] : array();
		$tag_ids      = ! empty( $primary['tag_ids'] ) ? (array) $primary['tag_ids'] : array();

		if ( empty( $category_ids ) && ! empty( $fallback['category_ids'] ) ) {
			$category_ids = (array) $fallback['category_ids'];
		}
		if ( empty( $tag_ids ) && ! empty( $fallback['tag_ids'] ) ) {
			$tag_ids = (array) $fallback['tag_ids'];
		}

		$category_ids = array_values( array_unique( array_map( 'intval', $category_ids ) ) );
		$tag_ids      = array_values( array_unique( array_map( 'intval', $tag_ids ) ) );

		$used_fallback = (
			( empty( $primary['category_ids'] ) && ! empty( $fallback['category_ids'] ) )
			|| ( empty( $primary['tag_ids'] ) && ! empty( $fallback['tag_ids'] ) )
		);

		$label           = $source;
		$fallback_source = $fallback['source'] ?? '';
		if ( $used_fallback ) {
			if ( 'rules' === $fallback_source ) {
				$label = $source . '+rules';
			} elseif ( 'rules' === $source ) {
				$label = 'rules+' . $fallback_source;
			} else {
				$label = $source . '+' . $fallback_source;
			}
		}

		return array(
			'category_ids' => $category_ids,
			'tag_ids'      => $tag_ids,
			'source'       => $label,
			'confidence'   => isset( $primary['confidence'] ) ? $primary['confidence'] : null,
		);
	}

	/**
	 * Collect Google place types including primaryType.
	 *
	 * @param array $place Place data.
	 * @return array
	 */
	private static function get_place_types( $place ) {
		$types = isset( $place['types'] ) && is_array( $place['types'] ) ? $place['types'] : array();
		if ( ! empty( $place['primaryType'] ) && ! in_array( $place['primaryType'], $types, true ) ) {
			array_unshift( $types, $place['primaryType'] );
		}
		return $types;
	}

	/**
	 * Add resolved category/tag labels to a suggestion payload.
	 *
	 * @param array  $suggested Suggestion from suggest().
	 * @param string $post_type Post type.
	 * @return array
	 */
	public static function enrich_suggested_with_labels( $suggested, $post_type ) {
		if ( ! is_array( $suggested ) ) {
			return $suggested;
		}

		$lists = self::get_taxonomy_lists( $post_type );
		$cat_map = array();
		$tag_map = array();

		foreach ( $lists['categories'] as $cat ) {
			$cat_map[ (int) $cat['id'] ] = $cat['name'];
		}
		foreach ( $lists['tags'] as $tag ) {
			$tag_map[ (int) $tag['id'] ] = $tag['name'];
		}

		$categories = array();
		$tags       = array();

		if ( ! empty( $suggested['category_ids'] ) ) {
			foreach ( (array) $suggested['category_ids'] as $id ) {
				$id = (int) $id;
				if ( isset( $cat_map[ $id ] ) ) {
					$categories[] = array(
						'id'   => $id,
						'name' => $cat_map[ $id ],
					);
				}
			}
		}

		if ( ! empty( $suggested['tag_ids'] ) ) {
			foreach ( (array) $suggested['tag_ids'] as $id ) {
				$id = (int) $id;
				if ( isset( $tag_map[ $id ] ) ) {
					$tags[] = array(
						'id'   => $id,
						'name' => $tag_map[ $id ],
					);
				}
			}
		}

		$suggested['categories'] = $categories;
		$suggested['tags']       = $tags;

		return $suggested;
	}

	/**
	 * Rule-based fallback using Google types.
	 *
	 * @param array  $place     Place data.
	 * @param string $post_type Post type.
	 * @param array  $lists     Taxonomy lists.
	 * @return array
	 */
	public static function suggest_via_rules( $place, $post_type, $lists = null ) {
		if ( null === $lists ) {
			$lists = self::get_taxonomy_lists( $post_type );
		}

		$types  = self::get_place_types( $place );
		$mapped = GPTG_Export::map_google_types_to_geodir_categories( $types, $post_type );

		$category_ids = array();
		$best_score   = 0;

		foreach ( $lists['categories'] as $cat ) {
			foreach ( $mapped as $name ) {
				$score = self::match_term_score( $cat, $name );
				if ( $score > $best_score ) {
					$best_score   = $score;
					$category_ids = array( (int) $cat['id'] );
				} elseif ( $score > 0 && $score === $best_score && ! in_array( (int) $cat['id'], $category_ids, true ) ) {
					$category_ids[] = (int) $cat['id'];
				}
			}
		}

		if ( empty( $category_ids ) ) {
			$category_ids = self::match_categories_from_keywords( $types, $mapped, $lists['categories'], $place );
		}

		$tag_ids = self::match_tags_from_types( $types, $mapped, $lists['tags'], $place );

		return array(
			'category_ids' => array_values( array_unique( $category_ids ) ),
			'tag_ids'      => array_values( array_unique( $tag_ids ) ),
			'source'       => 'rules',
		);
	}

	/**
	 * Ensure suggestion payload has exactly one valid category ID.
	 *
	 * @param array  $result    Suggestion payload.
	 * @param array  $place     Place data.
	 * @param string $post_type Post type.
	 * @param array  $lists     Taxonomy lists.
	 * @return array
	 */
	private static function apply_required_category( $result, $place, $post_type, $lists ) {
		if ( empty( $lists['categories'] ) ) {
			return $result;
		}

		$valid_ids = array_map( 'intval', wp_list_pluck( $lists['categories'], 'id' ) );
		$cat_ids   = ! empty( $result['category_ids'] ) ? array_map( 'intval', (array) $result['category_ids'] ) : array();
		$cat_ids   = array_values( array_intersect( $cat_ids, $valid_ids ) );

		if ( count( $cat_ids ) > 1 ) {
			$cat_ids = array( $cat_ids[0] );
		}

		if ( ! empty( $cat_ids ) ) {
			$result['category_ids'] = $cat_ids;
			return $result;
		}

		$types  = self::get_place_types( $place );
		$mapped = GPTG_Export::map_google_types_to_geodir_categories( $types, $post_type );
		$ids    = self::match_categories_from_keywords( $types, $mapped, $lists['categories'], $place );

		if ( empty( $ids ) ) {
			$rules = self::suggest_via_rules( $place, $post_type, $lists );
			if ( ! empty( $rules['category_ids'] ) ) {
				$ids = array( (int) $rules['category_ids'][0] );
			}
		}

		if ( empty( $ids ) ) {
			$provider = self::get_provider();
			if ( $provider ) {
				$ai_cat = self::suggest_category_via_ai( $place, $lists, $provider );
				if ( ! is_wp_error( $ai_cat ) && ! empty( $ai_cat ) ) {
					$ids = $ai_cat;
					$source = isset( $result['source'] ) ? $result['source'] : '';
					$result['source'] = $source ? $source . '+ai_category' : 'ai_category';
				}
			}
		}

		if ( empty( $ids ) ) {
			$ids = self::pick_fallback_category( $lists['categories'] );
			if ( ! empty( $ids ) ) {
				$source = isset( $result['source'] ) ? $result['source'] : '';
				$result['source'] = $source ? $source . '+fallback' : 'fallback';
			}
		} else {
			$source = isset( $result['source'] ) ? $result['source'] : 'rules';
			if ( false === strpos( $source, 'keywords' ) && false === strpos( $source, 'rules' ) ) {
				$result['source'] = $source . '+keywords';
			}
		}

		if ( ! empty( $ids ) ) {
			$result['category_ids'] = array( (int) $ids[0] );
		}

		return $result;
	}

	/**
	 * Last-resort category when AI and rules cannot match.
	 *
	 * @param array $categories Category term rows.
	 * @return int[]
	 */
	private static function pick_fallback_category( $categories ) {
		if ( empty( $categories ) ) {
			return array();
		}

		$fallback_id = (int) get_option( 'gptg_fallback_category_id', 0 );
		if ( $fallback_id > 0 ) {
			foreach ( $categories as $cat ) {
				if ( (int) $cat['id'] === $fallback_id ) {
					return array( $fallback_id );
				}
			}
		}

		$generic_slugs = array( 'general', 'other', 'misc', 'miscellaneous', 'uncategorized', 'default' );
		foreach ( $categories as $cat ) {
			$slug = isset( $cat['slug'] ) ? strtolower( $cat['slug'] ) : '';
			$name = strtolower( $cat['name'] );
			foreach ( $generic_slugs as $generic ) {
				if ( $slug === $generic || $name === $generic || false !== strpos( $slug, $generic ) ) {
					return array( (int) $cat['id'] );
				}
			}
		}

		return array( (int) $categories[0]['id'] );
	}

	/**
	 * Build keyword list from Google types, mapped labels, and business name tokens.
	 *
	 * @param array      $types  Google types.
	 * @param array      $mapped Mapped category names.
	 * @param array|null $place  Optional place for name tokens.
	 * @return array
	 */
	private static function build_type_keywords( $types, $mapped, $place = null ) {
		$keywords = array();

		foreach ( $types as $type ) {
			$keywords[] = str_replace( '_', ' ', $type );
		}
		foreach ( $mapped as $name ) {
			$keywords[] = $name;
		}

		if ( null !== $place ) {
			$business_name = GPTG_Google_Places_API::get_place_name( $place );
			if ( $business_name ) {
				foreach ( preg_split( '/[\s\-&,.]+/u', $business_name ) as $word ) {
					$word = trim( $word );
					if ( strlen( $word ) >= 3 ) {
						$keywords[] = $word;
					}
				}
			}
		}

		return array_values( array_unique( array_filter( $keywords ) ) );
	}

	/**
	 * Match categories using the same loose keywords as tags (best single match).
	 *
	 * @param array      $types      Google types.
	 * @param array      $mapped     Mapped labels.
	 * @param array      $categories Category term rows.
	 * @param array|null $place      Place data for name tokens.
	 * @return int[]
	 */
	private static function match_categories_from_keywords( $types, $mapped, $categories, $place = null ) {
		$keywords     = self::build_type_keywords( $types, $mapped, $place );
		$category_ids = array();
		$best_score   = 0;

		foreach ( $categories as $cat ) {
			foreach ( $keywords as $keyword ) {
				$score = self::match_term_score( $cat, $keyword );
				if ( $score > $best_score ) {
					$best_score   = $score;
					$category_ids = array( (int) $cat['id'] );
				}
			}
		}

		return $category_ids;
	}

	/**
	 * Score how well a taxonomy term matches a mapped label (higher = better).
	 *
	 * @param array  $term  Term row with name and slug.
	 * @param string $label Mapped category label.
	 * @return int 0 = no match, 3 = exact name.
	 */
	private static function match_term_score( $term, $label ) {
		$label = trim( (string) $label );
		if ( '' === $label ) {
			return 0;
		}

		$name = $term['name'];
		$slug = isset( $term['slug'] ) ? $term['slug'] : sanitize_title( $name );

		if ( strcasecmp( $name, $label ) === 0 ) {
			return 3;
		}

		$label_slug = sanitize_title( $label );
		if ( $slug === $label_slug ) {
			return 2;
		}

		$name_lower  = strtolower( $name );
		$label_lower = strtolower( $label );
		if ( false !== strpos( $name_lower, $label_lower ) || false !== strpos( $label_lower, $name_lower ) ) {
			return 1;
		}

		foreach ( preg_split( '/[\s\-_]+/', $label_lower ) as $word ) {
			if ( strlen( $word ) < 3 ) {
				continue;
			}
			if ( false !== strpos( $name_lower, $word ) || false !== strpos( $slug, $word ) ) {
				return 1;
			}
		}

		return 0;
	}

	/**
	 * Match tags from Google types and mapped labels.
	 *
	 * @param array $types  Google types.
	 * @param array $mapped Mapped category names.
	 * @param array $tags   Available tags.
	 * @return int[]
	 */
	private static function match_tags_from_types( $types, $mapped, $tags, $place = null ) {
		$keywords = self::build_type_keywords( $types, $mapped, $place );

		$tag_ids = array();
		foreach ( $tags as $tag ) {
			foreach ( $keywords as $keyword ) {
				if ( self::match_term_score( $tag, $keyword ) > 0 ) {
					$tag_ids[] = (int) $tag['id'];
					break;
				}
			}
			if ( count( $tag_ids ) >= 3 ) {
				break;
			}
		}

		return $tag_ids;
	}

	/**
	 * AI tagging.
	 *
	 * @param array            $place    Place.
	 * @param array            $lists    Taxonomies.
	 * @param GPTG_AI_Provider $provider Provider.
	 * @return array|WP_Error
	 */
	private static function suggest_via_ai( $place, $lists, $provider ) {
		$context = GPTG_AI_Description::build_place_context( $place );

		$system = 'You assign WordPress taxonomy terms for a business directory listing. '
			. 'Reply with JSON only, no markdown. Use only numeric term IDs from the provided lists (match by name and slug). '
			. 'category_ids is REQUIRED: include exactly one ID from categories—the most specific fit for this business. '
			. 'If uncertain, choose the closest general category from the list (still a valid ID). Never leave category_ids empty. '
			. 'tag_ids is optional: include 0–3 relevant tag IDs from the tags list. '
			. 'Format: {"category_ids":[1],"tag_ids":[3,4],"confidence":0.9}';

		$user = wp_json_encode(
			array(
				'place'      => $context,
				'categories' => $lists['categories'],
				'tags'       => $lists['tags'],
			),
			JSON_PRETTY_PRINT
		);

		$raw = $provider->complete( $system, $user );
		if ( is_wp_error( $raw ) ) {
			return $raw;
		}

		$raw  = preg_replace( '/^```json\s*|\s*```$/', '', trim( $raw ) );
		$data = json_decode( $raw, true );
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'ai_parse', __( 'Could not parse AI tagging response.', 'gptg' ) );
		}

		$valid_cats = array_map( 'intval', wp_list_pluck( $lists['categories'], 'id' ) );
		$valid_tags = array_map( 'intval', wp_list_pluck( $lists['tags'], 'id' ) );

		$category_ids = array();
		if ( ! empty( $data['category_ids'] ) && is_array( $data['category_ids'] ) ) {
			foreach ( $data['category_ids'] as $id ) {
				$id = (int) $id;
				if ( in_array( $id, $valid_cats, true ) ) {
					$category_ids[] = $id;
				}
			}
		} elseif ( ! empty( $data['category_id'] ) ) {
			$id = (int) $data['category_id'];
			if ( in_array( $id, $valid_cats, true ) ) {
				$category_ids[] = $id;
			}
		}

		$tag_ids = array();
		if ( ! empty( $data['tag_ids'] ) && is_array( $data['tag_ids'] ) ) {
			foreach ( $data['tag_ids'] as $id ) {
				$id = (int) $id;
				if ( in_array( $id, $valid_tags, true ) ) {
					$tag_ids[] = $id;
				}
			}
		}

		// Prefer a single primary category when AI returns several.
		if ( count( $category_ids ) > 1 ) {
			$category_ids = array( $category_ids[0] );
		}

		return array(
			'category_ids' => $category_ids,
			'tag_ids'      => $tag_ids,
			'source'       => $provider->get_id(),
			'confidence'   => isset( $data['confidence'] ) ? (float) $data['confidence'] : null,
		);
	}

	/**
	 * Category-only AI pass when combined tagging returned no category.
	 *
	 * @param array            $place    Place data.
	 * @param array            $lists    Taxonomy lists.
	 * @param GPTG_AI_Provider $provider Provider.
	 * @return array|WP_Error Single-element category ID array or error.
	 */
	private static function suggest_category_via_ai( $place, $lists, $provider ) {
		if ( empty( $lists['categories'] ) ) {
			return array();
		}

		$context = GPTG_AI_Description::build_place_context( $place );

		$system = 'You pick exactly one WordPress category for a business directory listing. '
			. 'Reply with JSON only, no markdown. category_ids must contain exactly one numeric ID from the provided categories list. '
			. 'Choose the most specific category that fits. If unsure, pick the closest general category from the list. '
			. 'Format: {"category_ids":[12]} or {"category_id":12}';

		$user = wp_json_encode(
			array(
				'place'      => $context,
				'categories' => $lists['categories'],
			),
			JSON_PRETTY_PRINT
		);

		$raw = $provider->complete( $system, $user );
		if ( is_wp_error( $raw ) ) {
			return $raw;
		}

		$raw  = preg_replace( '/^```json\s*|\s*```$/', '', trim( $raw ) );
		$data = json_decode( $raw, true );
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'ai_parse', __( 'Could not parse AI category response.', 'gptg' ) );
		}

		$valid_cats = array_map( 'intval', wp_list_pluck( $lists['categories'], 'id' ) );
		$candidates = array();

		if ( ! empty( $data['category_ids'] ) && is_array( $data['category_ids'] ) ) {
			$candidates = $data['category_ids'];
		} elseif ( ! empty( $data['category_id'] ) ) {
			$candidates = array( $data['category_id'] );
		}

		foreach ( $candidates as $id ) {
			$id = (int) $id;
			if ( in_array( $id, $valid_cats, true ) ) {
				return array( $id );
			}
		}

		return array();
	}
}
