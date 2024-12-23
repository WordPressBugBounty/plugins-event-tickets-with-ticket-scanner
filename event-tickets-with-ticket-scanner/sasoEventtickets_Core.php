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

	private function getBase() {
		return $this->MAIN->getBase();
	}
	private function getDB() {
		return $this->MAIN->getDB();
	}

	public function clearCode($code) {
		$ret = trim(urldecode(strip_tags(str_replace(" ","",str_replace(":","",str_replace("-", "", $code))))));
		$ret = apply_filters( $this->MAIN->_add_filter_prefix.'core_clearCode', $ret );
		return $ret;
	}

	public function getListById($id) {
		$sql = "select * from ".$this->getDB()->getTabelle("lists")." where id = ".intval($id);
		$ret = $this->getDB()->_db_datenholen($sql);
		if (count($ret) == 0) throw new Exception("#9232 ticket list not found");
		return $ret[0];
	}

	public function getCodesByRegUserId($user_id) {
		$user_id = intval($user_id);
		if ($user_id <= 0) return [];
		$sql = "select a.* from ".$this->getDB()->getTabelle("codes")." a where user_id = ".$user_id;
		return $this->getDB()->_db_datenholen($sql);
	}

	public function retrieveCodeByCode($code, $mitListe=false) {
		$code = $this->clearCode($code);
		$code = $this->getDB()->reinigen_in($code);
		if (empty($code)) throw new Exception("#203 tiket number empty");
		if ($mitListe) {
			$sql = "select a.*, b.name as list_name from ".$this->getDB()->getTabelle("codes")." a
					left join ".$this->getDB()->getTabelle("lists")." b on a.list_id = b.id
					where code = '".$code."'";
		} else {
			$sql = "select a.* from ".$this->getDB()->getTabelle("codes")." a where code = '".$code."'";
		}
		$ret = $this->getDB()->_db_datenholen($sql);
		if (count($ret) == 0) throw new Exception("#204 ticket with ".$code." not found");
		return $ret[0];
	}

	public function checkCodesSize() {
		if ($this->isCodeSizeExceeded()) throw new Exception("#208 too many tickets. Unlimited tickets only with premium");
	}
	public function isCodeSizeExceeded() {
		return $this->getBase()->_isMaxReachedForTickets($this->getDB()->getCodesSize()) == false;
	}

	public function retrieveCodeById($id, $mitListe=false) {
		$id = intval($id);
		if ($id == 0) throw new Exception("#220 id is wrong");
		if ($mitListe) {
			$sql = "select a.*, b.name as list_name from ".$this->getDB()->getTabelle("codes")." a
					left join ".$this->getDB()->getTabelle("lists")." b on a.list_id = b.id
					where a.id = ".$id;
		} else {
			$sql = "select a.* from ".$this->getDB()->getTabelle("codes")." a where a.id = ".$id;
		}
		$ret = $this->getDB()->_db_datenholen($sql);
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
				'_qr_content'=>''
				] // ticket purchase ; stats_redeemed is only used if the ticket can be redeemed more than once
			];

		if ($this->MAIN->isPremium() && method_exists($this->MAIN->getPremiumFunctions(), 'getMetaObject')) {
			$metaObj = $this->MAIN->getPremiumFunctions()->getMetaObject($metaObj);
		}

		return $metaObj;
	}
	public function encodeMetaValuesAndFillObject($metaValuesString, $codeObj=null) {
		$metaObj = $this->getMetaObject();
		if (!empty($metaValuesString)) {
			$metaObj = array_replace_recursive($metaObj, json_decode($metaValuesString, true));
		}
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
			if (empty($metaObj['wc_ticket']['idcode']))	$metaObj['wc_ticket']['idcode'] = crc32($codeObj['id']."-".current_time("timestamp"));
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
			]
		];
		if ($this->MAIN->isPremium() && method_exists($this->MAIN->getPremiumFunctions(), 'getMetaObjectList')) {
			$metaObj = $this->MAIN->getPremiumFunctions()->getMetaObjectList($metaObj);
		}
		return $metaObj;
	}

	public function encodeMetaValuesAndFillObjectList($metaValuesString) {
		$metaObj = $this->getMetaObjectList();
		if (!empty($metaValuesString)) {
			$metaObj = array_replace_recursive($metaObj, json_decode($metaValuesString, true));
		}
		return $metaObj;
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
		$metaObj = $this->getMetaObjectAuthtoken();
		if (!empty($metaValuesString)) {
			$metaObj = array_replace_recursive($metaObj, json_decode($metaValuesString, true));
		}
		return $metaObj;
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

	public function getUserIdsForCustomerName($search_query) {
		$ret = [];
		$search_query = trim($search_query);
		if (empty($search_query)) return $ret;
		$args = array(
			'meta_query' => array(
				'relation' => 'OR',
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
			),
		);

		$user_query = new WP_User_Query($args);
		if (!empty($user_query->get_results())) {
			foreach ($user_query->get_results() as $user) {
				$ret[] = $user->ID;
			}
		}
		return $ret;
	}

	public function json_encode_with_error_handling($object, $depth=512) {
		$json = json_encode($object, JSON_NUMERIC_CHECK, $depth);
		if (json_last_error() !== JSON_ERROR_NONE) {
			throw new Exception(json_last_error_msg());
		}
		return $json;
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
			$optionname = "";
			switch($status) {
				case 0:
					$optionname = "webhookURLinvalid";
					break;
				case 1:
					$optionname = "webhookURLvalid";
					break;
				case 2:
					$optionname = "webhookURLinactive";
					break;
				case 3:
					$optionname = "webhookURLisregistered";
					break;
				case 4:
					$optionname = "webhookURLexpired";
					break;
				case 5:
					$optionname = "webhookURLmarkedused";
					break;
				case 6:
					$optionname = "webhookURLsetused";
					break;
				case 7:
					$optionname = "webhookURLregister";
					break;
				case 8:
					$optionname = "webhookURLipblocking";
					break;
				case 9:
					$optionname = "webhookURLipblocked";
					break;
				case 10:
					$optionname = "webhookURLaddwcinfotocode";
					break;
				case 11:
					$optionname = "webhookURLwcremove";
					break;
				case 12:
					$optionname = "webhookURLaddwcticketinfoset";
					break;
				case 13:
					$optionname = "webhookURLaddwcticketredeemed";
					break;
				case 14:
					$optionname = "webhookURLaddwcticketunredeemed";
					break;
				case 15:
					$optionname = "webhookURLaddwcticketinforemoved";
					break;
				case 16:
					$optionname = "webhookURLrestrictioncodeused";
					break;
				case 17:
					$optionname = "webhookURLaddwcticketsold";
					break;
			}
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
		if (isset($codeObj['list_id']) && $codeObj['list_id'] > 0 && strpos($url, "{LIST}") !== false) {
			try {
				$listObj = $this->_getCachedList($codeObj['list_id']);
				$listname = $listObj['name'];
			} catch (Exception $e) {
			}
		}
		$url = str_replace("{LIST}", urlencode($listname), $url);

		$listdesc = "";
		if (isset($codeObj['list_id']) && $codeObj['list_id'] > 0 && strpos($url, "{LIST_DESC}") !== false) {
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
			$idcode = strtoupper(md5($order_id."-".current_time("timestamp")."-".uniqid()));
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
		if (isset($_GET['code'])) { // overwrites any found code, if parameter is available
			$foundcode = trim($_GET['code']);
			$parts = explode("-", $foundcode);
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
			$is_pdf_request = in_array("pdf", $t) || isset($_GET['pdf']);
			$is_ics_request = in_array("ics", $t) || isset($_GET['ics']);
			$is_badge_request = in_array("badge", $t) || isset($_GET['badge']);
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