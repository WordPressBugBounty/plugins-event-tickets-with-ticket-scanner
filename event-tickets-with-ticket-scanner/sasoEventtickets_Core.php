<?php
include_once(plugin_dir_path(__FILE__)."init_file.php");
class sasoEventtickets_Core {
	private $MAIN;

	private $_CACHE_list = [];

	public $ticket_url_path_part = "ticket";

	public function __construct($MAIN) {
		if ($MAIN->getDB() == null) throw new Exception("#9999 DB needed");
		$this->MAIN = $MAIN;
	}

	public function clearCode($code) {
		$ret = trim(urldecode(strip_tags(str_replace(" ","",str_replace(":","",str_replace("-", "", $code))))));
		$ret = apply_filters( $this->MAIN->_add_filter_prefix.'core_clearCode', $ret );
		return $ret;
	}

	public function getListById($id) {
		$sql = "select * from ".$this->MAIN->getDB()->getTabelle("lists")." where id = ".intval($id);
		$ret = $this->MAIN->getDB()->_db_datenholen($sql);
		if (count($ret) == 0) throw new Exception("#9232 ticket list not found");
		return $ret[0];
	}

	public function getCodesByRegUserId($user_id) {
		$user_id = intval($user_id);
		if ($user_id <= 0) return [];
		$sql = "select a.* from ".$this->MAIN->getDB()->getTabelle("codes")." a where user_id = ".$user_id;
		return $this->MAIN->getDB()->_db_datenholen($sql);
	}

	/**
	 * Get all ticket codes for a specific WooCommerce order
	 *
	 * @param int $order_id WooCommerce order ID
	 * @return array Array of code records
	 */
	public function getCodesByOrderId(int $order_id): array {
		$order_id = intval($order_id);
		if ($order_id <= 0) return [];
		$sql = "select a.* from ".$this->MAIN->getDB()->getTabelle("codes")." a where order_id = ".$order_id;
		return $this->MAIN->getDB()->_db_datenholen($sql);
	}

	public function retrieveCodeByCode($code, $mitListe=false) {
		$code = $this->clearCode($code);
		$code = $this->MAIN->getDB()->reinigen_in($code);
		if (empty($code)) throw new Exception("#203 tiket number empty");
		if ($mitListe) {
			$sql = "select a.*, b.name as list_name from ".$this->MAIN->getDB()->getTabelle("codes")." a
					left join ".$this->MAIN->getDB()->getTabelle("lists")." b on a.list_id = b.id
					where code = '".$code."'";
		} else {
			$sql = "select a.* from ".$this->MAIN->getDB()->getTabelle("codes")." a where code = '".$code."'";
		}
		$ret = $this->MAIN->getDB()->_db_datenholen($sql);
		if (count($ret) == 0) throw new Exception("#204 ticket with ".$code." not found");
		return $ret[0];
	}

	public function checkCodesSize() {
		if ($this->isCodeSizeExceeded()) throw new Exception("#208 too many tickets. Unlimited tickets only with premium");
	}
	public function isCodeSizeExceeded() {
		return $this->MAIN->getBase()->_isMaxReachedForTickets($this->MAIN->getDB()->getCodesSize()) == false;
	}

	// helpful if meta information is changed by  function and the following function might retrieve the inform from the database
	public function saveMetaObject($codeObj, $metaObj) {
		// convert meta object to json and save it
		$codeObj['meta'] = $this->_json_encode_with_error_handling($metaObj);
		$this->MAIN->getDB()->update("codes", ["meta"=>$codeObj['meta']], ['id'=>$codeObj['id']]);
		return $codeObj;
	}

	public function retrieveCodeById($id, $mitListe=false) {
		$id = intval($id);
		if ($id == 0) throw new Exception("#220 id is wrong");
		if ($mitListe) {
			$sql = "select a.*, b.name as list_name from ".$this->MAIN->getDB()->getTabelle("codes")." a
					left join ".$this->MAIN->getDB()->getTabelle("lists")." b on a.list_id = b.id
					where a.id = ".$id;
		} else {
			$sql = "select a.* from ".$this->MAIN->getDB()->getTabelle("codes")." a where a.id = ".$id;
		}
		$ret = $this->MAIN->getDB()->_db_datenholen($sql);
		if (count($ret) == 0) throw new Exception("#221 ticket not found");
		return $ret[0];
	}

	public function getMetaObject() {
		$metaObj = [
			'validation'=>[
				'first_success'=>'',
				'first_success_tz'=>'',
				'first_ip'=>'',
				'last_success'=>'',
				'last_success_tz'=>'',
				'last_ip'=>''
				]
			,'user'=>[
				'reg_approved'=>0,
				'reg_request'=>'',
				'reg_request_tz'=>'',
				'value'=>'',
				'reg_ip'=>'',
				'reg_userid'=>0,
				'_reg_username'=>'']
			,'used'=>[
				'reg_ip'=>'',
				'reg_request'=>'',
				'reg_request_tz'=>'',
				'reg_userid'=>0,
				'_reg_username'=>'']
			,'confirmedCount'=>0
			,'woocommerce'=>[
				'order_id'=>0,
				'product_id'=>0,
				'creation_date'=>0,
				'creation_date_tz'=>'',
				'item_id'=>0,
				'user_id'=>0
				] // product code for sale
			,'wc_rp'=>[
				'order_id'=>0,
				'product_id'=>0,
				'creation_date'=>0,
				'creation_date_tz'=>'',
				'item_id'=>0
				] // restriction purchase used
			,'wc_ticket'=>[
				'is_ticket'=>0,
				'ip'=>'',
				'userid'=>0,
				'_username'=>'',
				'redeemed_date'=>'',
				'redeemed_date_tz'=>'',
				'redeemed_by_admin'=>0,
				'set_by_admin'=>0,
				'set_by_admin_date'=>'',
				'set_by_admin_date_tz'=>'',
				'idcode'=>'',
				'_url'=>'',
				'_public_ticket_id'=>'',
				'stats_redeemed'=>[],
				'name_per_ticket'=>'',
				'value_per_ticket'=>'',
				'is_daychooser'=>0,
				'day_per_ticket'=>'',
				'subs'=>$this->getDefaultMetaValueOfSubs(),
				'_qr_content'=>''
				] // ticket purchase ; stats_redeemed is only used if the ticket can be redeemed more than once
			];

		if ($this->MAIN->isPremium() && method_exists($this->MAIN->getPremiumFunctions(), 'getMetaObject')) {
			$metaObj = $this->MAIN->getPremiumFunctions()->getMetaObject($metaObj);
		}

		return $metaObj;
	}
	public function getDefaultMetaValueOfSubs() {
		return [];
	}

	public function encodeMetaValuesAndFillObject($metaValuesString, $codeObj=null) {
		// Decode + merge with defaults
		$metaObj = $this->decodeAndMergeMeta($metaValuesString, $this->getMetaObject());

		// Fill computed values (usernames, URLs, etc.)
		if (isset($metaObj['user']['reg_userid']) && $metaObj['user']['reg_userid'] > 0) {
			$u = get_userdata($metaObj['user']['reg_userid']);
			if ($u === false) {
				$metaObj['user']['_reg_username'] = esc_html__("USERID DO NOT EXISTS", 'event-tickets-with-ticket-scanner');
			} else {
				$metaObj['user']['_reg_username'] = $u->first_name." ".$u->last_name." (".$u->user_login.")";
			}
		} else {
			$metaObj['user']['_reg_username'] = "";
		}
		if (isset($metaObj['used']['reg_userid']) && $metaObj['used']['reg_userid'] > 0) {
			$u = get_userdata($metaObj['used']['reg_userid']);
			if ($u === false) {
				$metaObj['used']['_reg_username'] = esc_html__("USERID DO NOT EXISTS", 'event-tickets-with-ticket-scanner');
			} else {
				$metaObj['used']['_reg_username'] = $u->first_name." ".$u->last_name." (".$u->user_login.")";
			}
		} else {
			$metaObj['used']['_reg_username'] = "";
		}
		if (isset($metaObj['wc_ticket']['userid']) && $metaObj['wc_ticket']['userid'] > 0) {
			$u = get_userdata($metaObj['wc_ticket']['userid']);
			if ($u === false) {
				$metaObj['wc_ticket']['_username'] = esc_html__("USERID DO NOT EXISTS", 'event-tickets-with-ticket-scanner');
			} else {
				$metaObj['wc_ticket']['_username'] = $u->first_name." ".$u->last_name." (".$u->user_login.")";
			}
		} else {
			$metaObj['wc_ticket']['_username'] = "";
		}
		if (isset($metaObj['wc_ticket']['redeemed_by_admin']) && $metaObj['wc_ticket']['redeemed_by_admin'] > 0) {
			$u = get_userdata($metaObj['wc_ticket']['redeemed_by_admin']);
			if ($u === false) {
				$metaObj['wc_ticket']['_redeemed_by_admin_username'] = esc_html__("USERID DO NOT EXISTS", 'event-tickets-with-ticket-scanner');
			} else {
				$metaObj['wc_ticket']['_redeemed_by_admin_username'] = $u->first_name." ".$u->last_name." (".$u->user_login.")";
			}
		} else {
			$metaObj['wc_ticket']['_redeemed_by_admin_username'] = "";
		}
		if (isset($metaObj['wc_ticket']['set_by_admin']) && $metaObj['wc_ticket']['set_by_admin'] > 0) {
			$u = get_userdata($metaObj['wc_ticket']['set_by_admin']);
			if ($u === false) {
				$metaObj['wc_ticket']['_set_by_admin_username'] = esc_html__("USERID DO NOT EXISTS", 'event-tickets-with-ticket-scanner');
			} else {
				$metaObj['wc_ticket']['_set_by_admin_username'] = $u->first_name." ".$u->last_name." (".$u->user_login.")";
			}
		} else {
			$metaObj['wc_ticket']['_set_by_admin_username'] = "";
		}
		if ($metaObj['wc_ticket']['is_ticket'] == 1 && $codeObj != null && is_array($codeObj)) {
			if (empty($metaObj['wc_ticket']['idcode']))	$metaObj['wc_ticket']['idcode'] = crc32($codeObj['id']."-".time());
			if (empty($metaObj['wc_ticket']['_public_ticket_id'])) $metaObj['wc_ticket']['_public_ticket_id'] = $this->getTicketId($codeObj, $metaObj);
			if (empty($metaObj['wc_ticket']['_qr_content'])) $metaObj['wc_ticket']['_qr_content'] = $this->getQRCodeContent($codeObj, $metaObj);
			$metaObj['wc_ticket']['_url'] = $this->getTicketURL($codeObj, $metaObj);
		}

		// update validation fields
		if ($metaObj['confirmedCount'] > 0) {
			if (empty($metaObj['validation']['first_success'])) {
				// check used wert
				if ( !empty($metaObj['used']['reg_request']) ) {
					if (empty($metaObj['validation']['first_success'])) $metaObj['validation']['first_success'] = $metaObj['used']['reg_request'];
					if (empty($metaObj['validation']['first_success_tz'])) $metaObj['validation']['first_success_tz'] = $metaObj['used']['reg_request_tz'];
					if (empty($metaObj['validation']['first_ip'])) $metaObj['validation']['first_ip'] = $metaObj['used']['reg_ip'];
				} elseif (!empty($metaObj['user']['reg_request'])) { // check user reg wert
					if (empty($metaObj['validation']['first_success'])) $metaObj['validation']['first_success'] = $metaObj['user']['reg_request'];
					if (empty($metaObj['validation']['first_success_tz'])) $metaObj['validation']['first_success_tz'] = $metaObj['user']['reg_request_tz'];
					if (empty($metaObj['validation']['first_ip'])) $metaObj['validation']['first_ip'] = $metaObj['user']['reg_ip'];
				}
			}
		}

		if ($this->MAIN->isPremium() && method_exists($this->MAIN->getPremiumFunctions(), 'encodeMetaValuesAndFillObject')) {
			$metaObj = $this->MAIN->getPremiumFunctions()->encodeMetaValuesAndFillObject($metaObj, $codeObj);
		}
		return $metaObj;
	}

	public function getMetaObjectKeyList($metaObj, $prefix="META_") {
		$keys = [];
		$prefix = strtoupper(trim($prefix));
		foreach(array_keys($metaObj) as $key) {
			$tag = $prefix.strtoupper($key);
			if (is_array($metaObj[$key])) {
				$_keys = $this->getMetaObjectKeyList($metaObj[$key], $tag."_");
				$keys = array_merge($keys, $_keys);
			} else {
				$keys[] = $tag;
			}
		}
		return $keys;
	}

	public function getMetaObjectAllowedReplacementTags() {
		$tags = [];
		$allowed_tags = [
			"USER_VALUE"=>esc_html__("Value given by the user during the code registration.", 'event-tickets-with-ticket-scanner'),
			"USER_REG_IP"=>esc_html__("IP address of the user, register to a code.", 'event-tickets-with-ticket-scanner'),
			"USER_REG_USERID"=>esc_html__("User id of the registered user to a code. Default will be 0.", 'event-tickets-with-ticket-scanner'),
			"USED_REG_IP"=>esc_html__("IP addres of the user that used the code.", 'event-tickets-with-ticket-scanner'),
			"CONFIRMEDCOUNT"=>esc_html__("Amount of how many times the code was validated successfully.", 'event-tickets-with-ticket-scanner'),
			"WOOCOMMERCE_ORDER_ID"=>esc_html__("WooCommerce order id assigned to the code.", 'event-tickets-with-ticket-scanner'),
			"WOOCOMMERCE_PRODUCT_ID"=>esc_html__("WooCommerce product id assigned to the code.", 'event-tickets-with-ticket-scanner'),
			"WOOCOMMERCE_CREATION_DATE"=>esc_html__("Creation date of the WooCommerce sales date.", 'event-tickets-with-ticket-scanner'),
			"WOOCOMMERCE_CREATION_DATE_TZ"=>esc_html__("Creation date of the WooCommerce sales date timezone.", 'event-tickets-with-ticket-scanner'),
			"WOOCOMMERCE_USER_ID"=>esc_html__("User id of the WooCommerce sales.", 'event-tickets-with-ticket-scanner'),
			"WC_RP_ORDER_ID"=>esc_html__("WooCommerce order id, that was purchases using this code as an allowance to purchase a restricted product.", 'event-tickets-with-ticket-scanner'),
			"WC_RP_PRODUCT_ID"=>esc_html__("WooCommerce product id that was restricted with this code.", 'event-tickets-with-ticket-scanner'),
			"WC_RP_CREATION_DATE"=>esc_html__("Creation date of the WooCommerce purchase using the allowance code.", 'event-tickets-with-ticket-scanner'),
			"WC_RP_CREATION_DATE_TZ"=>esc_html__("Creation date timezone of the WooCommerce purchase using the allowance code.", 'event-tickets-with-ticket-scanner'),
			"WC_TICKET__PUBLIC_TICKET_ID"=>esc_html__("The public ticket number", 'event-tickets-with-ticket-scanner')
		];
		$allowed_tags = apply_filters( $this->MAIN->_add_filter_prefix.'core_getMetaObjectAllowedReplacementTags', $allowed_tags );
		foreach($allowed_tags as $key => $value) {
			$tags[] = ["key"=>$key, "label"=>$value];
		}
		return $tags;
	}

	public function getMetaObjectList() {
		$metaObj = [
			'desc'=>'',
			'redirect'=>['url'=>''],
			'formatter'=>[
				'active'=>1,
				'format'=>'' // JSON mit den Format Werten
			],
			'webhooks'=>[
				'webhookURLaddwcticketsold'=>''
			],
			'messages'=>[
				'format_limit_threshold_warning'=>[
					'attempts'=>0,
					'last_email'=>''
				],
				'format_end_warning'=>[
					'attempts'=>0,
					'last_email'=>''
				]
			]
		];
		if ($this->MAIN->isPremium() && method_exists($this->MAIN->getPremiumFunctions(), 'getMetaObjectList')) {
			$metaObj = $this->MAIN->getPremiumFunctions()->getMetaObjectList($metaObj);
		}
		return $metaObj;
	}

	public function encodeMetaValuesAndFillObjectList($metaValuesString) {
		return $this->decodeAndMergeMeta($metaValuesString, $this->getMetaObjectList());
	}

	public function setMetaObj($codeObj) {
		if (!isset($codeObj["metaObj"])) {
			$metaObj = $this->encodeMetaValuesAndFillObject($codeObj['meta'], $codeObj);
			$codeObj["metaObj"] = $metaObj;
		}
		return $codeObj;
	}

	public function getQRCodeContent($codeObj, $metaObj=null) {
		if (!isset($codeObj['metaObj']) || $codeObj['metaObj'] == null) {
			if ($metaObj != null) {
				$codeObj['metaObj'] = $metaObj;
			} else {
				$codeObj = $this->setMetaObj($codeObj);
			}
		}
		$metaObj = $codeObj['metaObj'];
		$ticket_id = $this->getTicketId($codeObj, $metaObj);
		$qrCodeContent = $ticket_id;
		if ($this->MAIN->getOptions()->isOptionCheckboxActive('ticketQRUseURLToTicketScanner')) {
			$qrCodeContent = $this->getTicketScannerURL($ticket_id);
		}
		if ($this->MAIN->getOptions()->isOptionCheckboxActive('qrUseOwnQRContent')) {
			$qr_content = $this->MAIN->getAdmin()->getOptionValue('qrOwnQRContent');
			if (!empty($qr_content)) {
				$qrCodeContent = $this->replaceURLParameters($qr_content, $codeObj);
			}
		}
		$qrCodeContent = apply_filters( $this->MAIN->_add_filter_prefix.'core_getQRCodeContent', $qrCodeContent );
		return $qrCodeContent;
	}

	public function getMetaObjectAuthtoken() {
		$metaObj = [
			'desc'=>'',
			'ticketscanner'=>["bound_to_products"=>""]
		];
		if ($this->MAIN->isPremium() && method_exists($this->MAIN->getPremiumFunctions(), 'getMetaObjectAuthtoken')) {
			$metaObj = $this->MAIN->getPremiumFunctions()->getMetaObjectAuthtoken($metaObj);
		}
		return $metaObj;
	}

	public function encodeMetaValuesAndFillObjectAuthtoken($metaValuesString) {
		return $this->decodeAndMergeMeta($metaValuesString, $this->getMetaObjectAuthtoken());
	}

	public function alignArrays(&$array1, &$array2) {
		// Füge fehlende Schlüssel von array1 zu array2 hinzu
		foreach ($array1 as $key => $value) {
			if (!array_key_exists($key, $array2)) {
				$array2[$key] = is_array($value) ? [] : null;
			}
		}

		// Entferne überschüssige Schlüssel aus array2
		foreach ($array2 as $key => $value) {
			if (!array_key_exists($key, $array1)) {
				unset($array2[$key]);
			}
		}

		// Rekursiver Aufruf für Subarrays
		foreach ($array1 as $key => &$value) {
			if (is_array($value) && array_key_exists($key, $array2) && is_array($array2[$key])) {
				$this->alignArrays($value, $array2[$key]);
			}
		}
		unset($value); // Referenz aufheben
	}

	/**
	 * Search for customers by name and return matching user_ids and order_ids
	 *
	 * @param string $search_query Search term
	 * @return array ['user_ids' => [...], 'order_ids' => [...]]
	 */
	public function getUserIdsForCustomerName($search_query): array {
		$ret = ['user_ids' => [], 'order_ids' => []];
		$search_query = trim($search_query);
		if (empty($search_query)) return $ret;

		// Search in WordPress standard meta AND WooCommerce billing/shipping meta
		$args = array(
			'meta_query' => array(
				'relation' => 'OR',
				// WordPress standard
				array(
					'key'     => 'first_name',
					'value'   => $search_query,
					'compare' => 'LIKE',
				),
				array(
					'key'     => 'last_name',
					'value'   => $search_query,
					'compare' => 'LIKE',
				),
				// WooCommerce billing
				array(
					'key'     => 'billing_first_name',
					'value'   => $search_query,
					'compare' => 'LIKE',
				),
				array(
					'key'     => 'billing_last_name',
					'value'   => $search_query,
					'compare' => 'LIKE',
				),
				// WooCommerce shipping
				array(
					'key'     => 'shipping_first_name',
					'value'   => $search_query,
					'compare' => 'LIKE',
				),
				array(
					'key'     => 'shipping_last_name',
					'value'   => $search_query,
					'compare' => 'LIKE',
				),
			),
		);

		$user_query = new WP_User_Query($args);
		if (!empty($user_query->get_results())) {
			foreach ($user_query->get_results() as $user) {
				$ret['user_ids'][] = $user->ID;
			}
		}

		// Also search by display_name and user_email
		$args2 = array(
			'search'         => '*' . $search_query . '*',
			'search_columns' => array('display_name', 'user_email', 'user_login'),
		);
		$user_query2 = new WP_User_Query($args2);
		if (!empty($user_query2->get_results())) {
			foreach ($user_query2->get_results() as $user) {
				if (!in_array($user->ID, $ret['user_ids'])) {
					$ret['user_ids'][] = $user->ID;
				}
			}
		}

		// Search in WooCommerce orders (HPOS compatible) - includes guest orders
		if (class_exists('WooCommerce')) {
			$this->searchWooCommerceOrdersForCustomer($search_query, $ret);
		}

		return $ret;
	}

	/**
	 * Search WooCommerce orders by customer name and add matching user_ids and order_ids
	 * Uses WooCommerce API (wc_get_orders) with field_query for LIKE searches
	 * Works with both HPOS and legacy storage
	 * For registered users: adds user_id
	 * For guest orders: adds order_id
	 *
	 * @param string $search_query Search term
	 * @param array &$ret Reference to result array ['user_ids' => [...], 'order_ids' => [...]]
	 */
	private function searchWooCommerceOrdersForCustomer(string $search_query, array &$ret): void {
		if (!function_exists('wc_get_orders')) {
			return;
		}

		global $wpdb;
		$search_like = '%' . $wpdb->esc_like($search_query) . '%';

		// Check if HPOS is enabled
		if (class_exists('Automattic\WooCommerce\Utilities\OrderUtil')
			&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()) {

			// HPOS: Search in wc_order_addresses table
			$addresses_table = $wpdb->prefix . 'wc_order_addresses';
			$orders_table = $wpdb->prefix . 'wc_orders';

			$sql = $wpdb->prepare(
				"SELECT DISTINCT o.id as order_id, o.customer_id
				FROM {$orders_table} o
				INNER JOIN {$addresses_table} a ON o.id = a.order_id
				WHERE (a.first_name LIKE %s
					OR a.last_name LIKE %s
					OR a.email LIKE %s
					OR a.company LIKE %s)",
				$search_like, $search_like, $search_like, $search_like
			);

			$results = $wpdb->get_results($sql);
			foreach ($results as $row) {
				$customer_id = (int) $row->customer_id;
				$order_id = (int) $row->order_id;

				if ($customer_id > 0) {
					if (!in_array($customer_id, $ret['user_ids'])) {
						$ret['user_ids'][] = $customer_id;
					}
				} else {
					if (!in_array($order_id, $ret['order_ids'])) {
						$ret['order_ids'][] = $order_id;
					}
				}
			}
		} else {
			// Legacy: Search in post meta
			$sql = $wpdb->prepare(
				"SELECT DISTINCT pm.post_id as order_id, COALESCE(pm_cust.meta_value, 0) as customer_id
				FROM {$wpdb->postmeta} pm
				LEFT JOIN {$wpdb->postmeta} pm_cust ON pm.post_id = pm_cust.post_id AND pm_cust.meta_key = '_customer_user'
				WHERE pm.meta_key IN ('_billing_first_name', '_billing_last_name', '_billing_email', '_billing_company')
				AND pm.meta_value LIKE %s",
				$search_like
			);

			$results = $wpdb->get_results($sql);
			foreach ($results as $row) {
				$customer_id = (int) $row->customer_id;
				$order_id = (int) $row->order_id;

				if ($customer_id > 0) {
					if (!in_array($customer_id, $ret['user_ids'])) {
						$ret['user_ids'][] = $customer_id;
					}
				} else {
					if (!in_array($order_id, $ret['order_ids'])) {
						$ret['order_ids'][] = $order_id;
					}
				}
			}
		}
	}

	public function json_encode_with_error_handling($object, $depth=512) {
		$json = json_encode($object, JSON_NUMERIC_CHECK, $depth);
		if (json_last_error() !== JSON_ERROR_NONE) {
			throw new Exception(json_last_error_msg());
		}
		return $json;
	}

	/**
	 * Generic decode and merge meta with defaults
	 *
	 * Use this for any entity type by passing the default meta object.
	 * Pattern: Decode stored JSON, merge over defaults, return complete object.
	 *
	 * Benefits:
	 * - Old stored data automatically gets new fields
	 * - No data loss (stored values preserved)
	 * - Single source of truth for merge logic
	 *
	 * @param string|null $metaJson JSON string from database
	 * @param array $defaultMetaObj Default meta object with all fields
	 * @return array Merged meta object with all fields guaranteed
	 */
	public function decodeAndMergeMeta(?string $metaJson, array $defaultMetaObj): array {
		if (!empty($metaJson)) {
			$decoded = json_decode($metaJson, true);
			if (is_array($decoded)) {
				$defaultMetaObj = array_replace_recursive($defaultMetaObj, $decoded);
			}
		}
		return $defaultMetaObj;
	}

	public function getRealIpAddr() {
	    if (!empty($_SERVER['HTTP_CLIENT_IP']))   //check ip from share internet
	    {
	      $ip=sanitize_text_field($_SERVER['HTTP_CLIENT_IP']);
	    }
	    elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR']))   //to check ip is pass from proxy
	    {
	      $ip=sanitize_text_field($_SERVER['HTTP_X_FORWARDED_FOR']);
	    }
	    else
	    {
	      $ip=sanitize_text_field($_SERVER['REMOTE_ADDR']);
	    }
	    return $ip;
	}

	public function triggerWebhooks($status, $codeObj) {
		$options = $this->MAIN->getOptions();
		if ($options->isOptionCheckboxActive('webhooksActiv')) {
			$statusToOption = [
				0  => "webhookURLinvalid",
				1  => "webhookURLvalid",
				2  => "webhookURLinactive",
				3  => "webhookURLisregistered",
				4  => "webhookURLexpired",
				5  => "webhookURLmarkedused",
				6  => "webhookURLsetused",
				7  => "webhookURLregister",
				8  => "webhookURLipblocking",
				9  => "webhookURLipblocked",
				10 => "webhookURLaddwcinfotocode",
				11 => "webhookURLwcremove",
				12 => "webhookURLaddwcticketinfoset",
				13 => "webhookURLaddwcticketredeemed",
				14 => "webhookURLaddwcticketunredeemed",
				15 => "webhookURLaddwcticketinforemoved",
				16 => "webhookURLrestrictioncodeused",
				17 => "webhookURLaddwcticketsold",
			];
			$optionname = $statusToOption[$status] ?? "";
			if (!empty($optionname)) {
				$url = $options->getOption($optionname)['value'];

				if ($optionname == "webhookURLaddwcticketsold") {
					$list_id = intval($codeObj['list_id']);
					if ($list_id > 0) {
						try {
							$listObj = $this->MAIN->getAdmin()->getList(['id'=>$list_id]);
							$metaObj = $this->encodeMetaValuesAndFillObjectList($listObj['meta']);
							if (isset($metaObj['webhooks']) && isset($metaObj['webhooks']['webhookURLaddwcticketsold'])) {
								if (!empty(trim($metaObj['webhooks']['webhookURLaddwcticketsold']))) {
									$url = trim($metaObj['webhooks']['webhookURLaddwcticketsold']);
								}
							}
						} catch(Exception $e) {
							$this->MAIN->getAdmin()->logErrorToDB($e);
						}
					}
				}

				if (!empty($url)) {
					$url = $this->replaceURLParameters($url, $codeObj);
					wp_remote_get($url);
					do_action( $this->MAIN->_do_action_prefix.'core_triggerWebhooks', $status, $codeObj, $url );
				}
			}
		}
	}

	private function _getCachedList($list_id) {
		if (isset($this->_CACHE_list[$list_id])) return $this->_CACHE_list[$list_id];
		$this->_CACHE_list[$list_id] = $this->getListById($list_id);
		return $this->_CACHE_list[$list_id];
	}

	public function replaceURLParameters($url, $codeObj) {
		$url = str_replace("{CODE}", isset($codeObj['code']) ? $codeObj['code'] : '', $url);
		$url = str_replace("{CODEDISPLAY}", isset($codeObj['code_display']) ? $codeObj['code_display'] : '', $url);
		$url = str_replace("{IP}", $this->getRealIpAddr(), $url);
		$userid = '';
		if (is_user_logged_in()) {
			$userid = get_current_user_id();
		}
		$url = str_replace("{USERID}", $userid, $url);

		$listname = "";
		if (isset($codeObj['list_id']) && $codeObj['list_id'] > 0 && strpos(" ".$url, "{LIST}") !== false) {
			try {
				$listObj = $this->_getCachedList($codeObj['list_id']);
				$listname = $listObj['name'];
			} catch (Exception $e) {
			}
		}
		$url = str_replace("{LIST}", urlencode($listname), $url);

		$listdesc = "";
		if (isset($codeObj['list_id']) && $codeObj['list_id'] > 0 && strpos(" ".$url, "{LIST_DESC}") !== false) {
			try {
				$listObj = $this->_getCachedList($codeObj['list_id']);
				$metaObj = [];
				if (!empty($listObj['meta'])) $metaObj = $this->encodeMetaValuesAndFillObjectList($listObj['meta']);
				if (isset($metaObj['desc'])) $listdesc = $metaObj['desc'];
			} catch (Exception $e) {
			}
		}
		$url = str_replace("{LIST_DESC}", urlencode($listdesc), $url);

		$metaObj = [];
		if (!isset($codeObj['metaObj'])) {
			if (!empty($codeObj['meta'])) $metaObj = $this->encodeMetaValuesAndFillObject($codeObj['meta'], $codeObj);
		} else {
			$metaObj = $codeObj['metaObj'];
		}
		if (count($metaObj) > 0) $url = $this->_replaceTagsInTextWithMetaObjectsValues($url, $metaObj, "META_");
		if (count($metaObj) > 0) $url = $this->_replaceTagsInTextWithMetaObjectsValues($url, $metaObj, "");

		$url = apply_filters( $this->MAIN->_add_filter_prefix.'core_replaceURLParameters', $url, $codeObj, $metaObj );

		return $url;
	}

	private function _replaceTagsInTextWithMetaObjectsValues($text, $metaObj, $prefix="") {
		$prefix = strtoupper(trim($prefix));
		foreach(array_keys($metaObj) as $key) {
			$tag = $prefix.strtoupper($key);
			if (is_array($metaObj[$key])) {
				$text = $this->_replaceTagsInTextWithMetaObjectsValues($text, $metaObj[$key], $tag."_");
			} else {
				$text = str_replace("{".$tag."}", urlencode($metaObj[$key]), $text);
			}
		}
		return $text;
	}

	public function checkCodeExpired($codeObj) {
		if ($this->MAIN->isPremium() && method_exists($this->MAIN->getPremiumFunctions(), 'checkCodeExpired')) {
			if ($this->MAIN->getPremiumFunctions()->checkCodeExpired($codeObj)) {
				return true;
			}
		}
		return false;
	}
	public function isCodeIsRegistered($codeObj) {
		$meta = [];
		if (!empty($codeObj['meta'])) $meta = $this->encodeMetaValuesAndFillObject($codeObj['meta'], $codeObj);
		if (isset($meta['user']) && isset($meta['user']['value']) && !empty($meta['user']['value'])) {
			return true;
		}
		return false;
	}

	public function getTicketURLBase($defaultPath=false) {
		$path = plugin_dir_url(__FILE__).$this->ticket_url_path_part;
		if ($defaultPath == false) {
			$wcTicketCompatibilityModeURLPath = trim($this->MAIN->getOptions()->getOptionValue('wcTicketCompatibilityModeURLPath'));
			$wcTicketCompatibilityModeURLPath = trim(trim($wcTicketCompatibilityModeURLPath, "/"));
			if (!empty($wcTicketCompatibilityModeURLPath)) {
				$path = site_url()."/".$wcTicketCompatibilityModeURLPath;
			}
		}
		$ret = $path."/";
		$ret = apply_filters( $this->MAIN->_add_filter_prefix.'core_getTicketURLBase', $ret );
		return $ret;
	}
	public function getTicketId($codeObj, $metaObj) {
		$ret = "";
		if (isset($codeObj['code']) && isset($codeObj['order_id']) && isset($metaObj['wc_ticket']['idcode'])) {
			$ret = $metaObj['wc_ticket']['idcode']."-".$codeObj['order_id']."-".$codeObj['code'];
		}
		$ret = apply_filters( $this->MAIN->_add_filter_prefix.'core_getTicketId', $ret, $codeObj, $metaObj );
		return $ret;
	}
	public function getTicketURL($codeObj, $metaObj) {
		$ticket_id = $this->getTicketId($codeObj, $metaObj);
		$baseURL = $this->getTicketURLBase();
		$url = $baseURL.$ticket_id;
		if ($this->MAIN->getOptions()->isOptionCheckboxActive('wcTicketCompatibilityMode')) {
			$url = $baseURL."?code=".$ticket_id;
		}
		$url = apply_filters( $this->MAIN->_add_filter_prefix.'core_getTicketURL', $url, $codeObj, $metaObj );
		return $url;
	}
	public function getOrderTicketIDCode($order) {
		$order_id = $order->get_id();
		$idcode = $order->get_meta('_saso_eventtickets_order_idcode');
		if (empty($idcode)) {
			$idcode = strtoupper(md5($order_id."-".time()."-".uniqid()));
			$order->update_meta_data( '_saso_eventtickets_order_idcode', $idcode );
			$order->save();
		}
		return $idcode;
	}
	public function getOrderTicketId($order, $ticket_id_prefix="order-") {
		$order_id = $order->get_id();
		$idcode = $this->getOrderTicketIDCode($order);
		$ticket_id = trim($ticket_id_prefix).$order_id."-".$idcode;
		return $ticket_id;
	}
	public function getOrderTicketsURL($order, $ticket_id_prefix="order-") {
		if ($order == null) throw new Exception("Order empty - no order tickets PDF url created");
		$ticket_id = $this->getOrderTicketId($order, $ticket_id_prefix);
		$baseURL = $this->getTicketURLBase();
		$url = $baseURL.$ticket_id;
		if ($this->MAIN->getOptions()->isOptionCheckboxActive('wcTicketCompatibilityMode')) {
			$url = $baseURL."?code=".$ticket_id;
		}
		$url = apply_filters( $this->MAIN->_add_filter_prefix.'core_getOrderTicketsURL', $url, $order, $ticket_id_prefix );
		return $url;
	}
	public function getTicketScannerURL($ticket_id) {
		$baseURL = $this->getTicketURLBase();
		$url = $baseURL."scanner/?code=".urlencode($ticket_id);
		$url = apply_filters( $this->MAIN->_add_filter_prefix.'core_getTicketScannerURL', $url, $ticket_id );
		return $url;
	}
	public function getTicketURLPath($defaultPath=false) {
		$p = $this->getTicketURLBase($defaultPath);
		$teile = parse_url($p);
		$ret = $teile['path'];
		$ret = apply_filters( $this->MAIN->_add_filter_prefix.'core_getTicketURLPath', $ret, $defaultPath );
		return $ret;
	}
	public function getTicketURLComponents($url) {
		$teile = explode("/", $url);
		$teile = array_reverse($teile);
		$request = "";
		$is_pdf_request = false;
		$is_ics_request = false;
		$is_badge_request = false;
		$foundcode = "";
		foreach($teile as $teil) {
			$teil = trim($teil);
			if (empty($teil)) continue;
			if (strtolower($teil) == "?pdf") continue;
			if (strtolower($teil) == "?ics") continue;
			if ($teil == $this->ticket_url_path_part) break;
			$foundcode = $teil;
			break;
		}
		if (SASO_EVENTTICKETS::issetRPara('code')) { // overwrites any found code, if parameter is available
			$foundcode = trim(SASO_EVENTTICKETS::getRequestPara('code'));
			if (strpos($foundcode, "'") === false) {
				$parts = explode("-", $foundcode);
			} else {
				$parts = explode("'", $foundcode);
			}
			$t = explode("?", $url);
			if (count($t) > 1) {
				unset($t[0]);
				$tt = [];
				foreach($t as $tp){
					$ttt = explode("&", $tp);
					$tt = array_merge($tt, $ttt);
				}
				$t = $tt;
				$request = join("&", $t);
			}
			$is_pdf_request = in_array("pdf", $t);
			$is_ics_request = in_array("ics", $t);
			$is_badge_request = in_array("badge", $t);
		} else {
			if (empty($foundcode)) throw new Exception("#9301 ticket id not found from ticket url");
			$parts = explode("-", $foundcode);
			if (count($parts) < 3) throw new Exception("#9303 ticket id is wrong");
			$t = explode("?", $parts[2]);
			$parts[2] = $t[0];
			if (count($t) > 1) {
				unset($t[0]);
				$request = join("&", $t);
			}
			$is_pdf_request = in_array("pdf", $t) || SASO_EVENTTICKETS::issetRPara('pdf');
			$is_ics_request = in_array("ics", $t) || SASO_EVENTTICKETS::issetRPara('ics');
			$is_badge_request = in_array("badge", $t) || SASO_EVENTTICKETS::issetRPara('badge');
		}
		if (count($parts) != 3) throw new Exception("#9302 ticket id not correct - cannot create ticket url components");
		$parts[2] = str_replace("?pdf", "", $parts[2]);
		$parts[2] = str_replace("?ics", "", $parts[2]);
		$parts_assoc = [
			"foundcode"=>$foundcode,
			"idcode"=>$parts[0],
			"order_id"=>$parts[1],
			"code"=>$parts[2],
			"_request"=>$request,
			"_isPDFRequest"=>$is_pdf_request,
			"_isICSRequest"=>$is_ics_request,
			"_isBadgeRequest"=>$is_badge_request
		];
		$parts_assoc = apply_filters( $this->MAIN->_add_filter_prefix.'core_getTicketURLComponents', $parts_assoc, $url );
		return $parts_assoc;
	}

	public function mergePDFs($filepaths, $filename, $filemode="I", $deleteFilesAfterMerge=true) {
		if (count($filepaths) > 0) {
			$pdf = $this->MAIN->getNewPDFObject();
			$pdf->setFilemode($filemode);
			$pdf->setFilename($filename);
			try {
				$pdf->mergeFiles($filepaths); // send file to browser if,filemode is I
			} catch(Exception $e) {
				$this->MAIN->getAdmin()->logErrorToDB($e, null, "tried to merge PDFs together. Filepaths: (".join(", ", $filepaths).")");
			}

			// clean up temp files
			if ($deleteFilesAfterMerge) {
				foreach($filepaths as $filepath) {
					if (file_exists($filepath)) {
						@unlink($filepath);
					}
				}
			}
			if ($pdf->getFilemode() == "F") {
				return $pdf->getFullFilePath();
			} else {
				exit;
			}
		}
	}

	public function parser_search_loop($text) {
        // search for loop
        // {{LOOP ORDER.items AS item}} loop-content {{LOOPEND}}
		if (empty($text)) return false;
        $pos = strpos($text, "{{LOOP ");
		if ($pos !== false) {
			$pos_end = strpos($text, "{{LOOPEND}}", $pos);
			if ($pos_end !== false) {
				$pos_end += 11;
				$html_part = substr($text, $pos, $pos_end - $pos);
				//echo $html_part;

				$matches = [];

				$collection = null;
				$item_var = null;
				$loop_part = null;
				// finde loop collection and item var
				$pattern = '/{{\s?LOOP\s(.*?)\sAS\s(.*?)\s?}}(.*?){{\s?LOOPEND\s?}}/is';
				if (preg_match($pattern, $html_part, $matches)) {
					$collection = trim($matches[1]);
					$item_var = trim($matches[2]);
					$loop_part = trim($matches[3]);
				}

				return [
					"collection"=>$collection,
					"item_var"=>$item_var,
					"loop_part"=>$loop_part,
					"pos_start"=>$pos,
					"pos_end"=>$pos_end,
					"found_str"=>$matches[0]
				];
			}
		}
		return false;
	}
}
?>