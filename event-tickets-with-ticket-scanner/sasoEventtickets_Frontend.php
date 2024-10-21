<?php
include_once(plugin_dir_path(__FILE__)."init_file.php");
class sasoEventtickets_Frontend {
	private $MAIN;

	public function __construct($MAIN) {
		$this->MAIN = $MAIN;
	}

	private function getDB() {
		return $this->MAIN->getDB();
	}
	private function getBase() {
		return $this->MAIN->getBase();
	}
	private function getCore() {
		return $this->MAIN->getCore();
	}

	public function executeJSON($a, $data=[]) {
		$ret = "";
		$justJSON = false;
		try {
			switch (trim($a)) {
				case "checkCode":
					$ret = $this->checkCode($data);
					break;
				case "getOptions":
					$ret = $this->getOptions();
					break;
				case "registerToCode":
					$ret = $this->registerToCode($data);
					break;
				case "premium":
					$ret = $this->executeJSONPremium($data);
					break;
				default:
					throw new Exception(sprintf(esc_html__('function "%s" not implemented', 'event-tickets-with-ticket-scanner')));
			}
		} catch(Exception $e) {
			//$this->MAIN->getAdmin()->logErrorToDB($e);
			return wp_send_json_error (['msg'=>$e->getMessage()]);
		}
		if ($justJSON) return wp_send_json($ret);
		else return wp_send_json_success( $ret );
	}

	private function executeJSONPremium($data) {
		if (!$this->MAIN->isPremium() && method_exists($this->MAIN->getPremiumFunctions(), 'executeFrontendJSON')) throw new Exception("#9001a premium is not active");
		if (!isset($data['d'])) throw new Exception("#9002a premium action is missing");
		return $this->MAIN->getPremiumFunctions()->executeFrontendJSON($data['d'], $data);
	}

	private function checkIfOnlyLoggedInIsAffected($data) {
		if ($this->MAIN->getOptions()->isOptionCheckboxActive('onlyForLoggedInWPuser') && !is_user_logged_in()) {
			$v = trim($this->MAIN->getOptions()->getOptionValue('onlyForLoggedInWPuserMessage'));
			throw new Exception($v);
		}
		return $data;
	}

	public function isUsed($codeObj) {
		$ret = false;
		$metaObj = $this->getCore()->encodeMetaValuesAndFillObject($codeObj['meta'], $codeObj);
		if (!empty($metaObj['used']['reg_request'])) {
			$ret = true;
		}
		$ret = apply_filters( $this->MAIN->_add_filter_prefix.'frontend_isUsed', $ret );
		return $ret;
	}
	public function markAsUsed($codeObj, $force=false) {
		if ($force || $this->MAIN->getOptions()->isOptionCheckboxActive('oneTimeUseOfRegisterCode')) {
			if ($codeObj['aktiv'] == 1) {
				$metaObj = $this->getCore()->encodeMetaValuesAndFillObject($codeObj['meta'], $codeObj);
				// check ob nicht schon used
				if (!empty($metaObj['used']['reg_request'])) {
					$codeObj['_valid'] = 5; // used
				} else {
					$confirmedCount = isset($metaObj['confirmedCount']) ? intval($metaObj['confirmedCount']) : 0;
					$confirmedCount++; // da erst am ende der Count erhöht wird, hier schon +1 machen
					if ($force) {
						$optionCount = 1;
					} else {
						// setze als used
						$optionCount = intval($this->MAIN->getOptions()->getOptionValue('oneTimeUseOfRegisterAmount'));
						if ($optionCount < 1) $optionCount = 1;
						// check if code has list
						if ($codeObj['list_id'] > 0) {
							// lade liste , um auf code list ebene einen abweichenden Wert zu prüfen
							$listObj = $this->getCore()->getListById($codeObj['list_id']);
							$listObjMeta = [];
							// check if code has in metaObj a value set and if it is > 0
							if (isset($listObj["meta"]) && $listObj["meta"] != "")  {
								$listObjMeta = array_replace_recursive($listObjMeta, json_decode($listObj['meta'], true));
								if (isset($listObjMeta['oneTimeUseOfRegisterAmount'])) {
									$_optionCount = intval($listObjMeta['oneTimeUseOfRegisterAmount']);
									if ($_optionCount > 0) $optionCount = $_optionCount;
								}
							}
						}
					}
					if ($optionCount <= $confirmedCount) {
						$metaObj = $this->addNewUsedEntryToMetaObject($metaObj);
						$codeObj['meta'] = $this->getCore()->json_encode_with_error_handling($metaObj);
						$this->getDB()->update("codes", ["meta"=>$codeObj['meta']], ['id'=>$codeObj['id']]);
						$this->getCore()->triggerWebhooks(6, $codeObj);
					}
				}
			}
		}
		do_action( $this->MAIN->_do_action_prefix.'frontend_markAsUsed', $codeObj );
		return $codeObj;
	}

	private function checkTicket($codeObj) {
		if ($codeObj != null && isset($codeObj['order_id']) && $codeObj['order_id'] > 0) {
			$metaObj = $this->getCore()->encodeMetaValuesAndFillObject($codeObj['meta'], $codeObj);
			if (isset($metaObj['woocommerce'])
				&& $metaObj['woocommerce']['order_id'] > 0
				&& isset($metaObj['wc_ticket'])
				&& $metaObj['wc_ticket']['is_ticket'] == 1) {
				if ($metaObj['wc_ticket']['redeemed_date'] != "") {
					$codeObj['_valid'] = 8; // ticket redeemed
				}
			}
		}
		$codeObj = apply_filters( $this->MAIN->_add_filter_prefix.'frontend_checkTicket', $codeObj );
		return $codeObj;
	}

	private function addNewUsedEntryToMetaObject($metaObj) {
		// darf auf used setzen, die letzte IP wird genutzt.
		if (!isset($metaObj['used'])) $metaObj['used'] = [];
		$metaObj['used']['reg_request'] = date("Y-m-d H:i:s", current_time("timestamp"));
		$metaObj['used']['reg_request_tz'] = date_default_timezone_get();
		$metaObj['used']['reg_ip'] = $this->getCore()->getRealIpAddr();
		if ($this->MAIN->getOptions()->isOptionCheckboxActive('oneTimeUseOfRegisterCodeWPuser')) {
			$metaObj['used']['reg_userid'] = get_current_user_id();
		}
		$metaObj = apply_filters( $this->MAIN->_add_filter_prefix.'frontend_addNewUsedEntryToMetaObject', $metaObj );
		return $metaObj;
	}

	private function addJSRedirectToObject($codeObj) {
		$url = $this->MAIN->getTicketHandler()->getUserRedirectURLForCode($codeObj);

		// füge die in das codeobj ein
		if (!empty($url)) {
			$optionBtnLabel = esc_attr($this->MAIN->getOptions()->getOptionValue('userJSRedirectBtnLabel'));
			if(!isset($codeObj['_retObject'])) $codeObj['_retObject'] = [];
			$codeObj['_retObject']['userJSRedirect'] = ['url'=>$url, 'btnlabel'=>$optionBtnLabel];
		}

		return $codeObj;
	}

	private function getJSRedirect($codeObj) {
		if ($this->MAIN->getOptions()->isOptionCheckboxActive('userJSRedirectActiv')) {
			if ($codeObj['_valid'] == 1) {
				$codeObj = $this->addJSRedirectToObject($codeObj);
			} else if ($codeObj['_valid'] == 3) { // is registered already
				if ($this->MAIN->getOptions()->isOptionCheckboxActive('userJSRedirectIfSameUserRegistered')) {					//
					$codeObj = $this->addJSRedirectToObject($codeObj);
				}
			}
		}
		return $codeObj;
	}

	public function countConfirmedStatus($codeObj, $force=false) {
		if (isset($codeObj['aktiv']) && $codeObj['aktiv'] == 1) {
			if ((isset($codeObj['_valid']) && $codeObj['_valid'] == 1) || $force) {
				$metaObj = [];
				if (isset($codeObj["metaObj"])) {
					$metaObj = $codeObj["metaObj"];
				} else {
					$metaObj = $this->getCore()->encodeMetaValuesAndFillObject($codeObj['meta'], $codeObj);
				}
				$confirmedCount = isset($metaObj['confirmedCount']) ? intval($metaObj['confirmedCount']) : 0;
				if ($confirmedCount == 0) {
					$metaObj['validation']['first_success'] = date("Y-m-d H:i:s", current_time("timestamp"));
					$metaObj['validation']['first_success_tz'] = wp_timezone_string();
					$metaObj['validation']['first_ip'] = $this->getCore()->getRealIpAddr();
				}
				$metaObj['validation']['last_success'] = date("Y-m-d H:i:s", current_time("timestamp"));
				$metaObj['validation']['last_success_tz'] = wp_timezone_string();

				$metaObj['confirmedCount'] = $confirmedCount + 1;
				$codeObj['meta'] = $this->getCore()->json_encode_with_error_handling($metaObj);
				$this->getDB()->update("codes", ["meta"=>$codeObj['meta']], ['id'=>$codeObj['id']]);
				if (isset($codeObj["metaObj"])) {
					$codeObj["metaObj"] = $metaObj;
				}
			}
		}
		do_action( $this->MAIN->_do_action_prefix.'frontend_countConfirmedStatus', $codeObj, $force );
		return $codeObj;
	}

	private function setStatusMessages($codeObj) {
		if(!isset($codeObj['_retObject'])) $codeObj['_retObject'] = [];
		switch ($codeObj['_valid']) {
			case 0:
				$codeObj['_retObject']['message'] = ['ok'=>false, 'text'=>$this->MAIN->getOptions()->getOptionValue('textValidationMessage'.$codeObj['_valid'])];
				break;
			case 1:
				$codeObj['_retObject']['message'] = ['ok'=>true, 'text'=>$this->MAIN->getOptions()->getOptionValue('textValidationMessage'.$codeObj['_valid'])];
				break;
			case 2:
				$codeObj['_retObject']['message'] = ['ok'=>false, 'text'=>$this->MAIN->getOptions()->getOptionValue('textValidationMessage'.$codeObj['_valid'])];
				break;
			case 3:
				$codeObj['_retObject']['message'] = ['ok'=>true, 'text'=>$this->MAIN->getOptions()->getOptionValue('textValidationMessage'.$codeObj['_valid'])];
				break;
			case 4:
				$codeObj['_retObject']['message'] = ['ok'=>true, 'text'=>$this->MAIN->getOptions()->getOptionValue('textValidationMessage'.$codeObj['_valid'])];
				break;
			case 5:
				$codeObj['_retObject']['message'] = ['ok'=>true, 'text'=>$this->MAIN->getOptions()->getOptionValue('textValidationMessage'.$codeObj['_valid'])];
				break;
			case 6:
				$codeObj['_retObject']['message'] = ['ok'=>false, 'text'=>$this->MAIN->getOptions()->getOptionValue('textValidationMessage'.$codeObj['_valid'])];
				break;
			case 7:
				$codeObj['_retObject']['message'] = ['ok'=>false, 'text'=>$this->MAIN->getOptions()->getOptionValue('textValidationMessage'.$codeObj['_valid'])];
				break;
			case 8:
				$codeObj['_retObject']['message'] = ['ok'=>false, 'text'=>$this->MAIN->getOptions()->getOptionValue('textValidationMessage'.$codeObj['_valid'])];
				break;
								}
		if (isset($codeObj['_retObject']['message']['text']) && !empty($codeObj['_retObject']['message']['text'])) {
			$codeObj['_retObject']['message']['text'] = $this->getCore()->replaceURLParameters($codeObj['_retObject']['message']['text'], $codeObj);
		}
		$codeObj = apply_filters( $this->MAIN->_add_filter_prefix.'frontend_setStatusMessages', $codeObj );
		return $codeObj;
	}

	private function displayMessageValue($codeObj) {
		if ($this->MAIN->getOptions()->isOptionCheckboxActive('displayUserRegistrationOfCode')) {
			if ($codeObj['_valid'] == 3) {
				$metaObj = $this->getCore()->encodeMetaValuesAndFillObject($codeObj['meta'], $codeObj);
				if (isset($metaObj['user']) && isset($metaObj['user']['value'])) {
					if(!isset($codeObj['_retObject'])) $codeObj['_retObject'] = [];
					$text = "";
					if (isset($codeObj['_retObject']['message']) && !empty($codeObj['_retObject']['message']['text'])) $text = $codeObj['_retObject']['message']['text']."<br>";
					$preText = $this->MAIN->getOptions()->getOptionValue('displayUserRegistrationPreText');
					$afterText = $this->MAIN->getOptions()->getOptionValue('displayUserRegistrationAfterText');
					if (!empty($preText)) $text .= $preText."<br>";
					$text .= htmlentities($metaObj['user']['value']);
					if (!empty($afterText)) $text .= "<br>".$afterText;
					$codeObj['_retObject']['message'] = ['ok'=>true, 'text'=>$text];
				}
			}
		}

		if ($codeObj['_valid'] == 1) {
			$metaObj = $this->getCore()->encodeMetaValuesAndFillObject($codeObj['meta'], $codeObj);

			$date_format = $this->MAIN->getOptions()->getOptionDateFormat();

			if ($codeObj['list_id'] != 0) {
				if ($this->MAIN->getOptions()->isOptionCheckboxActive('displayCodeListDescriptionIfValid')) {
					// hole code list
					$listObj = $this->getCore()->getListById($codeObj['list_id']);
					$metaObj = $this->getCore()->encodeMetaValuesAndFillObjectList($listObj['meta']);
					// setze message
					if (isset($metaObj['desc']) && !empty($metaObj['desc'])) {
						if(!isset($codeObj['_retObject'])) $codeObj['_retObject'] = [];
						$text = "";
						if (isset($codeObj['_retObject']['message']) && !empty($codeObj['_retObject']['message']['text'])) $text = $codeObj['_retObject']['message']['text']."<br>";
						$text .= htmlentities($metaObj['desc']);
						$codeObj['_retObject']['message'] = ['ok'=>true, 'text'=>$text, 'color'=>'', 'weight'=>'normal']; // normale schriftfarbe
					}
				}
			}

			if ($this->MAIN->getOptions()->isOptionCheckboxActive('displayCodeInfoFirstCheck')) {
				$label = $this->MAIN->getOptions()->getOptionValue('displayCodeInfoFirstCheckLabel');
				if (strpos($label, '{VALIDATION-FIRST_SUCCESS}') === false ) {
					$label .= " {VALIDATION-FIRST_SUCCESS}";
				}
				$label = str_replace('{VALIDATION-FIRST_SUCCESS}', date($date_format, strtotime($metaObj['validation']['first_success'])), $label);
				$label = str_replace('{VALIDATION-FIRST_SUCCESS_TZ}', $metaObj['validation']['first_success_tz'], $label);
				$codeObj['_retObject']['message']['text'] .= "<br>".$label;
			}

			if ($this->MAIN->getOptions()->isOptionCheckboxActive('displayCodeInfoLastCheck')) {
				$label = $this->MAIN->getOptions()->getOptionValue('displayCodeInfoLastCheckLabel');
				if (strpos($label, '{VALIDATION-LAST_SUCCESS}') === false ) {
					$label .= " {VALIDATION-LAST_SUCCESS}";
				}
				$label = str_replace('{VALIDATION-LAST_SUCCESS}', date($date_format, strtotime($metaObj['validation']['last_success'])), $label);
				$label = str_replace('{VALIDATION-LAST_SUCCESS_TZ}', $metaObj['validation']['last_success_tz'], $label);
				$codeObj['_retObject']['message']['text'] .= "<br>".$label;
			}

			if ($this->MAIN->getOptions()->isOptionCheckboxActive('displayCodeInfoConfirmedCount')) {
				$label = $this->MAIN->getOptions()->getOptionValue('displayCodeInfoConfirmedCountLabel');
				if (strpos($label, '{CONFIRMEDCOUNT}') === false ) {
					$label .= " {CONFIRMEDCOUNT}";
				}
				$label = str_replace('{CONFIRMEDCOUNT}', intval($metaObj['confirmedCount']), $label);
				$codeObj['_retObject']['message']['text'] .= "<br>".$label;
			}
		}

		if ($codeObj['_valid'] == 7) {
			$codeObj['_retObject']['message'] = ['ok'=>false, 'text'=>$this->MAIN->getOptions()->getOptionValue('textValidationMessage7')];
		}

		if (isset($codeObj['_retObject']['message']['text']) && !empty($codeObj['_retObject']['message']['text'])) {
			$codeObj['_retObject']['message']['text'] = $this->getCore()->replaceURLParameters($codeObj['_retObject']['message']['text'], $codeObj);
		}
		$codeObj = apply_filters( $this->MAIN->_add_filter_prefix.'frontend_displayMessageValue', $codeObj );
		return $codeObj;
	}

	public function checkCode($data) {
		if (!isset($data['code']) || trim($data['code']) == "") throw new Exception("#1001 code parameter is missing");

		$data = apply_filters($this->MAIN->_add_filter_prefix.'beforeCheckCodePre', $data);
		if ($this->MAIN->isPremium() && method_exists($this->MAIN->getPremiumFunctions(), 'beforeCheckCodePre')) {
			 $data = $this->MAIN->getPremiumFunctions()->beforeCheckCodePre($data);
		}

		$data = $this->checkIfOnlyLoggedInIsAffected($data);

		if ($this->MAIN->isPremium() && method_exists($this->MAIN->getPremiumFunctions(), 'beforeCheckCode')) {
			$data = $this->MAIN->getPremiumFunctions()->beforeCheckCode($data);
		}
		$data = apply_filters($this->MAIN->_add_filter_prefix.'beforeCheckCode', $data);

		$valid = 1;
		try {
			$codeObj = $this->getCore()->retrieveCodeByCode($data['code'], false);
			$codeObj['_data_code'] = urlencode(trim($data['code']));
			if ($codeObj['aktiv'] != 1) $valid = 2;
			if ($codeObj['aktiv'] == 2) $valid = 7; // stolen

			if ($valid == 1 && $codeObj['cvv'] != "") {
				$valid = 6; // ask for CVV
				if (isset($data['cvv']) && $data['cvv'] != "") {
					if (strtoupper($data['cvv']) == strtoupper($codeObj['cvv'])) {
						$valid = 1;
					}
				}
			}

			if ($valid == 1) {
				if($this->getCore()->checkCodeExpired($codeObj)) {
					$valid = 4;
				} else if($this->getCore()->isCodeIsRegistered($codeObj)) {
					$valid = 3;
				}
			}
		} catch (Exception $e) {
			$valid = 0; // not found
		}
		$codeObj['_valid'] = $valid;
		$codeObj['_data_code'] = urlencode(trim($data['code']));

		$codeObj = $this->setStatusMessages($codeObj); // muss später nochmal ausgeführt werden, falls sich das valid nochmal ändert

		if ($this->MAIN->isPremium() && method_exists($this->MAIN->getPremiumFunctions(), 'afterCheckCodePre')) {
			$codeObj = $this->MAIN->getPremiumFunctions()->afterCheckCodePre($codeObj);
		}
		$codeObj = apply_filters($this->MAIN->_add_filter_prefix.'afterCheckCodePre', $codeObj);

		if (count($codeObj) > 1 && isset($codeObj['id']) && !empty($codeObj['id'])) {
			if ($codeObj['_valid'] != 6) { // cvv check request
				// codeObj is found
				$codeObj = $this->markAsUsed($codeObj);
				$codeObj = $this->checkTicket($codeObj);
				$codeObj = $this->getJSRedirect($codeObj);
				$codeObj = $this->countConfirmedStatus($codeObj);
				$codeObj = $this->setStatusMessages($codeObj); // nochmal, falls sich das valid nochmal geändert hat
				$codeObj = $this->displayMessageValue($codeObj);
			}
		}

		$this->getCore()->triggerWebhooks($codeObj['_valid'], $codeObj);

		if ($this->MAIN->isPremium() && method_exists($this->MAIN->getPremiumFunctions(), 'afterCheckCode')) {
			$codeObj = $this->MAIN->getPremiumFunctions()->afterCheckCode($codeObj);
		}
		$codeObj = apply_filters($this->MAIN->_add_filter_prefix.'afterCheckCode', $codeObj);

		$ret = ['valid'=>$codeObj['_valid']];
		if (isset($codeObj['_retObject'])) $ret['retObject'] = $codeObj['_retObject'];
		return $ret;
	}

	public function getOptions() {
		return $this->MAIN->getOptions()->getOptionsOnlyPublic();
	}

	private function registerToCode($data) {
		if(!isset($data['code'])) throw new Exception("#9201 code parameter missing");
		if(!isset($data['value'])) throw new Exception("#9202 value parameter missing");
		$codeObj = $this->getCore()->retrieveCodeByCode($data['code']);
		if ($codeObj['aktiv'] != 1) throw new Exception("#9205 ticket number not correct");
		if ($this->getCore()->checkCodeExpired($codeObj)) throw new Exception("#9206 ticket expired");
		if ($this->getCore()->isCodeIsRegistered($codeObj)) throw new Exception("#9207 ticket already taken - cannot register user to this ticket");
		// speicher registrierung
		$metaObj = $this->getCore()->encodeMetaValuesAndFillObject($codeObj['meta'], $codeObj);
		$metaObj['user']['value'] = htmlentities($data['value']);
		$metaObj['user']['reg_ip'] = $this->getCore()->getRealIpAddr();
		$metaObj['user']['reg_approved'] = 1; // auto approval
		$metaObj['user']['reg_request'] = date("Y-m-d H:i:s", current_time("timestamp"));
		$metaObj['user']['reg_request_tz'] = date_default_timezone_get();
		$metaObj['user']['reg_userid'] = 0;
		if ($this->MAIN->getOptions()->isOptionCheckboxActive('allowUserRegisterCodeWPuserid')) {
			$metaObj['user']['reg_userid'] = get_current_user_id();
		}
		$codeObj['meta'] = $this->getCore()->json_encode_with_error_handling($metaObj);
		$this->getDB()->update("codes", ["meta"=>$codeObj['meta'], "user_id"=>$metaObj['user']['reg_userid']], ['id'=>$codeObj['id']]);
		// send webhook if activated
		$this->getCore()->triggerWebhooks(7, $codeObj);
		do_action( $this->MAIN->_do_action_prefix.'frontend_registerToCode', $data, $codeObj );
		return $metaObj;
	}
}
?>