<?php
/**
 * Plugin Name: Event Tickets with Ticket Scanner
 * Plugin URI: https://vollstart.com/event-tickets-with-ticket-scanner/docs/
 * Description: You can create and generate tickets and codes. You can redeem the tickets at entrance using the built-in ticket scanner. You customer can download a PDF with the ticket information. The Premium allows you also to activate user registration and more. This allows your user to register them self to a ticket.
 * Version: 3.0.3
 * Author: Vollstart
 * Author URI: https://vollstart.com
 * Requires at least: 6.0
 * Tested up to: 6.9
 * Requires PHP: 8.1
 * Text Domain: event-tickets-with-ticket-scanner
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 *
 * Event Tickets with Ticket Scanner is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 */
// https://semver.org/
// https://developer.wordpress.org/plugins/security/securing-output/
// https://developer.wordpress.org/plugins/security/securing-input/

include_once(plugin_dir_path(__FILE__)."init_file.php");

if (!defined('SASO_EVENTTICKETS_PLUGIN_VERSION'))
	define('SASO_EVENTTICKETS_PLUGIN_VERSION', '3.0.3');
if (!defined('SASO_EVENTTICKETS_PLUGIN_DIR_PATH'))
	define('SASO_EVENTTICKETS_PLUGIN_DIR_PATH', plugin_dir_path(__FILE__));

include_once plugin_dir_path(__FILE__)."SASO_EVENTTICKETS.php";

class sasoEventtickets_fakeprem{}
class sasoEventtickets {
	private $_js_version;
	private $_js_file = 'saso-eventtickets-validator.js';
	public $_js_nonce = 'sasoEventtickets';
	public $_do_action_prefix = 'saso_eventtickets_';
	public $_add_filter_prefix = 'saso_eventtickets_';
	protected $_prefix = 'sasoEventtickets';
	public $_prefix_session = 'sasoEventtickets_';
	protected $_shortcode = 'sasoEventTicketsValidator';
	protected $_shortcode_mycode = 'sasoEventTicketsValidator_code';
	protected $_shortcode_eventviews = 'sasoEventTicketsValidator_eventsview';
	protected $_shortcode_ticket_scanner = 'sasoEventTicketsValidator_ticket_scanner';
	protected $_shortcode_feature_list = 'sasoEventTicketsValidator_feature_list';
	protected $_shortcode_ticket_detail = 'sasoEventTicketsValidator_ticket_detail';
	protected $_divId = 'sasoEventtickets';

	private $_isPrem = null;
	private $_isCheckingSubscription = false;
	private $_premium_plugin_name = 'event-tickets-with-ticket-scanner-premium';
	private $_premium_function_file = 'sasoEventtickets_PremiumFunctions.php';
	private $PREMFUNCTIONS = null;
	private $_oldPremiumDetected = false;
	private $_starterOrStopDetected = false;
	private $BASE = null;
	private $CORE = null;
	private $ADMIN = null;
	private $FRONTEND = null;
	private $OPTIONS = null;

	private $isAllowedAccess = null;

	public static function Instance() {
		static $inst = null;
        if ($inst === null) {
            $inst = new self();
		}
        return $inst;
	}

	public function __construct() {
		global $sasoEventtickets;
		$sasoEventtickets = $this; // Set early to prevent circular dependency with sasoEventtickets_Ticket
		$this->_js_version = $this->getPluginVersion() . (defined('WP_DEBUG') && WP_DEBUG ? '.' . time() : '');
		$this->initHandlers();
	}

	public function initHandlers() {
		add_action( 'init', [$this, 'load_plugin_textdomain'] );
		add_action( 'upgrader_process_complete', [$this, 'listener_upgrader_process_complete'], 10, 2 );
		//add_action('admin_init', [$this, 'initialize_plugin']);
		if (is_admin()) { // called in backend admin, admin-ajax!
			$this->init_backend();
		} else { // called in front end
			$this->init_frontend();
		}
		add_action( 'sasoEventtickets_cronjob_daily', [$this, 'relay_sasoEventtickets_cronjob_daily'], 10, 0 ); // set in tickets.php
		add_action( 'plugins_loaded', [$this, 'WooCommercePluginLoaded'], 20, 0 );
  		if (basename($_SERVER['SCRIPT_NAME']) == "admin-ajax.php") {
			add_action('wp_ajax_nopriv_'.$this->_prefix.'_executeFrontend', [$this,'executeFrontend_a'], 10, 0); // nicht angemeldete user, sollen eine antwort erhalten
			add_action('wp_ajax_'.$this->_prefix.'_executeFrontend', [$this,'executeFrontend_a'], 10, 0); // falls eingeloggt ist
			add_action('wp_ajax_'.$this->_prefix.'_executeWCBackend', [$this,'executeWCBackend'], 10, 0); // falls eingeloggt ist
			add_action('wp_ajax_'.$this->_prefix.'_downloadMyCodesAsPDF', [$this,'downloadMyCodesAsPDF'], 10, 0); // logged in users only
		}
		if (method_exists($this->getPremiumFunctions(), "initHandlers")) {
			$this->getPremiumFunctions()->initHandlers();
		}
		$this->cronjob_daily_activate();
	}
	public function cronjob_daily_activate() {
		$args = [];
		if (! wp_next_scheduled ( 'sasoEventtickets_cronjob_daily', $args )) {
			wp_schedule_event( strtotime("00:05"), 'daily', 'sasoEventtickets_cronjob_daily', $args );
		}
	}
	public function cronjob_daily_deactivate() {
		wp_clear_scheduled_hook( 'sasoEventtickets_cronjob_daily' );
	}
	public function load_plugin_textdomain() {
		load_plugin_textdomain( 'event-tickets-with-ticket-scanner', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
	}
	public function getPluginPath() {
		return SASO_EVENTTICKETS_PLUGIN_DIR_PATH;
	}
	public function getPluginVersion() {
		return SASO_EVENTTICKETS_PLUGIN_VERSION;
	}
	public function getPluginVersions() {
		$ret = ['basic'=>SASO_EVENTTICKETS_PLUGIN_VERSION, 'premium'=>'', 'debug'=>''];
		if (defined('SASO_EVENTTICKETS_PREMIUM_PLUGIN_VERSION')) {
			$ret['premium'] = SASO_EVENTTICKETS_PREMIUM_PLUGIN_VERSION;
		}
		if (defined('WP_DEBUG') && WP_DEBUG) {
			$ret['debug'] = esc_html__('is active', 'event-tickets-with-ticket-scanner');
		}
		return $ret;
	}
	public function getDB() {
		return SASO_EVENTTICKETS::getDB(plugin_dir_path(__FILE__), "sasoEventticketsDB", $this);
	}
	public function getBase() {
		if ($this->BASE == null) {
			if (!class_exists('sasoEventtickets_Base')) {
				include_once plugin_dir_path(__FILE__)."sasoEventtickets_Base.php";
			}
			$this->BASE = new sasoEventtickets_Base($this);
		}
		return $this->BASE;
	}
	public function getCore() {
		if ($this->CORE == null) {
			if (!class_exists('sasoEventtickets_Core')) {
				include_once plugin_dir_path(__FILE__)."sasoEventtickets_Core.php";
			}
			$this->CORE = new sasoEventtickets_Core($this);
		}
		return $this->CORE;
	}
	public function getAdmin() {
		if ($this->ADMIN == null) {
			if (!class_exists('sasoEventtickets_AdminSettings')) {
				include_once plugin_dir_path(__FILE__)."sasoEventtickets_AdminSettings.php";
			}
			$this->ADMIN = new sasoEventtickets_AdminSettings($this);
		}
		return $this->ADMIN;
	}
	public function getFrontend() {
		if ($this->FRONTEND == null) {
			if (!class_exists('sasoEventtickets_Frontend')) {
				include_once plugin_dir_path(__FILE__)."sasoEventtickets_Frontend.php";
			}
			$this->FRONTEND = new sasoEventtickets_Frontend($this);
		}
		return $this->FRONTEND;
	}
	public function getOptions() {
		if ($this->OPTIONS == null) {
			if (!class_exists('sasoEventtickets_Options')) {
				include_once plugin_dir_path(__FILE__)."sasoEventtickets_Options.php";
			}
			$this->OPTIONS = new sasoEventtickets_Options($this, $this->_prefix);
			$this->OPTIONS->initOptions();
		}
		return $this->OPTIONS;
	}
	public function getNewPDFObject() {
		if (!class_exists('sasoEventtickets_PDF')) {
			require_once("sasoEventtickets_PDF.php");
		}
		$pdf = new sasoEventtickets_PDF();
		$pdf->setFontSize($this->getOptions()->getOptionValue('wcTicketPDFFontSize'));
		$pdf->setFontFamily($this->getOptions()->getOptionValue('wcTicketPDFFontFamily'));
		$pdf = apply_filters( $this->_add_filter_prefix.'main_getNewPDFObject', $pdf );
		return $pdf;
	}
	public function loadOnce($className, $filename="") {
		if (!class_exists($className)) {
			if ($filename == "") $filename = $className;
			include_once __DIR__.'/'.$filename.'.php';
		}
	}

	/**
	 * Load class from /includes/ folder structure
	 *
	 * @param string $className The class name to load
	 * @param string $relativePath Path relative to plugin root (e.g., 'includes/woocommerce/class-base.php')
	 * @return void
	 */
	private function loadClass(string $className, string $relativePath): void {
		if (class_exists($className)) {
			return; // Already loaded
		}

		$path = __DIR__ . '/' . $relativePath;

		if (file_exists($path)) {
			require_once $path;
		}
	}
	public function getWC() {
		$this->loadOnce('sasoEventtickets_WC', "woocommerce-hooks");
		return sasoEventtickets_WC::Instance();
	}
	public function getTicketHandler() {
		$this->loadOnce('sasoEventtickets_Ticket');
		return sasoEventtickets_Ticket::Instance($_SERVER["REQUEST_URI"]);
	}
	public function getTicketDesignerHandler($template="") {
		$this->loadOnce('sasoEventtickets_TicketDesigner');
		return sasoEventtickets_TicketDesigner::Instance($this, $template);
	}
	public function getTicketBadgeHandler() {
		$this->loadOnce('sasoEventtickets_TicketBadge');
		return sasoEventtickets_TicketBadge::Instance();
	}
	public function getTicketQRHandler() {
		$this->loadOnce('sasoEventtickets_TicketQR');
		return sasoEventtickets_TicketQR::Instance();
	}
	public function getAuthtokenHandler() {
		$this->loadOnce('sasoEventtickets_Authtoken');
		return sasoEventtickets_Authtoken::Instance();
	}
	public function getSeating() {
		$this->loadOnce('sasoEventtickets_Seating');
		return sasoEventtickets_Seating::Instance($this);
	}

	public function isOldPremiumDetected(): bool {
		return $this->_oldPremiumDetected;
	}

	public function isStarterOrStopDetected(): bool {
		return $this->_starterOrStopDetected;
	}

	/**
	 * After a valid license key is saved, automatically upgrade the premium
	 * plugin from starter/stop to the real premium version.
	 *
	 * Customers install the starter, enter their license, but don't manually
	 * click "Update" — they see expiration warnings and contact support.
	 * This triggers the upgrade in the background.
	 *
	 * Runs synchronously (takes ~5-10s) — acceptable since user just clicked Save.
	 * Errors are logged but not surfaced to the user.
	 */
	public function autoUpgradePremiumAfterLicenseSave(): void {
		try {
			$premFolder = trim($this->getPremiumPluginFolder(), '/');
			if (empty($premFolder)) return;

			// Find the premium plugin file in active_plugins
			$pluginFile = null;
			foreach (get_option('active_plugins', []) as $p) {
				if (strpos($p, $premFolder . '/') === 0) {
					$pluginFile = $p;
					break;
				}
			}
			if (empty($pluginFile)) return;

			// Force WP to re-check for plugin updates (clears PUC cache)
			delete_site_transient('update_plugins');
			delete_site_transient('puc_request_info_saso-event-tickets-with-ticket-scanner-premium');
			wp_update_plugins();

			// Check if an update is available
			$updates = get_site_transient('update_plugins');
			if (empty($updates->response[$pluginFile])) {
				return; // Already on latest — nothing to do
			}

			// Silently upgrade
			if (!class_exists('Plugin_Upgrader')) {
				require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
			}
			if (!class_exists('Automatic_Upgrader_Skin')) {
				require_once ABSPATH . 'wp-admin/includes/class-automatic-upgrader-skin.php';
			}
			$upgrader = new Plugin_Upgrader(new \Automatic_Upgrader_Skin());
			$upgrader->upgrade($pluginFile);
		} catch (\Throwable $e) {
			error_log('Event Tickets: auto-upgrade after license save failed: ' . $e->getMessage());
		}
	}

	public function getPremiumFunctions() {
		if ($this->_isPrem == null && $this->PREMFUNCTIONS == null) {
			$this->_isPrem = false;
			$this->PREMFUNCTIONS = new sasoEventtickets_fakeprem();

			// Check for Premium class - function-based compatibility check
			if (class_exists('sasoEventtickets_PremiumFunctions')) {
				// Check if this is Starter or Stop plugin (both are "update-only" placeholders)
				$is_starter_or_stop = defined('SASO_EVENTTICKETS_STARTER_VERSION') || defined('SASO_EVENTTICKETS_STOP_VERSION');

				if ($is_starter_or_stop) {
					// Starter/Stop plugin detected - treat as "premium not active"
					// Show message: Enter license, then update via plugin area
					$this->_starterOrStopDetected = true;
					$this->PREMFUNCTIONS = new sasoEventtickets_fakeprem();
				} elseif (!defined('SASO_EVENTTICKETS_PREMIUM_PLUGIN_VERSION')) {
					// Premium plugin exists but no version defined - likely very old or corrupted
					$this->_oldPremiumDetected = true;
				} else {
					// Compatibility check BEFORE instantiation — the premium constructor
					// registers 25+ WordPress hooks on $this. If we instantiate first and
					// discard the object afterwards, WP keeps the callbacks alive in memory
					// and fires them later, which crashes the site on old premiums that
					// reference methods removed in the WC manager refactor (2.7.x+).
					//
					// Use ReflectionClass to inspect methods WITHOUT running the constructor.
					$min_premium_version = '1.5.0';
					$version_too_old = version_compare(SASO_EVENTTICKETS_PREMIUM_PLUGIN_VERSION, $min_premium_version, '<');

					// Fingerprint methods added in Premium 1.5.0 — Premium 1.5.x still works
					// with the current basic because it only calls the deprecated proxies
					// getTicketsFromOrder() / add_serialcode_to_order(). 1.3.x / 1.4.x do
					// not have these methods and their hook callbacks crash later.
					$has_required_methods = false;
					try {
						$reflection = new ReflectionClass('sasoEventtickets_PremiumFunctions');
						$has_required_methods = $reflection->hasMethod('maxValues')
							&& $reflection->hasMethod('ticket_outputTicketInfo_template')
							&& $reflection->hasMethod('wc_order_add_meta_boxes');
					} catch (\Throwable $e) {
						// Reflection itself failed — treat as incompatible
						$has_required_methods = false;
					}

					if ($version_too_old || !$has_required_methods) {
						// Old premium detected — DO NOT instantiate. Instantiating would
						// register hooks that crash later when WooCommerce fires events.
						$this->_oldPremiumDetected = true;
						error_log('Event Tickets: Incompatible old premium detected (v' . SASO_EVENTTICKETS_PREMIUM_PLUGIN_VERSION . ') — not instantiated to prevent hook crashes.');
					} else {
						// Version and methods are OK — safe to instantiate.
						// Keep try/catch as safety net for unexpected constructor errors.
						try {
							$this->PREMFUNCTIONS = new sasoEventtickets_PremiumFunctions($this, plugin_dir_path(__FILE__), $this->_prefix, $this->getDB());
							$this->_isPrem = $this->PREMFUNCTIONS->isPremium();
						} catch (\Throwable $e) {
							$this->_oldPremiumDetected = true;
							$this->PREMFUNCTIONS = new sasoEventtickets_fakeprem();
							error_log('Event Tickets: Premium instantiation failed: ' . $e->getMessage());
						}
					}
				}
			} else {
				// Premium class not yet available — basic plugin loaded before premium.
				// Defer premium setup until all plugins are loaded (plugins_loaded hook).
				$premPluginFolder = $this->getPremiumPluginFolder();
				if (!empty($premPluginFolder)) {
					add_action('plugins_loaded', [$this, '_lateLoadPremium'], 1);
				}
			}
		}
		return $this->PREMFUNCTIONS;
	}
	/**
	 * Late-load premium plugin when basic loaded before premium (plugin loading order).
	 * Hooked to plugins_loaded with priority 1 so it runs before WooCommercePluginLoaded (priority 20).
	 */
	public function _lateLoadPremium(): void {
		if (!class_exists('sasoEventtickets_PremiumFunctions')) {
			return; // Premium plugin not installed/active
		}
		if ($this->PREMFUNCTIONS instanceof sasoEventtickets_PremiumFunctions) {
			return; // Already loaded
		}

		// Check for Starter/Stop plugin first (update-only placeholders, no premium features)
		if (defined('SASO_EVENTTICKETS_STARTER_VERSION') || defined('SASO_EVENTTICKETS_STOP_VERSION')) {
			$this->_starterOrStopDetected = true;
			return;
		}

		$min_premium_version = '1.5.0';
		if (!defined('SASO_EVENTTICKETS_PREMIUM_PLUGIN_VERSION')
			|| version_compare(SASO_EVENTTICKETS_PREMIUM_PLUGIN_VERSION, $min_premium_version, '<')) {
			$this->_oldPremiumDetected = true;
			return;
		}

		// Reflection-based method check BEFORE instantiation — same reason as in
		// getPremiumFunctions(): old premium constructors register hooks that crash
		// later if methods were removed in the WC manager refactor.
		// Fingerprint methods added in Premium 1.5.0.
		try {
			$reflection = new ReflectionClass('sasoEventtickets_PremiumFunctions');
			$has_required_methods = $reflection->hasMethod('maxValues')
				&& $reflection->hasMethod('ticket_outputTicketInfo_template')
				&& $reflection->hasMethod('wc_order_add_meta_boxes');
		} catch (\Throwable $e) {
			$has_required_methods = false;
		}
		if (!$has_required_methods) {
			$this->_oldPremiumDetected = true;
			error_log('Event Tickets: Incompatible old premium detected in late-load — not instantiated.');
			return;
		}

		try {
			$this->PREMFUNCTIONS = new sasoEventtickets_PremiumFunctions($this, plugin_dir_path(__FILE__), $this->_prefix, $this->getDB());
			$this->_isPrem = $this->PREMFUNCTIONS->isPremium();

			if (method_exists($this->PREMFUNCTIONS, 'initHandlers')) {
				$this->PREMFUNCTIONS->initHandlers();
			}
		} catch (\Throwable $e) {
			// Premium loading failed — degrade gracefully to free mode
			$this->PREMFUNCTIONS = new sasoEventtickets_fakeprem();
			$this->_isPrem = false;
			error_log('Event Tickets: Premium late-load failed: ' . $e->getMessage());
		}
	}

	public function getPremiumPluginFolder(): string {
		$plugins = get_option('active_plugins', []);
		$premiumFile = "";
		foreach($plugins as $plugin) {
			if (strpos(" ".$plugin, $this->_premium_plugin_name) > 0) {
				$premiumFile = plugin_dir_path($plugin);
				break;
			}
		}
		return $premiumFile;
	}
	public function isPremium() {
		if ($this->_isPrem === null) {
			$this->getPremiumFunctions();
			// If PREMFUNCTIONS already existed, getPremiumFunctions() skipped re-evaluation.
			// Re-query the premium plugin directly.
			if ($this->_isPrem === null && $this->PREMFUNCTIONS !== null) {
				$this->_isPrem = ($this->PREMFUNCTIONS instanceof sasoEventtickets_PremiumFunctions)
					? $this->PREMFUNCTIONS->isPremium()
					: false;
			}
		}

		// Eigene Verifikation: auch wenn Premium-Plugin "ja" sagt,
		// Basic Plugin prüft den gespeicherten Lizenzstatus selbst.
		// Rekursionsschutz: isPremium() -> getTicketHandler() -> Konstruktor -> getOptions() -> isPremium()
		if ($this->_isPrem === true && !$this->_isCheckingSubscription) {
			$this->_isCheckingSubscription = true;
			try {
				if (!$this->getTicketHandler()->isSubscriptionActive()) {
					$this->_isPrem = false;
				}
			} finally {
				$this->_isCheckingSubscription = false;
			}
		}

		return $this->_isPrem;
	}
	/**
	 * Invalidate the cached premium status so the next isPremium() call re-evaluates.
	 */
	public function invalidatePremiumCache(): void {
		$this->_isPrem = null;
		$this->PREMFUNCTIONS = null;
	}
	public function getPrefix() {
		return $this->_prefix;
	}
	public function getMV() {
		$v = ['storeip'=>false,'allowuserreg'=>false,'codes_total'=>0x13,'codes'=>0x12,'lists'=>5,'authtokens_total'=>3];
		$v["codes"] = (int) hexdec(0x80 / 0x002) / 2;
		$v["codes_total"] = (int) hexdec(0x80 / 0x002) / 2;
		$v["seatingplans"] = (int) (0x04 >> 0x02);
		$v["seats_per_plan"] = (int) (0x50 >> 0x02);
		return $v;
	}
	public function listener_upgrader_process_complete( $upgrader_object, $options ) {
		$current_plugin_path_name = plugin_basename( __FILE__ );
    	if ($options['action'] == 'update' && $options['type'] == 'plugin' ) {
			if (isset($options['plugins'])) {
				foreach($options['plugins'] as $each_plugin) {
					if ($each_plugin==$current_plugin_path_name) {
					// .......................... YOUR CODES .............
					}
				}
			}
    	}
		do_action( $this->_do_action_prefix.'main_listener_upgrader_process_complete' );
	}
	/**
	* check for ticket detail page request
	*/
	public function wc_checkTicketDetailPage() {
		if( is_404() ){
			include_once("SASO_EVENTTICKETS.php");
			// /wp-content/plugins/event-tickets-with-ticket-scanner/ticket/
			$p = $this->getCore()->getTicketURLPath(true);
			$t = explode("/", $_SERVER["REQUEST_URI"]);
			if (count($t) > 1) {
				if ($t[count($t)-2] != "scanner") {
					if(substr($_SERVER["REQUEST_URI"], 0, strlen($p)) == $p) {
						$this->getTicketHandler()->initFilterAndActions();
					} else {
						$wcTicketCompatibilityModeURLPath = trim($this->getOptions()->getOptionValue('wcTicketCompatibilityModeURLPath'));
						$wcTicketCompatibilityModeURLPath = trim(trim($wcTicketCompatibilityModeURLPath, "/"));
						if (!empty($wcTicketCompatibilityModeURLPath)) {
							$uri = trim($_SERVER["REQUEST_URI"]);
							if (!empty($uri)) {
								$pos = strpos($uri, $wcTicketCompatibilityModeURLPath);
								if ($pos > 0) {
									$this->getTicketHandler()->initFilterAndActions();
								}
							}
						}
					}
				}

				if ($t[count($t)-2] == "scanner") {
					if(substr($_SERVER["REQUEST_URI"], 0, strlen($p)) == $p) {
						//$this->replacingShortcodeTicketScanner();
						$this->getTicketHandler()->initFilterAndActionsTicketScanner();
					} else {
						$wcTicketCompatibilityModeURLPath = trim($this->getOptions()->getOptionValue('wcTicketCompatibilityModeURLPath'));
						$wcTicketCompatibilityModeURLPath = trim(trim($wcTicketCompatibilityModeURLPath, "/"));
						if (!empty($wcTicketCompatibilityModeURLPath)) {
							$uri = trim($_SERVER["REQUEST_URI"]);
							if (!empty($uri)) {
								$pos = strpos($_SERVER["REQUEST_URI"], $wcTicketCompatibilityModeURLPath."/scanner/");
								if ($pos > 0) {
									$this->getTicketHandler()->initFilterAndActionsTicketScanner();
								}
							}
						}
					}
				}
			}
		} // endif 404
	}
	private function init_frontend() {
		add_shortcode($this->_shortcode, [$this, 'replacingShortcode']);
		add_shortcode($this->_shortcode_mycode, [$this, 'replacingShortcodeMyCode']);
		add_shortcode($this->_shortcode_ticket_scanner, [$this, 'replacingShortcodeTicketScanner']);
		add_shortcode($this->_shortcode_eventviews, [$this, 'replacingShortcodeEventViews']);
		add_shortcode($this->_shortcode_feature_list, [$this, 'replacingShortcodeFeatureList']);
		add_shortcode($this->_shortcode_ticket_detail, [$this, 'replacingShortcodeTicketDetail']);
		do_action( $this->_do_action_prefix.'main_init_frontend' );
	}
	private function init_backend() {
		add_action('admin_menu', [$this, 'register_options_page']);
		register_activation_hook(__FILE__, [$this, 'plugin_activated']);
		register_deactivation_hook( __FILE__, [$this, 'plugin_deactivated'] );
		//register_uninstall_hook( __FILE__, 'sasoEventticketsDB::plugin_uninstall' );  // MUSS NOCH GETESTE WERDEN
		add_action( 'plugins_loaded', [$this, 'plugins_loaded'] );
		add_action( 'show_user_profile', [$this, 'show_user_profile'] );
		add_action( 'admin_init', [$this, 'handleFormatWarningDismiss'] );
		add_action( 'admin_notices', [$this, 'showSubscriptionWarning'] );
		add_action( 'admin_notices', [$this, 'showFomoBanner'] );
		add_action( 'admin_notices', [$this, 'showOutdatedPremiumWarning'] );
		add_action( 'admin_notices', [$this, 'showFormatWarning'] );
		add_action( 'admin_notices', [$this, 'showPhpVersionWarning'] );
		add_action( 'admin_notices', [$this, 'showOptionsMigrationNotice'] );
		add_action( 'wp_ajax_saso_et_dismiss_fomo', [$this, 'ajaxDismissFomo'] );

		if (basename($_SERVER['SCRIPT_NAME']) == "admin-ajax.php") {
			add_action('wp_ajax_'.$this->_prefix.'_executeAdminSettings', [$this,'executeAdminSettings_a'], 10, 0);
			add_action('wp_ajax_'.$this->_prefix.'_executeSeatingAdmin', [$this,'executeSeatingAdmin_a'], 10, 0);
		}

		add_action('admin_init', [$this, 'periodicLicenseCheck']);

		do_action( $this->_do_action_prefix.'main_init_backend' );
	}

	/**
	 * Periodic license check on admin page loads.
	 * Hard-throttled via site transient so that even if many admin requests come in
	 * (bots, health-checks, other plugins hammering admin-ajax) only one actual
	 * license call fires per interval.
	 */
	public function periodicLicenseCheck(): void {
		// Hard throttle: site transient = max 1 check per interval, independent of
		// option-based last_run throttling below. Prevents runaway requests when
		// admin pages are hit rapidly (monitoring tools, page builders, broken cron).
		$throttleKey = 'saso_et_periodic_license_check_lock';
		if (get_site_transient($throttleKey)) return;

		// Also run when premium plugin is installed with a serial key but isPremium() is false
		// This breaks the deadlock where isPremium()=false prevents the license check from ever running
		$hasPremiumPlugin = class_exists('sasoEventtickets_PremiumFunctions');
		$hasSerial = !empty(trim(get_option("saso-event-tickets-premium_serial", "")));
		if (!$this->isPremium() && !($hasPremiumPlugin && $hasSerial)) return;

		// Set throttle lock IMMEDIATELY — before any other work — so concurrent
		// admin_init calls bail out. Full interval even on recovery mode.
		$interval = $this->isPremium() ? 86400 : 3600;
		set_site_transient($throttleKey, time(), $interval);

		$info = $this->getTicketHandler()->get_expiration();
		$last_run = intval($info['last_run']);
		if ($last_run > 0 && (time() - $last_run) < $interval) return;

		// Check ausführen
		$this->getTicketHandler()->checkForPremiumSerialExpiration();

		// isPremium Cache invalidieren damit neuer Wert gilt
		$this->invalidatePremiumCache();
	}
	public function WooCommercePluginLoaded() {
		// DON'T load WC here - let relay functions do lazy loading
		//$this->getWC(); // um die wc handler zu laden
		add_action('woocommerce_review_order_after_cart_contents', [$this, 'relay_woocommerce_review_order_after_cart_contents']);
		add_action('woocommerce_checkout_process', [$this, 'relay_woocommerce_checkout_process']);
		add_action('woocommerce_before_cart_table', [$this, 'relay_woocommerce_before_cart_table']);
		add_action('woocommerce_cart_updated', [$this, 'relay_woocommerce_cart_updated']);
		add_filter('woocommerce_email_attachments', [$this, 'relay_woocommerce_email_attachments'], 10, 3);
		add_action('woocommerce_checkout_create_order_line_item', [$this, 'relay_woocommerce_checkout_create_order_line_item'], 20, 4 );
		add_action('woocommerce_check_cart_items', [$this, 'relay_woocommerce_check_cart_items'] );
		add_action('woocommerce_new_order', [$this, 'relay_woocommerce_new_order'], 10, 1);
		add_action('woocommerce_checkout_update_order_meta', [$this, 'relay_woocommerce_checkout_update_order_meta'], 20, 2);
		add_action('woocommerce_order_status_changed', [$this, 'relay_woocommerce_order_status_changed'], 10, 3);
		add_filter('woocommerce_order_item_display_meta_key', [$this, 'relay_woocommerce_order_item_display_meta_key'], 20, 3 );
		add_filter('woocommerce_order_item_display_meta_value', [$this, 'relay_woocommerce_order_item_display_meta_value'], 20, 3);
		add_action('wpo_wcpdf_after_item_meta', [$this, 'relay_wpo_wcpdf_after_item_meta'], 20, 3 );
		add_action('woocommerce_order_item_meta_start', [$this, 'relay_woocommerce_order_item_meta_start'], 201, 4);
		add_action('woocommerce_product_after_variable_attributes', [$this, 'relay_woocommerce_product_after_variable_attributes'], 10, 3);
		add_action('woocommerce_save_product_variation',[$this, 'relay_woocommerce_save_product_variation'], 10 ,2 );
		add_action('woocommerce_email_order_meta', [$this, 'relay_woocommerce_email_order_meta'], 10, 4 );
		add_action('woocommerce_thankyou', [$this, 'relay_woocommerce_thankyou'], 5);
		if (wp_doing_ajax()) {
			// erlaube ajax nonpriv und registriere handler
			add_action('wp_ajax_nopriv_'.$this->getPrefix().'_executeWCFrontend', [$this,'relay_executeWCFrontend']); // nicht angemeldete user, sollen eine antwort erhalten
			add_action('wp_ajax_'.$this->getPrefix().'_executeWCFrontend', [$this,'relay_executeWCFrontend']); // nicht angemeldete user, sollen eine antwort erhalten
			// Seating Frontend AJAX (seat selection in shop)
			add_action('wp_ajax_nopriv_'.$this->getPrefix().'_executeSeatingFrontend', [$this,'relay_executeSeatingFrontend']);
			add_action('wp_ajax_'.$this->getPrefix().'_executeSeatingFrontend', [$this,'relay_executeSeatingFrontend']);
		}
		if (is_admin()) {
			add_action('woocommerce_delete_order', [$this, 'relay_woocommerce_delete_order'], 10, 1 );
			add_action('woocommerce_delete_order_item', [$this, 'relay_woocommerce_delete_order_item'], 20, 1);
			add_action('woocommerce_pre_delete_order_refund', [$this, 'relay_woocommerce_pre_delete_order_refund'], 10, 3);
			add_action('woocommerce_delete_order_refund', [$this, 'relay_woocommerce_delete_order_refund'], 10, 1 );
			add_action('woocommerce_order_partially_refunded', [$this, 'relay_woocommerce_order_partially_refunded'], 10, 2);
			add_filter('woocommerce_product_data_tabs', [$this, 'relay_woocommerce_product_data_tabs'], 98 );
			add_action('woocommerce_product_data_panels', [$this, 'relay_woocommerce_product_data_panels'] );
			add_action('woocommerce_process_product_meta', [$this, 'relay_woocommerce_process_product_meta'], 10, 2 );
			add_action('add_meta_boxes', [$this, 'relay_add_meta_boxes'], 10, 2);
			add_filter('manage_edit-product_columns', [$this, 'relay_manage_edit_product_columns']);
			add_action('manage_product_posts_custom_column', [$this, 'relay_manage_product_posts_custom_column'], 2);
			add_filter("manage_edit-product_sortable_columns", [$this, 'relay_manage_edit_product_sortable_columns']);
		} else {
			add_action('woocommerce_single_product_summary', [$this, 'relay_woocommerce_single_product_summary']);
		}

		// set routing -- NEEDS to be replaced by add_rewrite_rule later
		add_action( 'template_redirect', [$this, 'wc_checkTicketDetailPage'], 1 );
		//$this->wc_checkTicketDetailPage();
		add_action('rest_api_init', function () {
			SASO_EVENTTICKETS::setRestRoutesTicket();
			// Vollstart Wallet REST API (only if enabled)
			if ($this->getOptions()->isOptionCheckboxActive('walletVollstartEnable')) {
				include_once plugin_dir_path(__FILE__) . 'includes/wallet/class-wallet-rest.php';
				$walletRest = new sasoEventtickets_Wallet_REST($this);
				$walletRest->register_routes();
				$walletRest->register_cors();
			}
		});

		add_action('woocommerce_after_shop_loop_item', [$this, 'relay_woocommerce_after_shop_loop_item'], 9); // with 9 we are just before the add to cart button
		add_filter('woocommerce_add_to_cart_validation', [$this, 'relay_woocommerce_add_to_cart_validation'], 10, 3);
		add_filter('woocommerce_add_cart_item_data', [$this, 'relay_woocommerce_add_cart_item_data'], 10, 3);
		add_action('woocommerce_add_to_cart', [$this, 'relay_woocommerce_add_to_cart'], 10, 6);
		add_action('woocommerce_cart_item_removed', [$this, 'relay_woocommerce_cart_item_removed'], 10, 2);
		add_action('woocommerce_after_cart_item_quantity_update', [$this, 'relay_woocommerce_after_cart_item_quantity_update'], 10, 4);
		add_filter('woocommerce_update_cart_validation', [$this, 'relay_woocommerce_update_cart_validation'], 10, 4);
		add_action('woocommerce_before_add_to_cart_button', [$this, 'relay_woocommerce_before_add_to_cart_button'], 15);

		// Vollstart Wallet: "Add to Wallet" button on ticket detail page
		add_action($this->_do_action_prefix . 'ticket_outputTicketInfo_after', [$this, 'wallet_render_ticket_button'], 10, 2);

		// Vollstart Wallet: link in order emails
		add_action($this->_do_action_prefix . 'woocommerce-hooks_woocommerce_email_order_meta', [$this, 'wallet_render_email_link'], 10, 4);

		do_action( $this->_do_action_prefix.'main_WooCommercePluginLoaded' );
	}

	/**
	 * Render the "Add to Vollstart Wallet" button on the ticket detail page.
	 */
	public function wallet_render_ticket_button(array $codeObj, bool $forPDFOutput): void {
		if ($forPDFOutput) return;
		if (!$this->getOptions()->isOptionCheckboxActive('walletVollstartEnable')) return;

		$codeObj = $this->getCore()->setMetaObj($codeObj);
		$metaObj = $codeObj['metaObj'];
		$walletUrl = $metaObj['wc_ticket']['_wallet_url'] ?? '';
		if (empty($walletUrl)) return;

		echo '<p style="text-align:center;margin-top:10px;">';
		echo '<a class="button" href="' . esc_url($walletUrl) . '" target="_blank" rel="noopener">';
		echo esc_html__('Add to Vollstart Wallet', 'event-tickets-with-ticket-scanner');
		echo '</a>';
		echo '</p>';
	}

	/**
	 * Render Vollstart Wallet links in WooCommerce order emails.
	 */
	public function wallet_render_email_link($order, bool $sent_to_admin, bool $plain_text, $email): void {
		if ($sent_to_admin) return;
		if (!$this->getOptions()->isOptionCheckboxActive('walletVollstartEnable')) return;

		$codes = $this->getCore()->getCodesByOrderId($order->get_id());
		if (empty($codes)) return;

		$walletLinks = [];
		foreach ($codes as $codeRow) {
			try {
				$codeObj = $this->getCore()->retrieveCodeByCode($codeRow['code']);
				$codeObj = $this->getCore()->setMetaObj($codeObj);
				$metaObj = $codeObj['metaObj'];
				if (intval($metaObj['wc_ticket']['is_ticket'] ?? 0) !== 1) continue;
				$walletUrl = $metaObj['wc_ticket']['_wallet_url'] ?? '';
				if (empty($walletUrl)) continue;

				$walletLinks[] = $walletUrl;
			} catch (\Exception $e) {
				continue;
			}
		}

		if (empty($walletLinks)) return;

		if ($plain_text) {
			echo "\n" . __('Add to Vollstart Wallet', 'event-tickets-with-ticket-scanner') . ":\n";
			foreach ($walletLinks as $url) {
				echo $url . "\n";
			}
		} else {
			echo '<p><b>' . esc_html__('Add to Vollstart Wallet', 'event-tickets-with-ticket-scanner') . '</b></p>';
			foreach ($walletLinks as $url) {
				echo '<p><a href="' . esc_url($url) . '" target="_blank" rel="noopener">' . esc_html__('Add to Wallet', 'event-tickets-with-ticket-scanner') . '</a></p>';
			}
		}
	}

	public function relay_woocommerce_after_shop_loop_item() {
		$this->getWC()->getFrontendManager()->woocommerce_after_shop_loop_item_handler();
	}
	public function relay_woocommerce_add_to_cart_validation() {
		$args = func_get_args();
		return $this->getWC()->getFrontendManager()->woocommerce_add_to_cart_validation_handler(...$args);
	}
	public function relay_woocommerce_add_cart_item_data() {
		$args = func_get_args();
		return $this->getWC()->getFrontendManager()->woocommerce_add_cart_item_data_handler(...$args);
	}
	public function relay_woocommerce_add_to_cart() {
		$args = func_get_args();
		return $this->getWC()->getFrontendManager()->woocommerce_add_to_cart_handler(...$args);
	}
	public function relay_woocommerce_cart_item_removed() {
		$args = func_get_args();
		$this->getWC()->getFrontendManager()->woocommerce_cart_item_removed_handler(...$args);
	}
	public function relay_woocommerce_after_cart_item_quantity_update() {
		$args = func_get_args();
		$this->getWC()->getFrontendManager()->woocommerce_after_cart_item_quantity_update_handler(...$args);
	}
	public function relay_woocommerce_update_cart_validation() {
		$args = func_get_args();
		return $this->getWC()->getFrontendManager()->woocommerce_update_cart_validation_handler(...$args);
	}
	public function relay_woocommerce_before_add_to_cart_button() {
		$this->getWC()->getFrontendManager()->woocommerce_before_add_to_cart_button_handler();
	}
	public function relay_woocommerce_review_order_after_cart_contents() {
		$this->getWC()->getFrontendManager()->woocommerce_review_order_after_cart_contents();
	}
	public function relay_woocommerce_checkout_process() {
		$this->getWC()->getFrontendManager()->woocommerce_checkout_process();
	}
	public function relay_woocommerce_before_cart_table() {
		$this->getWC()->getFrontendManager()->woocommerce_before_cart_table();
	}
	public function relay_woocommerce_cart_updated() {
		$this->getWC()->getFrontendManager()->woocommerce_cart_updated_handler();
	}
	public function relay_woocommerce_email_attachments() {
		$args = func_get_args();
		return $this->getWC()->getEmailHandler()->woocommerce_email_attachments(...$args);
	}
	public function relay_woocommerce_checkout_create_order_line_item() {
		$args = func_get_args();
		return $this->getWC()->getOrderManager()->woocommerce_checkout_create_order_line_item(...$args);
	}
	public function relay_woocommerce_check_cart_items() {
		$this->getWC()->getFrontendManager()->woocommerce_check_cart_items();
	}
	public function relay_woocommerce_new_order() {
		$args = func_get_args();
		return $this->getWC()->getOrderManager()->woocommerce_new_order(...$args);
	}
	public function relay_woocommerce_checkout_update_order_meta() {
		$args = func_get_args();
		return $this->getWC()->getOrderManager()->woocommerce_checkout_update_order_meta(...$args);
	}
	public function relay_executeWCFrontend() {
		return $this->getWC()->getFrontendManager()->executeWCFrontend();
	}
	public function relay_executeSeatingFrontend() {
		return $this->getSeating()->getFrontendManager()->executeSeatingFrontend();
	}
	public function relay_woocommerce_delete_order() {
		$args = func_get_args();
		$this->getWC()->getOrderManager()->woocommerce_delete_order(...$args);
	}
	public function relay_woocommerce_delete_order_item() {
		$args = func_get_args();
		$this->getWC()->getOrderManager()->woocommerce_delete_order_item(...$args);
	}
	public function relay_woocommerce_pre_delete_order_refund() {
		$args = func_get_args();
		$this->getWC()->getOrderManager()->woocommerce_pre_delete_order_refund(...$args);
	}
	public function relay_woocommerce_delete_order_refund() {
		$args = func_get_args();
		$this->getWC()->getOrderManager()->woocommerce_delete_order_refund(...$args);
	}
	public function relay_woocommerce_product_data_tabs() {
		$args = func_get_args();
		return $this->getWC()->getProductManager()->woocommerce_product_data_tabs(...$args);
	}
	public function relay_woocommerce_product_data_panels() {
		$this->getWC()->getProductManager()->woocommerce_product_data_panels();
	}
	public function relay_woocommerce_process_product_meta() {
		$args = func_get_args();
		$this->getWC()->getProductManager()->woocommerce_process_product_meta(...$args);
	}
	public function relay_add_meta_boxes(...$args) {
		$this->getWC()->add_meta_boxes(...$args);
	}
	public function relay_manage_edit_product_columns() {
		$args = func_get_args();
		return $this->getWC()->getProductManager()->manage_edit_product_columns(...$args);
	}
	public function relay_manage_product_posts_custom_column() {
		$args = func_get_args();
		$this->getWC()->getProductManager()->manage_product_posts_custom_column(...$args);
	}
	public function relay_manage_edit_product_sortable_columns() {
		$args = func_get_args();
		return $this->getWC()->getProductManager()->manage_edit_product_sortable_columns(...$args);
	}
	public function relay_woocommerce_single_product_summary() {
		$this->getWC()->getFrontendManager()->woocommerce_single_product_summary();
	}
	public function relay_woocommerce_order_status_changed() {
		$args = func_get_args();
		$this->getWC()->getOrderManager()->woocommerce_order_status_changed(...$args);
	}
	public function relay_woocommerce_order_partially_refunded() {
		$args = func_get_args();
		$this->getWC()->getOrderManager()->woocommerce_order_partially_refunded(...$args);
	}
	public function relay_woocommerce_order_item_display_meta_key() {
		$args = func_get_args();
		return $this->getWC()->getOrderManager()->woocommerce_order_item_display_meta_key(...$args);
	}
	public function relay_woocommerce_order_item_display_meta_value() {
		$args = func_get_args();
		return $this->getWC()->getOrderManager()->woocommerce_order_item_display_meta_value(...$args);
	}
	public function relay_wpo_wcpdf_after_item_meta() {
		$args = func_get_args();
		$this->getWC()->getOrderManager()->wpo_wcpdf_after_item_meta(...$args);
	}
	public function relay_woocommerce_order_item_meta_start() {
		$args = func_get_args();
		$this->getWC()->getOrderManager()->woocommerce_order_item_meta_start(...$args);
	}
	public function relay_woocommerce_product_after_variable_attributes() {
		$args = func_get_args();
		$this->getWC()->getProductManager()->woocommerce_product_after_variable_attributes(...$args);
	}
	public function relay_woocommerce_save_product_variation() {
		$args = func_get_args();
		$this->getWC()->getProductManager()->woocommerce_save_product_variation(...$args);
	}
	public function relay_woocommerce_email_order_meta() {
		$args = func_get_args();
		$this->getWC()->getEmailHandler()->woocommerce_email_order_meta(...$args);
	}
	public function relay_woocommerce_thankyou() {
		$args = func_get_args();
		$this->getWC()->getFrontendManager()->woocommerce_thankyou(...$args);
	}
	public function relay_sasoEventtickets_cronjob_daily() {
		$this->getTicketHandler()->cronJobDaily();
		$this->getAdmin()->cleanupOptionsHistory();
	}

	public function plugin_deactivated() {
		$this->cronjob_daily_deactivate();
		$this->getDB();
		sasoEventticketsDB::plugin_deactivated();
		do_action( $this->_do_action_prefix.'main_plugin_deactivated' );
	}
	public function plugin_uninstall() {
		$this->getDB();
    	sasoEventticketsDB::plugin_uninstall();
		$this->getAdmin();
		sasoEventtickets_AdminSettings::plugin_uninstall();
		do_action( $this->_do_action_prefix.'main_WooCommercePluginLoaded' );
	}
	public function plugin_activated($is_network_wide=false) { // und auch für updates, macht es einfacher
		$this->getDB(); // um installiere Tabellen auszuführen
    	update_option('SASO_EVENTTICKETS_PLUGIN_VERSION', SASO_EVENTTICKETS_PLUGIN_VERSION);
		// Only reset migration flag if options exist in wp_options (downgrade scenario).
		// Do NOT blindly delete — the migration already removed options from wp_options,
		// so deleting the flag would cause one request to read empty wp_options → all defaults.
		$sentinelKey = $this->_prefix . 'qrAttachQRFilesToMailAsOnePDF';
		if (get_option($sentinelKey, '__NOT_SET__') !== '__NOT_SET__') {
			delete_option('saso_eventtickets_options_migrated');
		}
		$this->getAdmin()->generateFirstCodeList();
		$this->cronjob_daily_activate();
		do_action( $this->_do_action_prefix.'activated' );
		do_action( $this->_do_action_prefix.'main_plugin_activated' );
	}
	public function plugins_loaded() {
		if (SASO_EVENTTICKETS_PLUGIN_VERSION !== get_option('SASO_EVENTTICKETS_PLUGIN_VERSION', '')) $this->plugin_activated(); // vermutlich wurde die aktivierung übersprungen, bei änderungen direkt an den files
	}
    public function initialize_plugin() {
		$this->getDB(); // um installiere Tabellen auszuführen
		do_action( $this->_do_action_prefix.'initialized' );
		do_action( $this->_do_action_prefix.'main_initialize_plugin' );
    }
	function show_user_profile($profileuser) {
		$this->getAdmin()->show_user_profile($profileuser);
		do_action( $this->_do_action_prefix.'main_show_user_profile' );
	}
	function register_options_page() {
		$allowed = $this->isUserAllowedToAccessAdminArea();
		$allowed = apply_filters( $this->_add_filter_prefix.'main_options_page', $allowed );
		if ($allowed) {
			//add_options_page(__('Event Tickets', 'event-tickets-with-ticket-scanner'), 'Event Tickets', 'manage_options', 'event-tickets-with-ticket-scanner', [$this,'options_page']);
			add_menu_page( __('Event Tickets', 'event-tickets-with-ticket-scanner'), 'Event Tickets', 'read', 'event-tickets-with-ticket-scanner', [$this,'options_page'], plugins_url( "",__FILE__ )."/img/icon_event-tickets-with-ticket-scanner_18px.gif", null );
		}
		do_action( $this->_do_action_prefix.'main_register_options_page' );
	}

	function options_page() {
		$allowed = $this->isUserAllowedToAccessAdminArea();
		$allowed = apply_filters( $this->_add_filter_prefix.'main_options_page', $allowed );
		if ( !$allowed )  {
			wp_die( __( 'You do not have sufficient permissions to access this page.', 'event-tickets-with-ticket-scanner' ) );
		}

		wp_enqueue_style("wp-jquery-ui-dialog");
		wp_enqueue_style(
			'event-tickets-backend',
			plugins_url('css/styles_backend.css', __FILE__),
			array(),
			$this->getPluginVersion()
		);

		$js_url = "jquery.qrcode.min.js?_v=".$this->_js_version;
		wp_register_script('ajax_script2', plugins_url( "3rd/".$js_url,__FILE__ ), array('jquery', 'jquery-ui-dialog'));
		wp_enqueue_script('ajax_script2');

		wp_enqueue_media(); // um die js wp.media lib zu laden

		// einbinden das js starter skript
		$js_url = $this->_js_file."?_v=".$this->_js_version;
		if (defined( 'WP_DEBUG')) $js_url .= '&debug=1';
		wp_register_script('ajax_script_backend', plugins_url( $js_url,__FILE__ ), array('jquery', 'jquery-ui-dialog', 'wp-i18n'));
        wp_enqueue_script('ajax_script_backend');
		wp_set_script_translations('ajax_script_backend', 'event-tickets-with-ticket-scanner', __DIR__.'/languages');

		// per script eine variable einbinden, die url hat den wp-admin prefix
		// damit im backend.js dann die richtige callback url genutzt werden kann
		$vars = array(
			'_plugin_home_url' =>plugins_url( "",__FILE__ ),
			'_plugin_version' => $this->getPluginVersion(),
			'_action' => $this->_prefix.'_executeAdminSettings',
			'_max'=>$this->getBase()->getMaxValues(),
			'_isPremium'=>$this->isPremium(),
			'_isUserLoggedin'=>is_user_logged_in(),
			'_premJS'=>$this->isPremium() && method_exists($this->getPremiumFunctions(), "getJSBackendFile") ? $this->getPremiumFunctions()->getJSBackendFile() : '',
			'url'   => admin_url( 'admin-ajax.php' ),
			'ticket_url' => $this->getCore()->getTicketURLPath(),
			'nonce' => wp_create_nonce( $this->_js_nonce ),
			'ajaxActionPrefix' => $this->_prefix,
			'divPrefix' => $this->_prefix,
			'divId' => $this->_divId,
			'jsFiles' => plugins_url( 'backend.js?_v='.$this->_js_version.'&_f='.filemtime(__DIR__.'/backend.js'),__FILE__ )
		);
		// Version notices for "What's New" banner
		$versionNoticesFile = __DIR__ . '/version-notices.json';
		$vars['_versionNotices'] = file_exists($versionNoticesFile) ? json_decode(file_get_contents($versionNoticesFile), true) : [];

		$vars = apply_filters( $this->_add_filter_prefix.'main_options_page', $vars );
        wp_localize_script(
            'ajax_script_backend',
            'Ajax_'.$this->_prefix, // name der injected variable
            $vars
        );

		do_action( $this->_do_action_prefix.'main_options_page' );

		$versions = $this->getPluginVersions();
		$versions_tail = $versions['basic'].($versions['premium'] != "" ? ', Premium: '.$versions['premium'] : '');
		$version_tail_add = "";
		if ($versions['debug'] != "") $version_tail_add .= 'DEBUG: '.$versions['debug'].', LANG: '.determine_locale();
		?>
		<style>.event-tickets-with-ticket-scanner-admin-page{opacity:0;transition:opacity .3s ease}.event-tickets-with-ticket-scanner-admin-page.et-ready{opacity:1}</style>
		<div class="event-tickets-with-ticket-scanner-admin-page">
			<div class="event-tickets-with-ticket-scanner-header">
				<div class="event-tickets-with-ticket-scanner-header-left">
					<img src="<?php echo plugins_url( "",__FILE__ ); ?>/img/logo_event-tickets-with-ticket-scanner.gif"
						alt="Event Tickets"
						class="event-tickets-with-ticket-scanner-header-logo">

					<div class="event-tickets-with-ticket-scanner-header-title">
						<div class="event-tickets-with-ticket-scanner-header-name">
							Event Tickets with Ticket Scanner
						</div>
						<div class="event-tickets-with-ticket-scanner-header-meta">
							<?php esc_html_e('Version', 'event-tickets-with-ticket-scanner'); ?>: <?php echo $versions_tail; ?> <?php echo $version_tail_add; ?>
						</div>
					</div>
				</div>

				<div class="event-tickets-with-ticket-scanner-header-right" id="event-tickets-with-ticket-scanner-header-actions">
					<!-- Button kommt via JS -->
				</div>
			</div>

			<div style="clear:both;" data-id="plugin_addons"></div>
			<div style="clear:both;" data-id="plugin_info_area"></div>
			<div style="clear:both;" id="<?php echo esc_attr($this->_divId); ?>" class="et-content-area">
				<div class="et-loading"><span class="lds-dual-ring"></span></div>
			</div>
			<div class="et-footer">
				<a name="shortcodedetails"></a>
				<div class="et-footer-grid">
					<div class="et-card et-footer-card">
						<div class="et-card-header">
							<span class="dashicons dashicons-book" style="color:#9333ea;margin-right:6px;"></span>
							Documentation
						</div>
						<p><a href="https://vollstart.com/event-tickets-with-ticket-scanner/docs/" target="_blank"><?php esc_html_e('Click here, to visit the documentation of this plugin.', 'event-tickets-with-ticket-scanner'); ?></a></p>
						<p style="margin-top:12px;"><?php esc_html_e('You can use this plugin to sell tickets and even redeem them. Check out the documentation for', 'event-tickets-with-ticket-scanner'); ?> <a target="_blank" href="https://vollstart.com/event-tickets-with-ticket-scanner/docs/#ticket"><?php esc_html_e('more details here', 'event-tickets-with-ticket-scanner'); ?></a>.</p>
					</div>
					<div class="et-card et-footer-card">
						<div class="et-card-header">
							<span class="dashicons dashicons-star-filled" style="color:#f59e0b;margin-right:6px;"></span>
							<?php esc_html_e('Plugin Rating', 'event-tickets-with-ticket-scanner'); ?>
						</div>
						<p><?php esc_html_e('If you like our plugin, then please give us a', 'event-tickets-with-ticket-scanner'); ?> <a target="_blank" href="https://wordpress.org/support/plugin/event-tickets-with-ticket-scanner/reviews?rate=5#new-post">5-Star Rating</a>.</p>
					</div>
					<div class="et-card et-footer-card">
						<div class="et-card-header">
							<span class="dashicons dashicons-superhero" style="color:#9333ea;margin-right:6px;"></span>
							<?php esc_html_e('Premium Homepage', 'event-tickets-with-ticket-scanner'); ?>
						</div>
						<p><?php esc_html_e('You can find more details about the', 'event-tickets-with-ticket-scanner'); ?> <a target="_blank" href="https://vollstart.com/event-tickets-with-ticket-scanner/"><?php esc_html_e('premium version here', 'event-tickets-with-ticket-scanner'); ?></a>.</p>
					</div>
				</div>

				<div class="et-card et-footer-shortcodes">
					<div class="et-card-header">
						<span class="dashicons dashicons-shortcode" style="color:#9333ea;margin-right:6px;"></span>
						<?php esc_html_e('Shortcodes', 'event-tickets-with-ticket-scanner'); ?>
					</div>

					<h3><?php esc_html_e('Shortcode to display the event calendar form within a page', 'event-tickets-with-ticket-scanner'); ?></h3>
					<b>[<?php echo esc_html($this->_shortcode_eventviews); ?>]</b>
					<p><?php esc_html_e('The event calendar form will be displayed. You can add the following parameters to change the output:', 'event-tickets-with-ticket-scanner'); ?></p>
					<ul>
						<li>months_to_show - Values can be a number higher than 0. Default: 3</li>
					</ul>
					<p>CSS file: <a href="<?php echo plugins_url( "",__FILE__ ); ?>/css/calendar.css" target="_blank">calendar.css</a></p>

					<h3><?php esc_html_e('Shortcode to display the assigned tickets and codes of an user within a page', 'event-tickets-with-ticket-scanner'); ?></h3>
					<b>[<?php echo esc_html($this->_shortcode_mycode); ?>]</b>
					<p><?php esc_html_e('Displays tickets assigned to the current logged-in user (default) or tickets from a specific order.', 'event-tickets-with-ticket-scanner'); ?></p>
					<ul>
						<li><b>order_id</b> - <?php esc_html_e('Show tickets from a specific order instead of user tickets. Security: User must own the order or have valid order key in URL.', 'event-tickets-with-ticket-scanner'); ?><br>
						<?php esc_html_e('Example:', 'event-tickets-with-ticket-scanner'); ?> [<?php echo esc_html($this->_shortcode_mycode); ?> order_id="123"]</li>
						<li><b>format</b> - <?php esc_html_e('Output format. Values: json', 'event-tickets-with-ticket-scanner'); ?></li>
						<li><b>display</b> - <?php esc_html_e('Fields to show (comma-separated). Values: codes, validation, user, used, confirmedCount, woocommerce, wc_rp, wc_ticket', 'event-tickets-with-ticket-scanner'); ?></li>
						<li><b>download_all_pdf</b> - <?php esc_html_e('Show download button for all tickets as one PDF. Values: true/false', 'event-tickets-with-ticket-scanner'); ?></li>
					</ul>
					<p>
						<?php esc_html_e('Example with JSON output:', 'event-tickets-with-ticket-scanner'); ?> [<?php echo esc_html($this->_shortcode_mycode); ?> format="json" display="code,wc_ticket"]
					</p>

					<h3><?php esc_html_e('Shortcode to display the ticket scanner within a page', 'event-tickets-with-ticket-scanner'); ?></h3>
					<?php esc_html_e('Useful if you cannot open the ticket scanner due to security issues.', 'event-tickets-with-ticket-scanner'); ?><br>
					<b>[<?php echo esc_html($this->_shortcode_ticket_scanner); ?>]</b>

					<h3><?php esc_html_e('Shortcode to display ticket detail view within a page', 'event-tickets-with-ticket-scanner'); ?></h3>
					<?php esc_html_e('Useful if the /ticket/ URL path does not work on your server.', 'event-tickets-with-ticket-scanner'); ?><br>
					<b>[<?php echo esc_html($this->_shortcode_ticket_detail); ?>]</b>
					<p>
						<?php esc_html_e('Usage: Add the shortcode to a page and access it with ?ticket=YOUR-TICKET-CODE in the URL.', 'event-tickets-with-ticket-scanner'); ?><br>
						<?php esc_html_e('Example:', 'event-tickets-with-ticket-scanner'); ?> yoursite.com/ticket-page/?ticket=ABC-123-XYZ<br>
						<?php esc_html_e('Or use the code attribute:', 'event-tickets-with-ticket-scanner'); ?> [<?php echo esc_html($this->_shortcode_ticket_detail); ?> code="ABC-123-XYZ"]
					</p>

					<h3><?php esc_html_e('PHP Filters', 'event-tickets-with-ticket-scanner'); ?></h3>
					<p><?php esc_html_e('You can use PHP code to register your filter functions for the validation check.', 'event-tickets-with-ticket-scanner'); ?>
					<a href="https://vollstart.com/event-tickets-with-ticket-scanner/docs/#filters" target="_blank"><?php esc_html_e('Click here for more help about the functions', 'event-tickets-with-ticket-scanner'); ?></a>
					</p>
					<ul>
						<li>add_filter('<?php echo $this->_add_filter_prefix.'beforeCheckCodePre'; ?>', 'myfunc', 20, 1)</li>
						<li>add_filter('<?php echo $this->_add_filter_prefix.'beforeCheckCode'; ?>', 'myfunc', 20, 1)</li>
						<li>add_filter('<?php echo $this->_add_filter_prefix.'afterCheckCodePre'; ?>', 'myfunc', 20, 1)</li>
						<li>add_filter('<?php echo $this->_add_filter_prefix.'afterCheckCode'; ?>', 'myfunc', 20, 1)</li>
					</ul>
					<p>More BETA filters and actions hooks can be found <a href="https://vollstart.com/event-tickets-with-ticket-scanner/docs/ticket-plugin-api/" target="_blank">here (NOT STABLE, be aware that they might be changed in the future)</a>.</p>
				</div>

				<div class="et-footer-credits">
					<a target="_blank" href="https://vollstart.com">VOLLSTART</a> &middot; More plugins: <a target="_blank" href="https://wordpress.org/plugins/serial-codes-generator-and-validator/">Serial Code Validator</a>
				</div>
			</div>
	  	</div>
		<?php
		do_action( $this->_do_action_prefix.'options_page' );
	}

	public function isUserAllowedToAccessAdminArea() {
		if ($this->isAllowedAccess != null) return $this->isAllowedAccess;
		if ($this->getOptions()->isOptionCheckboxActive('allowOnlySepcificRoleAccessToAdmin')) {
			// check welche rollen
			$user = wp_get_current_user();
			$user_roles = (array) $user->roles;
			if (in_array("administrator", $user_roles)) {
				$this->isAllowedAccess = true;
			} else {
				$adminAreaAllowedRoles = $this->getOptions()->getOptionValue('adminAreaAllowedRoles');
				if (!is_array($adminAreaAllowedRoles)) {
					if (empty($adminAreaAllowedRoles)) {
						$adminAreaAllowedRoles = [];
					} else {
						$adminAreaAllowedRoles = [$adminAreaAllowedRoles];
					}
				}
				foreach($adminAreaAllowedRoles as $role_name) {
					if (in_array($role_name, $user_roles)) {
						$this->isAllowedAccess = true;
						break;
					};
				}
			}
		} else {
			// Standard: Only administrators have access
			$this->isAllowedAccess = current_user_can('manage_options');
		}
		$this->isAllowedAccess = apply_filters( $this->_add_filter_prefix.'main_isUserAllowedToAccessAdminArea', $this->isAllowedAccess );
		return $this->isAllowedAccess;
	}

	public function executeAdminSettings_a() {
		if (!SASO_EVENTTICKETS::issetRPara('a_sngmbh')) return wp_send_json_success("a_sngmbh not provided");
		return $this->executeAdminSettings(SASO_EVENTTICKETS::getRequestPara('a_sngmbh')); // to prevent WP adds parameters
	}

	public function executeAdminSettings($a=0, $data=null) {
		if (!$this->isUserAllowedToAccessAdminArea()) {
			return wp_send_json_error("Access denied", 403);
		}
		if ($a === 0 && !SASO_EVENTTICKETS::issetRPara('a_sngmbh')) return wp_send_json_success("a not provided");

		if ($data == null) {
			$data = SASO_EVENTTICKETS::issetRPara('data') ? SASO_EVENTTICKETS::getRequestPara('data') : [];
		}
		if ($a === 0 || empty($a) || trim($a) == "") {
			$a = SASO_EVENTTICKETS::getRequestPara('a_sngmbh');
		}
		do_action( $this->_do_action_prefix.'executeAdminSettings', $a, $data );
		return $this->getAdmin()->executeJSON($a, $data, false, false); // with nonce check
	}

	public function executeSeatingAdmin_a() {
		return $this->executeSeatingAdmin(SASO_EVENTTICKETS::getRequestPara('a'));
	}

	public function executeSeatingAdmin($a = '', $data = null) {
		if (!$this->isUserAllowedToAccessAdminArea()) {
			return wp_send_json_error('Access denied', 403);
		}
		if (empty($a) && !SASO_EVENTTICKETS::issetRPara('a')) {
			return wp_send_json_error('a not provided');
		}
		if ($data === null) {
			$data = SASO_EVENTTICKETS::getRequest();
		}
		if (empty($a)) {
			$a = SASO_EVENTTICKETS::getRequestPara('a');
		}
		return $this->getSeating()->getAdminHandler()->executeSeatingJSON($a, $data);
	}

	public function executeFrontend_a() {
		return $this->executeFrontend(); // to prevent WP adds parameters
	}

	public function executeWCBackend() {
		if (!$this->isUserAllowedToAccessAdminArea()) {
			return wp_send_json_error("Access denied", 403);
		}
		if (!SASO_EVENTTICKETS::issetRPara('a_sngmbh')) return wp_send_json_success("a_sngmbh not provided");
		$data = SASO_EVENTTICKETS::issetRPara('data') ? SASO_EVENTTICKETS::getRequestPara('data') : [];
		return $this->getWC()->executeJSON(SASO_EVENTTICKETS::getRequestPara('a_sngmbh'), $data);
	}

	public function executeFrontend($a=0, $data=null) {
		$sasoEventtickets_Frontend = $this->getFrontend();
		if ($a === 0 && !SASO_EVENTTICKETS::issetRPara('a_sngmbh')) return wp_send_json_success("a not provided");

		if ($data == null) {
			$data = SASO_EVENTTICKETS::issetRPara('data') ? SASO_EVENTTICKETS::getRequestPara('data') : [];
		}
		if ($a === 0 || empty($a) || trim($a) == "") {
			$a = SASO_EVENTTICKETS::getRequestPara('a_sngmbh');
		}
		do_action( $this->_do_action_prefix.'executeFrontend', $a, $data );
		return $sasoEventtickets_Frontend->executeJSON($a, $data);
	}

	public function replacingShortcode($attr=[], $content = null, $tag = '') {
		add_filter( $this->_add_filter_prefix.'replaceShortcode', [$this, 'replaceShortcode'], 10, 3 );
		$ret = apply_filters( $this->_add_filter_prefix.'replaceShortcode', $attr, $content, $tag );
		return $ret;
	}

	public function setTicketScannerJS() {
		wp_enqueue_style("wp-jquery-ui-dialog");

		$js_url = "jquery.qrcode.min.js?_v=".$this->getPluginVersion();
		wp_enqueue_script(
			'ajax_script2',
			plugins_url( "3rd/".$js_url,__FILE__ ),
			array('jquery', 'jquery-ui-dialog')
		);

		$js_url = plugin_dir_url(__FILE__)."3rd/html5-qrcode.min.js?_v=".$this->getPluginVersion();
		wp_register_script('html5-qrcode', $js_url, array('jquery', 'jquery-ui-dialog'));
		wp_enqueue_script('html5-qrcode');

		// https://github.com/nimiq/qr-scanner - NEW scanner lib
		$js_url = plugin_dir_url(__FILE__)."3rd/qr-scanner-1.4.2/qr-scanner.umd.min.js?_v=".$this->getPluginVersion();
		wp_register_script('qr-scanner', $js_url, array('jquery', 'jquery-ui-dialog'));
		wp_enqueue_script('qr-scanner');

		$js_url = "ticket_scanner.js?_v=".$this->getPluginVersion();
		if (defined('WP_DEBUG')) $js_url .= '&t='.time();
		$js_url = plugins_url( $js_url,__FILE__ );
		wp_register_script('ajax_script_ticket_scanner', $js_url, array('jquery', 'jquery-ui-dialog', 'wp-i18n'));
		wp_enqueue_script('ajax_script_ticket_scanner');
		wp_set_script_translations('ajax_script_ticket_scanner', 'event-tickets-with-ticket-scanner', __DIR__.'/languages');

		$ticketScannerDontRememberCamChoice = $this->getOptions()->isOptionCheckboxActive("ticketScannerDontRememberCamChoice") ? true : false;

		$pwaEnabled = $this->getOptions()->isOptionCheckboxActive('ticketScannerPWA');
		$vars = [
			'root' => esc_url_raw( rest_url() ),
			'_plugin_home_url' =>plugins_url( "",__FILE__ ),
			'_action' => $this->_prefix.'_executeAdminSettings',
			'_isPremium'=>$this->isPremium(),
			'_isUserLoggedin'=>is_user_logged_in(),
			'_userId'=>get_current_user_id(),
			'_restPrefixUrl'=>SASO_EVENTTICKETS::getRESTPrefixURL(),
			'_siteUrl'=>get_site_url(),
			'_params'=>["auth"=>$this->getAuthtokenHandler()::$authtoken_param],
			'_pwaSWUrl'=>$pwaEnabled ? rest_url(SASO_EVENTTICKETS::getRESTPrefixURL().'/ticket/scanner/pwa-sw') : '',
			//'url'   => admin_url( 'admin-ajax.php' ), // not used for now in ticketscanner.js
			'url'   => rest_get_server(), // not used for now in ticketscanner.js
			'nonce' => wp_create_nonce( 'wp_rest' ),
			//'nonce' => wp_create_nonce( $this->_js_nonce ),
			'ajaxActionPrefix' => $this->_prefix,
			'wcTicketCompatibilityModeRestURL' => $this->getOptions()->getOptionValue('wcTicketCompatibilityModeRestURL', ''),
			'IS_PRETTY_PERMALINK_ACTIVATED' => get_option('permalink_structure') ? true :false,
			'ticketScannerDontRememberCamChoice' => $ticketScannerDontRememberCamChoice,
			'ticketScannerStartCamWithoutButtonClicked' => $this->getOptions()->isOptionCheckboxActive('ticketScannerStartCamWithoutButtonClicked'),
			'ticketScannerDontShowOptionControls' => $this->getOptions()->isOptionCheckboxActive('ticketScannerDontShowOptionControls'),
			'ticketScannerScanAndRedeemImmediately' => $this->getOptions()->isOptionCheckboxActive('ticketScannerScanAndRedeemImmediately'),
			'ticketScannerHideTicketInformation' => $this->getOptions()->isOptionCheckboxActive('ticketScannerHideTicketInformation'),
			'ticketScannerHideTicketInformationShowShortDesc' => $this->getOptions()->isOptionCheckboxActive('ticketScannerHideTicketInformationShowShortDesc'),
			'ticketScannerDontShowBtnPDF' => $this->getOptions()->isOptionCheckboxActive('ticketScannerDontShowBtnPDF'),
			'ticketScannerDontShowBtnBadge' => $this->getOptions()->isOptionCheckboxActive('ticketScannerDontShowBtnBadge'),
			'ticketScannerDisplayTimes' => $this->getOptions()->isOptionCheckboxActive('ticketScannerDisplayTimes'),
			'ticketScannerThemeColor' => $this->getOptions()->getOptionValue('ticketScannerThemeColor', '#2e74b5'),
			'ticketScannerVibrate' => $this->getOptions()->isOptionCheckboxActive('ticketScannerVibrate')
		];
		$vars = apply_filters( $this->_add_filter_prefix.'main_setTicketScannerJS', $vars );
        wp_localize_script(
            'ajax_script_ticket_scanner',
            'Ajax_'.$this->_prefix, // name der injected variable
			$vars
        );

		do_action( $this->_do_action_prefix.'main_setTicketScannerJS', $js_url );
	}

	public function replacingShortcodeTicketScanner($attr=[], $content = null, $tag = '') {
		$this->setTicketScannerJS();
		return '
		<center>
        <div style="width:90%;max-width:1024px;">'.$this->getTicketHandler()->getTicketScannerHTMLBoilerplate().'
        </div>
        </center>
		';
	}

	/**
	 * Shortcode to display ticket detail view on any page
	 * Usage: [sasoEventTicketsValidator_ticket_detail] with ?ticket=CODE in URL
	 * Or: [sasoEventTicketsValidator_ticket_detail code="TICKET-CODE"]
	 */
	public function replacingShortcodeTicketDetail($attr = [], $content = null, $tag = ''): string {
		$code = '';
		if (!empty($attr['code'])) {
			$code = sanitize_text_field($attr['code']);
		} elseif (isset($_GET['ticket'])) {
			$code = sanitize_text_field($_GET['ticket']);
		}

		if (empty($code)) {
			return '<p>' . esc_html__('No ticket code provided. Use ?ticket=YOUR-CODE in the URL.', 'event-tickets-with-ticket-scanner') . '</p>';
		}

		// Build a fake request URI for the ticket
		$ticketPath = $this->getCore()->getTicketURLPath(true);
		$fakeUri = $ticketPath . $code;

		include_once plugin_dir_path(__FILE__) . "sasoEventtickets_Ticket.php";
		$ticketInstance = sasoEventtickets_Ticket::Instance($fakeUri);

		return $ticketInstance->renderTicketDetailForShortcode();
	}

	public function getCodesTextAsShortList($codes) {
		$ret = "";
		if (count($codes) > 0) {
			$ret = '<table>';
			$wcTicketUserProfileDisplayTicketDetailURL = $this->getOptions()->isOptionCheckboxActive("wcTicketUserProfileDisplayTicketDetailURL");
			$wcTicketUserProfileDisplayRedeemAmount = $this->getOptions()->isOptionCheckboxActive("wcTicketUserProfileDisplayRedeemAmount");

			$label_expired = $this->getOptions()->getOptionValue('wcTicketTransTicketExpired', 'EXPIRED');
			$label_stolen = $this->getOptions()->getOptionValue('wcTicketTransTicketIsStolen', 'REPORTED AS STOLEN');
			$label_notvalid = $this->getOptions()->getOptionValue('wcTicketTransTicketNotValid', 'DISABLED');

			$myCodes = [];
			foreach($codes as $idx => $codeObj) {
				$metaObj = $this->getCore()->encodeMetaValuesAndFillObject($codeObj['meta'], $codeObj);

				$_c = '<tr><td style="text-align:right;">'.($idx + 1).'.</td><td>'.$codeObj['code_display'].'</td><td>';
				if ($codeObj['aktiv'] == 1) {
					if ($this->getCore()->checkCodeExpired($codeObj)) {
						$_c .= $label_expired;
					}
				} else if ($codeObj['aktiv'] == 0) {
					$_c .= $label_notvalid;
				} else if ($codeObj['aktiv'] == 2) {
					$_c .= $label_stolen;
				}
				$_c .= '</td>';

				if ($wcTicketUserProfileDisplayTicketDetailURL) {
					$_c .= "<td>";
					$url = $this->getCore()->getTicketURL($codeObj, $metaObj);
					if (!empty($url)) {
						$_c .= '<a href="'.$url.'" target="_blank">Ticket Details</a>';
					}
					$_c .= "</td>";
				}
				if ($wcTicketUserProfileDisplayRedeemAmount && function_exists("wc_get_product")) {
					$_c .= "<td>";
					$text_redeem_amount = $this->getTicketHandler()->getRedeemAmountText($codeObj, $metaObj, false);
					if (!empty($text_redeem_amount)) {
						$_c .= $text_redeem_amount;
					}
					$_c .= "<td>";
				}
				$_c .= "</tr>";
				$myCodes[] = $_c;
			}
			$ret .= implode("", $myCodes);
			$ret .= "</table>";
		}
		$ret = apply_filters( $this->_add_filter_prefix.'main_getCodesTextAsShortList', $ret, $codes );
		return $ret;
	}

	public function getMyCodeText($user_id, $attr=[], $content = null, $tag = '', $codes = null) {
		$ret = '';
		// check ob eingeloggt
		$pre_text = $this->getOptions()->getOptionValue('userDisplayCodePrefix', '');
		if (!empty($pre_text)) $pre_text .= " ";

		// If codes are provided (e.g., from order_id), use them; otherwise fetch by user_id
		if ($codes === null && $user_id > 0) {
			$codes = $this->getCore()->getCodesByRegUserId($user_id);
		}

		if ($codes !== null && count($codes) > 0) {
			$ret .= "<b>".$pre_text."</b><br>";
			$ret .= $this->getCodesTextAsShortList($codes);

			// Download All as PDF button
			$show_download_btn = isset($attr['download_all_pdf']) &&
				in_array(strtolower($attr['download_all_pdf']), ['true', '1', 'yes'], true);

			if ($show_download_btn && count($codes) > 0) {
				$max_tickets = isset($attr['download_all_pdf_max']) ? intval($attr['download_all_pdf_max']) : 100;
				$btn_label = isset($attr['download_all_pdf_label']) ?
					sanitize_text_field($attr['download_all_pdf_label']) :
					__('Download All Tickets as PDF', 'event-tickets-with-ticket-scanner');

				$ticket_count = count($codes);
				if ($ticket_count > $max_tickets) {
					$ret .= '<p><em>' . sprintf(
						/* translators: %d: maximum number of tickets */
						esc_html__('Too many tickets (%1$d). Maximum %2$d tickets can be downloaded at once.', 'event-tickets-with-ticket-scanner'),
						$ticket_count,
						$max_tickets
					) . '</em></p>';
				} else {
					// Generate secure download URL
					$nonce = wp_create_nonce('download_my_codes_pdf_' . $user_id);
					$download_url = admin_url('admin-ajax.php') . '?' . http_build_query([
						'action' => $this->_prefix . '_downloadMyCodesAsPDF',
						'nonce' => $nonce
					]);
					$ret .= '<p style="margin-top:10px;"><a href="' . esc_url($download_url) . '" class="button" target="_blank">' .
						esc_html($btn_label) . ' (' . $ticket_count . ')</a></p>';
				}
			}
		}
		if (empty($ret) && $this->getOptions()->isOptionCheckboxActive('userDisplayCodePrefixAlways')) {
			$ret .= $pre_text;
		}
		$ret = apply_filters( $this->_add_filter_prefix.'main_getMyCodeText', $ret, $user_id, $attr, $content, $tag);
		return $ret;
	}

	/**
	 * AJAX handler: Download all user's tickets as one PDF
	 * Used by shortcode [sasoEventTicketsValidator_code download_all_pdf="true"]
	 */
	public function downloadMyCodesAsPDF(): void {
		$user_id = get_current_user_id();

		// Must be logged in
		if ($user_id <= 0) {
			wp_die(esc_html__('You must be logged in to download tickets.', 'event-tickets-with-ticket-scanner'), 403);
		}

		// Verify nonce
		$nonce = isset($_GET['nonce']) ? sanitize_text_field($_GET['nonce']) : '';
		if (!wp_verify_nonce($nonce, 'download_my_codes_pdf_' . $user_id)) {
			wp_die(esc_html__('Security check failed. Please refresh the page and try again.', 'event-tickets-with-ticket-scanner'), 403);
		}

		// Get user's codes
		$codes = $this->getCore()->getCodesByRegUserId($user_id);

		if (empty($codes)) {
			wp_die(esc_html__('No tickets found.', 'event-tickets-with-ticket-scanner'), 404);
		}

		// Limit to 100 tickets
		$max_tickets = 100;
		if (count($codes) > $max_tickets) {
			wp_die(
				sprintf(
					/* translators: %d: maximum number of tickets */
					esc_html__('Too many tickets. Maximum %d tickets can be downloaded at once.', 'event-tickets-with-ticket-scanner'),
					$max_tickets
				),
				400
			);
		}

		// Extract code strings
		$code_strings = array_map(function($codeObj) {
			return $codeObj['code'];
		}, $codes);

		// Generate merged PDF
		$filename = 'my_tickets_' . wp_date('Ymd_Hi') . '.pdf';

		try {
			$this->getTicketHandler()->generateOnePDFForCodes($code_strings, $filename, 'I');
		} catch (Exception $e) {
			$this->getAdmin()->logErrorToDB($e, null, 'downloadMyCodesAsPDF');
			wp_die(esc_html__('Error generating PDF. Please try again later.', 'event-tickets-with-ticket-scanner'), 500);
		}

		exit;
	}

	public function getMyCodeFormatted($user_id, $attr=[], $content = null, $tag = '', $codes = null) {
		$format = "json";
		if (isset($attr["format"])) {
			$format = strtolower(trim(sanitize_key($attr["format"])));
		}
		$display = ["codes"];
		if (isset($attr["display"])) {

			$_d = trim(sanitize_text_field($attr["display"]));
			$_da = explode(",", $_d);
			if (count($_da) > 0) {
				$display = [];
			}
			foreach ($_da as $item) {
				$item = trim($item);
				$display[] = $item;
			}
		}

		$output = [];
		//codes,validation,user,used,confirmedCount,woocommerce,wc_rp,wc_ticket
		// If codes are provided (e.g., from order_id), use them; otherwise fetch by user_id
		if ($codes === null) {
			$codes = $this->getCore()->getCodesByRegUserId($user_id);
		}
		$metas = [];
		foreach($codes as $codeObj) {
			$metas[$codeObj["code"]]  = $this->getCore()->encodeMetaValuesAndFillObject($codeObj['meta'], $codeObj);
		}
		foreach ($display as $item) {
			$output[$item] = [];
			if ($item == "codes") {
				foreach($codes as $codeObj) {
					if (isset($codeObj["meta"])) {
						unset($codeObj["meta"]);
					}
					$output[$item][] = $codeObj;
				}
			} elseif($item == "confirmedCount") {
				foreach($metas as $key => $meta) {
					$output[$item][] = array_merge(["value"=>$meta[$item]], ["code"=>$key]);
				}
			} else {
				foreach($metas as $key => $m) {
					if (is_array($m) && isset($m[$item])) {
						$meta = $m[$item];
						if (isset($meta["stats_redeemed"])) {
							unset($meta["stats_redeemed"]);
						}
						if (isset($meta["set_by_admin"])) {
							unset($meta["set_by_admin"]);
						}
						if (isset($meta["redeemed_by_admin"])) {
							unset($meta["redeemed_by_admin"]);
						}
						$output[$item][] = array_merge($meta, ["code"=>$key]);
					}
				}
			}
		}

		switch($format) {
			case "json":
			default:
				$ret = $this->getCore()->json_encode_with_error_handling($output);
		}
		$ret = apply_filters( $this->_add_filter_prefix.'main_getMyCodeFormatted', $ret, $user_id, $attr, $content, $tag, $output);
		return $ret;
	}

	public function replacingShortcodeMyCode($attr=[], $content = null, $tag = '') {
		$user_id = get_current_user_id();
		$codes = null; // Will be set if order_id is used

		// Check if order_id parameter is provided
		if (isset($attr['order_id']) && !empty($attr['order_id'])) {
			$order_id = intval($attr['order_id']);
			if ($order_id > 0) {
				// Security check: can current user access this order?
				if (!$this->canUserAccessOrder($order_id)) {
					return '<p>' . esc_html__('You do not have permission to view tickets for this order.', 'event-tickets-with-ticket-scanner') . '</p>';
				}
				// Get codes by order_id
				$codes = $this->getCore()->getCodesByOrderId($order_id);
			}
		}

		if (count($attr) > 0 && isset($attr["format"])) {
			return $this->getMyCodeFormatted($user_id, $attr, $content, $tag, $codes);
		} else {
			return $this->getMyCodeText($user_id, $attr, $content, $tag, $codes);
		}
	}

	/**
	 * Check if current user can access a specific order's tickets
	 *
	 * @param int $order_id WooCommerce order ID
	 * @return bool True if user can access, false otherwise
	 */
	private function canUserAccessOrder(int $order_id): bool {
		$order = wc_get_order($order_id);
		if (!$order) {
			return false;
		}

		// 1. Admin/Shop-Manager can access all orders
		if (current_user_can('manage_woocommerce')) {
			return true;
		}

		$current_user_id = get_current_user_id();

		// 2. Logged-in user owns this order
		if ($current_user_id > 0 && $order->get_user_id() == $current_user_id) {
			return true;
		}

		// 3. Valid order key in URL (WooCommerce thank-you page pattern)
		$order_key_from_url = isset($_GET['key']) ? sanitize_text_field($_GET['key']) : '';
		if (!empty($order_key_from_url) && $order->get_order_key() === $order_key_from_url) {
			return true;
		}

		return false;
	}

	public function replacingShortcodeFeatureList($attr=[], $content = null, $tag = '') {
		//$features = $this->getAdmin()->getOptions();
		//$options = $features["options"];
 		$options = $this->getOptions()->getOptions();
		$features = [];
		$act_heading = "";
		foreach ($options as $option) {
			if ($option["key"] == "serial") continue;
			if ($option["type"] == "heading") {
				$act_heading = $option["key"];
				$features[$act_heading] = ["heading"=>$option, "options"=>[]];
			} else {
				if ($act_heading != "") {
					$features[$act_heading]["options"][] = $option;
				}
			}
		}

		$ret = "";
		uasort($features, function($a, $b) {
			return strnatcasecmp($a["heading"]["label"], $b["heading"]["label"]);
		});
		foreach ($features as $key => $feature) {
			$ret .= '<h3>'.$feature["heading"]["label"].'</h3>';
			$video = $feature["heading"]["_doc_video"] != "" ? ' <a href="'.$feature["heading"]["_doc_video"].'" target="_blank"><span class="dashicons dashicons-video-alt3"></span> Introduction video</a>' : "";
			$ret .= '<p>'.trim($feature["heading"]["desc"].$video).'</p>';
			if (count($feature["options"]) > 0) {
				$ret .= '<ul>';
				foreach ($feature["options"] as $option) {
					$label = $option["label"];
					$desc = $option["desc"];
					$desc .= $option["_doc_video"] != "" ? ' <a href="'.$option["_doc_video"].'" target="_blank"><span class="dashicons dashicons-video-alt3"></span> Introduction video</a>' : "";
					$desc = trim($desc);
					if ($desc != "") {
						$desc = '<p>'.$desc.'</p>';
					}
					$label = '<span class="dashicons dashicons-yes"></span> '.$label;
					$ret .= '<li>'.$label.$desc.'</li>';
				}
				$ret .= '</ul>';
			}
			$ret .= '<hr>';
		}

		return $ret;
	}

	public function replacingShortcodeEventViews($attr=[], $content = null, $tag = '') {
		// iterate over all woocommerce products and check if they are an event
		$view = "calendar|list";
		if (isset($attr["view"])) {
			$view = strtolower(trim(sanitize_key($attr["view"])));
		}
		$months_to_show = 3;
		if (isset($attr["months_to_show"])) {
			$m = intval($attr["months_to_show"]);
			if ($m > 0) $months_to_show = $m;
		}

		$month_start = strtotime(wp_date("Y-m-1 00:00:00"));
		//$month_end = strtotime(wp_date("Y-m-t 23:59:59"));
		$month_end = strtotime(wp_date("Y-m-1 23:59:59", strtotime("+".$months_to_show." month", $month_start)));

		$products_args = array(
			'post_type' => 'product',
			'post_status' => 'publish',
			'posts_per_page' => -1, // -1 zeigt alle Produkte an
			'meta_query' => array(
				[
					'key' => 'saso_eventtickets_is_ticket',
					'value' => 'yes',
					'compare' => '='
				]
			)
		);
		$posts = get_posts($products_args); // get only ticket products

		$list_infos = [
			'months_to_show'=>$months_to_show,
			'month_start'=>$month_start,
			'month_end'=>$month_end,
			'view'=>$view
		];
		$products_to_show = [];
		// Ergebnisse überprüfen
		foreach ($posts as $post) {
			$product_ids = [];
			$product = wc_get_product( $post->ID );

			// check if event is variant product
			$is_variable = $product->get_type() == "variable";
			if ($is_variable) {
				// check if event dates are the same for all variants
				$date_is_for_all_variants = get_post_meta( $product_id, 'saso_eventtickets_is_date_for_all_variants', true ) == "yes" ? true : false;
				if ($date_is_for_all_variants == false) {
					// if not add also the variants
					$childs = $product->get_children();
					foreach ($childs as $child_id) {
						if (get_post_meta($child_id, '_saso_eventtickets_is_not_ticket', true) == "yes") {
							continue;
						}
						$product_ids[] = $child_id;
					}
				}
			} else {
				$product_ids[] = $product->get_id();
			}

			foreach ($product_ids as $product_id) {
				$product = wc_get_product( $product_id );
				$dates = $this->getTicketHandler()->calcDateStringAllowedRedeemFrom($product_id);
				//if ($dates['ticket_end_date_timestamp'] > $month_start && $dates['ticket_start_date_timestamp'] < $month_end) {
				if ($dates['ticket_end_date_timestamp'] >= $month_start || ($dates['ticket_start_date_timestamp'] >= $month_start && $dates['ticket_start_date_timestamp'] <= $month_end)) {
					// set product to hidden
					$product_data = array(
						'ID' => $product_id,
						'dates' => $dates,
						'event'=> [
							'location' => trim(get_post_meta( $product_id, 'saso_eventtickets_event_location', true )),
							'location_label' => wp_kses_post(trim($this->getAdmin()->getOptionValue("wcTicketTransLocation")))
						],
						'product' => [
							'title' => $product->get_name(),
							'url' => get_permalink($product_id),
							'price' =>$product->get_price(),
							'price_html' => $product->get_price_html(),
							'type' => $product->get_type(),
						]
					);
					$products_to_show[] = $product_data;
				}
			}
		}

		$divId = "sasoEventTicketsValidator_eventsview";

		// add js for the events
		wp_enqueue_style("wp-jquery-ui-dialog");

		$js_url = "ticket_events.js?_v=".$this->getPluginVersion();
		if (defined('WP_DEBUG')) $js_url .= '&t='.time();
		$js_url = plugins_url( $js_url,__FILE__ );
		wp_register_script('ajax_script_ticket_events', $js_url, array('jquery', 'jquery-ui-dialog', 'wp-i18n'));
		wp_enqueue_script('ajax_script_ticket_events');
		wp_set_script_translations('ajax_script_ticket_events', 'event-tickets-with-ticket-scanner', __DIR__.'/languages');
		wp_enqueue_style("ticket_events_css", plugins_url( "",__FILE__ ).'/css/calendar.css', [], true);

		// add all events as an array for max 3 months??? or config parameter
		$vars = [
			'root' => esc_url_raw( rest_url() ),
			'_plugin_home_url' =>plugins_url( "",__FILE__ ),
			'_action' => $this->_prefix.'_executeFrontendEvents',
			'_isPremium'=>$this->isPremium(),
			'_isUserLoggedin'=>is_user_logged_in(),
			'_userId'=>get_current_user_id(),
			'_premJS'=>$this->isPremium() && method_exists($this->getPremiumFunctions(), "getJSFrontEventFile") ? $this->getPremiumFunctions()->getJSFrontEventFile() : '',
			'_siteUrl'=>get_site_url(),
			'events' => $products_to_show,
			'list_infos' => $list_infos,
			'format_date' => $this->getOptions()->getOptionDateFormat(),
			'format_time' => $this->getOptions()->getOptionTimeFormat(),
			'format_datetime' => $this->getOptions()->getOptionDateTimeFormat(),
			'url'   => admin_url( 'admin-ajax.php' ),
			'nonce' => wp_create_nonce( $this->_js_nonce ),
			'ajaxActionPrefix' => $this->_prefix,
			'divId' => $divId
		];
		$vars = apply_filters( $this->_add_filter_prefix.'main_setTicketEventJS', $vars );
        wp_localize_script(
            'ajax_script_ticket_events',
            'Ajax_ticket_events_'.$this->_prefix, // name der injected variable
			$vars
        );

		do_action( $this->_do_action_prefix.'main_setTicketEventJS', $js_url );

		$ret = '';
        if (!isset($attr['divid']) || trim($attr['divid']) == "") {
        	$ret .= '<div id="'.$divId.'">'.__('...loading...', 'event-tickets-with-ticket-scanner').'</div>';
        }

		$ret = apply_filters( $this->_add_filter_prefix.'main_replacingShortcodeEventViews', $ret );
		do_action( $this->_do_action_prefix.'main_replacingShortcodeEventViews', $vars, $ret );

		return $ret;
	}

	public function replaceShortcode($attr=[], $content = null, $tag = '') {
		// einbinden das js starter skript
		$js_url = $this->_js_file."?_v=".$this->_js_version;
		if (defined( 'WP_DEBUG')) $js_url .= '&debug=1';
		$userDivId = !isset($attr['divid']) || trim($attr['divid']) == "" ? '' : trim($attr['divid']);

		$attr = array_change_key_case( (array) $attr, CASE_LOWER );

      	wp_enqueue_script(
            'ajax_script_validator',
            plugins_url( $js_url,__FILE__ ),
            array('jquery', 'wp-i18n')
        );
		wp_set_script_translations('ajax_script_validator', 'event-tickets-with-ticket-scanner', __DIR__.'/languages');

		$vars = array(
				'shortcode_attr'=>json_encode($attr),
            	'_plugin_home_url' =>plugins_url( "",__FILE__ ),
            	'_action' => $this->_prefix.'_executeFrontend',
            	'_isPremium'=>$this->isPremium(),
            	'_isUserLoggedin'=>is_user_logged_in(),
            	'_premJS'=>$this->isPremium() && method_exists($this->getPremiumFunctions(), "getJSFrontFile") ? $this->getPremiumFunctions()->getJSFrontFile() : '',
                'url'   => admin_url( 'admin-ajax.php' ),
                'nonce' => wp_create_nonce( $this->_js_nonce ),
                'ajaxActionPrefix' => $this->_prefix,
                'divPrefix' => $userDivId == "" ? $this->_prefix : $userDivId,
                'divId' => $this->_divId,
                'jsFiles' => plugins_url( 'validator.js?_v='.$this->_js_version, __FILE__ )
            );
		$vars['_messages'] = [
			'msgCheck0'=>$this->getOptions()->getOptionValue('textValidationMessage0'),
			'msgCheck1'=>$this->getOptions()->getOptionValue('textValidationMessage1'),
			'msgCheck2'=>$this->getOptions()->getOptionValue('textValidationMessage2'),
			'msgCheck3'=>$this->getOptions()->getOptionValue('textValidationMessage3'),
			'msgCheck4'=>$this->getOptions()->getOptionValue('textValidationMessage4'),
			'msgCheck5'=>$this->getOptions()->getOptionValue('textValidationMessage5'),
			'msgCheck6'=>$this->getOptions()->getOptionValue('textValidationMessage6')
		];
		$enableQR = $this->getOptions()->isOptionCheckboxActive('enableQRScanner');
		$vars['_enableQRScanner'] = $enableQR;
		if ($enableQR) {
			wp_enqueue_script(
				'saso-html5-qrcode',
				plugins_url('3rd/html5-qrcode.min.js', __FILE__),
				array(),
				$this->_js_version,
				true
			);
		}

		$vars = apply_filters( $this->_add_filter_prefix.'main_replaceShortcode', $vars );

		if ($this->isPremium() && method_exists($this->getPremiumFunctions(), "addJSFrontFile")) $this->getPremiumFunctions()->addJSFrontFile();

        wp_localize_script(
            'ajax_script_validator',
            'Ajax_'.$this->_prefix, // name of the injected variable
            $vars
        );
        $ret = '';
        if (!isset($attr['divid']) || trim($attr['divid']) == "") {
        	$ret .= '<div id="'.$this->_divId.'">'.__('...loading...', 'event-tickets-with-ticket-scanner').'</div>';
        }

		$ret = apply_filters( $this->_add_filter_prefix.'main_replaceShortcode_2', $ret );
		do_action( $this->_do_action_prefix.'main_replaceShortcode', $vars, $ret );

		return $ret;
	}

	/**
	 * Show admin notice when premium subscription is about to expire or has expired
	 *
	 * - Warning: 14 days before expiration
	 * - Error: After expiration (during grace period or after)
	 *
	 * @return void
	 */
	public function showSubscriptionWarning(): void {
		// Only show when premium plugin is installed (not dependent on subscription status,
		// otherwise the expiration warning itself would never be shown)
		if (!class_exists('sasoEventtickets_PremiumFunctions')) {
			return;
		}

		// Only show in admin
		if (!is_admin()) {
			return;
		}

		// Only show to users who can manage options
		if (!current_user_can('manage_options')) {
			return;
		}

		$info = $this->getTicketHandler()->get_expiration();

		// No expiration date or lifetime license - no warning needed
		if (empty($info['timestamp']) || $info['timestamp'] <= 0 || $info['timestamp'] == -1) {
			return;
		}

		// Lifetime subscription type - no warning needed
		if (isset($info['subscription_type']) && $info['subscription_type'] === 'lifetime') {
			return;
		}

		$days_left = ($info['timestamp'] - time()) / 86400;
		$renewal_url = 'https://vollstart.com/event-tickets-with-ticket-scanner/';

		// Warning 14 days before expiration
		if ($days_left <= 14 && $days_left > 0) {
			$date = date_i18n(get_option('date_format'), $info['timestamp']);
			echo '<div class="notice notice-warning is-dismissible"><p>';
			printf(
				/* translators: %1$s: expiration date, %2$s: renewal URL */
				esc_html__('Your Event-Tickets Premium subscription expires on %1$s. %2$sRenew now%3$s to keep all features.', 'event-tickets-with-ticket-scanner'),
				'<strong>' . esc_html($date) . '</strong>',
				'<a href="' . esc_url($renewal_url) . '" target="_blank">',
				'</a>'
			);
			echo '</p></div>';
		}

		// Error after expiration
		if ($days_left <= 0) {
			$grace_days = isset($info['grace_period_days']) ? intval($info['grace_period_days']) : 7;
			$grace_left = $grace_days + $days_left; // days_left is negative

			echo '<div class="notice notice-error"><p>';
			if ($grace_left > 0) {
				printf(
					/* translators: %1$d: days remaining in grace period, %2$s: renewal URL */
					esc_html__('Your Premium subscription has expired. You have %1$d days remaining before features are disabled. %2$sReactivate now%3$s', 'event-tickets-with-ticket-scanner'),
					ceil($grace_left),
					'<a href="' . esc_url($renewal_url) . '" target="_blank">',
					'</a>'
				);
			} else {
				printf(
					/* translators: %1$s: renewal URL */
					esc_html__('Your Premium subscription has expired. Premium features are now limited. %1$sReactivate now%2$s', 'event-tickets-with-ticket-scanner'),
					'<a href="' . esc_url($renewal_url) . '" target="_blank">',
					'</a>'
				);
			}
			echo '</p></div>';
		}
	}

	/**
	 * FOMO banner: show missed premium features to users with expired subscriptions.
	 * Dismissable for 30 days, then re-appears with an even longer feature list.
	 */
	public function showFomoBanner(): void {
		if (!current_user_can('manage_options')) return;
		if ($this->isPremium()) return;
		if (!class_exists('sasoEventtickets_PremiumFunctions')) return;
		if ($this->isStarterOrStopDetected()) return;
		if ($this->isOldPremiumDetected()) return;

		$info = $this->getTicketHandler()->get_expiration();

		if (empty($info['expiration_date'])) return;
		if (isset($info['subscription_type']) && $info['subscription_type'] === 'lifetime') return;

		$expired_ts = strtotime($info['expiration_date']);
		if (!$expired_ts || $expired_ts > time()) return;

		if (get_transient('saso_et_fomo_dismissed')) return;

		$json_path = plugin_dir_path(__FILE__) . 'changelog-features.json';
		if (!file_exists($json_path)) return;

		$changelog = json_decode(file_get_contents($json_path), true);
		if (!is_array($changelog)) return;

		$missed = [];
		foreach ($changelog as $release) {
			$release_ts = strtotime($release['date'] ?? '');
			if ($release_ts && $release_ts > $expired_ts && !empty($release['features'])) {
				foreach ($release['features'] as $feat) {
					$missed[] = $feat;
				}
			}
		}

		if (empty($missed)) return;

		$total = count($missed);
		$show = array_slice($missed, 0, 5);
		$renewal_url = 'https://vollstart.com/event-tickets-with-ticket-scanner/';
		$nonce = wp_create_nonce('saso_et_dismiss_fomo');

		echo '<div class="notice notice-info is-dismissible" id="saso-et-fomo-banner" style="border-left-color:#9333ea;">';
		echo '<p><strong>';
		printf(
			/* translators: %d: number of missed features */
			esc_html__("You're missing %d new premium features!", 'event-tickets-with-ticket-scanner'),
			$total
		);
		echo '</strong></p>';
		echo '<ul style="list-style:disc;margin-left:20px;">';
		foreach ($show as $feat) {
			echo '<li>' . esc_html($feat) . '</li>';
		}
		if ($total > 5) {
			printf('<li><em>' . esc_html__('...and %d more', 'event-tickets-with-ticket-scanner') . '</em></li>', $total - 5);
		}
		echo '</ul>';
		echo '<p><a href="' . esc_url($renewal_url) . '" target="_blank" class="button button-primary" style="background:#9333ea;border-color:#7c22d0;">';
		esc_html_e('Renew now', 'event-tickets-with-ticket-scanner');
		echo '</a></p>';
		echo '</div>';
		echo '<script>jQuery(function($){$("#saso-et-fomo-banner").on("click",".notice-dismiss",function(){$.post(ajaxurl,{action:"saso_et_dismiss_fomo",_wpnonce:"' . $nonce . '"});});});</script>';
	}

	/**
	 * AJAX handler: dismiss FOMO banner for 30 days
	 */
	public function ajaxDismissFomo(): void {
		check_ajax_referer('saso_et_dismiss_fomo');
		set_transient('saso_et_fomo_dismissed', 1, 30 * DAY_IN_SECONDS);
		wp_send_json_success();
	}

	/**
	 * Show admin notice when premium plugin version is outdated or incompatible
	 *
	 * Shows a prominent, non-dismissible error when old premium is detected.
	 * Old premium (< 1.6.0) is NOT loaded to prevent fatal errors.
	 *
	 * @return void
	 */
	public function showOutdatedPremiumWarning(): void {
		if (!is_admin() || !current_user_can('manage_options')) {
			return;
		}

		$starter_or_stop = $this->isStarterOrStopDetected();
		$old_premium = $this->isOldPremiumDetected();

		if (!$starter_or_stop && !$old_premium) {
			return;
		}

		$old_version = defined('SASO_EVENTTICKETS_PREMIUM_PLUGIN_VERSION')
			? SASO_EVENTTICKETS_PREMIUM_PLUGIN_VERSION
			: __('unknown', 'event-tickets-with-ticket-scanner');

		$upgrade_url = 'https://vollstart.com/event-tickets-with-ticket-scanner/';
		$support_url = 'https://vollstart.com/support/';
		$premium_url = 'https://vollstart.com/downloads/event-tickets-with-ticket-scanner/';

		echo '<div class="notice notice-error" style="border-left-color:#dc3232;padding:15px 20px;">';
		echo '<p style="font-size:15px;font-weight:bold;margin:0 0 10px 0;color:#dc2626;">';
		echo '&#9888; ';

		if ($starter_or_stop) {
			$is_stop = defined('SASO_EVENTTICKETS_STOP_VERSION');
			if ($is_stop) {
				printf(
					esc_html__('Event Tickets: Your Premium subscription has expired. Please renew your license to continue using Premium features.', 'event-tickets-with-ticket-scanner')
				);
			} else {
				printf(
					esc_html__('Event Tickets: The Starter plugin is installed. Please update to the latest Premium version within the Plugins page.', 'event-tickets-with-ticket-scanner')
				);
			}
		} else {
			printf(
				esc_html__('Event Tickets: Your Premium plugin (v%s) is outdated and not compatible with this version.', 'event-tickets-with-ticket-scanner'),
				esc_html($old_version)
			);
		}
		echo '</p>';

		if ($starter_or_stop) {
			// Starter/Stop plugin - different messages
			$is_stop = defined('SASO_EVENTTICKETS_STOP_VERSION');
			$hasSerial = !empty(trim(get_option("saso-event-tickets-premium_serial", "")));

			echo '<div style="background:#f8f9fa;border-left:4px solid #2563eb;padding:12px;margin:12px 0;">';

			if ($is_stop) {
				// Stop plugin - subscription expired, PUC can update once license is renewed
				echo '<p style="margin:0 0 10px 0;"><strong>' . esc_html__('Solution: Renew Your Premium License', 'event-tickets-with-ticket-scanner') . '</strong></p>';
				echo '<ol style="margin:0 0 12px 20px;padding-left:20px;">';
				echo '<li style="margin:0 0 6px 0;">' . esc_html__('Renew your license', 'event-tickets-with-ticket-scanner') . '</li>';
				if ($hasSerial) {
					echo '<li style="margin:0 0 6px 0;">' . esc_html__('Update your license key in Event Tickets settings', 'event-tickets-with-ticket-scanner') . '</li>';
				} else {
					echo '<li style="margin:0 0 6px 0;">' . esc_html__('Enter your license key in Event Tickets settings', 'event-tickets-with-ticket-scanner') . '</li>';
				}
				echo '<li style="margin:0 0 6px 0;">' . esc_html__('Click "Check License" to verify your new subscription', 'event-tickets-with-ticket-scanner') . '</li>';
				echo '<li style="margin:0;">' . esc_html__('Update the Premium plugin via Plugins > Updates', 'event-tickets-with-ticket-scanner') . '</li>';
				echo '</ol>';
				printf(
					'<p style="margin:0 0 8px 0;"><a href="%s" class="button button-primary button-hero" target="_blank">%s</a></p>',
					esc_url($upgrade_url . '?utm_source=plugin&utm_medium=stop-notice&utm_campaign=renew-license'),
					esc_html__('Renew License', 'event-tickets-with-ticket-scanner')
				);
				echo '<p style="margin:0;font-size:13px;color:#666;">';
				esc_html_e('Contact support via support@vollstart.com if you think this is not correct.', 'event-tickets-with-ticket-scanner');
				echo '</p>';
			} else {
				// Starter plugin - just update within plugins area
				echo '<p style="margin:0 0 10px 0;"><strong>' . esc_html__('Solution: Update to Premium via Plugins Page', 'event-tickets-with-ticket-scanner') . '</strong></p>';
				echo '<p style="margin:0 0 8px 0;">';
				esc_html_e('The Starter plugin needs to be updated to Premium. Please update within the Plugins page:', 'event-tickets-with-ticket-scanner');
				echo '</p>';
				echo '<ol style="margin:0 0 12px 20px;padding-left:20px;">';
				if (!$hasSerial) {
					echo '<li style="margin:0 0 6px 0;">' . esc_html__('Enter your license key in Event Tickets settings', 'event-tickets-with-ticket-scanner') . '</li>';
				}
				echo '<li style="margin:0 0 6px 0;">' . esc_html__('Go to Plugins > Add New > Upload Plugin', 'event-tickets-with-ticket-scanner') . '</li>';
				echo '<li style="margin:0 0 6px 0;">' . esc_html__('Upload the Premium ZIP file', 'event-tickets-with-ticket-scanner') . '</li>';
				echo '<li style="margin:0 0 6px 0;">' . esc_html__('WordPress will replace the Starter plugin with Premium', 'event-tickets-with-ticket-scanner') . '</li>';
				echo '<li style="margin:0;">' . esc_html__('Click "Replace current with uploaded" and activate', 'event-tickets-with-ticket-scanner') . '</li>';
				echo '</ol>';
			}

			echo '</div>';
		} else {
			// Old premium detected - update instructions
			echo '<div style="background:#f8f9fa;border-left:4px solid #2563eb;padding:12px;margin:12px 0;">';
			echo '<p style="margin:0 0 10px 0;"><strong>' . esc_html__('Solution: Update Premium Plugin', 'event-tickets-with-ticket-scanner') . '</strong></p>';
			echo '<p style="margin:0 0 8px 0;">';
			esc_html_e('Your current Premium plugin is outdated and incompatible. Premium features have been temporarily disabled to prevent errors. Your tickets and data are safe.', 'event-tickets-with-ticket-scanner');
			echo '</p>';
			echo '<p style="margin:0 0 8px 0;">';
			printf(
				esc_html__('Required version: 1.6.0 or higher. You have: %s', 'event-tickets-with-ticket-scanner'),
				'<strong>' . esc_html($old_version) . '</strong>'
			);
			echo '</p>';
			echo '<p style="margin:0 0 12px 0;">';
			esc_html_e('To restore premium features:', 'event-tickets-with-ticket-scanner');
			echo '</p>';
			echo '<ol style="margin:0 0 12px 20px;padding-left:20px;">';
			echo '<li style="margin:0 0 6px 0;">' . sprintf(
				/* translators: %s: URL to account */
				esc_html__('Download the latest Premium from your %1$saccount%2$s', 'event-tickets-with-ticket-scanner'),
				'<a href="' . esc_url($upgrade_url) . '" target="_blank"><strong>',
				'</strong></a>'
			) . '</li>';
			echo '<li style="margin:0 0 6px 0;">' . esc_html__('Go to Plugins > Add New > Upload Plugin', 'event-tickets-with-ticket-scanner') . '</li>';
			echo '<li style="margin:0 0 6px 0;">' . esc_html__('Upload the new Premium ZIP file', 'event-tickets-with-ticket-scanner') . '</li>';
			echo '<li style="margin:0;">' . esc_html__('WordPress will ask to replace - confirm to update', 'event-tickets-with-ticket-scanner') . '</li>';
			echo '</ol>';
			echo '<p style="margin:0;">';
			printf(
				'<a href="' . esc_url($support_url) . '" class="button" style="margin-right:8px;" target="_blank">%s</a>',
				esc_html__('Contact Support', 'event-tickets-with-ticket-scanner')
			);
			echo '</p>';
		}

		echo '</div>'; // End gray box

		echo '<p style="margin:12px 0 0 0;font-style:italic;color:#666;font-size:13px;">';
		esc_html_e('Your tickets, orders, and data are completely safe. You can continue using the basic features while updating.', 'event-tickets-with-ticket-scanner');
		echo '</p>';

		$downgrade_url = 'https://plugins.trac.wordpress.org/browser/event-tickets-with-ticket-scanner/tags';
		echo '<p style="margin:6px 0 0 0;font-style:italic;color:#666;font-size:13px;">';
		printf(
			esc_html__('Alternatively, your old Premium plugin still works with basic plugin versions below 2.8.0. You can %1$sdowngrade the basic plugin here%2$s.', 'event-tickets-with-ticket-scanner'),
			'<a href="' . esc_url($downgrade_url) . '" target="_blank">',
			'</a>'
		);
		echo '</p>';

		echo '</div>'; // End notice
	}

	/**
	 * Show admin notice when ticket format is running out of combinations
	 *
	 * Checks all ticket lists for format warnings and displays notice
	 *
	 * @return void
	 */
	public function showPhpVersionWarning(): void {
		if (version_compare(PHP_VERSION, '8.1.0', '>=')) {
			return;
		}
		printf(
			'<div class="notice notice-warning is-dismissible"><p><strong>Event Tickets with Ticket Scanner:</strong> %s</p></div>',
			sprintf(
				/* translators: 1: current PHP version 2: required PHP version */
				esc_html__('Your server is running PHP %1$s. This plugin requires PHP %2$s or higher. Please upgrade PHP to ensure full compatibility.', 'event-tickets-with-ticket-scanner'),
				PHP_VERSION,
				'8.1'
			)
		);
	}

	/**
	 * Handle format warning dismiss — runs on admin_init (before output) so wp_redirect() works.
	 */
	public function handleFormatWarningDismiss(): void {
		if (!isset($_GET['saso_eventtickets_clear_format_warning']) || !isset($_GET['_wpnonce'])) {
			return;
		}
		if (!current_user_can('manage_options')) {
			return;
		}
		$list_id = intval($_GET['saso_eventtickets_clear_format_warning']);
		$nonce = sanitize_text_field($_GET['_wpnonce']);
		if (wp_verify_nonce($nonce, 'clear_format_warning_' . $list_id)) {
			$this->getAdmin()->clearFormatWarning($list_id);
			wp_redirect(remove_query_arg(['saso_eventtickets_clear_format_warning', '_wpnonce']));
			exit;
		}
	}

	/**
	 * Show admin notice when options migration from wp_options to custom table is incomplete.
	 * Includes a button to manually trigger the migration.
	 */
	public function showOptionsMigrationNotice(): void {
		if (!current_user_can('manage_options')) {
			return;
		}
		// Fast path: migration already done
		if (get_option('saso_eventtickets_options_migrated', '0') === '1') {
			return;
		}
		// Check if custom table exists (DB upgrade may not have run yet)
		global $wpdb;
		$table = $wpdb->prefix . 'saso_eventtickets_options';
		if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) !== $table) {
			return;
		}
		// Check sentinel: is the last known option still in wp_options?
		$sentinelKey = $this->_prefix . 'qrAttachQRFilesToMailAsOnePDF';
		if (get_option($sentinelKey, '__NOT_SET__') === '__NOT_SET__') {
			// Sentinel gone — migration probably completed, set flag
			update_option('saso_eventtickets_options_migrated', '1');
			return;
		}
		// Migration incomplete — show admin notice with button
		$nonce = wp_create_nonce($this->_prefix);
		printf(
			'<div class="notice notice-warning"><p><strong>Event Tickets:</strong> %s <button class="button button-primary" onclick="sasoEventticketsMigrateOptions(this)" data-nonce="%s">%s</button></p></div>',
			esc_html__('Options migration to custom database table is incomplete.', 'event-tickets-with-ticket-scanner'),
			esc_attr($nonce),
			esc_html__('Migrate Options', 'event-tickets-with-ticket-scanner')
		);
	}

	public function showFormatWarning(): void {
		// Only show in admin
		if (!is_admin()) {
			return;
		}

		// Only show to users who can manage options
		if (!current_user_can('manage_options')) {
			return;
		}

		try {
			// Get all ticket lists
			$lists = $this->getAdmin()->getLists([], false);

			foreach ($lists as $list) {
				$warning = $this->getAdmin()->getFormatWarning($list['id']);

				if ($warning) {
					$list_name = esc_html($warning['list_name']);
					$attempts = intval($warning['attempts']);

					if ($warning['type'] === 'critical') {
						// Critical - format exhausted
						$clear_url = wp_nonce_url(
							add_query_arg(['saso_eventtickets_clear_format_warning' => $list['id']]),
							'clear_format_warning_' . $list['id']
						);

						echo '<div class="notice notice-error"><p>';
						printf(
							/* translators: 1: list name, 2: attempts, 3: clear URL */
							esc_html__('⚠️ CRITICAL: Ticket format for "%1$s" is exhausted! It took %2$d attempts to generate a code. Future ticket sales may fail. %3$sEdit list%4$s | %5$sDismiss%4$s', 'event-tickets-with-ticket-scanner'),
							$list_name,
							$attempts,
							'<a href="' . esc_url(admin_url('admin.php?page=event-tickets-with-ticket-scanner')) . '">',
							'</a>',
							'<a href="' . esc_url($clear_url) . '">'
						);
						echo '</p></div>';
					} else {
						// Warning - running out
						$clear_url = wp_nonce_url(
							add_query_arg(['saso_eventtickets_clear_format_warning' => $list['id']]),
							'clear_format_warning_' . $list['id']
						);

						echo '<div class="notice notice-warning is-dismissible"><p>';
						printf(
							/* translators: 1: list name, 2: attempts, 3: clear URL */
							esc_html__('⚠️ WARNING: Ticket format for "%1$s" is running out of combinations. It took %2$d attempts to generate a code. Consider increasing code length. %3$sEdit list%4$s | %5$sDismiss%4$s', 'event-tickets-with-ticket-scanner'),
							$list_name,
							$attempts,
							'<a href="' . esc_url(admin_url('admin.php?page=event-tickets-with-ticket-scanner')) . '">',
							'</a>',
							'<a href="' . esc_url($clear_url) . '">'
						);
						echo '</p></div>';
					}

					// Only show one warning at a time
					break;
				}
			}
		} catch (Exception $e) {
			// Silently fail - don't break the admin
		}
	}
}
$sasoEventtickets = sasoEventtickets::Instance();

// Cross-Promotion: andere Vollstart Plugins empfehlen
if (is_admin() && file_exists(__DIR__ . '/vollstart-cross-promo.php')) {
    require_once __DIR__ . '/vollstart-cross-promo.php';
    vollstart_cross_promo_init('event-tickets-with-ticket-scanner');
}
?>
