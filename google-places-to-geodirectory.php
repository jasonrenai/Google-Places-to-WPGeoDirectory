<?php
/**
 * Plugin Name: Google Places to GeoDirectory
 * Plugin URI: https://github.com/jasonrenai/Google-Places-to-WPGeoDirectory
 * Description: Import businesses from Google Places API and export them in GeoDirectory-compatible format.
 * Version: 2.0.0
 * Author: Jason Renai (Spokencode)
 * Author URI: https://spokencode.com
 * Text Domain: gptg
 * Domain Path: /languages
 * Requires at least: 5.0
 * Tested up to: 6.8
 * License: GPL-2.0+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Define plugin constants
define( 'GPTG_VERSION', '2.0.0' );
define( 'GPTG_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'GPTG_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'GPTG_PLUGIN_FILE', __FILE__ );

/**
 * Main plugin class
 */
class Google_Places_To_GeoDirectory {
	
	/**
	 * Plugin instance
	 *
	 * @var Google_Places_To_GeoDirectory
	 */
	private static $instance = null;
	
	/**
	 * Get plugin instance
	 *
	 * @return Google_Places_To_GeoDirectory
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}
	
	/**
	 * Constructor
	 */
	private function __construct() {
		$this->init();
	}
	
	/**
	 * Initialize plugin
	 */
	private function init() {
		// Load plugin files
		$this->load_dependencies();
		
		// Initialize hooks
		$this->init_hooks();
	}
	
	/**
	 * Load plugin dependencies
	 */
	private function load_dependencies() {
		require_once GPTG_PLUGIN_DIR . 'includes/class-gptg-google-places-api.php';
		require_once GPTG_PLUGIN_DIR . 'includes/class-gptg-place-cache.php';
		require_once GPTG_PLUGIN_DIR . 'includes/class-gptg-contact-cache.php';
		require_once GPTG_PLUGIN_DIR . 'includes/enrichment/interface-gptg-contact-provider.php';
		require_once GPTG_PLUGIN_DIR . 'includes/enrichment/class-gptg-contact-hunter.php';
		require_once GPTG_PLUGIN_DIR . 'includes/enrichment/class-gptg-contact-apollo.php';
		require_once GPTG_PLUGIN_DIR . 'includes/enrichment/class-gptg-contact-enricher.php';
		require_once GPTG_PLUGIN_DIR . 'includes/enrichment/class-gptg-website-content.php';
		require_once GPTG_PLUGIN_DIR . 'includes/class-gptg-export.php';
		require_once GPTG_PLUGIN_DIR . 'includes/class-gptg-geodirectory-importer.php';
		require_once GPTG_PLUGIN_DIR . 'includes/ai/interface-gptg-ai-provider.php';
		require_once GPTG_PLUGIN_DIR . 'includes/ai/class-gptg-ai-openai.php';
		require_once GPTG_PLUGIN_DIR . 'includes/ai/class-gptg-ai-anthropic.php';
		require_once GPTG_PLUGIN_DIR . 'includes/ai/class-gptg-ai-tagger.php';
		require_once GPTG_PLUGIN_DIR . 'includes/ai/class-gptg-ai-description.php';
		require_once GPTG_PLUGIN_DIR . 'includes/class-gptg-admin.php';
		require_once GPTG_PLUGIN_DIR . 'includes/class-gptg-ajax.php';
	}
	
	/**
	 * Initialize WordPress hooks
	 */
	private function init_hooks() {
		add_action( 'plugins_loaded', array( $this, 'load_textdomain' ) );
		add_action( 'admin_init', array( $this, 'check_geodirectory' ) );
		
		// Initialize admin and AJAX
		if ( is_admin() ) {
			new GPTG_Admin();
			new GPTG_AJAX();
		}
	}
	
	/**
	 * Load plugin textdomain
	 */
	public function load_textdomain() {
		load_plugin_textdomain( 'gptg', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
	}
	
	/**
	 * Check if GeoDirectory is active
	 */
	public function check_geodirectory() {
		if ( ! class_exists( 'GeoDirectory' ) ) {
			add_action( 'admin_notices', array( $this, 'geodirectory_missing_notice' ) );
		}
	}
	
	/**
	 * Display notice if GeoDirectory is not active
	 */
	public function geodirectory_missing_notice() {
		?>
		<div class="notice notice-error">
			<p><?php esc_html_e( 'Google Places to GeoDirectory requires GeoDirectory plugin to be installed and active.', 'gptg' ); ?></p>
		</div>
		<?php
	}
}

/**
 * Initialize the plugin
 */
function gptg_init() {
	return Google_Places_To_GeoDirectory::get_instance();
}

// Start the plugin
gptg_init();

