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
	private $onlyLoggedInScannerAllowed = false;

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
			$sasoEventtickets = new sasoEventtickets();
		}
		$this->MAIN = $sasoEventtickets;

		$this->setRequestURI($request_uri);
		$this->onlyLoggedInScannerAllowed = $this->MAIN->getOptions()->isOptionCheckboxActive('wcTicketOnlyLoggedInScannerAllowed') ? true : false;
		//load_plugin_textdomain('event-tickets-with-ticket-scanner', false, 'event-tickets-with-ticket-scanner/languages');
		if ($this->MAIN->getOptions()->isOptionCheckboxActive('wcTicketActivateOBFlush')) {
			/**
			 * Proper ob_end_flush() for all levels
			 * This replaces the WordPress `wp_ob_end_flush_all()` function
			 * with a replacement that doesn't cause PHP notices.
			 */
			remove_action( 'shutdown', 'wp_ob_end_flush_all', 1 );
			add_action( 'shutdown', function() {
				while ( ob_get_level() > 0 ) {
					@ob_end_flush();
				}
			} );
		}
	}

	public function setRequestURI($request_uri) {
		$this->request_uri = trim($request_uri);
	}

	public function cronJobDaily() {
		$this->hideAllTicketProductsWithExpiredEndDate();
		$this->checkForPremiumSerialExpiration();
		do_action( $this->MAIN->_do_action_prefix.'ticket_cronJobDaily' );
	}

	public function get_expiration() {
		$option_name = $this->MAIN->getPrefix()."_premium_serial_expiration";
		$info = get_option( $option_name );
		$info_obj = ["last_run"=>0, "timestamp"=>0, "expiration_date"=>"", "timezone"=>""]; // expiration_date is only for display
		if (!empty($info)) {
			$info_obj = array_merge($info_obj, json_decode($info, true));
		}
		$info_obj = apply_filters( $this->MAIN->_add_filter_prefix.'ticket_get_expiration', $info_obj );
		return $info_obj;
	}

	private function checkForPremiumSerialExpiration() {
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
				if (!empty($serial)) {
					$domain = parse_url( get_site_url(), PHP_URL_HOST );

					$url = "https://vollstart.com/plugins/event-tickets-with-ticket-scanner-premium/"
								.'?checking_for_updates=2&ver='.SASO_EVENTTICKETS_PREMIUM_PLUGIN_VERSION
								."&m=".get_option('admin_email')
								."&d=".$domain
								."&serial=".urlencode($serial);

					$response = wp_remote_get($url, ['timeout' => 45]);
					if (is_wp_error($response)) {
					} else {
						$body = wp_remote_retrieve_body( $response );
						$data = json_decode( $body, true );
						if (isset($data["isCheckCall"]) && $data["isCheckCall"] == 1) {
							// store it get_option( self::$_dbprefix."db_version" ); update_option( self::$_dbprefix."db_version", $this->dbversion );
							$info_obj["last_run"] = time();
							$info_obj = array_merge($data, $info_obj);
							$value = $this->getCore()->json_encode_with_error_handling($info_obj);
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
					array(
						'key' => '_visibility',
						'value' => array('catalog', 'visible'), // Produkte, die nicht als "Privat" gelten
						'compare' => 'IN',
					),
				),
			);

			$products = get_posts($products_args);
			// Ergebnisse überprüfen
			if ($products && function_exists("wp_update_post")) {
				foreach ($products as $product) {
					// check if ticket
					$product_id = $product->ID; //$product->get_id();
					if ($this->MAIN->getWC()->isTicketByProductId($product_id) ) {
						// check if event date end is set
						$dates = $this->calcDateStringAllowedRedeemFrom($product_id);
						if (!empty($dates['ticket_end_date_orig'])) { // only if end date is also set
							// check if expired - non premium
							if ($dates['ticket_end_date_timestamp'] < $dates['server_time_timestamp']) {
								// set product to hidden
								$product_data = array(
									'ID' => $product_id,
									'post_status' => 'private', // Setzen Sie den Status auf 'private'
								);
								wp_update_post($product_data);
							}
						}
					}
				}
			}

			do_action( $this->MAIN->_do_action_prefix.'ticket_hideAllTicketProductsWithExpiredEndDate', $products );
		}
	}

	function rest_permission_callback(WP_REST_Request $web_request) {
		// check ip brute force attack?????

		$ret = false;
		// check if request contains authtoken var
		if ($web_request->has_param($this->MAIN->getAuthtokenHandler()::$authtoken_param)) {
			$authHandler = $this->MAIN->getAuthtokenHandler();
			$this->authtoken = $web_request->get_param($authHandler::$authtoken_param);
			$ret = $authHandler->checkAccessForAuthtoken($this->authtoken);
		} else {
			$allowed_role = $this->MAIN->getOptions()->getOptionValue('wcTicketScannerAllowedRoles');
			if (!$this->onlyLoggedInScannerAllowed && $allowed_role == "-") return true;
			$user = wp_get_current_user();
			$user_roles = (array) $user->roles;
			if ($this->onlyLoggedInScannerAllowed && in_array("administrator", $user_roles)) return true;
			if ($allowed_role != "-") {
				if (in_array($allowed_role, $user_roles)) $ret = true;
			}
		}
		$ret = apply_filters( $this->MAIN->_add_filter_prefix.'ticket_rest_permission_callback', $ret, $web_request );
		return $ret;
	}
	function rest_ping(WP_REST_Request $web_request=null) {
		return ['time'=>current_time("timestamp"), 'img_pfad'=>plugins_url( "img/",__FILE__ ), '_ret'=>['_server'=>$this->getTimes()] ];
	}
	function rest_helper_tickets_redeemed($codeObj) {
		$metaObj = $metaObj = $codeObj['metaObj'];
		$ret = [];
		$ret['tickets_redeemed'] = 0;
		$ret['tickets_redeemed_show'] = false;
		if ($this->MAIN->getOptions()->isOptionCheckboxActive('wcTicketDisplayRedeemedAtScanner') == false) {
			$ret['tickets_redeemed_show'] = true;
			if (isset($metaObj['woocommerce']) && isset($metaObj['woocommerce']['product_id'])) {
				if ($this->MAIN->isPremium() && method_exists($this->MAIN->getPremiumFunctions(), 'getTicketStats')) {
					if (method_exists($this->MAIN->getPremiumFunctions()->getTicketStats(), 'getEntryAmountForProductId')) {
						$ret['tickets_redeemed'] = $this->MAIN->getPremiumFunctions()->getTicketStats()->getEntryAmountForProductId($metaObj['woocommerce']['product_id']);
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
		if (!isset($_GET['code'])) {
			return wp_send_json_error(esc_html__("code missing", 'event-tickets-with-ticket-scanner'));
		}
		$code = trim($_GET['code']);
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

		$this->isProductAllowedByAuthToken([$product->get_id()]);

		if ($this->MAIN->getOptions()->isOptionCheckboxActive('wcTicketScanneCountRetrieveAsConfirmed')) {
			$codeObj = $this->MAIN->getFrontend()->countConfirmedStatus($codeObj, true);
			$metaObj = $codeObj['metaObj'];
		}

		if (!isset($metaObj["wc_ticket"]["_public_ticket_id"])) $metaObj["wc_ticket"]["_public_ticket_id"] = "";
		do_action( $this->MAIN->_do_action_prefix.'trackIPForTicketScannerCheck', array_merge($codeObj, ["_data_code"=>$metaObj["wc_ticket"]["_public_ticket_id"]]) );

		$saso_eventtickets_is_date_for_all_variants = true;
		if ($is_variation && $product_parent_id > 0) {
			$product_parent = $this->get_product( $product_parent_id );
			$saso_eventtickets_is_date_for_all_variants = get_post_meta( $product_parent->get_id(), 'saso_eventtickets_is_date_for_all_variants', true ) == "yes" ? true : false;
		}

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
		$ret['ticket_heading'] = esc_html($this->getAdminSettings()->getOptionValue("wcTicketHeading"));
		$ret['ticket_title'] = esc_html($product_parent->get_Title());
		$ret['ticket_sub_title'] = "";
		if ($is_variation && $this->MAIN->getOptions()->isOptionCheckboxActive('wcTicketPDFDisplayVariantName') && count($product->get_attributes()) > 0) {
			foreach($product->get_attributes() as $k => $v){
				$ret['ticket_sub_title'] .= $v." ";
			}
		}
		$ret['ticket_location'] = trim(get_post_meta( $product_parent->get_id(), 'saso_eventtickets_event_location', true ));
		$ret['ticket_location_label'] = wp_kses_post($this->getAdminSettings()->getOptionValue("wcTicketTransLocation"));

		$tmp_product = $product_parent;
		if (!$saso_eventtickets_is_date_for_all_variants) $tmp_product = $product; // unter Umständen die Variante

		$ret = array_merge($ret, $this->calcDateStringAllowedRedeemFrom($tmp_product->get_id()));

		$ret['ticket_date_as_string'] = $this->displayTicketDateAsString($tmp_product, $this->MAIN->getOptions()->getOptionDateFormat(), $this->MAIN->getOptions()->getOptionTimeFormat());
		$ret['short_desc'] = "";
		if ($this->MAIN->getOptions()->isOptionCheckboxActive('wcTicketDisplayShortDesc')) {
			$ret['short_desc'] = wp_kses_post(trim($product->get_short_description()));
		}
		$ret['ticket_info'] = wp_kses_post(nl2br(trim(get_post_meta( $product_parent->get_id(), 'saso_eventtickets_ticket_is_ticket_info', true ))));
		$ret['cst_label'] = "";
		$ret['cst_billing_address'] = "";
		if (!$this->MAIN->getOptions()->isOptionCheckboxActive('wcTicketDontDisplayCustomer')) {
			$ret['cst_label'] = wp_kses_post($this->getAdminSettings()->getOptionValue("wcTicketTransCustomer"));
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
			$ret['payment_label'] = wp_kses_post(trim($this->getAdminSettings()->getOptionValue("wcTicketTransPaymentDetail")));
			$ret['payment_paid_at_label'] = wp_kses_post($this->getAdminSettings()->getOptionValue("wcTicketTransPaymentDetailPaidAt"));
			$ret['payment_completed_at_label'] = wp_kses_post($this->getAdminSettings()->getOptionValue("wcTicketTransPaymentDetailCompletedAt"));
			$ret['payment_paid_at'] = $order->get_date_paid() != null ? date($date_time_format, strtotime($order->get_date_paid())) : "-";
			$ret['payment_completed_at'] = $order->get_date_completed() != null ? date($date_time_format, strtotime($order->get_date_completed())) : "-";
			$payment_method = $order->get_payment_method_title();
			if (!empty($payment_method)) {
				$ret['payment_method_label'] = wp_kses_post($this->getAdminSettings()->getOptionValue("wcTicketTransPaymentDetailPaidVia"));
				$ret['payment_method'] = esc_html($payment_method);
				$ret['payment_trx_id'] = esc_html($order->get_transaction_id());
			} else {
				$ret['payment_method_label'] = wp_kses_post($this->getAdminSettings()->getOptionValue("wcTicketTransPaymentDetailFreeTicket"));
			}
			$coupons = $order->get_coupon_codes();
			if (count($coupons) > 0) {
				$ret['coupon_label'] = wp_kses_post($this->getAdminSettings()->getOptionValue("wcTicketTransPaymentDetailCouponUsed"));
				$ret['coupon'] = esc_html(implode(", ", $coupons));
			}
		}
		$ret['product'] = [];
		$ret['product']['id'] = $product->get_id();
		$ret['product']['parent_id'] = $product_parent->get_id();
		$ret['product']['name'] = esc_html($product_parent->get_Title());
		$ret['product']['name_variant'] = "";
		if ($is_variation && $this->MAIN->getOptions()->isOptionCheckboxActive('wcTicketPDFDisplayVariantName') && count($product->get_attributes()) > 0) {
			foreach($product->get_attributes() as $k => $v){
				$ret['product']['name_variant'] .= $v." ";
			}
		}
		$ret['product']['sku'] = esc_html($product->get_sku());
		$ret['product']['type'] = esc_html($product->get_type());

		$label = esc_attr($this->getLabelNamePerTicket($product_parent->get_id()));
		$order_quantity = $order_item->get_quantity();
		$ticket_pos = "";
		if ($order_quantity > 1) {
			// ermittel ticket pos
			$codes = explode(",", $order_item->get_meta('_saso_eventtickets_product_code', true));
			$ticket_pos = $this->ermittelCodePosition($codeObj['code_display'], $codes);
		}
		$ret['name_per_ticket_label'] = str_replace("{count}", $ticket_pos, $label);

		$label = esc_attr($this->getLabelValuePerTicket($product_parent->get_id()));
		$ticket_pos = "";
		if ($order_quantity > 1) {
			// ermittel ticket pos
			$codes = explode(",", $order_item->get_meta('_saso_eventtickets_product_code', true));
			$ticket_pos = $this->ermittelCodePosition($codeObj['code_display'], $codes);
		}
		$ret['value_per_ticket_label'] = str_replace("{count}", $ticket_pos, $label);

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
		$ret['ticket_label'] = wp_kses_post($this->getAdminSettings()->getOptionValue("wcTicketTransTicket"));
		$paid_price = $order_item->get_subtotal() / $order_item->get_quantity();
		$ret['paid_price_label'] = wp_kses_post($this->getAdminSettings()->getOptionValue("wcTicketTransPrice"));
		$ret['paid_price'] = floatval($paid_price);
		$ret['paid_price_as_string'] = function_exists("wc_price") ? wc_price($paid_price, ['decimals'=>2]) : $paid_price;
		$product_price = $product->get_price();
		$ret['product_price_label'] = wp_kses_post($this->getAdminSettings()->getOptionValue("wcTicketTransProductPrice"));
		$ret['product_price'] = floatval($product_price);
		$ret['product_price_as_string'] = function_exists("wc_price") ? wc_price($product_price, ['decimals'=>2]) : $product_price;

		$ret['msg_redeemed'] = wp_kses_post($this->getAdminSettings()->getOptionValue("wcTicketTransTicketRedeemed"));
		$ret['redeemed_date_label'] = wp_kses_post($this->getAdminSettings()->getOptionValue("wcTicketTransRedeemDate"));
		$ret['msg_ticket_valid'] = wp_kses_post($this->getAdminSettings()->getOptionValue("wcTicketTransTicketValid"));
		$ret['msg_ticket_expired'] = wp_kses_post($this->getAdminSettings()->getOptionValue("wcTicketTransTicketExpired"));

		$ret['msg_ticket_not_valid_yet'] = wp_kses_post($this->getAdminSettings()->getOptionValue("wcTicketTransTicketNotValidToEarly"));
		$ret['msg_ticket_not_valid_anymore'] = wp_kses_post($this->getAdminSettings()->getOptionValue("wcTicketTransTicketNotValidToLate"));
		$ret['msg_ticket_event_ended'] = wp_kses_post($this->getAdminSettings()->getOptionValue("wcTicketTransTicketNotValidToLateEndEvent"));


		$ret['max_redeem_amount'] = intval(get_post_meta( $product_parent->get_id(), 'saso_eventtickets_ticket_max_redeem_amount', true ));
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
			"time"=>date("Y-m-d H:i:s", current_time("timestamp")),
			"timestamp"=>current_time("timestamp"),
			"UTC_time"=>$dt->format("Y-m-d H:i:s"),
			"timezone"=>wp_timezone()
		];
	}
	function rest_redeem_ticket(WP_REST_Request $web_request) {
		if (!isset($_REQUEST['code'])) wp_send_json_error(esc_html__("code missing", 'event-tickets-with-ticket-scanner'));
		$ret = null;
		if ($this->is_ticket_code_orderticket($_REQUEST['code'])) {
			$ret = $this->redeem_order_ticket($_REQUEST['code']);
		}
		if ($ret == null) {
			$ret = $this->redeem_ticket($_REQUEST['code']);
		}
		$ret = apply_filters( $this->MAIN->_add_filter_prefix.'ticket_rest_redeem_ticket', $ret, $web_request );
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
					$codeObj = $this->getCore()->retrieveCodeByCode($code);
					$metaObj = $this->getCore()->encodeMetaValuesAndFillObject($codeObj['meta'], $codeObj);
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
		$ticket_id = $this->getCore()->getTicketId($codeObj, $metaObj);

		$ret = ['redeem_successfully'=>$this->redeem_successfully, 'ticket_id'=>$ticket_id];
		$ret["_ret"] = $this->rest_helper_tickets_redeemed($codeObj);

		$ret = apply_filters( $this->MAIN->_add_filter_prefix.'ticket_redeem_ticket', $ret, $code, $codeObj );

		return $ret;
	}

	function calcDateStringAllowedRedeemFrom($product_id) {
		$ret = [];
		$ret['ticket_start_date'] = trim(get_post_meta( $product_id, 'saso_eventtickets_ticket_start_date', true ));
		$ret['ticket_start_time'] = trim(get_post_meta( $product_id, 'saso_eventtickets_ticket_start_time', true ));
		$ret['ticket_end_date'] = trim(get_post_meta( $product_id, 'saso_eventtickets_ticket_end_date', true ));
		$ret['ticket_end_date_orig'] = $ret['ticket_end_date'];
		$ret['ticket_end_time'] = trim(get_post_meta( $product_id, 'saso_eventtickets_ticket_end_time', true ));
		$ret['ticket_start_date_timestamp'] = current_time("timestamp");
		$ret['ticket_end_date_timestamp'] = 0;
		$ret['is_date_set'] = true;
		if (empty($ret['ticket_start_date']) && empty($ret['ticket_start_time'])) { // date not set
			$ret['is_date_set'] = false;
		}
		if (empty($ret['ticket_start_date']) && !empty($ret['ticket_start_time'])) {
			$ret['ticket_start_date'] = current_time("timestamp");
		}
		if (!empty($ret['ticket_start_date'])) {
			$ret['ticket_start_date_timestamp'] = strtotime(trim($ret['ticket_start_date']." ".$ret['ticket_start_time']));
		}
		if (empty($ret['ticket_end_date']) && !empty($ret['ticket_end_time'])) {
			$ret['ticket_end_date'] = $ret['ticket_start_date'];
		}
		$ticket_end_time = $ret['ticket_end_time'];
		if (empty($ticket_end_time)) {
			$ticket_end_time = "23:59:59";
		}
		$ret['ticket_end_date_timestamp'] = strtotime(trim($ret['ticket_end_date']." ".$ticket_end_time));

		$redeem_allowed_from = current_time("timestamp");
		if ($this->MAIN->getOptions()->isOptionCheckboxActive('wcTicketDontAllowRedeemTicketBeforeStart')) {
			$time_offset = intval($this->getAdminSettings()->getOptionValue("wcTicketOffsetAllowRedeemTicketBeforeStart"));
			if ($time_offset < 0) $time_offset = 0;
			//$offset  = (float) get_option( 'gmt_offset' ); // timezone offset
			//$redeem_allowed_from = $ret['ticket_start_date_timestamp'] - ($time_offset * 3600) - ($offset * 3600);
			//if ($offset > 0)  $redeem_allowed_from -= ($offset * 3600);
			//else $redeem_allowed_from += ($offset * 3600);
			$redeem_allowed_from = $ret['ticket_start_date_timestamp'] - ($time_offset * 3600);
		}
		$ret['redeem_allowed_from'] = date("Y-m-d H:i", $redeem_allowed_from); // here without the timezone
		$ret['redeem_allowed_from_timestamp'] = $redeem_allowed_from;
		$ret['redeem_allowed_until'] = date("Y-m-d H:i:s", $ret['ticket_end_date_timestamp']); // here without the timezone
		$ret['redeem_allowed_until_timestamp'] = $ret['ticket_end_date_timestamp'];
		$ret['server_time_timestamp'] = current_time("timestamp"); // timezone is removed or added
		$ret['redeem_allowed_too_late'] = $ret['ticket_end_date_timestamp'] < $ret['server_time_timestamp'];
		$ret['server_time'] = date("Y-m-d H:i:s", current_time("timestamp")); // normal timestamp, because the function will do it the add/remove timezone also
		$ret = apply_filters( $this->MAIN->_add_filter_prefix.'ticket_calcDateStringAllowedRedeemFrom', $ret, $product_id );
		return $ret;
	}

	public function getLabelNamePerTicket($product_id) {
		$t = trim(get_post_meta($product_id, "saso_eventtickets_request_name_per_ticket_label", true));
        if (empty($t)) $t = "Name for the ticket #{count}:";
		return $t;
	}
	public function getLabelValuePerTicket($product_id) {
		$t = trim(get_post_meta($product_id, "saso_eventtickets_request_value_per_ticket_label", true));
        if (empty($t)) $t = "Please choose a value #{count}:";
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

	private function getCore() {
		return $this->MAIN->getCore();
	}
	private function getAdminSettings() {
		return $this->MAIN->getAdmin();
	}
	private function getOptions() {
		return $this->MAIN->getOptions();
	}

	public function isScanner() {
		// /wp-content/plugins/event-tickets-with-ticket-scanner/ticket/scanner/
		if ($this->isScanner == null) {
			if ($this->onlyLoggedInScannerAllowed) {
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
					$this->parts =  $this->getCore()->getTicketURLComponents($uri);
				}
			} else {
				$this->parts =  $this->getCore()->getTicketURLComponents($this->request_uri);
			}
		}
		return $this->parts;
	}

	static public function generateICSFile($product) {
		$product_id = $product->get_id();
		$titel = $product->get_name();
		$short_desc = "";

		global $sasoEventtickets;
		if (isset($sasoEventtickets)) {
			if ($sasoEventtickets->getOptions()->isOptionCheckboxActive('wcTicketDisplayShortDesc')) {
				$short_desc .= trim($product->get_short_description());
			}
		}

		$tzid = wp_timezone_string();
		//$tzid_text = empty($tzid) ? '' : ';TZID="'.wp_timezone_string().'":';

		$ticket_info = trim(get_post_meta( $product->get_id(), 'saso_eventtickets_ticket_is_ticket_info', true ));
		if (!empty($short_desc) && !empty($ticket_info)) $short_desc .= "\n\n";
		$short_desc .= trim($ticket_info);
		$ticket_start_date = trim(get_post_meta( $product_id, 'saso_eventtickets_ticket_start_date', true ));
		$ticket_start_time = trim(get_post_meta( $product_id, 'saso_eventtickets_ticket_start_time', true ));
		$ticket_end_date = trim(get_post_meta( $product_id, 'saso_eventtickets_ticket_end_date', true ));
		$ticket_end_time = trim(get_post_meta( $product_id, 'saso_eventtickets_ticket_end_time', true ));

		if (empty($ticket_start_date) && !empty($ticket_start_time)) {
			$ticket_start_date = date("Y-m-d", current_time("timestamp"));
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
			$DTSTART_line .= ":".date("Ymd\THis", $start_timestamp);
			$DTEND_line .= ":".date("Ymd\THis", $end_timestamp);
		}

		$LOCATION = trim(get_post_meta( $product_id, 'saso_eventtickets_event_location', true ));

		$temp = wp_kses_post(str_replace(array("\r\n", "<br>"),"\n",$short_desc));
		$lines = explode("\n",$temp);
		$new_lines =array();
		foreach($lines as $i => $line) {
			if(!empty($line))
			$new_lines[]=trim($line);
		}
		$desc = implode("\r\n ",$new_lines);

		$event_url = get_permalink( $product->get_id() );
		$uid = $product_id."-".date("Y-m-d-H-i-s", current_time("timestamp"))."-".get_site_url();

		$wcTicketICSOrganizerEmail = trim($sasoEventtickets->getOptions()->getOptionValue("wcTicketICSOrganizerEmail"));

		$ret = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//hacksw/handcal//NONSGML v1.0//EN\r\nBEGIN:VEVENT\r\n";
		$ret .= "UID:".$uid."\r\n";
		if ($wcTicketICSOrganizerEmail != "") {
			$ret .= "ORGANIZER;CN=".trim(wp_kses_post(str_replace(":", " ", get_bloginfo('name')))).":mailto:".$wcTicketICSOrganizerEmail."\r\n";
		}
		$ret .= "LOCATION:".htmlentities($LOCATION)."\r\n";
		//$ret .= "DTSTAMP:".gmdate("Ymd\THis")."\r\n";
		$ret .= "DTSTAMP:".date("Ymd\THis")."\r\n";
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
			$this->codeObj = $this->getCore()->setMetaObj($this->codeObj);
			return $this->codeObj;
		}
		$codeObj = $this->getCore()->retrieveCodeByCode($this->getParts($code)['code']);
		if ($codeObj['aktiv'] == 2) throw new Exception("#8005 ".esc_html($this->getAdminSettings()->getOptionValue("wcTicketTransTicketIsStolen")));
		if ($codeObj['aktiv'] != 1) throw new Exception("#8006 ".esc_html($this->getAdminSettings()->getOptionValue("wcTicketTransTicketNotValid")));
		$metaObj = $this->getCore()->encodeMetaValuesAndFillObject($codeObj['meta'], $codeObj);
		$codeObj["metaObj"] = $metaObj;

		// check ob order_id stimmen
		if ($this->getParts($code)['order_id'] != $codeObj['order_id']) throw new Exception("#8001 ".esc_html($this->getAdminSettings()->getOptionValue("wcTicketTransTicketNumberWrong")));
		// check idcode
		if ($this->getParts($code)['idcode'] != $metaObj['wc_ticket']['idcode']) throw new Exception("#8006 ".esc_html($this->getAdminSettings()->getOptionValue("wcTicketTransTicketNumberWrong")));
		// check ob serial ein ticket ist
		if ($metaObj['wc_ticket']['is_ticket'] != 1) throw new Exception("#8002 ".esc_html($this->getAdminSettings()->getOptionValue("wcTicketTransTicketNotValid")));
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
		//get_header();
		echo '<html><head>';
		?>
		<style>
            body {font-family: Helvetica, Arial, sans-serif;}
            h3,h4,h5 {padding-bottom:0.5em;margin-bottom:0;}
            p {padding:0;margin:0;margin-bottom:1em;}
			div.ticket_content p {font-size:initial !important;margin-bottom:1em !important;}
            button {padding:10px;font-size: 1.5em;}
            .lds-dual-ring {display:inline-block;width:64px;height:64px;}
            .lds-dual-ring:after {content:" ";display:block;width:46px;height:46px;margin:1px;border-radius:50%;border:5px solid #fff;border-color:#2e74b5 transparent #2e74b5 transparent;animation:lds-dual-ring 0.6s linear infinite;}
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

				$ticket_id = $this->getCore()->getTicketId($codeObj, $metaObj);
				$ticket_start_date = trim(get_post_meta( $metaObj['woocommerce']['product_id'], 'saso_eventtickets_ticket_start_date', true ));
				$ticket_start_time = trim(get_post_meta( $metaObj['woocommerce']['product_id'], 'saso_eventtickets_ticket_start_time', true ));
				$ticket_end_date = trim(get_post_meta( $metaObj['woocommerce']['product_id'], 'saso_eventtickets_ticket_end_date', true ));
				$ticket_end_time = trim(get_post_meta( $metaObj['woocommerce']['product_id'], 'saso_eventtickets_ticket_end_time', true ));
				if (empty($ticket_start_date) && !empty($ticket_start_time)) {
					$ticket_start_date = date("Y-m-d", current_time("timestamp"));
				}
				if (empty($ticket_end_date) && !empty($ticket_end_time)) {
					$ticket_end_date = $ticket_start_date;
				}
				$ticket_end_date_timestamp = strtotime($ticket_end_date." ".$ticket_end_time);
				$color = 'green';
				if ($ticket_end_date != "" && $ticket_end_date_timestamp < current_time("timestamp")) {
					$color = 'orange';
				}
				if (!empty($metaObj['wc_ticket']['redeemed_date'])) {
					$color = 'red';
				}

				if (isset($_POST['action']) && $_POST['action'] == "redeem") {
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
		$this->sendICSFileByProductId($product_id);
	}

	public function sendICSFileByProductId($product_id) {
		$product = $this->get_product( $product_id );
		$contents = self::generateICSFile($product);
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
						$codeObj = $this->getCore()->retrieveCodeByCode($code);
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
						$codeObj = $this->getCore()->retrieveCodeByCode($code);
					} catch (Exception $e) {
						continue;
					}
					$this->setCodeObj($codeObj);
					// attach PDF
					$filepaths[] = $this->outputPDF("F");
				}
				if ($filename == null) {
					$filename = "tickets_".date("Ymd_Hi", current_time("timestamp")).".pdf";
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
							$codeObj = $this->getCore()->retrieveCodeByCode($code);
						} catch (Exception $e) {
							continue;
						}
						$this->setCodeObj($codeObj);
						// attach PDF
						$filepaths[] = $badgeHandler->getPDFTicketBadgeFilepath($codeObj, $dirname);
					}
					if ($filename == null) {
						$filename = "ticketsbadges_".date("Ymd_Hi", current_time("timestamp")).".pdf";
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
		$ticket_id = $this->getCore()->getTicketId($codeObj, $metaObj);
		$order_item = $this->getOrderItem($order, $metaObj);
		if ($order_item == null) throw new Exception("#8013 ".esc_html__("Order item not found for the PDF ticket", 'event-tickets-with-ticket-scanner'));

		if ($filemode == "I") {
			do_action( $this->MAIN->_do_action_prefix.'trackIPForPDFView', $codeObj );
		}

		$product = $order_item->get_product();
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
		//if (get_post_meta( $metaObj['woocommerce']['product_id'], 'saso_eventtickets_ticket_is_RTL', true ) == "yes") {
			//$pdf->setRTL(true);
		//}
		if (isset($_REQUEST["testDesigner"]) && $this->getOptions()->isOptionCheckboxActive('wcTicketPDFisRTLTest')) {
			$pdf->setRTL(true);
		} else if($this->getOptions()->isOptionCheckboxActive('wcTicketPDFisRTL')) {
			$pdf->setRTL(true);
		}

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
		if (isset($_REQUEST["testDesigner"])) {
			if ($this->getOptions()->isOptionCheckboxActive('wcTicketPDFZeroMarginTest')) {
				$marginZero = true;
			}
		} else {
			if ($this->getOptions()->isOptionCheckboxActive('wcTicketPDFZeroMargin')) {
				$marginZero = true;
			}
		}
		$pdf->marginsZero = $marginZero;

		$width = 210;
        $height = 297;
		$qr_code_size = 0; // takes default then
		if (isset($_REQUEST["testDesigner"])) {
			$width = $this->MAIN->getOptions()->getOptionValue("wcTicketSizeWidthTest", 0);
			$height = $this->MAIN->getOptions()->getOptionValue("wcTicketSizeHeightTest", 0);
			$qr_code_size = intval($this->MAIN->getOptions()->getOptionValue("wcTicketQRSizeTest", 0));
		} else {
			$width = $this->MAIN->getOptions()->getOptionValue("wcTicketSizeWidth", 0);
			$height = $this->MAIN->getOptions()->getOptionValue("wcTicketSizeHeight", 0);
			$qr_code_size = intval($this->MAIN->getOptions()->getOptionValue("wcTicketQRSize", 0));
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

		$wcTicketTicketBanner = $this->getAdminSettings()->getOptionValue('wcTicketTicketBanner');
		$wcTicketTicketBanner = apply_filters( $this->MAIN->_add_filter_prefix.'wcTicketTicketBanner', $wcTicketTicketBanner, $product_id);
		if (!empty($wcTicketTicketBanner) && intval($wcTicketTicketBanner) > 0) {
			//$option_wcTicketTicketBanner = $this->getOptions()->getOption('wcTicketTicketBanner');
			$mediaData = SASO_EVENTTICKETS::getMediaData($wcTicketTicketBanner);
			/*$width = "600";
			if (isset($option_wcTicketTicketBanner['additional']) && isset($option_wcTicketTicketBanner['additional']['min']) && isset($option_wcTicketTicketBanner['additional']['min']['width'])) {
				$width = $option_wcTicketTicketBanner['additional']['min']['width'];
			}*/
			//if (!empty($mediaData['location']) && file_exists($mediaData['location'])) {
			if (!empty($mediaData['for_pdf'])) {
				$pdf->addPart('<div style="text-align:center;"><img src="'.$mediaData['for_pdf'].'"></div>');
				if (isset($mediaData['meta']) && isset($mediaData['meta']['height']) && floatval($mediaData['meta']['height']) > 0) {
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
		}

		/* old approach
		$pdf->addPart('<h1 style="font-size:20pt;text-align:center;">'.htmlentities($this->getAdminSettings()->getOptionValue("wcTicketHeading")).'</h1>');
		$pdf->addPart('{QRCODE_INLINE}');
		$pdf->addPart("<style>h4{font-size:16pt;} table.ticket_content_upper {width:14cm;padding-top:10pt;} table.ticket_content_upper td {height:5cm;}</style>".$html);
		$pdf->addPart('<br><br><p style="text-align:center;">'.$ticket_id.'</p>');
		*/

		if (strpos(" ".$html,"{QRCODE_INLINE}") > 0 || strpos(" ".$html,"{QRCODE}") > 0) {
		} else {
			$pdf->addPart('{QRCODE}');
		}

		$pdf->addPart($html);

		$wcTicketDontDisplayBlogName = $this->getOptions()->isOptionCheckboxActive('wcTicketDontDisplayBlogName');
		if (!$wcTicketDontDisplayBlogName) {
			$pdf->addPart('<br><br><div style="text-align:center;font-size:10pt;"><b>'.wp_kses_post(get_bloginfo("name")).'</b></div>');
		}
		$wcTicketDontDisplayBlogDesc = $this->getOptions()->isOptionCheckboxActive('wcTicketDontDisplayBlogDesc');
		if (!$wcTicketDontDisplayBlogDesc) {
			if ($wcTicketDontDisplayBlogName) $pdf->addPart('<br>');
			$pdf->addPart('<div style="text-align:center;font-size:10pt;">'.wp_kses_post(get_bloginfo("description")).'</div>');
		}
		if (!$this->getOptions()->isOptionCheckboxActive('wcTicketDontDisplayBlogURL')) {
			$pdf->addPart('<br><div style="text-align:center;font-size:10pt;">'.site_url().'</div>');
		}

		$wcTicketTicketLogo = $this->getAdminSettings()->getOptionValue('wcTicketTicketLogo');
		$wcTicketTicketLogo = apply_filters( $this->MAIN->_add_filter_prefix.'wcTicketTicketLogo', $wcTicketTicketLogo, $product_id);
		if (!empty($wcTicketTicketLogo) && intval($wcTicketTicketLogo) >0) {
			$option_wcTicketTicketLogo = $this->getOptions()->getOption('wcTicketTicketLogo');
			$mediaData = SASO_EVENTTICKETS::getMediaData($wcTicketTicketLogo);
			$width = "200";
			if (isset($option_wcTicketTicketLogo['additional']) && isset($option_wcTicketTicketLogo['additional']['max']) && isset($option_wcTicketTicketLogo['additional']['max']['width'])) {
				$width = $option_wcTicketTicketLogo['additional']['max']['width'];
			}
			//if (!empty($mediaData['location']) && file_exists($mediaData['location'])) {
			if (!empty($mediaData['for_pdf'])) {
				$pdf->addPart('<br><br><p style="text-align:center;"><img width="'.$width.'" src="'.$mediaData['for_pdf'].'"></p>');
			}
		}
		$brandingHidePluginBannerText = $this->getOptions()->isOptionCheckboxActive('brandingHidePluginBannerText');
		if ($brandingHidePluginBannerText == false) {
			$pdf->addPart('<br><p style="text-align:center;font-size:6pt;">"Event Tickets With Ticket Scanner Plugin" for Wordpress</p>');
		}

		$wcTicketTicketBG = $this->getAdminSettings()->getOptionValue('wcTicketTicketBG');
		$wcTicketTicketBG = apply_filters( $this->MAIN->_add_filter_prefix.'wcTicketTicketBG', $wcTicketTicketBG, $product_id);
		if (!empty($wcTicketTicketBG) && intval($wcTicketTicketBG) >0) {
			$mediaData = SASO_EVENTTICKETS::getMediaData($wcTicketTicketBG);
			//if (!empty($mediaData['location']) && file_exists($mediaData['location'])) {
			if (!empty($mediaData['for_pdf'])) {
				$pdf->setBackgroundImage($mediaData['for_pdf']);
			}
		}

		$wcTicketTicketAttachPDFOnTicket = $this->getAdminSettings()->getOptionValue('wcTicketTicketAttachPDFOnTicket');
		if (!empty($wcTicketTicketAttachPDFOnTicket)) {
			$mediaData = SASO_EVENTTICKETS::getMediaData($wcTicketTicketAttachPDFOnTicket);
			if (!empty($mediaData['location']) && file_exists($mediaData['location'])) {
				$pdf->setAdditionalPDFsToAttachThem([$mediaData['location']]);
			}
		}

		$qrCodeContent = $this->getCore()->getQRCodeContent($codeObj);
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

	public static function displayTicketDateAsString($product, $date_format="Y/m/d", $time_format="H:i") {
		$product_id = $product->get_id();
		$ticket_start_date = trim(get_post_meta( $product_id, 'saso_eventtickets_ticket_start_date', true ));
		$ticket_start_time = trim(get_post_meta( $product_id, 'saso_eventtickets_ticket_start_time', true ));
		$ticket_end_date = trim(get_post_meta( $product_id, 'saso_eventtickets_ticket_end_date', true ));
		$ticket_end_time = trim(get_post_meta( $product_id, 'saso_eventtickets_ticket_end_time', true ));
		$ret = "";
		if (empty($ticket_start_date) && !empty($ticket_start_time)) {
			$ticket_start_date = date("Y-m-d", current_time("timestamp"));
		}
		if (empty($ticket_end_date) && !empty($ticket_end_time)) {
			$ticket_end_date = $ticket_start_date;
		}
		if (empty($ticket_end_time) && !empty($ticket_start_time)) $ticket_end_time = "23:59:59";

		if (!empty($ticket_start_date)) {
			$ticket_start_date_timestamp = strtotime($ticket_start_date." ".$ticket_start_time);
			$ticket_end_date_timestamp = strtotime($ticket_end_date." ".$ticket_end_time);
			$ret .= date($date_format, $ticket_start_date_timestamp);
			if (!empty($ticket_start_time)) $ret .= " ".date($time_format, $ticket_start_date_timestamp);
			if (!empty($ticket_end_date) || !empty($ticket_end_time)) $ret .= " - ";
			if (!empty($ticket_end_date)) $ret .= date($date_format, $ticket_end_date_timestamp);
			if (!empty($ticket_end_time)) $ret .= " ".date($time_format, $ticket_end_date_timestamp);
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

			$wcTicketHideDateOnPDF = $this->getOptions()->isOptionCheckboxActive('wcTicketHideDateOnPDF');

			foreach($tickets as $key => $obj) {
				$codes = [];
				if (!empty($obj['codes'])) {
					$codes = explode(",", $obj['codes']);
				}
				foreach($codes as $code) {
					try {
						$codeObj = $this->getCore()->retrieveCodeByCode($code);
					} catch (Exception $e) {
						continue;
					}
					$codeObj = $this->getCore()->setMetaObj($codeObj);
					$metaObj = $codeObj['metaObj'];

					$order_item = $this->getOrderItem($order, $metaObj);
					if ($order_item == null) throw new Exception("#8004 Order not found");
					$product = $order_item->get_product();
					$is_variation = $product->get_type() == "variation" ? true : false;
					$product_parent = $product;
					$product_parent_id = $product->get_parent_id();

					$this->isProductAllowedByAuthToken([$product->get_id()]);

					$saso_eventtickets_is_date_for_all_variants = true;
					if ($is_variation && $product_parent_id > 0) {
						$product_parent = $this->get_product( $product_parent_id );
						$saso_eventtickets_is_date_for_all_variants = get_post_meta( $product_parent->get_id(), 'saso_eventtickets_is_date_for_all_variants', true ) == "yes" ? true : false;
					}
					$location = trim(get_post_meta( $product_parent->get_id(), 'saso_eventtickets_event_location', true ));
					$tmp_product = $product_parent;
					if (!$saso_eventtickets_is_date_for_all_variants) $tmp_product = $product; // unter Umständen die Variante
					$ticket_start_date = trim(get_post_meta( $tmp_product->get_id(), 'saso_eventtickets_ticket_start_date', true ));
					$ticket_start_time = trim(get_post_meta( $tmp_product->get_id(), 'saso_eventtickets_ticket_start_time', true ));
					if (empty($ticket_start_date) && !empty($ticket_start_time)) {
						$ticket_start_date = date("Y-m-d", current_time("timestamp"));
					}

					$ticket_id = $this->getCore()->getTicketId($codeObj, $metaObj);
					$qrCodeContent = $this->getCore()->getQRCodeContent($codeObj, $metaObj);

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
					$ticketObj['location'] = $location == "" ? "" : wp_kses_post($this->getAdminSettings()->getOptionValue("wcTicketTransLocation"))." <b>".wp_kses_post($location)."</b>";
					$ticketObj['ticket_date'] = "";
					if ($wcTicketHideDateOnPDF == false && !empty($ticket_start_date)) {
						$ticketObj['ticket_date'] = self::displayTicketDateAsString($tmp_product, $this->MAIN->getOptions()->getOptionDateFormat(), $this->MAIN->getOptions()->getOptionTimeFormat());
					}
					$ticketObj['name_per_ticket'] = "";
					if (!empty($metaObj['wc_ticket']['name_per_ticket'])) {
						$label = esc_attr($this->getLabelNamePerTicket($product_parent->get_id()));
						$order_quantity = $order_item->get_quantity();
						$ticket_pos = "";
						if ($order_quantity > 1) {
							// ermittel ticket pos
							$codes = explode(",", $order_item->get_meta('_saso_eventtickets_product_code', true));
							$ticket_pos = $this->ermittelCodePosition($codeObj['code_display'], $codes);
						}
						$ticketObj['name_per_ticket'] = str_replace("{count}", $ticket_pos, $label)." ".esc_attr($metaObj['wc_ticket']['name_per_ticket']);
					}
					$ticketObj['value_per_ticket'] = "";
					if (!empty($metaObj['wc_ticket']['value_per_ticket'])) {
						$label = esc_attr($this->getLabelValuePerTicket($product_parent->get_id()));
						$order_quantity = $order_item->get_quantity();
						$ticket_pos = "";
						if ($order_quantity > 1) {
							$codes = explode(",", $order_item->get_meta('_saso_eventtickets_product_code', true));
							$ticket_pos = $this->ermittelCodePosition($codeObj['code_display'], $codes);
						}
						$ticketObj['value_per_ticket'] = str_replace("{count}", $ticket_pos, $label)." ".esc_attr($metaObj['wc_ticket']['value_per_ticket']);
					}

					$ticket_infos[] = $ticketObj;

					$products[$product->get_id()] = [
						"product_id"=>$product->get_id(),
						"product_parent_id"=>$product_parent->get_id(),
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
				"date_created"=>date($option_displayDateTimeFormat, strtotime($order->get_date_created())),
				"date_paid"=>date($option_displayDateTimeFormat, strtotime($order->get_date_paid())),
				"date_completed"=>date($option_displayDateTimeFormat, strtotime($order->get_date_completed())),
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
		$order_infos = $infos["order_infos"];
		$ticket_infos = $infos["ticket_infos"];

		if ($this->getOptions()->isOptionCheckboxActive('wcTicketDisplayDownloadAllTicketsPDFButtonOnOrderdetail')) {
			$url = $this->getCore()->getOrderTicketsURL($order);
			$dlnbtnlabel = $this->getOptions()->getOptionValue('wcTicketLabelPDFDownload');
			$dlnbtnlabelHeading = $this->getOptions()->getOptionValue('wcTicketLabelPDFDownloadHeading');
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
		$codeObj = $this->getCore()->setMetaObj($codeObj);
		$metaObj = $codeObj['metaObj'];

		if ($forPDFOutput == false) {
			do_action( $this->MAIN->_do_action_prefix.'trackIPForTicketView', $codeObj );
		}

		$display_the_ticket = apply_filters( $this->MAIN->_do_action_prefix.'ticket_outputTicketInfo', true, $codeObj, $forPDFOutput );
		do_action( $this->MAIN->_do_action_prefix.'ticket_outputTicketInfo_pre', $display_the_ticket, $codeObj, $forPDFOutput );

		if ($display_the_ticket) {
			$ticketDesigner = $this->MAIN->getTicketDesignerHandler();

			//if (isset($_REQUEST["testDesigner"]) && current_user_can( 'manage_options' ) ) {
			if (isset($_REQUEST["testDesigner"]) && $this->MAIN->isUserAllowedToAccessAdminArea() ) {
				$template = "";
				if (empty($template)) {
					$ticketDesigner->setTemplate($this->getAdminSettings()->getOptionValue("wcTicketDesignerTemplateTest"));
				}
			} else {
				if ($this->MAIN->getOptions()->isOptionCheckboxActive('wcTicketTemplateUseDefault')) {
					$ticketDesigner->setTemplate("");
				} else {
					$ticketDesigner->setTemplate($this->getAdminSettings()->getOptionValue("wcTicketDesignerTemplate"));
				}
			}
			echo $ticketDesigner->renderHTML($codeObj, $forPDFOutput);

			// buttons
			$vars = $ticketDesigner->getVariables();
			$ticket_times = $this->calcDateStringAllowedRedeemFrom($vars["PRODUCT"]->get_id());
			if ($vars["forPDFOutput"] == false) {
				$is_expired = $this->getCore()->checkCodeExpired($codeObj);
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
					echo wp_kses_post($vars["OPTIONS"]["wcTicketTransRedeemDate"]).' '.date($vars["TICKET"]["date_time_format"], strtotime($vars["METAOBJ"]["wc_ticket"]["redeemed_date"]));
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
				if (isset($_REQUEST["displaytime"])) {
					echo '<p>Server time: '.date("Y-m-d H:i", current_time("timestamp")).'</p>';
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
		$codeObj = $this->getCore()->setMetaObj($codeObj);
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
		$is_variation = $product->get_type() == "variation" ? true : false;
		$product_parent = $product;
		$product_parent_id = $product->get_parent_id();
		$tmp_prod = $product;
		$saso_eventtickets_is_date_for_all_variants = true;
		if ($is_variation && $product_parent_id > 0) {
			$product_parent = $this->get_product( $product_parent_id );
			$saso_eventtickets_is_date_for_all_variants = get_post_meta( $product_parent->get_id(), 'saso_eventtickets_is_date_for_all_variants', true ) == "yes" ? true : false;
			if ($saso_eventtickets_is_date_for_all_variants) {
				$tmp_prod = $product_parent;
			}
		}
		$ret = $this->calcDateStringAllowedRedeemFrom($tmp_prod->get_id());
		return $ret['redeem_allowed_from_timestamp'] >= $ret['server_time_timestamp'];
	}
	private function isRedeemOperationTooLateEventEnded($codeObj, $metaObj, $order) {
		$order_item = $this->getOrderItem($order, $metaObj);
		if ($order_item == null) throw new Exception("#8015 ".esc_html__("Can not find the product for this ticket.", 'event-tickets-with-ticket-scanner'));
		$product = $order_item->get_product();
		$is_variation = $product->get_type() == "variation" ? true : false;
		$product_parent = $product;
		$product_parent_id = $product->get_parent_id();
		$tmp_prod = $product;
		$saso_eventtickets_is_date_for_all_variants = true;
		if ($is_variation && $product_parent_id > 0) {
			$product_parent = $this->get_product( $product_parent_id );
			$saso_eventtickets_is_date_for_all_variants = get_post_meta( $product_parent->get_id(), 'saso_eventtickets_is_date_for_all_variants', true ) == "yes" ? true : false;
			if ($saso_eventtickets_is_date_for_all_variants) {
				$tmp_prod = $product_parent;
			}
		}
		$ret = $this->calcDateStringAllowedRedeemFrom($tmp_prod->get_id());
		return $ret['ticket_end_date_timestamp'] <= $ret['server_time_timestamp'];
	}
	private function isRedeemOperationTooLate($codeObj, $metaObj, $order) {
		$order_item = $this->getOrderItem($order, $metaObj);
		$order_item = $this->getOrderItem($order, $metaObj);
		if ($order_item == null) throw new Exception("#8018 ".esc_html__("Can not find the product for this ticket.", 'event-tickets-with-ticket-scanner'));
		$product = $order_item->get_product();
		$is_variation = $product->get_type() == "variation" ? true : false;
		$product_parent = $product;
		$product_parent_id = $product->get_parent_id();
		$tmp_prod = $product;
		$saso_eventtickets_is_date_for_all_variants = true;
		if ($is_variation && $product_parent_id > 0) {
			$product_parent = $this->get_product( $product_parent_id );
			$saso_eventtickets_is_date_for_all_variants = get_post_meta( $product_parent->get_id(), 'saso_eventtickets_is_date_for_all_variants', true ) == "yes" ? true : false;
			if ($saso_eventtickets_is_date_for_all_variants) {
				$tmp_prod = $product_parent;
			}
		}
		$ret = $this->calcDateStringAllowedRedeemFrom($tmp_prod->get_id());
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
	private function redeemTicket($codeObj = null) {
		global $sasoEventtickets;
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
			$sasoEventtickets->getAdmin()->executeJSON('redeemWoocommerceTicketForCode', $data, true);

			$order = $this->setStatusAfterRedeemOperation($order);

			$this->redeem_successfully = true;
			do_action( $this->MAIN->_do_action_prefix.'ticket_redeemTicket', $codeObj, $data );
		}
	}

	private function executeRequestScanner() {
		if (isset($_POST['action']) && $_POST['action'] == "redeem" || (isset($_GET['redeemauto']) && isset($_GET['code']))) {
			if (!isset($_POST['code']) && !isset($_GET['code']) ) throw new Exception("#8008 ".esc_html__('Ticket number to redeem is missing', 'event-tickets-with-ticket-scanner'));
			$this->redeemTicket();
			$this->codeObj = null;
		}
	}

	private function executeRequest() {
		global $sasoEventtickets;
		// auswerten $this->getParts()['_request']
		//if ($this->getParts()['_request'] == "action=redeem") {
		if (isset($_POST['action']) && $_POST['action'] == "redeem") {
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

					$sasoEventtickets->getAdmin()->executeJSON('redeemWoocommerceTicketForCode', $data, true);

					$order = $this->setStatusAfterRedeemOperation($order);

					// check if ticket redirection is activated
					if ($this->MAIN->getOptions()->isOptionCheckboxActive('wcTicketRedirectUser')) {
						// redirect
						$url = $this->getAdminSettings()->getOptionValue('wcTicketRedirectUserURL');
						$url = $this->getCore()->replaceURLParameters($url, $this->codeObj);
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
			$listObj = $this->getCore()->getListById($codeObj['list_id']);
			$metaObj = $this->getCore()->encodeMetaValuesAndFillObjectList($listObj['meta']);
			if (isset($metaObj['redirect']['url'])) {
				$_url = trim($metaObj['redirect']['url']);
				if (!empty($_url)) $url = $_url;
			}
		}

		$url = apply_filters($this->MAIN->_add_filter_prefix.'getJSRedirectURL', $codeObj);
		if (is_array($_url)) $_url = ""; // codeobj kam zurück, da niemand auf den hook hört (premium missing/deaktiviert)
		if (!empty($_url)) $url = $_url;

		// replace place holder
		$url = $this->getCore()->replaceURLParameters($url, $codeObj);
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
				$this->outputPDFTicketsForOrder($order);
			} else {
				echo "Wrong ticket code";
			}
		}
	}

	public function output() {
		$hasError = false;
		header('HTTP/1.1 200 OK');
		if (class_exists( 'WooCommerce' )) {

			try {
				if (!$this->isScanner()) {
					if($this->isPDFRequest()) {
						try {
							$this->outputPDF();
							exit;
						} catch (Exception $e) {}
					} elseif ($this->isICSRequest()) {
						$this->sendICSFile();
						exit;
					} elseif ($this->isBadgeRequest()) {
						$this->sendBadgeFile();
						exit;
					} elseif ($this->isOnePDFRequest()) {
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