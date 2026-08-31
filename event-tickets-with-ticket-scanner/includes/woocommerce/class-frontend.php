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

			// Only load the cart script when it is actually needed. The precise
			// condition is "a restricted product is in this cart" - the master
			// switch is already part of containsProductsWithRestrictions(), so
			// checking it again here would be a second gate for the same rule.
			$added = false;
			if ($this->containsProductsWithRestrictions()) {
				$this->addJSFileAndHandler();
				$added = true;
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
		public function woocommerce_thankyou($order_id = 0): void {
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
		 * Honors the is_ticket flag on the parent product: a stale `_saso_eventticket_list_sale_restriction`
		 * post_meta entry on a non-ticket product (common after a staging→live migration or after a
		 * product is reconfigured from "ticket with code" to "regular product") must NOT trigger the
		 * restriction-code UI or validation. This is the cart/checkout analogue of the
		 * 3.1.8 ticket-specific-UI gate — without it the customer sees a code field on a product that
		 * has none in the admin.
		 *
		 * @return bool True if cart contains products with restrictions
		 */
		public function containsProductsWithRestrictions(): bool {
			if ($this->_containsProductsWithRestrictions === null) {
				$this->_containsProductsWithRestrictions = false;
				// wcRestrictPurchase is the global off-switch for the feature.
				// Gating here covers every consumer at once (checkout validation,
				// cart input, JS loading) - previously only the JS was gated, so
				// switching it off still enforced codes on products that had a
				// list assigned, with no visible way to configure them.
				if (!$this->MAIN->getOptions()->isOptionCheckboxActive('wcRestrictPurchase')) {
					return false;
				}
				if (WC()->cart === null) {
					return false;
				}
				foreach (WC()->cart->get_cart() as $cart_item) {
					if ($this->getEffectiveRestrictionListId($cart_item['product_id']) !== '') {
						$this->_containsProductsWithRestrictions = true;
						break;
					}
				}
			}
			return $this->_containsProductsWithRestrictions;
		}

		/**
		 * Effective restriction-code list id for a cart item, with stale post_meta stripped.
		 *
		 * Returns the `_saso_eventticket_list_sale_restriction` value for products that are
		 * (still) ticket products, and an empty string for everything else. The empty return
		 * value means "no restriction applies" for every caller — `containsProductsWithRestrictions`,
		 * `woocommerce_after_cart_item_name_handler` (cart input) and `check_code_for_cartitem`
		 * (checkout validation) all branch on this same helper, so the gate is enforced in one place.
		 *
		 * Without this helper, a non-ticket product with stale post_meta from a previous
		 * configuration (very common after staging→live DB migrations) keeps demanding a code
		 * the customer cannot supply.
		 *
		 * For variable products the cart passes the variation id as `$product_id`. The ticket
		 * meta (`is_ticket`, `_saso_eventticket_list_sale_restriction`) lives on the parent, so
		 * we resolve to the parent before deciding whether the product is a ticket. Without
		 * this step every variation of a ticket parent would be treated as "not a ticket" and
		 * the restriction code would silently disappear for variable products.
		 *
		 * @param int $product_id Product id (variation or parent).
		 * @return string Empty string = no restriction; otherwise the restriction-code list id.
		 */
		public function getEffectiveRestrictionListId(int $product_id): string {
			if ($product_id < 1) return '';
			$parent_id = wp_get_post_parent_id($product_id);
			$effective_id = $parent_id > 0 ? $parent_id : $product_id;
			if (!$this->MAIN->getWC()->getProductManager()->isTicketByProductId($effective_id)) {
				return '';
			}
			$value = get_post_meta($effective_id, self::META_KEY_CODELIST_RESTRICTION, true);
			return is_string($value) ? $value : (string) $value;
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
				$cart_item[self::META_KEY_CODELIST_RESTRICTION_ORDER_ITEM] = $code;

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
				// Gate by is_ticket via the shared helper: a stale restriction post_meta on a
				// non-ticket product must not turn a customer-typed code into a rejection.
				$saso_eventtickets_list_id = $this->getEffectiveRestrictionListId(intval($cart_item['product_id']));
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
							// Per-product opt-in: the same code may unlock several
							// purchases. Only the used-check is skipped - active
							// state and list membership above still apply.
							$allow_multiuse = get_post_meta($cart_item['product_id'], 'saso_eventtickets_restriction_allow_multiuse', true) === 'yes';
							if (!$allow_multiuse) {
								return 2; // used
							}
						}
						return 1; // valid
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
					'readonly' => 'readonly',
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
			$daychooser_cutoff_time = get_post_meta($product_id_orig, 'saso_eventtickets_daychooser_cutoff_time', true);
			if (!empty($daychooser_cutoff_time) && $saso_eventtickets_daychooser_offset_start == 0) {
				// Absolute moment in shop time — the raw "HH:MM" would be compared
				// against the buyer's browser clock and differ from the server rule
				$cutoff_ts = $this->MAIN->getTicketHandler()->localDateToTimestamp(
					wp_date('Y-m-d'),
					sanitize_text_field($daychooser_cutoff_time)
				);
				if ($cutoff_ts > 0) {
					$params['custom_attributes']['data-cutoff-ts'] = $cutoff_ts;
				}
			}

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

			echo '<div id="datepicker-wrapper_' . $cart_item_key . '_' . $a . '" class="saso-eventtickets-datepicker' . ($disabled ? ' saso-datepicker-locked' : '') . '" data-product-id="' . $product_id . '">';
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
			// Show input for purchase restriction code (separate feature — works on
			// any product type, ticket or not).
			// go through getEffectiveRestrictionListId() so stale post_meta on a non-ticket
			// product (common after a staging→live migration) does not trigger the field.
			$saso_eventtickets_list = $this->getEffectiveRestrictionListId(intval($cart_item['product_id']));
			if (!empty($saso_eventtickets_list) && $this->MAIN->getOptions()->isOptionCheckboxActive('wcRestrictPurchase')) {
				$code = isset($cart_item[self::META_KEY_CODELIST_RESTRICTION_ORDER_ITEM]) ? $cart_item[self::META_KEY_CODELIST_RESTRICTION_ORDER_ITEM] : '';
				$infoLabel = $this->MAIN->getOptions()->getOptionValue('wcRestrictCartInfo');
				$fieldPlaceholder = $this->MAIN->getOptions()->getOptionValue('wcRestrictCartFieldPlaceholder');
				$html = '<p class="form-row form-row-wide"><label>%s</label>
							<input
								type="text"
								maxlength="140"
								placeholder="%s"
								data-input-type="%s"
								data-cart-item-id="%s"
								data-plugin="event"
								data-plg="' . esc_attr($this->MAIN->getPrefix()) . '"
								value="%s"
								class="input-text" /></p>';
				printf(
					str_replace("\n", "", $html),
					esc_html($infoLabel),
					esc_attr($fieldPlaceholder),
					esc_attr($this->js_inputType),
					esc_attr($cart_item_key),
					wc_clean($code)
				);
			}

			// Top-level gate: everything below is ticket-specific UI (daychooser,
			// seats, name-per-ticket, value-per-ticket). If the parent product is
			// not (or no longer) a ticket — even if individual variation/product
			// flags are still saved in postmeta from a previous configuration —
			// none of it must render. The is_ticket flag on the parent product
			// is the single source of truth.
			$_pid_ticket = isset($cart_item['product_id']) ? intval($cart_item['product_id']) : 0;
			if ($_pid_ticket < 1 || !$this->MAIN->getWC()->getProductManager()->isTicketByProductId($_pid_ticket)) {
				return;
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
						// Die Variante bringt ihren eigenen Plan mit; ohne eigenen erbt sie den des Produkts.
						$variationIdOfItem = isset($cart_item['variation_id']) ? intval($cart_item['variation_id']) : 0;
						$planOfItem = $seating->getFrontendManager()->getPlanForProductFrontend($product_id_orig, $variationIdOfItem > 0 ? $variationIdOfItem : null);
						$seatingRequired = get_post_meta($product_id_orig, $seating->getMetaProductSeatingRequired(), true) === 'yes';
						if ($planOfItem !== null && $seatingRequired) {
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
			$_vid = isset($cart_item['variation_id']) ? intval($cart_item['variation_id']) : 0;
			$_pid = intval($cart_item['product_id']);
			$_th = $this->MAIN->getTicketHandler();
			$saso_eventtickets_request_name_per_ticket = $_th->getMetaWithVariationFallback($_pid, $_vid, "saso_eventtickets_request_name_per_ticket") == "yes";
			if ($saso_eventtickets_request_name_per_ticket) {
				$anzahl = intval($cart_item["quantity"]);
				if ($anzahl > 0) {
					$valueArray = WC()->session->get("saso_eventtickets_request_name_per_ticket");

					$label = esc_attr($_th->getLabelNamePerTicket($_pid, $_vid));
					for ($a = 0; $a < $anzahl; $a++) {
						$value = "";
						if ($valueArray != null && isset($valueArray[$cart_item_key]) && isset($valueArray[$cart_item_key][$a])) {
							$value = trim($valueArray[$cart_item_key][$a]);
						}
						$html = '<p class="form-row form-row-wide"><label>' . esc_html(str_replace("{count}", $a + 1, $label)) . '</label>
								<input type="text" data-input-type="text"
									name="saso_eventtickets_request_name_per_ticket[%s][]"
									data-cart-item-id="%s"
									data-cart-item-count="%s"
									data-plugin="event"
									data-plg="' . esc_attr($this->MAIN->getPrefix()) . '"
									value="%s"
									class="input-text" /></p>';
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
			$saso_eventtickets_request_value_per_ticket = $_th->getMetaWithVariationFallback($_pid, $_vid, "saso_eventtickets_request_value_per_ticket") == "yes";
			if ($saso_eventtickets_request_value_per_ticket) {
				$anzahl = intval($cart_item["quantity"]);
				if ($anzahl > 0) {
					$valueArray = WC()->session->get("saso_eventtickets_request_value_per_ticket");

					$dropdown_values = $_th->getMetaWithVariationFallback($_pid, $_vid, "saso_eventtickets_request_value_per_ticket_values");
					$dropdown_def = $_th->getMetaWithVariationFallback($_pid, $_vid, "saso_eventtickets_request_value_per_ticket_def");
					if (!empty($dropdown_values)) {
						$is_mandatory = $_th->getMetaWithVariationFallback($_pid, $_vid, "saso_eventtickets_request_value_per_ticket_mandatory") == "yes";
						$label_option = esc_attr($_th->getLabelValuePerTicket($_pid, $_vid));
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

							$html = '<p class="form-row form-row-wide"><label>' . esc_html($l) . '</label>
									<select
										name="saso_eventtickets_request_value_per_ticket[%s][]"
										data-input-type="value"
										data-cart-item-id="%s"
										data-cart-item-count="%s"
										data-plugin="event"
										data-plg="' . esc_attr($this->MAIN->getPrefix()) . '">' . $html_options . '</select></p>';
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

			// Check sales cutoff (online sales closed before event start)
			$this->validateSalesCutoff($cart_items);

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
			$_th = $this->MAIN->getTicketHandler();
			$_pm = $this->MAIN->getWC()->getProductManager();

			foreach ($cart_items as $item_id => $cart_item) {
				$_vid = isset($cart_item['variation_id']) ? intval($cart_item['variation_id']) : 0;
				$_pid = intval($cart_item['product_id']);
				// Top-level gate: only validate when the parent is actually a ticket.
				if ($_pid < 1 || !$_pm->isTicketByProductId($_pid)) {
					continue;
				}
				$request_name = $_th->getMetaWithVariationFallback($_pid, $_vid, "saso_eventtickets_request_name_per_ticket") == "yes";
				if (!$request_name) {
					continue;
				}

				$mandatory = $_th->getMetaWithVariationFallback($_pid, $_vid, "saso_eventtickets_request_name_per_ticket_mandatory") == "yes";
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
			$_th = $this->MAIN->getTicketHandler();
			$_pm = $this->MAIN->getWC()->getProductManager();

			foreach ($cart_items as $item_id => $cart_item) {
				$_vid = isset($cart_item['variation_id']) ? intval($cart_item['variation_id']) : 0;
				$_pid = intval($cart_item['product_id']);
				// Top-level gate: only validate when the parent is actually a ticket.
				if ($_pid < 1 || !$_pm->isTicketByProductId($_pid)) {
					continue;
				}
				$request_value = $_th->getMetaWithVariationFallback($_pid, $_vid, "saso_eventtickets_request_value_per_ticket") == "yes";
				if (!$request_value) {
					continue;
				}

				$mandatory = $_th->getMetaWithVariationFallback($_pid, $_vid, "saso_eventtickets_request_value_per_ticket_mandatory") == "yes";
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
		/**
		 * Hide the buy button once online sales for a ticket are closed
		 *
		 * Runs on woocommerce_is_purchasable and woocommerce_variation_is_purchasable,
		 * which also covers direct ?add-to-cart= links and the Store API.
		 *
		 * Deliberately narrow: never turns a false into a true, stays out of the
		 * admin, ignores everything that is not a ticket, and leaves day chooser
		 * products alone — their date is picked per cart item, so the product as
		 * such never expires.
		 *
		 * @param bool $purchasable Current state
		 * @param mixed $product Product object
		 * @return bool
		 */
		public function woocommerce_is_purchasable_handler($purchasable, $product): bool {
			if (!$purchasable || !($product instanceof WC_Product)) {
				return (bool) $purchasable;
			}

			// Admin screens keep working — an organiser must be able to edit and
			// hand-book a product whose event already started
			if (is_admin() && !wp_doing_ajax()) {
				return true;
			}

			$product_id = $product->get_id();
			$variation_id = 0;
			if ($product->is_type('variation')) {
				$variation_id = $product_id;
				$product_id = $product->get_parent_id();
			}

			if ($product_id < 1 || !$this->MAIN->getWC()->getProductManager()->isTicketByProductId($product_id)) {
				return true;
			}

			// No selected date at product level, so a day chooser never resolves to
			// a cutoff here — see Core::getSalesCutoffTimestamp()
			return !$this->MAIN->getCore()->isSalesCutoffReached($product_id, null, $variation_id);
		}

		/**
		 * Explain on the product page why the buy button is gone
		 *
		 * @return void
		 */
		public function woocommerce_single_product_summary_cutoff(): void {
			global $product;
			if (!($product instanceof WC_Product)) {
				return;
			}

			$product_id = $product->get_id();
			if (!$this->MAIN->getWC()->getProductManager()->isTicketByProductId($product_id)) {
				return;
			}

			if (!$this->MAIN->getCore()->isSalesCutoffReached($product_id)) {
				return;
			}

			echo '<p class="saso-eventtickets-sales-closed">' . $this->getSalesCutoffLabel($product->get_name(), 'wcTicketTransSalesCutoffMessage', '', (int) $product_id) . '</p>';
		}

		/**
		 * Customer facing notice for a product whose online sales are closed
		 *
		 * @param string $product_name Product name for the {PRODUCT_NAME} placeholder
		 * @return void
		 */
		private function displayWarningSalesCutoff(string $product_name): void {
			wc_add_notice($this->getSalesCutoffLabel($product_name), 'error');
		}

		/**
		 * Escaped "sales closed" text for a product
		 *
		 * @param string $product_name Product name for the {PRODUCT_NAME} placeholder
		 * @param string $option_key Option holding the text
		 * @param string $fallback Text used when the option is empty, with {PRODUCT_NAME}
		 * @return string
		 */
		private function getSalesCutoffLabel(string $product_name, string $option_key = 'wcTicketTransSalesCutoffMessage', string $fallback = '', int $product_id = 0): string {
			$label = trim((string) $this->MAIN->getOptions()->getOptionValue($option_key));
			if ($label === '') {
				/* translators: %s: product name */
				$label = $fallback !== '' ? $fallback : sprintf(__('Online sales for "%s" have closed.', 'event-tickets-with-ticket-scanner'), '{PRODUCT_NAME}');
			}
			$label = str_replace('{PRODUCT_NAME}', '%s', $label);
			$label = wp_kses_post(sprintf($label, esc_html($product_name)));

			// Einstieg fuer das Premium: eigener Text je Event und der Hinweis auf
			// die Abendkasse. Der Shop-Text bleibt die Vorgabe, das Premium
			// verfeinert ihn - deshalb hier und nicht in jeder Aufrufstelle.
			return (string) apply_filters(
				$this->MAIN->_add_filter_prefix.'wc_salesCutoffLabel',
				$label,
				$product_name,
				$product_id,
				$option_key
			);
		}

		/**
		 * Own wording when WooCommerce drops a closed ticket from the cart
		 *
		 * WooCommerce removes items that are no longer purchasable while loading the
		 * session and says "contact us if you need assistance" — misleading when the
		 * presale simply ended. Every other removal reason keeps WooCommerce's text.
		 *
		 * @param string $message WooCommerce message
		 * @param mixed $product Product that was removed
		 * @return string
		 */
		public function woocommerce_cart_item_removed_message_handler($message, $product): string {
			if (!($product instanceof WC_Product)) {
				return (string) $message;
			}

			$product_id = $product->is_type('variation') ? $product->get_parent_id() : $product->get_id();
			$variation_id = $product->is_type('variation') ? $product->get_id() : 0;

			if ($product_id < 1 || !$this->MAIN->getWC()->getProductManager()->isTicketByProductId($product_id)) {
				return (string) $message;
			}

			// Removed for some other reason (out of stock, unpublished, ...)
			if (!$this->MAIN->getCore()->isSalesCutoffReached($product_id, null, $variation_id)) {
				return (string) $message;
			}

			return $this->getSalesCutoffLabel(
				$product->get_name(),
				'wcTicketTransSalesCutoffRemovedMessage',
				/* translators: %s: product name */
				sprintf(__('Online sales for "%s" have closed, so it was removed from your cart.', 'event-tickets-with-ticket-scanner'), '{PRODUCT_NAME}'),
				(int) $product_id
			);
		}

		/**
		 * Validate the sales cutoff for cart items
		 *
		 * Catches carts that were filled before the cutoff and held past it.
		 *
		 * @param array $cart_items Cart items
		 * @return void
		 */
		private function validateSalesCutoff(array $cart_items): void {
			$core = $this->MAIN->getCore();
			$_pm = $this->MAIN->getWC()->getProductManager();

			foreach ($cart_items as $item_id => $cart_item) {
				$_pid = isset($cart_item['product_id']) ? intval($cart_item['product_id']) : 0;
				if ($_pid < 1 || !$_pm->isTicketByProductId($_pid)) {
					continue;
				}
				$_vid = isset($cart_item['variation_id']) ? intval($cart_item['variation_id']) : 0;

				// Day chooser: every picked date is checked on its own
				$dates = [null];
				if (get_post_meta($_pid, 'saso_eventtickets_is_daychooser', true) == 'yes') {
					$key = self::SESSION_KEY_DAYCHOOSER;
					$valueArray = isset($cart_item[$key]) ? $cart_item[$key] : $this->session_get_value($key . '_' . $item_id);
					$dates = is_array($valueArray) ? array_filter(array_map('trim', $valueArray)) : [];
				}

				foreach ($dates as $date) {
					if ($core->isSalesCutoffReached($_pid, $date, $_vid)) {
						$this->displayWarningSalesCutoff($cart_item['data']->get_name());
						break;
					}
				}
			}
		}

		private function validateDayChooserDates(array $cart_items): void {
			$_pm = $this->MAIN->getWC()->getProductManager();
			foreach ($cart_items as $item_id => $cart_item) {
				$_pid = isset($cart_item['product_id']) ? intval($cart_item['product_id']) : 0;
				// Top-level gate: only validate when the parent is actually a ticket.
				if ($_pid < 1 || !$_pm->isTicketByProductId($_pid)) {
					continue;
				}
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

					// Validate excluded weekdays (0=Sun, 1=Mon, ..., 6=Sat)
					$exclude_wdays = $dates['daychooser_exclude_wdays'] ?? [];
					if (!empty($exclude_wdays) && is_array($exclude_wdays)) {
						$dayOfWeek = (int) date('w', strtotime($value));
						if (in_array($dayOfWeek, array_map('intval', $exclude_wdays), true)) {
							$this->displayWarningDatePicker($cart_item['data']->get_name(), $item_id, $a);
							continue;
						}
					}

					// Validate same-day cutoff time — the admin enters it in shop time,
					// so it must not be read with the PHP timezone (WordPress runs on UTC)
					$cutoff_time = get_post_meta($cart_item['product_id'], 'saso_eventtickets_daychooser_cutoff_time', true);
					if (!empty($cutoff_time) && $offset_start == 0 && $value === wp_date('Y-m-d')) {
						$cutoff_ts = $this->MAIN->getTicketHandler()->localDateToTimestamp(wp_date('Y-m-d'), $cutoff_time);
						if ($cutoff_ts > 0 && time() >= $cutoff_ts) {
							$this->displayWarningDatePicker($cart_item['data']->get_name(), $item_id, $a);
							continue;
						}
					}

					// Validate excluded specific dates (Premium feature)
					if ($this->MAIN->isPremium() && method_exists($this->MAIN->getPremiumFunctions(), 'getDayChooserExclusionDates')) {
						$product_id_orig = $this->MAIN->getTicketHandler()->getWPMLProductId($cart_item['product_id']);
						$exclusionDates = $this->MAIN->getPremiumFunctions()->getDayChooserExclusionDates($product_id_orig);
						if (!empty($exclusionDates) && in_array($value, $exclusionDates, true)) {
							$this->displayWarningDatePicker($cart_item['data']->get_name(), $item_id, $a);
							continue;
						}
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
				// Skip the input field validations on the cart page when the checkout-only
				// option is active — but the sales cutoff is no input field and must still
				// block here, not least because the block checkout never fires
				// woocommerce_checkout_process and this hook is all it triggers
				$this->validateSalesCutoff(WC()->cart->get_cart());
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

				$this->addDatepickerHTML($name, 0, $product_id, "", null, null, null, $name, ["data-is-shop-page" => "1"]);

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
			$frontendManager = $seating->getFrontendManager();

			// Welcher Plan gilt, sagt eine Stelle: der Resolver. In der Shop-Uebersicht
			// steht keine Variante fest, hier gilt der Plan des Produkts.
			$plan = $frontendManager->getPlanForProductFrontend($product_id_orig);
			if ($plan) {
				$frontendManager->enqueueScripts();

				echo '<div class="saso-seating-wrapper" data-product-id="' . esc_attr($product_id) . '" data-requires-date="' . ($isDaychooser ? '1' : '0') . '">';
				echo $frontendManager->renderSeatSelector($product_id_orig, $eventDate);
				echo '</div>';
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

				$this->addDatepickerHTML($name, 0, $product_id, "", null, null, null, $name, ["data-is-shop-page" => "1"]);

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
			$frontendManager = $seating->getFrontendManager();

			// Der Kunde waehlt die Variante erst im Browser. Serverseitig faellt hier
			// der Plan des Produkts, das JS tauscht die Karte beim Variantenwechsel.
			$plan = $frontendManager->getPlanForProductFrontend($product_id);

			// Auch wenn das Produkt selbst keinen Plan hat: sobald eine Variante
			// einen mitbringt, braucht die Seite den Platz dafuer. Sonst hat das
			// JS beim Variantenwechsel nichts, was es fuellen koennte.
			if ($plan || $frontendManager->hasVariationPlans($product_id)) {
				$frontendManager->enqueueScripts();

				echo '<div class="saso-seating-wrapper" data-product-id="' . esc_attr($product_id) . '" data-requires-date="' . ($isDaychooser ? '1' : '0') . '">';
				if ($plan) {
					echo $frontendManager->renderSeatSelector($product_id, $eventDate);
				}
				echo '</div>';
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
		public function woocommerce_add_cart_item_data_handler(array $cart_item_data, $product_id, $variation_id = 0): array {
		$product_id = intval($product_id);
		$variation_id = intval($variation_id);
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
		public function woocommerce_add_to_cart_validation_handler($passed, $product_id, $quantity): bool {
		$product_id = intval($product_id);
		$quantity = intval($quantity);
			$selectedDate = null;

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

				$selectedDate = $date;
			}

			// Sales cutoff: online sales close X hours before the event starts
			if ($this->MAIN->getWC()->getProductManager()->isTicketByProductId($product_id)) {
				$variation_id = isset($_REQUEST['variation_id']) ? intval($_REQUEST['variation_id']) : 0;
				if ($this->MAIN->getCore()->isSalesCutoffReached($product_id, $selectedDate, $variation_id)) {
					$product = wc_get_product($product_id);
					$this->displayWarningSalesCutoff($product ? $product->get_name() : '');
					return false;
				}
			}

			// Seating validation
			$seating = $this->MAIN->getSeating();
			$variationIdOfRequest = isset($_REQUEST['variation_id']) ? intval($_REQUEST['variation_id']) : 0;
			$frontendManager = $seating->getFrontendManager();
			$fieldName = $seating->getFieldSeatSelection();
			$seatingRequired = get_post_meta($product_id, $seating->getMetaProductSeatingRequired(), true) === 'yes';
			// wp_unslash is required because WordPress adds slashes to all $_REQUEST data
			// sanitize_text_field is not suitable for JSON - validation is done via json_decode
			$seatSelection = isset($_REQUEST[$fieldName]) ? wp_unslash($_REQUEST[$fieldName]) : '';

			// Check if plan is actually available to customers (published).
			// Die gewaehlte Variante entscheidet, gegen welchen Plan geprueft wird.
			$plan = $frontendManager->getPlanForProductFrontend($product_id, $variationIdOfRequest > 0 ? $variationIdOfRequest : null);
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
		public function woocommerce_add_to_cart_handler($cart_item_key, $product_id, $quantity, $variation_id, $variation = [], $cart_item_data = []): void {
		$product_id = intval($product_id);
		$quantity = intval($quantity);
		$variation_id = intval($variation_id);
		// Some 3rd-party plugins (e.g. WooCommerce Wishlists) call WC_Cart::add_to_cart()
		// with $variation as a string instead of an array, which would trip a strict
		// array type-hint and abort the whole cart operation. Normalize defensively.
		if (!is_array($variation)) $variation = [];
		if (!is_array($cart_item_data)) $cart_item_data = [];
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
					$totalQty = intval($line['quantity']);
					$addedQty = $quantity;

					// Get fallback value in case cart item is adjusted by other plugins
					$valueArray = $this->session_get_value($key . '_' . $cart_item_key);
					if ($valueArray != null && is_array($valueArray)) {
						$line[$key] = $valueArray;
					}
					if (!isset($line[$key]) || !is_array($line[$key])) {
						$line[$key] = [];
					}

					// Trim to pre-merge quantity (totalQty - addedQty), then append new dates
					$oldQty = max(0, $totalQty - $addedQty);
					$line[$key] = array_slice($line[$key], 0, $oldQty);
					for ($i = 0; $i < $addedQty; $i++) {
						$line[$key][] = $date;
					}

					$product_id_orig = $this->MAIN->getTicketHandler()->getWPMLProductId($product_id);
					$display_only_one_datepicker = get_post_meta($product_id_orig, 'saso_eventtickets_only_one_day_for_all_tickets', true) == "yes";
					if ($display_only_one_datepicker) {
						$line[$key] = array_fill(0, $totalQty, $date);
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

			// Clean up daychooser session data
			$this->session_unset_value(self::SESSION_KEY_DAYCHOOSER . '_' . $cart_item_key);

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
		public function woocommerce_after_cart_item_quantity_update_handler($cart_item_key, $quantity, $old_quantity, ?\WC_Cart $cart = null): void {
		$quantity = intval($quantity);
		$old_quantity = intval($old_quantity);
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
		public function woocommerce_update_cart_validation_handler($passed, $cart_item_key, array $values, $quantity): bool {
		$quantity = intval($quantity);
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
