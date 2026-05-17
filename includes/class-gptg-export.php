<?php
/**
 * Export Functionality
 *
 * @package GPTG
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Export Class
 */
class GPTG_Export {
	
	/**
	 * Constructor
	 */
	public function __construct() {
		// Export actions are handled via AJAX
	}
	
	/**
	 * Export places to CSV
	 *
	 * @param array  $places Places data.
	 * @param string $post_type GeoDirectory post type.
	 * @return void
	 */
	public function export_to_csv( $places, $post_type = 'gd_place' ) {
		$filename = 'geodirectory-import-' . date( 'Y-m-d-H-i-s' ) . '.csv';
		
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=' . $filename );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );
		
		$output = fopen( 'php://output', 'w' );
		
		// Get CSV headers based on GeoDirectory format
		$headers = $this->get_csv_headers( $post_type );
		fputcsv( $output, $headers );
		
		// Add data rows
		foreach ( $places as $place ) {
			$row = $this->format_place_for_csv( $place, $post_type, $headers );
			fputcsv( $output, $row );
		}
		
		fclose( $output );
		exit;
	}
	
	/**
	 * Export places to JSON
	 *
	 * @param array  $places Places data.
	 * @param string $post_type GeoDirectory post type.
	 * @return void
	 */
	public function export_to_json( $places, $post_type = 'gd_place' ) {
		$filename = 'geodirectory-import-' . date( 'Y-m-d-H-i-s' ) . '.json';
		
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=' . $filename );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );
		
		$formatted_places = array();
		foreach ( $places as $place ) {
			$formatted_places[] = $this->format_place_for_geodirectory( $place, $post_type );
		}
		
		echo wp_json_encode( $formatted_places, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );
		exit;
	}
	
	/**
	 * Get CSV headers for GeoDirectory import
	 *
	 * @param string $post_type Post type.
	 * @return array
	 */
	private function get_csv_headers( $post_type ) {
		// Base WordPress fields
		$headers = array(
			'post_title',
			'post_content',
			'post_status',
			'post_author',
			'post_date',
		);
		
		// GeoDirectory specific fields
		$geodir_fields = array(
			'post_address',
			'post_city',
			'post_region',
			'post_country',
			'post_zip',
			'post_latitude',
			'post_longitude',
			'post_mapview',
			'post_mapzoom',
			'post_category',
			'post_tags',
			'geodir_timing',
			'geodir_contact',
			'geodir_email',
			'geodir_website',
			'geodir_twitter',
			'geodir_facebook',
			'geodir_video',
			'geodir_special_offers',
			'geodir_special_offers',
			'featured',
			'post_images',
		);
		
		// Add custom fields if GeoDirectory is active
		if ( function_exists( 'geodir_get_custom_fields' ) ) {
			$custom_fields = geodir_get_custom_fields( $post_type );
			if ( ! empty( $custom_fields ) ) {
				foreach ( $custom_fields as $field ) {
					if ( isset( $field['htmlvar_name'] ) ) {
						$headers[] = $field['htmlvar_name'];
					}
				}
			}
		}
		
		return array_merge( $headers, $geodir_fields );
	}
	
	/**
	 * Format place data for CSV export
	 *
	 * @param array  $place Place data.
	 * @param string $post_type Post type.
	 * @param array  $headers CSV headers.
	 * @return array
	 */
	private function format_place_for_csv( $place, $post_type, $headers ) {
		$row = array();
		
		// Map place data to headers
		$place_data = $this->format_place_for_geodirectory( $place, $post_type );
		
		foreach ( $headers as $header ) {
			$row[ $header ] = isset( $place_data[ $header ] ) ? $place_data[ $header ] : '';
		}
		
		return $row;
	}
	
	/**
	 * Format place data for GeoDirectory
	 *
	 * @param array  $place Place data from Google Places API.
	 * @param string $post_type Post type.
	 * @return array
	 */
	public function format_place_for_geodirectory( $place, $post_type = 'gd_place' ) {
		$formatted = array();
		
		// Basic WordPress fields
		$formatted['post_title'] = GPTG_Google_Places_API::get_place_name( $place );
		$formatted['post_content'] = isset( $place['editorialSummary']['text'] ) ? $place['editorialSummary']['text'] : '';
		$formatted['post_status'] = 'publish';
		$formatted['post_author'] = get_current_user_id();
		$formatted['post_date'] = current_time( 'mysql' );
		
		// Location data
		if ( isset( $place['location'] ) ) {
			$formatted['post_latitude'] = isset( $place['location']['latitude'] ) ? (string) $place['location']['latitude'] : '';
			$formatted['post_longitude'] = isset( $place['location']['longitude'] ) ? (string) $place['location']['longitude'] : '';
		}
		
		// Address parsing
		if ( isset( $place['formattedAddress'] ) ) {
			$formatted['post_address'] = $place['formattedAddress'];
		}
		
		// Parse address components
		if ( isset( $place['addressComponents'] ) && is_array( $place['addressComponents'] ) ) {
			foreach ( $place['addressComponents'] as $component ) {
				$types = isset( $component['types'] ) ? $component['types'] : array();
				$long_text = isset( $component['longText'] ) ? $component['longText'] : '';
				
				if ( in_array( 'locality', $types, true ) ) {
					$formatted['post_city'] = $long_text;
				}
				if ( in_array( 'administrative_area_level_1', $types, true ) ) {
					$formatted['post_region'] = $long_text;
				}
				if ( in_array( 'country', $types, true ) ) {
					$formatted['post_country'] = $long_text;
				}
				if ( in_array( 'postal_code', $types, true ) ) {
					$formatted['post_zip'] = $long_text;
				}
			}
		}
		
		// Phone
		if ( isset( $place['nationalPhoneNumber'] ) ) {
			$formatted['geodir_contact'] = $place['nationalPhoneNumber'];
		} elseif ( isset( $place['internationalPhoneNumber'] ) ) {
			$formatted['geodir_contact'] = $place['internationalPhoneNumber'];
		}
		
		// Website
		if ( isset( $place['websiteUri'] ) ) {
			$formatted['geodir_website'] = $place['websiteUri'];
		}

		if ( ! empty( $place['gptgContact'] ) && is_array( $place['gptgContact'] ) ) {
			$contact_map = array(
				'email'     => 'geodir_email',
				'facebook'  => 'geodir_facebook',
				'twitter'   => 'geodir_twitter',
				'instagram' => 'instagram',
				'linkedin'  => 'linkedin',
			);
			foreach ( $contact_map as $key => $header ) {
				if ( ! empty( $place['gptgContact'][ $key ] ) ) {
					$formatted[ $header ] = $place['gptgContact'][ $key ];
				}
			}
		}
		
		// Rating
		if ( isset( $place['rating'] ) ) {
			$formatted['overall_rating'] = (string) $place['rating'];
		}
		
		// User rating count
		if ( isset( $place['userRatingCount'] ) ) {
			$formatted['rating_count'] = (string) $place['userRatingCount'];
		}
		
		// Types (categories) - map to GeoDirectory categories
		if ( isset( $place['types'] ) && is_array( $place['types'] ) ) {
			$categories = self::map_google_types_to_geodir_categories( $place['types'], $post_type );
			if ( ! empty( $categories ) ) {
				$formatted['post_category'] = implode( ',', $categories );
			}
		}
		
		// Opening hours
		if ( isset( $place['currentOpeningHours'] ) ) {
			$formatted['geodir_timing'] = $this->format_opening_hours( $place['currentOpeningHours'] );
		}
		
		// Price level
		if ( isset( $place['priceLevel'] ) ) {
			$formatted['price_level'] = $place['priceLevel'];
		}
		
		// Place ID (for reference)
		if ( isset( $place['id'] ) ) {
			$formatted['google_place_id'] = $place['id'];
		}
		
		// Default values
		$formatted['post_mapview'] = 'ROADMAP';
		$formatted['post_mapzoom'] = '12';
		$formatted['featured'] = '0';
		
		return $formatted;
	}
	
	/**
	 * Map Google Place types to GeoDirectory categories
	 *
	 * @param array  $google_types Google Place types.
	 * @param string $post_type Post type.
	 * @return array
	 */
	public static function map_google_types_to_geodir_categories( $google_types, $post_type ) {
		// This is a basic mapping - you may need to adjust based on your GeoDirectory setup
		$category_map = array(
			'restaurant' => 'Restaurant',
			'food' => 'Restaurant',
			'cafe' => 'Cafe',
			'bar' => 'Bar',
			'lodging' => 'Hotel',
			'hotel' => 'Hotel',
			'gas_station' => 'Gas Station',
			'store' => 'Store',
			'gift_shop' => 'Gift Shop',
			'souvenir_shop' => 'Souvenir Shop',
			'clothing_store' => 'Clothing Store',
			'tourist_attraction' => 'Attraction',
			'shopping_mall' => 'Shopping',
			'pharmacy' => 'Pharmacy',
			'bank' => 'Bank',
			'hospital' => 'Hospital',
			'school' => 'School',
			'gym' => 'Gym',
			'park' => 'Park',
		);
		
		$categories = array();
		
		foreach ( $google_types as $type ) {
			if ( isset( $category_map[ $type ] ) ) {
				$categories[] = $category_map[ $type ];
			}
		}
		
		// If no mapping found, use the first type
		if ( empty( $categories ) && ! empty( $google_types ) ) {
			$categories[] = ucwords( str_replace( '_', ' ', $google_types[0] ) );
		}
		
		return array_unique( $categories );
	}
	
	/**
	 * Format opening hours
	 *
	 * @param array $hours Opening hours data.
	 * @return string
	 */
	private function format_opening_hours( $hours ) {
		if ( ! isset( $hours['weekdayDescriptions'] ) || ! is_array( $hours['weekdayDescriptions'] ) ) {
			return '';
		}
		
		return implode( "\n", $hours['weekdayDescriptions'] );
	}
}

