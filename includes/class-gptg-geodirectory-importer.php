<?php
/**
 * Create GeoDirectory listings from Google Places data.
 *
 * @package GPTG
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * GeoDirectory importer.
 */
class GPTG_GeoDirectory_Importer {

	const META_PLACE_ID = 'gptg_google_place_id';

	/**
	 * Import a single place.
	 *
	 * @param array  $place       Full place details from Google.
	 * @param string $post_type   GeoDirectory CPT.
	 * @param array  $args        Optional overrides (category_ids, tag_ids, post_status).
	 * @return array|WP_Error
	 */
	public function import_place( $place, $post_type, $args = array() ) {
		if ( ! class_exists( 'GeoDirectory' ) ) {
			return new WP_Error( 'no_geodir', __( 'GeoDirectory is not active.', 'gptg' ) );
		}

		if ( ! geodir_is_gd_post_type( $post_type ) ) {
			return new WP_Error( 'invalid_post_type', __( 'Invalid GeoDirectory post type.', 'gptg' ) );
		}

		$place_id = isset( $place['id'] ) ? $place['id'] : '';
		if ( empty( $place_id ) ) {
			return new WP_Error( 'no_place_id', __( 'Place ID is missing.', 'gptg' ) );
		}

		$existing = $this->find_existing_post( $place_id, $post_type );
		$duplicate_behavior = get_option( 'gptg_duplicate_behavior', 'skip' );

		if ( $existing && 'skip' === $duplicate_behavior ) {
			return array(
				'status'  => 'duplicate',
				'post_id' => $existing,
				'edit_url' => get_edit_post_link( $existing, 'raw' ),
				'message' => __( 'Listing already exists (skipped).', 'gptg' ),
			);
		}

		if ( GPTG_AI_Description::is_enabled() ) {
			$place = GPTG_AI_Description::enrich_place_for_description( $place );
		}

		$postarr = $this->build_postarr( $place, $post_type, $args );

		if ( $existing && 'update' === $duplicate_behavior ) {
			$postarr['ID'] = $existing;
			$post_id = wp_update_post( $postarr, true );
			$status  = 'updated';
		} else {
			$post_id = wp_insert_post( $postarr, true );
			$status  = 'created';
		}

		if ( is_wp_error( $post_id ) ) {
			$this->log_import( $place, $post_type, 'error', $post_id->get_error_message() );
			return $post_id;
		}

		update_post_meta( $post_id, self::META_PLACE_ID, $place_id );

		$category_ids = isset( $args['category_ids'] ) ? array_map( 'intval', (array) $args['category_ids'] ) : array();
		$tag_ids      = isset( $args['tag_ids'] ) ? array_map( 'intval', (array) $args['tag_ids'] ) : array();

		if ( empty( $category_ids ) && empty( $tag_ids ) && ! empty( $place['gptgTaxonomy'] ) && is_array( $place['gptgTaxonomy'] ) ) {
			if ( ! empty( $place['gptgTaxonomy']['category_ids'] ) ) {
				$category_ids = array_map( 'intval', (array) $place['gptgTaxonomy']['category_ids'] );
			}
			if ( ! empty( $place['gptgTaxonomy']['tag_ids'] ) ) {
				$tag_ids = array_map( 'intval', (array) $place['gptgTaxonomy']['tag_ids'] );
			}
		}

		$category_ids = array_values( array_unique( array_map( 'intval', (array) $category_ids ) ) );
		$tag_ids      = array_values( array_unique( array_map( 'intval', (array) $tag_ids ) ) );

		$this->assign_taxonomies( $post_id, $post_type, $category_ids, $tag_ids );
		$this->import_photos( $place, $post_id );

		$this->log_import(
			$place,
			$post_type,
			$status,
			'',
			$post_id
		);

		return array(
			'status'                 => $status,
			'post_id'                => $post_id,
			'edit_url'               => get_edit_post_link( $post_id, 'raw' ),
			'view_url'               => get_permalink( $post_id ),
			'assigned_category_ids'  => $category_ids,
			'assigned_tag_ids'       => $tag_ids,
			'message'                => 'created' === $status
				? __( 'Listing created successfully.', 'gptg' )
				: __( 'Listing updated successfully.', 'gptg' ),
		);
	}

	/**
	 * Find existing post by Google place ID.
	 *
	 * @param string $place_id  Google place ID.
	 * @param string $post_type Post type.
	 * @return int Post ID or 0.
	 */
	public function find_existing_post( $place_id, $post_type ) {
		$query = new WP_Query(
			array(
				'post_type'      => $post_type,
				'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_query'     => array(
					array(
						'key'   => self::META_PLACE_ID,
						'value' => $place_id,
					),
				),
			)
		);
		if ( ! empty( $query->posts[0] ) ) {
			return (int) $query->posts[0];
		}
		return 0;
	}

	/**
	 * Build wp_insert_post / GeoDirectory postarr.
	 *
	 * @param array  $place     Place data.
	 * @param string $post_type Post type.
	 * @param array  $args      Args.
	 * @return array
	 */
	private function build_postarr( $place, $post_type, $args ) {
		$api = new GPTG_Google_Places_API();

		$content = '';
		if ( ! empty( $place['editorialSummary']['text'] ) ) {
			$content = $place['editorialSummary']['text'];
		} elseif ( ! empty( $place['generativeSummary']['overview']['text'] ) ) {
			$content = $place['generativeSummary']['overview']['text'];
		}

		if ( ! empty( $place['gptgAiDescription'] ) ) {
			$content = $place['gptgAiDescription'];
		} elseif ( GPTG_AI_Description::is_enabled() ) {
			$has_ai_provider = (bool) GPTG_AI_Tagger::get_provider();
			$use_ai          = $has_ai_provider || GPTG_AI_Description::prefer_ai_over_google() || empty( $content );
			if ( $use_ai ) {
				$generated = GPTG_AI_Description::generate( $place, $post_type );
				if ( ! empty( $generated ) ) {
					$content = $generated;
				}
			}
		}

		$post_status = isset( $args['post_status'] ) ? $args['post_status'] : get_option( 'gptg_import_post_status', 'draft' );
		$author      = isset( $args['post_author'] ) ? (int) $args['post_author'] : get_option( 'gptg_import_post_author', get_current_user_id() );

		$postarr = array(
			'post_type'    => $post_type,
			'post_status'  => $post_status,
			'post_author'  => $author,
			'post_title'   => GPTG_Google_Places_API::get_place_name( $place ),
			'post_content' => $content,
		);

		if ( isset( $place['location']['latitude'] ) ) {
			$postarr['latitude'] = $place['location']['latitude'];
		}
		if ( isset( $place['location']['longitude'] ) ) {
			$postarr['longitude'] = $place['location']['longitude'];
		}

		if ( ! empty( $place['formattedAddress'] ) ) {
			$postarr['street'] = $place['formattedAddress'];
		}

		if ( ! empty( $place['addressComponents'] ) && is_array( $place['addressComponents'] ) ) {
			foreach ( $place['addressComponents'] as $component ) {
				$types = isset( $component['types'] ) ? $component['types'] : array();
				$text  = isset( $component['longText'] ) ? $component['longText'] : '';
				if ( in_array( 'locality', $types, true ) ) {
					$postarr['city'] = $text;
				}
				if ( in_array( 'administrative_area_level_1', $types, true ) ) {
					$postarr['region'] = $text;
				}
				if ( in_array( 'country', $types, true ) ) {
					$postarr['country'] = $text;
				}
				if ( in_array( 'postal_code', $types, true ) ) {
					$postarr['zip'] = $text;
				}
				if ( in_array( 'street_number', $types, true ) || in_array( 'route', $types, true ) ) {
					$postarr['street'] = isset( $postarr['street'] ) ? $postarr['street'] : $text;
				}
			}
		}

		if ( ! empty( $place['nationalPhoneNumber'] ) ) {
			$postarr['phone'] = $place['nationalPhoneNumber'];
		} elseif ( ! empty( $place['internationalPhoneNumber'] ) ) {
			$postarr['phone'] = $place['internationalPhoneNumber'];
		}

		if ( ! empty( $place['websiteUri'] ) ) {
			$postarr['website'] = $place['websiteUri'];
		}

		$hours = ! empty( $place['regularOpeningHours'] ) ? $place['regularOpeningHours'] : ( isset( $place['currentOpeningHours'] ) ? $place['currentOpeningHours'] : null );
		if ( $hours ) {
			$bh = $api->format_hours_for_geodirectory( $hours );
			if ( $bh ) {
				$postarr['business_hours'] = $bh;
			}
		}

		if ( isset( $place['rating'] ) ) {
			$postarr['overall_rating'] = (string) $place['rating'];
		}
		if ( isset( $place['userRatingCount'] ) ) {
			$postarr['rating_count'] = (string) $place['userRatingCount'];
		}

		$postarr['post_mapview'] = 'ROADMAP';
		$postarr['post_mapzoom'] = '12';

		if ( GPTG_Contact_Enricher::needs_enrichment( $place ) ) {
			$place = GPTG_Contact_Enricher::enrich( $place );
		}
		$postarr = GPTG_Contact_Enricher::apply_to_postarr( $postarr, $place, $post_type );

		return $postarr;
	}

	/**
	 * Assign categories and tags.
	 *
	 * @param int    $post_id       Post ID.
	 * @param string $post_type     Post type.
	 * @param array  $category_ids  Category term IDs.
	 * @param array  $tag_ids       Tag term IDs.
	 */
	private function assign_taxonomies( $post_id, $post_type, $category_ids, $tag_ids ) {
		$cat_tax = $post_type . 'category';
		$tag_tax = $post_type . '_tags';

		$category_ids = array_values( array_filter( array_map( 'intval', (array) $category_ids ) ) );
		$tag_ids      = array_values( array_filter( array_map( 'intval', (array) $tag_ids ) ) );

		if ( ! empty( $category_ids ) && taxonomy_exists( $cat_tax ) ) {
			wp_set_object_terms( $post_id, $category_ids, $cat_tax );
			if ( function_exists( 'geodir_save_post_meta' ) ) {
				geodir_save_post_meta( $post_id, 'post_category', ',' . implode( ',', $category_ids ) . ',' );
				geodir_save_post_meta( $post_id, 'default_category', $category_ids[0] );
			}
		}

		if ( ! empty( $tag_ids ) && taxonomy_exists( $tag_tax ) ) {
			wp_set_object_terms( $post_id, $tag_ids, $tag_tax );
			if ( function_exists( 'geodir_save_post_meta' ) ) {
				$tag_names = array();
				foreach ( $tag_ids as $term_id ) {
					$term = get_term( $term_id, $tag_tax );
					if ( $term && ! is_wp_error( $term ) ) {
						$tag_names[] = $term->name;
					}
				}
				if ( ! empty( $tag_names ) ) {
					geodir_save_post_meta( $post_id, 'post_tags', $tag_names );
				}
			}
		}
	}

	/**
	 * Sideload place photos into GeoDirectory media.
	 *
	 * @param array $place   Place data.
	 * @param int   $post_id Post ID.
	 */
	private function import_photos( $place, $post_id ) {
		if ( '1' !== get_option( 'gptg_import_images', '1' ) ) {
			return;
		}
		if ( empty( $place['photos'] ) || ! is_array( $place['photos'] ) ) {
			return;
		}
		if ( ! class_exists( 'GeoDir_Media' ) ) {
			return;
		}

		$limit = (int) get_option( 'gptg_import_photo_limit', 5 );
		if ( $limit <= 0 ) {
			return;
		}

		$featured_first = '1' === get_option( 'gptg_import_featured_first', '1' );
		$order          = $featured_first ? 0 : 1;
		$api            = new GPTG_Google_Places_API( get_option( 'gptg_api_key', '' ) );

		foreach ( array_slice( $place['photos'], 0, $limit ) as $photo ) {
			if ( empty( $photo['name'] ) ) {
				continue;
			}
			$photo_data = $api->get_place_photo_url( $photo['name'], 1200 );
			if ( is_wp_error( $photo_data ) || empty( $photo_data['url'] ) ) {
				continue;
			}

			$title  = GPTG_Google_Places_API::get_place_name( $place );
			$result = GeoDir_Media::insert_attachment(
				$post_id,
				'post_images',
				$photo_data['url'],
				$title,
				'',
				$order,
				1
			);
			if ( ! is_wp_error( $result ) ) {
				$order++;
			}
		}
	}

	/**
	 * Append to import log option.
	 *
	 * @param array  $place     Place.
	 * @param string $post_type Post type.
	 * @param string $status    Status.
	 * @param string $error     Error message.
	 * @param int    $post_id   Post ID.
	 */
	public function log_import( $place, $post_type, $status, $error = '', $post_id = 0 ) {
		$log = get_option( 'gptg_import_log', array() );
		if ( ! is_array( $log ) ) {
			$log = array();
		}

		array_unshift(
			$log,
			array(
				'time'       => current_time( 'mysql' ),
				'name'       => GPTG_Google_Places_API::get_place_name( $place ),
				'place_id'   => isset( $place['id'] ) ? $place['id'] : '',
				'post_type'  => $post_type,
				'post_id'    => $post_id,
				'status'     => $status,
				'error'      => $error,
			)
		);

		$log = array_slice( $log, 0, 100 );
		update_option( 'gptg_import_log', $log, false );
	}

	/**
	 * Clear import log.
	 */
	public static function clear_log() {
		delete_option( 'gptg_import_log' );
	}
}
