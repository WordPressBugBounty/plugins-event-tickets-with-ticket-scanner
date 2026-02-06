<?php
/**
 * WooCommerce Frontend Handler
 *
 * Handles customer-facing WooCommerce functionality including cart operations,
 * checkout validation, product page display, and thank you page customization.
 *
 * @package    Event_Tickets_With_Ticket_Scanner
 * @subpackage WooCommerce
 * @since      2.9.0
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
	exit;
}

/**
 * WooCommerce Frontend Handler Class
 *
 * Manages customer-facing WooCommerce integration:
 * - Cart operations and validation
 * - Checkout process and restrictions
 * - Product page display enhancements
 * - Add to cart validation
 * - Thank you page customization
 *
 * @since 2.9.0
 */
if (!class_exists('sasoEventtickets_WC_Frontend')) {
	class sasoEventtickets_WC_Frontend extends sasoEventtickets_WC_Base {

		/**
		 * Cache for products with restrictions check
		 *
		 * @var bool|null
		 */
		private $_containsProductsWithRestrictions = null;

		/**
		 * JavaScript input type identifier
		 *
		 * @var string
		 */
		private $js_inputType = 'eventcoderestriction';

		/**
		 * Field key for datepicker in shop/product pages
		 *
		 * @var string
		 */
		const FIELD_KEY = 'event_date';

		/**
		 * Nonce key for datepicker security
		 *
		 * @var string
		 */
		const NONCE_KEY = 'saso_eventtickets_wc_datepicker_nonce';

		/**
		 * Constructor
		 *
		 * @param sasoEventtickets $main Main plugin instance
		 */
		public function __construct($main) {
			parent::__construct($main);
		}

		/**
		 * Initialize cart table display
		 * Registers the after_cart_item_name hook and loads JS if cart contains tickets
		 *
		 * @return void
		 */
		public function woocommerce_before_cart_table(): void {
			// Register hook for rendering input fields after cart item name
			add_action('woocommerce_after_cart_item_name', [$this, 'woocommerce_after_cart_item_name_handler'], 10, 2);

			$added = false;
			if ($this->MAIN->getOptions()->isOptionCheckboxActive('wcRestrictPurchase')) {
				if ($this->containsProductsWithRestrictions()) {
					$this->addJSFileAndHandler();
					$added = true;
				}
			}
			if ($this->hasTicketsInCart() && $added === false) {
				$this->addJSFileAndHandler();
			}
		}

		/**
		 * Display ticket date on single product page
		 *
		 * Shows event date information below product summary if enabled.
		 *
		 * @return void
		 */
		public function woocommerce_single_product_summary(): void {
			if (!$this->MAIN->getOptions()->isOptionCheckboxActive('wcTicketDisplayDateOnPrdDetail')) {
				return;
			}

			global $product;

			// Fallback if global product is not set
			if (!$product instanceof \WC_Product) {
				$product = wc_get_product(get_the_ID());
			}
			if (!$product) {
				return;
			}

			$product_id = $product->get_id();
			$product_id_orig = $this->MAIN->getTicketHandler()->getWPMLProductId($product_id);

			// Retrieve WooCommerce date and time formats
			$date_format = get_option('date_format');
			$time_format = get_option('time_format');

			$date_str = $this->MAIN->getTicketHandler()->displayTicketDateAsString($product_id_orig, $date_format, $time_format);
			if (!empty($date_str)) {
				echo "<br>" . $date_str;
			}
		}

		/**
		 * Display ticket information on thank you page
		 *
		 * Shows PDF download link and order tickets view link if configured.
		 *
		 * @param int $order_id Order ID
		 * @return void
		 */
		public function woocommerce_thankyou(int $order_id = 0): void {
			$order_id = intval($order_id);
			if ($order_id <= 0) {
				return;
			}

			$order = wc_get_order($order_id);
			if (!$order) {
				return;
			}

			$hasTickets = $this->MAIN->getWC()->getOrderManager()->hasTicketsInOrderWithTicketnumber($order);
			if (!$hasTickets) {
				return;
			}

			$isHeaderAdded = false;

			// Display PDF download button
			if ($this->MAIN->getOptions()->isOptionCheckboxActive('wcTicketDisplayDownloadAllTicketsPDFButtonOnCheckout')) {
				$url = $this->MAIN->getCore()->getOrderTicketsURL($order);
				$dlnbtnlabel = $this->MAIN->getOptions()->getOptionValue('wcTicketLabelPDFDownload');
				$dlnbtnlabelHeading = trim($this->MAIN->getOptions()->getOptionValue('wcTicketLabelPDFDownloadHeading'));

				if (!empty($dlnbtnlabelHeading)) {
					echo '<h2>' . esc_html($dlnbtnlabelHeading) . '</h2>';
				}
				echo '<p><a target="_blank" href="' . esc_url($url) . '"><b>' . esc_html($dlnbtnlabel) . '</b></a></p>';
				$isHeaderAdded = true;
			}

			// Display order tickets view link
			if ($this->MAIN->getOptions()->isOptionCheckboxActive('wcTicketDisplayOrderTicketsViewLinkOnCheckout')) {
				$url = $this->MAIN->getCore()->getOrderTicketsURL($order, "ordertickets-");
				$dlnbtnlabel = $this->MAIN->getOptions()->getOptionValue('wcTicketLabelOrderDetailView');

				if (!$isHeaderAdded) {
					$dlnbtnlabelHeading = trim($this->MAIN->getOptions()->getOptionValue('wcTicketLabelPDFDownloadHeading'));
					if (!empty($dlnbtnlabelHeading)) {
						echo '<h2>' . esc_html($dlnbtnlabelHeading) . '</h2>';
					}
				}
				echo '<p><a target="_blank" href="' . esc_url($url) . '"><b>' . esc_html($dlnbtnlabel) . '</b></a></p>';
			}
		}

		// =====================================================================
		// Migrated Methods (from woocommerce-hooks.php)
		// =====================================================================

		/**
		 * Check if cart contains tickets
		 *
		 * @return bool True if cart contains ticket products
		 */
		public function hasTicketsInCart(): bool {
			if (WC()->cart === null) {
				return false;
			}
			foreach (WC()->cart->get_cart() as $cart_item) {
				$saso_eventtickets_list_id = get_post_meta($cart_item['product_id'], "saso_eventtickets_list", true);
				if (!empty($saso_eventtickets_list_id)) {
					return true;
				}
			}
			return false;
		}

		/**
		 * Check if cart contains products with purchase restrictions
		 *
		 * @return bool True if cart contains products with restrictions
		 */
		public function containsProductsWithRestrictions(): bool {
			if ($this->_containsProductsWithRestrictions === null) {
				$this->_containsProductsWithRestrictions = false;
				if (WC()->cart === null) {
					return false;
				}
				foreach (WC()->cart->get_cart() as $cart_item) {
					$saso_eventtickets_list = get_post_meta($cart_item['product_id'], self::META_KEY_CODELIST_RESTRICTION, true);
					if (!empty($saso_eventtickets_list)) {
						$this->_containsProductsWithRestrictions = true;
						break;
					}
				}
			}
			return $this->_containsProductsWithRestrictions;
		}

		/**
		 * Load frontend JavaScript and localize script variables
		 *
		 * @param array $additional_values Additional values to pass to JavaScript
		 * @return void
		 */
		public function addJSFileAndHandler(array $additional_values = []): void {
			if (version_compare(WC_VERSION, SASO_EVENTTICKETS_PLUGIN_MIN_WC_VER, '<')) {
				return;
			}

			wp_register_style('jquery-ui', 'https://code.jquery.com/ui/1.12.1/themes/smoothness/jquery-ui.css');
			wp_enqueue_style('jquery-ui');
			wp_enqueue_style("wp-jquery-ui-dialog");
			wp_enqueue_style("wp-jquery-ui-datepicker");
			wp_enqueue_style("jquery-ui");
			wp_enqueue_style("jquery-ui-datepicker");

			wp_register_script(
				'SasoEventticketsValidator_WC_frontend',
				trailingslashit(plugin_dir_url(dirname(__DIR__))) . 'wc_frontend.js?_v=' . $this->MAIN->getPluginVersion(),
				array('jquery', 'jquery-ui-dialog', 'jquery-blockui', 'jquery-ui-datepicker', 'wp-i18n'),
				(current_user_can("administrator") ? time() : $this->MAIN->getPluginVersion()),
				true
			);
			wp_set_script_translations('SasoEventticketsValidator_WC_frontend', 'event-tickets-with-ticket-scanner', dirname(dirname(__DIR__)) . '/languages');

			$values = [
				'ajaxurl' => admin_url('admin-ajax.php'),
				'inputType' => $this->js_inputType,
				'action' => $this->MAIN->getPrefix() . '_executeWCFrontend',
				'nonce' => wp_create_nonce($this->MAIN->_js_nonce),
			];
			foreach ($additional_values as $k => $v) {
				$values[$k] = $v;
			}

			wp_localize_script(
				'SasoEventticketsValidator_WC_frontend',
				'SasoEventticketsValidator_phpObject',
				$values
			);
			wp_enqueue_script('SasoEventticketsValidator_WC_frontend');
		}

		/**
		 * Handle WooCommerce frontend AJAX requests
		 *
		 * @return void
		 */
		public function executeWCFrontend(): void {
			$nonce_mode = $this->MAIN->_js_nonce;
			if (!SASO_EVENTTICKETS::issetRPara('security') || !wp_verify_nonce(SASO_EVENTTICKETS::getRequestPara('security'), $nonce_mode)) {
				wp_send_json(['nonce_fail' => 1]);
				exit;
			}
			if (!SASO_EVENTTICKETS::issetRPara('a')) {
				wp_send_json_error("a not provided");
				return;
			}

			$ret = "";
			$a = trim(SASO_EVENTTICKETS::getRequestPara('a'));
			try {
				switch ($a) {
					case "updateSerialCodeToCartItem":
						$ret = $this->wc_frontend_updateSerialCodeToCartItem();
						break;
					case "updateSerialCodeToCartItemRestriction":
						$ret = $this->wc_frontend_updateSerialCodeToCartItemRestriction();
						break;
					default:
						throw new Exception("#6003 " . sprintf(
							esc_html__('function "%s" not implemented', 'event-tickets-with-ticket-scanner'),
							$a
						));
				}
			} catch (Exception $e) {
				$this->MAIN->getAdmin()->logErrorToDB($e);
				wp_send_json_error(['msg' => $e->getMessage()]);
				return;
			}
			wp_send_json_success($ret);
		}

		/**
		 * Update cart item meta data
		 *
		 * @param string $type Meta type
		 * @param string $cart_item_id Cart item ID
		 * @param int $cart_item_count Cart item count index
		 * @param mixed $value Value to store
		 * @return array Check values result
		 */
		public function updateCartItemMeta(string $type, string $cart_item_id, int $cart_item_count, $value): array {
			if (!in_array($type, [
				'saso_eventtickets_request_name_per_ticket',
				'saso_eventtickets_request_value_per_ticket',
				'saso_eventtickets_request_daychooser'
			])) {
				$type = 'saso_eventtickets_request_name_per_ticket';
			}

			$check_values = [];
			if (empty($cart_item_id)) {
				$check_values["item_id_missing"] = true;
			} else {
				if ($type == 'saso_eventtickets_request_daychooser') {
					$line = null;
					$cart = WC()->cart;
					$date = sanitize_text_field($value);
					try {
						$line =& $cart->cart_contents[$cart_item_id];
					} catch (Exception $e) {
						$line = null;
					}

					if ($line === null) {
						$check_values["item_not_in_cart"] = true;
					} else {
						$key = self::SESSION_KEY_DAYCHOOSER;
						$valueArray = $this->session_get_value($key . '_' . $cart_item_id);
						if ($valueArray !== null && is_array($valueArray)) {
							$line[$key] = $valueArray;
						}
						if (!isset($line[$key]) || !is_array($line[$key])) {
							$line[$key] = [];
						}
						if (count($line[$key]) < $cart_item_count) {
							$line[$key] = array_pad($line[$key], $cart_item_count, $date);
						}
						$line[$key][$cart_item_count] = $date;

						$product_id = $line['product_id'];
						$product_id_orig = $this->MAIN->getTicketHandler()->getWPMLProductId($product_id);
						$display_only_one_datepicker = get_post_meta($product_id_orig, 'saso_eventtickets_only_one_day_for_all_tickets', true) == "yes";
						if ($display_only_one_datepicker) {
							$line[$key] = array_fill(0, $line["quantity"], $date);
						}

						WC()->cart->set_session();
						$this->session_set_value($key . '_' . $cart_item_id, $line[$key]);
					}
				} else {
					$valueArray = WC()->session->get($type);
					if ($valueArray === null) {
						$valueArray = [];
					}
					if (!isset($valueArray[$cart_item_id]) || !is_array($valueArray[$cart_item_id])) {
						$valueArray[$cart_item_id] = [];
					}
					$valueArray[$cart_item_id][$cart_item_count] = $value;
					WC()->session->set($type, $valueArray);
				}
			}
			return $check_values;
		}

		/**
		 * Handle serial code update for cart item (AJAX handler)
		 *
		 * @return void
		 */
		private function wc_frontend_updateSerialCodeToCartItem(): void {
			$cart_item_id = sanitize_key(SASO_EVENTTICKETS::getRequestPara('cart_item_id'));
			$cart_item_count = intval(SASO_EVENTTICKETS::getRequestPara('cart_item_count'));
			$type = sanitize_key(SASO_EVENTTICKETS::getRequestPara('type'));
			$code = trim(SASO_EVENTTICKETS::getRequestPara('code'));

			$check_values = $this->updateCartItemMeta($type, $cart_item_id, $cart_item_count, $code);

			wp_send_json(['success' => 1, 'code' => esc_attr($code), 'check_values' => $check_values, 'type' => $type]);
			exit;
		}

		/**
		 * Handle serial code restriction update for cart item (AJAX handler)
		 *
		 * @return void
		 */
		private function wc_frontend_updateSerialCodeToCartItemRestriction(): void {
			$cart = WC()->cart->cart_contents;
			$cart_item_id = sanitize_key(SASO_EVENTTICKETS::getRequestPara('cart_item_id'));
			$code = sanitize_key(SASO_EVENTTICKETS::getRequestPara('code'));
			$code = strtoupper($code);

			$check_values = [];
			if (empty($cart_item_id)) {
				$check_values["item_id_missing"] = true;
			} else {
				$cart_item = $cart[$cart_item_id];
				$cart_item[self::META_KEY_CODELIST_RESTRICTION_order_item] = $code;

				WC()->cart->cart_contents[$cart_item_id] = $cart_item;
				WC()->cart->set_session();

				switch ($this->check_code_for_cartitem($cart_item, $code)) {
					case 0:
						$check_values['isEmpty'] = true;
						break;
					case 1:
						$check_values['isValid'] = true;
						break;
					case 2:
						$check_values['isUsed'] = true;
						break;
					case 3:
					case 4:
					default:
						$check_values['notValid'] = true;
				}
			}

			wp_send_json(['success' => 1, 'code' => esc_attr(strtoupper($code)), 'check_values' => $check_values]);
			exit;
		}

		/**
		 * Check if code is valid for cart item
		 *
		 * @param array $cart_item Cart item data
		 * @param string $code Code to check
		 * @return int Status: 0=empty, 1=valid, 2=used, 3=not valid, 4=no code list
		 */
		public function check_code_for_cartitem(array $cart_item, string $code): int {
			$ret = 0; // empty
			if (!empty($code)) {
				$saso_eventtickets_list_id = get_post_meta($cart_item['product_id'], self::META_KEY_CODELIST_RESTRICTION, true);
				if (!empty($saso_eventtickets_list_id)) {
					try {
						$codeObj = $this->MAIN->getCore()->retrieveCodeByCode($code);
						if ($codeObj['aktiv'] != 1) {
							return 3; // not valid - ticket not active
						}
						if ($saso_eventtickets_list_id != "0" && $codeObj['list_id'] != $saso_eventtickets_list_id) {
							return 3; // not valid - wrong list
						}
						if ($this->MAIN->getFrontend()->isUsed($codeObj)) {
							return 2; // used
						} else {
							return 1; // valid
						}
					} catch (Exception $e) {
						$ret = 3; // not valid - code not found
					}
				} else {
					$ret = 4; // no code list defined
				}
			}
			return $ret;
		}

		/**
		 * Handle cart update - save custom field data to session
		 *
		 * @return void
		 */
		public function woocommerce_cart_updated_handler(): void {
			$R = SASO_EVENTTICKETS::getRequest();
			if (isset($R["action"]) && strtolower($R["action"]) == "heartbeat") {
				return;
			}
			$session_keys = ['saso_eventtickets_request_name_per_ticket', 'saso_eventtickets_request_value_per_ticket'];
			$cart = null;
			foreach ($session_keys as $k) {
				if (isset($R[$k])) { // wenn der warenkorb aktualisiert wird und das feld gesendet wird
					$values = [];
					if ($cart == null) {
						$cart = WC()->cart;
					}
					foreach ($cart->get_cart() as $cart_item) {
						if (isset($R[$k][$cart_item['key']])) {
							$value = $R[$k][$cart_item['key']];
							$values[$cart_item['key']] = $value;
						}
					}
					if (count($values) > 0) {
						WC()->session->set($k, $values);
					} else {
						WC()->session->__unset($k);
					}
				}
			}
		}

		/**
		 * Render datepicker HTML for day chooser
		 *
		 * @param string $cart_item_key Cart item key
		 * @param int $a Cart item count (index 0..n)
		 * @param int $product_id Product ID
		 * @param string $value Current value
		 * @param string|null $label Label text
		 * @param array|null $valueArray Array of values
		 * @param array|null $dates Date settings
		 * @param string|null $name Input name
		 * @param array $custom_attributes Custom HTML attributes
		 * @param bool $disabled Whether to disable the datepicker (e.g., when seats are selected)
		 * @param string $disabled_reason Reason for disabling (shown as message)
		 * @return void
		 */
		public function addDatepickerHTML($cart_item_key, $a, $product_id, $value = "", $label = null, $valueArray = null, $dates = null, $name = null, $custom_attributes = [], bool $disabled = false, string $disabled_reason = ''): void {
			$cart_item_key = sanitize_key($cart_item_key);
			$product_id = intval($product_id);
			$product_id_orig = $this->MAIN->getTicketHandler()->getWPMLProductId($product_id);
			$value = sanitize_text_field($value);

			$display_only_one_datepicker = get_post_meta($product_id_orig, 'saso_eventtickets_only_one_day_for_all_tickets', true) == "yes";

			if ($label === null) {
				$label = esc_attr($this->MAIN->getTicketHandler()->getLabelDaychooserPerTicket($product_id));
			}
			if ($valueArray == null) {
				$key = self::SESSION_KEY_DAYCHOOSER;
				$cart = WC()->cart->get_cart();
				if (isset($cart[$cart_item_key])) {
					$cart_item = $cart[$cart_item_key];
					$valueArray = isset($cart_item[$key]) ? $cart_item[$key] : null;
					// fallback to session in case the item meta is adjusted by other plugins
					if ($valueArray == null) {
						$valueArray = $this->session_get_value($key . '_' . $cart_item_key);
					}
				}
			}
			if (empty($value) && $valueArray != null && isset($valueArray[$a])) {
				$value = $valueArray[$a];
			}
			if ($dates == null) {
				$dates = $this->MAIN->getTicketHandler()->getCalcDateStringAllowedRedeemFromCorrectProduct($product_id);
			}
			$saso_eventtickets_daychooser_offset_start = $dates['daychooser_offset_start'];
			$saso_eventtickets_daychooser_offset_end = $dates['daychooser_offset_end'];
			$saso_eventtickets_daychooser_exclude_wdays = $dates['daychooser_exclude_wdays'];
			$saso_eventtickets_ticket_start_date = $dates['ticket_start_date'];
			$saso_eventtickets_ticket_end_date = $dates['ticket_end_date'];
			if (!is_array($saso_eventtickets_daychooser_exclude_wdays)) {
				$saso_eventtickets_daychooser_exclude_wdays = [];
			}

			$params = [
				'type' => 'text',
				'custom_attributes' => [
					'data-input-type' => 'daychooser',
					'data-plugin' => 'event',
					'data-plg' => esc_attr($this->MAIN->getPrefix()),
					'data-product-id' => $product_id_orig,
					'data-cart-item-id' => $cart_item_key,
					'data-cart-item-count' => $a,
					"data-only-one-datepicker" => $display_only_one_datepicker ? "1" : "0",
					'style' => 'width:auto;',
					'required' => 'required',
					'onClick' => 'window.SasoEventticketsValidator_WC_frontend._addHandlerToTheCodeFields();',
				],
				'id' => 'saso_eventtickets_request_daychooser[' . $cart_item_key . '][' . $a . ']',
				'class' => array('form-row-first input-text text'),
				'required' => true,
			];
			if (!empty($custom_attributes) && is_array($custom_attributes)) {
				foreach ($custom_attributes as $k => $v) {
					$params['custom_attributes'][$k] = $v;
				}
			}
			if ($label != null) {
				$params['label'] = esc_attr(str_replace("{count}", $a + 1, $label));
			}
			if ($name != null) {
				$params['custom_attributes']['name'] = esc_attr($name);
			} else {
				$params['custom_attributes']['name'] = 'saso_eventtickets_request_daychooser[' . $cart_item_key . '][]';
			}
			$params['custom_attributes']['data-offset-start'] = $saso_eventtickets_daychooser_offset_start;
			$params['custom_attributes']['data-offset-end'] = $saso_eventtickets_daychooser_offset_end;
			$params['custom_attributes']['data-exclude-wdays'] = is_array($saso_eventtickets_daychooser_exclude_wdays) ? implode(",", $saso_eventtickets_daychooser_exclude_wdays) : $saso_eventtickets_daychooser_exclude_wdays;

			if ($this->MAIN->isPremium() && method_exists($this->MAIN->getPremiumFunctions(), 'getDayChooserExclusionDates')) {
				$exclusionDates = $this->MAIN->getPremiumFunctions()->getDayChooserExclusionDates($product_id_orig);
				if (!empty($exclusionDates)) {
					$params['custom_attributes']['data-exclude-dates'] = implode(",", $exclusionDates);
				}
			}

			if ($saso_eventtickets_ticket_start_date != "") {
				$params['custom_attributes']['min'] = $saso_eventtickets_ticket_start_date;
			}
			if ($saso_eventtickets_daychooser_offset_start > 0) {
				// if the start date is not set, then we set it to today + days offset
				if (!isset($params['custom_attributes']['min'])) {
					$params['custom_attributes']['min'] = date("Y-m-d", strtotime("+" . $saso_eventtickets_daychooser_offset_start . " days"));
				} else {
					// if the start date + offset days is set before the ticket start date then use the start date
					if (time() < strtotime($params['custom_attributes']['min'] . " -" . $saso_eventtickets_daychooser_offset_start . " days")) {
						$params['custom_attributes']['min'] = $saso_eventtickets_ticket_start_date;
					} else {
						$params['custom_attributes']['min'] = date("Y-m-d", strtotime("+" . $saso_eventtickets_daychooser_offset_start . " days"));
					}
				}
			}
			if ($saso_eventtickets_ticket_end_date != "") {
				$params['custom_attributes']['max'] = $saso_eventtickets_ticket_end_date;
			}
			if (!isset($params['custom_attributes']['max']) && $saso_eventtickets_daychooser_offset_end > 0) {
				$params['custom_attributes']['max'] = date("Y-m-d", strtotime("+" . $saso_eventtickets_daychooser_offset_end . " days"));
			}

			// Disable datepicker if seats are selected (date change would invalidate seat blocks)
			if ($disabled) {
				$params['custom_attributes']['readonly'] = 'readonly';
				$params['custom_attributes']['disabled'] = 'disabled';
				$params['class'][] = 'saso-datepicker-disabled';
			}

			echo '<div id="datepicker-wrapper_' . $cart_item_key . '_' . $a . '" class="' . ($disabled ? 'saso-datepicker-locked' : '') . '">';
			woocommerce_form_field('saso_eventtickets_request_daychooser[' . $cart_item_key . '][]', $params, $value);

			// Show reason for disabled state
			if ($disabled && !empty($disabled_reason)) {
				echo '<p class="saso-datepicker-locked-reason"><small>' . esc_html($disabled_reason) . '</small></p>';
			}

			echo '</div>';
		}

		/**
		 * Render input fields after cart item name
		 *
		 * Displays input fields for:
		 * - Purchase restriction codes
		 * - Day chooser datepickers
		 * - Name per ticket inputs
		 * - Value per ticket dropdowns
		 *
		 * @param array $cart_item Cart item data
		 * @param string $cart_item_key Cart item key
		 * @return void
		 */
		public function woocommerce_after_cart_item_name_handler(array $cart_item, string $cart_item_key): void {
			// Show input for purchase restriction code
			$saso_eventtickets_list = get_post_meta($cart_item['product_id'], self::META_KEY_CODELIST_RESTRICTION, true);
			if (!empty($saso_eventtickets_list)) {
				$code = isset($cart_item[self::META_KEY_CODELIST_RESTRICTION_order_item]) ? $cart_item[self::META_KEY_CODELIST_RESTRICTION_order_item] : '';
				$infoLabel = $this->MAIN->getOptions()->getOptionValue('wcRestrictCartInfo');
				$fieldPlaceholder = $this->MAIN->getOptions()->getOptionValue('wcRestrictCartFieldPlaceholder');
				$html = '<div><small>' . esc_attr($infoLabel) . '<br></small>
							<input
								type="text"
								maxlength="140"
								placeholder="%s"
								data-input-type="%s"
								data-cart-item-id="%s"
								data-plugin="event"
								data-plg="' . esc_attr($this->MAIN->getPrefix()) . '"
								value="%s"
								class="input-text text" /></div>';
				printf(
					str_replace("\n", "", $html),
					esc_attr($fieldPlaceholder),
					esc_attr($this->js_inputType),
					esc_attr($cart_item_key),
					wc_clean($code)
				);
			}

			// Check if the product is a daychooser
			$saso_eventtickets_is_daychooser = get_post_meta($cart_item['product_id'], "saso_eventtickets_is_daychooser", true) == "yes";
			// Render the datepicker
			if ($saso_eventtickets_is_daychooser) {
				$anzahl = intval($cart_item["quantity"]);
				if ($anzahl > 0) {
					$key = self::SESSION_KEY_DAYCHOOSER;
					$valueArray = isset($cart_item[$key]) ? $cart_item[$key] : null;
					if ($valueArray == null) {
						$valueArray = $this->session_get_value($key . '_' . $cart_item_key);
					}

					$product_id = $cart_item['product_id'];
					$product_id_orig = $this->MAIN->getTicketHandler()->getWPMLProductId($product_id);

					$dates = $this->MAIN->getTicketHandler()->getCalcDateStringAllowedRedeemFromCorrectProduct($product_id);
					$label = esc_attr($this->MAIN->getTicketHandler()->getLabelDaychooserPerTicket($product_id_orig));
					$display_only_one_datepicker = get_post_meta($product_id_orig, 'saso_eventtickets_only_one_day_for_all_tickets', true) == "yes";
					if ($display_only_one_datepicker) {
						$anzahl = 1; // force only one datepicker
					}

					// Check if seats are selected - if so, lock the datepicker
					$seating = $this->MAIN->getSeating();
					$seatsData = $cart_item[$seating->getMetaCartItemSeat()] ?? null;
					$hasSeats = !empty($seatsData) && is_array($seatsData) &&
						(isset($seatsData['seat_id']) || (isset($seatsData[0]['seat_id'])));
					$disableDatepicker = false;
					$disabledReason = '';

					if ($hasSeats) {
						// Check if a date was already selected
						$hasDateSelected = !empty($valueArray) && !empty($valueArray[0]);
						if ($hasDateSelected) {
							$disableDatepicker = true;
							$disabledReason = __('Date locked: Seats are selected for this date. Remove item to change date.', 'event-tickets-with-ticket-scanner');
						}
					}

					for ($a = 0; $a < $anzahl; $a++) {
						$value = "";
						if ($valueArray != null && isset($valueArray[$a])) {
							$value = trim($valueArray[$a]);
						}
						// Don't pass reason to addDatepickerHTML - we show it once after the loop
						$this->addDatepickerHTML($cart_item_key, $a, $product_id, $value, $label, $valueArray, $dates, null, [], $disableDatepicker, '');
						echo '<br clear="all"></div>';
					}

					// Show locked reason once after all datepickers
					if ($disableDatepicker && !empty($disabledReason)) {
						echo '<p class="saso-datepicker-locked-reason"><small>' . esc_html($disabledReason) . '</small></p>';
					}

					// Show message if seats are required but not selected
					if (!$hasSeats) {
						$planId = get_post_meta($product_id_orig, $seating->getMetaProductSeatingplan(), true);
						$seatingRequired = get_post_meta($product_id_orig, $seating->getMetaProductSeatingRequired(), true) === 'yes';
						if (!empty($planId) && $seatingRequired) {
							echo '<p class="saso-seats-required-notice">';
							echo '<strong>' . esc_html__('Note:', 'event-tickets-with-ticket-scanner') . '</strong> ';
							echo esc_html__('Please select your seats on the product page before checkout.', 'event-tickets-with-ticket-scanner');
							echo '</p>';
						}
					}
				}
			}

			// Display selected seat info with countdown (seats stored as array)
			$seatsData = $cart_item[$this->MAIN->getSeating()->getMetaCartItemSeat()] ?? null;
			if (!empty($seatsData) && is_array($seatsData)) {
				// Normalize: if it's a single seat object, wrap in array
				if (isset($seatsData['seat_id'])) {
					$seatsData = [$seatsData];
				}

				if (!empty($seatsData) && isset($seatsData[0]['seat_id'])) {
					// Enqueue CSS and JS for seat display with countdown
					$this->MAIN->getSeating()->getFrontendManager()->enqueueScripts();

					// Check if expiration time should be hidden (option)
					$hideExpiration = $this->MAIN->getOptions()->isOptionCheckboxActive('seatingHideExpirationTime');

					// Sort seats by label
					usort($seatsData, function($a, $b) {
						$labelA = $a['seat_label'] ?? '';
						$labelB = $b['seat_label'] ?? '';
						return strnatcasecmp($labelA, $labelB);
					});

					// Build seat list HTML with countdown
					$seatTitle = count($seatsData) > 1
						? esc_html__('Seats:', 'event-tickets-with-ticket-scanner')
						: esc_html__('Seat:', 'event-tickets-with-ticket-scanner');

					echo '<div class="saso-cart-seat-info">';
					echo '<strong>' . $seatTitle . '</strong>';
					echo '<div class="saso-selected-seats-labels">';
					echo '<ul class="saso-seat-list">';

					$showDescInCart = $this->MAIN->getOptions()->isOptionCheckboxActive('seatingShowDescInCart');
					foreach ($seatsData as $seat) {
						$label = esc_html($seat['seat_label'] ?? '');
						if (!empty($seat['seat_category'])) {
							$label .= ' <small>(' . esc_html($seat['seat_category']) . ')</small>';
						}
						if ($showDescInCart && !empty($seat['seat_desc'])) {
							$label .= '<br><small>' . esc_html($seat['seat_desc']) . '</small>';
						}

						// Calculate remaining seconds for countdown (avoids timezone issues)
						$remainingAttr = '';
						$remainingSeconds = 0;
						if (!$hideExpiration && !empty($seat['expires_at'])) {
							$expiresTimestamp = strtotime($seat['expires_at']);
							$remainingSeconds = max(0, $expiresTimestamp - current_time('timestamp'));
							$remainingAttr = ' data-remaining-seconds="' . (int) $remainingSeconds . '"';
						}

						echo '<li class="saso-seat-item" data-seat-id="' . esc_attr($seat['seat_id'] ?? '') . '"' . $remainingAttr . '>';
						echo '<span class="saso-seat-name">' . $label . '</span>';
						// Only show countdown if not hidden by option
						if ($remainingSeconds > 0) {
							echo '<span class="saso-seat-countdown" data-remaining-seconds="' . (int) $remainingSeconds . '"></span>';
						}
						echo '</li>';
					}

					echo '</ul>';
					echo '</div>';
					echo '</div>';
				}
			}

			// Handle name per ticket input
			$saso_eventtickets_request_name_per_ticket = get_post_meta($cart_item['product_id'], "saso_eventtickets_request_name_per_ticket", true) == "yes";
			if ($saso_eventtickets_request_name_per_ticket) {
				$anzahl = intval($cart_item["quantity"]);
				if ($anzahl > 0) {
					$valueArray = WC()->session->get("saso_eventtickets_request_name_per_ticket");

					$label = esc_attr($this->MAIN->getTicketHandler()->getLabelNamePerTicket($cart_item['product_id']));
					for ($a = 0; $a < $anzahl; $a++) {
						$value = "";
						if ($valueArray != null && isset($valueArray[$cart_item_key]) && isset($valueArray[$cart_item_key][$a])) {
							$value = trim($valueArray[$cart_item_key][$a]);
						}
						$html = '<div class="saso_eventtickets_request_name_per_ticket_label"><small>' . str_replace("{count}", $a + 1, $label) . '<br></small>
								<input type="text" data-input-type="text"
									name="saso_eventtickets_request_name_per_ticket[%s][]"
									data-cart-item-id="%s"
									data-cart-item-count="%s"
									data-plugin="event"
									data-plg="' . esc_attr($this->MAIN->getPrefix()) . '"
									value="%s"
									class="input-text text" /></div>';
						printf(
							str_replace("\n", "", $html),
							esc_attr($cart_item_key),
							esc_attr($cart_item_key),
							esc_attr($a),
							esc_attr($value)
						);
					}
				}
			}

			// Handle value per ticket dropdown
			$saso_eventtickets_request_value_per_ticket = get_post_meta($cart_item['product_id'], "saso_eventtickets_request_value_per_ticket", true) == "yes";
			if ($saso_eventtickets_request_value_per_ticket) {
				$anzahl = intval($cart_item["quantity"]);
				if ($anzahl > 0) {
					$valueArray = WC()->session->get("saso_eventtickets_request_value_per_ticket");

					$dropdown_values = get_post_meta($cart_item['product_id'], "saso_eventtickets_request_value_per_ticket_values", true);
					$dropdown_def = get_post_meta($cart_item['product_id'], "saso_eventtickets_request_value_per_ticket_def", true);
					if (!empty($dropdown_values)) {
						$is_mandatory = get_post_meta($cart_item['product_id'], "saso_eventtickets_request_value_per_ticket_mandatory", true) == "yes";
						$label_option = esc_attr($this->MAIN->getTicketHandler()->getLabelValuePerTicket($cart_item['product_id']));
						for ($a = 0; $a < $anzahl; $a++) {
							$value = "";
							if ($valueArray != null && isset($valueArray[$cart_item_key]) && isset($valueArray[$cart_item_key][$a])) {
								$value = trim($valueArray[$cart_item_key][$a]);
							}
							$l = str_replace("{count}", $a + 1, $label_option);
							$html_options = "";
							$has_empty_option = false;
							foreach (explode("\n", $dropdown_values) as $entry) {
								$t = explode("|", $entry);
								$v = "";
								$label = "";
								if (count($t) > 0) {
									$v = sanitize_key(trim($t[0]));
									if (count($t) > 1) {
										$label = sanitize_key(trim($t[1]));
									}
								}
								if (!empty($v)) {
									if (empty($label)) {
										$label = $v;
									}
									$html_options .= '<option value="' . esc_attr($v) . '"';
									if ($value == $v || (empty($value) && $v == $dropdown_def)) {
										$html_options .= ' selected';
									}
									$html_options .= '>' . esc_html($label) . '</option>';
								} else if (!empty($label)) {
									$html_options .= '<option>' . esc_html($label) . '</option>';
									$has_empty_option = true;
								}
							}
							if ($is_mandatory && $has_empty_option == false) {
								$html_options = '<option>' . esc_html($l) . '</option>' . $html_options;
							}

							$html = '<div class="saso_eventtickets_request_value_per_ticket_label"><small>' . $l . '<br></small>
									<select
										name="saso_eventtickets_request_value_per_ticket[%s][]"
										data-input-type="value"
										data-cart-item-id="%s"
										data-cart-item-count="%s"
										data-plugin="event"
										data-plg="' . esc_attr($this->MAIN->getPrefix()) . '"
										class="dropdown">' . $html_options . '</select></div>';
							printf(
								str_replace("\n", "", $html),
								esc_attr($cart_item_key),
								esc_attr($cart_item_key),
								esc_attr($a)
							);
						}
					}
				}
			}
		}

		/**
		 * Check cart items and add validation warnings
		 *
		 * Validates cart items for:
		 * - Restriction codes
		 * - Required name per ticket
		 * - Required dropdown value per ticket
		 * - Day chooser date selection
		 *
		 * @return void
		 */
		public function check_cart_item_and_add_warnings(): void {
			$cart_items = WC()->cart->get_cart();

			// Check restriction codes
			$this->validateRestrictionCodes($cart_items);

			// Check name per ticket
			$this->validateNamePerTicket($cart_items);

			// Check dropdown value per ticket
			$this->validateValuePerTicket($cart_items);

			// Check day chooser dates
			$this->validateDayChooserDates($cart_items);

			// Check seat reservations (blocks not expired)
			$this->validateSeatReservations($cart_items);
		}

		/**
		 * Validate restriction codes for cart items
		 *
		 * @param array $cart_items Cart items
		 * @return void
		 */
		private function validateRestrictionCodes(array $cart_items): void {
			if (!$this->containsProductsWithRestrictions()) {
				return;
			}

			$meta_key = '_saso_eventticket_list_sale_restriction';

			foreach ($cart_items as $item_id => $cart_item) {
				$code = isset($cart_item[$meta_key]) ? $cart_item[$meta_key] : '';
				$code = strtoupper($code);

				switch ($this->check_code_for_cartitem($cart_item, $code)) {
					case 0:
						wc_add_notice(
							sprintf(
								/* translators: %s: name of product */
								__('The product "%s" requires a restriction code for checkout.', 'event-tickets-with-ticket-scanner'),
								esc_html($cart_item['data']->get_name())
							),
							'error',
							["cart-item-id" => $item_id]
						);
						break;
					case 1: // valid
						break;
					case 2:
						wc_add_notice(
							sprintf(
								/* translators: 1: restriction code number 2: name of product */
								__('The restriction code "%1$s" for product "%2$s" is already used.', 'event-tickets-with-ticket-scanner'),
								esc_attr($code),
								esc_html($cart_item['data']->get_name())
							),
							'error',
							["cart-item-id" => $item_id]
						);
						break;
					case 3: // not valid
					case 4: // no code list
					default:
						wc_add_notice(
							sprintf(
								/* translators: 1: restriction code number 2: name of product */
								__('The restriction code "%1$s" for product "%2$s" is not valid.', 'event-tickets-with-ticket-scanner'),
								esc_attr($code),
								esc_html($cart_item['data']->get_name())
							),
							'error',
							["cart-item-id" => $item_id]
						);
				}
			}
		}

		/**
		 * Validate name per ticket for cart items
		 *
		 * @param array $cart_items Cart items
		 * @return void
		 */
		private function validateNamePerTicket(array $cart_items): void {
			$valueArray = WC()->session->get("saso_eventtickets_request_name_per_ticket");

			foreach ($cart_items as $item_id => $cart_item) {
				$request_name = get_post_meta($cart_item['product_id'], "saso_eventtickets_request_name_per_ticket", true) == "yes";
				if (!$request_name) {
					continue;
				}

				$mandatory = get_post_meta($cart_item['product_id'], "saso_eventtickets_request_name_per_ticket_mandatory", true) == "yes";
				if (!$mandatory) {
					continue;
				}

				$anzahl = intval($cart_item["quantity"]);
				if ($anzahl <= 0) {
					continue;
				}

				for ($a = 0; $a < $anzahl; $a++) {
					$value = "";
					if ($valueArray != null && isset($valueArray[$cart_item['key']]) && isset($valueArray[$cart_item['key']][$a])) {
						$value = trim($valueArray[$cart_item['key']][$a]);
					}
					if (empty($value)) {
						$label = $this->MAIN->getOptions()->getOptionValue('wcTicketLabelCartForName');
						$label = str_replace("{PRODUCT_NAME}", "%s", $label);
						wc_add_notice(
							wp_kses_post(sprintf($label, esc_html($cart_item['data']->get_name()))),
							'error',
							["cart-item-id" => $item_id, "" => ""]
						);
						break;
					}
				}
			}
		}

		/**
		 * Validate dropdown value per ticket for cart items
		 *
		 * @param array $cart_items Cart items
		 * @return void
		 */
		private function validateValuePerTicket(array $cart_items): void {
			$valueArray = WC()->session->get("saso_eventtickets_request_value_per_ticket");

			foreach ($cart_items as $item_id => $cart_item) {
				$request_value = get_post_meta($cart_item['product_id'], "saso_eventtickets_request_value_per_ticket", true) == "yes";
				if (!$request_value) {
					continue;
				}

				$mandatory = get_post_meta($cart_item['product_id'], "saso_eventtickets_request_value_per_ticket_mandatory", true) == "yes";
				if (!$mandatory) {
					continue;
				}

				$anzahl = intval($cart_item["quantity"]);
				if ($anzahl <= 0) {
					continue;
				}

				for ($a = 0; $a < $anzahl; $a++) {
					$value = "";
					if ($valueArray != null && isset($valueArray[$cart_item['key']]) && isset($valueArray[$cart_item['key']][$a])) {
						$value = trim($valueArray[$cart_item['key']][$a]);
					}
					if (empty($value)) {
						$label = $this->MAIN->getOptions()->getOptionValue('wcTicketLabelCartForValue');
						$label = str_replace("{PRODUCT_NAME}", "%s", $label);
						wc_add_notice(
							wp_kses_post(sprintf($label, esc_html($cart_item['data']->get_name()))),
							'error',
							["cart-item-id" => $item_id, "cart-item-count" => $a]
						);
						continue;
					}
				}
			}
		}

		/**
		 * Validate day chooser dates for cart items
		 *
		 * @param array $cart_items Cart items
		 * @return void
		 */
		private function validateDayChooserDates(array $cart_items): void {
			foreach ($cart_items as $item_id => $cart_item) {
				$is_daychooser = get_post_meta($cart_item['product_id'], "saso_eventtickets_is_daychooser", true) == "yes";
				if (!$is_daychooser) {
					continue;
				}

				$key = self::SESSION_KEY_DAYCHOOSER;
				$valueArray = isset($cart_item[$key]) ? $cart_item[$key] : null;
				if ($valueArray == null) {
					$valueArray = $this->session_get_value($key . '_' . $item_id);
				}

				$dates = $this->MAIN->getTicketHandler()->getCalcDateStringAllowedRedeemFromCorrectProduct($cart_item['product_id']);
				$offset_start = $dates['daychooser_offset_start'];
				$offset_end = $dates['daychooser_offset_end'];
				$ticket_start_date = $dates['ticket_start_date'];
				$ticket_end_date = $dates['ticket_end_date'];

				$anzahl = intval($cart_item["quantity"]);
				if ($anzahl <= 0) {
					continue;
				}

				for ($a = 0; $a < $anzahl; $a++) {
					$value = "";
					if ($valueArray != null && isset($valueArray[$a])) {
						$value = trim($valueArray[$a]);
					}

					if (empty($value)) {
						$this->displayWarningDatePicker($cart_item['data']->get_name(), $item_id, $a);
						continue;
					}

					// Test if the date is valid
					$date = DateTime::createFromFormat('Y-m-d', $value);
					if (!$date || $date->format('Y-m-d') !== $value) {
						$this->displayWarningDatePicker($cart_item['data']->get_name(), $item_id, $a);
						continue;
					}

					// Calculate start date with offset
					if ($offset_start > 0) {
						if (empty($ticket_start_date)) {
							$ticket_start_date = date("Y-m-d", strtotime("+" . $offset_start . " days"));
						} else {
							if (time() < strtotime($ticket_start_date . " -" . $offset_start . " days")) {
								$ticket_start_date = date("Y-m-d", strtotime("+" . $offset_start . " days"));
							}
						}
					}

					// Calculate end date with offset
					if ($offset_end > 0) {
						if (empty($ticket_end_date)) {
							$ticket_end_date = date("Y-m-d", strtotime("+" . $offset_end . " days"));
						}
					}

					// Validate date range
					if (!empty($ticket_start_date) && strtotime($value) < strtotime($ticket_start_date)) {
						$this->displayWarningDatePicker($cart_item['data']->get_name(), $item_id, $a);
						continue;
					}
					if (!empty($ticket_end_date) && strtotime($value) > strtotime($ticket_end_date)) {
						$this->displayWarningDatePicker($cart_item['data']->get_name(), $item_id, $a, true);
						continue;
					}
				}
			}
		}

		/**
		 * Validate seat reservations for cart items
		 *
		 * Checks if seat blocks are still valid (not expired).
		 * This check runs ALWAYS, even when wcTicketShowInputFieldsOnCheckoutPage is active.
		 *
		 * @param array $cart_items Cart items
		 * @return void
		 */
		private function validateSeatReservations(array $cart_items): void {
			$seating = $this->MAIN->getSeating();
			$seatMetaKey = $seating->getMetaCartItemSeat();
			$now = current_time('mysql');
			$autoRemove = $this->MAIN->getOptions()->isOptionCheckboxActive('seatingRemoveExpiredFromCart');
			$itemsToRemove = [];

			foreach ($cart_items as $item_id => $cart_item) {
				$seatsData = $cart_item[$seatMetaKey] ?? null;
				if (empty($seatsData) || !is_array($seatsData)) {
					continue;
				}

				// Normalize: if it's a single seat object, wrap in array
				if (isset($seatsData['seat_id'])) {
					$seatsData = [$seatsData];
				}

				if (empty($seatsData) || !isset($seatsData[0]['seat_id'])) {
					continue;
				}

				$expiredSeats = [];
				foreach ($seatsData as $seat) {
					$expiresAt = $seat['expires_at'] ?? '';
					if (empty($expiresAt)) {
						continue;
					}

					// Check if block has expired
					if (strtotime($expiresAt) < strtotime($now)) {
						$expiredSeats[] = $seat['seat_label'] ?? $seat['seat_id'];
					}
				}

				if (!empty($expiredSeats)) {
					$productName = $cart_item['data']->get_name();
					$seatLabels = implode(', ', $expiredSeats);

					if ($autoRemove) {
						// Mark for removal and show info notice
						$itemsToRemove[] = $item_id;
						wc_add_notice(
							sprintf(
								/* translators: 1: product name 2: seat labels */
								__('"%1$s" was removed from your cart because the seat reservation expired: %2$s', 'event-tickets-with-ticket-scanner'),
								esc_html($productName),
								esc_html($seatLabels)
							),
							'notice'
						);
					} else {
						// Show error and block checkout
						wc_add_notice(
							sprintf(
								/* translators: 1: product name 2: seat labels */
								__('The seat reservation for "%1$s" has expired: %2$s. Please select your seats again.', 'event-tickets-with-ticket-scanner'),
								esc_html($productName),
								esc_html($seatLabels)
							),
							'error',
							['cart-item-id' => $item_id]
						);
					}
				}
			}

			// Remove expired items from cart
			if (!empty($itemsToRemove) && WC()->cart) {
				foreach ($itemsToRemove as $item_id) {
					WC()->cart->remove_cart_item($item_id);
				}
			}
		}

		/**
		 * Display warning for date picker validation
		 *
		 * @param string $product_name Product name
		 * @param string $item_id Cart item ID
		 * @param int $a Item count index
		 * @param bool $in_the_past Whether date is in the past
		 * @return void
		 */
		public function displayWarningDatePicker(string $product_name, string $item_id, int $a, bool $in_the_past = false): void {
			$label = $this->getWarningDatePickerLabel($product_name, $item_id, $a, $in_the_past);
			wc_add_notice(wp_kses_post($label), 'error', ["cart-item-id" => $item_id, "cart-item-count" => $a]);
		}

		/**
		 * Handle checkout process validation
		 *
		 * @return void
		 */
		public function woocommerce_checkout_process(): void {
			// Validate seat reservations - block checkout if any seats expired
			$this->validateSeatReservations(WC()->cart->get_cart());

			$this->check_cart_item_and_add_warnings();
		}

		/**
		 * Handle cart items validation
		 *
		 * @return void
		 */
		public function woocommerce_check_cart_items(): void {
			// Seat reservation check must ALWAYS run (independent of wcTicketShowInputFieldsOnCheckoutPage)
			$this->validateSeatReservations(WC()->cart->get_cart());

			if ($this->MAIN->getOptions()->isOptionCheckboxActive('wcTicketShowInputFieldsOnCheckoutPage')) {
				// Skip other validations on cart page when checkout-only option is active
				return;
			}
			$this->check_cart_item_and_add_warnings();
		}

		/**
		 * Display input fields on checkout page after cart contents
		 *
		 * @return void
		 */
		public function woocommerce_review_order_after_cart_contents(): void {
			if (!$this->MAIN->getOptions()->isOptionCheckboxActive('wcTicketShowInputFieldsOnCheckoutPage')) {
				return;
			}

			// Prevent rendering for ajax call
			if (is_ajax()) {
				return;
			}

			// Load wc_frontend.js to the checkout view
			$this->addJSFileAndHandler();

			// Render the input fields
			$cart_items = WC()->cart->get_cart();
			foreach ($cart_items as $cart_item_key => $cart_item) {
				$this->woocommerce_after_cart_item_name_handler($cart_item, $cart_item_key);
			}
		}

		// =====================================================================
		// Shop & Product Page Datepicker Methods
		// =====================================================================

		/**
		 * Display datepicker and seating plan on shop loop item (shop page, category, tag pages)
		 *
		 * @return void
		 */
		public function woocommerce_after_shop_loop_item_handler(): void {
			if (!is_shop() && !is_product_category() && !is_product_tag()) {
				return;
			}

			global $product;

			if (!$product || !$product->is_purchasable()) {
				return;
			}

			$product_id = $product->get_id();
			$product_id_orig = $this->MAIN->getTicketHandler()->getWPMLProductId($product_id);

			$is_ticket = $this->MAIN->getWC()->getProductManager()->isTicketByProductId($product_id_orig);
			if (!$is_ticket) {
				return;
			}

			$isDaychooser = get_post_meta($product_id_orig, 'saso_eventtickets_is_daychooser', true) == "yes";
			$eventDate = null;

			// Daychooser handling
			if ($isDaychooser) {
				$name = esc_attr(self::FIELD_KEY . '_' . $product_id);

				// Nonce per page (once is enough)
				wp_nonce_field('wcadr_add_to_cart', self::NONCE_KEY);

				$this->addDatepickerHTML($name, 0, $product_id, "", "", null, null, $name, ["data-is-shop-page" => "1"]);

				// Load JS and initialize handler
				$label = $this->getWarningDatePickerLabel($product->get_name(), 0, 1);
				$this->addJSFileAndHandler([
					"has_daychooser" => true,
					"fieldDayChooserIndicator" => "is_daychooser",
					"fieldKey" => self::FIELD_KEY,
					"nonceKey" => self::NONCE_KEY,
					"daychooser_warning" => wp_kses_post($label)
				]);
			}

			// Seating plan handling
			$seating = $this->MAIN->getSeating();
			$planId = get_post_meta($product_id_orig, $seating->getMetaProductSeatingplan(), true);
			if (!empty($planId)) {
				$frontendManager = $seating->getFrontendManager();

				// Check if plan is available for frontend (published or admin preview)
				$plan = $frontendManager->getPlanForProductFrontend($product_id_orig);

				if ($plan) {
					$frontendManager->enqueueScripts();

					echo '<div class="saso-seating-wrapper" data-product-id="' . esc_attr($product_id) . '" data-requires-date="' . ($isDaychooser ? '1' : '0') . '">';
					echo $frontendManager->renderSeatSelector($product_id_orig, $eventDate);
					echo '</div>';
				}
			}
		}

		/**
		 * Display datepicker before add to cart button on single product page
		 *
		 * @return void
		 */
		public function woocommerce_before_add_to_cart_button_handler(): void {
			if (!is_product()) {
				return;
			}

			global $product;

			if (!$product || !$product->is_purchasable()) {
				return;
			}

			$product_id_raw = $product->get_id();

			// WPML: Normalize to original product ID for meta lookups
			$product_id = $this->MAIN->getTicketHandler()->getWPMLProductId($product_id_raw);

			$is_ticket = $this->MAIN->getWC()->getProductManager()->isTicketByProductId($product_id);
			if (!$is_ticket) {
				return;
			}

			// Daychooser handling
			$isDaychooser = get_post_meta($product_id, 'saso_eventtickets_is_daychooser', true) == "yes";
			$eventDate = null;

			if ($isDaychooser) {
				$name = esc_attr(self::FIELD_KEY . '_' . $product_id);

				// Nonce per page (once is enough)
				wp_nonce_field('wcadr_add_to_cart', self::NONCE_KEY);

				$this->addDatepickerHTML($name, 0, $product_id, "", "", null, null, $name, ["data-is-shop-page" => "1"]);

				// Load JS and initialize handler
				$label = $this->getWarningDatePickerLabel($product->get_name(), 0, 1);
				$this->addJSFileAndHandler([
					"has_daychooser" => true,
					"fieldDayChooserIndicator" => "is_daychooser",
					"fieldKey" => self::FIELD_KEY,
					"nonceKey" => self::NONCE_KEY,
					"daychooser_warning" => wp_kses_post($label)
				]);
			}

			// Seating plan handling
			// Only show if:
			// 1. Product has a seating plan assigned
			// 2. Plan is published (or admin preview mode)
			// 3. If daychooser is active, selector is hidden until date is chosen (via JS)
			$seating = $this->MAIN->getSeating();
			$planId = get_post_meta($product_id, $seating->getMetaProductSeatingplan(), true);
			if (!empty($planId)) {
				$frontendManager = $seating->getFrontendManager();

				// Check if plan is available for frontend (published or admin preview)
				$plan = $frontendManager->getPlanForProductFrontend($product_id);

				if ($plan) {
					$frontendManager->enqueueScripts();

					echo '<div class="saso-seating-wrapper" data-requires-date="' . ($isDaychooser ? '1' : '0') . '">';
					echo $frontendManager->renderSeatSelector($product_id, $eventDate);
					echo '</div>';
				}
			}
		}

		/**
		 * Add custom data to cart item before adding to cart
		 *
		 * Handles seat data storage based on global option:
		 * - seatingSeparateCartItems = true: Each seat creates unique cart item (prevents merging)
		 * - seatingSeparateCartItems = false: Seats appended in woocommerce_add_to_cart_handler (like daychooser)
		 *
		 * @param array $cart_item_data Existing cart item data
		 * @param int $product_id Product ID
		 * @param int $variation_id Variation ID
		 * @return array Modified cart item data
		 */
		public function woocommerce_add_cart_item_data_handler(array $cart_item_data, int $product_id, int $variation_id = 0): array {
			// If "separate cart items" option is active, add seat unique key to prevent merging
			// Option OFF (default): All seats in one cart item (dates/seats appended in add_to_cart_handler)
			// Option ON: Each seat = separate cart item
			$separateCartItems = $this->MAIN->getOptions()->isOptionCheckboxActive('seatingSeparateCartItems');
			if ($separateCartItems) {
				$seating = $this->MAIN->getSeating();
				$fieldName = $seating->getFieldSeatSelection();
				$seatSelection = isset($_REQUEST[$fieldName]) ? wp_unslash($_REQUEST[$fieldName]) : '';

				if (!empty($seatSelection)) {
					$seatData = json_decode($seatSelection, true);

					if (is_array($seatData)) {
						// Normalize to array format
						if (isset($seatData['seat_id'])) {
							$seatData = [$seatData];
						}

						if (!empty($seatData) && isset($seatData[0]['seat_id'])) {
							// Store seat data
							$cart_item_data[$seating->getMetaCartItemSeat()] = $seatData;

							// Add unique key to prevent merging
							$seatIds = array_column($seatData, 'seat_id');
							$cart_item_data['saso_seat_unique_key'] = implode('_', $seatIds);
						}
					}
				}
			}

			return $cart_item_data;
		}

		/**
		 * Server-side validation for datepicker when adding to cart
		 *
		 * @param bool $passed Current validation status
		 * @param int $product_id Product ID
		 * @param int $quantity Quantity
		 * @return bool Validation result
		 */
		public function woocommerce_add_to_cart_validation_handler(bool $passed, int $product_id, int $quantity): bool {
			// Daychooser validation
			if (isset($_REQUEST["is_daychooser"]) && $_REQUEST["is_daychooser"] == "1") {
				// Verify nonce if present
				if (isset($_REQUEST[self::NONCE_KEY]) && !wp_verify_nonce($_REQUEST[self::NONCE_KEY], 'wcadr_add_to_cart')) {
					wc_add_notice(__('Security check failed. Please reload page.', 'event-tickets-with-ticket-scanner'), 'error');
					return false;
				}

				$date = isset($_REQUEST[self::FIELD_KEY]) ? SASO_EVENTTICKETS::sanitize_date_from_datepicker($_REQUEST[self::FIELD_KEY]) : '';

				if (empty($date)) {
					$product = wc_get_product($product_id);
					$this->displayWarningDatePicker($product->get_name(), '0', 1);
					return false;
				}

				// Disallow past dates
				if ($date < date('Y-m-d')) {
					$product = wc_get_product($product_id);
					$this->displayWarningDatePicker($product->get_name(), '0', 1, true);
					return false;
				}
			}

			// Seating validation
			$seating = $this->MAIN->getSeating();
			$planId = get_post_meta($product_id, $seating->getMetaProductSeatingplan(), true);
			if (!empty($planId)) {
				$frontendManager = $seating->getFrontendManager();
				$fieldName = $seating->getFieldSeatSelection();
				$seatingRequired = get_post_meta($product_id, $seating->getMetaProductSeatingRequired(), true) === 'yes';
				// wp_unslash is required because WordPress adds slashes to all $_REQUEST data
				// sanitize_text_field is not suitable for JSON - validation is done via json_decode
				$seatSelection = isset($_REQUEST[$fieldName]) ? wp_unslash($_REQUEST[$fieldName]) : '';

				// Check if plan is actually available to customers (published)
				$plan = $frontendManager->getPlanForProductFrontend($product_id);
				$planIsAvailable = !empty($plan);

				// Only validate if plan is published and visible to customer
				if ($planIsAvailable) {
					// Parse seat selection (always array format)
					$seatsToValidate = [];
					if (!empty($seatSelection)) {
						$seatData = json_decode($seatSelection, true);
						if (is_array($seatData)) {
							if (isset($seatData['seat_id'])) {
								// Legacy single seat - wrap in array
								$seatsToValidate = [$seatData];
							} elseif (!empty($seatData) && isset($seatData[0]['seat_id'])) {
								$seatsToValidate = $seatData;
							}
						}
					}

					$seatCount = count($seatsToValidate);

					// Check if seats are required but missing
					if ($seatingRequired && $seatCount === 0) {
						wc_add_notice(
							sprintf(
								__('Please select %d seat(s) before adding to cart.', 'event-tickets-with-ticket-scanner'),
								$quantity
							),
							'error'
						);
						return false;
					}

					// Check if seat count matches quantity
					if ($seatCount > 0 && $seatCount !== $quantity) {
						wc_add_notice(
							sprintf(
								__('Please select exactly %d seat(s). You have selected %d.', 'event-tickets-with-ticket-scanner'),
								$quantity,
								$seatCount
							),
							'error'
						);
						return false;
					}

					// Validate each seat availability
					$eventDate = isset($_REQUEST[self::FIELD_KEY]) ? SASO_EVENTTICKETS::sanitize_date_from_datepicker($_REQUEST[self::FIELD_KEY]) : null;
					$blockOnAddToCart = $this->MAIN->getOptions()->isOptionCheckboxActive('seatingBlockOnAddToCart');
					$blockManager = $seating->getBlockManager();
					$sessionId = WC()->session ? WC()->session->get_customer_id() : session_id();
					$blockedSeatsData = [];

					foreach ($seatsToValidate as $index => $seat) {
						if (!isset($seat['seat_id'])) {
							continue;
						}

						$seatId = (int) $seat['seat_id'];

						// Validate seat belongs to the product's seating plan (security check)
						$seatPlanId = $seating->getSeatManager()->getSeatingPlanIdForSeatId($seatId);
						if ($seatPlanId === null || (int)$seatPlanId !== (int)$planId) {
							wc_add_notice(
								__('Invalid seat selection. Please reload the page and try again.', 'event-tickets-with-ticket-scanner'),
								'error'
							);
							return false;
						}

						// If blockOnAddToCart is active, we need to create blocks now
						if ($blockOnAddToCart) {
							// Try to block the seat
							$blockResult = $blockManager->blockSeat($seatId, $planId, $product_id, $eventDate, $sessionId);

							if (!$blockResult['success']) {
								$seatLabel = $seat['seat_label'] ?? $seat['seat_id'];
								wc_add_notice(
									sprintf(__('Seat "%s" is no longer available. Please choose another seat.', 'event-tickets-with-ticket-scanner'), $seatLabel),
									'error'
								);
								return false;
							}

							// Store block info to update the seat data
							$blockedSeatsData[$index] = [
								'block_id' => $blockResult['block_id'],
								'expires_at' => $blockResult['expires_at'],
							];
						} else {
							// Standard validation - seat should already be blocked
							$validation = $frontendManager->validateSeatSelection(
								$product_id,
								$seatId,
								$eventDate
							);

							if (!$validation['valid']) {
								$seatLabel = $seat['seat_label'] ?? $seat['seat_id'];
								$errorMsg = $validation['error'] === 'seat_unavailable'
									? sprintf(__('Seat "%s" is no longer available. Please choose another seat.', 'event-tickets-with-ticket-scanner'), $seatLabel)
									: sprintf(__('Invalid seat selection: %s. Please try again.', 'event-tickets-with-ticket-scanner'), $seatLabel);
								wc_add_notice($errorMsg, 'error');
								return false;
							}
						}
					}

					// If blockOnAddToCart, update REQUEST data with block info for the add_to_cart_handler
					if ($blockOnAddToCart && !empty($blockedSeatsData)) {
						foreach ($blockedSeatsData as $index => $blockInfo) {
							$seatsToValidate[$index]['block_id'] = $blockInfo['block_id'];
							$seatsToValidate[$index]['expires_at'] = $blockInfo['expires_at'];
						}
						// Update the REQUEST data so add_to_cart_handler gets the block info
						$_REQUEST[$fieldName] = wp_json_encode($seatsToValidate);
					}
				}
			}

			return $passed;
		}

		/**
		 * Handle add to cart action - store selected date in cart item
		 *
		 * @param string $cart_item_key Cart item key
		 * @param int $product_id Product ID
		 * @param int $quantity Quantity
		 * @param int $variation_id Variation ID
		 * @param array $variation Variation data
		 * @param array $cart_item_data Cart item data
		 * @return void
		 */
		public function woocommerce_add_to_cart_handler(string $cart_item_key, int $product_id, int $quantity, int $variation_id, array $variation, array $cart_item_data): void {
			$cart = WC()->cart->cart_contents;

			if (!isset($cart[$cart_item_key])) {
				return;
			}

			$line =& WC()->cart->cart_contents[$cart_item_key];

			// Handle daychooser
			if (isset($_REQUEST["is_daychooser"]) && $_REQUEST["is_daychooser"] == "1") {
				$date = isset($_REQUEST[self::FIELD_KEY]) ? SASO_EVENTTICKETS::sanitize_date_from_datepicker($_REQUEST[self::FIELD_KEY]) : '';

				if (!empty($date)) {
					$key = self::SESSION_KEY_DAYCHOOSER;

					// Get fallback value in case cart item is adjusted by other plugins
					$valueArray = $this->session_get_value($key . '_' . $cart_item_key);
					if ($valueArray != null && is_array($valueArray)) {
						$line[$key] = $valueArray;
					}
					if (!isset($line[$key]) || !is_array($line[$key])) {
						$line[$key] = [];
					}

					for ($i = 0; $i < $quantity; $i++) {
						$line[$key][] = $date;
					}

					$product_id_orig = $this->MAIN->getTicketHandler()->getWPMLProductId($product_id);
					$display_only_one_datepicker = get_post_meta($product_id_orig, 'saso_eventtickets_only_one_day_for_all_tickets', true) == "yes";
					if ($display_only_one_datepicker) {
						$line[$key] = array_fill(0, $quantity, $date);
					}

					$this->session_set_value($key . '_' . $cart_item_key, $line[$key]);
				}
			}

			// Handle seating - append seats to array (like daychooser)
			// Only if "separate cart items" option is NOT active (when active, handled in add_cart_item_data)
			$separateCartItems = $this->MAIN->getOptions()->isOptionCheckboxActive('seatingSeparateCartItems');
			if (!$separateCartItems) {
				$seating = $this->MAIN->getSeating();
				$fieldName = $seating->getFieldSeatSelection();
				$seatSelection = isset($_REQUEST[$fieldName]) ? wp_unslash($_REQUEST[$fieldName]) : '';

				if (!empty($seatSelection)) {
					$seatData = json_decode($seatSelection, true);

					if (is_array($seatData)) {
						// Normalize to array format
						if (isset($seatData['seat_id'])) {
							$seatData = [$seatData];
						}

						if (!empty($seatData) && isset($seatData[0]['seat_id'])) {
							$seatMetaKey = $seating->getMetaCartItemSeat();

							// Initialize or get existing seats array
							if (!isset($line[$seatMetaKey]) || !is_array($line[$seatMetaKey])) {
								$line[$seatMetaKey] = [];
							}

							// Append new seats to the array
							foreach ($seatData as $seat) {
								$line[$seatMetaKey][] = $seat;
							}

							// Store in session as backup (like daychooser does)
							$this->session_set_value($seatMetaKey . '_' . $cart_item_key, $line[$seatMetaKey]);
						}
					}
				}
			}

			WC()->cart->set_session();
		}

		/**
		 * Handle cart item removal - cleanup session data and release seat blocks
		 *
		 * @param string $cart_item_key Cart item key
		 * @param \WC_Cart|null $cart Cart object
		 * @return void
		 */
		public function woocommerce_cart_item_removed_handler(string $cart_item_key, $cart): void {
			// Clean up session data
			$session_keys = [
				'saso_eventtickets_request_name_per_ticket',
				'saso_eventtickets_request_value_per_ticket'
			];
			foreach ($session_keys as $k) {
				$valueArray = WC()->session->get($k);
				if ($valueArray != null && isset($valueArray[$cart_item_key])) {
					WC()->session->__unset($k);
				}
			}

			// Release seat blocks if any
			if ($cart && isset($cart->removed_cart_contents[$cart_item_key])) {
				$removedItem = $cart->removed_cart_contents[$cart_item_key];
				$this->releaseSeatBlocksFromCartItem($removedItem);
			}
		}

		/**
		 * Release seat blocks from a cart item
		 *
		 * @param array $cart_item Cart item data
		 * @return void
		 */
		private function releaseSeatBlocksFromCartItem(array $cart_item): void {
			$seating = $this->MAIN->getSeating();
			$seatMetaKey = $seating->getMetaCartItemSeat();
			$seatsData = $cart_item[$seatMetaKey] ?? null;

			if (empty($seatsData) || !is_array($seatsData)) {
				return;
			}

			// Normalize: if it's a single seat object, wrap in array
			if (isset($seatsData['seat_id'])) {
				$seatsData = [$seatsData];
			}

			// Release each seat block
			foreach ($seatsData as $seat) {
				if (!empty($seat['block_id'])) {
					$seating->releaseSeatFromCart((int) $seat['block_id']);
				}
			}
		}

		/**
		 * Handle cart item quantity update - adjust session data accordingly
		 *
		 * @param string $cart_item_key Cart item key
		 * @param int $quantity New quantity
		 * @param int $old_quantity Old quantity
		 * @param \WC_Cart|null $cart Cart object (optional, not used)
		 * @return void
		 */
		public function woocommerce_after_cart_item_quantity_update_handler(string $cart_item_key, int $quantity, int $old_quantity, $cart = null): void {
			if ($quantity == $old_quantity) {
				return;
			}
			if ($quantity < 1) {
				$this->woocommerce_cart_item_removed_handler($cart_item_key, null);
				return;
			}

			$session_keys = [
				'saso_eventtickets_request_name_per_ticket',
				'saso_eventtickets_request_value_per_ticket'
			];

			if ($quantity > $old_quantity) {
				// Increase quantity
				$value = null;
				$diff = $quantity - $old_quantity;

				foreach ($session_keys as $k) {
					$valueArray = WC()->session->get($k);
					if ($valueArray != null && isset($valueArray[$cart_item_key])) {
						if ($value == null) {
							// Get value from last entry to prefill new entries
							if (isset($valueArray[$cart_item_key][$old_quantity - 1])) {
								$value = trim($valueArray[$cart_item_key][$old_quantity - 1]);
							}
						}
						for ($i = 0; $i < $diff; $i++) {
							$valueArray[$cart_item_key][] = $value;
						}
						WC()->session->set($k, $valueArray);
					}
				}

				// Handle daychooser product dates
				$cart_contents = WC()->cart->cart_contents;
				$key = self::SESSION_KEY_DAYCHOOSER;

				if (isset($cart_contents[$cart_item_key])) {
					$line =& WC()->cart->cart_contents[$cart_item_key];

					// Get fallback value in case cart item is adjusted by other plugins
					$valueArray = $this->session_get_value($key . '_' . $cart_item_key);
					if ($valueArray != null && is_array($valueArray)) {
						$line[$key] = $valueArray;
					}

					if (isset($line[$key]) && is_array($line[$key])) {
						$value = null;
						if (isset($line[$key][$old_quantity - 1])) {
							$value = trim($line[$key][$old_quantity - 1]);
						}
						for ($i = 0; $i < $diff; $i++) {
							$line[$key][] = $value;
						}
						WC()->cart->set_session();
						$this->session_set_value($key . '_' . $cart_item_key, $line[$key]);
					}
				}
			} else {
				// Decrease quantity
				$diff = $old_quantity - $quantity;

				foreach ($session_keys as $k) {
					$valueArray = WC()->session->get($k);
					if ($valueArray != null && isset($valueArray[$cart_item_key])) {
						array_splice($valueArray[$cart_item_key], $quantity, $diff);
						WC()->session->set($k, $valueArray);
					}
				}

				// Handle daychooser product dates
				$cart_contents = WC()->cart->cart_contents;
				$key = self::SESSION_KEY_DAYCHOOSER;

				if (isset($cart_contents[$cart_item_key])) {
					$line =& WC()->cart->cart_contents[$cart_item_key];

					$valueArray = $this->session_get_value($key . '_' . $cart_item_key);
					if ($valueArray != null && is_array($valueArray)) {
						$line[$key] = $valueArray;
					}

					if (isset($line[$key]) && is_array($line[$key])) {
						array_splice($line[$key], $quantity, $diff);
						WC()->cart->set_session();
						$this->session_set_value($key . '_' . $cart_item_key, $line[$key]);
					}
				}
			}
		}

		/**
		 * Get warning label for date picker validation (public access for WC hooks)
		 *
		 * @param string $product_name Product name
		 * @param string $item_id Cart item ID
		 * @param int $a Item count index
		 * @param bool $in_the_past Whether date is in the past
		 * @return string Warning label
		 */
		public function getWarningDatePickerLabel(string $product_name, string $item_id, int $a, bool $in_the_past = false): string {
			if ($in_the_past) {
				$label = $this->MAIN->getOptions()->getOptionValue('wcTicketLabelCartForDaychooserPassedDate');
			} else {
				$label = $this->MAIN->getOptions()->getOptionValue('wcTicketLabelCartForDaychooserInvalidDate');
			}
			$label = str_replace("{PRODUCT_NAME}", "%s", $label);
			$label = str_replace("{count}", "%d", $label);
			return sprintf($label, esc_html($product_name), $a + 1);
		}

		/**
		 * Validate cart update - prevent quantity change when seats are selected
		 *
		 * @param bool $passed Whether validation passed
		 * @param string $cart_item_key Cart item key
		 * @param array $values Cart item values
		 * @param int $quantity New quantity
		 * @return bool Whether validation passed
		 */
		public function woocommerce_update_cart_validation_handler(bool $passed, string $cart_item_key, array $values, int $quantity): bool {
			if (!$passed) {
				return $passed;
			}

			$cart = WC()->cart->get_cart();
			if (!isset($cart[$cart_item_key])) {
				return $passed;
			}

			$cart_item = $cart[$cart_item_key];
			$old_quantity = (int) $cart_item['quantity'];

			// If quantity unchanged, allow
			if ($quantity === $old_quantity) {
				return $passed;
			}

			// Check if this item has seats selected
			$seating = $this->MAIN->getSeating();
			$seatsData = $cart_item[$seating->getMetaCartItemSeat()] ?? null;

			if (empty($seatsData) || !is_array($seatsData)) {
				return $passed;
			}

			// Normalize seats array
			if (isset($seatsData['seat_id'])) {
				$seatsData = [$seatsData];
			}

			if (empty($seatsData) || !isset($seatsData[0]['seat_id'])) {
				return $passed;
			}

			$seatCount = count($seatsData);

			// Seats are selected - check if seating is required
			$product_id = $cart_item['product_id'];
			$product_id_orig = $this->MAIN->getTicketHandler()->getWPMLProductId($product_id);
			$seatingRequired = get_post_meta($product_id_orig, $seating->getMetaProductSeatingRequired(), true) === 'yes';

			if ($seatingRequired) {
				// Seating required: quantity must match seat count, no changes allowed
				$productName = $cart_item['data']->get_name();
				wc_add_notice(
					sprintf(
						/* translators: 1: product name 2: seat count */
						__('Cannot change quantity for "%1$s": %2$d seat(s) are selected. Remove the item and add again to change quantity.', 'event-tickets-with-ticket-scanner'),
						esc_html($productName),
						$seatCount
					),
					'error'
				);
				return false;
			}

			// Seating optional: allow reducing quantity (can have fewer seats than tickets)
			// but not increasing beyond what's blocked
			if ($quantity > $seatCount) {
				$productName = $cart_item['data']->get_name();
				wc_add_notice(
					sprintf(
						/* translators: 1: product name 2: seat count */
						__('Cannot increase quantity for "%1$s" beyond %2$d: you have selected %2$d seat(s). Select more seats on the product page first.', 'event-tickets-with-ticket-scanner'),
						esc_html($productName),
						$seatCount
					),
					'error'
				);
				return false;
			}

			return $passed;
		}
	}
}
