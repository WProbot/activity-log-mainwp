<?php
/**
 * Plugin Name: MainWP Activity Log Extension
 * Plugin URI: http://www.wpsecurityauditlog.com/
 * Description: An add-on for MainWP to be able to view the activity logs of all child sites from the central MainWP dashboard.
 * Author: WP White Security
 * Version: 0.1.0
 * Text Domain: mwp-al-ext
 * Author URI: http://www.wpsecurityauditlog.com/
 * License: GPL2
 *
 * @package mwp-al-ext
 */

/*
	MainWP Activity Log Extension
	Copyright(c) 2018  Robert Abela  (email : robert@wpwhitesecurity.com)

	This program is free software; you can redistribute it and/or modify
	it under the terms of the GNU General Public License, version 2, as
	published by the Free Software Foundation.

	This program is distributed in the hope that it will be useful,
	but WITHOUT ANY WARRANTY; without even the implied warranty of
	MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
	GNU General Public License for more details.

	You should have received a copy of the GNU General Public License
	along with this program; if not, write to the Free Software
	Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
*/

namespace WSAL\MainWPExtension;

// use \WSAL\MainWPExtension\Views\View as SingleView;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * MainWP Activity Log Extension
 *
 * Entry class for activity log extension.
 */
class Activity_Log {

	/**
	 * Plugin version.
	 *
	 * @var string
	 */
	public $version = '0.1.0';

	/**
	 * Single Static Instance of the plugin.
	 *
	 * @var Activity_Log
	 */
	public static $instance = null;

	/**
	 * Is MainWP Activated?
	 *
	 * @var boolean
	 */
	protected $mainwp_main_activated = false;

	/**
	 * Is MainWP Child plugin enabled?
	 *
	 * @var boolean
	 */
	protected $child_enabled = false;

	/**
	 * Child Key.
	 *
	 * @var boolean
	 */
	protected $child_key = false;

	/**
	 * Child File.
	 *
	 * @var string
	 */
	protected $child_file;

	/**
	 * Extension View.
	 *
	 * @var \WSAL\MainWPExtension\Views\View
	 */
	public $extension_view;

	/**
	 * Extension Settings.
	 *
	 * @var \WSAL\MainWPExtension\Settings
	 */
	public $settings;

	/**
	 * Alerts Manager.
	 *
	 * @var \WSAL\MainWPExtension\AlertManager
	 */
	public $alerts;

	/**
	 * Constants Manager.
	 *
	 * @var \WSAL\MainWPExtension\ConstantManager
	 */
	public $constants;

	/**
	 * Returns the singular instance of the plugin.
	 *
	 * @return Activity_Log
	 */
	public static function get_instance() {
		if ( ! self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	public function __construct() {
		// Define plugin constants.
		$this->define_constants();

		require_once MWPAL_BASE_DIR . 'includes/helpers/class-datahelper.php';
		require_once MWPAL_BASE_DIR . 'includes/models/class-activerecord.php';
		require_once MWPAL_BASE_DIR . 'includes/models/class-query.php';
		require_once MWPAL_BASE_DIR . 'includes/models/class-occurrencequery.php';

		// Include autoloader.
		require_once MWPAL_BASE_DIR . 'includes/vendors/autoload.php';
		\AaronHolbrook\Autoload\autoload( MWPAL_BASE_DIR . 'includes' );

		// Initiate the view.
		$this->extension_view = new \WSAL\MainWPExtension\Views\View( $this );
		$this->settings       = new \WSAL\MainWPExtension\Settings();
		$this->constants      = new \WSAL\MainWPExtension\ConstantManager( $this );
		$this->alerts         = new \WSAL\MainWPExtension\AlertManager( $this );

		// Installation routine.
		register_activation_hook( __FILE__, array( $this, 'install_extension' ) );

		// Schedule hook for refreshing events.
		add_action( 'mwp_events_cleanup', array( $this, 'events_cleanup' ) );

		// Set child file.
		$this->child_file = __FILE__;
		add_filter( 'mainwp-getextensions', array( &$this, 'get_this_extension' ) );

		// This filter will return true if the main plugin is activated.
		$this->mainwp_main_activated = apply_filters( 'mainwp-activated-check', false );

		if ( false !== $this->mainwp_main_activated ) {
			$this->activate_this_plugin();
		} else {
			// Because sometimes our main plugin is activated after the extension plugin is activated we also have a second step,
			// listening to the 'mainwp-activated' action. This action is triggered by MainWP after initialisation.
			add_action( 'mainwp-activated', array( &$this, 'activate_this_plugin' ) );
		}
		add_action( 'admin_init', array( &$this, 'redirect_to_extensions' ) );
		add_action( 'admin_notices', array( &$this, 'mainwp_error_notice' ) );
	}

	/**
	 * Load extension on `plugins_loaded` action.
	 */
	public function load_mwpal_extension() {
		do_action( 'mwpal_init', $this );
	}

	/**
	 * DB connection.
	 *
	 * @param mixed $config - DB configuration.
	 * @param bool  $reset  - True if reset.
	 * @return \WSAL\MainWPExtension\Connector\ConnectorInterface
	 */
	public static function get_connector( $config = null, $reset = false ) {
		return \WSAL\MainWPExtension\Connector\ConnectorFactory::getConnector( $config, $reset );
	}

	/**
	 * Save option that extension has been activated.
	 */
	public function install_extension() {
		// Ensure that the system is installed and schema is correct.
		self::get_connector()->installAll();

		// Option to redirect to extensions page.
		$this->settings->set_extension_activated( 'yes' );

		// Install refresh hook (remove older one if it exists).
		wp_clear_scheduled_hook( 'mwp_events_cleanup' );
		wp_schedule_event( current_time( 'timestamp' ) + 600, 'hourly', 'mwp_events_cleanup' );
	}

	/**
	 * Define constants.
	 */
	public function define_constants() {
		// Plugin version.
		if ( ! defined( 'MWPAL_VERSION' ) ) {
			define( 'MWPAL_VERSION', $this->version );
		}

		// Plugin Name.
		if ( ! defined( 'MWPAL_BASE_NAME' ) ) {
			define( 'MWPAL_BASE_NAME', plugin_basename( __FILE__ ) );
		}

		// Plugin Directory URL.
		if ( ! defined( 'MWPAL_BASE_URL' ) ) {
			define( 'MWPAL_BASE_URL', plugin_dir_url( __FILE__ ) );
		}

		// Plugin Directory Path.
		if ( ! defined( 'MWPAL_BASE_DIR' ) ) {
			define( 'MWPAL_BASE_DIR', plugin_dir_path( __FILE__ ) );
		}

		// Plugin Extension Name.
		if ( ! defined( 'MWPAL_EXTENSION_NAME' ) ) {
			define( 'MWPAL_EXTENSION_NAME', 'mainwp-activity-log-extension' );
		}

		// Plugin Min PHP Version.
		if ( ! defined( 'MWPAL_MIN_PHP_VERSION' ) ) {
			define( 'MWPAL_MIN_PHP_VERSION', '5.4.0' );
		}

		// Plugin Options Prefix.
		if ( ! defined( 'MWPAL_OPT_PREFIX' ) ) {
			define( 'MWPAL_OPT_PREFIX', 'mwpal-' );
		}
	}

	/**
	 * Redirect to MainWP Extensions Page.
	 *
	 * @return void
	 */
	public function redirect_to_extensions() {
		if ( 'yes' === $this->settings->is_extension_activated() ) {
			$this->settings->delete_option( 'activity-extension-activated' );
			wp_safe_redirect( add_query_arg( 'page', 'Extensions', admin_url( 'admin.php' ) ) );
			return;
		}
	}

	/**
	 * Add extension to MainWP.
	 *
	 * @param array $plugins – Array of plugins.
	 * @return array
	 */
	public function get_this_extension( $plugins ) {
		$plugins[] = array(
			'plugin'   => __FILE__,
			'api'      => MWPAL_EXTENSION_NAME,
			'mainwp'   => false,
			'callback' => array( &$this, 'display_extension' ),
		);
		return $plugins;
	}

	/**
	 * Extension Display on MainWP Dashboard.
	 */
	public function display_extension() {
		$this->extension_view->render_page();
	}

	/**
	 * The function "activate_this_plugin" is called when the main is initialized.
	 */
	public function activate_this_plugin() {
		// Checking if the MainWP plugin is enabled. This filter will return true if the main plugin is activated.
		$this->mainwp_main_activated = apply_filters( 'mainwp-activated-check', $this->mainwp_main_activated );

		// The 'mainwp-extension-enabled-check' hook. If the plugin is not enabled this will return false,
		// if the plugin is enabled, an array will be returned containing a key.
		// This key is used for some data requests to our main.
		$this->child_enabled = apply_filters( 'mainwp-extension-enabled-check', __FILE__ );
		$this->child_key     = $this->child_enabled['key'];
	}

	/**
	 * MainWP Plugin Error Notice.
	 */
	public function mainwp_error_notice() {
		global $current_screen;
		if ( 'plugins' === $current_screen->parent_base && false === $this->mainwp_main_activated ) {
			echo '<div class="error"><p>MainWP Hello World! Extension ' . esc_html__( 'requires ', 'mwp-al-ext' ) . '<a href="http://mainwp.com/" target="_blank">MainWP</a>' . esc_html__( ' Plugin to be activated in order to work. Please install and activate', 'mwp-al-ext' ) . '<a href="http://mainwp.com/" target="_blank">MainWP</a> ' . esc_html__( 'first.', 'mwp-al-ext' ) . '</p></div>';
		}
	}

	/**
	 * Check if extension is enabled.
	 *
	 * @return mix
	 */
	public function is_child_enabled() {
		return $this->child_enabled;
	}

	/**
	 * Get Child Key.
	 *
	 * @return string
	 */
	public function get_child_key() {
		return $this->child_key;
	}

	/**
	 * Get Child File.
	 *
	 * @return string
	 */
	public function get_child_file() {
		return $this->child_file;
	}

	/**
	 * Load events from external file: `default-events.php`.
	 */
	public function load_events() {
		require_once 'default-events.php';
	}

	/**
	 * Error Logger
	 *
	 * Logs given input into debug.log file in debug mode.
	 *
	 * @param mix $message - Error message.
	 */
	public function log( $message ) {
		if ( WP_DEBUG === true ) {
			if ( is_array( $message ) || is_object( $message ) ) {
				error_log( print_r( $message, true ) );
			} else {
				error_log( $message );
			}
		}
	}

	/**
	 * Clean Up Events
	 *
	 * Clean up events of a site if the latest event is more
	 * than three hours late.
	 */
	public function events_cleanup() {
		// Get MainWP sites.
		$mwp_sites = $this->settings->get_mwp_child_sites();

		foreach ( $mwp_sites as $site ) {
			$event = $this->get_latest_event_by_siteid( $site['id'] );

			if ( $event ) {
				$hrs_diff = $this->settings->get_hours_since_last_alert( $event->created_on );

				// If the hours difference is more than 3.
				if ( $hrs_diff > 3 ) {
					// Get latest event from child site.
					$live_event = $this->get_live_event_by_siteid( $site['id'] );

					// If the latest event on the dashboard matches the timestamp of the latest event on child site, then skip.
					if ( $live_event && $event->created_on === $live_event->created_on ) {
						continue;
					}

					// Delete events by site id.
					$delete_query = new \WSAL\MainWPExtension\Models\OccurrenceQuery();
					$delete_query->addCondition( 'site_id = %s ', $site['id'] );
					// $result       = $delete_query->getAdapter()->GetSqlDelete( $delete_query );
					$delete_count = $delete_query->getAdapter()->Delete( $delete_query );

					// Nothing to delete.
					if ( 0 == $delete_count ) {
						return;
					}

					// Keep track of what we're doing.
					// $this->alerts->Trigger(
					// 	0003, array(
					// 		'Message'    => 'Running system cleanup.',
					// 		'Query SQL'  => $result['sql'],
					// 		'Query Args' => $result['args'],
					// 	), true
					// );
				}
			}
		}
	}

	/**
	 * Get the latest event by site id.
	 *
	 * @param integer $site_id — Site ID.
	 * @return array
	 */
	private function get_latest_event_by_siteid( $site_id = 0 ) {
		// Return if site id is empty.
		if ( empty( $site_id ) ) {
			return false;
		}

		// Query for latest event.
		$event_query = new \WSAL\MainWPExtension\Models\OccurrenceQuery();
		$event_query->addCondition( 'site_id = %s ', $site_id ); // Set site id.
		$event_query->addOrderBy( 'created_on', true );
		$event_query->setLimit( 1 );
		$event = $event_query->getAdapter()->Execute( $event_query );

		if ( isset( $event[0] ) ) {
			return $event[0];
		}
		return false;
	}

	/**
	 * Get live event by site id (from child site).
	 *
	 * @param integer $site_id — Site ID.
	 * @return stdClass
	 */
	private function get_live_event_by_siteid( $site_id = 0 ) {
		// Return if site id is empty.
		if ( empty( $site_id ) ) {
			return false;
		}

		// Post data for child sites.
		$post_data = array(
			'action' => 'latest_event',
		);

		// Call to child sites to fetch WSAL events.
		$latest_event = apply_filters(
			'mainwp_fetchurlauthed',
			$this->get_child_file(),
			$this->get_child_key(),
			$site_id,
			'extra_excution',
			$post_data
		);
		return $latest_event;
	}
}

/**
 * Return the one and only instance of this plugin.
 *
 * @return \WSAL\MainWPExtension\Activity_Log
 */
function mwpal_extension_load() {
	return \WSAL\MainWPExtension\Activity_Log::get_instance();
}

// Initiate the plugin.
$mwpal_extension = mwpal_extension_load();

// Load MainWP Activity Log Extension.
add_action( 'plugins_loaded', array( $mwpal_extension, 'load_mwpal_extension' ) );

// Include events for extension.
$mwpal_extension->load_events();
