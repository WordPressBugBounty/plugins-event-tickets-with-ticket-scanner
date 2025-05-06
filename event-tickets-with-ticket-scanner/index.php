<?php
/**
 * Plugin Name: Event Tickets with Ticket Scanner
 * Plugin URI: https://vollstart.com/event-tickets-with-ticket-scanner/docs/
 * Description: You can create and generate tickets and codes. You can redeem the tickets at entrance using the built-in ticket scanner. You customer can download a PDF with the ticket information. The Premium allows you also to activate user registration and more. This allows your user to register them self to a ticket.
 * Version: 2.6.6
 * Author: Vollstart
 * Author URI: https://vollstart.com
 * Text Domain: event-tickets-with-ticket-scanner
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
	define('SASO_EVENTTICKETS_PLUGIN_VERSION', '2.6.6');
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
	protected $_shortcode = 'sasoEventTicketsValidator';
	protected $_shortcode_mycode = 'sasoEventTicketsValidator_code';
	protected $_shortcode_eventviews = 'sasoEventTicketsValidator_eventsview';
	protected $_shortcode_ticket_scanner = 'sasoEventTicketsValidator_ticket_scanner';
	protected $_divId = 'sasoEventtickets';

	private $_isPrem = null;
	private $_premium_plugin_name = 'event-tickets-with-ticket-scanner-premium';
	private $_premium_function_file = 'sasoEventtickets_PremiumFunctions.php';
	private $PREMFUNCTIONS = null;
	private $BASE = null;
	private $CORE = null;
	private $ADMIN = null;
	private $FRONTEND = null;
	private $OPTIONS = null;
	private $WC = null;

	private $isAllowedAccess = null;

	public static function Instance() {
		static $inst = null;
        if ($inst === null) {
            $inst = new self();
		}
        return $inst;
	}

	public function __construct() {
		$this->_js_version = $this->getPluginVersion();
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
			$ret['debug'] = '<span style="color:red;">'.esc_html__('is active', 'event-tickets-with-ticket-scanner').'</span>';
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
	public function getWC() {
		if ($this->WC == null) {
			$this->loadOnce('sasoEventtickets_WC', "woocommerce-hooks");
			$this->WC = new sasoEventtickets_WC($this);
		}
		return $this->WC;
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
	public function getPremiumFunctions() {
		if ($this->_isPrem == null && $this->PREMFUNCTIONS == null) {
			$this->_isPrem = false;
			$this->PREMFUNCTIONS = new sasoEventtickets_fakeprem();
			if (class_exists('sasoEventtickets_PremiumFunctions')) {
				$this->PREMFUNCTIONS = new sasoEventtickets_PremiumFunctions($this, plugin_dir_path(__FILE__), $this->_prefix, $this->getDB());
				$this->_isPrem = $this->PREMFUNCTIONS->isPremium();
			} else { // old approach before v 2.5.7
				// this is causing issues with base_dir set
				$premPluginFolder = $this->getPremiumPluginFolder();
				if (!empty($premPluginFolder)) {
					$file = $premPluginFolder.$this->_premium_function_file;
					$premiumFile = plugin_dir_path(__FILE__)."../".$file;
					if (file_exists($premiumFile)) { // check ob active ist nicht nötig, das das getPremiumPluginFolder schon macht
						if (!class_exists('sasoEventtickets_PremiumFunctions')) {
							include_once $premiumFile;
						}
						$this->PREMFUNCTIONS = new sasoEventtickets_PremiumFunctions($this, plugin_dir_path(__FILE__), $this->_prefix, $this->getDB());
						$this->_isPrem = $this->PREMFUNCTIONS->isPremium();
					}
				}
			}
		}
		return $this->PREMFUNCTIONS;
	}
	private function getPremiumPluginFolder() {
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
		if ($this->_isPrem == null) $this->getPremiumFunctions();
		return $this->_isPrem;
	}
	public function getPrefix() {
		return $this->_prefix;
	}
	public function getMV() {
		$v = ['storeip'=>false,'allowuserreg'=>false,'codes_total'=>0x13,'codes'=>0x12,'lists'=>5,'authtokens_total'=>0];
		$v["codes"] = (int) hexdec(0x80 / 0x002) / 2;
		$v["codes_total"] = (int) hexdec(0x80 / 0x002) / 2;
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
		do_action( $this->_do_action_prefix.'main_init_frontend' );
	}
	private function init_backend() {
		add_action('admin_menu', [$this, 'register_options_page']);
		register_activation_hook(__FILE__, [$this, 'plugin_activated']);
		register_deactivation_hook( __FILE__, [$this, 'plugin_deactivated'] );
		//register_uninstall_hook( __FILE__, 'sasoEventticketsDB::plugin_uninstall' );  // MUSS NOCH GETESTE WERDEN
		add_action( 'plugins_loaded', [$this, 'plugins_loaded'] );
		add_action( 'show_user_profile', [$this, 'show_user_profile'] );

		if (basename($_SERVER['SCRIPT_NAME']) == "admin-ajax.php") {
			add_action('wp_ajax_'.$this->_prefix.'_executeAdminSettings', [$this,'executeAdminSettings_a'], 10, 0);
		}

		do_action( $this->_do_action_prefix.'main_init_backend' );
	}
	public function WooCommercePluginLoaded() {
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
		}
		if (is_admin()) {
			add_action('woocommerce_delete_order', [$this, 'relay_woocommerce_delete_order'], 10, 1 );
			add_action('woocommerce_delete_order_item', [$this, 'relay_woocommerce_delete_order_item'], 20, 1);
			add_action('woocommerce_pre_delete_order_refund', [$this, 'relay_woocommerce_pre_delete_order_refund'], 10, 3);
			add_action('woocommerce_delete_order_refund', [$this, 'relay_woocommerce_delete_order_refund'], 10, 1 );
			add_action('woocommerce_order_partially_refunded', [$this, 'relay_woocommerce_order_partially_refunded'], 10, 2);
			//add_action('woocommerce_update_order', [$this, 'relay_woocommerce_update_order'], 10, 2);
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
		});

		do_action( $this->_do_action_prefix.'main_WooCommercePluginLoaded' );
	}
	public function relay_woocommerce_review_order_after_cart_contents() {
		$this->getWC()->woocommerce_review_order_after_cart_contents();
	}
	public function relay_woocommerce_checkout_process() {
		$this->getWC()->woocommerce_checkout_process();
	}
	public function relay_woocommerce_before_cart_table() {
		$this->getWC()->woocommerce_before_cart_table();
	}
	public function relay_woocommerce_cart_updated() {
		$this->getWC()->woocommerce_cart_updated();
	}
	public function relay_woocommerce_email_attachments() {
		$args = func_get_args();
		return $this->getWC()->woocommerce_email_attachments(...$args);
	}
	public function relay_woocommerce_checkout_create_order_line_item() {
		$args = func_get_args();
		return $this->getWC()->woocommerce_checkout_create_order_line_item(...$args);
	}
	public function relay_woocommerce_check_cart_items() {
		$this->getWC()->woocommerce_check_cart_items();
	}
	public function relay_woocommerce_new_order() {
		$args = func_get_args();
		return $this->getWC()->woocommerce_new_order(...$args);
	}
	public function relay_woocommerce_checkout_update_order_meta() {
		$args = func_get_args();
		return $this->getWC()->woocommerce_checkout_update_order_meta(...$args);
	}
	public function relay_executeWCFrontend() {
		return $this->getWC()->executeWCFrontend();
	}
	public function relay_woocommerce_delete_order() {
		$args = func_get_args();
		$this->getWC()->woocommerce_delete_order(...$args);
	}
	public function relay_woocommerce_delete_order_item() {
		$args = func_get_args();
		$this->getWC()->woocommerce_delete_order_item(...$args);
	}
	public function relay_woocommerce_pre_delete_order_refund() {
		$args = func_get_args();
		$this->getWC()->woocommerce_pre_delete_order_refund(...$args);
	}
	public function relay_woocommerce_delete_order_refund() {
		$args = func_get_args();
		$this->getWC()->woocommerce_delete_order_refund(...$args);
	}
	public function relay_woocommerce_product_data_tabs() {
		$args = func_get_args();
		return $this->getWC()->woocommerce_product_data_tabs(...$args);
	}
	public function relay_woocommerce_product_data_panels() {
		$this->getWC()->woocommerce_product_data_panels();
	}
	public function relay_woocommerce_process_product_meta() {
		$args = func_get_args();
		$this->getWC()->woocommerce_process_product_meta(...$args);
	}
	public function relay_add_meta_boxes(...$args) {
		$this->getWC()->add_meta_boxes(...$args);
	}
	public function relay_manage_edit_product_columns() {
		$args = func_get_args();
		return $this->getWC()->manage_edit_product_columns(...$args);
	}
	public function relay_manage_product_posts_custom_column() {
		$args = func_get_args();
		$this->getWC()->manage_product_posts_custom_column(...$args);
	}
	public function relay_manage_edit_product_sortable_columns() {
		$args = func_get_args();
		return $this->getWC()->manage_edit_product_sortable_columns(...$args);
	}
	public function relay_woocommerce_single_product_summary() {
		$this->getWC()->woocommerce_single_product_summary();
	}
	public function relay_woocommerce_order_status_changed() {
		$args = func_get_args();
		$this->getWC()->woocommerce_order_status_changed(...$args);
	}
	public function relay_woocommerce_order_partially_refunded() {
		$args = func_get_args();
		$this->getWC()->woocommerce_order_partially_refunded(...$args);
	}
	public function relay_woocommerce_update_order() {
		$args = func_get_args();
		$this->getWC()->woocommerce_update_order(...$args);
	}
	public function relay_woocommerce_order_item_display_meta_key() {
		$args = func_get_args();
		return $this->getWC()->woocommerce_order_item_display_meta_key(...$args);
	}
	public function relay_woocommerce_order_item_display_meta_value() {
		$args = func_get_args();
		return $this->getWC()->woocommerce_order_item_display_meta_value(...$args);
	}
	public function relay_wpo_wcpdf_after_item_meta() {
		$args = func_get_args();
		$this->getWC()->wpo_wcpdf_after_item_meta(...$args);
	}
	public function relay_woocommerce_order_item_meta_start() {
		$args = func_get_args();
		$this->getWC()->woocommerce_order_item_meta_start(...$args);
	}
	public function relay_woocommerce_product_after_variable_attributes() {
		$args = func_get_args();
		$this->getWC()->woocommerce_product_after_variable_attributes(...$args);
	}
	public function relay_woocommerce_save_product_variation() {
		$args = func_get_args();
		$this->getWC()->woocommerce_save_product_variation(...$args);
	}
	public function relay_woocommerce_email_order_meta() {
		$args = func_get_args();
		$this->getWC()->woocommerce_email_order_meta(...$args);
	}
	public function relay_woocommerce_thankyou() {
		$args = func_get_args();
		$this->getWC()->woocommerce_thankyou(...$args);
	}
	public function relay_sasoEventtickets_cronjob_daily() {
		$this->getTicketHandler()->cronJobDaily();
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
			add_options_page(__('Event Tickets', 'event-tickets-with-ticket-scanner'), 'Event Tickets', 'manage_options', 'event-tickets-with-ticket-scanner', [$this,'options_page']);
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
			'jsFiles' => plugins_url( 'backend.js?_v='.$this->_js_version,__FILE__ )
		);
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
		<div style="padding-top:10px;">
			<table style="border:none;width:98%;margin-bottom:10px;">
				<tr>
					<td style="vertical-align:middle;width:195px;">
						<img style="height:40px;" src="<?php echo plugins_url( "",__FILE__ ); ?>/img/logo_event-tickets-with-ticket-scanner.gif">
					</td>
					<td>
						<h2 style="margin-top:0;padding-top:0;margin-bottom:5px;">Event Tickets with Ticket Scanner <?php esc_html_e('Version', 'event-tickets-with-ticket-scanner'); ?>: <?php echo $versions_tail; ?></h2>
						<?php esc_html_e('If you like our plugin, then please give us a', 'event-tickets-with-ticket-scanner'); ?> <a target="_blank" href="https://wordpress.org/support/plugin/event-tickets-with-ticket-scanner/reviews?rate=5#new-post">★★★★★ 5-Star Rating</a>. <?php echo $version_tail_add; ?>
						</div>
					</td>
					<td style="font-size:20px;vertical-align:middle;text-align:right;" data-id="plugin_info_area_premium_status"></td>
				</tr>
			</table>
			<div style="clear:both;" data-id="plugin_addons"></div>
			<div style="clear:both;" data-id="plugin_info_area"></div>
			<div style="clear:both;" id="<?php echo esc_attr($this->_divId); ?>"></div>
			<div style="margin-top:100px;">
				<hr>
				<a name="shortcodedetails"></a>
				<h3>Documentation</h3>
				<p><span class="dashicons dashicons-external"></span><a href="https://vollstart.com/event-tickets-with-ticket-scanner/docs/" target="_blank">Click here, to visit the documentation of this plugin.</a></p>
				<h3><?php esc_html_e('Plugin Rating', 'event-tickets-with-ticket-scanner'); ?></h3>
				<p><?php esc_html_e('If you like our plugin, then please give us a', 'event-tickets-with-ticket-scanner'); ?> <a target="_blank" href="https://wordpress.org/support/plugin/event-tickets-with-ticket-scanner/reviews?rate=5#new-post">★★★★★ 5-Star Rating</a>.</p>
				<h3><?php esc_html_e('Ticket Sale option', 'event-tickets-with-ticket-scanner'); ?></h3>
				<p><?php esc_html_e('You can use this plugin to sell tickets and even redeem them. Check out the documentation for', 'event-tickets-with-ticket-scanner'); ?> <a target="_blank" href="https://vollstart.com/event-tickets-with-ticket-scanner/docs/#ticket"><?php esc_html_e('more details here', 'event-tickets-with-ticket-scanner'); ?></a>.</p>
				<h3><?php esc_html_e('Premium Homepage', 'event-tickets-with-ticket-scanner'); ?></h3>
				<p><?php esc_html_e('You can find more details about the', 'event-tickets-with-ticket-scanner'); ?> <a target="_blank" href="https://vollstart.com/event-tickets-with-ticket-scanner/"><?php esc_html_e('premium version here', 'event-tickets-with-ticket-scanner'); ?></a>.</p>
				<!--
				<h3>Shortcode parameter In- & Output</h3>
				<a href="https://vollstart.com/event-tickets-with-ticket-scanner/docs/" target="_blank">Click here for more help about the options</a>
				<p>You can use your own HTML input, output and trigger component. If you add the parameters (all 3 mandatory to use this feature), then the default input area will not be rendered.</p>
				<ul>
					<li><b>inputid</b><br>inputid="html-element-id". The value of this component will be taken. It need to be an HTML input element. We will access the value-parameter of it.</li>
					<li><b>triggerid</b><br>triggerid="html-element-id". The onclick event of this component will be replaced by our function to call the server validation with the code.</li>
					<li><b>outputid</b><br>outputid="html-element-id". The content of this component will be replaced by the server result after the check . We will use the innerHTML property of it, so use a DIV, SPAN, TD or similar for best results.</li>
				</ul>
				<h3>Shortcode parameter Javascript</h3>
				<p>You can add your Javascript function name. Both parameters are optional and not required. If functions will be called before the code is sent to the server or displaying the result.</p>
				<ul>
					<li><b>jspre</b><br>jspre="function-name". The function will be called. The input parameter will be the code. If your function returns a value, than this returned value will be used otherwise the entered code will be used.</li>
					<li><b>jsafter</b><br>jsafter="function-name". The function will be called. The input parameter will be the result JSON object from the server.</li>
				</ul>
				-->
				<h3><?php esc_html_e('Shortcode to display the event calendar form within a page', 'event-tickets-with-ticket-scanner'); ?></h3>
				<b>[<?php echo esc_html($this->_shortcode_eventviews); ?>]</b>
				<p><?php esc_html_e('The event calendar form will be displayed. You can add the following parameters to change the output:', 'event-tickets-with-ticket-scanner'); ?></p>
				<ul>
					<!--<li>view<br>Values: calendar, list or calendar|list<br>Default: list</li>-->
					<li>months_to_show<br>Values can be a number higher than 0. Default: 3</li>
				</ul>
				<p>CSS file: <a href="<?php echo plugins_url( "",__FILE__ ); ?>/css/calendar.css" target="_blank">calendar.css</a></p>
				<h3><?php esc_html_e('Shortcode to display the assigned tickets and codes of an user within a page', 'event-tickets-with-ticket-scanner'); ?></h3>
				<b>[<?php echo esc_html($this->_shortcode_mycode); ?>]</b>
				<p>
					The list will be human readable in a table - default.<br>
					You can add two parameter to change the output. <br>
					format: only json for now possible.
					display: one or more values. Values are seperated with a comma. Possible values: codes,validation,user,used,confirmedCount,woocommerce,wc_rp,wc_ticket<br>
					e.g. [<?php echo esc_html($this->_shortcode_mycode); ?> format="json" display="code,wc_ticket"]<br>
					The return value is a JSON string. So might want to add this shortcode within a Javascript HTML block to access the variable.
				</p>
				<h3><?php esc_html_e('Shortcode to display the ticket scanner within a page', 'event-tickets-with-ticket-scanner'); ?></h3>
				<?php esc_html_e('Useful if you cannot open the ticket scanner due to security issues.', 'event-tickets-with-ticket-scanner'); ?><br>
				<b>[<?php echo esc_html($this->_shortcode_ticket_scanner); ?>]</b>
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
				<p>More BETA filters and actions hooks can be found <a href="https://vollstart.com/event-tickets-with-ticket-scanner/docs/ticket-plugin-api/" target="_blank">here (NOT STABLE, be aware that they might be changed in the future)</a>.
				<p style="text-align:center;"><a target="_blank" href="https://vollstart.com">VOLLSTART</a> - More plugins: <a target="_blank" href="https://wordpress.org/plugins/serial-codes-generator-and-validator/">Serial Code Validator</a></p>
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
			$this->isAllowedAccess = true;
		}
		$this->isAllowedAccess = apply_filters( $this->_add_filter_prefix.'main_isUserAllowedToAccessAdminArea', $this->isAllowedAccess );
		return $this->isAllowedAccess;
	}

	public function executeAdminSettings_a() {
		if (!SASO_EVENTTICKETS::issetRPara('a_sngmbh')) return wp_send_json_success("a_sngmbh not provided");
		return $this->executeAdminSettings(SASO_EVENTTICKETS::getRequestPara('a_sngmbh')); // to prevent WP adds parameters
	}

	public function executeAdminSettings($a=0, $data=null) {
		if ($this->isUserAllowedToAccessAdminArea()) {
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
	}

	public function executeFrontend_a() {
		return $this->executeFrontend(); // to prevent WP adds parameters
	}

	public function executeWCBackend() {
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
		if (defined('WP_DEBUG')) $js_url .= '&t='.current_time("timestamp");
		$js_url = plugins_url( $js_url,__FILE__ );
		wp_register_script('ajax_script_ticket_scanner', $js_url, array('jquery', 'jquery-ui-dialog', 'wp-i18n'));
		wp_enqueue_script('ajax_script_ticket_scanner');
		wp_set_script_translations('ajax_script_ticket_scanner', 'event-tickets-with-ticket-scanner', __DIR__.'/languages');

		$ticketScannerDontRememberCamChoice = $this->getOptions()->isOptionCheckboxActive("ticketScannerDontRememberCamChoice") ? true : false;

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
			'ticketScannerDontShowBtnPDF' => $this->getOptions()->isOptionCheckboxActive('ticketScannerDontShowBtnPDF'),
			'ticketScannerDontShowBtnBadge' => $this->getOptions()->isOptionCheckboxActive('ticketScannerDontShowBtnBadge'),
			'ticketScannerDisplayTimes' => $this->getOptions()->isOptionCheckboxActive('ticketScannerDisplayTimes')
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

	public function getMyCodeText($user_id, $attr=[], $content = null, $tag = '') {
		$ret = '';
		// check ob eingeloggt
		$pre_text = $this->getOptions()->getOptionValue('userDisplayCodePrefix', '');
		if (!empty($pre_text)) $pre_text .= " ";

		if ($user_id > 0) {
			// lade codes mit user_id
			$codes = $this->getCore()->getCodesByRegUserId($user_id);
			$ret .= "<b>".$pre_text."</b><br>";
			$ret .= $this->getCodesTextAsShortList($codes);
		}
		if (empty($ret) && $this->getOptions()->isOptionCheckboxActive('userDisplayCodePrefixAlways')) {
			$ret .= $pre_text;
		}
		$ret = apply_filters( $this->_add_filter_prefix.'main_getMyCodeText', $ret, $user_id, $attr, $content, $tag);
		return $ret;
	}
	public function getMyCodeFormatted($user_id, $attr=[], $content = null, $tag = '') {
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
		$codes = $this->getCore()->getCodesByRegUserId($user_id);
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

		if (count($attr) > 0 && isset($attr["format"])) {
			return $this->getMyCodeFormatted($user_id, $attr, $content, $tag);
		} else {
			return $this->getMyCodeText($user_id, $attr, $content, $tag);
		}
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

		$month_start = strtotime(date("Y-m-1 00:00:00"));
		//$month_end = strtotime(date("Y-m-t 23:59:59"));
		$month_end = strtotime(date("Y-m-1 23:59:59", strtotime("+".$months_to_show." month", $month_start)));

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
		if (defined('WP_DEBUG')) $js_url .= '&t='.current_time("timestamp");
		$js_url = plugins_url( $js_url,__FILE__ );
		wp_register_script('ajax_script_ticket_events', $js_url, array('jquery', 'jquery-ui-dialog', 'wp-i18n'));
		wp_enqueue_script('ajax_script_ticket_events');
		wp_set_script_translations('ajax_script_ticket_events', 'event-tickets-with-ticket-scanner', __DIR__.'/languages');
		wp_enqueue_style("ticket_events_css", plugins_url( "",__FILE__ ).'/css/calendar.css');

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
}
$sasoEventtickets = sasoEventtickets::Instance();
?>
