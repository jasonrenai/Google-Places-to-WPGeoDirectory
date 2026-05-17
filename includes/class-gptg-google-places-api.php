<?php
/**
 * Google Places API Integration
 *
 * @package GPTG
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Google Places API Class
 */
class GPTG_Google_Places_API {
	
	/**
	 * API Key
	 *
	 * @var string
	 */
	private $api_key;
	
	/**
	 * API Base URL
	 *
	 * @var string
	 */
	private $api_base_url = 'https://places.googleapis.com/v1/';
	
	/**
	 * Constructor
	 *
	 * @param string $api_key Google Places API key.
	 */
	public function __construct( $api_key = '' ) {
		$this->api_key = $api_key ? $api_key : get_option( 'gptg_api_key', '' );
	}
	
	/**
	 * Search for places using Text Search
	 *
	 * @param array $params Search parameters.
	 * @param bool  $use_all_fields If true, requests all fields (for testing).
	 * @param string $page_token Optional page token for pagination.
	 * @return array|WP_Error
	 */
	public function text_search( $params, $use_all_fields = false, $page_token = '' ) {
		$endpoint = 'places:searchText';
		
		$defaults = array(
			'textQuery' => '',
			'languageCode' => 'en',
			'regionCode' => '',
			'locationBias' => array(),
			'locationRestriction' => array(),
			'includedType' => '',
			'excludedType' => '',
			'priceLevel' => '',
			'minRating' => '',
			'maxResultCount' => 20,
			'rankPreference' => 'RELEVANCE',
		);
		
		$params = wp_parse_args( $params, $defaults );
		
		// Build request body
		$body = array();
		
		if ( ! empty( $params['textQuery'] ) ) {
			$body['textQuery'] = $params['textQuery'];
		}
		
		if ( ! empty( $params['languageCode'] ) ) {
			$body['languageCode'] = $params['languageCode'];
		}
		
		if ( ! empty( $params['regionCode'] ) ) {
			$body['regionCode'] = $params['regionCode'];
		}
		
		if ( ! empty( $params['locationBias'] ) ) {
			$body['locationBias'] = $params['locationBias'];
		}
		
		if ( ! empty( $params['locationRestriction'] ) ) {
			$body['locationRestriction'] = $params['locationRestriction'];
		}
		
		if ( ! empty( $params['includedType'] ) ) {
			$body['includedType'] = $params['includedType'];
		}
		
		if ( ! empty( $params['excludedType'] ) ) {
			$body['excludedType'] = $params['excludedType'];
		}
		
		if ( ! empty( $params['priceLevel'] ) ) {
			$body['priceLevel'] = $params['priceLevel'];
		}
		
		if ( ! empty( $params['minRating'] ) ) {
			$body['minRating'] = floatval( $params['minRating'] );
		}
		
		if ( ! empty( $params['maxResultCount'] ) ) {
			$page_size = max( 1, min( 20, intval( $params['maxResultCount'] ) ) );
			$body['pageSize'] = $page_size;
		}
		
		if ( ! empty( $params['rankPreference'] ) ) {
			$body['rankPreference'] = $params['rankPreference'];
		}
		
		// Add page token for pagination
		if ( ! empty( $page_token ) ) {
			$body['pageToken'] = $page_token;
		}
		
		$field_mask = $use_all_fields ? $this->get_search_field_mask( true ) : '';
		return $this->make_request( $endpoint, $body, $field_mask );
	}
	
	/**
	 * Search for places using Nearby Search
	 *
	 * @param array $params Search parameters.
	 * @param string $page_token Optional page token for pagination.
	 * @return array|WP_Error
	 */
	public function nearby_search( $params, $page_token = '' ) {
		$endpoint = 'places:searchNearby';
		
		$defaults = array(
			'locationRestriction' => array(
				'circle' => array(
					'center' => array(),
					'radius' => 5000, // meters
				),
			),
			'includedType' => '',
			'excludedType' => '',
			'languageCode' => 'en',
			'maxResultCount' => 20,
			'rankPreference' => 'POPULARITY',
		);
		
		$params = wp_parse_args( $params, $defaults );
		
		$body = array();
		
		if ( ! empty( $params['locationRestriction'] ) ) {
			$body['locationRestriction'] = $params['locationRestriction'];
		}
		
		if ( ! empty( $params['includedType'] ) ) {
			$body['includedType'] = $params['includedType'];
		}
		
		if ( ! empty( $params['excludedType'] ) ) {
			$body['excludedType'] = $params['excludedType'];
		}
		
		if ( ! empty( $params['languageCode'] ) ) {
			$body['languageCode'] = $params['languageCode'];
		}
		
		// Always include maxResultCount (default to 20 if not specified)
		$body['maxResultCount'] = isset( $params['maxResultCount'] ) ? intval( $params['maxResultCount'] ) : 20;
		
		if ( ! empty( $params['rankPreference'] ) ) {
			$body['rankPreference'] = $params['rankPreference'];
		}
		
		// Add page token for pagination
		if ( ! empty( $page_token ) ) {
			$body['pageToken'] = $page_token;
		}
		
		return $this->make_request( $endpoint, $body );
	}
	
	/**
	 * Search entire area using grid-based approach
	 *
	 * @param array $params Search parameters including bounding box or center+radius.
	 * @param callable $progress_callback Optional callback for progress updates.
	 * @return array|WP_Error Array of unique places.
	 */
	public function search_area_comprehensive( $params, $progress_callback = null ) {
		$all_places = array();
		$place_ids = array(); // For deduplication
		
		// Determine search area
		if ( isset( $params['bounding_box'] ) ) {
			// Use bounding box
			$bbox = $params['bounding_box'];
			$north = $bbox['north'];
			$south = $bbox['south'];
			$east = $bbox['east'];
			$west = $bbox['west'];
		} elseif ( isset( $params['center'] ) && isset( $params['radius'] ) ) {
			// Convert center+radius to bounding box
			$center_lat = $params['center']['latitude'];
			$center_lng = $params['center']['longitude'];
			$radius_meters = $params['radius'];
			
			// Approximate conversion: 1 degree latitude ≈ 111,000 meters
			// 1 degree longitude ≈ 111,000 * cos(latitude) meters
			$lat_offset = $radius_meters / 111000;
			$lng_offset = $radius_meters / ( 111000 * cos( deg2rad( $center_lat ) ) );
			
			$north = $center_lat + $lat_offset;
			$south = $center_lat - $lat_offset;
			$east = $center_lng + $lng_offset;
			$west = $center_lng - $lng_offset;
		} else {
			return new WP_Error( 'invalid_params', __( 'Invalid area parameters. Provide either bounding_box or center+radius.', 'gptg' ) );
		}
		
		// Calculate grid size based on radius
		// Use a radius that gives ~40-50 results per cell to stay under 60 limit
		$cell_radius = isset( $params['cell_radius'] ) ? intval( $params['cell_radius'] ) : 2000; // 2km default
		
		// Validate bounding box
		if ( $north <= $south || $east <= $west ) {
			return new WP_Error( 'invalid_bbox', __( 'Invalid bounding box coordinates.', 'gptg' ) );
		}
		
		// Calculate grid dimensions
		$lat_range = $north - $south;
		$lng_range = $east - $west;
		
		// Approximate cell size in degrees
		$cell_lat_size = ( $cell_radius * 2 ) / 111000;
		$cell_lng_size = ( $cell_radius * 2 ) / ( 111000 * cos( deg2rad( ( $north + $south ) / 2 ) ) );
		
		// Ensure cell size is reasonable
		if ( $cell_lat_size <= 0 || $cell_lng_size <= 0 ) {
			return new WP_Error( 'invalid_cell_size', __( 'Invalid cell size calculated. Try adjusting the cell radius.', 'gptg' ) );
		}
		
		// Number of cells needed
		$cells_lat = max( 1, ceil( $lat_range / $cell_lat_size ) );
		$cells_lng = max( 1, ceil( $lng_range / $cell_lng_size ) );
		
		$total_cells = $cells_lat * $cells_lng;
		$current_cell = 0;
		$cells_searched = 0;
		$cells_with_results = 0;
		
		// Add slight overlap to avoid missing edge places (10% overlap)
		$overlap = 0.1;
		
		// Search each grid cell
		for ( $i = 0; $i < $cells_lat; $i++ ) {
			for ( $j = 0; $j < $cells_lng; $j++ ) {
				$current_cell++;
				
				// Calculate cell boundaries with overlap
				$cell_south = $south + ( $i * $cell_lat_size * ( 1 - $overlap ) );
				$cell_north = min( $north, $cell_south + $cell_lat_size );
				$cell_west = $west + ( $j * $cell_lng_size * ( 1 - $overlap ) );
				$cell_east = min( $east, $cell_west + $cell_lng_size );
				
				// Center of this cell
				$cell_center_lat = ( $cell_south + $cell_north ) / 2;
				$cell_center_lng = ( $cell_west + $cell_east ) / 2;
				
				// Update progress
				if ( is_callable( $progress_callback ) ) {
					call_user_func( $progress_callback, $current_cell, $total_cells, sprintf(
						__( 'Searching cell %d of %d...', 'gptg' ),
						$current_cell,
						$total_cells
					) );
				}
				
				// Search this cell
				$cell_params = array(
					'locationRestriction' => array(
						'circle' => array(
							'center' => array(
								'latitude' => $cell_center_lat,
								'longitude' => $cell_center_lng,
							),
							'radius' => $cell_radius,
						),
					),
					'maxResultCount' => 60, // Get max results per cell
				);
				
				// Add type filter if specified
				if ( ! empty( $params['includedType'] ) ) {
					$cell_params['includedType'] = $params['includedType'];
				}
				
				// Search with pagination
				$cell_places = array();
				$page_token = '';
				$page_count = 0;
				
				$cells_searched++;
				
				do {
					$page_count++;
					$result = $this->nearby_search( $cell_params, $page_token );
					
					if ( is_wp_error( $result ) ) {
						// Log error but continue with other cells
						error_log( 'GPTG Grid Search Error (Cell ' . $current_cell . ' at ' . $cell_center_lat . ',' . $cell_center_lng . '): ' . $result->get_error_message() );
						// Don't break - continue to next cell
						break;
					}
					
					$places = isset( $result['places'] ) ? $result['places'] : array();
					
					if ( ! empty( $places ) ) {
						$cells_with_results++;
						$cell_places = array_merge( $cell_places, $places );
					}
					
					$page_token = isset( $result['nextPageToken'] ) ? $result['nextPageToken'] : '';
					
					if ( ! empty( $page_token ) && count( $cell_places ) < 60 && $page_count < 3 ) {
						sleep( 2 ); // Wait for token
					}
				} while ( ! empty( $page_token ) && count( $cell_places ) < 60 && $page_count < 3 );
				
				// Deduplicate and add to all places
				foreach ( $cell_places as $place ) {
					$place_id = isset( $place['id'] ) ? $place['id'] : '';
					
					if ( ! empty( $place_id ) && ! isset( $place_ids[ $place_id ] ) ) {
						$place_ids[ $place_id ] = true;
						$all_places[] = $place;
					} elseif ( empty( $place_id ) ) {
						// If no ID, use location as fallback key
						$location_key = '';
						if ( isset( $place['location'] ) ) {
							$loc = $place['location'];
							$location_key = round( $loc['latitude'], 6 ) . ',' . round( $loc['longitude'], 6 );
						}
						
						if ( ! empty( $location_key ) && ! isset( $place_ids[ $location_key ] ) ) {
							$place_ids[ $location_key ] = true;
							$all_places[] = $place;
						} elseif ( empty( $location_key ) ) {
							// Last resort: add anyway
							$all_places[] = $place;
						}
					}
				}
				
				// Small delay between cells to avoid rate limiting
				if ( $current_cell < $total_cells ) {
					usleep( 200000 ); // 0.2 seconds
				}
			}
		}
		
		// Return result even if empty (for debugging)
		$result = array(
			'places' => $all_places,
			'total_cells' => $total_cells,
			'cells_searched' => $cells_searched,
			'cells_with_results' => $cells_with_results,
			'unique_count' => count( $all_places ),
		);
		
		// Log summary for debugging
		error_log( sprintf( 
			'GPTG Comprehensive Search: Total cells: %d, Searched: %d, With results: %d, Unique places: %d',
			$total_cells,
			$cells_searched,
			$cells_with_results,
			count( $all_places )
		) );
		
		return $result;
	}
	
	/**
	 * Get place details
	 *
	 * @param string $place_id Place ID.
	 * @param array  $params Additional parameters.
	 * @return array|WP_Error
	 */
	public function get_place_details( $place_id, $params = array(), $field_groups = null ) {
		$place_id = trim( (string) $place_id );
		$endpoint = ( strpos( $place_id, 'places/' ) === 0 )
			? $place_id
			: 'places/' . $place_id;
		
		$defaults = array(
			'languageCode' => 'en',
		);
		
		$params = wp_parse_args( $params, $defaults );
		
		$query_params = array();
		if ( ! empty( $params['languageCode'] ) ) {
			$query_params['languageCode'] = $params['languageCode'];
		}
		
		$url = $this->api_base_url . $endpoint;
		if ( ! empty( $query_params ) ) {
			$url .= '?' . http_build_query( $query_params );
		}
		
		$field_mask = null === $field_groups ? '' : $this->build_details_field_mask( $field_groups );
		return $this->make_get_request( $url, $field_mask );
	}

	/**
	 * Fetch photo media bytes/URL from Place Photos API.
	 *
	 * @param string $photo_name Photo resource name from places.photos[].name.
	 * @param int    $max_width  Max width in pixels.
	 * @return array|WP_Error { url: string } or binary redirect URL.
	 */
	public function get_place_photo_url( $photo_name, $max_width = 800 ) {
		if ( empty( $this->api_key ) ) {
			return new WP_Error( 'no_api_key', __( 'Google Places API key is not set.', 'gptg' ) );
		}

		$photo_name = trim( (string) $photo_name );
		if ( strpos( $photo_name, 'places/' ) !== 0 ) {
			$photo_name = 'places/' . ltrim( $photo_name, '/' );
		}

		$url = $this->api_base_url . $photo_name . '/media';
		$url = add_query_arg(
			array(
				'maxWidthPx' => max( 1, min( 4800, (int) $max_width ) ),
				'key'        => $this->api_key,
			),
			$url
		);

		return array( 'url' => $url );
	}

	/**
	 * Field groups available for Place Details requests.
	 *
	 * @return array
	 */
	public static function get_details_field_groups() {
		return array(
			'basic'    => 'id,displayName,formattedAddress,addressComponents,location,types,primaryType,businessStatus,priceLevel',
			'contact'  => 'nationalPhoneNumber,internationalPhoneNumber,websiteUri,googleMapsUri',
			'hours'    => 'regularOpeningHours,currentOpeningHours,utcOffsetMinutes',
			'content'  => 'editorialSummary,generativeSummary,rating,userRatingCount',
			'photos'   => 'photos',
			'reviews'  => 'reviews,reviewSummary',
			'extra'    => 'accessibilityOptions,paymentOptions,parkingOptions,googleMapsLinks',
		);
	}

	/**
	 * Build field mask from enabled groups (option or argument).
	 *
	 * @param array|null $groups Group keys or null to use saved option.
	 * @return string
	 */
	public function build_details_field_mask( $groups = null ) {
		if ( null === $groups ) {
			$groups = get_option( 'gptg_details_field_groups', array( 'basic', 'contact', 'hours', 'content', 'photos' ) );
		}
		if ( ! is_array( $groups ) ) {
			$groups = array( 'basic', 'contact', 'hours', 'content' );
		}

		$all_groups = self::get_details_field_groups();
		$fields     = array();
		foreach ( $groups as $group ) {
			if ( isset( $all_groups[ $group ] ) ) {
				$fields = array_merge( $fields, explode( ',', $all_groups[ $group ] ) );
			}
		}
		$fields = array_unique( array_filter( $fields ) );
		return implode( ',', $fields );
	}

	/**
	 * Convert Google opening hours to GeoDirectory business_hours schema string.
	 *
	 * @param array $hours regularOpeningHours or currentOpeningHours from API.
	 * @return string
	 */
	public function format_hours_for_geodirectory( $hours ) {
		if ( empty( $hours ) || ! is_array( $hours ) ) {
			return '';
		}

		if ( function_exists( 'geodir_array_to_schema' ) && ! empty( $hours['periods'] ) && is_array( $hours['periods'] ) ) {
			$google_to_geodir_day = array( 0 => 7, 1 => 1, 2 => 2, 3 => 3, 4 => 4, 5 => 5, 6 => 6 );
			$schema_hours         = array();

			foreach ( $hours['periods'] as $period ) {
				if ( empty( $period['open'] ) ) {
					continue;
				}
				$g_day = isset( $period['open']['day'] ) ? (int) $period['open']['day'] : -1;
				$day_no = isset( $google_to_geodir_day[ $g_day ] ) ? $google_to_geodir_day[ $g_day ] : 0;
				if ( ! $day_no ) {
					continue;
				}
				$opens  = sprintf( '%02d:%02d', (int) $period['open']['hour'], (int) $period['open']['minute'] );
				$closes = '17:00';
				if ( ! empty( $period['close'] ) ) {
					$closes = sprintf( '%02d:%02d', (int) $period['close']['hour'], (int) $period['close']['minute'] );
				}
				if ( ! isset( $schema_hours[ $day_no ] ) ) {
					$schema_hours[ $day_no ] = array();
				}
				$schema_hours[ $day_no ][] = array( 'opens' => $opens, 'closes' => $closes );
			}

			if ( ! empty( $schema_hours ) ) {
				$offset = function_exists( 'geodir_gmt_offset' ) ? geodir_gmt_offset() : '+0';
				if ( isset( $hours['utcOffsetMinutes'] ) && function_exists( 'geodir_timezone_utc_offset' ) ) {
					$offset = geodir_timezone_utc_offset( '', false );
				}
				$tz = function_exists( 'geodir_timezone_string' ) ? geodir_timezone_string() : wp_timezone_string();
				return geodir_array_to_schema(
					array(
						'hours'           => $schema_hours,
						'offset'          => $offset,
						'timezone_string' => $tz,
					)
				);
			}
		}

		return $this->format_opening_hours_text( $hours );
	}

	/**
	 * Strip Google-appended suffixes so only the business name remains.
	 *
	 * @param string $text Raw display name from Google.
	 * @return string
	 */
	public static function sanitize_display_name( $text ) {
		$text = trim( preg_replace( '/\s+/u', ' ', (string) $text ) );
		if ( '' === $text ) {
			return '';
		}

		$text = preg_replace( '/\s*\([^)]+\)\s*$/u', '', $text );
		$text = trim( $text );

		$separators = array( ' · ', ' | ', ' – ', ' — ', ' - ' );
		foreach ( $separators as $sep ) {
			$pos = strpos( $text, $sep );
			if ( false !== $pos && $pos > 0 ) {
				$text = trim( substr( $text, 0, $pos ) );
				break;
			}
		}

		return trim( $text );
	}

	/**
	 * Get display name text from place array.
	 *
	 * @param array $place Place data.
	 * @return string
	 */
	public static function get_place_name( $place ) {
		if ( empty( $place['displayName'] ) ) {
			return '';
		}
		$name = '';
		if ( is_string( $place['displayName'] ) ) {
			$name = $place['displayName'];
		} elseif ( isset( $place['displayName']['text'] ) ) {
			$name = $place['displayName']['text'];
		}

		return self::sanitize_display_name( $name );
	}
	
	/**
	 * Get field mask for search requests
	 *
	 * @param bool $all_fields If true, returns '*' for all fields (for testing).
	 * @return string
	 */
	private function get_search_field_mask( $all_fields = false ) {
		if ( $all_fields ) {
			return '*';
		}
		return 'places.id,places.displayName,places.formattedAddress,places.location,places.rating,places.userRatingCount,places.nationalPhoneNumber,places.internationalPhoneNumber,places.websiteUri,places.types,places.priceLevel,places.businessStatus';
	}
	
	/**
	 * Get field mask for place details
	 *
	 * @return string
	 */
	private function get_details_field_mask() {
		return $this->build_details_field_mask();
	}
	
	/**
	 * Make POST request to Google Places API
	 *
	 * @param string $endpoint API endpoint.
	 * @param array  $body Request body.
	 * @param string $field_mask Optional field mask. If not provided, uses search field mask.
	 * @return array|WP_Error
	 */
	private function make_request( $endpoint, $body, $field_mask = '' ) {
		if ( empty( $this->api_key ) ) {
			return new WP_Error( 'no_api_key', __( 'Google Places API key is not set.', 'gptg' ) );
		}
		
		$url = $this->api_base_url . $endpoint;
		
		// Use provided field mask or default to search field mask
		if ( empty( $field_mask ) ) {
			$field_mask = $this->get_search_field_mask();
		}
		
		$args = array(
			'method'  => 'POST',
			'headers' => array(
				'Content-Type'     => 'application/json',
				'X-Goog-Api-Key'  => $this->api_key,
				'X-Goog-FieldMask' => $field_mask,
			),
			'body'    => wp_json_encode( $body ),
			'timeout' => 30,
		);
		
		$response = wp_remote_request( $url, $args );
		
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		
		$response_code = wp_remote_retrieve_response_code( $response );
		$response_body = wp_remote_retrieve_body( $response );
		
		if ( $response_code !== 200 ) {
			$error_data = json_decode( $response_body, true );
			$error_message = isset( $error_data['error']['message'] ) ? $error_data['error']['message'] : __( 'Unknown error occurred.', 'gptg' );
			return new WP_Error( 'api_error', $error_message, array( 'status' => $response_code ) );
		}

		$decoded = json_decode( $response_body, true );

		if ( ! is_array( $decoded ) ) {
			return new WP_Error( 'api_error', __( 'Invalid response from Google Places API.', 'gptg' ) );
		}

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			$place_count = isset( $decoded['places'] ) && is_array( $decoded['places'] ) ? count( $decoded['places'] ) : 0;
			error_log( sprintf( 'GPTG API POST %s: HTTP %d, places: %d', $endpoint, $response_code, $place_count ) );
		}

		return $decoded;
	}
	
	/**
	 * Make GET request to Google Places API
	 *
	 * @param string $url Full API URL.
	 * @return array|WP_Error
	 */
	private function make_get_request( $url, $field_mask = '' ) {
		if ( empty( $this->api_key ) ) {
			return new WP_Error( 'no_api_key', __( 'Google Places API key is not set.', 'gptg' ) );
		}
		
		// Add API key to URL
		$url = add_query_arg( 'key', $this->api_key, $url );
		
		if ( empty( $field_mask ) ) {
			$field_mask = $this->get_details_field_mask();
		}
		
		$args = array(
			'method'  => 'GET',
			'headers' => array(
				'X-Goog-FieldMask' => $field_mask,
			),
			'timeout' => 30,
		);
		
		$response = wp_remote_request( $url, $args );
		
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		
		$response_code = wp_remote_retrieve_response_code( $response );
		$response_body = wp_remote_retrieve_body( $response );
		
		if ( $response_code !== 200 ) {
			$error_data = json_decode( $response_body, true );
			$error_message = isset( $error_data['error']['message'] ) ? $error_data['error']['message'] : __( 'Unknown error occurred.', 'gptg' );
			return new WP_Error( 'api_error', $error_message, array( 'status' => $response_code ) );
		}
		
		return json_decode( $response_body, true );
	}
	
	/**
	 * Format place data for GeoDirectory
	 *
	 * @param array $place_data Place data from Google Places API.
	 * @return array Formatted data.
	 */
	public function format_for_geodirectory( $place_data ) {
		$formatted = array();
		
		// Basic information
		$formatted['post_title'] = isset( $place_data['displayName']['text'] ) ? $place_data['displayName']['text'] : '';
		$formatted['post_content'] = isset( $place_data['editorialSummary']['text'] ) ? $place_data['editorialSummary']['text'] : '';
		
		// Location data
		if ( isset( $place_data['location'] ) ) {
			$formatted['post_latitude'] = isset( $place_data['location']['latitude'] ) ? $place_data['location']['latitude'] : '';
			$formatted['post_longitude'] = isset( $place_data['location']['longitude'] ) ? $place_data['location']['longitude'] : '';
		}
		
		// Address
		if ( isset( $place_data['formattedAddress'] ) ) {
			$formatted['post_address'] = $place_data['formattedAddress'];
		} elseif ( isset( $place_data['addressComponents'] ) ) {
			$formatted['post_address'] = $this->format_address( $place_data['addressComponents'] );
		}
		
		// Phone
		if ( isset( $place_data['nationalPhoneNumber'] ) ) {
			$formatted['post_phone'] = $place_data['nationalPhoneNumber'];
		} elseif ( isset( $place_data['internationalPhoneNumber'] ) ) {
			$formatted['post_phone'] = $place_data['internationalPhoneNumber'];
		}
		
		// Website
		if ( isset( $place_data['websiteUri'] ) ) {
			$formatted['post_website'] = $place_data['websiteUri'];
		}
		
		// Rating
		if ( isset( $place_data['rating'] ) ) {
			$formatted['post_rating'] = $place_data['rating'];
		}
		
		// User rating count
		if ( isset( $place_data['userRatingCount'] ) ) {
			$formatted['post_rating_count'] = $place_data['userRatingCount'];
		}
		
		// Business status
		if ( isset( $place_data['businessStatus'] ) ) {
			$formatted['post_business_status'] = $place_data['businessStatus'];
		}
		
		// Types (categories)
		if ( isset( $place_data['types'] ) && is_array( $place_data['types'] ) ) {
			$formatted['post_categories'] = implode( ',', $place_data['types'] );
		}
		
		// Place ID
		if ( isset( $place_data['id'] ) ) {
			$formatted['google_place_id'] = $place_data['id'];
		}
		
		// Price level
		if ( isset( $place_data['priceLevel'] ) ) {
			$formatted['post_price_level'] = $place_data['priceLevel'];
		}
		
		// Opening hours
		if ( isset( $place_data['currentOpeningHours'] ) ) {
			$formatted['post_hours'] = $this->format_opening_hours( $place_data['currentOpeningHours'] );
		}
		
		return $formatted;
	}
	
	/**
	 * Format address from address components
	 *
	 * @param array $components Address components.
	 * @return string
	 */
	private function format_address( $components ) {
		$address_parts = array();
		
		foreach ( $components as $component ) {
			if ( isset( $component['longText'] ) ) {
				$address_parts[] = $component['longText'];
			}
		}
		
		return implode( ', ', $address_parts );
	}
	
	/**
	 * Format opening hours
	 *
	 * @param array $hours Opening hours data.
	 * @return string
	 */
	private function format_opening_hours( $hours ) {
		return $this->format_opening_hours_text( $hours );
	}

	/**
	 * Plain-text hours for display fallback.
	 *
	 * @param array $hours Hours data.
	 * @return string
	 */
	public function format_opening_hours_text( $hours ) {
		if ( ! isset( $hours['weekdayDescriptions'] ) || ! is_array( $hours['weekdayDescriptions'] ) ) {
			return '';
		}
		return implode( "\n", $hours['weekdayDescriptions'] );
	}
}

