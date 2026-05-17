<?php
/**
 * AJAX Handlers
 *
 * @package GPTG
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * AJAX Class
 */
class GPTG_AJAX {
	
	/**
	 * Constructor
	 */
	public function __construct() {
		add_action( 'wp_ajax_gptg_test_api', array( $this, 'test_api' ) );
		add_action( 'wp_ajax_gptg_search_places', array( $this, 'search_places' ) );
		add_action( 'wp_ajax_gptg_search_comprehensive', array( $this, 'search_comprehensive' ) );
		add_action( 'wp_ajax_gptg_save_places', array( $this, 'save_places' ) );
		add_action( 'wp_ajax_gptg_clear_places', array( $this, 'clear_places' ) );
		add_action( 'wp_ajax_gptg_export_places', array( $this, 'export_places' ) );
		add_action( 'wp_ajax_gptg_get_place_details', array( $this, 'get_place_details' ) );
		add_action( 'wp_ajax_gptg_import_place', array( $this, 'import_place' ) );
		add_action( 'wp_ajax_gptg_import_places', array( $this, 'import_places' ) );
		add_action( 'wp_ajax_gptg_clear_import_log', array( $this, 'clear_import_log' ) );
		add_action( 'wp_ajax_gptg_get_taxonomies', array( $this, 'get_taxonomies' ) );
		add_action( 'wp_ajax_gptg_enrich_contact', array( $this, 'enrich_contact' ) );
		add_action( 'wp_ajax_gptg_test_hunter', array( $this, 'test_hunter' ) );
		add_action( 'wp_ajax_gptg_batch_enrich_contacts', array( $this, 'batch_enrich_contacts' ) );
	}
	
	/**
	 * Test API connection
	 */
	public function test_api() {
		check_ajax_referer( 'gptg-admin-nonce', 'nonce' );
		
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'gptg' ) ) );
		}
		
		$api_key = get_option( 'gptg_api_key', '' );
		
		if ( empty( $api_key ) ) {
			wp_send_json_error( array( 'message' => __( 'API key is not set.', 'gptg' ) ) );
		}
		
		$api = new GPTG_Google_Places_API( $api_key );
		
		// Match production Text Search path (restricted field mask, not *)
		$result = $api->text_search( array(
			'textQuery' => 'restaurant',
			'maxResultCount' => 1,
		), false );
		
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		$places = isset( $result['places'] ) ? $result['places'] : array();

		if ( empty( $places ) ) {
			wp_send_json_error( array(
				'message' => __( 'API connected but Text Search returned no places. Check your query, billing, and Places API (New) permissions.', 'gptg' ),
			) );
		}

		$place_count = count( $places );
		$details_status = __( 'Details OK', 'gptg' );

		if ( isset( $places[0]['id'] ) ) {
			$details = $api->get_place_details( $places[0]['id'] );
			if ( is_wp_error( $details ) ) {
				$details_status = sprintf(
					/* translators: %s: error message from Place Details API */
					__( 'Details failed: %s', 'gptg' ),
					$details->get_error_message()
				);
			}
		}

		wp_send_json_success( array(
			'message' => sprintf(
				/* translators: 1: number of places, 2: Place Details status */
				__( 'API OK — found %1$d place(s), %2$s', 'gptg' ),
				$place_count,
				$details_status
			),
		) );
	}
	
	/**
	 * Search places with pagination support
	 */
	public function search_places() {
		check_ajax_referer( 'gptg-admin-nonce', 'nonce' );
		
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'gptg' ) ) );
		}
		
		$api_key = get_option( 'gptg_api_key', '' );
		
		if ( empty( $api_key ) ) {
			wp_send_json_error( array( 'message' => __( 'API key is not set.', 'gptg' ) ) );
		}
		
		$api = new GPTG_Google_Places_API( $api_key );
		$search_method = isset( $_POST['search_method'] ) ? sanitize_text_field( $_POST['search_method'] ) : 'text';
		
		// Get requested result count (max 60)
		$requested_count = 20;
		if ( 'text' === $search_method ) {
			$requested_count = isset( $_POST['max_result_count'] ) ? intval( $_POST['max_result_count'] ) : 20;
		} else {
			$requested_count = isset( $_POST['nearby_max_result_count'] ) ? intval( $_POST['nearby_max_result_count'] ) : 20;
		}

		// Empty or invalid max result count must not slice results to zero.
		if ( $requested_count < 1 ) {
			$requested_count = 20;
		}

		// Limit to 60 (API maximum)
		$requested_count = min( $requested_count, 60 );
		
		$params = array();
		$all_places = array();
		$page_token = '';
		$page_count = 0;
		$max_pages = max( 1, (int) ceil( $requested_count / 20 ) );
		$page_size = min( 20, $requested_count );
		
		// Build base params
		if ( 'text' === $search_method ) {
			$params['textQuery'] = isset( $_POST['text_query'] ) ? sanitize_text_field( wp_unslash( $_POST['text_query'] ) ) : '';
			$params['regionCode'] = isset( $_POST['region_code'] ) ? sanitize_text_field( wp_unslash( $_POST['region_code'] ) ) : '';
			$params['includedType'] = isset( $_POST['included_type'] ) ? sanitize_text_field( wp_unslash( $_POST['included_type'] ) ) : '';
			$params['minRating'] = ( isset( $_POST['min_rating'] ) && '' !== $_POST['min_rating'] ) ? floatval( $_POST['min_rating'] ) : '';
			
			if ( empty( $params['textQuery'] ) ) {
				wp_send_json_error( array( 'message' => __( 'Search query is required.', 'gptg' ) ) );
			}
		} else {
			$latitude = isset( $_POST['latitude'] ) ? floatval( $_POST['latitude'] ) : 0;
			$longitude = isset( $_POST['longitude'] ) ? floatval( $_POST['longitude'] ) : 0;
			$radius = isset( $_POST['radius'] ) ? intval( $_POST['radius'] ) : 5000;
			
			if ( empty( $latitude ) || empty( $longitude ) ) {
				wp_send_json_error( array( 'message' => __( 'Latitude and longitude are required.', 'gptg' ) ) );
			}
			
			$params['locationRestriction'] = array(
				'circle' => array(
					'center' => array(
						'latitude' => $latitude,
						'longitude' => $longitude,
					),
					'radius' => $radius,
				),
			);
			$params['includedType'] = isset( $_POST['nearby_included_type'] ) ? sanitize_text_field( $_POST['nearby_included_type'] ) : '';
		}
		
		// Fetch pages until we have enough results or no more pages
		do {
			$page_count++;

			$remaining = $requested_count - count( $all_places );
			if ( $remaining <= 0 ) {
				break;
			}

			// Use stable page size for pagination (Google requires matching params per page)
			$params['maxResultCount'] = min( $page_size, $remaining );
			
			// Make API request
			if ( 'text' === $search_method ) {
				$result = $api->text_search( $params, false, $page_token );
			} else {
				$result = $api->nearby_search( $params, $page_token );
			}
			
			if ( is_wp_error( $result ) ) {
				wp_send_json_error( array( 'message' => $result->get_error_message() ) );
			}
			
			$places = isset( $result['places'] ) ? $result['places'] : array();
			$all_places = array_merge( $all_places, $places );
			
			// Check for next page token
			$page_token = isset( $result['nextPageToken'] ) ? $result['nextPageToken'] : '';
			
			// If we have more pages and haven't reached the limit, wait 2 seconds before next request
			if ( ! empty( $page_token ) && count( $all_places ) < $requested_count && $page_count < $max_pages ) {
				sleep( 2 ); // Wait 2 seconds for token to become valid
			}
			
		} while ( ! empty( $page_token ) && count( $all_places ) < $requested_count && $page_count < $max_pages );
		
		// Limit to requested count (never discard when we already have results)
		if ( $requested_count > 0 && count( $all_places ) > $requested_count ) {
			$all_places = array_slice( $all_places, 0, $requested_count );
		}

		$all_places = array_values( $all_places );

		if ( empty( $all_places ) ) {
			$message = $this->build_empty_search_message( $search_method, $params );
			$response = array(
				'places' => array(),
				'count' => 0,
				'pages_fetched' => $page_count,
				'message' => $message,
			);
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				$response['debug'] = array(
					'search_method' => $search_method,
					'text_query' => isset( $params['textQuery'] ) ? $params['textQuery'] : '',
					'requested_count' => $requested_count,
				);
			}
			wp_send_json_success( $response );
		}

		$enrichment = $this->enrich_places_with_details( $api, $all_places );

		wp_send_json_success( array(
			'places' => array_values( $enrichment['places'] ),
			'count' => count( $enrichment['places'] ),
			'pages_fetched' => $page_count,
			'details_errors' => $enrichment['details_errors'],
		) );
	}
	
	/**
	 * Comprehensive area search
	 */
	public function search_comprehensive() {
		check_ajax_referer( 'gptg-admin-nonce', 'nonce' );
		
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'gptg' ) ) );
		}
		
		$api_key = get_option( 'gptg_api_key', '' );
		
		if ( empty( $api_key ) ) {
			wp_send_json_error( array( 'message' => __( 'API key is not set.', 'gptg' ) ) );
		}
		
		$latitude = isset( $_POST['comprehensive_latitude'] ) ? floatval( $_POST['comprehensive_latitude'] ) : 0;
		$longitude = isset( $_POST['comprehensive_longitude'] ) ? floatval( $_POST['comprehensive_longitude'] ) : 0;
		$radius_meters = isset( $_POST['comprehensive_radius'] ) ? intval( $_POST['comprehensive_radius'] ) : 0;
		$cell_radius_meters = isset( $_POST['comprehensive_cell_radius_meters'] ) ? intval( $_POST['comprehensive_cell_radius_meters'] ) : 2000;
		
		if ( empty( $latitude ) || empty( $longitude ) || empty( $radius_meters ) ) {
			wp_send_json_error( array( 'message' => __( 'Center location and radius are required.', 'gptg' ) ) );
		}
		
		$api = new GPTG_Google_Places_API( $api_key );
		
		$params = array(
			'center' => array(
				'latitude' => $latitude,
				'longitude' => $longitude,
			),
			'radius' => $radius_meters,
			'cell_radius' => $cell_radius_meters,
		);
		
		if ( isset( $_POST['comprehensive_included_type'] ) && ! empty( $_POST['comprehensive_included_type'] ) ) {
			$params['includedType'] = sanitize_text_field( $_POST['comprehensive_included_type'] );
		}
		
		// Progress callback for real-time updates
		$progress_updates = array();
		$progress_callback = function( $current, $total, $message ) use ( &$progress_updates ) {
			$progress_updates[] = array(
				'current' => $current,
				'total' => $total,
				'message' => $message,
				'percent' => round( ( $current / $total ) * 100 ),
			);
		};
		
		$result = $api->search_area_comprehensive( $params, $progress_callback );
		
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 
				'message' => $result->get_error_message(),
				'error_code' => $result->get_error_code(),
			) );
		}
		
		$places = isset( $result['places'] ) ? $result['places'] : array();
		
		// If no places found, return helpful message with debug info
		if ( empty( $places ) ) {
			$message = __( 'No places found in the specified area.', 'gptg' );
			if ( isset( $result['cells_searched'] ) && $result['cells_searched'] > 0 ) {
				$message .= ' ' . sprintf( 
					__( 'Searched %d grid cell(s), %d had results.', 'gptg' ),
					$result['cells_searched'],
					isset( $result['cells_with_results'] ) ? $result['cells_with_results'] : 0
				);
			}
			$message .= ' ' . __( 'Try adjusting the radius, location, or place type filter.', 'gptg' );
			
			wp_send_json_success( array(
				'places' => array(),
				'count' => 0,
				'total_cells' => isset( $result['total_cells'] ) ? $result['total_cells'] : 0,
				'cells_searched' => isset( $result['cells_searched'] ) ? $result['cells_searched'] : 0,
				'cells_with_results' => isset( $result['cells_with_results'] ) ? $result['cells_with_results'] : 0,
				'unique_count' => 0,
				'message' => $message,
			) );
		}
		
		$enrichment = $this->enrich_places_with_details( $api, $places );

		wp_send_json_success( array(
			'places' => $enrichment['places'],
			'count' => count( $enrichment['places'] ),
			'total_cells' => isset( $result['total_cells'] ) ? $result['total_cells'] : 0,
			'unique_count' => isset( $result['unique_count'] ) ? $result['unique_count'] : count( $enrichment['places'] ),
			'details_errors' => $enrichment['details_errors'],
		) );
	}

	/**
	 * Enrich search results with Place Details (capped to avoid timeouts).
	 *
	 * @param GPTG_Google_Places_API $api API instance.
	 * @param array                  $places Places from search.
	 * @return array { places: array, details_errors: int }
	 */
	private function enrich_places_with_details( $api, $places ) {
		$skip_details = apply_filters( 'gptg_skip_place_details', false );
		$details_limit = (int) apply_filters( 'gptg_place_details_limit', 20 );

		if ( $skip_details || $details_limit <= 0 ) {
			return array(
				'places' => $places,
				'details_errors' => 0,
			);
		}

		$detailed_places = array();
		$details_errors = 0;

		foreach ( $places as $index => $place ) {
			if ( $index < $details_limit && isset( $place['id'] ) ) {
				$details = $api->get_place_details( $place['id'] );
				if ( ! is_wp_error( $details ) ) {
					$detailed_places[] = $details;
				} else {
					$detailed_places[] = $place;
					$details_errors++;
				}
			} else {
				$detailed_places[] = $place;
			}
		}

		return array(
			'places' => $detailed_places,
			'details_errors' => $details_errors,
		);
	}

	/**
	 * Build a helpful message when a search returns no places.
	 *
	 * @param string $search_method Search method key.
	 * @param array  $params        Search parameters used.
	 * @return string
	 */
	private function build_empty_search_message( $search_method, $params ) {
		if ( 'text' === $search_method ) {
			$message = __( 'No places found for your search.', 'gptg' );
			$message .= ' ' . sprintf(
				/* translators: %s: search query text */
				__( 'Query: "%s".', 'gptg' ),
				isset( $params['textQuery'] ) ? $params['textQuery'] : ''
			);

			$filters = array();
			if ( ! empty( $params['regionCode'] ) ) {
				$filters[] = sprintf(
					/* translators: %s: ISO region code */
					__( 'region: %s', 'gptg' ),
					$params['regionCode']
				);
			}
			if ( ! empty( $params['includedType'] ) ) {
				$filters[] = sprintf(
					/* translators: %s: place type */
					__( 'type: %s', 'gptg' ),
					$params['includedType']
				);
			}
			if ( ! empty( $params['minRating'] ) ) {
				$filters[] = sprintf(
					/* translators: %s: minimum rating */
					__( 'min rating: %s', 'gptg' ),
					$params['minRating']
				);
			}

			if ( ! empty( $filters ) ) {
				$message .= ' ' . sprintf(
					/* translators: %s: comma-separated filter list */
					__( 'Active filters: %s.', 'gptg' ),
					implode( ', ', $filters )
				);
			}

			$message .= ' ' . __( 'Try a broader query, e.g. "restaurants in New York", or remove filters.', 'gptg' );
			return $message;
		}

		$message = __( 'No places found near this location.', 'gptg' );
		if ( ! empty( $params['includedType'] ) ) {
			$message .= ' ' . sprintf(
				/* translators: %s: place type */
				__( 'Type filter: %s.', 'gptg' ),
				$params['includedType']
			);
		}
		$message .= ' ' . __( 'Try increasing the radius or changing the place type.', 'gptg' );
		return $message;
	}
	
	/**
	 * Save places to export list
	 */
	public function save_places() {
		check_ajax_referer( 'gptg-admin-nonce', 'nonce' );
		
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'gptg' ) ) );
		}
		
		$places = isset( $_POST['places'] ) ? json_decode( stripslashes( $_POST['places'] ), true ) : array();
		
		if ( empty( $places ) || ! is_array( $places ) ) {
			wp_send_json_error( array( 'message' => __( 'No places to save.', 'gptg' ) ) );
		}
		
		$saved_places = get_option( 'gptg_saved_places', array() );
		
		// Add new places (avoid duplicates by place ID)
		foreach ( $places as $place ) {
			$place_id = isset( $place['id'] ) ? $place['id'] : '';
			if ( ! empty( $place_id ) ) {
				// Check if already exists
				$exists = false;
				foreach ( $saved_places as $saved_place ) {
					if ( isset( $saved_place['id'] ) && $saved_place['id'] === $place_id ) {
						$exists = true;
						break;
					}
				}
				if ( ! $exists ) {
					$saved_places[] = $place;
				}
			}
		}
		
		update_option( 'gptg_saved_places', $saved_places );
		
		wp_send_json_success( array(
			'message' => sprintf( __( '%d place(s) saved successfully.', 'gptg' ), count( $places ) ),
			'total' => count( $saved_places ),
		) );
	}
	
	/**
	 * Clear saved places
	 */
	public function clear_places() {
		check_ajax_referer( 'gptg-admin-nonce', 'nonce' );
		
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'gptg' ) ) );
		}
		
		delete_option( 'gptg_saved_places' );
		
		wp_send_json_success( array( 'message' => __( 'All places cleared.', 'gptg' ) ) );
	}
	
	/**
	 * Export places
	 */
	public function export_places() {
		check_ajax_referer( 'gptg-admin-nonce', 'nonce' );
		
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( __( 'Permission denied.', 'gptg' ) );
		}
		
		$saved_places = get_option( 'gptg_saved_places', array() );
		
		if ( empty( $saved_places ) ) {
			wp_die( __( 'No places to export.', 'gptg' ) );
		}
		
		$export_format = isset( $_POST['export_format'] ) ? sanitize_text_field( $_POST['export_format'] ) : 'csv';
		$post_type = isset( $_POST['post_type'] ) ? sanitize_text_field( $_POST['post_type'] ) : 'gd_place';
		
		$export = new GPTG_Export();
		
		if ( 'json' === $export_format ) {
			$export->export_to_json( $saved_places, $post_type );
		} else {
			$export->export_to_csv( $saved_places, $post_type );
		}
	}

	/**
	 * Fetch full place details (cached).
	 */
	public function get_place_details() {
		check_ajax_referer( 'gptg-admin-nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'gptg' ) ) );
		}

		$place_id = isset( $_POST['place_id'] ) ? sanitize_text_field( wp_unslash( $_POST['place_id'] ) ) : '';
		if ( empty( $place_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Place ID is required.', 'gptg' ) ) );
		}

		$api_key = get_option( 'gptg_api_key', '' );
		if ( empty( $api_key ) ) {
			wp_send_json_error( array( 'message' => __( 'API key is not set.', 'gptg' ) ) );
		}

		$api = new GPTG_Google_Places_API( $api_key );

		$cached = GPTG_Place_Cache::get( $place_id );
		if ( $cached ) {
			wp_send_json_success( array(
				'place'  => $this->prepare_place_for_modal( $cached, $api ),
				'cached' => true,
			) );
		}

		$place = $api->get_place_details( $place_id );

		if ( is_wp_error( $place ) ) {
			wp_send_json_error( array( 'message' => $place->get_error_message() ) );
		}

		GPTG_Place_Cache::set( $place_id, $place );

		wp_send_json_success( array(
			'place'  => $this->prepare_place_for_modal( $place, $api ),
			'cached' => false,
		) );
	}

	/**
	 * Enrich place payload for admin modal (hours text, photo URLs).
	 *
	 * @param array                 $place Place data.
	 * @param GPTG_Google_Places_API $api   API instance.
	 * @return array
	 */
	private function prepare_place_for_modal( $place, $api ) {
		if ( ! empty( $place['regularOpeningHours'] ) ) {
			$place['gptgHoursFormatted'] = $api->format_hours_for_geodirectory( $place['regularOpeningHours'] );
			$place['gptgHoursText']      = $api->format_opening_hours_text( $place['regularOpeningHours'] );
		}
		if ( ! empty( $place['photos'] ) && is_array( $place['photos'] ) ) {
			foreach ( $place['photos'] as $i => $photo ) {
				if ( empty( $photo['name'] ) ) {
					continue;
				}
				$url = $api->get_place_photo_url( $photo['name'], 640 );
				if ( ! is_wp_error( $url ) ) {
					$place['photos'][ $i ]['gptgPhotoUrl'] = $url['url'];
				}
			}
		}
		return $place;
	}

	/**
	 * Get taxonomy lists for UI.
	 */
	public function get_taxonomies() {
		check_ajax_referer( 'gptg-admin-nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'gptg' ) ) );
		}

		$post_type = isset( $_POST['post_type'] ) ? sanitize_text_field( $_POST['post_type'] ) : 'gd_place';
		$lists     = GPTG_AI_Tagger::get_taxonomy_lists( $post_type );
		$lists['has_categories']  = ! empty( $lists['categories'] );
		$lists['category_count']  = count( $lists['categories'] );
		wp_send_json_success( $lists );
	}

	/**
	 * Import single place to GeoDirectory.
	 */
	public function import_place() {
		check_ajax_referer( 'gptg-admin-nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'gptg' ) ) );
		}

		$place = isset( $_POST['place'] ) ? json_decode( wp_unslash( $_POST['place'] ), true ) : array();
		$post_type = isset( $_POST['post_type'] ) ? sanitize_text_field( $_POST['post_type'] ) : get_option( 'gptg_import_post_type', 'gd_place' );

		if ( empty( $place ) || empty( $place['id'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Place data is required.', 'gptg' ) ) );
		}

		if ( ! empty( $_POST['gptg_contact'] ) ) {
			$incoming = GPTG_Contact_Enricher::sanitize_contact( json_decode( wp_unslash( $_POST['gptg_contact'] ), true ) );
			if ( ! isset( $place['gptgContact'] ) || ! is_array( $place['gptgContact'] ) ) {
				$place['gptgContact'] = array();
			}
			foreach ( $incoming as $key => $value ) {
				if ( '' !== $value ) {
					$place['gptgContact'][ $key ] = $value;
				}
			}
		}

		$args = array(
			'post_status'        => isset( $_POST['post_status'] ) ? sanitize_text_field( $_POST['post_status'] ) : get_option( 'gptg_import_post_status', 'draft' ),
			'category_ids'       => isset( $_POST['category_ids'] ) ? array_map( 'intval', (array) json_decode( wp_unslash( $_POST['category_ids'] ), true ) ) : array(),
			'tag_ids'            => isset( $_POST['tag_ids'] ) ? array_map( 'intval', (array) json_decode( wp_unslash( $_POST['tag_ids'] ), true ) ) : array(),
			'taxonomy_reviewed'  => ! empty( $_POST['taxonomy_reviewed'] ),
		);

		$importer = new GPTG_GeoDirectory_Importer();
		$result   = $importer->import_place( $place, $post_type, $args );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( $result );
	}

	/**
	 * Import one place from bulk queue (by place_id, fetches details).
	 */
	public function import_places() {
		check_ajax_referer( 'gptg-admin-nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'gptg' ) ) );
		}

		$place_id = isset( $_POST['place_id'] ) ? sanitize_text_field( wp_unslash( $_POST['place_id'] ) ) : '';
		$post_type = isset( $_POST['post_type'] ) ? sanitize_text_field( $_POST['post_type'] ) : get_option( 'gptg_import_post_type', 'gd_place' );

		if ( empty( $place_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Place ID is required.', 'gptg' ) ) );
		}

		$cached = GPTG_Place_Cache::get( $place_id );
		if ( ! $cached ) {
			$api = new GPTG_Google_Places_API( get_option( 'gptg_api_key', '' ) );
			$cached = $api->get_place_details( $place_id );
			if ( is_wp_error( $cached ) ) {
				wp_send_json_error( array( 'message' => $cached->get_error_message() ) );
			}
			GPTG_Place_Cache::set( $place_id, $cached );
		}

		$args = array(
			'post_status' => get_option( 'gptg_import_post_status', 'draft' ),
		);

		$importer = new GPTG_GeoDirectory_Importer();
		$result   = $importer->import_place( $cached, $post_type, $args );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( $result );
	}

	/**
	 * Enrich place with email/social via Hunter or Apollo.
	 */
	public function enrich_contact() {
		check_ajax_referer( 'gptg-admin-nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'gptg' ) ) );
		}

		if ( ! GPTG_Contact_Enricher::is_enabled() ) {
			wp_send_json_error( array( 'message' => __( 'Contact enrichment is disabled.', 'gptg' ) ) );
		}

		$place_id = isset( $_POST['place_id'] ) ? sanitize_text_field( wp_unslash( $_POST['place_id'] ) ) : '';
		$place    = isset( $_POST['place'] ) ? json_decode( wp_unslash( $_POST['place'] ), true ) : array();
		$force    = ! empty( $_POST['force'] ) && '1' === (string) $_POST['force'];

		if ( empty( $place ) && ! empty( $place_id ) ) {
			$place = GPTG_Place_Cache::get( $place_id );
		}

		if ( empty( $place ) || empty( $place['websiteUri'] ) ) {
			wp_send_json_error( array( 'message' => __( 'A website is required to look up contact details.', 'gptg' ) ) );
		}

		$domain = GPTG_Contact_Enricher::extract_domain( $place['websiteUri'] );
		if ( empty( $domain ) ) {
			wp_send_json_error(
				array(
					'message'      => __( 'Website URL is not a valid business domain for contact lookup.', 'gptg' ),
					'domain_used'  => '',
					'error_detail' => GPTG_Contact_Enricher::get_last_enrichment_error(),
				)
			);
		}

		if ( ! $force && ! GPTG_Contact_Enricher::needs_enrichment( $place ) ) {
			wp_send_json_success(
				array(
					'contact'     => GPTG_Contact_Enricher::get_contact( $place ),
					'place'       => $place,
					'cached'      => true,
					'provider'    => GPTG_Contact_Enricher::get_provider_id(),
					'domain_used' => $domain,
					'skipped'     => true,
				)
			);
		}

		$from_cache = false;
		if ( ! $force ) {
			$cached = GPTG_Contact_Cache::get( $domain );
			if ( is_array( $cached ) && GPTG_Contact_Enricher::has_contact_data( $cached ) ) {
				$place      = GPTG_Contact_Enricher::merge_contact( $place, $cached );
				$from_cache = true;
			}
		}

		if ( ! $from_cache ) {
			$place = GPTG_Contact_Enricher::enrich( $place, $force );
		}

		if ( ! empty( $place_id ) ) {
			GPTG_Place_Cache::set( $place_id, $place );
		}

		$contact      = GPTG_Contact_Enricher::get_contact( $place );
		$provider     = GPTG_Contact_Enricher::get_provider_id();
		$provider_lbl = ucfirst( $provider );
		$error_detail = GPTG_Contact_Enricher::get_last_enrichment_error();
		$message      = '';

		if ( GPTG_Contact_Enricher::has_contact_data( $contact ) ) {
			$message = $provider_lbl;
			if ( ! empty( $contact['fetched_at'] ) ) {
				$message .= ' — ' . $contact['fetched_at'];
			}
			if ( $from_cache ) {
				$message .= ' (cached)';
			}
		} elseif ( $from_cache ) {
			$message = sprintf(
				/* translators: %s: domain name */
				__( '%1$s: No data for %2$s', 'gptg' ),
				$provider_lbl,
				$domain
			);
		} elseif ( $error_detail ) {
			$message = $provider_lbl . ': ' . $error_detail;
		} else {
			$message = sprintf(
				/* translators: %s: domain name */
				__( '%1$s: No data for %2$s', 'gptg' ),
				$provider_lbl,
				$domain
			);
		}

		wp_send_json_success(
			array(
				'contact'      => $contact,
				'place'        => $place,
				'cached'       => $from_cache,
				'provider'     => $provider,
				'domain_used'  => $domain,
				'message'      => $message,
				'error_detail' => $error_detail,
			)
		);
	}

	/**
	 * Test Hunter.io API key with a known domain lookup.
	 */
	public function test_hunter() {
		check_ajax_referer( 'gptg-admin-nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'gptg' ) ) );
		}

		$key = GPTG_Contact_Enricher::get_api_key( 'hunter' );
		if ( empty( $key ) ) {
			wp_send_json_error( array( 'message' => __( 'Hunter API key is not set.', 'gptg' ) ) );
		}

		$hunter = new GPTG_Contact_Hunter( $key );
		$result = $hunter->enrich_by_domain( 'hunter.io' );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error(
				array(
					'message' => $result->get_error_message(),
				)
			);
		}

		$email_count = ! empty( $result['email'] ) ? 1 : 0;
		$sample      = ! empty( $result['email'] ) ? $result['email'] : '';

		wp_send_json_success(
			array(
				'message' => sprintf(
					/* translators: 1: email count, 2: sample email or dash */
					__( 'Hunter connection OK. Found %1$d email(s). Sample: %2$s', 'gptg' ),
					$email_count,
					$sample ? $sample : '—'
				),
				'email_count' => $email_count,
				'sample'      => $sample,
			)
		);
	}

	/**
	 * Batch enrich contacts for search results (dedupe by domain).
	 */
	public function batch_enrich_contacts() {
		check_ajax_referer( 'gptg-admin-nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'gptg' ) ) );
		}

		if ( ! GPTG_Contact_Enricher::is_enabled() ) {
			wp_send_json_success( array( 'contacts' => array() ) );
		}

		$places = isset( $_POST['places'] ) ? json_decode( wp_unslash( $_POST['places'] ), true ) : array();
		if ( ! is_array( $places ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid places payload.', 'gptg' ) ) );
		}

		$places = array_slice( $places, 0, 60 );
		$contacts_by_place = array();
		$domain_contacts     = array();

		foreach ( $places as $place ) {
			if ( empty( $place['id'] ) || empty( $place['websiteUri'] ) ) {
				continue;
			}

			$place_id = sanitize_text_field( $place['id'] );
			$domain   = GPTG_Contact_Enricher::extract_domain( $place['websiteUri'] );
			if ( empty( $domain ) ) {
				$contacts_by_place[ $place_id ] = array();
				continue;
			}

			if ( ! isset( $domain_contacts[ $domain ] ) ) {
				$stub = array(
					'websiteUri' => $place['websiteUri'],
					'gptgContact' => array(),
				);
				$enriched = GPTG_Contact_Enricher::enrich( $stub );
				$domain_contacts[ $domain ] = GPTG_Contact_Enricher::get_contact( $enriched );
			}

			$contacts_by_place[ $place_id ] = $domain_contacts[ $domain ];
		}

		wp_send_json_success(
			array(
				'contacts' => $contacts_by_place,
			)
		);
	}

	/**
	 * Clear import log.
	 */
	public function clear_import_log() {
		check_ajax_referer( 'gptg-admin-nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'gptg' ) ) );
		}

		GPTG_GeoDirectory_Importer::clear_log();
		wp_send_json_success( array( 'message' => __( 'Import log cleared.', 'gptg' ) ) );
	}
}

