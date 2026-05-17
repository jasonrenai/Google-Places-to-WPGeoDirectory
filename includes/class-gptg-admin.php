<?php
/**
 * Admin Settings Page
 *
 * @package GPTG
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin Class
 */
class GPTG_Admin {
	
	/**
	 * Constructor
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_scripts' ) );
	}
	
	/**
	 * Add admin menu
	 */
	public function add_admin_menu() {
		add_menu_page(
			__( 'Google Places to GeoDirectory', 'gptg' ),
			__( 'GP to GeoDir', 'gptg' ),
			'manage_options',
			'gptg-settings',
			array( $this, 'render_settings_page' ),
			'dashicons-location',
			30
		);
	}
	
	/**
	 * Register settings
	 */
	public function register_settings() {
		register_setting( 'gptg_api_settings', 'gptg_api_key' );
		register_setting( 'gptg_import_settings', 'gptg_import_post_type' );
		register_setting( 'gptg_import_settings', 'gptg_import_post_status' );
		register_setting( 'gptg_import_settings', 'gptg_duplicate_behavior' );
		register_setting( 'gptg_import_settings', 'gptg_import_photo_limit' );
		register_setting( 'gptg_import_settings', 'gptg_import_images' );
		register_setting( 'gptg_import_settings', 'gptg_import_featured_first' );
		register_setting( 'gptg_import_settings', 'gptg_details_field_groups', array(
			'type'              => 'array',
			'sanitize_callback' => array( $this, 'sanitize_field_groups' ),
		) );
		register_setting( 'gptg_ai_settings', 'gptg_ai_provider' );
		register_setting( 'gptg_ai_settings', 'gptg_openai_api_key' );
		register_setting( 'gptg_ai_settings', 'gptg_openai_model' );
		register_setting( 'gptg_ai_settings', 'gptg_anthropic_api_key' );
		register_setting( 'gptg_ai_settings', 'gptg_anthropic_model' );
		register_setting( 'gptg_ai_settings', 'gptg_ai_generate_description' );
		register_setting( 'gptg_ai_settings', 'gptg_ai_prefer_description' );
		register_setting( 'gptg_ai_settings', 'gptg_description_fetch_website' );
		register_setting( 'gptg_contact_settings', 'gptg_contact_enabled' );
		register_setting( 'gptg_contact_settings', 'gptg_contact_provider' );
		register_setting( 'gptg_contact_settings', 'gptg_hunter_api_key' );
		register_setting( 'gptg_contact_settings', 'gptg_apollo_api_key' );
	}

	/**
	 * Sanitize details field group checkboxes.
	 *
	 * @param array $input Input.
	 * @return array
	 */
	public function sanitize_field_groups( $input ) {
		if ( ! is_array( $input ) ) {
			return array( 'basic', 'contact', 'hours', 'content', 'photos' );
		}
		$valid = array_keys( GPTG_Google_Places_API::get_details_field_groups() );
		return array_values( array_intersect( $input, $valid ) );
	}
	
	/**
	 * Enqueue admin scripts and styles
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_admin_scripts( $hook ) {
		if ( 'toplevel_page_gptg-settings' !== $hook ) {
			return;
		}
		
		wp_enqueue_style(
			'gptg-admin',
			GPTG_PLUGIN_URL . 'assets/css/admin.css',
			array(),
			GPTG_VERSION
		);
		
		// Get Google Maps API key (use the same one as Places API)
		$maps_api_key     = get_option( 'gptg_api_key', '' );
		$import_post_type = get_option( 'gptg_import_post_type', 'gd_place' );

		// Enqueue Google Maps JavaScript API
		if ( ! empty( $maps_api_key ) ) {
			wp_enqueue_script(
				'google-maps',
				'https://maps.googleapis.com/maps/api/js?key=' . esc_attr( $maps_api_key ) . '&libraries=places',
				array(),
				null,
				false
			);
		}
		
		wp_enqueue_script(
			'gptg-admin',
			GPTG_PLUGIN_URL . 'assets/js/admin.js',
			array( 'jquery' ),
			GPTG_VERSION,
			true
		);
		
		wp_localize_script(
			'gptg-admin',
			'gptgAdmin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'gptg-admin-nonce' ),
				'mapsApiKey' => $maps_api_key,
				'importPostType' => $import_post_type,
				'importPostStatus' => get_option( 'gptg_import_post_status', 'draft' ),
				'contactEnabled' => GPTG_Contact_Enricher::is_enabled(),
				'contactProvider' => GPTG_Contact_Enricher::get_provider_id(),
				'importImagesEnabled' => '1' === get_option( 'gptg_import_images', '1' ),
				'importFeaturedFirst' => '1' === get_option( 'gptg_import_featured_first', '1' ),
				'importPhotoLimit' => (int) get_option( 'gptg_import_photo_limit', 5 ),
				'importTabUrl' => admin_url( 'admin.php?page=gptg-settings&tab=import#gptg-images-settings' ),
				'i18n'    => array(
					'searching' => __( 'Searching...', 'gptg' ),
					'error'     => __( 'An error occurred. Please try again.', 'gptg' ),
					'noResults' => __( 'No results found.', 'gptg' ),
					'fetchingPages' => __( 'Fetching additional pages...', 'gptg' ),
					'loadingDetails' => __( 'Loading details...', 'gptg' ),
					'importing' => __( 'Importing...', 'gptg' ),
					'importSuccess' => __( 'Imported successfully.', 'gptg' ),
					'viewListing' => __( 'View listing', 'gptg' ),
					'editListing' => __( 'Edit listing', 'gptg' ),
					'noEmailNote' => __( 'Email and social profiles may be filled via Hunter or Apollo when a website is known.', 'gptg' ),
					'enrichingContact' => __( 'Looking up email and social profiles...', 'gptg' ),
					'contactNoWebsite' => __( 'A website is required to look up email and social profiles.', 'gptg' ),
					'contactNotFound' => __( 'No contact data found for this domain.', 'gptg' ),
					'retryContact' => __( 'Retry lookup', 'gptg' ),
					'testHunter' => __( 'Test Hunter', 'gptg' ),
					'testingHunter' => __( 'Testing Hunter...', 'gptg' ),
					'enrichingContacts' => __( 'Enriching contacts', 'gptg' ),
					'of' => __( 'of', 'gptg' ),
					'sortNameAsc' => __( 'Name A–Z', 'gptg' ),
					'sortNameDesc' => __( 'Name Z–A', 'gptg' ),
					'sortRatingDesc' => __( 'Rating (high to low)', 'gptg' ),
					'sortReviewsDesc' => __( 'Reviews (high to low)', 'gptg' ),
					'filterHasWebsite' => __( 'Has website', 'gptg' ),
					'filterHasEmail' => __( 'Has email', 'gptg' ),
					'filterHasPhone' => __( 'Has phone', 'gptg' ),
					'filterHasFacebook' => __( 'Has Facebook', 'gptg' ),
					'filterHasTwitter' => __( 'Has X/Twitter', 'gptg' ),
					'filterHasInstagram' => __( 'Has Instagram', 'gptg' ),
					'filterHasLinkedin' => __( 'Has LinkedIn', 'gptg' ),
					'filterMinRating' => __( 'Min rating', 'gptg' ),
					'applyFilters' => __( 'Apply', 'gptg' ),
					'clearFilters' => __( 'Clear filters', 'gptg' ),
					'noCategoriesMatched' => __( 'No categories matched', 'gptg' ),
					'categoriesLabel' => __( 'Categories', 'gptg' ),
					'tagsLabel' => __( 'Tags', 'gptg' ),
					'imageSettingsNotice' => __( 'Photo import options are on the Import tab under Images.', 'gptg' ),
					'imageSettingsLink' => __( 'Open image settings', 'gptg' ),
				),
			)
		);
	}
	
	/**
	 * Render settings page
	 */
	public function render_settings_page() {
		$active_tab = isset( $_GET['tab'] ) ? sanitize_text_field( $_GET['tab'] ) : 'api';
		?>
		<div class="wrap gptg-admin-wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
			
			<nav class="nav-tab-wrapper">
				<a href="?page=gptg-settings&tab=api" class="nav-tab <?php echo 'api' === $active_tab ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'API Settings', 'gptg' ); ?>
				</a>
				<a href="?page=gptg-settings&tab=search" class="nav-tab <?php echo 'search' === $active_tab ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Search', 'gptg' ); ?>
				</a>
				<a href="?page=gptg-settings&tab=import" class="nav-tab <?php echo 'import' === $active_tab ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Import', 'gptg' ); ?>
				</a>
				<a href="?page=gptg-settings&tab=ai" class="nav-tab <?php echo 'ai' === $active_tab ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'AI Tagging', 'gptg' ); ?>
				</a>
				<a href="?page=gptg-settings&tab=contact" class="nav-tab <?php echo 'contact' === $active_tab ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Contact Enrichment', 'gptg' ); ?>
				</a>
				<a href="?page=gptg-settings&tab=export" class="nav-tab <?php echo 'export' === $active_tab ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Export', 'gptg' ); ?>
				</a>
			</nav>
			
			<div class="gptg-tab-content">
				<?php
				switch ( $active_tab ) {
					case 'api':
						$this->render_api_tab();
						break;
					case 'search':
						$this->render_search_tab();
						break;
					case 'import':
						$this->render_import_tab();
						break;
					case 'ai':
						$this->render_ai_tab();
						break;
					case 'contact':
						$this->render_contact_tab();
						break;
					case 'export':
						$this->render_export_tab();
						break;
				}
				?>
			</div>
			<?php $this->render_place_modal(); ?>
		</div>
		<?php
	}
	
	/**
	 * Render API Settings tab
	 */
	private function render_api_tab() {
		$api_key = get_option( 'gptg_api_key', '' );
		?>
		<form method="post" action="options.php" class="gptg-settings-form">
			<?php settings_fields( 'gptg_api_settings' ); ?>
			
			<table class="form-table">
				<tbody>
					<tr>
						<th scope="row">
							<label for="gptg_api_key"><?php esc_html_e( 'Google Places API Key', 'gptg' ); ?></label>
						</th>
						<td>
							<input 
								type="text" 
								id="gptg_api_key" 
								name="gptg_api_key" 
								value="<?php echo esc_attr( $api_key ); ?>" 
								class="regular-text"
								placeholder="<?php esc_attr_e( 'Enter your Google Places API key', 'gptg' ); ?>"
							/>
							<p class="description">
								<?php esc_html_e( 'Get your API key from', 'gptg' ); ?>
								<a href="https://console.cloud.google.com/google/maps-apis/credentials" target="_blank">
									<?php esc_html_e( 'Google Cloud Console', 'gptg' ); ?>
								</a>
							</p>
							<p class="description">
								<?php esc_html_e( 'Make sure to enable the Places API (New) in your Google Cloud project.', 'gptg' ); ?>
							</p>
						</td>
					</tr>
				</tbody>
			</table>
			
			<?php submit_button(); ?>
		</form>
		
		<div class="gptg-api-test">
			<h2><?php esc_html_e( 'Test API Connection', 'gptg' ); ?></h2>
			<button type="button" class="button" id="gptg-test-api">
				<?php esc_html_e( 'Test Connection', 'gptg' ); ?>
			</button>
			<div id="gptg-api-test-result"></div>
		</div>
		<?php
	}
	
	/**
	 * Render Search tab
	 */
	private function render_search_tab() {
		?>
		<div class="gptg-search-container">
			<h2><?php esc_html_e( 'Search Google Places', 'gptg' ); ?></h2>
			
			<form id="gptg-search-form" class="gptg-search-form" novalidate>
				<div class="gptg-form-section">
					<h3><?php esc_html_e( 'Search Method', 'gptg' ); ?></h3>
					<label>
						<input type="radio" name="search_method" value="text" checked />
						<?php esc_html_e( 'Text Search', 'gptg' ); ?>
					</label>
					<label>
						<input type="radio" name="search_method" value="nearby" />
						<?php esc_html_e( 'Nearby Search', 'gptg' ); ?>
					</label>
					<label>
						<input type="radio" name="search_method" value="comprehensive" />
						<?php esc_html_e( 'Comprehensive Area Search', 'gptg' ); ?>
						<span class="description"><?php esc_html_e( '(Grid-based search to get ALL places in an area)', 'gptg' ); ?></span>
					</label>
				</div>
				
				<div class="gptg-form-section" id="text-search-options">
					<h3><?php esc_html_e( 'Text Search Options', 'gptg' ); ?></h3>
					
					<table class="form-table">
						<tbody>
							<tr>
								<th scope="row">
									<label for="text_query"><?php esc_html_e( 'Search Query', 'gptg' ); ?></label>
								</th>
								<td>
									<input type="text" id="text_query" name="text_query" class="regular-text" placeholder="<?php esc_attr_e( 'e.g., restaurants in New York', 'gptg' ); ?>" />
									<p class="description"><?php esc_html_e( 'Enter keywords to search for places', 'gptg' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row">
									<label for="region_code"><?php esc_html_e( 'Region Code', 'gptg' ); ?></label>
								</th>
								<td>
									<input type="text" id="region_code" name="region_code" class="regular-text" placeholder="<?php esc_attr_e( 'e.g., US, GB', 'gptg' ); ?>" />
									<p class="description"><?php esc_html_e( 'ISO 3166-1 Alpha-2 country code (optional)', 'gptg' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row">
									<label for="included_type"><?php esc_html_e( 'Included Type', 'gptg' ); ?></label>
								</th>
								<td>
									<select id="included_type" name="included_type" class="regular-text">
										<option value=""><?php esc_html_e( 'Any', 'gptg' ); ?></option>
										<option value="restaurant"><?php esc_html_e( 'Restaurant', 'gptg' ); ?></option>
										<option value="lodging"><?php esc_html_e( 'Lodging', 'gptg' ); ?></option>
										<option value="gas_station"><?php esc_html_e( 'Gas Station', 'gptg' ); ?></option>
										<option value="store"><?php esc_html_e( 'Store', 'gptg' ); ?></option>
										<option value="pharmacy"><?php esc_html_e( 'Pharmacy', 'gptg' ); ?></option>
										<option value="bank"><?php esc_html_e( 'Bank', 'gptg' ); ?></option>
										<option value="hospital"><?php esc_html_e( 'Hospital', 'gptg' ); ?></option>
										<option value="school"><?php esc_html_e( 'School', 'gptg' ); ?></option>
									</select>
									<p class="description"><?php esc_html_e( 'Filter results by place type', 'gptg' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row">
									<label for="min_rating"><?php esc_html_e( 'Minimum Rating', 'gptg' ); ?></label>
								</th>
								<td>
									<input type="number" id="min_rating" name="min_rating" min="0" max="5" step="0.1" class="small-text" />
									<p class="description"><?php esc_html_e( 'Minimum rating (0-5)', 'gptg' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row">
									<label for="max_result_count"><?php esc_html_e( 'Max Results', 'gptg' ); ?></label>
								</th>
								<td>
									<input type="number" id="max_result_count" name="max_result_count" min="1" max="60" value="20" class="small-text" />
									<p class="description"><?php esc_html_e( 'Maximum number of results (1-60). Requests over 20 will automatically paginate.', 'gptg' ); ?></p>
								</td>
							</tr>
						</tbody>
					</table>
				</div>
				
				<div class="gptg-form-section" id="nearby-search-options" style="display: none;">
					<h3><?php esc_html_e( 'Nearby Search Options', 'gptg' ); ?></h3>
					
					<table class="form-table">
						<tbody>
							<tr>
								<th scope="row">
									<label><?php esc_html_e( 'Location', 'gptg' ); ?></label>
								</th>
								<td>
									<div id="gptg-location-map" style="width: 100%; height: 400px; margin-bottom: 10px;"></div>
									<p class="description"><?php esc_html_e( 'Click on the map or drag the marker to set the search location', 'gptg' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row">
									<label for="latitude"><?php esc_html_e( 'Latitude', 'gptg' ); ?></label>
								</th>
								<td>
									<input type="number" id="latitude" name="latitude" step="any" class="regular-text" placeholder="<?php esc_attr_e( 'e.g., 40.7128', 'gptg' ); ?>" readonly />
									<p class="description"><?php esc_html_e( 'Set by clicking on the map above', 'gptg' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row">
									<label for="longitude"><?php esc_html_e( 'Longitude', 'gptg' ); ?></label>
								</th>
								<td>
									<input type="number" id="longitude" name="longitude" step="any" class="regular-text" placeholder="<?php esc_attr_e( 'e.g., -74.0060', 'gptg' ); ?>" readonly />
									<p class="description"><?php esc_html_e( 'Set by clicking on the map above', 'gptg' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row">
									<label for="radius_miles"><?php esc_html_e( 'Radius (miles)', 'gptg' ); ?></label>
								</th>
								<td>
									<input type="number" id="radius_miles" name="radius_miles" min="0.1" max="31.07" step="0.1" value="3.1" class="regular-text" />
									<p class="description"><?php esc_html_e( 'Search radius in miles (max ~31 miles / 50 km)', 'gptg' ); ?></p>
									<input type="hidden" id="radius" name="radius" />
								</td>
							</tr>
							<tr>
								<th scope="row">
									<label for="nearby_included_type"><?php esc_html_e( 'Included Type', 'gptg' ); ?></label>
								</th>
								<td>
									<select id="nearby_included_type" name="nearby_included_type" class="regular-text">
										<option value=""><?php esc_html_e( 'Any', 'gptg' ); ?></option>
										<option value="restaurant"><?php esc_html_e( 'Restaurant', 'gptg' ); ?></option>
										<option value="lodging"><?php esc_html_e( 'Lodging', 'gptg' ); ?></option>
										<option value="gas_station"><?php esc_html_e( 'Gas Station', 'gptg' ); ?></option>
										<option value="store"><?php esc_html_e( 'Store', 'gptg' ); ?></option>
										<option value="pharmacy"><?php esc_html_e( 'Pharmacy', 'gptg' ); ?></option>
										<option value="bank"><?php esc_html_e( 'Bank', 'gptg' ); ?></option>
										<option value="hospital"><?php esc_html_e( 'Hospital', 'gptg' ); ?></option>
										<option value="school"><?php esc_html_e( 'School', 'gptg' ); ?></option>
									</select>
								</td>
							</tr>
							<tr>
								<th scope="row">
									<label for="nearby_max_result_count"><?php esc_html_e( 'Max Results', 'gptg' ); ?></label>
								</th>
								<td>
									<input type="number" id="nearby_max_result_count" name="nearby_max_result_count" min="1" max="60" value="20" class="small-text" />
									<p class="description"><?php esc_html_e( 'Maximum number of results (1-60). Requests over 20 will automatically paginate.', 'gptg' ); ?></p>
								</td>
							</tr>
						</tbody>
					</table>
				</div>
				
				<div class="gptg-form-section" id="comprehensive-search-options" style="display: none;">
					<h3><?php esc_html_e( 'Comprehensive Area Search Options', 'gptg' ); ?></h3>
					<p class="description">
						<?php esc_html_e( 'This method divides the area into a grid and searches each cell to get ALL places without gaps. Use the map to set the center point and radius.', 'gptg' ); ?>
					</p>
					
					<table class="form-table">
						<tbody>
							<tr>
								<th scope="row">
									<label><?php esc_html_e( 'Search Area', 'gptg' ); ?></label>
								</th>
								<td>
									<div id="gptg-comprehensive-map" style="width: 100%; height: 400px; margin-bottom: 10px;"></div>
									<p class="description"><?php esc_html_e( 'The circle shows your search area. Drag the marker or click the map to set center, adjust radius below.', 'gptg' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row">
									<label for="comprehensive_latitude"><?php esc_html_e( 'Center Latitude', 'gptg' ); ?></label>
								</th>
								<td>
									<input type="number" id="comprehensive_latitude" name="comprehensive_latitude" step="any" class="regular-text" readonly />
								</td>
							</tr>
							<tr>
								<th scope="row">
									<label for="comprehensive_longitude"><?php esc_html_e( 'Center Longitude', 'gptg' ); ?></label>
								</th>
								<td>
									<input type="number" id="comprehensive_longitude" name="comprehensive_longitude" step="any" class="regular-text" readonly />
								</td>
							</tr>
							<tr>
								<th scope="row">
									<label for="comprehensive_radius_miles"><?php esc_html_e( 'Search Radius (miles)', 'gptg' ); ?></label>
								</th>
								<td>
									<input type="number" id="comprehensive_radius_miles" name="comprehensive_radius_miles" min="0.5" max="50" step="0.5" value="5" class="regular-text" />
									<p class="description"><?php esc_html_e( 'Larger radii will take longer and use more API calls. Recommended: 2-10 miles.', 'gptg' ); ?></p>
									<input type="hidden" id="comprehensive_radius" name="comprehensive_radius" />
								</td>
							</tr>
							<tr>
								<th scope="row">
									<label for="comprehensive_included_type"><?php esc_html_e( 'Included Type', 'gptg' ); ?></label>
								</th>
								<td>
									<select id="comprehensive_included_type" name="comprehensive_included_type" class="regular-text">
										<option value=""><?php esc_html_e( 'Any', 'gptg' ); ?></option>
										<option value="restaurant"><?php esc_html_e( 'Restaurant', 'gptg' ); ?></option>
										<option value="lodging"><?php esc_html_e( 'Lodging', 'gptg' ); ?></option>
										<option value="gas_station"><?php esc_html_e( 'Gas Station', 'gptg' ); ?></option>
										<option value="store"><?php esc_html_e( 'Store', 'gptg' ); ?></option>
										<option value="pharmacy"><?php esc_html_e( 'Pharmacy', 'gptg' ); ?></option>
										<option value="bank"><?php esc_html_e( 'Bank', 'gptg' ); ?></option>
										<option value="hospital"><?php esc_html_e( 'Hospital', 'gptg' ); ?></option>
										<option value="school"><?php esc_html_e( 'School', 'gptg' ); ?></option>
									</select>
									<p class="description"><?php esc_html_e( 'Filter by place type (optional)', 'gptg' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row">
									<label for="comprehensive_cell_radius"><?php esc_html_e( 'Grid Cell Size (miles)', 'gptg' ); ?></label>
								</th>
								<td>
									<input type="number" id="comprehensive_cell_radius" name="comprehensive_cell_radius" min="0.5" max="5" step="0.5" value="1.5" class="regular-text" />
									<p class="description"><?php esc_html_e( 'Smaller cells = more thorough but slower. Recommended: 1-2 miles.', 'gptg' ); ?></p>
									<input type="hidden" id="comprehensive_cell_radius_meters" name="comprehensive_cell_radius_meters" />
								</td>
							</tr>
						</tbody>
					</table>
				</div>
				
				<?php submit_button( __( 'Search Places', 'gptg' ), 'primary', 'gptg-search-submit' ); ?>
			</form>

			<div class="notice notice-info inline gptg-image-settings-notice">
				<p>
					<?php
					printf(
						/* translators: %s: link to Import tab image settings */
						esc_html__( '%1$s %2$s', 'gptg' ),
						esc_html__( 'Photo import options are on the Import tab under Images.', 'gptg' ),
						'<a href="' . esc_url( admin_url( 'admin.php?page=gptg-settings&tab=import#gptg-images-settings' ) ) . '">' . esc_html__( 'Open image settings', 'gptg' ) . '</a>'
					);
					?>
					<span class="gptg-image-settings-summary">
						(<?php
						echo esc_html(
							sprintf(
								/* translators: 1: on/off, 2: featured on/off, 3: number */
								__( 'Images: %1$s, featured first: %2$s, limit: %3$d', 'gptg' ),
								'1' === get_option( 'gptg_import_images', '1' ) ? __( 'on', 'gptg' ) : __( 'off', 'gptg' ),
								'1' === get_option( 'gptg_import_featured_first', '1' ) ? __( 'on', 'gptg' ) : __( 'off', 'gptg' ),
								(int) get_option( 'gptg_import_photo_limit', 5 )
							)
						);
						?>)
					</span>
				</p>
			</div>
			
			<div id="gptg-search-results" class="gptg-search-results" style="display: none;">
				<h3><?php esc_html_e( 'Search Results', 'gptg' ); ?></h3>
				<div class="gptg-results-header">
					<label>
						<input type="checkbox" id="gptg-select-all-checkbox" />
						<strong><?php esc_html_e( 'Select All', 'gptg' ); ?></strong>
					</label>
				</div>
				<div id="gptg-contact-enrich-progress" class="gptg-contact-enrich-progress" style="display:none;">
					<div class="gptg-progress-bar"><div class="gptg-progress-fill"></div></div>
					<p id="gptg-contact-enrich-progress-text"></p>
				</div>
				<div id="gptg-results-toolbar" class="gptg-results-toolbar" style="display:none;">
					<div class="gptg-toolbar-row">
						<label><?php esc_html_e( 'Sort', 'gptg' ); ?>
							<select id="gptg-results-sort">
								<option value="name_asc"><?php esc_html_e( 'Name A–Z', 'gptg' ); ?></option>
								<option value="name_desc"><?php esc_html_e( 'Name Z–A', 'gptg' ); ?></option>
								<option value="rating_desc"><?php esc_html_e( 'Rating (high to low)', 'gptg' ); ?></option>
								<option value="reviews_desc"><?php esc_html_e( 'Reviews (high to low)', 'gptg' ); ?></option>
							</select>
						</label>
						<label><?php esc_html_e( 'Min rating', 'gptg' ); ?>
							<select id="gptg-filter-min-rating">
								<option value=""><?php esc_html_e( 'Any', 'gptg' ); ?></option>
								<option value="3">3+</option>
								<option value="3.5">3.5+</option>
								<option value="4">4+</option>
								<option value="4.5">4.5+</option>
							</select>
						</label>
					</div>
					<div class="gptg-toolbar-filters">
						<label><input type="checkbox" id="gptg-filter-website" /> <?php esc_html_e( 'Has website', 'gptg' ); ?></label>
						<label><input type="checkbox" id="gptg-filter-email" /> <?php esc_html_e( 'Has email', 'gptg' ); ?></label>
						<label><input type="checkbox" id="gptg-filter-phone" /> <?php esc_html_e( 'Has phone', 'gptg' ); ?></label>
						<label><input type="checkbox" id="gptg-filter-facebook" /> <?php esc_html_e( 'Facebook', 'gptg' ); ?></label>
						<label><input type="checkbox" id="gptg-filter-twitter" /> <?php esc_html_e( 'X/Twitter', 'gptg' ); ?></label>
						<label><input type="checkbox" id="gptg-filter-instagram" /> <?php esc_html_e( 'Instagram', 'gptg' ); ?></label>
						<label><input type="checkbox" id="gptg-filter-linkedin" /> <?php esc_html_e( 'LinkedIn', 'gptg' ); ?></label>
						<button type="button" class="button button-small" id="gptg-apply-filters"><?php esc_html_e( 'Apply', 'gptg' ); ?></button>
						<button type="button" class="button button-small" id="gptg-clear-filters"><?php esc_html_e( 'Clear', 'gptg' ); ?></button>
					</div>
				</div>
				<div id="gptg-results-list"></div>
				<div id="gptg-results-actions">
					<button type="button" class="button" id="gptg-select-all"><?php esc_html_e( 'Select All', 'gptg' ); ?></button>
					<button type="button" class="button" id="gptg-deselect-all"><?php esc_html_e( 'Deselect All', 'gptg' ); ?></button>
					<button type="button" class="button button-primary" id="gptg-import-selected"><?php esc_html_e( 'Import Selected to GeoDirectory', 'gptg' ); ?></button>
					<button type="button" class="button" id="gptg-save-selected"><?php esc_html_e( 'Save to Export List', 'gptg' ); ?></button>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Place detail modal shell.
	 */
	private function render_place_modal() {
		?>
		<div id="gptg-place-modal" class="gptg-modal" style="display:none;" aria-hidden="true">
			<div class="gptg-modal-backdrop"></div>
			<div class="gptg-modal-dialog" role="dialog" aria-modal="true">
				<button type="button" class="gptg-modal-close" aria-label="<?php esc_attr_e( 'Close', 'gptg' ); ?>">&times;</button>
				<div class="gptg-modal-body">
					<div id="gptg-modal-loading" class="gptg-modal-loading"><?php esc_html_e( 'Loading...', 'gptg' ); ?></div>
					<div id="gptg-modal-content" style="display:none;"></div>
				</div>
				<div class="gptg-modal-footer">
					<button type="button" class="button button-primary" id="gptg-modal-import"><?php esc_html_e( 'Import to GeoDirectory', 'gptg' ); ?></button>
					<button type="button" class="button" id="gptg-modal-save"><?php esc_html_e( 'Save to Export List', 'gptg' ); ?></button>
					<button type="button" class="button gptg-modal-close-btn"><?php esc_html_e( 'Close', 'gptg' ); ?></button>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Import settings tab.
	 */
	private function render_import_tab() {
		$post_types = $this->get_geodirectory_post_types();
		$groups     = GPTG_Google_Places_API::get_details_field_groups();
		$enabled    = get_option( 'gptg_details_field_groups', array( 'basic', 'contact', 'hours', 'content', 'photos' ) );
		$log        = get_option( 'gptg_import_log', array() );
		?>
		<form method="post" action="options.php" class="gptg-settings-form">
			<?php settings_fields( 'gptg_import_settings' ); ?>

			<h2 id="gptg-images-settings"><?php esc_html_e( 'Images', 'gptg' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Import Google Place photos into the GeoDirectory gallery. Featured image uses gallery order 0 when enabled below.', 'gptg' ); ?></p>
			<table class="form-table gptg-images-settings">
				<tbody>
					<tr>
						<th scope="row"><?php esc_html_e( 'Enable image import', 'gptg' ); ?></th>
						<td>
							<input type="hidden" name="gptg_import_images" value="0" />
							<label><input type="checkbox" name="gptg_import_images" value="1" <?php checked( get_option( 'gptg_import_images', '1' ), '1' ); ?> /> <?php esc_html_e( 'Import photos from Google when importing a listing', 'gptg' ); ?></label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Featured image', 'gptg' ); ?></th>
						<td>
							<input type="hidden" name="gptg_import_featured_first" value="0" />
							<label><input type="checkbox" name="gptg_import_featured_first" value="1" <?php checked( get_option( 'gptg_import_featured_first', '1' ), '1' ); ?> /> <?php esc_html_e( 'Set first imported image as featured (GeoDirectory gallery order 0)', 'gptg' ); ?></label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="gptg_import_photo_limit"><?php esc_html_e( 'Photo limit', 'gptg' ); ?></label></th>
						<td>
							<input type="number" name="gptg_import_photo_limit" id="gptg_import_photo_limit" min="0" max="10" value="<?php echo esc_attr( get_option( 'gptg_import_photo_limit', 5 ) ); ?>" />
							<p class="description"><?php esc_html_e( 'Maximum photos per listing (0 = none). Requires the Photos detail field group when loading place details.', 'gptg' ); ?></p>
						</td>
					</tr>
				</tbody>
			</table>

			<h2><?php esc_html_e( 'Import Settings', 'gptg' ); ?></h2>
			<table class="form-table">
				<tbody>
					<tr>
						<th scope="row"><label for="gptg_import_post_type"><?php esc_html_e( 'Post Type', 'gptg' ); ?></label></th>
						<td>
							<select name="gptg_import_post_type" id="gptg_import_post_type">
								<?php foreach ( $post_types as $pt => $label ) : ?>
									<option value="<?php echo esc_attr( $pt ); ?>" <?php selected( get_option( 'gptg_import_post_type', 'gd_place' ), $pt ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="gptg_import_post_status"><?php esc_html_e( 'Default Status', 'gptg' ); ?></label></th>
						<td>
							<select name="gptg_import_post_status" id="gptg_import_post_status">
								<option value="draft" <?php selected( get_option( 'gptg_import_post_status', 'draft' ), 'draft' ); ?>><?php esc_html_e( 'Draft', 'gptg' ); ?></option>
								<option value="publish" <?php selected( get_option( 'gptg_import_post_status', 'draft' ), 'publish' ); ?>><?php esc_html_e( 'Published', 'gptg' ); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="gptg_duplicate_behavior"><?php esc_html_e( 'Duplicates', 'gptg' ); ?></label></th>
						<td>
							<select name="gptg_duplicate_behavior" id="gptg_duplicate_behavior">
								<option value="skip" <?php selected( get_option( 'gptg_duplicate_behavior', 'skip' ), 'skip' ); ?>><?php esc_html_e( 'Skip', 'gptg' ); ?></option>
								<option value="update" <?php selected( get_option( 'gptg_duplicate_behavior', 'skip' ), 'update' ); ?>><?php esc_html_e( 'Update existing', 'gptg' ); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Detail fields', 'gptg' ); ?></th>
						<td>
							<?php foreach ( $groups as $key => $mask ) : ?>
								<label style="display:block;margin-bottom:6px;">
									<input type="checkbox" name="gptg_details_field_groups[]" value="<?php echo esc_attr( $key ); ?>" <?php checked( in_array( $key, (array) $enabled, true ) ); ?> />
									<?php echo esc_html( ucfirst( $key ) ); ?>
								</label>
							<?php endforeach; ?>
							<p class="description"><?php esc_html_e( 'More fields = higher API cost. Loaded when you open a place card or import a listing. Enable Reviews for richer third-person descriptions.', 'gptg' ); ?></p>
						</td>
					</tr>
				</tbody>
			</table>
			<?php submit_button(); ?>
		</form>

		<h2><?php esc_html_e( 'Import Log', 'gptg' ); ?></h2>
		<p><button type="button" class="button" id="gptg-clear-import-log"><?php esc_html_e( 'Clear log', 'gptg' ); ?></button></p>
		<table class="widefat striped" id="gptg-import-log-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Time', 'gptg' ); ?></th>
					<th><?php esc_html_e( 'Place', 'gptg' ); ?></th>
					<th><?php esc_html_e( 'Status', 'gptg' ); ?></th>
					<th><?php esc_html_e( 'Post', 'gptg' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $log ) ) : ?>
					<tr><td colspan="4"><?php esc_html_e( 'No imports yet.', 'gptg' ); ?></td></tr>
				<?php else : ?>
					<?php foreach ( array_slice( $log, 0, 20 ) as $entry ) : ?>
						<tr>
							<td><?php echo esc_html( isset( $entry['time'] ) ? $entry['time'] : '' ); ?></td>
							<td><?php echo esc_html( isset( $entry['name'] ) ? $entry['name'] : '' ); ?></td>
							<td><?php echo esc_html( isset( $entry['status'] ) ? $entry['status'] : '' ); ?></td>
							<td>
								<?php if ( ! empty( $entry['post_id'] ) ) : ?>
									<a href="<?php echo esc_url( get_edit_post_link( $entry['post_id'] ) ); ?>"><?php esc_html_e( 'Edit', 'gptg' ); ?></a>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * AI settings tab.
	 */
	private function render_ai_tab() {
		$ai_status    = GPTG_AI_Tagger::get_provider_status();
		$status_class = 'notice-info';
		if ( 'ready' === $ai_status['status'] ) {
			$status_class = 'notice-success';
		} elseif ( 'warning' === $ai_status['status'] ) {
			$status_class = 'notice-warning';
		}
		?>
		<form method="post" action="options.php" class="gptg-settings-form">
			<?php settings_fields( 'gptg_ai_settings' ); ?>
			<h2><?php esc_html_e( 'AI Settings', 'gptg' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Configure AI providers for listing descriptions on import. Categories and tags can be assigned manually in the import preview or after import in GeoDirectory.', 'gptg' ); ?></p>
			<div class="notice <?php echo esc_attr( $status_class ); ?> inline" style="margin:12px 0;">
				<p><strong><?php esc_html_e( 'AI status:', 'gptg' ); ?></strong> <?php echo esc_html( $ai_status['message'] ); ?></p>
			</div>
			<table class="form-table">
				<tbody>
					<tr>
						<th scope="row"><label for="gptg_ai_provider"><?php esc_html_e( 'Provider', 'gptg' ); ?></label></th>
						<td>
							<select name="gptg_ai_provider" id="gptg_ai_provider">
								<option value="rules" <?php selected( get_option( 'gptg_ai_provider', 'rules' ), 'rules' ); ?>><?php esc_html_e( 'Disabled (template descriptions only)', 'gptg' ); ?></option>
								<option value="openai" <?php selected( get_option( 'gptg_ai_provider', 'rules' ), 'openai' ); ?>>OpenAI</option>
								<option value="anthropic" <?php selected( get_option( 'gptg_ai_provider', 'rules' ), 'anthropic' ); ?>>Anthropic</option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="gptg_openai_api_key"><?php esc_html_e( 'OpenAI API Key', 'gptg' ); ?></label></th>
						<td>
							<input type="password" name="gptg_openai_api_key" id="gptg_openai_api_key" value="<?php echo esc_attr( get_option( 'gptg_openai_api_key', '' ) ); ?>" class="regular-text" autocomplete="off" />
							<?php if ( defined( 'GPTG_OPENAI_API_KEY' ) && GPTG_OPENAI_API_KEY ) : ?>
								<p class="description"><?php esc_html_e( 'Using GPTG_OPENAI_API_KEY from wp-config.php.', 'gptg' ); ?></p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="gptg_openai_model"><?php esc_html_e( 'OpenAI Model', 'gptg' ); ?></label></th>
						<td><input type="text" name="gptg_openai_model" id="gptg_openai_model" value="<?php echo esc_attr( get_option( 'gptg_openai_model', 'gpt-4o-mini' ) ); ?>" class="regular-text" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="gptg_anthropic_api_key"><?php esc_html_e( 'Anthropic API Key', 'gptg' ); ?></label></th>
						<td>
							<input type="password" name="gptg_anthropic_api_key" id="gptg_anthropic_api_key" value="<?php echo esc_attr( get_option( 'gptg_anthropic_api_key', '' ) ); ?>" class="regular-text" autocomplete="off" />
							<?php if ( defined( 'GPTG_ANTHROPIC_API_KEY' ) && GPTG_ANTHROPIC_API_KEY ) : ?>
								<p class="description"><?php esc_html_e( 'Using GPTG_ANTHROPIC_API_KEY from wp-config.php.', 'gptg' ); ?></p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="gptg_anthropic_model"><?php esc_html_e( 'Anthropic Model', 'gptg' ); ?></label></th>
						<td><input type="text" name="gptg_anthropic_model" id="gptg_anthropic_model" value="<?php echo esc_attr( get_option( 'gptg_anthropic_model', 'claude-3-5-haiku-latest' ) ); ?>" class="regular-text" /></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Description', 'gptg' ); ?></th>
						<td>
							<input type="hidden" name="gptg_ai_generate_description" value="0" />
							<label style="display:block;margin-bottom:8px;"><input type="checkbox" name="gptg_ai_generate_description" value="1" <?php checked( get_option( 'gptg_ai_generate_description', '1' ), '1' ); ?> /> <?php esc_html_e( 'Generate third-person listing description on import', 'gptg' ); ?></label>
							<p class="description" style="margin:0 0 8px 24px;"><?php echo esc_html( GPTG_AI_Description::get_description_mode_message() ); ?></p>
							<input type="hidden" name="gptg_description_fetch_website" value="0" />
							<label style="display:block;margin:8px 0 8px 24px;"><input type="checkbox" name="gptg_description_fetch_website" value="1" <?php checked( get_option( 'gptg_description_fetch_website', '1' ), '1' ); ?> /> <?php esc_html_e( 'Read business website for description context', 'gptg' ); ?></label>
							<input type="hidden" name="gptg_ai_prefer_description" value="0" />
							<label><input type="checkbox" name="gptg_ai_prefer_description" value="1" <?php checked( get_option( 'gptg_ai_prefer_description', '' ), '1' ); ?> /> <?php esc_html_e( 'Prefer AI description over Google editorial summary', 'gptg' ); ?></label>
							<p class="description" style="margin:4px 0 0 24px;"><?php esc_html_e( 'Google reviews are fetched automatically when generating descriptions (even if the Reviews detail field is off). Star ratings and review counts are never inserted into listing text.', 'gptg' ); ?></p>
						</td>
					</tr>
				</tbody>
			</table>
			<?php submit_button(); ?>
		</form>
		<?php
	}
	
	/**
	 * Contact enrichment settings tab.
	 */
	private function render_contact_tab() {
		$provider = get_option( 'gptg_contact_provider', 'hunter' );
		?>
		<form method="post" action="options.php" class="gptg-settings-form">
			<?php settings_fields( 'gptg_contact_settings' ); ?>
			<h2><?php esc_html_e( 'Contact Enrichment', 'gptg' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Look up business email and social profile URLs from the listing website domain. Google Places does not provide these fields.', 'gptg' ); ?></p>
			<table class="form-table">
				<tbody>
					<tr>
						<th scope="row"><?php esc_html_e( 'Enable enrichment', 'gptg' ); ?></th>
						<td>
							<input type="hidden" name="gptg_contact_enabled" value="0" />
							<label><input type="checkbox" name="gptg_contact_enabled" value="1" <?php checked( get_option( 'gptg_contact_enabled', '' ), '1' ); ?> /> <?php esc_html_e( 'Fetch email and social links after search and on import', 'gptg' ); ?></label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Provider', 'gptg' ); ?></th>
						<td>
							<fieldset>
								<label style="display:block;margin-bottom:8px;">
									<input type="radio" name="gptg_contact_provider" value="hunter" <?php checked( $provider, 'hunter' ); ?> />
									<?php esc_html_e( 'Hunter.io (recommended for email)', 'gptg' ); ?>
								</label>
								<label style="display:block;">
									<input type="radio" name="gptg_contact_provider" value="apollo" <?php checked( $provider, 'apollo' ); ?> />
									<?php esc_html_e( 'Apollo.io (strong for social URLs)', 'gptg' ); ?>
								</label>
							</fieldset>
							<p class="description"><?php esc_html_e( 'Only one provider is used at a time.', 'gptg' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="gptg_hunter_api_key"><?php esc_html_e( 'Hunter API Key', 'gptg' ); ?></label></th>
						<td>
							<input type="password" name="gptg_hunter_api_key" id="gptg_hunter_api_key" value="<?php echo esc_attr( get_option( 'gptg_hunter_api_key', '' ) ); ?>" class="regular-text" autocomplete="off" />
							<?php if ( defined( 'GPTG_HUNTER_API_KEY' ) && GPTG_HUNTER_API_KEY ) : ?>
								<p class="description"><?php esc_html_e( 'Using GPTG_HUNTER_API_KEY from wp-config.php.', 'gptg' ); ?></p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="gptg_apollo_api_key"><?php esc_html_e( 'Apollo API Key', 'gptg' ); ?></label></th>
						<td>
							<input type="password" name="gptg_apollo_api_key" id="gptg_apollo_api_key" value="<?php echo esc_attr( get_option( 'gptg_apollo_api_key', '' ) ); ?>" class="regular-text" autocomplete="off" />
							<?php if ( defined( 'GPTG_APOLLO_API_KEY' ) && GPTG_APOLLO_API_KEY ) : ?>
								<p class="description"><?php esc_html_e( 'Using GPTG_APOLLO_API_KEY from wp-config.php.', 'gptg' ); ?></p>
							<?php endif; ?>
						</td>
					</tr>
				</tbody>
			</table>
			<p class="description"><?php esc_html_e( 'Each new domain lookup uses API credits. Results are cached for 24 hours per domain.', 'gptg' ); ?></p>
			<?php submit_button(); ?>
		</form>

		<h2><?php esc_html_e( 'Test Hunter', 'gptg' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Runs a domain search for hunter.io to verify your API key and plan.', 'gptg' ); ?></p>
		<p>
			<button type="button" class="button" id="gptg-test-hunter"><?php esc_html_e( 'Test Hunter', 'gptg' ); ?></button>
			<span id="gptg-test-hunter-result" class="gptg-test-result"></span>
		</p>
		<?php
	}

	/**
	 * Render Export tab
	 */
	private function render_export_tab() {
		$saved_places = get_option( 'gptg_saved_places', array() );
		$count = is_array( $saved_places ) ? count( $saved_places ) : 0;
		?>
		<div class="gptg-export-container">
			<h2><?php esc_html_e( 'Export to GeoDirectory', 'gptg' ); ?></h2>
			
			<div class="gptg-export-info">
				<p>
					<strong><?php esc_html_e( 'Saved Places:', 'gptg' ); ?></strong> 
					<span id="gptg-places-count"><?php echo esc_html( $count ); ?></span>
				</p>
				<?php if ( $count > 0 ) : ?>
					<button type="button" class="button" id="gptg-clear-places"><?php esc_html_e( 'Clear All Places', 'gptg' ); ?></button>
				<?php endif; ?>
			</div>
			
			<?php if ( $count > 0 ) : ?>
				<div class="gptg-export-options">
					<h3><?php esc_html_e( 'Export Options', 'gptg' ); ?></h3>
					
					<form id="gptg-export-form">
						<table class="form-table">
							<tbody>
								<tr>
									<th scope="row">
										<label for="export_format"><?php esc_html_e( 'Export Format', 'gptg' ); ?></label>
									</th>
									<td>
										<select id="export_format" name="export_format">
											<option value="csv"><?php esc_html_e( 'CSV', 'gptg' ); ?></option>
											<option value="json"><?php esc_html_e( 'JSON', 'gptg' ); ?></option>
										</select>
									</td>
								</tr>
								<tr>
									<th scope="row">
										<label for="post_type"><?php esc_html_e( 'GeoDirectory Post Type', 'gptg' ); ?></label>
									</th>
									<td>
										<?php
										$post_types = $this->get_geodirectory_post_types();
										if ( ! empty( $post_types ) ) :
											?>
											<select id="post_type" name="post_type">
												<?php foreach ( $post_types as $post_type => $label ) : ?>
													<option value="<?php echo esc_attr( $post_type ); ?>"><?php echo esc_html( $label ); ?></option>
												<?php endforeach; ?>
											</select>
										<?php else : ?>
											<p class="description"><?php esc_html_e( 'No GeoDirectory post types found. Please create a post type in GeoDirectory first.', 'gptg' ); ?></p>
										<?php endif; ?>
									</td>
								</tr>
							</tbody>
						</table>
						
						<?php submit_button( __( 'Download Export File', 'gptg' ), 'primary', 'gptg-export-submit' ); ?>
					</form>
				</div>
			<?php else : ?>
				<p><?php esc_html_e( 'No places saved yet. Use the Search tab to find and save places.', 'gptg' ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}
	
	/**
	 * Get GeoDirectory post types
	 *
	 * @return array
	 */
	private function get_geodirectory_post_types() {
		if ( ! function_exists( 'geodir_get_posttypes' ) ) {
			return array();
		}
		
		$post_types = geodir_get_posttypes();
		$formatted = array();
		
		foreach ( $post_types as $post_type ) {
			$formatted[ $post_type ] = geodir_post_type_singular_name( $post_type );
		}
		
		return $formatted;
	}
}

