<?php
include_once(plugin_dir_path(__FILE__)."init_file.php");
final class sasoEventtickets_Ticket {
	private $MAIN;

	private $request_uri;
	private $parts = null;

	private $codeObj;
	private $order;
	private $orders_cache = [];

	private $isScanner = null;
	private $authtoken = null; // only set if the ticket scanner is sending the request with authtoken

	private $redeem_successfully = false;
	private $onlyLoggedInScannerAllowed = null;

	public static function Instance($request_uri) {
		static $inst = null;
        if ($inst === null) {
            $inst = new sasoEventtickets_Ticket($request_uri);
        } else {
			$inst->setRequestURI($request_uri);
		}
        return $inst;
	}

	public function __construct($request_uri) {
		global $sasoEventtickets;
		if ($sasoEventtickets == null) {
			$sasoEventtickets = sasoEventtickets::Instance();
		}
		$this->MAIN = $sasoEventtickets;

		$this->setRequestURI($request_uri);
		// Options are loaded lazily to avoid triggering translations before 'init'
		add_action( 'init', [$this, 'initOptionsFromConstructor'], 1 );
	}

	public function initOptionsFromConstructor(): void {
		$this->onlyLoggedInScannerAllowed = $this->MAIN->getOptions()->isOptionCheckboxActive('wcTicketOnlyLoggedInScannerAllowed');
		if ($this->MAIN->getOptions()->isOptionCheckboxActive('wcTicketActivateOBFlush')) {
			remove_action( 'shutdown', 'wp_ob_end_flush_all', 1 );
			add_action( 'shutdown', function() {
				while ( ob_get_level() > 0 ) {
					@ob_end_flush();
				}
			} );
		}
	}

	private function isOnlyLoggedInScannerAllowed(): bool {
		if ($this->onlyLoggedInScannerAllowed === null) {
			$this->onlyLoggedInScannerAllowed = $this->MAIN->getOptions()->isOptionCheckboxActive('wcTicketOnlyLoggedInScannerAllowed');
		}
		return $this->onlyLoggedInScannerAllowed;
	}

	public function getWPMLProductId($product_id) {
		$pid = $product_id;
		if ($product_id) {
			$pid = apply_filters('wpml_object_id', $product_id, 'product', true );
			// polygone error handling or any other idiot is overwriting the wpml filter
			if ($pid == null || empty($pid) || !is_numeric($pid) || intval($pid) < 1) {
				$pid = $product_id;
			}
		}
		return $pid;
	}

	public function setRequestURI($request_uri) {
		$this->request_uri = trim($request_uri);
	}

	public function cronJobDaily() {
		$this->hideAllTicketProductsWithExpiredEndDate();
		$this->checkForPremiumSerialExpiration();
		do_action( $this->MAIN->_do_action_prefix.'ticket_cronJobDaily' );
	}

	/**
	 * Get premium subscription expiration info
	 *
	 * @return array Expiration info with keys: last_run, timestamp, expiration_date, timezone, subscription_type, grace_period_days
	 */
	public function get_expiration(): array {
		$option_name = $this->MAIN->getPrefix()."_premium_serial_expiration";
		$info = get_option( $option_name );
		$info_obj = [
			"last_run" => 0,
			"timestamp" => 0,
			"expiration_date" => "",
			"timezone" => "",
			"subscription_type" => "abo",      // 'abo' or 'lifetime'
			"grace_period_days" => 7,          // Days after expiration where features still work
			"last_success" => 0,               // Last successful license check
			"consecutive_failures" => 0        // Failed license check attempts
		];
		if (!empty($info)) {
			$info_obj = array_merge($info_obj, json_decode($info, true));
		}
		$info_obj = apply_filters( $this->MAIN->_add_filter_prefix.'ticket_get_expiration', $info_obj );
		return $info_obj;
	}

	/**
	 * Check if the premium subscription is currently active
	 *
	 * Considers:
	 * - Lifetime licenses (timestamp = -1 or subscription_type = 'lifetime')
	 * - Regular subscriptions with expiration date
	 * - Grace period after expiration
	 *
	 * @return bool True if subscription is active, false if expired
	 */
	public function isSubscriptionActive(): bool {
		$info = $this->get_expiration();

		// Lifetime = immer aktiv
		if (isset($info['timestamp']) && $info['timestamp'] == -1) return true;
		if (isset($info['subscription_type']) && $info['subscription_type'] === 'lifetime') return true;

		// Zu viele Fehler hintereinander = inaktiv
		if (intval($info['consecutive_failures'] ?? 0) >= 5) return false;

		// Checksum-Prüfung: wurden Werte manipuliert?
		if (!$this->verifyExpirationChecksum($info)) return false;

		// Noch nie erfolgreich geprüft?
		$last_success = intval($info['last_success'] ?? 0);
		if ($last_success === 0) {
			// First-Install Grace: 3 Tage Zeit für ersten Server-Check
			$first_seen = intval($info['first_seen'] ?? 0);
			if ($first_seen === 0) {
				// Allererster Aufruf - first_seen setzen
				$this->setFirstSeen();
				return true;
			}
			// Mehr als 3 Tage ohne erfolgreichen Check = inaktiv
			return (time() - $first_seen) < (3 * 86400);
		}

		// Letzter erfolgreicher Check zu alt (>10 Tage) = inaktiv
		if ((time() - $last_success) > (10 * 86400)) return false;

		// Kein Timestamp vom Server = inaktiv (außer in Grace Period)
		if (empty($info['timestamp']) || $info['timestamp'] <= 0) return false;

		// Normale Expiration + Grace Period
		$grace_days = intval($info['grace_period_days'] ?? 7);
		return time() < ($info['timestamp'] + ($grace_days * 86400));
	}

	/**
	 * Calculate HMAC checksum over critical license fields to detect DB manipulation.
	 */
	private function calculateExpirationChecksum(array $info): string {
		$critical = [
			'timestamp' => $info['timestamp'] ?? 0,
			'last_success' => $info['last_success'] ?? 0,
			'notvalid' => $info['notvalid'] ?? 0,
			'consecutive_failures' => $info['consecutive_failures'] ?? 0,
		];
		$salt = defined('AUTH_KEY') ? AUTH_KEY : 'saso_et_fallback_salt';
		return hash_hmac('sha256', json_encode($critical), $salt);
	}

	/**
	 * Verify that stored license data has not been tampered with.
	 * Legacy data without checksum is accepted.
	 */
	private function verifyExpirationChecksum(array $info): bool {
		if (!isset($info['_checksum'])) return true; // Legacy-Daten ohne Checksum = OK
		return hash_equals($info['_checksum'], $this->calculateExpirationChecksum($info));
	}

	/**
	 * Record the first time the plugin was seen (for grace period on fresh installs).
	 */
	private function setFirstSeen(): void {
		$option_name = $this->MAIN->getPrefix() . "_premium_serial_expiration";
		$info = get_option($option_name);
		$info_obj = !empty($info) ? json_decode($info, true) : [];
		$info_obj['first_seen'] = time();
		$info_obj['_checksum'] = $this->calculateExpirationChecksum($info_obj);
		update_option($option_name, json_encode($info_obj));
	}

	public function checkForPremiumSerialExpiration() {
		$option_name = $this->MAIN->getPrefix()."_premium_serial_expiration";
		// check the expiration of the premium serial
		if ($this->MAIN->isPremium()) {
			$info_obj = $this->get_expiration();
			$doCheck = false;
			if ($info_obj["last_run"] == 0) {
				$doCheck = true;
			} else {
				if (isset($info_obj["timestamp"])) {
					if ($info_obj["timestamp"] >= 0) {
						$doCheck = true;
						if (strtotime("+21 days") > intval($info_obj["timestamp"])) {
							// check if enough time past after the last check
							if (strtotime("-7 days") < intval($info_obj["last_run"])) {
								$doCheck = false; // wait till the cache expires
							}
						}
					}
				} else {
					$doCheck = true;
				}
			}
			if ($doCheck) {
				$serial = trim(get_option( "saso-event-tickets-premium_serial" ));
				if (!empty($serial) && defined('SASO_EVENTTICKETS_PREMIUM_PLUGIN_VERSION')) {
					$domain = parse_url( get_site_url(), PHP_URL_HOST );

					$url = "https://vollstart.com/plugins/event-tickets-with-ticket-scanner-premium/"
								.'?checking_for_updates=2&ver='.SASO_EVENTTICKETS_PREMIUM_PLUGIN_VERSION
								."&m=".get_option('admin_email')
								."&d=".$domain
								."&serial=".urlencode($serial);

					$response = wp_remote_get($url, ['timeout' => 45]);
					if (is_wp_error($response)) {
						// Fehler tracken
						$info_obj["consecutive_failures"] = intval($info_obj["consecutive_failures"] ?? 0) + 1;
						$info_obj["last_run"] = time();
						$info_obj['_checksum'] = $this->calculateExpirationChecksum($info_obj);
						update_option($option_name, json_encode($info_obj));
					} else {
						$body = wp_remote_retrieve_body( $response );
						$data = json_decode( $body, true );
						if (isset($data["isCheckCall"]) && $data["isCheckCall"] == 1) {
							$info_obj["last_run"] = time();
							$info_obj["last_success"] = time();
							$info_obj["consecutive_failures"] = 0;
							// Server-Daten gezielt übernehmen (Server gewinnt)
							foreach (['timestamp', 'expiration_date', 'timezone', 'notvalid', 'isCheckCall', 'subscription_type', 'grace_period_days'] as $key) {
								if (isset($data[$key])) $info_obj[$key] = $data[$key];
							}
							$info_obj['_checksum'] = $this->calculateExpirationChecksum($info_obj);
							$value = $this->MAIN->getCore()->json_encode_with_error_handling($info_obj);
							update_option($option_name, $value);
						}
					}
				}
			}
		}
		do_action( $this->MAIN->_do_action_prefix.'ticket_checkForPremiumSerialExpiration' );
	}

	private function hideAllTicketProductsWithExpiredEndDate() {
		if ($this->MAIN->getOptions()->isOptionCheckboxActive('wcTicketHideTicketAfterEventEnd')) {
			// Produkte abrufen, die nicht als "Privat" markiert sind
			$products_args = array(
				'post_type' => 'product',
				'post_status' => 'publish',
				'posts_per_page' => -1, // -1 zeigt alle Produkte an
				'meta_query' => array(
					/*array(
						'key' => '_visibility',
						'value' => array('catalog', 'visible'), // Produkte, die nicht als "Privat" gelten
						'compare' => 'IN',
					),*/
					[
					'key' => 'saso_eventtickets_is_ticket',
					'value' => 'yes',
					'compare' => '='
					]
				)
			);

			$products = get_posts($products_args);
			// Ergebnisse überprüfen
			if ($products && function_exists("wp_update_post")) {
				foreach ($products as $product) {
					// check if ticket
					$product_id = $product->ID; //$product->get_id();
					$product_id_orig = $this->getWPMLProductId($product_id);
					//if ($this->MAIN->getWC()->isTicketByProductId($product_id) ) {
						// check if event date end is set
						$dates = $this->calcDateStringAllowedRedeemFrom($product_id_orig);
						if (!empty($dates['ticket_end_date_orig'])) { // only if end date is also set
							// check if expired - non premium
							if ($dates['ticket_end_date_timestamp'] < $dates['server_time_timestamp']) {
								// set product to hidden
								$product_data = array(
									'ID' => $product_id,
									'post_status' => 'private', // Setzen Sie den Status auf 'private'
								);
								wp_update_post($product_data);
								if ($product_id != $product_id_orig) {
									// update the original product too
									$product_data = array(
										'ID' => $product_id_orig,
										'post_status' => 'private', // Setzen Sie den Status auf 'private'
									);
									wp_update_post($product_data);
								}
							}
						}
					//}
				}
			}

			do_action( $this->MAIN->_do_action_prefix.'ticket_hideAllTicketProductsWithExpiredEndDate', $products );
		}
	}

	function rest_permission_callback(WP_REST_Request $web_request) {
		$ret = false;

		// Path 1: Authtoken authentication (for scanner team without WP accounts)
		if ($web_request->has_param($this->MAIN->getAuthtokenHandler()::$authtoken_param)) {
			$authHandler = $this->MAIN->getAuthtokenHandler();
			$this->authtoken = $web_request->get_param($authHandler::$authtoken_param);
			$ret = $authHandler->checkAccessForAuthtoken($this->authtoken);
		} else {
			// Path 2: Check if scanner is open to everyone (no login required)
			$allowed_role = $this->MAIN->getOptions()->getOptionValue('wcTicketScannerAllowedRoles');
			if ($allowed_role == "-" && !$this->isOnlyLoggedInScannerAllowed()) {
				$ret = true;
			} else {
				// Path 3: WordPress user authentication (must be logged in)
				if (!is_user_logged_in()) return false;

				$user = wp_get_current_user();
				$user_roles = (array) $user->roles;

				// Administrators always have access
				if (in_array("administrator", $user_roles)) return true;

				if ($this->isOnlyLoggedInScannerAllowed()) {
					// Strict mode: only administrators (already checked above)
					$ret = false;
				} else if ($allowed_role != "-") {
					// Specific role required
					$ret = in_array($allowed_role, $user_roles);
				}
			}
		}

		$ret = apply_filters( $this->MAIN->_add_filter_prefix.'ticket_rest_permission_callback', $ret, $web_request );
		return $ret;
	}
	function rest_ping(?WP_REST_Request $web_request = null) {
		return ['time'=>time(), 'img_pfad'=>plugins_url( "img/",__FILE__ ), '_ret'=>['_server'=>$this->getTimes()] ];
	}
	function rest_helper_tickets_redeemed($codeObj) {
		$metaObj = $metaObj = $codeObj['metaObj'];
		$ret = [];
		$ret['tickets_redeemed'] = 0;
		$ret['tickets_redeemed_by_codes'] = 0;
		$ret['tickets_redeemed_not_by_codes'] = 0;
		$ret['tickets_redeemed_show'] = false;
		$ret['tickets_redeemed_show_c'] = false;
		$ret['tickets_redeemed_show_cn'] = false;
		if (isset($metaObj['woocommerce']) && isset($metaObj['woocommerce']['product_id'])) {
			if ($this->MAIN->getOptions()->isOptionCheckboxActive('wcTicketDisplayRedeemedAtScanner') == false) {
				$ret['tickets_redeemed_show'] = true;
				if ($this->MAIN->isPremium() && method_exists($this->MAIN->getPremiumFunctions(), 'getTicketStats')) {
					if (method_exists($this->MAIN->getPremiumFunctions()->getTicketStats(), 'getEntryAmountForProductId')) {
						$ret['tickets_redeemed'] = $this->MAIN->getPremiumFunctions()->getTicketStats()->getEntryAmountForProductId($metaObj['woocommerce']['product_id']);
					}
				}
			}
			if ($this->MAIN->getOptions()->isOptionCheckboxActive('wcTicketDisplayRedeemedByCodesAtScanner') == true) {
				$ret['tickets_redeemed_show_c'] = true;
				if ($this->MAIN->isPremium() && method_exists($this->MAIN->getPremiumFunctions(), 'getTicketStats')) {
					if (method_exists($this->MAIN->getPremiumFunctions()->getTicketStats(), 'getEntryAmountForProductIdRedeemed')) {
						$ret['tickets_redeemed_by_codes'] = $this->MAIN->getPremiumFunctions()->getTicketStats()->getEntryAmountForProductIdRedeemed($metaObj['woocommerce']['product_id']);
					}
				}
			}
			if ($this->MAIN->getOptions()->isOptionCheckboxActive('wcTicketDisplayRedeemedNotByCodesAtScanner') == true) {
				$ret['tickets_redeemed_show_cn'] = true;
				if ($this->MAIN->isPremium() && method_exists($this->MAIN->getPremiumFunctions(), 'getTicketStats')) {
					if (method_exists($this->MAIN->getPremiumFunctions()->getTicketStats(), 'getEntryAmountForProductIdNotRedeemed')) {
						$ret['tickets_redeemed_not_by_codes'] = $this->MAIN->getPremiumFunctions()->getTicketStats()->getEntryAmountForProductIdNotRedeemed($metaObj['woocommerce']['product_id']);
					}
				}
			}
		}
		$ret = apply_filters( $this->MAIN->_add_filter_prefix.'ticket_rest_permission_callback', $ret, $codeObj );
		return $ret;
	}

	private function isProductAllowedByAuthToken($product_ids=[]) {
		if (!is_array($product_ids)) {
			$product_ids = [$product_ids];
		}
		$ret = false;
		if ($this->authtoken == null){
			$ret = true;
		} else {
			$authHandler = $this->MAIN->getAuthtokenHandler();
			if ($authHandler->isProductAllowedByAuthToken($this->authtoken, $product_ids)) {
				$ret = true;
			}
		}
		$ret = apply_filters( $this->MAIN->_add_filter_prefix.'ticket_isProductAllowedByAuthToken', $ret, $product_ids );
		if ($ret == false) {
			throw new Exception("#301 - product id ".join(", ", $product_ids)." is not allowed to be rededemed with this ticket scanner authentication");
		}
	}
	private function is_ticket_code_orderticket($code) {
		// is it an order ticket id
		$ret = false;
		$code = trim($code);
		if (strlen($code) > 13 && substr($code, 0, 13) == "ordertickets-") {
			$ret = true;
		}
		$ret = apply_filters( $this->MAIN->_add_filter_prefix.'ticket_is_ticket_code_orderticket', $ret, $code );
		return $ret;
	}
	function rest_retrieve_ticket($web_request) {
		if (!SASO_EVENTTICKETS::issetRPara('code')) {
			return wp_send_json_error(esc_html__("code missing", 'event-tickets-with-ticket-scanner'));
		}
		$code = trim(SASO_EVENTTICKETS::getRequestPara('code'));
		if ($this->is_ticket_code_orderticket($code)) {
			return $this->retrieve_order_ticket($code);
		}
		return $this->retrieve_ticket($code);
	}
	private function retrieve_order_ticket($code) {
		$parts = $this->getParts($code);
		if (!isset($parts["order_id"]) || !isset($parts["code"])) throw new Exception("#299 - wrong order ticket id");
		if (empty($parts["order_id"]) || empty($parts["code"])) throw new Exception("#297 - wrong order ticket id");

		$infos = $this->getOrderTicketsInfos($parts['order_id'], $parts['code']);
		if (!is_array($infos)) throw new Exception("#298 - wrong order ticket id");

		// TODO:check auch ob sofort redeem gemacht werden soll
			// redeem liefert für jedes ticket eine Meldung - muss dann aufgelistet werden im ticket scanner

		$infos = apply_filters( $this->MAIN->_add_filter_prefix.'ticket_retrieve_order_ticket', $infos, $code );

		return $infos;
	}
	private function retrieve_ticket($code) {
		$ret = [];

		// check if redeem immediately is requested
		if (isset($_GET['redeem']) && $_GET['redeem'] == "1") {
			// redeem immediately
			$_redeem_ret = [];
			try {
				$_redeem_ret = $this->redeem_ticket($code);
				$this->setCodeObj(null); // reset object
			} catch(Exception $e) {
				$_redeem_ret = ["error"=>$e->getMessage()];
			}
			$ret["redeem_operation"] = $_redeem_ret;
		}

		$codeObj = $this->getCodeObj(true, $code);
		$codeObj = apply_filters( $this->MAIN->_add_filter_prefix.'filter_updateExpirationInfo', $codeObj );
		$metaObj = $codeObj['metaObj'];

		$order = $this->getOrderById($codeObj["order_id"]);
		$order_item = $this->getOrderItem($order, $metaObj);
		if ($order_item == null) return wp_send_json_error(__("Order item not found", 'event-tickets-with-ticket-scanner'));
		$product = $order_item->get_product();
		if ($product == null) return wp_send_json_error(esc_html__("product of the order and ticket not found!", 'event-tickets-with-ticket-scanner'));

		$is_variation = $product->get_type() == "variation" ? true : false;
		$product_parent = $product;
		$product_parent_id = $product->get_parent_id();

		if ($is_variation && $product_parent_id > 0) {
			$product_parent = $this->get_product( $product_parent_id );
		}

		$product_original = $product; // original product, due to wpml
		$product_parent_original = $product_parent; // original product parent, due to wpml
		$product_original_id = $product->get_id();

		// load a possible language based product
		$product_original_id = $this->getWPMLProductId($product_original_id);
		if ($product_original == null) {
			return wp_send_json_error(esc_html__("original product of the order and ticket not found!", 'event-tickets-with-ticket-scanner'));
		}
		if ($product_original_id < 1) {
			$product_original_id = $product->get_id(); // repair the product id
		} else {
			$product_original = $this->get_product($product_original_id);
			if ($product_original_id > 0 && $product_original_id != $product->get_id()) {
				$product_original = $this->get_product($product_original_id);
			}
		}

		$saso_eventtickets_is_date_for_all_variants = true;
		if ($is_variation && $product_parent_id > 0) {
			$saso_eventtickets_is_date_for_all_variants = get_post_meta( $product_parent_original->get_id(), 'saso_eventtickets_is_date_for_all_variants', true ) == "yes" ? true : false;
		}

		$this->isProductAllowedByAuthToken([$product->get_id()]);

		if ($this->MAIN->getOptions()->isOptionCheckboxActive('wcTicketScanneCountRetrieveAsConfirmed')) {
			$codeObj = $this->MAIN->getFrontend()->countConfirmedStatus($codeObj, true);
			$metaObj = $codeObj['metaObj'];
		}

		if (!isset($metaObj["wc_ticket"]["_public_ticket_id"])) $metaObj["wc_ticket"]["_public_ticket_id"] = "";
		do_action( $this->MAIN->_do_action_prefix.'trackIPForTicketScannerCheck', array_merge($codeObj, ["_data_code"=>$metaObj["wc_ticket"]["_public_ticket_id"]]) );

		$date_time_format = $this->MAIN->getOptions()->getOptionDateTimeFormat();

		$is_expired = $this->MAIN->getCore()->checkCodeExpired($codeObj);

		$ret['is_expired'] = $is_expired;
		$ret['timezone_id'] = wp_timezone_string();
		$ret['option_displayDateFormat'] = $this->MAIN->getOptions()->getOptionDateFormat();
		$ret['option_displayTimeFormat'] = $this->MAIN->getOptions()->getOptionTimeFormat();
		$ret['option_displayDateTimeFormat'] = $date_time_format;

		$ret['is_paid'] = $this->isPaid($order);
		$ret['allow_redeem_only_paid'] = $this->MAIN->getOptions()->isOptionCheckboxActive('wcTicketAllowRedeemOnlyPaid');
		$ret['order_status'] = $order->get_status();
		$ret = array_merge($ret, $this->rest_helper_tickets_redeemed($codeObj));
		$ret['ticket_heading'] = esc_html($this->MAIN->getAdmin()->getOptionValue("wcTicketHeading"));
		$ret['ticket_title'] = esc_html($product_parent->get_Title());
		$ret['ticket_sub_title'] = "";
		//if ($is_variation && $this->MAIN->getOptions()->isOptionCheckboxActive('wcTicketPDFDisplayVariantName') && count($product->get_attributes()) > 0) {
		if ($is_variation && count($product->get_attributes()) > 0) {
			foreach($product->get_attributes() as $k => $v){
				$ret['ticket_sub_title'] .= $v." ";
			}
			$ret['ticket_sub_title'] = trim($ret['ticket_sub_title']);
		}
		$ret['ticket_location'] = trim(get_post_meta( $product_parent_original->get_id(), 'saso_eventtickets_event_location', true ));
		$ret['ticket_info'] = wp_kses_post(nl2br(trim(get_post_meta( $product_parent_original->get_id(), 'saso_eventtickets_ticket_is_ticket_info', true ))));
		$ret['ticket_location_label'] = wp_kses_post($this->MAIN->getAdmin()->getOptionValue("wcTicketTransLocation"));

		$tmp_product = $product_parent_original;
		if (!$saso_eventtickets_is_date_for_all_variants) $tmp_product = $product_original; // unter Umständen die Variante

		$ret = array_merge($ret, $this->calcDateStringAllowedRedeemFrom($tmp_product->get_id(), $codeObj));

		$ret['ticket_date_as_string'] = $this->displayTicketDateAsString($tmp_product->get_id(), $this->MAIN->getOptions()->getOptionDateFormat(), $this->MAIN->getOptions()->getOptionTimeFormat(), $codeObj);
		$ret['short_desc'] = "";
		if ($this->MAIN->getOptions()->isOptionCheckboxActive('wcTicketDisplayShortDesc')) {
			$ret['short_desc'] = wp_kses_post(trim($product_parent->get_short_description()));
		}
		$ret['cst_label'] = "";
		$ret['cst_billing_address'] = "";
		if (!$this->MAIN->getOptions()->isOptionCheckboxActive('wcTicketDontDisplayCustomer')) {
			$ret['cst_label'] = wp_kses_post($this->MAIN->getAdmin()->getOptionValue("wcTicketTransCustomer"));
			$ret['cst_billing_address'] = wp_kses_post(trim($order->get_formatted_billing_address()));
		}
		$ret['payment_label'] = "";
		$ret['payment_paid_at_label'] = "";
		$ret['payment_paid_at'] = "";
		$ret['payment_completed_at_label'] = "";
		$ret['payment_completed_at'] = "";
		$ret['payment_method'] = "";
		$ret['payment_trx_id'] = "";
		$ret['payment_method_label'] = "";
		$ret['coupon_label'] = "";
		$ret['coupon'] = "";
		if (!$this->MAIN->getOptions()->isOptionCheckboxActive('wcTicketDontDisplayPayment')) {
			$ret['payment_label'] = wp_kses_post(trim($this->MAIN->getAdmin()->getOptionValue("wcTicketTransPaymentDetail")));
			$ret['payment_paid_at_label'] = wp_kses_post($this->MAIN->getAdmin()->getOptionValue("wcTicketTransPaymentDetailPaidAt"));
			$ret['payment_completed_at_label'] = wp_kses_post($this->MAIN->getAdmin()->getOptionValue("wcTicketTransPaymentDetailCompletedAt"));
			$ret['payment_paid_at'] = $order->get_date_paid() != null ? wp_date($date_time_format, strtotime($order->get_date_paid())) : "-";
			$ret['payment_completed_at'] = $order->get_date_completed() != null ? wp_date($date_time_format, strtotime($order->get_date_completed())) : "-";
			$payment_method = $order->get_payment_method_title();
			if (!empty($payment_method)) {
				$ret['payment_method_label'] = wp_kses_post($this->MAIN->getAdmin()->getOptionValue("wcTicketTransPaymentDetailPaidVia"));
				$ret['payment_method'] = esc_html($payment_method);
				$ret['payment_trx_id'] = esc_html($order->get_transaction_id());
			} else {
				$ret['payment_method_label'] = wp_kses_post($this->MAIN->getAdmin()->getOptionValue("wcTicketTransPaymentDetailFreeTicket"));
			}
			$coupons = $order->get_coupon_codes();
			if (count($coupons) > 0) {
				$ret['coupon_label'] = wp_kses_post($this->MAIN->getAdmin()->getOptionValue("wcTicketTransPaymentDetailCouponUsed"));
				$ret['coupon'] = esc_html(implode(", ", $coupons));
			}
		}
		$ret['product'] = [];
		$ret['product']['id'] = $product->get_id();
		$ret['product']['parent_id'] = $product_parent->get_id();
		$ret['product']['id_original'] = $product_original->get_id();
		$ret['product']['parent_id_original'] = $product_parent_original->get_id();
		$ret['product']['name'] = esc_html($product_parent->get_Title());
		$ret['product']['name_variant'] = "";
		//if ($is_variation && $this->MAIN->getOptions()->isOptionCheckboxActive('wcTicketPDFDisplayVariantName') && count($product->get_attributes()) > 0) {
		if ($is_variation && count($product->get_attributes()) > 0) {
			foreach($product->get_attributes() as $k => $v){
				$ret['product']['name_variant'] .= $v." ";
			}
		}
		$ret['product']['sku'] = esc_html($product->get_sku());
		$ret['product']['type'] = esc_html($product->get_type());

		$order_quantity = $order_item->get_quantity();
		$ticket_pos = "";
		if ($order_quantity > 1) {
			// ermittel ticket pos
			$codes = explode(",", $order_item->get_meta('_saso_eventtickets_product_code', true));
			$ticket_pos = $this->ermittelCodePosition($codeObj['code_display'], $codes);
		}
		$label = esc_attr($this->getLabelNamePerTicket($product_parent_original->get_id()));
		$ret['name_per_ticket_label'] = str_replace("{count}", $ticket_pos, $label);

		$ticket_pos = "";
		if ($order_quantity > 1) {
			// ermittel ticket pos
			$codes = explode(",", $order_item->get_meta('_saso_eventtickets_product_code', true));
			$ticket_pos = $this->ermittelCodePosition($codeObj['code_display'], $codes);
		}
		$label = esc_attr($this->getLabelValuePerTicket($product_parent_original->get_id()));
		$ret['value_per_ticket_label'] = str_replace("{count}", $ticket_pos, $label);

		$ticket_pos = "";
		if ($order_quantity > 1) {
			// ermittel ticket pos
			$codes = explode(",", $order_item->get_meta('_saso_eventtickets_product_code', true));
			$ticket_pos = $this->ermittelCodePosition($codeObj['code_display'], $codes);
		}
		$label = esc_attr($this->getLabelDaychooserPerTicket($product_parent_original->get_id()));
		$ret['day_per_ticket_label'] = str_replace("{count}", $ticket_pos, $label);

		// Seat information
		$ret['seat_label'] = !empty($metaObj['wc_ticket']['seat_label']) ? esc_html($metaObj['wc_ticket']['seat_label']) : '';
		$ret['seat_category'] = !empty($metaObj['wc_ticket']['seat_category']) ? esc_html($metaObj['wc_ticket']['seat_category']) : '';
		$ret['seat_id'] = !empty($metaObj['wc_ticket']['seat_id']) ? intval($metaObj['wc_ticket']['seat_id']) : 0;
		$ret['seat_desc'] = '';
		$ret['seating_plan_id'] = 0;
		$ret['seating_plan_name'] = '';
		$ret['seat_label_text'] = '';
		if ($ret['seat_id'] > 0) {
			$ret['seat_label_text'] = esc_html($this->MAIN->getOptions()->getOptionValue('wcTicketTransSeat'));
			if (empty($ret['seat_label_text'])) {
				$ret['seat_label_text'] = __('Seat', 'event-tickets-with-ticket-scanner');
			}
			// Load seat description if option active
			if ($this->MAIN->getOptions()->isOptionCheckboxActive('seatingShowDescInScanner')) {
				$seat = $this->MAIN->getSeating()->getSeatManager()->getById($ret['seat_id']);
				if ($seat && !empty($seat['meta'])) {
					$seatMeta = is_array($seat['meta']) ? $seat['meta'] : json_decode($seat['meta'], true);
					$ret['seat_desc'] = esc_html($seatMeta['seat_desc'] ?? '');
				}
			}
			// Only load plan info if not hidden
			if (!$this->MAIN->getOptions()->isOptionCheckboxActive('seatingHidePlanNameInScanner')) {
				$planId = $this->MAIN->getSeating()->getSeatManager()->getSeatingPlanIdForSeatId($ret['seat_id']);
				if ($planId) {
					$ret['seating_plan_id'] = intval($planId);
					$plan = $this->MAIN->getSeating()->getPlanManager()->getById($planId);
					if ($plan) {
						$ret['seating_plan_name'] = esc_html($plan['name']);
					}
				}
			}
			// Load seating plan data for buttons
			$showVenueOption = $this->MAIN->getOptions()->isOptionCheckboxActive('ticketScannerShowVenueImage');
			$showSeatingPlanOption = $this->MAIN->getOptions()->isOptionCheckboxActive('ticketScannerShowSeatingPlan');
			if ($showVenueOption || $showSeatingPlanOption) {
				$planId = $ret['seating_plan_id'] > 0 ? $ret['seating_plan_id'] : $this->MAIN->getSeating()->getSeatManager()->getSeatingPlanIdForSeatId($ret['seat_id']);
				if ($planId) {
					$plan = $this->MAIN->getSeating()->getPlanManager()->getById($planId);
					if ($plan) {
						$planMeta = is_array($plan['meta']) ? $plan['meta'] : json_decode($plan['meta'], true);
						$ret['seating_plan_layout_type'] = esc_html($plan['layout_type'] ?? 'simple');
						$ret['seating_plan_description'] = esc_html($planMeta['description'] ?? '');

						// Venue image - available for ALL plan types
						$imageId = intval($planMeta['image_id'] ?? 0);
						$ret['seating_plan_image_url'] = '';
						if ($imageId > 0) {
							$ret['seating_plan_image_url'] = wp_get_attachment_url($imageId);
						}
						// Show venue image button if image exists AND option is active
						$ret['seating_plan_show_venue_button'] = $showVenueOption && !empty($ret['seating_plan_image_url']);

						// For visual plans: show button, data loaded on demand via REST endpoint
						$ret['seating_plan_show_visual_button'] = ($plan['layout_type'] === 'visual' && $showSeatingPlanOption);
					}
				}
			}
		}

		$ret['ticket_amount_label'] = "";
		if ($this->MAIN->getOptions()->isOptionCheckboxActive('wcTicketDisplayPurchasedTicketQuantity')) {
			$text_ticket_amount = wp_kses_post($this->MAIN->getOptions()->getOptionValue('wcTicketPrefixTextTicketQuantity'));
			//$order_quantity = $order_item->get_quantity();
			$ticket_pos = 1;
			if ($order_quantity > 1) {
				// ermittel ticket pos
				$codes = explode(",", $order_item->get_meta('_saso_eventtickets_product_code', true));
				$ticket_pos = $this->ermittelCodePosition($codeObj['code_display'], $codes);
			}
			$text_ticket_amount = str_replace("{TICKET_POSITION}", $ticket_pos, $text_ticket_amount);
			$text_ticket_amount = str_replace("{TICKET_TOTAL_AMOUNT}", $order_quantity, $text_ticket_amount);
			$ret['ticket_amount_label'] = $text_ticket_amount;
		}
		$ret['ticket_label'] = wp_kses_post($this->MAIN->getAdmin()->getOptionValue("wcTicketTransTicket"));
		$paid_price = $order_item->get_subtotal() / $order_item->get_quantity();
		$ret['paid_price_label'] = wp_kses_post($this->MAIN->getAdmin()->getOptionValue("wcTicketTransPrice"));
		$ret['paid_price'] = floatval($paid_price);
		$ret['paid_price_as_string'] = function_exists("wc_price") ? wc_price($paid_price, ['decimals'=>2]) : $paid_price;
		$product_price = $product_original->get_price();
		$ret['product_price_label'] = wp_kses_post($this->MAIN->getAdmin()->getOptionValue("wcTicketTransProductPrice"));
		$ret['product_price'] = floatval($product_price);
		$ret['product_price_as_string'] = function_exists("wc_price") ? wc_price($product_price, ['decimals'=>2]) : $product_price;

		$ret['msg_redeemed'] = wp_kses_post($this->MAIN->getAdmin()->getOptionValue("wcTicketTransTicketRedeemed"));
		$ret['redeemed_date_label'] = wp_kses_post($this->MAIN->getAdmin()->getOptionValue("wcTicketTransRedeemDate"));
		$ret['msg_ticket_valid'] = wp_kses_post($this->MAIN->getAdmin()->getOptionValue("wcTicketTransTicketValid"));
		$ret['msg_ticket_expired'] = wp_kses_post($this->MAIN->getAdmin()->getOptionValue("wcTicketTransTicketExpired"));

		$ret['msg_ticket_not_valid_yet'] = wp_kses_post($this->MAIN->getAdmin()->getOptionValue("wcTicketTransTicketNotValidToEarly"));
		$ret['msg_ticket_not_valid_anymore'] = wp_kses_post($this->MAIN->getAdmin()->getOptionValue("wcTicketTransTicketNotValidToLate"));
		$ret['msg_ticket_event_ended'] = wp_kses_post($this->MAIN->getAdmin()->getOptionValue("wcTicketTransTicketNotValidToLateEndEvent"));

		$ret['max_redeem_amount'] = intval(get_post_meta( $product_parent_original->get_id(), 'saso_eventtickets_ticket_max_redeem_amount', true ));
		if ($ret['max_redeem_amount'] < 0) $ret['max_redeem_amount'] = 1;

		$ret['_options'] = [
			"displayConfirmedCounter"=>$this->MAIN->getOptions()->isOptionCheckboxActive('wcTicketScannerDisplayConfirmedCount'),
			"wcTicketDontAllowRedeemTicketBeforeStart"=>$this->MAIN->getOptions()->isOptionCheckboxActive('wcTicketDontAllowRedeemTicketBeforeStart'),
			"wcTicketAllowRedeemTicketAfterEnd"=>$this->MAIN->getOptions()->isOptionCheckboxActive('wcTicketAllowRedeemTicketAfterEnd'),
			"wsticketDenyRedeemAfterstart"=>$this->MAIN->getOptions()->isOptionCheckboxActive('wsticketDenyRedeemAfterstart'),
			"isRedeemOperationTooEarly"=>$this->isRedeemOperationTooEarly($codeObj, $metaObj, $order),
			"isRedeemOperationTooLateEventEnded"=>$this->isRedeemOperationTooLateEventEnded($codeObj, $metaObj, $order),
			"isRedeemOperationTooLate"=>$this->isRedeemOperationTooLate($codeObj, $metaObj, $order)
		];

		$ret['_server'] = $this->getTimes();

		$codeObj["_ret"] = $ret;
		$codeObj["metaObj"] = $metaObj;

		$codeObj = apply_filters( $this->MAIN->_add_filter_prefix.'ticket_retrieve_ticket', $codeObj, $code );

		return $codeObj;
	}
	function getTimes() {
		$timezone_utc = new DateTimeZone("UTC");
		$dt = new DateTime('now', $timezone_utc);
		return [
			"time"=>wp_date("Y-m-d H:i:s"),
			"timestamp"=>time(),
			"UTC_time"=>$dt->format("Y-m-d H:i:s"),
			"timezone"=>wp_timezone()
		];
	}
	function rest_redeem_ticket(WP_REST_Request $web_request) {
		if (!SASO_EVENTTICKETS::issetRPara('code')) wp_send_json_error(esc_html__("code missing", 'event-tickets-with-ticket-scanner'));
		$ret = null;
		if ($this->is_ticket_code_orderticket(SASO_EVENTTICKETS::getRequestPara('code'))) {
			$ret = $this->redeem_order_ticket(SASO_EVENTTICKETS::getRequestPara('code'));
		}
		if ($ret == null) {
			$ret = $this->redeem_ticket(SASO_EVENTTICKETS::getRequestPara('code'));
		}
		$ret = apply_filters( $this->MAIN->_add_filter_prefix.'ticket_rest_redeem_ticket', $ret, $web_request );
		return $ret;
	}

	/**
	 * REST endpoint to load seating plan data (lazy loading for ticket scanner)
	 *
	 * @param WP_REST_Request $web_request Request with plan_id and optional seat_id
	 * @return array Seating plan data for rendering
	 */
	function rest_seating_plan(WP_REST_Request $web_request): array {
		$planId = intval(SASO_EVENTTICKETS::getRequestPara('plan_id'));
		$seatIdToHighlight = intval(SASO_EVENTTICKETS::getRequestPara('seat_id'));

		if ($planId <= 0) {
			throw new Exception(esc_html__("Plan ID missing", 'event-tickets-with-ticket-scanner'));
		}

		$plan = $this->MAIN->getSeating()->getPlanManager()->getById($planId);
		if (!$plan) {
			throw new Exception(esc_html__("Seating plan not found", 'event-tickets-with-ticket-scanner'));
		}

		$planMeta = is_array($plan['meta']) ? $plan['meta'] : json_decode($plan['meta'], true);

		// Get published meta (same as frontend does)
		$designerMeta = !empty($plan['meta_published'])
			? (is_array($plan['meta_published']) ? $plan['meta_published'] : json_decode($plan['meta_published'], true))
			: [];

		// Merge plan meta with designer meta
		$fullMeta = array_replace_recursive(
			$this->MAIN->getSeating()->getPlanManager()->getMetaObject(),
			is_array($planMeta) ? $planMeta : [],
			is_array($designerMeta) ? $designerMeta : []
		);

		// Venue image
		$imageId = intval($planMeta['image_id'] ?? 0);
		$planImageUrl = $imageId > 0 ? wp_get_attachment_url($imageId) : '';

		$ret = [
			'planId' => intval($plan['id']),
			'planName' => $plan['name'] ?? '',
			'layoutType' => $plan['layout_type'],
			'planImage' => $planImageUrl,
			'currentSeatId' => $seatIdToHighlight,
			'meta' => [
				'canvas_width' => intval($fullMeta['canvas_width'] ?? 800),
				'canvas_height' => intval($fullMeta['canvas_height'] ?? 600),
				'background_color' => $fullMeta['background_color'] ?? '#ffffff',
				'background_image' => $fullMeta['background_image'] ?? '',
				'decorations' => $fullMeta['decorations'] ?? [],
				'lines' => $fullMeta['lines'] ?? [],
				'labels' => $fullMeta['labels'] ?? [],
			],
			'seats' => []
		];

		// Get all seats with their full meta
		$allSeats = $this->MAIN->getSeating()->getSeatManager()->getByPlanId($planId, true);
		foreach ($allSeats as $s) {
			$sMeta = is_array($s['meta']) ? $s['meta'] : json_decode($s['meta'], true);
			$ret['seats'][] = [
				'id' => intval($s['id']),
				'seat_identifier' => $s['seat_identifier'],
				'meta' => $sMeta,
				'is_current' => intval($s['id']) === $seatIdToHighlight
			];
		}

		return $ret;
	}

	private function redeem_order_ticket($code) {
		$parts = $this->getParts($code);
		if (!isset($parts["order_id"]) || !isset($parts["code"])) throw new Exception("#296 - wrong order ticket id");
		if (empty($parts["order_id"]) || empty($parts["code"])) throw new Exception("#295 - wrong order ticket id");

		$order_id = intval($parts["order_id"]);
		$order = wc_get_order($order_id);
		if ($order == null) return "Wrong ticket code id for redeem order ticket";
		$idcode = $order->get_meta('_saso_eventtickets_order_idcode');
		if (empty($idcode) || $idcode != $parts["code"]) return "Wrong ticket code for redeem order ticket";

		$products = $this->MAIN->getWC()->getTicketsFromOrder($order);
		$ret = ["is_order_ticket"=>true, "errors"=>[], "not_redeemed"=>[], "redeemed"=>[], "products"=>[]];
		foreach($products as $obj) { // one ticket can have multiple
			$codes = [];
			if (!empty($obj['codes'])) {
				$codes = explode(",", $obj['codes']);
				$ret["products"][] = $obj;
			}
			foreach($codes as $code) {
				$public_ticket_id = "";
				try {
					$this->parts = null; // clear cache
					$codeObj = $this->MAIN->getCore()->retrieveCodeByCode($code);
					$metaObj = $this->MAIN->getCore()->encodeMetaValuesAndFillObject($codeObj['meta'], $codeObj);
					$codeObj["metaObj"] = $metaObj;
					$public_ticket_id = $metaObj["wc_ticket"]["_public_ticket_id"];
					$r = $this->redeem_ticket("", $codeObj);
					$r["code"] = $code;
					if ($this->redeem_successfully) {
						$ret["redeemed"][] = $r;
					} else {
						$ret["not_redeemed"] = $r; // is not implemented yet - all not redeem operation are exceptions
					}
				} catch(Exception $e) {
					$ret["errors"][] = ["error"=>$e->getMessage(), "code"=>$code, "ticket_id"=>$public_ticket_id];
				}
			}
		}
		$ret = apply_filters( $this->MAIN->_add_filter_prefix.'ticket_redeem_order_ticket', $ret, $code );
		do_action( $this->MAIN->_do_action_prefix.'ticket_redeem_order_ticket', $code, $ret );
		return $ret;
	}
	private function redeem_ticket($code, $codeObj=null) {
		if ($codeObj == null) {
			$codeObj = $this->getCodeObj(true, $code);
		}
		$metaObj = $codeObj['metaObj'];

		$order = $this->getOrderById($codeObj["order_id"]);
		$order_item = $this->getOrderItem($order, $metaObj);
		if ($order_item == null) return wp_send_json_error("#302 ".__("Order item not found", 'event-tickets-with-ticket-scanner'));
		$product = $order_item->get_product();
		if ($product == null) return wp_send_json_error("#303 ".esc_html__("product of the order and ticket not found!", 'event-tickets-with-ticket-scanner'));

		$this->isProductAllowedByAuthToken([$product->get_id()]);

		$this->redeemTicket($codeObj);
		$ticket_id = $this->MAIN->getCore()->getTicketId($codeObj, $metaObj);

		$ret = ['redeem_successfully'=>$this->redeem_successfully, 'ticket_id'=>$ticket_id];
		$ret["_ret"] = $this->rest_helper_tickets_redeemed($codeObj);

		$ret = apply_filters( $this->MAIN->_add_filter_prefix.'ticket_redeem_ticket', $ret, $code, $codeObj );

		return $ret;
	}

	public function getCalcDateStringAllowedRedeemFromCorrectProduct($product_id, $codeObj = null) {
		$product = $this->get_product( $product_id );
		if ($product == null) {
			return wp_send_json_error(esc_html__("#232 original product of the order and ticket not found!", 'event-tickets-with-ticket-scanner'));
		}
		$is_variation = false;
		try {
			$is_variation = $product->get_type() == "variation";
		} catch (Exception $e) {
			$this->MAIN->getAdmin()->logErrorToDB($e);
		}
		$tmp_prod = $product;
		if ($is_variation) {
			$product_parent_id = $product->get_parent_id();
			$product_parent_id_orig = $this->getWPMLProductId($product_parent_id);
			if ($product_parent_id_orig > 0) {
				$product_parent = $this->get_product( $product_parent_id_orig );
				$saso_eventtickets_is_date_for_all_variants = get_post_meta( $product_parent->get_id(), 'saso_eventtickets_is_date_for_all_variants', true ) == "yes";
				if ($saso_eventtickets_is_date_for_all_variants) {
					$tmp_prod = $product_parent;
				}
			}
		}
		return $this->calcDateStringAllowedRedeemFrom($tmp_prod->get_id(), $codeObj);
	}
	public function calcDateStringAllowedRedeemFrom($product_id, $codeObj = null) {
		// check if product id is from WPML plugin
		// get the original product id, because the event ticket information is stored in the original product
		$product_id_orig = $this->getWPMLProductId($product_id);

		$ret = [];
		$ret['is_daychooser'] = get_post_meta( $product_id_orig, 'saso_eventtickets_is_daychooser', true ) == "yes" ? true : false;
		$ret['is_daychooser_value_set'] = false;
		$ret['is_date_for_all_variants'] = get_post_meta( $product_id_orig, 'saso_eventtickets_is_date_for_all_variants', true ) == "yes" ? true : false;
		$ret['is_date_set'] = true;
		$ret['is_end_date_set'] = true;
		$ret['is_end_time_set'] = false;

		$ret['ticket_start_date'] = trim(get_post_meta( $product_id_orig, 'saso_eventtickets_ticket_start_date', true ));
		$ret['ticket_start_time'] = trim(get_post_meta( $product_id_orig, 'saso_eventtickets_ticket_start_time', true ));
		$ret['is_start_time_set'] = !empty($ret['ticket_start_time']) ? true : false;
		$ret['ticket_end_date'] = trim(get_post_meta( $product_id_orig, 'saso_eventtickets_ticket_end_date', true ));
		$ret['ticket_end_date_orig'] = $ret['ticket_end_date'];
		$ret['ticket_end_time'] = trim(get_post_meta( $product_id_orig, 'saso_eventtickets_ticket_end_time', true ));

		$ret['daychooser_offset_start'] = intval(get_post_meta( $product_id_orig, 'saso_eventtickets_daychooser_offset_start', true ));
		$ret['daychooser_offset_end'] = intval(get_post_meta( $product_id_orig, 'saso_eventtickets_daychooser_offset_end', true ));
		$ret['daychooser_exclude_wdays'] = get_post_meta( $product_id_orig, 'saso_eventtickets_daychooser_exclude_wdays', true );
		if ($ret['daychooser_exclude_wdays'] == "") $ret['daychooser_exclude_wdays'] = [];

		$ret['daychooser_exclude_dates'] = [];
		if ($this->MAIN->isPremium() && method_exists($this->MAIN->getPremiumFunctions(), 'getDayChooserExclusionDates')) {
			$ret['daychooser_exclude_dates'] = $this->MAIN->getPremiumFunctions()->getDayChooserExclusionDates($product_id_orig);
			if (!is_array($ret['daychooser_exclude_dates'])) $ret['daychooser_exclude_dates'] = [];
		}

		if ($codeObj != null && $ret['is_daychooser']) {
			// use date of the codeObj
			$codeObj = $this->MAIN->getCore()->setMetaObj($codeObj);
			$metaObj = $codeObj['metaObj'];
			$is_daychooser = intval($metaObj["wc_ticket"]["is_daychooser"]);
			$day_per_ticket = $metaObj["wc_ticket"]["day_per_ticket"];
			if ($is_daychooser == 1 && !empty($day_per_ticket)) {
				if (empty($ret['ticket_start_time'])) {
					$ret['ticket_start_time'] = "00:00:00";
				}
				$ret['ticket_start_date'] = $day_per_ticket;
				$ret['ticket_end_date'] = $day_per_ticket;
				$ret['is_daychooser_value_set'] = true;
			}
		}

		if (empty($ret['ticket_start_date']) && empty($ret['ticket_start_time'])) { // date not set
			$ret['is_date_set'] = false; // indicates that the ticket start date is not set, and the values are calculated
		}
		if (empty($ret['ticket_start_date'])) {
			$ret['ticket_start_date'] = wp_date("Y-m-d");
		}
		$ret['ticket_start_date_timestamp'] = strtotime(trim($ret['ticket_start_date']." ".$ret['ticket_start_time']));
		$ret['ticket_start_p_date'] = wp_date("d", $ret['ticket_start_date_timestamp']);
		$ret['ticket_start_p_month'] = wp_date("m", $ret['ticket_start_date_timestamp']);
		$ret['ticket_start_p_year'] = wp_date("Y", $ret['ticket_start_date_timestamp']);
		$ret['ticket_start_p_hour'] = wp_date("H", $ret['ticket_start_date_timestamp']);
		$ret['ticket_start_p_min'] = wp_date("i", $ret['ticket_start_date_timestamp']);
		$ret['ticket_start_p_sec'] = wp_date("s", $ret['ticket_start_date_timestamp']);

		if (empty($ret['ticket_end_date'])) {
			$ret['ticket_end_date'] = $ret['ticket_start_date'];
			$ret['is_end_date_set'] = false;
		}

		if (empty($ret['ticket_end_time'])) {
			$ret['ticket_end_time'] = "23:59:59";
		} else {
			$ret['is_end_time_set'] = true;
		}
		$ret['ticket_end_date_timestamp'] = strtotime(trim($ret['ticket_end_date']." ".$ret['ticket_end_time']));
		$ret['ticket_end_p_date'] = wp_date("d", $ret['ticket_end_date_timestamp']);
		$ret['ticket_end_p_month'] = wp_date("m", $ret['ticket_end_date_timestamp']);
		$ret['ticket_end_p_year'] = wp_date("Y", $ret['ticket_end_date_timestamp']);
		$ret['ticket_end_p_hour'] = wp_date("H", $ret['ticket_end_date_timestamp']);
		$ret['ticket_end_p_min'] = wp_date("i", $ret['ticket_end_date_timestamp']);
		$ret['ticket_end_p_sec'] = wp_date("s", $ret['ticket_end_date_timestamp']);

		$redeem_allowed_from = time();
		if ($this->MAIN->getOptions()->isOptionCheckboxActive('wcTicketDontAllowRedeemTicketBeforeStart')) {
			$time_offset = intval($this->MAIN->getAdmin()->getOptionValue("wcTicketOffsetAllowRedeemTicketBeforeStart"));
			if ($time_offset < 0) $time_offset = 0;
			//$offset  = (float) get_option( 'gmt_offset' ); // timezone offset
			//$redeem_allowed_from = $ret['ticket_start_date_timestamp'] - ($time_offset * 3600) - ($offset * 3600);
			//if ($offset > 0)  $redeem_allowed_from -= ($offset * 3600);
			//else $redeem_allowed_from += ($offset * 3600);
			$redeem_allowed_from = $ret['ticket_start_date_timestamp'] - ($time_offset * 3600);
		}
		$ret['redeem_allowed_from'] = wp_date("Y-m-d H:i", $redeem_allowed_from);
		$ret['redeem_allowed_from_timestamp'] = $redeem_allowed_from;
		$ret['redeem_allowed_until'] = wp_date("Y-m-d H:i:s", $ret['ticket_end_date_timestamp']);
		$ret['redeem_allowed_until_timestamp'] = $ret['ticket_end_date_timestamp'];
		$ret['server_time_timestamp'] = time(); // real Unix timestamp
		$ret['redeem_allowed_too_late'] = $ret['ticket_end_date_timestamp'] < $ret['server_time_timestamp'];
		$ret['server_time'] = wp_date("Y-m-d H:i:s"); // formatted with timezone
		$ret = apply_filters( $this->MAIN->_add_filter_prefix.'ticket_calcDateStringAllowedRedeemFrom', $ret, $product_id, $codeObj, $product_id_orig );
		return $ret;
	}

	public function getLabelNamePerTicket($product_id) {
		$product_id_orig = $this->getWPMLProductId($product_id);
		$t = trim(get_post_meta($product_id_orig, "saso_eventtickets_request_name_per_ticket_label", true));
        if (empty($t)) $t = "Name for the ticket #{count}:";
		return $t;
	}
	public function getLabelValuePerTicket($product_id) {
		$product_id_orig = $this->getWPMLProductId($product_id);
		$t = trim(get_post_meta($product_id_orig, "saso_eventtickets_request_value_per_ticket_label", true));
        if (empty($t)) $t = "Please choose a value #{count}:";
		return $t;
	}
	public function getLabelDaychooserPerTicket($product_id) {
		$product_id_orig = $this->getWPMLProductId($product_id);
		$t = trim(get_post_meta($product_id_orig, "saso_eventtickets_request_daychooser_per_ticket_label", true));
		if (empty($t)) $t = "Please choose a day #{count}:";
		return $t;
	}

	/**
	 * has to be explicitly called
	 */
	public function initFilterAndActions() {
		add_filter('query_vars', function( $query_vars ){
		    $query_vars[] = 'symbol';
		    return $query_vars;
		});
		add_filter("pre_get_document_title", function($title){
			return __("Ticket Info", "event-tickets-with-ticket-scanner");
		}, 2000);
		add_action('wp_head', function() {
			include_once plugin_dir_path(__FILE__)."sasoEventtickets_Ticket.php";
			$sasoEventtickets_Ticket = sasoEventtickets_Ticket::Instance($_SERVER["REQUEST_URI"]);
			$sasoEventtickets_Ticket->addMetaTags();
		}, 1);
		add_action('template_redirect', function() {
			include_once plugin_dir_path(__FILE__)."sasoEventtickets_Ticket.php";
			$sasoEventtickets_Ticket = sasoEventtickets_Ticket::Instance($_SERVER["REQUEST_URI"]);
			$sasoEventtickets_Ticket->output();
			exit;
		}, 300);
		do_action( $this->MAIN->_do_action_prefix.'ticket_initFilterAndActions' );
	}

	public function initFilterAndActionsTicketScanner() {
		add_filter('query_vars', function( $query_vars ){
		    $query_vars[] = 'symbol';
		    return $query_vars;
		});
		add_filter("pre_get_document_title", function($title){
			return __("Ticket Info", "event-tickets-with-ticket-scanner");
		}, 2000);
		add_action('template_redirect', function() {
			include_once plugin_dir_path(__FILE__)."sasoEventtickets_Ticket.php";
			$sasoEventtickets_Ticket = sasoEventtickets_Ticket::Instance($_SERVER["REQUEST_URI"]);
			$sasoEventtickets_Ticket->outputTicketScannerStandalone();
			exit;
		}, 100);
		do_action( $this->MAIN->_do_action_prefix.'ticket_initFilterAndActionsTicketScanner' );
	}

	/** falls man direkt aufrufen muss. Wie beim /ticket/scanner/ */
	public function renderPage() {
		include_once plugin_dir_path(__FILE__)."sasoEventtickets_Ticket.php";
		$vollstart_Ticket = sasoEventtickets_Ticket::Instance($_SERVER["REQUEST_URI"]);
		$vollstart_Ticket->output();
	}

	public function isScanner() {
		// /wp-content/plugins/event-tickets-with-ticket-scanner/ticket/scanner/
		if ($this->isScanner == null) {
			if ($this->isOnlyLoggedInScannerAllowed()) {
				if (!in_array('administrator',  wp_get_current_user()->roles)) {
					return false;
				}
			}

			$ret = false;
			$teile = explode("/", $this->request_uri);
			$teile = array_reverse($teile);
			if (count($teile) > 1) {
				if (substr(strtolower(trim($teile[1])), 0, 7) == "scanner") $ret = true;
			}
			$this->isScanner = $ret;
		}
		return $this->isScanner;
	}

	public function setOrder($order) {
		$this->order = $order;
	}

	private function getOrderById($order_id) {
		if (isset($this->orders_cache[$order_id])) {
			return $this->orders_cache[$order_id];
		}
		$order = null;
		if (function_exists("wc_get_order")) {
			$order = wc_get_order( $order_id );
			if (!$order) throw new Exception("#8009 Order not found by order id");
		}
		if (!isset($this->orders_cache[$order_id])) { // store also null, to prevent rechecks of this order_id
			$this->orders_cache[$order_id] = $order;
		}
		return $order;
	}

	private function getOrder() {
		if ($this->order != null) return $this->order;

		$codeObj = $this->getCodeObj();
		if (intval($codeObj['order_id']) == 0) throw new Exception("#8010 Order not available");

		$this->order = $this->getOrderById($codeObj['order_id']);
		return $this->order;
	}

	public function get_product($product_id) {
		$product = null;
		if (function_exists("wc_get_product")) {
			$product = wc_get_product( $product_id );
		}
		return $product;
	}

	public function get_is_paid_statuses() {
		$def = ['processing', 'completed'];
		if (function_exists("wc_get_is_paid_statuses")) {
			$def = wc_get_is_paid_statuses();
		}
		$def = apply_filters( $this->MAIN->_add_filter_prefix.'ticket_get_is_paid_statuses', $def );
		return $def;
	}

	private function getParts($code="") {
		if ($this->parts == null) {
			if ($this->isScanner()) {
				if (!SASO_EVENTTICKETS::issetRPara('code')) {
					throw new Exception("#8007 ticket number parameter missing");
				} else {
					if (empty($code)) {
						$code = SASO_EVENTTICKETS::getRequestPara('code', $def='');
					}
					$uri = trim($code);
					$this->parts =  $this->MAIN->getCore()->getTicketURLComponents($uri);
				}
			} else {
				$this->parts =  $this->MAIN->getCore()->getTicketURLComponents($this->request_uri);
			}
		}
		return $this->parts;
	}

	public function generateICSFile($product, $codeObj = null) {
		$product_id = $product->get_id();
		$titel = $product->get_name();
		$short_desc = "";

		$product_parent_id = $product->get_parent_id();
		$product_parent = $product;
		if ($product_parent_id > 0) {
			$product_parent = $this->get_product( $product_parent_id );
		}

		$product_original = $product;
		$product_parent_original = $product_parent;

		$product_original_id = $this->getWPMLProductId($product->get_id());
		if ($product_original_id != $product->get_id()) {
			$product_original = $this->get_product($product_original_id);
		}
		if ($product_parent_id > 0) {
			$product_parent_original_id = $this->getWPMLProductId($product_parent_id);
			if ($product_parent_original_id != $product_parent_id) {
				$product_parent_original = $this->get_product($product_parent_original_id);
			}
		}

		if ($this->MAIN->getOptions()->isOptionCheckboxActive('wcTicketDisplayShortDesc')) {
			$short_desc .= trim($product_parent->get_short_description());
		}

		$tzid = wp_timezone_string();
		//$tzid_text = empty($tzid) ? '' : ';TZID="'.wp_timezone_string().'":';

		$ticket_info = wp_kses_post(nl2br(trim(get_post_meta( $product_original->get_id(), 'saso_eventtickets_ticket_is_ticket_info', true ))));
		if (!empty($short_desc) && !empty($ticket_info)) $short_desc .= "\n\n";
		$short_desc .= trim($ticket_info);

		$ticket_times = $this->getCalcDateStringAllowedRedeemFromCorrectProduct($product_id, $codeObj);
		$ticket_start_date = $ticket_times['ticket_start_date'];
		$ticket_start_time = $ticket_times['ticket_start_time'];
		$ticket_end_date = $ticket_times['ticket_end_date'];
		$ticket_end_time = $ticket_times['ticket_end_time'];

		if (empty($ticket_start_date) && !empty($ticket_start_time)) {
			$ticket_start_date = wp_date("Y-m-d");
		}
		if (empty($ticket_start_date)) throw new Exception("#8011 ".esc_html__("No date available", 'event-tickets-with-ticket-scanner'));

		if (empty($ticket_end_date) && !empty($ticket_end_time)) {
			$ticket_end_date = $ticket_start_date;
		}
		if (empty($ticket_end_time)) $ticket_end_time = "23:59:59";

		$start_timestamp = strtotime(trim($ticket_start_date." ".$ticket_start_time));
		$end_timestamp = strtotime(trim($ticket_end_date." ".$ticket_end_time));

		$DTSTART_line = "DTSTART";
		$DTEND_line = "";
		if (empty($ticket_start_time)) {
			// Use date() - ticket dates are stored in local time, using wp_date() would double-convert
			$DTSTART_line .= ";VALUE=DATE:".date("Ymd", $start_timestamp);
			if (!empty($ticket_end_date)) {
				$DTEND_line .= ";VALUE=DATE:".date("Ymd", strtotime(trim($ticket_start_date)));
			}
		} else {
			$DTEND_line = "DTEND";
			// using utc to leave out the tzid
			//if (!empty($tzid)) {
			//	$DTSTART_line .= ";TZID=".$tzid;
			//	$DTEND_line .= ";TZID=".$tzid;
			//}
			// Use date() - ticket dates are stored in local time, using wp_date() would double-convert
			$DTSTART_line .= ":".date("Ymd\THis", $start_timestamp);
			$DTEND_line .= ":".date("Ymd\THis", $end_timestamp);
		}

		$LOCATION = trim(get_post_meta( $product_parent_original->get_id(), 'saso_eventtickets_event_location', true ));

		$temp = wp_kses_post(str_replace(array("\r\n", "<br>"),"\n",$short_desc));
		$lines = explode("\n",$temp);
		$new_lines =array();
		foreach($lines as $i => $line) {
			if(!empty($line))
			$new_lines[]=trim($line);
		}
		$desc = implode("\r\n ",$new_lines);

		$event_url = get_permalink( $product->get_id() );
		$uid = $product_id."-".wp_date("Y-m-d-H-i-s")."-".get_site_url();

		$wcTicketICSOrganizerEmail = trim($this->MAIN->getOptions()->getOptionValue("wcTicketICSOrganizerEmail"));

		$ret = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//hacksw/handcal//NONSGML v1.0//EN\r\nBEGIN:VEVENT\r\n";
		$ret .= "UID:".$uid."\r\n";
		if ($wcTicketICSOrganizerEmail != "") {
			$ret .= "ORGANIZER;CN=".trim(wp_kses_post(str_replace(":", " ", get_bloginfo('name')))).":mailto:".$wcTicketICSOrganizerEmail."\r\n";
		}
		$ret .= "LOCATION:".htmlentities($LOCATION)."\r\n";
		//$ret .= "DTSTAMP:".gmdate("Ymd\THis")."\r\n";
		$ret .= "DTSTAMP:".wp_date("Ymd\THis")."\r\n";
		$ret .= $DTSTART_line."\r\n";
		if (!empty($DTEND_line)) $ret .= $DTEND_line."\r\n";
		$ret .= "SUMMARY:".$titel."\r\n";
		$ret .= "DESCRIPTION:".$desc."\r\n ".$event_url."\r\n";
		$ret .= "X-ALT-DESC;FMTTYPE=text/html:".$desc."<br>".$event_url."\r\n";
		$ret .= "URL:".trim($event_url)."\r\n";
		$ret .= "END:VEVENT\r\n";
		$ret .= "END:VCALENDAR";
		return $ret;
	}

	public function setCodeObj($codeObj) {
		$this->codeObj = $codeObj;
		$this->order = null;
	}
	private function getCodeObj($dontFailPaid=false, $code="") {
		if ($this->codeObj != null) {
			$this->codeObj = $this->MAIN->getCore()->setMetaObj($this->codeObj);
			return $this->codeObj;
		}
		$codeObj = $this->MAIN->getCore()->retrieveCodeByCode($this->getParts($code)['code']);
		if ($codeObj['aktiv'] == 2) throw new Exception("#8005 ".esc_html($this->MAIN->getAdmin()->getOptionValue("wcTicketTransTicketIsStolen")));
		if ($codeObj['aktiv'] != 1) throw new Exception("#8006 ".esc_html($this->MAIN->getAdmin()->getOptionValue("wcTicketTransTicketNotValid")));
		$metaObj = $this->MAIN->getCore()->encodeMetaValuesAndFillObject($codeObj['meta'], $codeObj);
		$codeObj["metaObj"] = $metaObj;

		// check ob order_id stimmen
		if ($this->getParts($code)['order_id'] != $codeObj['order_id']) throw new Exception("#8001 ".esc_html($this->MAIN->getAdmin()->getOptionValue("wcTicketTransTicketNumberWrong")));
		// check idcode
		if ($this->getParts($code)['idcode'] != $metaObj['wc_ticket']['idcode']) throw new Exception("#8006 ".esc_html($this->MAIN->getAdmin()->getOptionValue("wcTicketTransTicketNumberWrong")));
		// check ob serial ein ticket ist
		if ($metaObj['wc_ticket']['is_ticket'] != 1) throw new Exception("#8002 ".esc_html($this->MAIN->getAdmin()->getOptionValue("wcTicketTransTicketNotValid")));
		// check ob order bezahlt ist
		if ($dontFailPaid == false) {
			$order = $this->getOrderById($codeObj["order_id"]);
			$ok_order_statuses = $this->get_is_paid_statuses();
			if (!$dontFailPaid && !$this->isPaid($order)) throw new Exception("#8003 Ticket payment is not completed. The ticket order status has to be set to a paid status like ".join(" or ", $ok_order_statuses).".");
		}

		$this->codeObj = $codeObj;
		return $codeObj;
	}

	private function isPaid($order) {
		return SASO_EVENTTICKETS::isOrderPaid($order);
	}

	public function getTicketScannerHTMLBoilerplate() {
		$t = '
		<div style="width: 100%; justify-content: center;align-items: center;position: relative;">
			<div class="ticket_content" style="background-color:white;color:black;padding:15px;display:block;position: relative;left: 0;right: 0;margin: auto;text-align:left;border:1px solid black;">
				<div id="ticket_scanner_info_area"></div>
				<div id="ticket_info_retrieved" style="padding-top:20px;padding-bottom:20px;"></div>
				<div id="reader_output"></div>
				<div id="reader" style="width:100%"></div>
				<div id="order_info"></div>
				<div id="ticket_info"></div>
				<div id="ticket_add_info"></div>
				<div id="ticket_info_btns" style="padding-top:20px;padding-bottom:20px;"></div>
				<div id="reader_options" style="width:100%"></div>
			</div>
		</div>
		';
		$t = apply_filters( $this->MAIN->_add_filter_prefix.'ticket_getTicketScannerHTMLBoilerplate', $t );
		return trim($t);
	}

	public function outputTicketScannerStandalone() {
		header('HTTP/1.1 200 OK');
		$this->MAIN->setTicketScannerJS();
		$pwaEnabled = $this->MAIN->getOptions()->isOptionCheckboxActive('ticketScannerPWA');
		$themeColor = $this->MAIN->getOptions()->getOptionValue('ticketScannerThemeColor', '#2e74b5');
		if (empty($themeColor)) $themeColor = '#2e74b5';
		echo '<!DOCTYPE html>';
		echo '<html lang="'.esc_attr(get_locale()).'">';
		echo '<head>';
		echo '<meta charset="UTF-8">';
		echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
		if ($pwaEnabled) {
			echo '<meta name="theme-color" content="'.esc_attr($themeColor).'">';
			echo '<meta name="mobile-web-app-capable" content="yes">';
			echo '<link rel="manifest" href="'.esc_url(rest_url(SASO_EVENTTICKETS::getRESTPrefixURL().'/ticket/scanner/pwa-manifest')).'">';
			echo '<link rel="apple-touch-icon" href="'.esc_url(plugins_url('img/pwa-icon-192.png', __FILE__)).'">';
		}
		$tc = esc_attr($themeColor);
		?>
		<style>
            body {font-family: Helvetica, Arial, sans-serif;}
            h3,h4,h5 {padding-bottom:0.5em;margin-bottom:0;}
            p {padding:0;margin:0;margin-bottom:1em;}
			div.ticket_content p {font-size:initial !important;margin-bottom:1em !important;}
            button {padding:10px;font-size: 1.5em;}
            .lds-dual-ring {display:inline-block;width:64px;height:64px;}
            .lds-dual-ring:after {content:" ";display:block;width:46px;height:46px;margin:1px;border-radius:50%;border:5px solid #fff;border-color:<?php echo $tc; ?> transparent <?php echo $tc; ?> transparent;animation:lds-dual-ring 0.6s linear infinite;}
            @keyframes lds-dual-ring {0% {transform: rotate(0deg);} 100% {transform: rotate(360deg);}}
		</style>
		<?php
		wp_head();
		?>
		</head><body>
		<center>
        <h1>Ticket Scanner</h1>
        <div style="width:90%;max-width:800px;">
		<?php echo $this->getTicketScannerHTMLBoilerplate(); ?>
        </div>
        </center>
		<?php
		//echo determine_locale();
		//load_script_translations(__DIR__.'/languages/event-tickets-with-ticket-scanner-de_CH-ajax_script_ticket_scanner.json', 'ajax_script_ticket_scanner', 'event-tickets-with-ticket-scanner');
		get_footer();
		//wp_footer();
		//echo '</body></html>';
	}

	public function outputTicketScanner() {
		echo '<center>';
		echo '<h3>'.__('Ticket scanner', 'event-tickets-with-ticket-scanner').'</h3>';
		echo '<div id="ticket_scanner_info_area">';
		if (isset($_GET['code']) && isset($_GET['redeemauto']) && $this->redeem_successfully == false) {
			echo '<h3 style="color:red;">'.esc_html__('TICKET NOT REDEEMED - see reason below', 'event-tickets-with-ticket-scanner').'</h3>';
		} else if (isset($_GET['code']) && isset($_GET['redeemauto']) && $this->redeem_successfully) {
			echo '<h3 style="color:green;">'.esc_html__('TICKET OK - Redeemed', 'event-tickets-with-ticket-scanner').'</h3>';
		}
		echo '</div>';

		echo '</center>';
		echo '<div id="reader_output">';
		if (SASO_EVENTTICKETS::issetRPara("code")) {
			try {
				$codeObj = $this->getCodeObj();
				$metaObj = $codeObj["metaObj"];

				$ticket_id = $this->MAIN->getCore()->getTicketId($codeObj, $metaObj);

				$ticket_times = $this->getCalcDateStringAllowedRedeemFromCorrectProduct($metaObj['woocommerce']['product_id'], $codeObj);
				$ticket_end_date = $ticket_times['ticket_end_date'];
				$ticket_end_date_timestamp = $ticket_times['ticket_end_date_timestamp'];
				$color = 'green';
				if ($ticket_end_date != "" && $ticket_end_date_timestamp < time()) {
					$color = 'orange';
				}
				if (!empty($metaObj['wc_ticket']['redeemed_date'])) {
					$color = 'red';
				}

				if (SASO_EVENTTICKETS::issetRPara('action') && SASO_EVENTTICKETS::getRequestPara('action') == "redeem") {
					$pfad = plugins_url( "img/",__FILE__ );
					if ($this->redeem_successfully) {
						echo '<p style="text-align:center;color:green"><img src="'.$pfad.'button_ok.png"><br><b>'.__("Successfully redeemed", 'event-tickets-with-ticket-scanner').'</b></p>';
					} else {
						echo '<p style="text-align:center;color:red;"><img src="'.$pfad.'button_cancel.png"><br><b>'.__("Failed to redeem", 'event-tickets-with-ticket-scanner').'</b></p>';
					}
				}

				echo '<div style="border:5px solid '.esc_attr($color).';margin:10px;padding:10px;">';
				$this->outputTicketInfo();
				echo '</div>';

				echo '<form id="f_reload" action="?" method="get">
				<input type="hidden" name="code" value="'.urlencode($ticket_id).'">
				</form>';
				echo '
					<script>
					function reload_ticket() {
						document.getElementById("f_reload").submit();
					}
					</script>
				';
				if (empty($metaObj['wc_ticket']['redeemed_date'])) {
					echo '<form id="f_redeem" action="?" method="post">
							<input type="hidden" name="action" value="redeem">
							<input type="hidden" name="code" value="'.urlencode($ticket_id).'">
							</form></p></center>';
					echo '
						<script>
						function redeem_ticket() {
							document.getElementById("f_redeem").submit();
						}
						</script>
					';
				}
				echo '<center><p><button onclick="reload_ticket()">'.esc_attr__("Reload Ticket", 'event-tickets-with-ticket-scanner').'</button>';
				if (empty($metaObj['wc_ticket']['redeemed_date'])) {
					echo '<button onclick="redeem_ticket()" style="background-color:green;color:white;">'.__("Redeem Ticket", 'event-tickets-with-ticket-scanner').'</button>';
				}
				echo '</p></center>';
			} catch (Exception $e) {
				echo '</div>';
				echo '<div style="color:red;">'.$e->getMessage().'</div>';
				echo $this->getParts()['code'];
			}
		}
		echo '</div>';
		echo '<center>';
		echo '<div id="reader" style="width:600px"></div>';
		echo '</center>';
		echo '<script>
			var serial_ticket_scanner_redeem = '.(isset($_GET['redeemauto']) ? 'true' : 'false').';
			var loadingticket = false;
			function setRedeemImmediately() {
				serial_ticket_scanner_redeem = !serial_ticket_scanner_redeem;
			}
			function onScanSuccess(decodedText, decodedResult) {
				if (loadingticket) return;
				loadingticket = true;
				// handle the scanned code as you like, for example:
				jQuery("#reader_output").html(decodedText+"<br>...'.__("loading", 'event-tickets-with-ticket-scanner').'...");
				window.location.href = "?code="+encodeURIComponent(decodedText) + (serial_ticket_scanner_redeem ? "&redeemauto=1" : "");
				window.setTimeout(()=>{
					html5QrcodeScanner.stop().then((ignore) => {
						// QR Code scanning is stopped.
						// reload the page with the ticket info and redeem button
						//console.log("stop success");
					}).catch((err) => {
						// Stop failed, handle it.
						//console.log("stop failed");
					});
				}, 250);
		  	}
		  	function onScanFailure(error) {
				// handle scan failure, usually better to ignore and keep scanning.
				// for example:
				console.warn("Code scan error = ${error}");
		  	}
		  	var html5QrcodeScanner = new Html5QrcodeScanner(
				"reader",
				{ fps: 10, qrbox: {width: 250, height: 250} },
				/* verbose= */ false);
		  </script>';
	  	echo '<script>
		  function startScanner() {
				jQuery("#ticket_scanner_info_area").html("");
				jQuery("#reader_output").html("");
			  	html5QrcodeScanner.render(onScanSuccess, onScanFailure);
		  }
		  </script>';

		if (SASO_EVENTTICKETS::issetRPara("code")) {
			echo "<center>";
			echo '<input type="checkbox" onclick="setRedeemImmediately()"'.(SASO_EVENTTICKETS::issetRPara("redeemauto") ? " ".'checked' :'').'> '.esc_html__('Scan and Redeem immediately', 'event-tickets-with-ticket-scanner').'<br>';
			echo '<button onclick="startScanner()">'.esc_attr__("Scan next Ticket", 'event-tickets-with-ticket-scanner').'</button>';
			echo "</center>";

			// display the amount entered already
			$redeemed_tickets = $this->rest_helper_tickets_redeemed($codeObj);
			if ($redeemed_tickets['tickets_redeemed_show']) {
				echo "<center><h5>";
				echo $redeemed_tickets['tickets_redeemed']." ".__('ticket redeemed already', 'event-tickets-with-ticket-scanner');
				echo "</h5></center>";
			}
		} else {
			echo '<script>
			startScanner();
			</script>';
		}
	}

	private function checkIfDownloadIsAllowed() {
		if ($this->MAIN->getOptions()->isOptionCheckboxActive('wcTicketAllowOnlyLoggedinToDownload')) {
			if (!is_user_logged_in()) {
				$url = $this->MAIN->getOptions()->getOptionValue("wcTicketAllowOnlyLoggedinToDownloadRedirectURL");
				if (!empty($url)) {
					wp_redirect( $url );
				} else {
					// send header not allowed
					header("HTTP/1.1 403 Forbidden");
					echo esc_html__("You are not allowed to download the ticket PDF. Please log in to your account.", 'event-tickets-with-ticket-scanner');
					//wp_redirect( home_url() );
				}
				exit;
			}
		}
	}

	private function sendBadgeFile() {
		$codeObj = $this->getCodeObj(true);
		$badgeHandler = $this->MAIN->getTicketBadgeHandler();
		$badgeHandler->downloadPDFTicketBadge($codeObj);
		die();
	}

	private function sendICSFile() {
		$codeObj = $this->getCodeObj(true);
		$metaObj = $codeObj['metaObj'];
		do_action( $this->MAIN->_do_action_prefix.'trackIPForICSDownload', $codeObj );
		$product_id = $metaObj['woocommerce']['product_id'];
		$this->sendICSFileByProductId($product_id, $codeObj);
	}

	public function sendICSFileByProductId($product_id, $codeObj = null) { // null, because it could be called for the product
		$product = $this->get_product( $product_id );
		$contents = $this->generateICSFile($product, $codeObj);
		SASO_EVENTTICKETS::sendeDaten($contents, "ics_".$product_id.".ics", "text/calendar");
	}

	/**
	 * will generate all tickets PDF
	 * then merge them together to one PDF
	 */
	public function outputPDFTicketsForOrder($order, $filemode="I") {
		$tickets = $this->MAIN->getWC()->getTicketsFromOrder($order);
		if (count($tickets) > 0) {
			set_time_limit(0);
			$this->setOrder($order);
			if ($filemode == "I") {
				do_action( $this->MAIN->_do_action_prefix.'trackIPForPDFOneView', $order );
			}
			$filepaths = [];
			foreach($tickets as $key => $obj) {
				$codes = [];
				if (!empty($obj['codes'])) {
					$codes = explode(",", $obj['codes']);
				}
				foreach($codes as $code) {
					try {
						$codeObj = $this->MAIN->getCore()->retrieveCodeByCode($code);
					} catch (Exception $e) {
						continue;
					}
					$this->setCodeObj($codeObj);
					// attach PDF
					$filepaths[] = $this->outputPDF("F");
				}
			}
			$filename = "tickets_".$order->get_id().".pdf";
			// merge files
			$fullFilePath = $this->MAIN->getCore()->mergePDFs($filepaths, $filename, $filemode);
			return $fullFilePath; // if not already exit call was made
		}
	}
	public function generateOnePDFForCodes($codes=[], $filename=null, $filemode="I") {
		try {
			if (count($codes) > 0) {
				set_time_limit(0);
				$filepaths = [];
				foreach($codes as $code) {
					try {
						$codeObj = $this->MAIN->getCore()->retrieveCodeByCode($code);
					} catch (Exception $e) {
						continue;
					}
					$this->setCodeObj($codeObj);
					// attach PDF
					$filepaths[] = $this->outputPDF("F");
				}
				if ($filename == null) {
					$filename = "tickets_".wp_date("Ymd_Hi").".pdf";
				}
				// merge files
				$fullFilePath = $this->MAIN->getCore()->mergePDFs($filepaths, $filename, $filemode);
				return $fullFilePath; // if not already exit call was made
			}
		} catch (Exception $e) {
			$this->MAIN->getAdmin()->logErrorToDB($e);
			throw $e;
		}
	}

	public function generateOneBadgePDFForCodes($codes=[], $filename=null, $filemode="I") {
		// set_time_limit(0); // should be set by the caller already
		try {
			if (count($codes) > 0) {
				$badgeHandler = $this->MAIN->getTicketBadgeHandler();
				$dirname = get_temp_dir(); // pfad zu den dateien
				if (wp_is_writable($dirname)) {
					$dirname .=  trailingslashit($this->MAIN->getPrefix());
					if (!file_exists($dirname)) {
						wp_mkdir_p($dirname);
					}
					set_time_limit(0);
					$filepaths = [];
					foreach($codes as $code) {
						try {
							$codeObj = $this->MAIN->getCore()->retrieveCodeByCode($code);
						} catch (Exception $e) {
							continue;
						}
						$this->setCodeObj($codeObj);
						// attach PDF
						$filepaths[] = $badgeHandler->getPDFTicketBadgeFilepath($codeObj, $dirname);
					}
					if ($filename == null) {
						$filename = "ticketsbadges_".wp_date("Ymd_Hi").".pdf";
					}
					// merge files
					$fullFilePath = $this->MAIN->getCore()->mergePDFs($filepaths, $filename, $filemode);
					return $fullFilePath; // if not already exit call was made
				} else {
					$this->MAIN->getAdmin()->logErrorToDB(new Exception("#8012 cannot create badge pdf - no write access to ".$dirname));
				}
			}
		} catch (Exception $e) {
			$this->MAIN->getAdmin()->logErrorToDB($e);
			throw $e;
		}
	}

	public function outputPDF($filemode="I") {
		$codeObj = $this->getCodeObj(true);
		$metaObj = $codeObj['metaObj'];
		$order = $this->getOrder();
		$ticket_id = $this->MAIN->getCore()->getTicketId($codeObj, $metaObj);
		$order_item = $this->getOrderItem($order, $metaObj);
		if ($order_item == null) throw new Exception("#8013 ".esc_html__("Order item not found for the PDF ticket", 'event-tickets-with-ticket-scanner'));

		if ($filemode == "I") {
			do_action( $this->MAIN->_do_action_prefix.'trackIPForPDFView', $codeObj );
			$this->setOrderStatusAfterViewOperation($order);
		}

		$ticket_template = apply_filters( $this->MAIN->_add_filter_prefix.'ticket_outputTicketInfo_template', null, $codeObj );

		$product = $order_item->get_product();
		if ($product == null) throw new Exception("#8020 ".esc_html__("Product not found for the PDF ticket", 'event-tickets-with-ticket-scanner'));

		$product_id = $product->get_id();
		$product_parent_id = $product->get_parent_id();
		$is_variation = $product->get_type() == "variation" ? true : false;
		if ($is_variation && $product_parent_id > 0) {
			$product_id = $product_parent_id;
		}

		ob_start();
		try {
			$this->outputTicketInfo(true);
			$html = trim(ob_get_contents());
		} catch (Exception $e) {
			$this->MAIN->getAdmin()->logErrorToDB($e);
			$html = $e->getMessage();
		}
		ob_end_clean();
		ob_start();

		$pdf = $this->MAIN->getNewPDFObject();

		// RTL product approach
		$rtl = false;
		if ($ticket_template != null) {
			$rtl = $ticket_template['metaObj']['wcTicketPDFisRTL'] == true || intval($ticket_template['metaObj']['wcTicketPDFisRTL']) == 1;
		} else {
			//if (get_post_meta( $metaObj['woocommerce']['product_id'], 'saso_eventtickets_ticket_is_RTL', true ) == "yes") {
				//$rtl = true;
			//}
			if (SASO_EVENTTICKETS::issetRPara('testDesigner') && $this->MAIN->getOptions()->isOptionCheckboxActive('wcTicketPDFisRTLTest')) {
				$rtl = true;
			} else if($this->MAIN->getOptions()->isOptionCheckboxActive('wcTicketPDFisRTL')) {
				$rtl = true;
			}
		}
		$pdf->setRTL($rtl);

		$pdf->setQRParams(['style'=>['position'=>'C'],'align'=>'N']);
		//$pdf->setQRParams(['style'=>['position'=>'R','vpadding'=>0,'hpadding'=>0], 'align'=>'C']);
		if ($pdf->isRTL()) {
			//$pdf->setQRParams(['style'=>['position'=>'L'], 'align'=>'C']);
			$lg = Array();
			$lg['a_meta_charset'] = 'UTF-8';
			$lg['a_meta_dir'] = 'rtl';
			$lg['a_meta_language'] = 'fa';
			$lg['w_page'] = 'page';
			// set some language-dependent strings (optional)
			$pdf->setLanguageArray($lg);
			$pdf->setQRParams(['style'=>['position'=>'T'],'align'=>'T']);
		}

		$marginZero = false;
		if ($ticket_template != null) {
			$marginZero = $ticket_template['metaObj']['wcTicketPDFZeroMargin'] == true || intval($ticket_template['metaObj']['wcTicketPDFZeroMargin']) == 1;
		} else {
			if (SASO_EVENTTICKETS::issetRPara('testDesigner')) {
				if ($this->MAIN->getOptions()->isOptionCheckboxActive('wcTicketPDFZeroMarginTest')) {
					$marginZero = true;
				}
			} else {
				if ($this->MAIN->getOptions()->isOptionCheckboxActive('wcTicketPDFZeroMargin')) {
					$marginZero = true;
				}
			}
		}
		$pdf->marginsZero = $marginZero;

		// Full bleed mode for edge-to-edge printing
		$fullBleed = false;
		if ($ticket_template != null) {
			$fullBleed = $ticket_template['metaObj']['wcTicketPDFFullBleed'] == true || intval($ticket_template['metaObj']['wcTicketPDFFullBleed']) == 1;
		} else {
			if (SASO_EVENTTICKETS::issetRPara('testDesigner')) {
				if ($this->MAIN->getOptions()->isOptionCheckboxActive('wcTicketPDFFullBleedTest')) {
					$fullBleed = true;
				}
			} else {
				if ($this->MAIN->getOptions()->isOptionCheckboxActive('wcTicketPDFFullBleed')) {
					$fullBleed = true;
				}
			}
		}
		$pdf->fullBleed = $fullBleed;

		$width = 210;
        $height = 297;
		$qr_code_size = 0; // takes default then
		if ($ticket_template != null) {
			$width = intval($ticket_template['metaObj']['wcTicketSizeWidth']);
			$height = intval($ticket_template['metaObj']['wcTicketSizeHeight']);
			$qr_code_size = intval($ticket_template['metaObj']['wcTicketQRSize']);
		} else {
			if (SASO_EVENTTICKETS::issetRPara('testDesigner')) {
				$width = $this->MAIN->getOptions()->getOptionValue("wcTicketSizeWidthTest", 0);
				$height = $this->MAIN->getOptions()->getOptionValue("wcTicketSizeHeightTest", 0);
				$qr_code_size = intval($this->MAIN->getOptions()->getOptionValue("wcTicketQRSizeTest", 0));
			} else {
				$width = $this->MAIN->getOptions()->getOptionValue("wcTicketSizeWidth", 0);
				$height = $this->MAIN->getOptions()->getOptionValue("wcTicketSizeHeight", 0);
				$qr_code_size = intval($this->MAIN->getOptions()->getOptionValue("wcTicketQRSize", 0));
			}
		}

        $width = $width > 0 ? $width : 210;
        $height = $height > 0 ? $height : 297;
		$pdf->setSize($width, $height);

		if ($qr_code_size > 0) {
			$pdf->setQRParams(['size'=>['width'=>$qr_code_size, 'height'=>$qr_code_size]]);
		}

		$pdf->setFilemode($filemode);
		if ($pdf->getFilemode() == "F") {
			$dirname = get_temp_dir();
			$dirname .= trailingslashit($this->MAIN->getPrefix());
			$filename = "ticket_".$order->get_id()."_".$ticket_id.".pdf";
			wp_mkdir_p($dirname);
			$pdf->setFilepath($dirname);
		} else {
			$filename = "ticket_".$order->get_id()."_".$ticket_id.".pdf";
		}
		$pdf->setFilename($filename);

		$wcTicketTicketBanner = $this->MAIN->getAdmin()->getOptionValue('wcTicketTicketBanner');
		$wcTicketTicketBanner = apply_filters( $this->MAIN->_add_filter_prefix.'wcTicketTicketBanner', $wcTicketTicketBanner, $product_id);
		if (!empty($wcTicketTicketBanner) && intval($wcTicketTicketBanner) > 0) {
			//$option_wcTicketTicketBanner = $this->MAIN->getOptions()->getOption('wcTicketTicketBanner');
			$mediaData = SASO_EVENTTICKETS::getMediaData($wcTicketTicketBanner);
			/*$width = "600";
			if (isset($option_wcTicketTicketBanner['additional']) && isset($option_wcTicketTicketBanner['additional']['min']) && isset($option_wcTicketTicketBanner['additional']['min']['width'])) {
				$width = $option_wcTicketTicketBanner['additional']['min']['width'];
			}*/
			//if (!empty($mediaData['location']) && file_exists($mediaData['location'])) {
			$has_banner = false;
			if ($this->MAIN->getOptions()->isOptionCheckboxActive('wcTicketCompatibilityUseURL')) {
				if (!empty($mediaData['url'])) {
					$pdf->addPart('<div style="text-align:center;"><img src="'.$mediaData['url'].'"></div>');
					$has_banner = true;
				}
			} else {
				if (!empty($mediaData['for_pdf'])) {
					$pdf->addPart('<div style="text-align:center;"><img src="'.$mediaData['for_pdf'].'"></div>');
					$has_banner = true;
				}
			}
			if ($has_banner && isset($mediaData['meta']) && isset($mediaData['meta']['height']) && floatval($mediaData['meta']['height']) > 0) {
				$dpiY = 96;
				if (function_exists("getimagesize")) {
					$imageInfo = getimagesize($mediaData['location']);
					// DPI-Werte aus den EXIF-Daten extrahieren
					$dpiY = isset($imageInfo['dpi_y']) ? $imageInfo['dpi_y'] : $dpiY;
				}
				$units = $pdf->convertPixelIntoMm($mediaData['meta']['height'] + 10, $dpiY);
				$pdf->setQRParams(['pos'=>['y'=>$units]]);
			}
		}

		/* old approach
		$pdf->addPart('<h1 style="font-size:20pt;text-align:center;">'.htmlentities($this->MAIN->getAdmin()->getOptionValue("wcTicketHeading")).'</h1>');
		$pdf->addPart('{QRCODE_INLINE}');
		$pdf->addPart("<style>h4{font-size:16pt;} table.ticket_content_upper {width:14cm;padding-top:10pt;} table.ticket_content_upper td {height:5cm;}</style>".$html);
		$pdf->addPart('<br><br><p style="text-align:center;">'.$ticket_id.'</p>');
		*/

		if (strpos(" ".$html,"{QRCODE_INLINE}") > 0 || strpos(" ".$html,"{QRCODE}") > 0) {
		} else {
			$pdf->addPart('{QRCODE}');
		}

		$pdf->addPart($html);

		$wcTicketDontDisplayBlogName = $this->MAIN->getOptions()->isOptionCheckboxActive('wcTicketDontDisplayBlogName');
		if (!$wcTicketDontDisplayBlogName) {
			$pdf->addPart('<br><br><div style="text-align:center;font-size:10pt;"><b>'.wp_kses_post(get_bloginfo("name")).'</b></div>');
		}
		$wcTicketDontDisplayBlogDesc = $this->MAIN->getOptions()->isOptionCheckboxActive('wcTicketDontDisplayBlogDesc');
		if (!$wcTicketDontDisplayBlogDesc) {
			if ($wcTicketDontDisplayBlogName) $pdf->addPart('<br>');
			$pdf->addPart('<div style="text-align:center;font-size:10pt;">'.wp_kses_post(get_bloginfo("description")).'</div>');
		}
		if (!$this->MAIN->getOptions()->isOptionCheckboxActive('wcTicketDontDisplayBlogURL')) {
			$pdf->addPart('<br><div style="text-align:center;font-size:10pt;">'.site_url().'</div>');
		}

		$wcTicketTicketLogo = $this->MAIN->getAdmin()->getOptionValue('wcTicketTicketLogo');
		$wcTicketTicketLogo = apply_filters( $this->MAIN->_add_filter_prefix.'wcTicketTicketLogo', $wcTicketTicketLogo, $product_id);
		if (!empty($wcTicketTicketLogo) && intval($wcTicketTicketLogo) >0) {
			$option_wcTicketTicketLogo = $this->MAIN->getOptions()->getOption('wcTicketTicketLogo');
			$mediaData = SASO_EVENTTICKETS::getMediaData($wcTicketTicketLogo);
			$width = "200";
			if (isset($option_wcTicketTicketLogo['additional']) && isset($option_wcTicketTicketLogo['additional']['max']) && isset($option_wcTicketTicketLogo['additional']['max']['width'])) {
				$width = $option_wcTicketTicketLogo['additional']['max']['width'];
			}
			//if (!empty($mediaData['location']) && file_exists($mediaData['location'])) {
			if ($this->MAIN->getOptions()->isOptionCheckboxActive('wcTicketCompatibilityUseURL')) {
				if (!empty($mediaData['url'])) {
					$pdf->addPart('<br><br><p style="text-align:center;"><img width="'.$width.'" src="'.$mediaData['url'].'"></p>');
				}
			} else {
				if (!empty($mediaData['for_pdf'])) {
					$pdf->addPart('<br><br><p style="text-align:center;"><img width="'.$width.'" src="'.$mediaData['for_pdf'].'"></p>');
				}
			}
		}
		$brandingHidePluginBannerText = $this->MAIN->getOptions()->isOptionCheckboxActive('brandingHidePluginBannerText');
		if ($brandingHidePluginBannerText == false) {
			$pdf->addPart('<br><p style="text-align:center;font-size:6pt;">"Event Tickets With Ticket Scanner Plugin" for Wordpress</p>');
		}

		$wcTicketTicketBG = $this->MAIN->getAdmin()->getOptionValue('wcTicketTicketBG');
		$wcTicketTicketBG = apply_filters( $this->MAIN->_add_filter_prefix.'wcTicketTicketBG', $wcTicketTicketBG, $product_id);
		if (!empty($wcTicketTicketBG) && intval($wcTicketTicketBG) >0) {
			$mediaData = SASO_EVENTTICKETS::getMediaData($wcTicketTicketBG);
			if ($this->MAIN->getOptions()->isOptionCheckboxActive('wcTicketCompatibilityUseURL')) {
				if (!empty($mediaData['url'])) {
					$pdf->setBackgroundImage($mediaData['url']);
				}
			} else {
				if (!empty($mediaData['for_pdf'])) {
					$pdf->setBackgroundImage($mediaData['for_pdf']);
				}
			}
		}

		// Background color (as fallback when no image or to fill gaps)
		$bgColorOption = SASO_EVENTTICKETS::issetRPara('testDesigner')
			? 'wcTicketPDFBackgroundColorTest'
			: 'wcTicketPDFBackgroundColor';
		$wcTicketPDFBackgroundColor = $this->MAIN->getOptions()->getOptionValue($bgColorOption);
		if (!empty($wcTicketPDFBackgroundColor)) {
			$pdf->setBackgroundColor($wcTicketPDFBackgroundColor);
		}

		$wcTicketTicketAttachPDFOnTicket = $this->MAIN->getAdmin()->getOptionValue('wcTicketTicketAttachPDFOnTicket');
		if (!empty($wcTicketTicketAttachPDFOnTicket)) {
			$mediaData = SASO_EVENTTICKETS::getMediaData($wcTicketTicketAttachPDFOnTicket);
			if (!empty($mediaData['location']) && file_exists($mediaData['location'])) {
				$pdf->setAdditionalPDFsToAttachThem([$mediaData['location']]);
			}
		}

		$qrCodeContent = $this->MAIN->getCore()->getQRCodeContent($codeObj);
		$qrTicketPDFPadding = intval($this->MAIN->getOptions()->getOptionValue('qrTicketPDFPadding'));
		$pdf->setQRCodeContent(["text"=>$qrCodeContent, "style"=>["vpadding"=>$qrTicketPDFPadding, "hpadding"=>$qrTicketPDFPadding]]);

		ob_end_clean();

		try {
			$pdf->render();
		} catch(Exception $e) {}
		if ($pdf->getFilemode() == "F") {
			return $pdf->getFullFilePath();
		} else {
			die("PDF render not possible. Please remove HTML tags from the product description and ticket info with the product detail view.");
		}
	}

	public function displayDayChooserDateAsString($codeObj, $withTime=false) {
		if ($codeObj == null) return "";

		// check of product is day chooser
		$codeObj = $this->MAIN->getCore()->setMetaObj($codeObj);
		$metaObj = $codeObj['metaObj'];
		$is_daychooser = intval($metaObj["wc_ticket"]["is_daychooser"]);
		if ($is_daychooser != 1) return "";

		$day_per_ticket = $metaObj["wc_ticket"]["day_per_ticket"];
		if (empty($day_per_ticket)) return "";

		$date_string = "";

		if ($withTime) {
			$format = $this->MAIN->getOptions()->getOptionDateTimeFormat();

			// get time from product
			$product_id = intval($metaObj['woocommerce']['product_id']);
			if ($product_id > 0) {
				$ticket_times = $this->getCalcDateStringAllowedRedeemFromCorrectProduct($product_id, $codeObj);
				if ($ticket_times['is_start_time_set']) {
					$time_str = $day_per_ticket." ".$ticket_times['ticket_start_time'];
					// Use date_i18n with gmt=true - input is already in local time, gmt=true prevents timezone conversion but translates month/day names
					$date_string = date_i18n($format, strtotime($time_str), true);
				}
			}
		}

		if (empty($date_string)) {
			// format day_per_ticket - use date_i18n with gmt=true to translate month/day names without timezone conversion
			$date_format = $this->MAIN->getOptions()->getOptionDateFormat();
			$date_string = date_i18n($date_format, strtotime($day_per_ticket), true);
		}

		return $date_string;
	}

	public function displayTicketDateAsString($product_id, $date_format="Y/m/d", $time_format="H:i", $codeObj = null) {
		$product_id = intval($product_id);
		if ($product_id <= 0) throw new Exception("#8021 ".esc_html__("Product ID not valid for ticket date string", 'event-tickets-with-ticket-scanner'));

		$ticket_times = $this->calcDateStringAllowedRedeemFrom($product_id, $codeObj);
		$ticket_start_date = $ticket_times['ticket_start_date'];
		$ticket_start_time = $ticket_times['ticket_start_time'];
		$ticket_end_date = $ticket_times['ticket_end_date'];
		$ticket_end_time = $ticket_times['ticket_end_time'];
		$is_daychooser = $ticket_times['is_daychooser'];
		$is_date_set = $ticket_times['is_date_set'];
		$is_end_time_set = $ticket_times['is_end_time_set'];
		$is_start_time_set = $ticket_times['is_start_time_set'];
		$ret = "";

		// not start day and time set
		// then only display what is set
		// to avoid something like " - 2024-12-12 14:00"
		// or "2024-12-12 14:00 - "
		// or " - 14:00"
		// or "2024-12-12 - "
		// or " - 2024-12-12"

		// Use date_i18n with gmt=true - input is already in local time, gmt=true prevents timezone conversion but translates month/day names
		if ($is_date_set) {
			$ret .= date_i18n($date_format." ".$time_format, strtotime($ticket_start_date." ".$ticket_start_time), true);
		} else if (!empty($ticket_start_date)) {
			$ret .= date_i18n($date_format, strtotime($ticket_start_date), true);
		} else if ($is_start_time_set) {
			$ret .= date_i18n($time_format, strtotime($ticket_start_time), true);
		}
		if (!empty($ret) && !empty($ticket_end_date) || $is_end_time_set) $ret .= " - ";
		if (!empty($ticket_end_date) && $is_end_time_set) {
			$ret .= date_i18n($date_format." ".$time_format, strtotime($ticket_end_date." ".$ticket_end_time), true);
		} else if (!empty($ticket_end_date)) {
			$ret .= date_i18n($date_format, strtotime($ticket_end_date), true);
		} else if ($is_end_time_set) {
			$ret .= date_i18n($time_format, strtotime($ticket_end_time), true);
		}

		return $ret;
	}

	public function getOrderItem($order, $metaObj) {
		$order_item = null;
		foreach ( $order->get_items() as $item_id => $item ) {
			if ($metaObj['woocommerce']['item_id'] == $item_id) {
				$order_item = $item;
				break;
			}
		}
		return $order_item;
	}

	private function getOrderTicketsInfos($order_id, $my_idcode) {
		$order_id = intval($order_id);
		$order = wc_get_order($order_id);
		if ($order == null) return "Wrong ticket code id";
		$idcode = $order->get_meta('_saso_eventtickets_order_idcode');
		if (empty($idcode) || $idcode != $my_idcode) return "Wrong ticket code";

		$option_displayDateTimeFormat = $this->MAIN->getOptions()->getOptionDateTimeFormat();
		$products = []; // to have the single items listed on the order view
		$ticket_infos = [];
		$tickets = $this->MAIN->getWC()->getTicketsFromOrder($order);
		if (count($tickets) > 0) {
			set_time_limit(0);
			$this->setOrder($order);

			$wcTicketHideDateOnPDF = $this->MAIN->getOptions()->isOptionCheckboxActive('wcTicketHideDateOnPDF');

			foreach($tickets as $key => $obj) {
				$codes = [];
				if (!empty($obj['codes'])) {
					$codes = explode(",", $obj['codes']);
				}
				foreach($codes as $code) {
					try {
						$codeObj = $this->MAIN->getCore()->retrieveCodeByCode($code);
					} catch (Exception $e) {
						continue;
					}
					$codeObj = $this->MAIN->getCore()->setMetaObj($codeObj);
					$metaObj = $codeObj['metaObj'];

					$order_item = $this->getOrderItem($order, $metaObj);
					if ($order_item == null) throw new Exception("#8004 Order not found");
					$product = $order_item->get_product();
					$is_variation = $product->get_type() == "variation" ? true : false;
					$product_parent = $product;
					$product_parent_id = $product->get_parent_id();

					if ($is_variation && $product_parent_id > 0) {
						$product_parent = $this->get_product( $product_parent_id );
					}

					$product_original = $product;
					$product_parent_original = $product_parent;

					$product_original_id = $this->getWPMLProductId($product->get_id());
					$product_parent_original_id = $this->getWPMLProductId($product_parent_id);
					if ($product_original_id != $product->get_id()) {
						$product_original = $this->get_product($product_original_id);
					}
					if ($product_parent_original_id > 0 && $product_parent_original_id != $product_parent->get_id()) {
						$product_parent_original = $this->get_product($product_parent_original_id);
					}

					$saso_eventtickets_is_date_for_all_variants = true;
					if ($is_variation && $product_parent_id > 0) {
						$saso_eventtickets_is_date_for_all_variants = get_post_meta( $product_parent_original->get_id(), 'saso_eventtickets_is_date_for_all_variants', true ) == "yes" ? true : false;
					}

					$this->isProductAllowedByAuthToken([$product->get_id()]);

					$tmp_product = $product_parent;
					if (!$saso_eventtickets_is_date_for_all_variants) $tmp_product = $product; // unter Umständen die Variante
					$ticket_start_date = trim(get_post_meta( $tmp_product->get_id(), 'saso_eventtickets_ticket_start_date', true ));
					$ticket_start_time = trim(get_post_meta( $tmp_product->get_id(), 'saso_eventtickets_ticket_start_time', true ));
					if (empty($ticket_start_date) && !empty($ticket_start_time)) {
						$ticket_start_date = wp_date("Y-m-d");
					}

					$ticket_id = $this->MAIN->getCore()->getTicketId($codeObj, $metaObj);
					$qrCodeContent = $this->MAIN->getCore()->getQRCodeContent($codeObj, $metaObj);

					$ticketObj = [];
					$ticketObj['ticket_id'] = $ticket_id;
					$ticketObj['product_id'] = $product->get_id();
					$ticketObj['product_parent_id'] = $product_parent->get_id();
					$ticketObj['qrcode_content'] = $qrCodeContent;
					$ticketObj['code_public'] = $metaObj["wc_ticket"]["_public_ticket_id"];
					$ticketObj['code'] = $codeObj['code'];
					$ticketObj['code_display'] = $codeObj['code_display'];
					$ticketObj['product_name'] = esc_html($product_parent->get_Title());
					$ticketObj['product_name_variant'] = "";
					if ($is_variation && $this->MAIN->getOptions()->isOptionCheckboxActive('wcTicketPDFDisplayVariantName') && count($product->get_attributes()) > 0) {
						foreach($product->get_attributes() as $k => $v){
							$ticketObj['product_name_variant'] .= $v." ";
						}
					}
					$location = trim(get_post_meta( $product_parent_original->get_id(), 'saso_eventtickets_event_location', true ));
					$ticketObj['location'] = $location == "" ? "" : wp_kses_post($this->MAIN->getAdmin()->getOptionValue("wcTicketTransLocation"))." <b>".wp_kses_post($location)."</b>";
					$ticketObj['ticket_date'] = "";
					if ($wcTicketHideDateOnPDF == false && !empty($ticket_start_date)) {
						$ticketObj['ticket_date'] = $this->displayTicketDateAsString($tmp_product->get_id(), $this->MAIN->getOptions()->getOptionDateFormat(), $this->MAIN->getOptions()->getOptionTimeFormat(), $codeObj);
					}
					$ticketObj['name_per_ticket'] = "";
					if (!empty($metaObj['wc_ticket']['name_per_ticket'])) {
						$order_quantity = $order_item->get_quantity();
						$ticket_pos = "";
						if ($order_quantity > 1) {
							// ermittel ticket pos
							$codes = explode(",", $order_item->get_meta('_saso_eventtickets_product_code', true));
							$ticket_pos = $this->ermittelCodePosition($codeObj['code_display'], $codes);
						}
						$label = esc_attr($this->getLabelNamePerTicket($product_parent_original->get_id()));
						$ticketObj['name_per_ticket'] = str_replace("{count}", $ticket_pos, $label)." ".esc_attr($metaObj['wc_ticket']['name_per_ticket']);
					}
					$ticketObj['value_per_ticket'] = "";
					if (!empty($metaObj['wc_ticket']['value_per_ticket'])) {
						$order_quantity = $order_item->get_quantity();
						$ticket_pos = "";
						if ($order_quantity > 1) {
							$codes = explode(",", $order_item->get_meta('_saso_eventtickets_product_code', true));
							$ticket_pos = $this->ermittelCodePosition($codeObj['code_display'], $codes);
						}
						$label = esc_attr($this->getLabelValuePerTicket($product_parent_original->get_id()));
						$ticketObj['value_per_ticket'] = str_replace("{count}", $ticket_pos, $label)." ".esc_attr($metaObj['wc_ticket']['value_per_ticket']);
					}

					// Seat information
					$ticketObj['seat_label'] = !empty($metaObj['wc_ticket']['seat_label']) ? esc_html($metaObj['wc_ticket']['seat_label']) : '';
					$ticketObj['seat_category'] = !empty($metaObj['wc_ticket']['seat_category']) ? esc_html($metaObj['wc_ticket']['seat_category']) : '';
					$ticketObj['seat_id'] = !empty($metaObj['wc_ticket']['seat_id']) ? intval($metaObj['wc_ticket']['seat_id']) : 0;
					$ticketObj['seating_plan_id'] = 0;
					$ticketObj['seating_plan_name'] = '';
					$hidePlanInScanner = $this->MAIN->getOptions()->isOptionCheckboxActive('seatingHidePlanNameInScanner');
					if ($ticketObj['seat_id'] > 0 && !$hidePlanInScanner) {
						$planId = $this->MAIN->getSeating()->getSeatManager()->getSeatingPlanIdForSeatId($ticketObj['seat_id']);
						if ($planId) {
							$ticketObj['seating_plan_id'] = intval($planId);
							$plan = $this->MAIN->getSeating()->getPlanManager()->getById($planId);
							if ($plan) {
								$ticketObj['seating_plan_name'] = esc_html($plan['name']);
							}
						}
					}

					$ticket_infos[] = $ticketObj;

					$products[$product->get_id()] = [
						"product_id"=>$product->get_id(),
						"product_parent_id"=>$product_parent->get_id(),
						"product_id_original"=>$product_original->get_id(),
						"product_parent_original_id"=>$product_parent_original->get_id(),
						"product_name"=>$ticketObj['product_name'],
						"product_name_variant"=>$ticketObj['product_name_variant'],
					];
				}
			}
		}

		$order_code = $this->getParts(trim(SASO_EVENTTICKETS::getRequestPara('code', $def='')))["foundcode"];
		$qrcode_content = $order_code;
		if ($this->MAIN->getOptions()->isOptionCheckboxActive('ticketQRUseURLToTicketScanner')) {
			$qrcode_content = $this->MAIN->getCore()->getTicketScannerURL($order_code);
		}
		$order_infos = [
				"id"=>$order_id,
				"is_order_ticket"=>true, // for the ticket scanner to recognize the answer
				"code"=>$order_code,
				"qrcode_content"=>$qrcode_content,
				"option_displayDateTimeFormat"=>$option_displayDateTimeFormat,
				"date_created"=>wp_date($option_displayDateTimeFormat, strtotime($order->get_date_created())),
				"date_paid"=> $order->get_date_paid() != null ? wp_date($option_displayDateTimeFormat, strtotime($order->get_date_paid())) : "-",
				"date_completed"=>$order->get_date_completed() != null ? wp_date($option_displayDateTimeFormat, strtotime($order->get_date_completed())) : "-",
				"total"=>$order->get_formatted_order_total(),
				"customer_id"=>$order->get_customer_id(),
				"billing_name"=>$order->get_formatted_billing_full_name(),
				"products"=>array_values($products)
			];

		$ret = ["order"=>$order, "order_infos"=>$order_infos, "ticket_infos"=>$ticket_infos];
		$ret = apply_filters( $this->MAIN->_add_filter_prefix.'ticket_getOrderTicketsInfos', $ret );
		return $ret;
	}

	private function outputOrderTicketsInfos() {
		$parts = $this->getParts();
		if (count($parts) < 3) return "WRONG CODE";

		wp_enqueue_style("wp-jquery-ui-dialog");

		wp_enqueue_script(
            'ajax_script_order_ticket',
            plugins_url("order_details.js?_v=".$this->MAIN->getPluginVersion(), __FILE__),
            array('jquery', 'jquery-ui-dialog', 'wp-i18n')
        );
		wp_set_script_translations('ajax_script_order_ticket', 'event-tickets-with-ticket-scanner', __DIR__.'/languages');

		$infos = $this->getOrderTicketsInfos($parts['order_id'], $parts['code']);
		$order = $infos["order"];

		$this->setOrderStatusAfterViewOperation($order);

		$order_infos = $infos["order_infos"];
		$ticket_infos = $infos["ticket_infos"];

		if ($this->MAIN->getOptions()->isOptionCheckboxActive('wcTicketDisplayDownloadAllTicketsPDFButtonOnOrderdetail')) {
			$url = $this->MAIN->getCore()->getOrderTicketsURL($order);
			$dlnbtnlabel = $this->MAIN->getOptions()->getOptionValue('wcTicketLabelPDFDownload');
			$dlnbtnlabelHeading = $this->MAIN->getOptions()->getOptionValue('wcTicketLabelPDFDownloadHeading');
			$order_infos["wcTicketDisplayDownloadAllTicketsPDFButtonOnOrderdetail"] = 1;
			$order_infos["wcTicketLabelPDFDownloadHeading"] = esc_html($dlnbtnlabelHeading);
			$order_infos["url_order_tickets"] = esc_url($url);
			$order_infos["wcTicketLabelPDFDownload"] = esc_html($dlnbtnlabel);
		}

		echo '<div id="'.$this->MAIN->getPrefix().'_order_detail_area"></div>';
		echo "\n<script>\n";
		echo 'let sasoEventtickets_order_detail_data = {"order":{},"tickets":[]};'."\n";
		echo 'sasoEventtickets_order_detail_data.order = '.json_encode($order_infos).';';
		echo 'sasoEventtickets_order_detail_data.tickets = '.json_encode($ticket_infos).';';
		echo 'sasoEventtickets_order_detail_data.system = '.json_encode(["base_url"=>plugin_dir_url(__FILE__), "divPrefix"=>$this->MAIN->getPrefix()]).';';
		echo '</script>';
	}

	private function outputTicketInfo($forPDFOutput=false) {
		$codeObj = $this->getCodeObj();
		$codeObj = $this->MAIN->getCore()->setMetaObj($codeObj);
		$metaObj = $codeObj['metaObj'];

		if ($forPDFOutput == false) {
			do_action( $this->MAIN->_do_action_prefix.'trackIPForTicketView', $codeObj );
		}

		$display_the_ticket = apply_filters( $this->MAIN->_do_action_prefix.'ticket_outputTicketInfo', true, $codeObj, $forPDFOutput );
		do_action( $this->MAIN->_do_action_prefix.'ticket_outputTicketInfo_pre', $display_the_ticket, $codeObj, $forPDFOutput );

		if ($display_the_ticket) {
			$ticketDesigner = $this->MAIN->getTicketDesignerHandler();

			// !!! nonce test is not working, because this function is also called from the other methods
			//if (SASO_EVENTTICKETS::issetRPara('testDesigner') && current_user_can( 'manage_options' ) ) {
			//$a = SASO_EVENTTICKETS::getRequestPara('nonce');
			//$b = $this->MAIN->_js_nonce;

			//$is_nonce_check_ok = wp_verify_nonce(SASO_EVENTTICKETS::getRequestPara('nonce'), $this->MAIN->_js_nonce);
			//if (SASO_EVENTTICKETS::issetRPara('testDesigner') && $this->MAIN->isUserAllowedToAccessAdminArea() ) {
			//if (SASO_EVENTTICKETS::issetRPara('testDesigner') && $is_nonce_check_ok ) {

			$template = "";
			$ticket_template = apply_filters( $this->MAIN->_add_filter_prefix.'ticket_outputTicketInfo_template', null, $codeObj );
			if ($ticket_template != null) {
				$template = $ticket_template['template'];
			}
			if (SASO_EVENTTICKETS::issetRPara('testDesigner') ) { // TODO: quick fix, so that users can work
				if (empty($template)) {
					$template = $this->MAIN->getAdmin()->getOptionValue("wcTicketDesignerTemplateTest");
				}
			} else {
				if ($this->MAIN->getOptions()->isOptionCheckboxActive('wcTicketTemplateUseDefault') == false) {
					if (empty($template)) {
						$template = $this->MAIN->getAdmin()->getOptionValue("wcTicketDesignerTemplate");
					}
				}
			}
			$ticketDesigner->setTemplate($template);
			echo $ticketDesigner->renderHTML($codeObj, $forPDFOutput);

			// buttons
			$vars = $ticketDesigner->getVariables();
			$ticket_times = $this->calcDateStringAllowedRedeemFrom($vars["PRODUCT"]->get_id(), $codeObj);
			if ($vars["forPDFOutput"] == false) {
				$is_expired = $this->MAIN->getCore()->checkCodeExpired($codeObj);
				if (!empty($vars["METAOBJ"]["wc_ticket"]["redeemed_date"])) {
					$redeem_counter = count($vars["METAOBJ"]["wc_ticket"]["stats_redeemed"]);
					$redeem_max = intval(get_post_meta( $vars["PRODUCT_PARENT"]->get_id(), 'saso_eventtickets_ticket_max_redeem_amount', true ));
					$color = "red";
					if ($redeem_max == 0) { // unlimited
						$color = "green";
					} elseif ($redeem_max > 1 && $redeem_counter <= $redeem_max) {
						$color = "green";
					}
					echo '<center>';
					echo '<h4 style="color:'.$color.';">'.wp_kses_post($vars["OPTIONS"]["wcTicketTransTicketRedeemed"]).'</h4>';
					// Use date_i18n with gmt=true - redeemed_date is stored in local time, gmt=true prevents timezone conversion but translates month/day names
				echo wp_kses_post($vars["OPTIONS"]["wcTicketTransRedeemDate"]).' '.date_i18n($vars["TICKET"]["date_time_format"], strtotime($vars["METAOBJ"]["wc_ticket"]["redeemed_date"]), true);
					if ($is_expired == false && $vars["isScanner"] == false && $ticket_times['ticket_end_date_timestamp'] > $ticket_times['server_time_timestamp']) {
						echo '<h5 style="font-weight:bold;color:green;">'.wp_kses_post($vars["OPTIONS"]["wcTicketTransTicketValid"]).'</h5>';
						echo '<form method="get"><input type="hidden" name="code" value="'.esc_attr($metaObj["wc_ticket"]["_public_ticket_id"]).'"><input type="submit" value="'.esc_attr($vars["OPTIONS"]["wcTicketTransRefreshPage"]).'"></form>';
					}
					echo '</center>';
				}
				if ($vars["isScanner"] == false) {
					if ($vars["OPTIONS"]["wcTicketShowRedeemBtnOnTicket"] == true) {
						$display_button = true;
						if ($is_expired) {
							$display_button = false;
							echo ' <center><h4 style="color:red;">'.wp_kses_post($vars["OPTIONS"]["wcTicketTransTicketExpired"]).'</h4></center>';
						} elseif ($ticket_times['is_date_set'] == true && $ticket_times['ticket_end_date_timestamp'] < $ticket_times['server_time_timestamp']) {
							$display_button = false;
							echo ' <center><h4 style="color:red;">'.wp_kses_post($vars["OPTIONS"]["wcTicketTransTicketNotValidToLate"]).'</h4></center>';
						} elseif ($ticket_times['is_date_set'] == true && $ticket_times['redeem_allowed_from_timestamp'] > $ticket_times['server_time_timestamp']) {
							$display_button = false;
							echo ' <center><h4 style="color:red;">'.wp_kses_post($vars["OPTIONS"]["wcTicketTransTicketNotValidToEarly"]).'</h4></center>';
						}
						if ($display_button) {
								echo '
								<script>
									function redeem_ticket() {
									if (confirm("'.$vars["OPTIONS"]["wcTicketTransRedeemQuestion"].'")) {
										return true;
									}
									return false;
								}
								</script>
								<div style="margin-top:30px;margin-bottom:30px;text-align:center;">
									<form onsubmit="return redeem_ticket()" method="post">
										<input type="hidden" name="action" value="redeem">
										<input type="submit" class="button-primary" value="'.wp_kses_post($vars["OPTIONS"]["wcTicketTransBtnRedeemTicket"]).'">
									</form>
								</div>';
						}
					}
				}
				if (SASO_EVENTTICKETS::issetRPara('displaytime')) {
					echo '<p>Server time: '.wp_date("Y-m-d H:i").'</p>';
					print_r($ticket_times);
				}
				if ($vars["OPTIONS"]["wcTicketDontDisplayPDFButtonOnDetail"] == false ||  $vars["OPTIONS"]["wcTicketLabelICSDownload"] == false || $vars["OPTIONS"]["wcTicketBadgeDisplayButtonOnDetail"]) {
					echo '<p style="text-align:center;">';
					if ($vars["OPTIONS"]["wcTicketDontDisplayPDFButtonOnDetail"] == false) {
						echo '<a class="button button-primary" target="_blank" href="'.$vars["METAOBJ"]["wc_ticket"]["_url"].'?pdf">'.wp_kses_post($vars["OPTIONS"]["wcTicketLabelPDFDownload"]).'</a> ';
					}
					if ($vars["OPTIONS"]["wcTicketDontDisplayICSButtonOnDetail"] == false) {
						echo '<a class="button button-primary" target="_blank" href="'.$vars["METAOBJ"]["wc_ticket"]["_url"].'?ics">'.wp_kses_post($vars["OPTIONS"]["wcTicketLabelICSDownload"]).'</a> ';
					}
					if ($vars["OPTIONS"]["wcTicketBadgeDisplayButtonOnDetail"] == true) {
						echo '<a class="button button-primary" target="_blank" href="'.$vars["METAOBJ"]["wc_ticket"]["_url"].'?badge">'.wp_kses_post($vars["OPTIONS"]["wcTicketBadgeLabelDownload"]).'</a>';
					}
					echo '</p>';
				}
			}
		}

		do_action( $this->MAIN->_do_action_prefix.'ticket_outputTicketInfo_after', $codeObj, $forPDFOutput );
	}

	/**
	 * welche position in den erstellten tickets für das order item hat der code
	 * @param $codes array mit den codes
	 */
	public function ermittelCodePosition($code, $codes) {
		$pos = array_search($code, $codes);
		if ($pos === false) return 1;
		return $pos + 1;
	}

	public function getMaxRedeemAmountOfTicket($codeObj) {
		$codeObj = $this->MAIN->getCore()->setMetaObj($codeObj);
		$metaObj = $codeObj['metaObj'];
		$max_redeem_amount = 1;
		if (isset($metaObj['woocommerce']) && isset($metaObj['woocommerce']['product_id'])) {
			$product_id = intval($metaObj['woocommerce']['product_id']);
			if ($product_id > 0) {
				$product = $this->get_product( $product_id );
				$is_variation = $product->get_type() == "variation" ? true : false;
				$product_parent_id = $product->get_parent_id();
				if ($is_variation && $product_parent_id > 0) {
					$product = $this->get_product( $product_parent_id );
				}
				$max_redeem_amount = intval(get_post_meta( $product->get_id(), 'saso_eventtickets_ticket_max_redeem_amount', true ));
			}
		}
		return $max_redeem_amount;
	}

	public function getRedeemAmountText($codeObj, $metaObj, $forPDFOutput=false) {
		$text_redeem_amount = "";
		$max_redeem_amount = $this->getMaxRedeemAmountOfTicket($codeObj);
		if ($max_redeem_amount > 1) {
			if ($forPDFOutput) {
				$text_redeem_amount = wp_kses_post($this->MAIN->getOptions()->getOptionValue('wcTicketTransRedeemMaxAmount'));
				$text_redeem_amount = str_replace("{MAX_REDEEM_AMOUNT}", $max_redeem_amount, $text_redeem_amount);
			} else {
				$text_redeem_amount = wp_kses_post($this->MAIN->getOptions()->getOptionValue('wcTicketTransRedeemedAmount'));
				$text_redeem_amount = str_replace("{MAX_REDEEM_AMOUNT}", $max_redeem_amount, $text_redeem_amount);
				$text_redeem_amount = str_replace("{REDEEMED_AMOUNT}", count($metaObj['wc_ticket']['stats_redeemed']), $text_redeem_amount);
			}
		}
		return $text_redeem_amount;
	}

	private function isRedeemOperationTooEarly($codeObj, $metaObj, $order) {
		// ermittel product
		$order_item = $this->getOrderItem($order, $metaObj);
		if ($order_item == null) throw new Exception("#8015 ".esc_html__("Can not find the product for this ticket.", 'event-tickets-with-ticket-scanner'));
		$product = $order_item->get_product();
		$product_id = $product->get_id();
		if ($product_id < 1) {
			throw new Exception("#236 product id could not be retrieved");
		}
		$ret = $this->getCalcDateStringAllowedRedeemFromCorrectProduct($product_id, $codeObj);
		return $ret['redeem_allowed_from_timestamp'] >= $ret['server_time_timestamp'];
	}
	private function isRedeemOperationTooLateEventEnded($codeObj, $metaObj, $order) {
		$order_item = $this->getOrderItem($order, $metaObj);
		if ($order_item == null) throw new Exception("#8015 ".esc_html__("Can not find the product for this ticket.", 'event-tickets-with-ticket-scanner'));
		$product = $order_item->get_product();
		$product_id = $product->get_id();
		if ($product_id < 1) {
			throw new Exception("#233 product id could not be retrieved");
		}
		$ret = $this->getCalcDateStringAllowedRedeemFromCorrectProduct($product_id, $codeObj);
		return $ret['ticket_end_date_timestamp'] <= $ret['server_time_timestamp'];
	}
	private function isRedeemOperationTooLate($codeObj, $metaObj, $order) {
		$order_item = $this->getOrderItem($order, $metaObj);
		if ($order_item == null) throw new Exception("#8018 ".esc_html__("Can not find the product for this ticket.", 'event-tickets-with-ticket-scanner'));
		$product = $order_item->get_product();
		$product_id = $product->get_id();
		if ($product_id < 1) {
			throw new Exception("#234 product id could not be retrieved");
		}
		$ret = $this->getCalcDateStringAllowedRedeemFromCorrectProduct($product_id, $codeObj);
		return $ret['is_date_set'] && $ret['ticket_start_date_timestamp'] < $ret['server_time_timestamp'];
	}
	private function checkEventStart($codeObj, $metaObj, $order) {
		if ($this->MAIN->getOptions()->isOptionCheckboxActive('wcTicketDontAllowRedeemTicketBeforeStart')) {
			if ($this->isRedeemOperationTooEarly($codeObj, $metaObj, $order)) {
				throw new Exception("#8016 ".esc_html__("Too early. Ticket cannot be redeemed yet.", 'event-tickets-with-ticket-scanner'));
			}
		}
	}
	private function checkEventEnd($codeObj, $metaObj, $order) {
		if ($this->MAIN->getOptions()->isOptionCheckboxActive('wcTicketAllowRedeemTicketAfterEnd') == false) {
			if ($this->isRedeemOperationTooLateEventEnded($codeObj, $metaObj, $order)) {
				throw new Exception("#8017 ".esc_html__("Too late, event finished. Ticket cannot be redeemed anymore.", 'event-tickets-with-ticket-scanner'));
			}
		}
		if ($this->MAIN->getOptions()->isOptionCheckboxActive('wsticketDenyRedeemAfterstart')) {
			if ($this->isRedeemOperationTooLate($codeObj, $metaObj, $order)) {
				throw new Exception("#8019 ".esc_html__("Too late, event started. Ticket cannot be redeemed anymore.", 'event-tickets-with-ticket-scanner'));
			}
		}
	}
	private function setStatusAfterRedeemOperation($order) {
		$ticketScannerSetOrderStatusAfterRedeem = $this->MAIN->getOptions()->getOptionValue("ticketScannerSetOrderStatusAfterRedeem");
		if (strlen($ticketScannerSetOrderStatusAfterRedeem) > 1) { // no status change = "1"
			if ($order != null) {
				if ($order->get_status() != $ticketScannerSetOrderStatusAfterRedeem) {
					$order->update_status($ticketScannerSetOrderStatusAfterRedeem);
				}
			}
		}
		return $order;
	}
	private function setOrderStatusAfterViewOperation($order) {
		$ticketScannerSetOrderStatusAfterTicketView = $this->MAIN->getOptions()->getOptionValue("ticketScannerSetOrderStatusAfterTicketView");
		if (strlen($ticketScannerSetOrderStatusAfterTicketView) > 1) { // no status change = "1"
			if ($order != null) {
				if ($order->get_status() != $ticketScannerSetOrderStatusAfterTicketView) {
					$order->update_status($ticketScannerSetOrderStatusAfterTicketView);
				}
			}
		}
		return $order;
	}
	private function redeemTicket($codeObj = null) {
		$this->redeem_successfully = false;
		if ($codeObj == null) {
			$codeObj = $this->getCodeObj();
		}
		$metaObj = $codeObj['metaObj'];

		// check wird nochmal in adminsetting redeem gemacht, aber ohne eigenen Text
		$max_redeem_amount = $this->getMaxRedeemAmountOfTicket($codeObj);

		if ($metaObj['wc_ticket']['redeemed_date'] == "" || $max_redeem_amount > 0) {
			$order = $this->getOrderById($codeObj["order_id"]);
			$is_paid = $this->isPaid($order);
			if (!$is_paid && $this->MAIN->getOptions()->isOptionCheckboxActive('wcTicketAllowRedeemOnlyPaid')) {
				throw new Exception("#8014 ".esc_html__("Order is not paid. And the option is active to allow only paid ticket to be redeemed is active.", 'event-tickets-with-ticket-scanner'));
			}

			$this->checkEventStart($codeObj, $metaObj, $order);
			$this->checkEventEnd($codeObj, $metaObj, $order);

			$user_id = $order->get_user_id();
			$user_id = intval($user_id);
			$data = [
				'code'=>$codeObj['code'],
				'userid'=>$user_id,
				'redeemed_by_admin'=>1
			];
			$this->MAIN->getAdmin()->executeJSON('redeemWoocommerceTicketForCode', $data, true);

			$order = $this->setStatusAfterRedeemOperation($order);

			$this->redeem_successfully = true;
			do_action( $this->MAIN->_do_action_prefix.'ticket_redeemTicket', $codeObj, $data );
		}
	}

	private function executeRequestScanner() {
		if (SASO_EVENTTICKETS::issetRPara('action') && SASO_EVENTTICKETS::getRequestPara('action') == "redeem" || (SASO_EVENTTICKETS::issetRPara('redeemauto') && SASO_EVENTTICKETS::issetRPara('code'))) {
			if (!SASO_EVENTTICKETS::issetRPara('code')) throw new Exception("#8008 ".esc_html__('Ticket number to redeem is missing', 'event-tickets-with-ticket-scanner')); // hmm, seems that this will never be called
			$this->redeemTicket();
			$this->codeObj = null;
		}
	}

	private function executeRequest() {
		// auswerten $this->getParts()['_request']
		//if ($this->getParts()['_request'] == "action=redeem") {
		if (SASO_EVENTTICKETS::issetRPara('action') && SASO_EVENTTICKETS::getRequestPara('action') == "redeem") {
			// redeem ausführen
			$order = $this->getOrder();
			if ($this->isPaid($order)) {
				$codeObj = $this->getCodeObj();
				$metaObj = $codeObj['metaObj'];

					$user_id = get_current_user_id();
					if (empty($user_id)) {
						$user_id = $order->get_user_id();
					}
					$user_id = intval($user_id);
					$data = [
						'code'=>$codeObj['code'],
						'userid'=>$user_id
					];

					try {
						$this->checkEventStart($codeObj, $metaObj, $order);
					} catch (Exception $e) {
						throw new Exception(esc_html__("Redeem operation not yet possible.", 'event-tickets-with-ticket-scanner'));
					}

					try {
						$this->checkEventEnd($codeObj, $metaObj, $order);
					} catch (Exception $e) {
						throw new Exception(esc_html__("Redeem operation not possible. Too late.", 'event-tickets-with-ticket-scanner'));
					}

					$this->MAIN->getAdmin()->executeJSON('redeemWoocommerceTicketForCode', $data, true);

					$order = $this->setStatusAfterRedeemOperation($order);

					// check if ticket redirection is activated
					if ($this->MAIN->getOptions()->isOptionCheckboxActive('wcTicketRedirectUser')) {
						// redirect
						$url = $this->MAIN->getAdmin()->getOptionValue('wcTicketRedirectUserURL');
						$url = $this->MAIN->getCore()->replaceURLParameters($url, $codeObj);
						if (!empty($url)) {
							header('Location: '.$url);
							exit;
						}
					}
					// check if user redirect is activated - Big BS, did not realize it was already implemented :( , now we need it twice here (on the front end, only the user redirect will be used)
					if ($this->MAIN->getOptions()->isOptionCheckboxActive('userJSRedirectActiv')) {
						$url = $this->MAIN->getTicketHandler()->getUserRedirectURLForCode($codeObj);
						if (!empty($url)) {
							header('Location: '.$url);
							exit;
						}
					}

					$this->codeObj = null;

			} else {
				throw new Exception(esc_html__("Order not marked as paid. Ticket not redeemed.", 'event-tickets-with-ticket-scanner'));
			}
		}
	}

	public function getUserRedirectURLForCode($codeObj) {
		$url = $this->MAIN->getOptions()->getOptionValue('userJSRedirectURL');
		// check if code list has url
		if ($codeObj['list_id'] != 0) {
			// hole code list
			$listObj = $this->MAIN->getCore()->getListById($codeObj['list_id']);
			$metaObj = $this->MAIN->getCore()->encodeMetaValuesAndFillObjectList($listObj['meta']);
			if (isset($metaObj['redirect']['url'])) {
				$_url = trim($metaObj['redirect']['url']);
				if (!empty($_url)) $url = $_url;
			}
		}

		$url = apply_filters($this->MAIN->_add_filter_prefix.'getJSRedirectURL', $codeObj);
		if (is_array($_url)) $_url = ""; // codeobj kam zurück, da niemand auf den hook hört (premium missing/deaktiviert)
		if (!empty($_url)) $url = $_url;

		// replace place holder
		$url = $this->MAIN->getCore()->replaceURLParameters($url, $codeObj);
		return $url;
	}

	public function addMetaTags() {
		echo "\n<!-- Meta TICKET EVENT -->\n";
        echo '<meta property="og:title" content="'.esc_attr__("Ticket Info", 'event-tickets-with-ticket-scanner').'" />';
        echo '<meta property="og:type" content="article" />';
        //echo '<meta property="og:description" content="'.$this->getPageDescription().'" />';
		echo '<style>
			div.ticket_content p {font-size:initial !important;margin-bottom:1em !important;}
			</style>';
        echo "\n<!-- Ende Meta TICKET EVENT -->\n\n";
	}

	private function isPDFRequest() {
		if (isset($_GET['pdf'])) return true;
		$this->getParts();
		if ($this->parts != null && isset($this->parts['_isPDFRequest'])) {
			return $this->parts['_isPDFRequest'];
		}
		return false;
	}

	private function isICSRequest() {
		if (isset($_GET['ics'])) return true;
		$this->getParts();
		if ($this->parts != null && isset($this->parts['_isICSRequest'])) {
			return $this->parts['_isICSRequest'];
		}
		return false;
	}

	private function isBadgeRequest() {
		if (isset($_GET['badge'])) return true;
		$this->getParts();
		if ($this->parts != null && isset($this->parts['_isBadgeRequest'])) {
			return $this->parts['_isBadgeRequest'];
		}
		return false;
	}

	private function isOrderTicketInfo() {
		$parts = $this->getParts();
		// bsp ordertickets-395-3477288899
		if (isset($parts['idcode']) && $parts['idcode'] == "ordertickets") return true;
		return false;
	}

	private function isOnePDFRequest() {
		$parts = $this->getParts();
		// bsp order-395-3477288899
		if (isset($parts['idcode']) && $parts['idcode'] == "order") return true;
		return false;
	}

	private function initOnePDFOutput() {
		$parts = $this->getParts();
		if (count($parts) > 2) {
			$order_id = intval($parts['order_id']);
			$order = wc_get_order($order_id);
			$idcode = $order->get_meta('_saso_eventtickets_order_idcode');
			if (!empty($idcode) && $idcode == $parts['code']) {
				$this->setOrderStatusAfterViewOperation($order);
				$this->outputPDFTicketsForOrder($order);
			} else {
				echo "Wrong ticket code";
			}
		}
	}

	/**
	 * Render ticket detail for shortcode (returns HTML, no header/footer)
	 */
	public function renderTicketDetailForShortcode(): string {
		if (!class_exists('WooCommerce')) {
			return '<p>' . esc_html__('No WooCommerce Support Found', 'event-tickets-with-ticket-scanner') . '</p>';
		}

		wp_enqueue_style("wp-jquery-ui-dialog");
		$js_url = "jquery.qrcode.min.js?_v=" . $this->MAIN->getPluginVersion();
		wp_enqueue_script(
			'ajax_script',
			plugins_url("3rd/" . $js_url, __FILE__),
			array('jquery', 'jquery-ui-dialog', 'wp-i18n')
		);
		wp_set_script_translations('ajax_script', 'event-tickets-with-ticket-scanner', __DIR__ . '/languages');

		ob_start();
		echo '<div class="ticket_content" style="background-color:white;color:black;padding:15px;display:block;position:relative;text-align:left;max-width:640px;border:1px solid black;margin:0 auto;">';
		try {
			if ($this->isOrderTicketInfo()) {
				$this->outputOrderTicketsInfos();
			} else {
				$this->outputTicketInfo();
				$order = $this->getOrder();
				if ($order != null) {
					$this->setOrderStatusAfterViewOperation($order);
				}
			}
		} catch (Exception $e) {
			echo '<h1 style="color:red;">' . esc_html__('Error', 'event-tickets-with-ticket-scanner') . '</h1>';
			echo '<p>' . esc_html($e->getMessage()) . '</p>';
		}
		echo '</div>';
		return ob_get_clean();
	}

	public function output() {
		$hasError = false;
		header('HTTP/1.1 200 OK');
		if (class_exists( 'WooCommerce' )) {

			try {
				if (!$this->isScanner()) {
					if($this->isPDFRequest()) {
						$this->checkIfDownloadIsAllowed();
						try {
							$this->outputPDF();
							exit;
						} catch (Exception $e) {}
					} elseif ($this->isICSRequest()) {
						$this->checkIfDownloadIsAllowed();
						$this->sendICSFile();
						exit;
					} elseif ($this->isBadgeRequest()) {
						$this->checkIfDownloadIsAllowed();
						$this->sendBadgeFile();
						exit;
					} elseif ($this->isOnePDFRequest()) {
						$this->checkIfDownloadIsAllowed();
						$this->initOnePDFOutput();
						exit;
					}
				}
			} catch(Exception $e) {
				$this->MAIN->getAdmin()->logErrorToDB($e);
				$hasError = true;
				get_header();
				echo '<div style="width: 100%; justify-content: center;align-items: center;position: relative;">';
				echo '<div class="ticket_content" style="background-color:white;color:black;padding:15px;display:block;position: relative;left: 0;right: 0;margin: auto;text-align:left;max-width:640px;border:1px solid black;">';
				echo '<h1 style="color:red;">'.esc_html__('Error', 'event-tickets-with-ticket-scanner').'</h1>';
				echo '<p>'.$e->getMessage().'</p>';
			}

			if (!$hasError) {
				wp_enqueue_style("wp-jquery-ui-dialog");

				$js_url = "jquery.qrcode.min.js?_v=".$this->MAIN->getPluginVersion();
				wp_enqueue_script(
					'ajax_script',
					plugins_url( "3rd/".$js_url,__FILE__ ),
					array('jquery', 'jquery-ui-dialog', 'wp-i18n')
				);
				wp_set_script_translations('ajax_script', 'event-tickets-with-ticket-scanner', __DIR__.'/languages');

				if ($this->MAIN->getOptions()->isOptionCheckboxActive('brandingHideHeader') == false) {
					get_header();
				}
				echo '<div style="width: 100%; justify-content: center;align-items: center;position: relative;">';
				echo '<div class="ticket_content" style="background-color:white;color:black;padding:15px;display:block;position: relative;left: 0;right: 0;margin: auto;text-align:left;max-width:640px;border:1px solid black;">';

				try {
					if ($this->isScanner()) { // old approach
						$this->executeRequestScanner();
						$this->outputTicketScanner();
					} else {
						$this->executeRequest();
						if ($this->isOrderTicketInfo()) {
							$this->outputOrderTicketsInfos();
						} else {
							$this->outputTicketInfo();
							$order = $this->getOrder();
							if ($order != null) {
								$this->setOrderStatusAfterViewOperation($order);
							}
						}
					}
				} catch(Exception $e) {
					echo '<h1 style="color:red;">Error</h1>';
					echo $e->getMessage();
				}
			}

			echo '</div>';
			echo '</div>';

			if ($hasError || $this->MAIN->getOptions()->isOptionCheckboxActive('brandingHideFooter') == false) {
				get_footer();
			}
		} else {
			get_header();
			echo '<h1 style="color:red;">'.esc_html__('No WooCommerce Support Found', 'event-tickets-with-ticket-scanner').'</h1>';
			echo '<p>'.esc_html__('Please contact us for a solution.', 'event-tickets-with-ticket-scanner').'</p>';
			get_footer();
		}
	}
}
?>