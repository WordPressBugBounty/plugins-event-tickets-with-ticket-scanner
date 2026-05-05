<?php
include_once(plugin_dir_path(__FILE__)."init_file.php");
class sasoEventtickets_AdminSettings {
	private $MAIN;

	private $_CACHE = [];

	public function __construct($MAIN) {
		$this->MAIN = $MAIN;
	}
	public static function plugin_uninstall(){
		//delete_option
		//delete tabellen
	}
	public function executeJSON($a, $data=[], $just_ret=false, $skipNonceTest=true) {
		$ret = "";
		$justJSON = false;

		if (!$skipNonceTest) {
			$nonce_mode = $this->MAIN->_js_nonce;
			if (!wp_verify_nonce(SASO_EVENTTICKETS::getRequestPara('nonce'), $nonce_mode)) {
				if ($just_ret) throw new Exception("Security check failed");
				return wp_send_json_error ("Security check failed");
			}
		}

		// Defense in depth: require manage_options for sensitive actions
		$sensitive_actions = [
			'changeOption', 'resetOptions', 'deleteOptions', 'exportOptions', 'importOptions', 'applyWizardPreset', 'applyPremiumDefaults', 'checkPremiumUpdate', 'recheckLicense', 'migrateOptionsToCustomTable', 'getOptionsHistory', 'revertOption', 'cleanupOptionsHistory', 'dismissSuggestion',
			'emptyTableCodes', 'emptyTableLists', 'emptyTableErrorLogs',
			'removeCode', 'removeCodes', 'removeAllCodesFromList',
			'addAuthtoken', 'editAuthtoken', 'removeAuthtoken',
			'repairTables', 'expose_desctables', 'testing',
			'addList', 'editList', 'removeList',
			'addCode', 'addCodes', 'editCode',
			'removeWoocommerceOrderInfoFromCode', 'removeWoocommerceRstrPurchaseInfoFromCode',
			'removeUserRegistrationFromCode', 'removeUsedInformationFromCode',
			'removeUsedInformationFromCodeBulk', 'editTicketMetaEntry',
			'getAllDaychooserCalendarData', 'getProductCalendarDetails', 'getRedemptionSummary', 'getRedemptionDetails', 'downloadRedemptionSummary',
			'downloadSoldTicketsCSV', 'downloadErrorLogsCSV', 'checkLicenseServer',
		];
		if (in_array(trim($a), $sensitive_actions) && !current_user_can('manage_options')) {
			if (!$this->MAIN->isUserAllowedToAccessAdminArea()) {
				if ($just_ret) throw new Exception("Permission denied");
				return wp_send_json_error("Permission denied", 403);
			}
		}

		try {
			switch (trim($a)) {
				case "getAuthtokens":
					$ret = $this->getAuthtokens();
					break;
				case "getAuthtoken":
					$ret = $this->getAuthtoken($data);
					break;
				case "addAuthtoken":
					$ret = $this->addAuthtoken($data);
					break;
				case "editAuthtoken":
					$ret = $this->editAuthtoken($data);
					break;
				case "removeAuthtoken":
					$ret = $this->removeAuthtoken($data);
					break;
				case "getLists":
					$ret = $this->getLists();
					break;
				case "getList":
					$ret = $this->getList($data);
					break;
				case "addList":
					$ret = $this->addList($data);
					break;
				case "editList":
					$ret = $this->editList($data);
					break;
				case "removeList":
					$ret = $this->removeList($data);
					break;
				case "getCodes":
					$ret = $this->getCodes($data, SASO_EVENTTICKETS::getRequest());
					$justJSON = true;
					break;
				case "addCode":
					$ret = $this->addCode($data);
					break;
				case "addCodes":
					$ret = $this->addCodes($data);
					break;
				case "editCode":
					$ret = $this->editCode($data);
					break;
				case "removeCode":
					$ret = $this->removeCode($data);
					break;
				case "removeCodes":
					$ret = $this->removeCodes($data);
					break;
				case "removeAllCodesFromList":
					$ret = $this->removeAllCodesFromList($data);
					break;
				case "emptyTableLists":
					$ret = $this->emptyTableLists($data);
					break;
				case "emptyTableCodes":
					$ret = $this->emptyTableCodes($data);
					break;
				case "exportTableCodes":
					$ret = $this->exportTableCodes($data);
					break;
				case "getErrorLogs":
					$ret = $this->getErrorLogs($data, SASO_EVENTTICKETS::getRequest());
					$justJSON = true;
					break;
				case "emptyTableErrorLogs":
					$ret = $this->emptyTableErrorLogs();
					break;
				case "premium":
					$ret = $this->executeJSONPremium($data);
					break;
				case "removeWoocommerceOrderInfoFromCode":
					$ret = $this->removeWoocommerceOrderInfoFromCode($data);
					break;
				case "removeWoocommerceRstrPurchaseInfoFromCode":
					$ret = $this->removeWoocommerceRstrPurchaseInfoFromCode($data);
					break;
				case "setWoocommerceTicketForCode":
					$ret = $this->setWoocommerceTicketForCode($data);
					break;
				case "redeemWoocommerceTicketForCode":
					$ret = $this->redeemWoocommerceTicketForCode($data);
					break;
				case "removeRedeemWoocommerceTicketForCode":
					$ret = $this->removeRedeemWoocommerceTicketForCode($data);
					break;
				case "removeRedeemWoocommerceTicketForCodeBulk":
					$ret = $this->removeRedeemWoocommerceTicketForCodeBulk($data);
					break;
				case "assignTicketListToTicketsBulk":
					$ret = $this->assignTicketListToTicketsBulk($data);
					break;
				case "generateOnePDFForTicketsBulk":
					$ret = $this->generateOnePDFForTicketsBulk($data);
					break;
				case "generateOnePDFForBadgesBulk":
					$ret = $this->generateOnePDFForBadgesBulk($data);
					break;
				case "removeWoocommerceTicketForCode":
					$ret = $this->removeWoocommerceTicketForCode($data);
					break;
				case "getOptions":
					$ret = $this->getOptions();
					break;
				case "changeOption":
					$ret = $this->changeOption($data);
					break;
				case "applyWizardPreset":
					$ret = $this->applyWizardPreset($data);
					break;
				case "applyPremiumDefaults":
					$ret = $this->applyPremiumDefaults($data);
					break;
				case "checkPremiumUpdate":
					$ret = $this->checkPremiumUpdate();
					break;
				case "recheckLicense":
					$ret = $this->recheckLicense();
					break;
				case "migrateOptionsToCustomTable":
					$ret = $this->migrateOptionsToCustomTable();
					break;
				case "getOptionsHistory":
					$ret = $this->getOptionsHistory($data, SASO_EVENTTICKETS::getRequest());
					$justJSON = true;
					break;
				case "revertOption":
					$ret = $this->revertOption($data);
					break;
				case "cleanupOptionsHistory":
					$ret = $this->cleanupOptionsHistory();
					break;
				case "dismissSuggestion":
					$ret = $this->dismissSuggestion($data);
					break;
				case "resetOptions":
					$ret = $this->resetOptions($data);
					break;
				case "deleteOptions":
					$ret = $this->deleteOptions($data);
					break;
				case "exportOptions":
					$ret = $this->exportOptions();
					break;
				case "importOptions":
					$ret = $this->importOptions($data);
					break;
				case "getMetaOfCode":
					$ret = $this->getMetaOfCode($data);
					break;
				case "removeUserRegistrationFromCode":
					$ret = $this->removeUserRegistrationFromCode($data);
					break;
				case "editUseridForUserRegistrationFromCode":
					$ret = $this->editUseridForUserRegistrationFromCode($data);
					break;
				case "removeUsedInformationFromCode":
					$ret = $this->removeUsedInformationFromCode($data);
					break;
				case "removeUsedInformationFromCodeBulk":
					$ret = $this->removeUsedInformationFromCodeBulk($data);
					break;
				case "editUseridForUsedInformationFromCode":
					$ret = $this->editUseridForUsedInformationFromCode($data);
					break;
				case "editTicketMetaEntry":
					$ret = $this->editTicketMetaEntry($data);
					break;
				case "repairTables":
					$ret = $this->repairTables($data);
					break;
				case "getMediaData":
					$ret = $this->getMediaData($data);
					break;
				case "getSupportInfos":
					$ret = $this->getSupportInfos($data);
					break;
				case "downloadPDFTicket":
					$ret = $this->downloadPDFTicket($data);
					break;
				case "downloadPDFTicketBadge":
					$ret = $this->downloadPDFTicketBadge($data);
					break;
				case "testing":
					$ret = $this->testing($data);
					break;
				case "expose_desctables":
					$ret = $this->expose("tables", $data);
					break;
				case "seating":
					$ret = $this->executeJSONSeating($data);
					break;
				case "ping":
					$ret = $this->ping($data);
					break;
				case "getAllDaychooserCalendarData":
					$ret = $this->MAIN->getWC()->getProductManager()->getAllDaychooserCalendarData($data);
					break;
				case "getProductCalendarDetails":
					$ret = $this->MAIN->getWC()->getProductManager()->getProductCalendarDetails($data);
					break;
				case "getRedemptionSummary":
					$ret = $this->getRedemptionSummary($data);
					break;
				case "getRedemptionDetails":
					$ret = $this->getRedemptionDetails($data);
					break;
				case "downloadRedemptionSummary":
					$this->downloadRedemptionSummary($data);
					break;
				case "downloadSoldTicketsCSV":
					$this->downloadSoldTicketsCSV($data);
					break;
				case "downloadErrorLogsCSV":
					$this->downloadErrorLogsCSV();
					break;
				case "checkLicenseServer":
					$ret = $this->checkLicenseServer($data);
					break;
				default:
					throw new Exception(sprintf(esc_html__('function "%s" not implemented', 'event-tickets-with-ticket-scanner'), $a));
			}
		} catch(Exception $e) {
			$this->logErrorToDB($e, "executeJSON", __FILE__." on Line: ".__LINE__);
			if ($just_ret) throw $e;
			if ($justJSON) return ["error"=>$e->getMessage()];
			return wp_send_json_error ($e->getMessage());
		}
		if ($just_ret) return $ret;
		if ($justJSON) return wp_send_json($ret);
		else {
			if (is_array($ret) && count($ret) > 0 && SASO_EVENTTICKETS::is_assoc_array($ret) && !isset($ret['nonce'])) {
				$ret['nonce'] = wp_create_nonce( $this->MAIN->_js_nonce );
			}
			return wp_send_json_success( $ret );
		}
	}
	private function ping($data) {
		return ["ret"=>"pong", "timestamp"=>time(), "date"=>wp_date("Y-m-d H:i:s")];
	}
	private function executeJSONPremium($data) {
		if (!$this->MAIN->isPremium() || !method_exists($this->MAIN->getPremiumFunctions(), "executeJSON")) {
			throw new Exception("#9001 premium is not active");
		}
		if (!isset($data['c'])) throw new Exception("#9002 premium action paramter is missing");
		return $this->MAIN->getPremiumFunctions()->executeJSON($data['c'], $data);
	}

	private function executeJSONSeating($data) {
		if (!isset($data['c'])) throw new Exception("#8501 seating action parameter is missing");
		return $this->MAIN->getSeating()->executeJSON($data['c'], $data);
	}

	/**
	 * Daily Redemption Summary — aggregated redeemed ticket counts by date and list/product.
	 * Premium feature (#236).
	 *
	 * @param array $data {date_from?: string, date_to?: string} Y-m-d format, defaults to today
	 * @return array {summary: {total_redeemed, total_codes, date_from, date_to}, rows: [...]}
	 */
	public function getRedemptionSummary(array $data): array {
		if (!$this->MAIN->isPremium()) {
			throw new Exception("#9701 premium is required for redemption summary");
		}

		$today = wp_date('Y-m-d');
		$date_from = isset($data['date_from']) && $data['date_from'] !== '' ? sanitize_text_field($data['date_from']) : $today;
		$date_to   = isset($data['date_to'])   && $data['date_to']   !== '' ? sanitize_text_field($data['date_to'])   : $today;

		if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to)) {
			throw new Exception("#9702 invalid date format — expected Y-m-d");
		}

		$db = $this->MAIN->getDB();
		$codes_table = $db->getTabelle('codes');
		$lists_table = $db->getTabelle('lists');

		// 1. Build list_id → {list_name, product_id, product_name} mapping
		$lists = $db->_db_datenholen("SELECT id, name FROM {$lists_table}");
		$list_map = [];
		foreach ($lists as $list) {
			$list_map[(int)$list['id']] = [
				'list_name'    => $list['name'],
				'product_id'   => 0,
				'product_name' => '',
			];
		}

		// Find WooCommerce products linked to each list
		global $wpdb;
		$product_links = $wpdb->get_results(
			"SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = 'saso_eventtickets_list' AND meta_value != ''",
			ARRAY_A
		);
		foreach ($product_links as $link) {
			$lid = (int)$link['meta_value'];
			if (isset($list_map[$lid]) && $list_map[$lid]['product_id'] === 0) {
				$pid = (int)$link['post_id'];
				$list_map[$lid]['product_id']   = $pid;
				$list_map[$lid]['product_name'] = get_the_title($pid);
			}
		}

		// 2. Get total codes per list (for "total_in_list" column)
		$totals_raw = $db->_db_datenholen("SELECT list_id, COUNT(*) as cnt FROM {$codes_table} GROUP BY list_id");
		$totals_per_list = [];
		foreach ($totals_raw as $row) {
			$totals_per_list[(int)$row['list_id']] = (int)$row['cnt'];
		}

		// 2b. Get no-show count per list (unredeemed WC tickets)
		$no_show_raw = $db->_db_datenholen(
			"SELECT list_id, COUNT(*) as cnt FROM {$codes_table}
			 WHERE redeemed = 0 AND JSON_EXTRACT(meta, '$.wc_ticket.is_ticket') = 1
			 GROUP BY list_id"
		);
		$no_show_per_list = [];
		foreach ($no_show_raw as $row) {
			$no_show_per_list[(int)$row['list_id']] = (int)$row['cnt'];
		}

		// 3. Fetch all redeemed codes with meta
		$redeemed_codes = $db->_db_datenholen(
			"SELECT id, list_id, meta FROM {$codes_table} WHERE redeemed = 1"
		);

		// 4. Aggregate by date + list_id
		$agg = []; // key: "Y-m-d|list_id" → count
		$total_redeemed = 0;

		foreach ($redeemed_codes as $code) {
			$meta = @json_decode($code['meta'], true);
			if (!is_array($meta)) continue;

			$wc = $meta['wc_ticket'] ?? [];
			$redemption_dates = [];

			// Multi-use tickets: each entry in stats_redeemed is a separate redemption
			$stats = $wc['stats_redeemed'] ?? [];
			if (is_array($stats) && count($stats) > 0) {
				foreach ($stats as $stat) {
					if (!empty($stat['redeemed_date'])) {
						$redemption_dates[] = substr($stat['redeemed_date'], 0, 10);
					}
				}
			} elseif (!empty($wc['redeemed_date'])) {
				// Single-use: only redeemed_date
				$redemption_dates[] = substr($wc['redeemed_date'], 0, 10);
			}

			$list_id = (int)$code['list_id'];
			foreach ($redemption_dates as $rd) {
				if ($rd < $date_from || $rd > $date_to) continue;
				$key = $rd . '|' . $list_id;
				if (!isset($agg[$key])) {
					$agg[$key] = 0;
				}
				$agg[$key]++;
				$total_redeemed++;
			}
		}

		// 5. Build result rows
		$rows = [];
		foreach ($agg as $key => $count) {
			[$date, $lid_str] = explode('|', $key);
			$lid = (int)$lid_str;
			$info = $list_map[$lid] ?? ['list_name' => '', 'product_id' => 0, 'product_name' => ''];
			$rows[] = [
				'date'           => $date,
				'list_id'        => $lid,
				'list_name'      => $info['list_name'],
				'product_id'     => $info['product_id'],
				'product_name'   => $info['product_name'],
				'redeemed_count' => $count,
				'total_in_list'  => $totals_per_list[$lid] ?? 0,
				'no_show_count'  => $no_show_per_list[$lid] ?? 0,
			];
		}

		// Sort by date DESC, list_name ASC
		usort($rows, function ($a, $b) {
			$cmp = strcmp($b['date'], $a['date']);
			return $cmp !== 0 ? $cmp : strcmp($a['list_name'], $b['list_name']);
		});

		// Total codes in all lists that have redemptions in the date range
		$involved_lists = array_unique(array_column($rows, 'list_id'));
		$total_codes = 0;
		$total_no_show = 0;
		foreach ($involved_lists as $lid) {
			$total_codes += $totals_per_list[$lid] ?? 0;
			$total_no_show += $no_show_per_list[$lid] ?? 0;
		}

		return [
			'summary' => [
				'total_redeemed' => $total_redeemed,
				'total_codes'    => $total_codes,
				'total_no_show'  => $total_no_show,
				'date_from'      => $date_from,
				'date_to'        => $date_to,
			],
			'rows' => $rows,
		];
	}

	/**
	 * Get individual redeemed ticket codes for a specific date + list combination.
	 * Drill-down data for the Attendance table (#236).
	 */
	public function getRedemptionDetails(array $data): array {
		if (!$this->MAIN->isPremium()) {
			throw new Exception("#9703 premium is required for redemption details");
		}

		$date = isset($data['date']) ? sanitize_text_field($data['date']) : '';
		$list_id = isset($data['list_id']) ? (int)$data['list_id'] : 0;

		if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
			throw new Exception("#9704 invalid date format — expected Y-m-d");
		}
		if ($list_id <= 0) {
			throw new Exception("#9705 invalid list_id");
		}

		$db = $this->MAIN->getDB();
		$codes_table = $db->getTabelle('codes');

		$redeemed_codes = $db->_db_datenholen(
			"SELECT code, code_display, order_id, meta FROM {$codes_table} WHERE list_id = " . intval($list_id) . " AND redeemed = 1"
		);

		$results = [];
		foreach ($redeemed_codes as $code) {
			$meta = @json_decode($code['meta'], true);
			if (!is_array($meta)) continue;

			$wc = $meta['wc_ticket'] ?? [];

			// Multi-use tickets
			$stats = $wc['stats_redeemed'] ?? [];
			if (is_array($stats) && count($stats) > 0) {
				foreach ($stats as $stat) {
					if (!empty($stat['redeemed_date'])) {
						$rd = substr($stat['redeemed_date'], 0, 10);
						if ($rd === $date) {
							$results[] = [
								'code'          => $code['code'],
								'code_display'  => $code['code_display'] ?: $code['code'],
								'order_id'      => (int)$code['order_id'],
								'redeemed_date' => $rd,
								'redeemed_time' => substr($stat['redeemed_date'], 11, 8),
							];
						}
					}
				}
			} elseif (!empty($wc['redeemed_date'])) {
				$rd = substr($wc['redeemed_date'], 0, 10);
				if ($rd === $date) {
					$results[] = [
						'code'          => $code['code'],
						'code_display'  => $code['code_display'] ?: $code['code'],
						'order_id'      => (int)$code['order_id'],
						'redeemed_date' => $rd,
						'redeemed_time' => substr($wc['redeemed_date'], 11, 8),
					];
				}
			}
		}

		usort($results, function ($a, $b) {
			return strcmp($a['redeemed_time'], $b['redeemed_time']);
		});

		return $results;
	}

	/**
	 * Download the redemption summary as CSV (#236).
	 */
	private function downloadRedemptionSummary(array $data): void {
		$result = $this->getRedemptionSummary($data);

		$csvRows = [];
		foreach ($result['rows'] as $row) {
			$csvRows[] = [
				'Date'          => $row['date'],
				'Event / List'  => $row['list_name'],
				'Product'       => $row['product_name'],
				'Product ID'    => $row['product_id'],
				'Redeemed'      => $row['redeemed_count'],
				'Total in List' => $row['total_in_list'],
				'No Show'       => $row['no_show_count'],
			];
		}

		$filename = 'redemption-summary-' . $result['summary']['date_from'] . '-to-' . $result['summary']['date_to'] . '.csv';
		SASO_EVENTTICKETS::_basics_sendeDateiCSVvonDBdaten($csvRows, $filename, ',');
		exit;
	}

	/**
	 * Download the sold tickets calendar data as CSV.
	 */
	private function downloadSoldTicketsCSV(array $data): void {
		$result = $this->MAIN->getWC()->getProductManager()->getAllDaychooserCalendarData($data);

		$csvRows = [];
		if (!empty($result['dates'])) {
			foreach ($result['dates'] as $date => $entries) {
				foreach ($entries as $entry) {
					$csvRows[] = [
						'Date'         => $date,
						'Product'      => $entry['product_name'],
						'Product ID'   => $entry['product_id'],
						'Ticket Count' => $entry['count'],
					];
				}
			}
		}

		$dateFrom = $result['date_from'] ?? ($data['date_from'] ?? wp_date('Y-m-d'));
		$dateTo = $result['date_to'] ?? ($data['date_to'] ?? wp_date('Y-m-d'));
		$filename = 'sold-tickets-' . $dateFrom . '-to-' . $dateTo . '.csv';
		SASO_EVENTTICKETS::_basics_sendeDateiCSVvonDBdaten($csvRows, $filename, ',');
		exit;
	}

	private function downloadErrorLogsCSV(): void {
		$sql = "SELECT time, exception_msg, caller_name, msg FROM " . $this->MAIN->getDB()->getTabelle("errorlogs") . " ORDER BY time DESC";
		$rows = $this->MAIN->getDB()->_db_datenholen($sql);

		$csvRows = [];
		foreach ($rows as $row) {
			$csvRows[] = [
				'Time'      => $row['time'] ?? '',
				'Exception' => $row['exception_msg'] ?? '',
				'Caller'    => $row['caller_name'] ?? '',
				'Message'   => $row['msg'] ?? '',
			];
		}

		$filename = 'error-logs_' . wp_date('Y-m-d') . '.csv';
		SASO_EVENTTICKETS::_basics_sendeDateiCSVvonDBdaten($csvRows, $filename, ',');
		exit;
	}

	private function repairTables() {
		$this->MAIN->getDB()->installiereTabellen(true);
		do_action($this->MAIN->_do_action_prefix."repairTables", true);

		// aktualisiere nicht erfasste userid für orders
		$sql = "select * from ".$this->MAIN->getDB()->getTabelle("codes")." where
			order_id != 0 and
			json_extract(meta, '$.wc_ticket.is_ticket') = 1 and
			json_extract(meta, '$.woocommerce.user_id') is null
		";
		$d = $this->MAIN->getDB()->_db_datenholen($sql);
		if (count($d) > 0) {
			foreach($d as $codeObj) {
				$metaObj = $this->MAIN->getCore()->encodeMetaValuesAndFillObject($codeObj['meta'], $codeObj);
				$order = wc_get_order( $codeObj['order_id'] );
				$metaObj['woocommerce']['user_id'] = intval($order->get_user_id());
				$codeObj['meta'] = $this->MAIN->getCore()->json_encode_with_error_handling($metaObj);
				$this->MAIN->getDB()->update("codes", ["meta"=>$codeObj['meta']], ['id'=>$codeObj['id']]);
			}
		}

		do_action( $this->MAIN->_do_action_prefix.'db_repairTables', $d );

		return "tables repair executed at ".wp_date("Y/m/d H:i:s");
	}

	private function getMediaData($data) {
		if (!isset($data['mediaid'])) throw new Exception("#9005 media id is missing");
		return SASO_EVENTTICKETS::getMediaData($data['mediaid']);
	}

	private function downloadPDFTicket($data) {
		$codeObj = $this->MAIN->getCore()->retrieveCodeByCode($data['code']);
		$this->MAIN->getTicketHandler()->setCodeObj($codeObj);
		$this->MAIN->getTicketHandler()->outputPDF("I");
		die();
	}

	public function downloadPDFTicketBadge($data) {
		$codeObj = $this->MAIN->getCore()->retrieveCodeByCode($data['code']);
		$badgeHandler = $this->MAIN->getTicketBadgeHandler();
		$badgeHandler->downloadPDFTicketBadge($codeObj);
		die();
	}

	private function getSupportInfos($data) {
		$codes_size = $this->MAIN->getDB()->getCodesSize();
		$lists_size = $this->MAIN->getDB()->_db_getRecordCountOfTable('lists');
		$ips_size = $this->MAIN->getDB()->_db_getRecordCountOfTable('ips');
		$ret = [
			"amount"=>["codes"=>$codes_size, "lists"=>$lists_size, "ips"=>$ips_size]
		];
		$ret = apply_filters( $this->MAIN->_add_filter_prefix.'admin_getSupportInfos', $ret );

		return $ret;
	}

	public function wpdocs_custom_timezone_string() {
		//$timezone_string = get_option( 'timezone_string' );
		//if (empty($timezone_string)) $timezone_string = wp_timezone_string();
		$timezone_string = wp_timezone_string();
		$offset  = (float) get_option( 'gmt_offset' );
		$hours   = (int) $offset;
		$minutes = ( $offset - $hours );
		$sign      = ( $offset < 0 ) ? '-' : '+';
		$abs_hour  = abs( $hours );
		$abs_mins  = abs( $minutes * 60 );
		$tz_offset = sprintf( '%s%02d:%02d', $sign, $abs_hour, $abs_mins );
		$timezone = $timezone_string ? $timezone_string . ' [' . $tz_offset . ']' : $tz_offset;

		$timezone = apply_filters( $this->MAIN->_add_filter_prefix.'admin_wpdocs_custom_timezone_string', $timezone );

		return $timezone;
	}

	public function getOptions() {
		global $wpdb, $wp_version ;
		$options = $this->MAIN->getOptions()->getOptions();

		$tags = $this->MAIN->getCore()->getMetaObjectAllowedReplacementTags();

		$ret = ['options'=>$options, 'meta_tags_keys'=>$tags, 'versions'=>[], 'infos'=>[]];
		if (is_admin()) {
			$pversions = $this->MAIN->getPluginVersions();
			$premium_db_version = '';
			if ($this->MAIN->isPremium() && method_exists($this->MAIN->getPremiumFunctions(), 'getDBVersion')) {
				$premium_db_version = $this->MAIN->getPremiumFunctions()->getDBVersion();
			}
			//$current = get_site_transient( 'update_core' );
			$mysql_version = 'N/A';
			if ( method_exists( $wpdb, 'db_version' ) ) {
				$mysql_version = preg_replace( '/[^0-9.].*/', '', $wpdb->db_version() );
			}
			$versions = [
				'php'=>phpversion(),
				'wp'=>$wp_version,
				'mysql'=>$mysql_version,
				'db'=>$this->MAIN->getDB()->dbversion,
				'premium_db'=>$premium_db_version,
				'basic'=>$pversions['basic'],
				'premium'=>$pversions['premium'] != "" ? $pversions['premium'] : '',
				'is_debug'=>isset($pversions['debug']) ? 1 : 0,
				'premium_serial'=>$this->getOptionValue("serial", "-"),
				'first_activated_at'=>(string) get_option('saso_eventtickets_first_activated_at', ''),
				'is_wc_available'=>class_exists( 'WooCommerce' ) ? 1 : 0,
				'IS_PRETTY_PERMALINK_ACTIVATED' => get_option('permalink_structure') ? true :false,
				'plugin_version' => defined('SASO_EVENTTICKETS_PREMIUM_PLUGIN_VERSION') ? SASO_EVENTTICKETS_PREMIUM_PLUGIN_VERSION : SASO_EVENTTICKETS_PLUGIN_VERSION,
				'requires_wp' => '6.0',
				'tested_up_to' => '6.9',
				'requires_php' => '8.1'
			];
			$versions["isOldPremiumDetected"] = $this->MAIN->isOldPremiumDetected();
			$versions["isStarterOrStopDetected"] = $this->MAIN->isStarterOrStopDetected();
			$versions["date_default_timezone"] = date_default_timezone_get();
			$versions["date_WP_timezone"] = wp_timezone_string();
			$versions["date_WP_timezone_time"] = $this->wpdocs_custom_timezone_string();
			$versions["date_default_timezone_time"] = wp_date("Y-m-d H:i:s");
			$timezone = new DateTimeZone("UTC");
			$dt = new DateTime('now', $timezone);
			$versions["date_UTC_timezone_time"] = $dt->format("Y-m-d H:i:s");

			$infos = [
				'ticket'=>[
					'ticket_base_url'=>$this->MAIN->getCore()->getTicketURLBase(true),
					'ticket_detail_path'=>$this->MAIN->getCore()->getTicketURLPath(true),
					'ticket_scanner_path'=>$this->MAIN->getCore()->getTicketURLPath(true).'scanner/',
					'ticket_scanner_url'=>$this->MAIN->getCore()->getTicketScannerURL(""),
					'counter'=>$this->MAIN->getBase()->getOverallTicketCounterValue(),
					'redeemed_count'=>(int) $this->MAIN->getDB()->_db_getRecordCountOfTable('codes', 'redeemed = 1')
				],
				'site'=>[
					'is_multisite'=>is_multisite() ? 1 : 0,
					'home'=>home_url(),
					'network_home'=>network_home_url(),
					'site_url'=>site_url()
				]
			];
			$infos["premium_expiration"] = $this->MAIN->getTicketHandler()->get_expiration();

			$tickets_for_testing = $this->getTicketsForTesting();
			$ticket_templates = $this->MAIN->getTicketDesignerHandler()->getTemplateList();
			$option_displayTimeFormat = $this->MAIN->getOptions()->getOptionTimeFormat();
			$option_displayDateFormat = $this->MAIN->getOptions()->getOptionDateFormat();
			$option_displayDateTimeFormat = $this->MAIN->getOptions()->getOptionDateTimeFormat();

			$options_special = [
				'format_date'=>$option_displayDateFormat,
				'format_time'=>$option_displayTimeFormat,
				'format_datetime'=>$option_displayDateTimeFormat,
				'option_displayTimeFormat'=>$option_displayTimeFormat,
				'option_displayDateFormat'=>$option_displayDateFormat,
				'option_displayDateTimeFormat'=>$option_displayDateTimeFormat
			];

			$ret = ['options'=>$options, 'options_special'=>$options_special, 'meta_tags_keys'=>$tags, 'versions'=>$versions, 'infos'=>$infos, 'tickets_for_testing'=>$tickets_for_testing, 'ticket_templates'=>$ticket_templates];
		}
		$ret['dismissed_suggestions'] = $this->getDismissedSuggestions();

		$ret = apply_filters( $this->MAIN->_add_filter_prefix.'admin_getOptions', $ret );

		return $ret;
	}
	public function changeOption($data) {
		$this->MAIN->getOptions()->changeOption($data);
	}
	public function resetOptions() {
		return $this->MAIN->getOptions()->resetAllOptionValuesToDefault();
	}
	public function deleteOptions() {
		return $this->MAIN->getOptions()->deleteAllOptionValues();
	}
	public function exportOptions(): array {
		$options = $this->MAIN->getOptions()->getOptions();
		$export = [];
		foreach ($options as $option) {
			if ($option['type'] === 'heading') {
				continue;
			}
			$export[$option['key']] = $this->MAIN->getOptions()->getOptionValue($option['key']);
		}
		return [
			'plugin' => 'event-tickets-with-ticket-scanner',
			'version' => $this->MAIN->getPluginVersion(),
			'exported_at' => gmdate('Y-m-d\TH:i:s\Z'),
			'options' => $export,
		];
	}
	public function importOptions(array $data): array {
		$validKeys = $this->MAIN->getOptions()->getOptionsKeys();
		$imported = 0;
		$skipped = 0;
		$raw = isset($data['options']) ? $data['options'] : '{}';
		if (is_string($raw)) {
			$raw = stripslashes($raw);
		}
		$optionsData = is_array($raw) ? $raw : json_decode($raw, true);
		if (!is_array($optionsData)) {
			$optionsData = [];
		}
		foreach ($optionsData as $key => $value) {
			if (!in_array($key, $validKeys, true)) {
				$skipped++;
				continue;
			}
			$option = $this->MAIN->getOptions()->getOption($key);
			if ($option === null || $option['type'] === 'heading') {
				$skipped++;
				continue;
			}
			$this->MAIN->getOptions()->changeOption(['key' => $key, 'value' => $value]);
			$imported++;
		}
		return ['imported' => $imported, 'skipped' => $skipped];
	}

	/**
	 * Returns the preset option values for a given use-case.
	 *
	 * @param string $preset One of: event, daypass, membership, voucher
	 * @return array<string,int> option_key => value
	 */
	public static function getWizardPresetDefaults(string $preset): array {
		$presets = [
			'event' => [
				// Redemption rules
				'wcTicketDontAllowRedeemTicketBeforeStart' => 1,
				'wcTicketOffsetAllowRedeemTicketBeforeStart' => 1,
				'wcTicketAllowRedeemTicketAfterEnd' => 0,
				// Scanner
				'ticketScannerScanAndRedeemImmediately' => 1,
				'ticketScannerVibrate' => 1,
				// Customer features
				'wcTicketShowRedeemBtnOnTicket' => 0,
				'wcTicketUserProfileDisplayRedeemAmount' => 0,
				// Email: order view + one-PDF link (groups/families)
				'wcTicketDisplayOrderTicketsViewLinkOnMail' => 1,
				'wcTicketDisplayDownloadAllTicketsPDFButtonOnMail' => 1,
				'wcTicketDisplayOrderTicketsViewLinkOnCheckout' => 1,
				// Email: event date + calendar file
				'wcTicketAttachICSToMail' => 1,
				'wcTicketDisplayDateOnMail' => 1,
				// Order processing
				'wcTicketSetOrderToCompleteIfAllOrderItemsAreTickets' => 1,
			],
			'daypass' => [
				// Redemption rules
				'wcTicketDontAllowRedeemTicketBeforeStart' => 0,
				'wcTicketOffsetAllowRedeemTicketBeforeStart' => 1,
				'wcTicketAllowRedeemTicketAfterEnd' => 1,
				// Scanner
				'ticketScannerScanAndRedeemImmediately' => 1,
				'ticketScannerVibrate' => 1,
				// Customer features
				'wcTicketShowRedeemBtnOnTicket' => 0,
				'wcTicketUserProfileDisplayRedeemAmount' => 0,
				// Email: order view + one-PDF link (families)
				'wcTicketDisplayOrderTicketsViewLinkOnMail' => 1,
				'wcTicketDisplayDownloadAllTicketsPDFButtonOnMail' => 1,
				'wcTicketDisplayOrderTicketsViewLinkOnCheckout' => 1,
				// Email: no date/calendar (day chooser or flexible)
				'wcTicketAttachICSToMail' => 0,
				'wcTicketDisplayDateOnMail' => 0,
				// Order processing
				'wcTicketSetOrderToCompleteIfAllOrderItemsAreTickets' => 1,
			],
			'membership' => [
				// Redemption rules
				'wcTicketDontAllowRedeemTicketBeforeStart' => 0,
				'wcTicketOffsetAllowRedeemTicketBeforeStart' => 1,
				'wcTicketAllowRedeemTicketAfterEnd' => 1,
				// Scanner
				'ticketScannerScanAndRedeemImmediately' => 0,
				'ticketScannerVibrate' => 1,
				// Customer features
				'wcTicketShowRedeemBtnOnTicket' => 1,
				'wcTicketUserProfileDisplayRedeemAmount' => 1,
				// Email: no group view (single pass)
				'wcTicketDisplayOrderTicketsViewLinkOnMail' => 0,
				'wcTicketDisplayDownloadAllTicketsPDFButtonOnMail' => 0,
				'wcTicketDisplayOrderTicketsViewLinkOnCheckout' => 0,
				// Email: no date/calendar
				'wcTicketAttachICSToMail' => 0,
				'wcTicketDisplayDateOnMail' => 0,
				// Order processing
				'wcTicketSetOrderToCompleteIfAllOrderItemsAreTickets' => 1,
			],
			'voucher' => [
				// Redemption rules
				'wcTicketDontAllowRedeemTicketBeforeStart' => 0,
				'wcTicketOffsetAllowRedeemTicketBeforeStart' => 1,
				'wcTicketAllowRedeemTicketAfterEnd' => 1,
				// Scanner
				'ticketScannerScanAndRedeemImmediately' => 1,
				'ticketScannerVibrate' => 1,
				// Customer features
				'wcTicketShowRedeemBtnOnTicket' => 1,
				'wcTicketUserProfileDisplayRedeemAmount' => 0,
				// Email: no group view (single code)
				'wcTicketDisplayOrderTicketsViewLinkOnMail' => 0,
				'wcTicketDisplayDownloadAllTicketsPDFButtonOnMail' => 0,
				'wcTicketDisplayOrderTicketsViewLinkOnCheckout' => 0,
				// Email: no date/calendar
				'wcTicketAttachICSToMail' => 0,
				'wcTicketDisplayDateOnMail' => 0,
				// Order processing
				'wcTicketSetOrderToCompleteIfAllOrderItemsAreTickets' => 1,
			],
		];
		if (!isset($presets[$preset])) {
			return [];
		}
		return $presets[$preset];
	}

	/**
	 * Returns premium-only preset additions for a given use-case.
	 * These are applied on top of the base preset when premium is active.
	 *
	 * @param string $preset One of: event, daypass, membership, voucher
	 * @return array<string,int> option_key => value
	 */
	public static function getWizardPresetPremiumDefaults(string $preset): array {
		$presets = [
			'event' => [
				'wcTicketAttachTicketToMail' => 1,
				'wcTicketAttachTicketToMailAsOnePDF' => 1,
				'wcTicketAttachTicketToMailMax' => 21,
			],
			'daypass' => [
				'wcTicketAttachTicketToMail' => 1,
				'wcTicketAttachTicketToMailAsOnePDF' => 1,
				'wcTicketAttachTicketToMailMax' => 21,
			],
			'membership' => [
				'wcTicketAttachTicketToMail' => 1,
				'wcTicketAttachTicketToMailAsOnePDF' => 0,
				'wcTicketAttachTicketToMailMax' => 5,
			],
			'voucher' => [
				'wcTicketAttachTicketToMail' => 1,
				'wcTicketAttachTicketToMailAsOnePDF' => 0,
				'wcTicketAttachTicketToMailMax' => 5,
			],
		];
		if (!isset($presets[$preset])) {
			return [];
		}
		return $presets[$preset];
	}

	/**
	 * Apply a wizard preset: sets multiple options at once and marks the wizard as completed.
	 *
	 * @param array $data Expected keys: 'preset' (string), optional 'overrides' (array of key=>value)
	 * @return array Result with applied count
	 */
	public function applyWizardPreset(array $data): array {
		$preset = isset($data['preset']) ? sanitize_text_field($data['preset']) : '';
		$defaults = self::getWizardPresetDefaults($preset);
		if (empty($defaults)) {
			throw new \Exception(sprintf(
				esc_html__('Unknown wizard preset: "%s"', 'event-tickets-with-ticket-scanner'),
				$preset
			));
		}

		// Merge premium defaults if premium is active
		if ($this->MAIN->isPremium()) {
			$premiumDefaults = self::getWizardPresetPremiumDefaults($preset);
			$defaults = array_merge($defaults, $premiumDefaults);
		}

		// Apply overrides from step 3 questions (comes as JSON string from JS)
		$raw_overrides = isset($data['overrides']) ? $data['overrides'] : [];
		if (is_string($raw_overrides)) {
			$raw_overrides = json_decode(stripslashes($raw_overrides), true);
			if (!is_array($raw_overrides)) {
				$raw_overrides = [];
			}
		}
		$overrides = $raw_overrides;
		$validKeys = array_keys($defaults);
		foreach ($overrides as $key => $value) {
			if (in_array($key, $validKeys, true)) {
				$defaults[$key] = $value;
			}
		}

		// Apply all preset values via the existing changeOption mechanism
		$applied = 0;
		foreach ($defaults as $key => $value) {
			$this->MAIN->getOptions()->changeOption(['key' => $key, 'value' => $value]);
			$applied++;
		}

		// Mark wizard as completed with current plugin version
		$this->MAIN->getOptions()->changeOption([
			'key' => 'wizardCompleted',
			'value' => $this->MAIN->getPluginVersion(),
		]);

		return ['applied' => $applied, 'preset' => $preset];
	}

	/**
	 * Apply premium defaults: enables recommended premium options and marks premium wizard as completed.
	 *
	 * @param array $data Currently unused, reserved for future overrides
	 * @return array Result with applied count and option details
	 */
	public function applyPremiumDefaults(array $data): array {
		if (!$this->MAIN->isPremium()) {
			throw new \Exception(
				esc_html__('Premium plugin is not active.', 'event-tickets-with-ticket-scanner')
			);
		}

		$defaults = [
			'wcTicketAttachTicketToMail' => 1,
			'wcTicketAttachTicketToMailAsOnePDF' => 1,
			'wcTicketAttachTicketToMailMax' => 21,
		];

		$applied = 0;
		$appliedKeys = [];
		foreach ($defaults as $key => $value) {
			$this->MAIN->getOptions()->changeOption(['key' => $key, 'value' => $value]);
			$appliedKeys[$key] = $value;
			$applied++;
		}

		// Mark premium wizard as completed
		$this->MAIN->getOptions()->changeOption([
			'key' => 'premiumWizardCompleted',
			'value' => $this->MAIN->getPluginVersion(),
		]);

		return ['applied' => $applied, 'options' => $appliedKeys];
	}

	/**
	 * Check if a premium plugin update is available (for old premium < 1.6.0).
	 * Triggers wp_update_plugins() to force a fresh check, then inspects the transient.
	 */
	public function checkPremiumUpdate(): array {
		if (!$this->MAIN->isOldPremiumDetected()) {
			return ['hasUpdate' => false];
		}

		// Force WordPress to re-check plugin updates
		delete_site_transient('update_plugins');
		wp_update_plugins();

		$updates = get_site_transient('update_plugins');
		$premiumFolder = $this->MAIN->getPremiumPluginFolder();
		if (empty($premiumFolder)) {
			return ['hasUpdate' => false];
		}
		$premiumSlug = $premiumFolder . 'index.php';

		if (isset($updates->response[$premiumSlug])) {
			$update = $updates->response[$premiumSlug];
			$updateUrl = wp_nonce_url(
				admin_url('update.php?action=upgrade-plugin&plugin=' . urlencode($premiumSlug)),
				'upgrade-plugin_' . $premiumSlug
			);
			return [
				'hasUpdate' => true,
				'newVersion' => $update->new_version ?? '',
				'updateUrl' => $updateUrl,
			];
		}
		return ['hasUpdate' => false];
	}

	/**
	 * Re-check the premium license status by calling checkForPremiumSerialExpiration()
	 * and returning the current license info for display in the admin UI.
	 */
	public function recheckLicense(): array {
		$this->MAIN->getTicketHandler()->checkForPremiumSerialExpiration(true);
		$this->MAIN->invalidatePremiumCache();
		$info = $this->MAIN->getTicketHandler()->get_expiration();
		$active = $this->MAIN->getTicketHandler()->isSubscriptionActive();
		return [
			'active' => $active,
			'last_success' => intval($info['last_success'] ?? 0),
			'consecutive_failures' => intval($info['consecutive_failures'] ?? 0),
			'expiration_date' => $info['expiration_date'] ?? '',
			'timestamp' => intval($info['timestamp'] ?? 0),
			'subscription_type' => $info['subscription_type'] ?? '',
			'notvalid' => intval($info['notvalid'] ?? 0),
		];
	}

	public function getOptionValue($name, $defvalue="") {
		return $this->MAIN->getOptions()->getOptionValue($name, $defvalue);
	}
	public function isOptionCheckboxActive($name) {
		return $this->MAIN->getOptions()->isOptionCheckboxActive($name);
	}

	public function getMetaOfCode($data) {
		if (!isset($data['code'])) throw new Exception("#9101 ticket code parameter is missing - cannot load the meta object for the ticket");
		$codeObj = $this->MAIN->getCore()->retrieveCodeByCode($data['code']);
		$codeObj = apply_filters( $this->MAIN->_add_filter_prefix.'filter_updateExpirationInfo', $codeObj );
		$metaObj = $this->MAIN->getCore()->encodeMetaValuesAndFillObject($codeObj['meta'], $codeObj);

		$max_redeem_amount = 1;
		if (class_exists( 'WooCommerce' )) {
			// load user info of woocommerce sale

			if ($metaObj["wc_ticket"]["is_ticket"]) {
				$max_redeem_amount = $this->MAIN->getTicketHandler()->getMaxRedeemAmountOfTicket($codeObj);
			}
		}
		$metaObj["wc_ticket"]["_max_redeem_amount"] = $max_redeem_amount;

		$url = $this->getOptionValue("qrDirectURL");
		if (!empty($url)) {
			$url = $this->MAIN->getCore()->replaceURLParameters($url, $codeObj);
			$metaObj['_QR']['directURL'] = trim($url);
		}

		$url = "";
		$url2 = "";
		if ($codeObj['order_id'] > 0) {
			$order = wc_get_order( $codeObj['order_id'] );
			if ($order != null) {
				$url = $this->MAIN->getCore()->getOrderTicketsURL($order);
				$url2 = $this->MAIN->getCore()->getOrderTicketsURL($order, "ordertickets-");
				$metaObj["woocommerce"]["_order_status"] = $order->get_status();
				$metaObj["woocommerce"]["_billing_email"] = $order->get_billing_email();
			}
		}
		$productId = intval($metaObj['woocommerce']['product_id'] ?? 0);
		if ($productId > 0) {
			$product = wc_get_product($productId);
			if ($product) {
				$metaObj["woocommerce"]["_product_name"] = $product->get_name();
				if ($product->is_type('variation')) {
					$metaObj["woocommerce"]["_variation_attributes"] = $product->get_attribute_summary();
				}
			}
		}
		$metaObj["wc_ticket"]["_order_url"] = $url;
		$metaObj["wc_ticket"]["_order_page_url"] = $url2;
		$metaObj["wc_ticket"]["_qr_content"] = $this->MAIN->getCore()->getQRCodeContent($codeObj);

		$metaObj = apply_filters( $this->MAIN->_add_filter_prefix.'admin_getMetaOfCode', $metaObj );

		return $metaObj;
	}

	private function removeUserRegistrationFromCode($data) {
		if(!isset($data['code'])) throw new Exception("#9221 code paramter missing - cannot remove user registration from the ticket");
		$codeObj = $this->MAIN->getCore()->retrieveCodeByCode($data['code']);
		$metaObj = $this->MAIN->getCore()->encodeMetaValuesAndFillObject($codeObj['meta'], $codeObj);
		$metaObj['user']['value'] = "";
		$metaObj['user']['reg_ip'] = "";
		$metaObj['user']['reg_approved'] = 0;
		$metaObj['user']['reg_request'] = "";
		$metaObj['user']['reg_request_tz'] = "";
		$metaObj['user']['reg_userid'] = 0;
		$codeObj['meta'] = $this->MAIN->getCore()->json_encode_with_error_handling($metaObj);
		$this->MAIN->getDB()->update("codes", ["meta"=>$codeObj['meta'], "user_id"=>0], ['id'=>$codeObj['id']]);
		do_action( $this->MAIN->_do_action_prefix.'removeUserRegistrationFromCode', $data, $codeObj );
		return $codeObj;
	}
	private function editUseridForUserRegistrationFromCode($data) {
		if(!isset($data['code'])) throw new Exception("#9222 code parameter missing - cannot edit user reg information");
		$codeObj = $this->MAIN->getCore()->retrieveCodeByCode($data['code']);
		// speicher neue registrierung
		$metaObj = $this->MAIN->getCore()->encodeMetaValuesAndFillObject($codeObj['meta'], $codeObj);
		if (isset($data['value'])) $metaObj['user']['value'] = htmlentities(trim($data['value']));
		$metaObj['user']['reg_ip'] = $this->MAIN->getCore()->getRealIpAddr();
		$metaObj['user']['reg_approved'] = 1; // auto approval
		if (empty($metaObj['user']['reg_request'])) {
			$metaObj['user']['reg_request'] = wp_date("Y-m-d H:i:s");
			$metaObj['user']['reg_request_tz'] = wp_timezone_string();
		}
		if (isset($data['reg_userid'])) $metaObj['user']['reg_userid'] = intval($data['reg_userid']);
		$codeObj['meta'] = $this->MAIN->getCore()->json_encode_with_error_handling($metaObj);
		$this->MAIN->getDB()->update("codes", ["meta"=>$codeObj['meta'], "user_id"=>$metaObj['used']['reg_userid']], ['id'=>$codeObj['id']]);
		// send webhook if activated
		$this->MAIN->getCore()->triggerWebhooks(7, $codeObj);
		do_action( $this->MAIN->_do_action_prefix.'editUseridForUserRegistrationFromCode', $data, $codeObj, $metaObj );
		return $codeObj;
	}
	public function removeUsedInformationFromCode($data) {
		if(!isset($data['code'])) throw new Exception("#9231 code parameter missing - cannot remove used information from the ticket");
		$codeObj = $this->MAIN->getCore()->retrieveCodeByCode($data['code']);
		$metaObj = $this->MAIN->getCore()->encodeMetaValuesAndFillObject($codeObj['meta'], $codeObj);
		$metaObj['used']['reg_ip'] = "";
		$metaObj['used']['reg_request'] = "";
		$metaObj['used']['reg_request_tz'] = "";
		$metaObj['confirmedCount'] = 0;
		$codeObj['meta'] = $this->MAIN->getCore()->json_encode_with_error_handling($metaObj);
		$this->MAIN->getDB()->update("codes", ["meta"=>$codeObj['meta']], ['id'=>$codeObj['id']]);
		do_action( $this->MAIN->_do_action_prefix.'removeUsedInformationFromCode', $data, $codeObj );
		return $codeObj;
	}
	private function removeUsedInformationFromCodeBulk($data) {
		if (!isset($data['codes'])) throw new Exception("#9235 codes parameter are missing - cannot remove used information from the tickets");
		if (!is_array($data['codes'])) throw new Exception("#9236 codes parameter must be an array");
		set_time_limit(0);
		$ret = [];
		foreach($data['codes'] as $v) {
			$_data = ['code'=>$v];
			$ret[$v] = $this->removeUsedInformationFromCode($_data);
		}
		$ret = ["count"=>count($data['codes']), "ret"=>$ret];
		$ret = apply_filters( $this->MAIN->_add_filter_prefix.'admin_removeUsedInformationFromCodeBulk', $ret );
		return $ret;
	}
	private function editUseridForUsedInformationFromCode($data) {
		if(!isset($data['code'])) throw new Exception("#9233 code parameter missing - cannot edit used information on the ticket");
		$codeObj = $this->MAIN->getCore()->retrieveCodeByCode($data['code']);
		// speicher neue registrierung
		$metaObj = $this->MAIN->getCore()->encodeMetaValuesAndFillObject($codeObj['meta'], $codeObj);
		$metaObj['used']['reg_ip'] = $this->MAIN->getCore()->getRealIpAddr();
		if (empty($metaObj['used']['reg_request'])) {
			$metaObj['used']['reg_request'] = wp_date("Y-m-d H:i:s");
			$metaObj['used']['reg_request_tz'] = wp_timezone_string();
		}
		if (isset($data['reg_userid'])) $metaObj['used']['reg_userid'] = intval($data['reg_userid']);
		$codeObj['meta'] = $this->MAIN->getCore()->json_encode_with_error_handling($metaObj);
		$this->MAIN->getDB()->update("codes", ["meta"=>$codeObj['meta']], ['id'=>$codeObj['id']]);
		// send webhook if activated
		$this->MAIN->getCore()->triggerWebhooks(6, $codeObj);
		do_action( $this->MAIN->_do_action_prefix.'editUseridForUsedInformationFromCode', $data, $codeObj );
		return $codeObj;
	}
	private function editTicketMetaEntry($data) {
		if(!isset($data['code'])) throw new Exception("#9234 code parameter missing - cannot edit ticket meta");
		$key = $data['key'];
		$value = trim($data['value']);
		$codeObj = $this->MAIN->getCore()->retrieveCodeByCode($data['code']);
		$metaObj = $this->MAIN->getCore()->encodeMetaValuesAndFillObject($codeObj['meta'], $codeObj);
		$is_changed = false;
		switch ($key) {
			case "wc_ticket.value_per_ticket":
				$is_changed = true;
				$metaObj['wc_ticket']['value_per_ticket'] = $value;
				break;
			case "wc_ticket.name_per_ticket":
				$is_changed = true;
				$metaObj['wc_ticket']['name_per_ticket'] = $value;
				break;
			case "wc_ticket.day_per_ticket":
				$is_changed = true;
				$metaObj['wc_ticket']['day_per_ticket'] = $value;
				break;
			case "wc_ticket.is_daychooser":
				$is_changed = true;
				$value = intval($value);
				if ($value < 0 || $value > 1) $value = 0;
				$metaObj['wc_ticket']['is_daychooser'] = intval($value);
				break;
		}
		if ($is_changed) {
			$codeObj['meta'] = $this->MAIN->getCore()->json_encode_with_error_handling($metaObj);
			$this->MAIN->getDB()->update("codes", ["meta"=>$codeObj['meta']], ['id'=>$codeObj['id']]);
		}
		return $codeObj;
	}

	private function _setMetaDataForList($data, $metaObj) {
		if (isset($data['meta'])) {
			if (isset($data['meta']['desc'])) {
				$metaObj['desc'] = trim($data['meta']['desc']);
			}
			if (isset($data['meta']['formatter']['active'])) {
				$metaObj['formatter']['active'] = 0;
				if ($data['meta']['formatter']['active'] == 1) $metaObj['formatter']['active'] = 1;
			}
			if (isset($data['meta']['formatter']['format'])) {
				$metaObj['formatter']['format'] = trim($data['meta']['formatter']['format']);
			}
			if (isset($data['meta']['redirect']) && isset($data['meta']['redirect']['url'])) {
				$metaObj['redirect']['url'] = trim($data['meta']['redirect']['url']);
			}
			if (isset($data['meta']['webhooks']) && isset($data['meta']['webhooks']['webhookURLaddwcticketsold'])) {
				$metaObj['webhooks']['webhookURLaddwcticketsold'] = trim($data['meta']['webhooks']['webhookURLaddwcticketsold']);
			}
		}
		return $metaObj;
	}

	public function generateFirstCodeList() {
		$lists = $this->_getLists();
		if (count($lists) == 0) {
			$data = ['name'=>'Ticket list'];
			$this->_addList($data);
		}
	}

	public function getAuthtokens() {
		$handler = $this->MAIN->getAuthtokenHandler();
		add_filter( $this->MAIN->_add_filter_prefix.'getAuthtokens', [$handler, 'getAuthtokens'], 10, 0 );
		return apply_filters( $this->MAIN->_add_filter_prefix.'getAuthtokens', 1);
	}
	public function getAuthtoken($data) {
		$handler = $this->MAIN->getAuthtokenHandler();
		add_filter( $this->MAIN->_add_filter_prefix.'getAuthtoken', [$handler, 'getAuthtoken'], 10, 1 );
		return apply_filters( $this->MAIN->_add_filter_prefix.'getAuthtoken', $data );
	}
	private function addAuthtoken($data) {
		$handler = $this->MAIN->getAuthtokenHandler();
		add_filter( $this->MAIN->_add_filter_prefix.'addAuthtoken', [$handler, 'addAuthtoken'], 10, 1 );
		return apply_filters( $this->MAIN->_add_filter_prefix.'addAuthtoken', $data);
	}
	public function editAuthtoken($data) {
		$handler = $this->MAIN->getAuthtokenHandler();
		add_filter( $this->MAIN->_add_filter_prefix.'editAuthtoken', [$handler, 'editAuthtoken'], 10, 1 );
		return apply_filters( $this->MAIN->_add_filter_prefix.'editAuthtoken', $data);
	}
	private function removeAuthtoken($data) {
		$handler = $this->MAIN->getAuthtokenHandler();
		$ret = $handler->removeAuthtoken($data);
		do_action( $this->MAIN->_do_action_prefix.'admin_removeAuthtoken', $data, $ret );
		return $ret;
	}

	public function _getList($data) {
		if (!isset($data['id'])) throw new Exception("#104 ticket list id is missing");
		$sql = "select * from ".$this->MAIN->getDB()->getTabelle("lists")." where id = ".intval($data['id']);
		$ret = $this->MAIN->getDB()->_db_datenholen($sql);
		if (count($ret) == 0) throw new Exception("#105 ticket list not found");
		return $ret[0];
	}
	public function getList($data) {
		add_filter( $this->MAIN->_add_filter_prefix.'getList', [$this, '_getList'], 10, 1 );
		return apply_filters( $this->MAIN->_add_filter_prefix.'getList', $data );
	}
	public function _getLists() {
		$sql = "select * from ".$this->MAIN->getDB()->getTabelle("lists")." order by name asc";
		return $this->MAIN->getDB()->_db_datenholen($sql);
	}
	public function getLists() {
		add_filter( $this->MAIN->_add_filter_prefix.'getLists', [$this, '_getLists'], 10 );
		return apply_filters( $this->MAIN->_add_filter_prefix.'getLists', 1);
	}

	public function _addList($data) {
		if (!isset($data['name']) || trim($data['name']) == "") throw new Exception("#101 name parameter missing - cannot add ticket list");
		if (!$this->MAIN->getBase()->_isMaxReachedForList($this->MAIN->getDB()->_db_getRecordCountOfTable('lists'))) throw new Exception("#108 too many codes. Unlimited codes only with premium possible");
		$data['name'] = strip_tags($data['name']);

		$listObj = ['meta'=>''];
		$metaObj = $this->MAIN->getCore()->encodeMetaValuesAndFillObjectList($listObj['meta']);

		$felder = ["name"=>$data['name'], "aktiv"=>1, "time"=>wp_date("Y-m-d H:i:s")];

		$metaObj = $this->_setMetaDataForList($data, $metaObj);

		if ($this->MAIN->isPremium() && method_exists($this->MAIN->getPremiumFunctions(), 'setFelderListEdit')) {
			$felder = $this->MAIN->getPremiumFunctions()->setFelderListEdit($felder, $data, $listObj, $metaObj);
		}
		if (isset($felder['meta']) && !empty($felder['meta'])) { // evtl gesetzt vom premium plugin
			$metaObj = array_replace_recursive($metaObj, json_decode($felder['meta'], true));
		}
		$felder["meta"] = $this->MAIN->getCore()->json_encode_with_error_handling($metaObj);

		try {
			return $this->MAIN->getDB()->insert("lists", $felder);
		} catch(Exception $e) {
			throw new Exception(__("Could not create code list. Name exists already.", 'event-tickets-with-ticket-scanner'));
		}
	}
	private function addList($data) {
		add_filter( $this->MAIN->_add_filter_prefix.'addList', [$this, '_addList'], 10, 1 );
		return apply_filters( $this->MAIN->_add_filter_prefix.'addList', $data);
	}

	public function _editList($data) {
		if (!isset($data['name']) || trim($data['name']) == "") throw new Exception("#102 name missing");
		if (!isset($data['id']) || intval($data['id']) == 0) throw new Exception("#103 id missing");
		$data['name'] = strip_tags($data['name']);

		$listObj = $this->getList($data);
		$metaObj = $this->MAIN->getCore()->encodeMetaValuesAndFillObjectList($listObj['meta']);

		$felder["name"] = $data['name'];

		$metaObj = $this->_setMetaDataForList($data, $metaObj);

		// Clear format warning when list is manually saved (user likely adjusted formatter)
		if (!empty($metaObj['messages']['format_limit_threshold_warning']['last_email']) ||
		    !empty($metaObj['messages']['format_end_warning']['last_email'])) {
			$metaObj['messages']['format_limit_threshold_warning'] = [
				'attempts' => 0,
				'last_email' => ''
			];
			$metaObj['messages']['format_end_warning'] = [
				'attempts' => 0,
				'last_email' => ''
			];
		}

		if ($this->MAIN->isPremium() && method_exists($this->MAIN->getPremiumFunctions(), 'setFelderListEdit')) {
			$felder = $this->MAIN->getPremiumFunctions()->setFelderListEdit($felder, $data, $listObj, $metaObj);
		}
		if (isset($felder['meta']) && !empty($felder['meta'])) { // evtl gesetzt vom premium plugin
			$metaObj = array_replace_recursive($metaObj, json_decode($felder['meta'], true));
		}
		$felder["meta"] = $this->MAIN->getCore()->json_encode_with_error_handling($metaObj);

		$where = ["id"=>intval($data['id'])];
		return $this->MAIN->getDB()->update("lists", $felder, $where);
	}
	public function editList($data) {
		add_filter( $this->MAIN->_add_filter_prefix.'editList', [$this, '_editList'], 10, 1 );
		return apply_filters( $this->MAIN->_add_filter_prefix.'editList', $data);
	}

	private function removeList(array $data): array {
		if (!isset($data['id'])) throw new Exception("#106 id parameter of the list is missing");
		$list_id = intval($data['id']);

		// Check for products using this list (default: check enabled)
		$skip_check = isset($data['skip_product_check']) && $data['skip_product_check'] === true;
		if (!$skip_check) {
			$products = $this->getProductsUsingList($list_id);
			if (!empty($products)) {
				return [
					'error' => 'list_in_use',
					'products' => $products
				];
			}
		}

		$felder = ["list_id"=>0];
		$where = ["list_id"=>$list_id];
		$this->MAIN->getDB()->update("codes", $felder, $where);
		$sql = "delete from ".$this->MAIN->getDB()->getTabelle("lists")." where id = ".$list_id;
		$this->MAIN->getDB()->_db_query($sql);
		do_action( $this->MAIN->_do_action_prefix.'removeList', $data );
		return ['success' => true];
	}

	/**
	 * Get WooCommerce products that use a specific ticket list
	 */
	private function getProductsUsingList(int $list_id): array {
		$products = [];
		$args = [
			'post_type' => 'product',
			'post_status' => 'any',
			'posts_per_page' => -1,
			'meta_query' => [
				[
					'key' => 'saso_eventtickets_list',
					'value' => $list_id,
					'compare' => '='
				]
			],
			'fields' => 'ids'
		];
		$product_ids = get_posts($args);

		foreach ($product_ids as $product_id) {
			$products[] = [
				'id' => $product_id,
				'name' => get_the_title($product_id),
				'edit_url' => get_edit_post_link($product_id, 'raw')
			];
		}

		return $products;
	}

	private function getCode($data) {
		if (!isset($data['code']) || trim($data['code']) == "") throw new Exception("#202 code missing");
		return $this->MAIN->getCore()->retrieveCodeByCode($data['code'], true);
	}
	public function getCodesByProductId($product_id) {
		$sql = "select a.*, order_id as o, b.name as list_name ";
		$sql .= " from ".$this->MAIN->getDB()->getTabelle("codes")." a left join ".$this->MAIN->getDB()->getTabelle("lists")." b on a.list_id = b.id";
		$sql .= " where";
		$sql .= " a.meta != '' and json_extract(a.meta, '$.woocommerce.product_id') = ".intval($product_id);
		$sql .= " order by a.time";
		$daten = $this->MAIN->getDB()->_db_datenholen($sql);
		foreach($daten as $key => $item) {
			$metaObj = $this->MAIN->getCore()->encodeMetaValuesAndFillObject($item['meta']);
			$order_id = intval($metaObj['woocommerce']['order_id']);
			$daten[$key]['_customer_name'] = $this->getCustomerName($order_id);
		}
		return $daten;
	}
	private function getCodes($data, $request) {
		$sql = "select a.*, order_id as o, b.name as list_name ";
		$sql .= " from ".$this->MAIN->getDB()->getTabelle("codes")." a left join ".$this->MAIN->getDB()->getTabelle("lists")." b on a.list_id = b.id";

		// für datatables
		$length = 0; // wieviele pro seite angezeigt werden sollen (limit)
		if (isset($request['length'])) $length = intval($request['length']);
		$draw = 1; // sequenz zähler, also fortlaufend für jede aktion auf der JS datentabelle
		if (isset($request['draw'])) $draw = intval($request['draw']);
		$start = 0;
		if (isset($request['start'])) $start = intval($request['start']);
		$order_column = "code";

		$displayAdminAreaColumnRedeemedInfo = $this->isOptionCheckboxActive('displayAdminAreaColumnRedeemedInfo');
		$displayAdminAreaColumnBillingName = $this->isOptionCheckboxActive('displayAdminAreaColumnBillingName');
		$displayAdminAreaColumnBillingCompany = $this->isOptionCheckboxActive('displayAdminAreaColumnBillingCompany');

		if (isset($request['order'])) {
			$order_columns = array('', '', 'code');
			if ($displayAdminAreaColumnBillingName) $order_columns[] = '';
			if ($displayAdminAreaColumnBillingCompany) $order_columns[] = '';
			$order_columns[] = 'list_name';
			$order_columns[] = 'time';
			$order_columns[] = 'redeemed';
			if ($displayAdminAreaColumnRedeemedInfo) $order_columns[] = '';
			$order_columns[] = 'order_id';
			$order_columns[] = '';
			$order_columns[] = 'aktiv';
			$order_column = $order_columns[intval($request['order'][0]['column'])];
		}
		$order_dir = "asc";
		if (isset($request['order']) && $request['order'][0]['dir'] == 'desc') $order_dir = "desc";
		$search = "";
		if (isset($request['search'])) $search = $this->MAIN->getDB()->reinigen_in($request['search']['value']);

		$where = "";
		if ($search != "") {
			$sql .= " where 1=1 ";

			$search_elements = explode("&", $search);
			foreach($search_elements as $search_element) {
				$nomatch = true;
				$search_element = trim($search_element);
				$orig_search_element = $search_element;

				$matches = [];
				preg_match('/\s?LIST:\s*([0-9]*)/', $search_element, $matches);
				if ($matches && count($matches) > 1) {
					$search_element = str_replace($matches[0], "", $search_element);
					$list_id = intval($matches[1]);
					$where .= " and a.list_id = ".$list_id." ";
					$nomatch = false;
				} else {
					preg_match('/\s?LIST:\s*\*/', $search_element, $matches);
					if ($matches && count($matches) > 1) {
						$search_element = str_replace($matches[0], "", $search_element);
						$where .= " and a.list_id > 0 ";
						$nomatch = false;
					}
				}
				preg_match('/\s?ORDERID:\s*([0-9]+)/', $search_element, $matches);
				if ($matches && count($matches) > 1) {
					$search_element = str_replace($matches[0], "", $search_element);
					$order_id = intval($matches[1]);
					$where .= " and a.order_id = ".$order_id." ";
					$nomatch = false;
				} else {
					preg_match('/\s?ORDERID:\s*\*/', $search_element, $matches);
					if ($matches && count($matches) > 0) {
						$search_element = str_replace($matches[0], "", $search_element);
						$where .= " and a.order_id > 0 ";
						$nomatch = false;
					}
				}
				preg_match('/\s?CVV:\s*([^\s]*)/', $search_element, $matches);
				if ($matches && count($matches) > 1) {
					$search_element = str_replace($matches[0], "", $search_element);
					$cvv = $matches[1];
					$where .= " and a.cvv = '".sanitize_text_field($cvv)."' ";
					$nomatch = false;
				}
				preg_match('/\s?STATUS:\s*([0-9]*)/', $search_element, $matches);
				if ($matches && count($matches) > 1) {
					$search_element = str_replace($matches[0], "", $search_element);
					$status = intval($matches[1]);
					$where .= " and a.aktiv = ".$status." ";
					$nomatch = false;
				}
				preg_match('/\s?REDEEMED:\s*([0-1]*)/', $search_element, $matches);
				if ($matches && count($matches) > 1) {
					$search_element = str_replace($matches[0], "", $search_element);
					$status = intval($matches[1]);
					$where .= " and a.redeemed = ".$status." ";
					$nomatch = false;
				}
				preg_match('/\s?USERID:\s*([0-9]*)/', $search_element, $matches);
				if ($matches && count($matches) > 1) {
					$search_element = str_replace($matches[0], "", $search_element);
					$user_id = intval($matches[1]);
					$where .= " and a.user_id = ".$user_id." ";
					$nomatch = false;
				}
				preg_match('/\s?CUSTOMER:\s*([^\s]*)/', $search_element, $matches);
				if ($matches && count($matches) > 1) {
					$search_element = str_replace($matches[0], "", $search_element);
					$result = $this->MAIN->getCore()->getUserIdsForCustomerName($matches[1]);

					$has_user_ids = count($result['user_ids']) > 0;
					$has_order_ids = count($result['order_ids']) > 0;

					if ($has_user_ids || $has_order_ids) {
						$conditions = [];
						if ($has_user_ids) {
							$conditions[] = "json_extract(a.meta, '$.woocommerce.user_id') IN (".join(",", $result['user_ids']).")";
						}
						if ($has_order_ids) {
							// Guest orders: search by order_id instead of user_id
							$conditions[] = "json_extract(a.meta, '$.woocommerce.order_id') IN (".join(",", $result['order_ids']).")";
						}
						$where .= " AND (" . join(" OR ", $conditions) . ")";
						$nomatch = false;
					} else {
						// No matches - return no results
						$where .= " AND 1=0";
						$nomatch = false;
					}
				}
				preg_match('/\s?PRODUCTID:\s*([0-9]*)/', $search_element, $matches);
				if ($matches && count($matches) > 1) {
					$search_element = str_replace($matches[0], "", $search_element);
					$product_id = intval($matches[1]);
					$where .= " and json_extract(a.meta, '$.woocommerce.product_id') = ".$product_id." ";
					$nomatch = false;
				}
				preg_match('/\s?DAYPERTICKET:\s*([^\s]*)/', $search_element, $matches);
				if ($matches && count($matches) > 1) {
					$search_element = str_replace($matches[0], "", $search_element);
					$json_search_value = $matches[1];
					$where .= " and json_extract(a.meta, '$.wc_ticket.is_daychooser') = 1 and json_unquote(json_extract(a.meta, '$.wc_ticket.day_per_ticket')) like '".sanitize_text_field($json_search_value)."%' ";
					$nomatch = false;
				}

				$where .= apply_filters( $this->MAIN->_add_filter_prefix.'admin_getCodes_searchword', "", $orig_search_element, $where);

				if ($nomatch == false) {
					$search = str_replace($orig_search_element, "", $search);
				}
			}
			$search = trim(str_replace("&", "", $search));

			if ($search != "") {
				$where .= " and (a.code like '%".$this->MAIN->getCore()->clearCode($search)."%' or b.name like '%".$search."%' or a.time like '%".$search."%' ";
				if (intval($search) > 0) {
					$where .= " or a.order_id = ".intval($search)." ";
				}
				$where .= ") ";
			}

			$sql .= $where;
		}
		if ($order_column != "") $sql .= " order by ".$order_column." ".$order_dir;
		if ($length > 0)
			$sql .= " limit ".$start.", ".$length;

		$daten = $this->MAIN->getDB()->_db_datenholen($sql);
		$recordsTotal = $this->MAIN->getDB()->_db_getRecordCountOfTable('codes');
		$recordsTotalFilter = $recordsTotal;
		if (!empty($where)) {
			$sql = "select count(a.id) as anzahl
					from
					".$this->MAIN->getDB()->getTabelle("codes")." a left join
					".$this->MAIN->getDB()->getTabelle("lists")." b on a.list_id = b.id
					where 1=1 ".$where;
			list($d) = $this->MAIN->getDB()->_db_datenholen($sql);
			$recordsTotalFilter = $d['anzahl'];
		}
		$redeemedRecordsTotal = $this->MAIN->getDB()->_db_getRecordCountOfTable('codes', 'redeemed = 1');
		$redeemedRecordsFiltered = $redeemedRecordsTotal;
		if (!empty($where)) {
			$sql = "select count(a.id) as anzahl
			from
			(select * from ".$this->MAIN->getDB()->getTabelle("codes")." where redeemed = 1) a left join
			".$this->MAIN->getDB()->getTabelle("lists")." b on a.list_id = b.id
			where 1=1 ".$where;
			list($d) = $this->MAIN->getDB()->_db_datenholen($sql);
			$redeemedRecordsFiltered = $d['anzahl'];
		}

		if ($displayAdminAreaColumnBillingName) {
			$had_error = false;
			foreach($daten as $key => $item) {
				$daten[$key]['_customer_name'] = "";
				if ($item['order_id'] > 0) {
					try {
						$daten[$key]['_customer_name'] = $this->getCustomerName($item['order_id']);
					} catch (Exception $e) {
						if ($had_error == false) {
							$this->logErrorToDB($e->getMessage());
						}
						$had_error = true;
					}
				}
			}
		}
		if ($displayAdminAreaColumnBillingCompany) {
			$had_error = false;
			foreach($daten as $key => $item) {
				$daten[$key]['_customer_company'] = "";
				if ($item['order_id'] > 0) {
					try {
						$daten[$key]['_customer_company'] = $this->getCompanyName($item['order_id']);
					} catch (Exception $e) {
						if ($had_error == false) {
							$this->logErrorToDB($e->getMessage());
						}
						$had_error = true;
					}
				}
			}
		}
		if ($displayAdminAreaColumnRedeemedInfo) {
			$products_max_redeem_amounts = []; // cache
			foreach($daten as $key => $item) {
				if (!isset($item['meta']) || empty($item['meta'])) {
					$daten[$key]['meta'] = $this->MAIN->getCore()->json_encode_with_error_handling($this->MAIN->getCore()->getMetaObject());
				}
				$redeemAmount = $this->getRedeemAmount($item, $products_max_redeem_amounts);
				$daten[$key]['_redeemed_counter'] = $redeemAmount['_redeemed_counter'];
				$daten[$key]['_max_redeem_amount'] = $redeemAmount['_max_redeem_amount'];
				$products_max_redeem_amounts = $redeemAmount['cache'];
			}
		}

		return ["draw"=>$draw,
				"recordsTotal"=>intval($recordsTotal),
				"recordsFiltered"=>intval($recordsTotalFilter),
				"redeemedRecordsTotal"=>intval($redeemedRecordsTotal),
				"redeemedRecordsFiltered"=>intval($redeemedRecordsFiltered),
				"data"=>$daten];
	}
	public function getCustomerName($order_id) {
		$ret = "";
		$order_id = intval($order_id);
		if ($order_id > 0) {
			if (!isset($this->_CACHE['order'])) {
				$this->_CACHE['order'] = ['getCustomerName'=> [] ];
			}
			if (!isset($this->_CACHE['order']['getCustomerName'])) {
				$this->_CACHE['order']['getCustomerName'] = [];
			}
			if (isset($this->_CACHE['order']['getCustomerName'][$order_id])) {
				$ret = $this->_CACHE['order']['getCustomerName'][$order_id];
			} else {
				try {
					$order = wc_get_order( $order_id );
					if ($order != null) {
						//$ret = $order->get_billing_company()." ".$order->get_billing_last_name();
						$ret = $order->get_formatted_billing_full_name();
					} else {
						$ret = __('Order not found', 'event-tickets-with-ticket-scanner');
					}
				} catch (Exception $e) {
					//$this->logErrorToDB($e->getMessage());
				}
				$this->_CACHE['order']['getCustomerName'][$order_id] = $ret;
			}
		}

		$ret = apply_filters( $this->MAIN->_add_filter_prefix.'admin_getCustomerName', $ret, $order_id);
		return $ret;
	}
	public function getCompanyName($order_id) {
		$ret = "";
		$order_id = intval($order_id);
		if ($order_id > 0) {
			try {
				$order = wc_get_order( $order_id );
				if ($order != null) {
					$ret = $order->get_billing_company();
				}
			} catch (Exception $e) {}
		}
		$ret = apply_filters( $this->MAIN->_add_filter_prefix.'admin_getCompanyName', $ret, $order_id);
		return $ret;
	}
	public function getRedeemAmount($codeObj, $cache=[]) {
		$ret = ["_redeemed_counter"=>0, "_max_redeem_amount"=>0];
		if (isset($codeObj['metaObj'])) {
			$metaObj = $codeObj['metaObj'];
		} else {
			if (!isset($codeObj['meta'])) throw new Exception("meta object is missing: ".__METHOD__);
			$metaObj = $this->MAIN->getCore()->encodeMetaValuesAndFillObject($codeObj['meta']);
		}
		if ($metaObj['wc_ticket']['is_ticket'] == 1) {
			$ret['_redeemed_counter'] = count($metaObj['wc_ticket']['stats_redeemed']);
			$product_id = intval($metaObj['woocommerce']['product_id']);
			if ($product_id > 0) {
				if (!isset($cache[$product_id])) {
					$cache[$product_id] = intval(get_post_meta( $product_id, 'saso_eventtickets_ticket_max_redeem_amount', true ));
				}
				$ret['_max_redeem_amount'] = $cache[$product_id];
			}
		}
		$ret['cache'] = $cache;
		return $ret;
	}
	private function _addCode($newcode, $list_id=0) {
		$cvv = "";
		$teile = explode(";", $newcode);
		if (count($teile) > 1) {
			$newcode = trim($teile[0]);
			$cvv = trim($teile[1]);
		}
		$code = $this->MAIN->getCore()->clearCode($newcode);
		if (empty($code)) {
			throw new Exception(__('Code is empty', 'event-tickets-with-ticket-scanner'));
		}
		try {
			$this->MAIN->getCore()->retrieveCodeByCode($code, false);
		} catch(Exception $e) { // not found -> add new
			$this->MAIN->getCore()->checkCodesSize();
			$felder = ["code"=>$code, "code_display"=>$newcode, "cvv"=>$cvv, "aktiv"=>1, "time"=>wp_date("Y-m-d H:i:s"), "meta"=>"", "list_id"=>$list_id];
			$this->MAIN->getBase()->increaseGlobalTicketCounter();
			return $this->MAIN->getDB()->insert("codes", $felder);
		}
		throw new Exception("#205 ".__('code exists already', 'event-tickets-with-ticket-scanner'));
	}

	public function addCodeFromListForOrder($list_id, $order_id, $product_id = 0, $item_id = 0, $formatterValues="") {
		// item_id is in der order der eintrag
		$list_id = intval($list_id);
		if ($list_id == 0) throw new Exception("#602 list id is invalid");
		$listObj = $this->getList(['id'=>$list_id]); // throws #104, #105
		// uses a list to define the autogenerated code, that will be taken (if not used codes exists) or store a new generated code to the list
		$data = ["code"=>"", "list_id"=>$list_id, "order_id"=>$order_id, "semaphorecode"=>""]; // semaphorecode wird beim speichern wieder frei gemacht
		$id = 0;
		// check if option is activate to reuse not purchased codes from the code list assigned to the woocommerce product
		if ($this->isOptionCheckboxActive('wcassignmentReuseNotusedCodes')) {
			// semaphore to prevent stealing code on heavy loaded servers
			$semaphorecode = md5(SASO_EVENTTICKETS::PasswortGenerieren() . microtime(). "_". rand());
			$rescueCounter = 0;
			while($rescueCounter < 50) {
				// get a code from the list with order_id = 0
				$sql = "select id from ".$this->MAIN->getDB()->getTabelle("codes")." where
						order_id = 0 and semaphorecode = '' and
						list_id = ".$list_id."
						limit 1";
				$d = $this->MAIN->getDB()->_db_datenholen($sql);
				if (count($d) == 0) {
					break; // if no unregistered code could be found => create a new one
				} else {
					//if (SASO_EVENTTICKETS::issetRPara('a') && SASO_EVENTTICKETS::getRequestPara('a') == 'testing') echo "<p>FOUND UNUSED</p>";
					// update it with a random code if not already a code is assigned
					$this->MAIN->getDB()->update("codes", ['semaphorecode'=>$semaphorecode], ['id'=>$d[0]['id'], 'semaphorecode'=>'']);
					// retrieve the code again and check if the random code could be set
					$sql = "select id, code_display, semaphorecode from ".$this->MAIN->getDB()->getTabelle("codes")." where id = ".$d[0]['id'];
					$d = $this->MAIN->getDB()->_db_datenholen($sql);
					// if not managed to add the random code do it again
					if ($d[0]['semaphorecode'] == $semaphorecode) {
						if ($semaphorecode == "") break; // semaphorecode is empty => create a new serial
						// set the $id
						$id = intval($d[0]['id']);
						$data['code'] = $d[0]['code_display'];
						// too clean up again after the testing // $this->MAIN->getDB()->update("codes", ['semaphorecode'=>''], ['id'=>$d[0]['id']]);
						break; // done
					}
				}
				$rescueCounter++;
			}
		}
		if (SASO_EVENTTICKETS::issetRPara('a') && SASO_EVENTTICKETS::getRequestPara('a') == 'testing') {
			/*
			echo(esc_html($id));
			if ($id > 0) {
				print_r($this->MAIN->getCore()->retrieveCodeById($id, true));
			}

			$this->MAIN->getDB()->update("codes", ['semaphorecode'=>""], ['list_id'=>$list_id, 'order_id'=>0]);
			$sql = "select * from ".$this->MAIN->getDB()->getTabelle("codes")." where
					order_id = 0 and
					list_id = ".$list_id;
			$d = $this->MAIN->getDB()->_db_datenholen($sql);
			print_r($d);
			if ($id == 0) {
				echo "no reusabel code found";
				exit;
			}
			*/
		}

		if ($id == 0 && empty($data['code'])) {
			// check if serial code formatter is active
			if (empty($formatterValues)) {
				$metaObj = $this->MAIN->getCore()->encodeMetaValuesAndFillObjectList($listObj['meta']);
				if ($metaObj['formatter']['active'] == 1) {
					$formatterValues = stripslashes($metaObj['formatter']['format']);
				}
			}

			$counter = 0;
			while($counter < 500) {
				$counter++;
				$data["code"] = $this->generateCode($formatterValues);
				try {
					$id = $this->addCode($data);
					// Check if counter exceeded threshold (50% warning)
					if ($counter > 250) {
						$this->checkAndSaveFormatWarning($list_id, $counter, 'format_limit_threshold_warning');
					}
					break;
				} catch(Exception $e) {
					// code exists already, try a new one
					if (substr($e->getMessage(), 0, 5) == "#208 ") { // no premium and limit exceeded
						$data["code"] = $this->getOptionValue('wcassignmentTextNoCodePossible', __("Please contact our support for the ticket/code", 'event-tickets-with-ticket-scanner'));
						return $data["code"];
					}
				}
			}

			// If loop exhausted without finding a unique code (all 500 attempts produced duplicates)
			if ($id == 0 && $counter >= 500) {
				$this->checkAndSaveFormatWarning($list_id, $counter, 'format_end_warning');
				$data["code"] = $this->getOptionValue('wcassignmentTextNoCodePossible', __("Please contact our support for the ticket/code", 'event-tickets-with-ticket-scanner'));
				return $data["code"];
			}
		}
		//if (SASO_EVENTTICKETS::issetRPara('a') && SASO_EVENTTICKETS::getRequestPara('a') == 'testing') exit;

		if ($id > 0) {
			$this->editCode($data); // order_id wird nicht beim anlegen gespeichert, deswegen hier nochmal ein update
			$codeObj = $this->addWoocommerceInfoToCode([
												'code'=>$data["code"],
												'list_id'=>$list_id,
												'order_id'=>$order_id,
												'product_id'=>$product_id,
												'item_id'=>$item_id
												]);
			if ($this->MAIN->isPremium() && method_exists($this->MAIN->getPremiumFunctions(), 'addCodeFromListForOrderAfter')) {
				$codeObj = $this->MAIN->getPremiumFunctions()->addCodeFromListForOrderAfter($codeObj);
			}

			$this->MAIN->getCore()->triggerWebhooks(17, $codeObj);

			return $data["code"];
		}
		throw new Exception("#601 ".__('code could not be generated and stored', 'event-tickets-with-ticket-scanner'));
	}
	private function generateCode($formatterValues="") {
		$datePrefix = $this->encodeDateToLetters();
		$code = $datePrefix . '-' . implode('-', str_split(substr(strtoupper(md5(time()."_".rand())), 0, 10), 5));
		if (!empty($formatterValues) || $this->isOptionCheckboxActive("wcassignmentUseGlobalSerialFormatter")) {
			if (empty($formatterValues)) {
				$codeFormatterJSON = $this->getOptionValue('wcassignmentUseGlobalSerialFormatter_values');
			} else {
				$codeFormatterJSON = $formatterValues;
			}
			// check ob formatter infos gespeichert
			if (!empty($codeFormatterJSON)) {
				// check ob man das JSON erstellen kann
				$obj = json_decode($codeFormatterJSON, true);
				if (is_array($obj)) {
					// bauen den code
					$laenge = 0;
					if (isset($obj['input_amount_letters'])) $laenge = intval($obj['input_amount_letters']);
					if ($laenge == 0) $laenge = 12;
					$charset = join("", range("a", "z"));
					$letterStyle = 0;
					if (isset($obj['input_letter_style'])) $letterStyle = intval($obj['input_letter_style']);
					if ($letterStyle == 1) $charset = strtoupper($charset);
					if ($letterStyle == 3) $charset .= strtoupper($charset);
					$withnumbers = 0;
					if (isset($obj['input_include_numbers'])) $withnumbers = intval($obj['input_include_numbers']);
					if ($withnumbers == 2) $charset .= '0123456789';
					if ($withnumbers == 3) $charset = '0123456789';
					$exclusion = 0;
					if (isset($obj['input_letter_excl'])) $exclusion = intval($obj['input_letter_excl']);
					if ($exclusion == 2) {
						$charset = str_ireplace(['i','l','o','p','q'], "", $charset);
					}
					$letters = str_split($charset);
					$code = "";
					for ($a=0;$a<$laenge;$a++) {
				        shuffle($letters);
				        $zufallszahl = rand(0, count($letters)-1);
				        $buchstabe = $letters[$zufallszahl];
				        $code .= $buchstabe;
					}

					// add delimiter to the code
					$serial_delimiter = 0;
					if (isset($obj['input_serial_delimiter'])) $serial_delimiter = intval($obj['input_serial_delimiter']);
					if ($serial_delimiter > 0) {
						$serial_delimiter_space = 3;
						if (isset($obj['input_serial_delimiter_space'])) $serial_delimiter_space = intval($obj['input_serial_delimiter_space']);
						$delimiter = ['','-',' ',':'][$serial_delimiter - 1];
						$codeLetters = str_split($code);
						$chunks = array_chunk($codeLetters, $serial_delimiter_space);
						$codeChunks = [];
						foreach($chunks as $chunk) {
							$codeChunks[] = join("", $chunk);
						}
						$code = join($delimiter, $codeChunks);
					}

					// prefix
					if (isset($obj['input_prefix_codes']) && trim($obj['input_prefix_codes']) !== '') {
						$prefix_code = trim($obj['input_prefix_codes']);
						$prefix_code = str_replace(" ", "_", $prefix_code);
						$prefix_code = $this->replacePlaceholderForCode($prefix_code);
						$prefix_code = str_replace("/", "-", $prefix_code);
						$code = $prefix_code.$code;
					} else {
						// No prefix configured: add date prefix to avoid collisions
						$code = $datePrefix . '-' . $code;
					}
				}
			}
		}
		return $code;
	}

	/**
	 * Encode current date as 5 uppercase letters (base-26, days since 2020-01-01)
	 * Covers ~32,000 years. Same day = same prefix = partitioned address space.
	 * @return string e.g. "AADIL" for 2026-02-24
	 */
	private function encodeDateToLetters(): string {
		$today = wp_date('Y-m-d');
		$daysSinceRef = max(0, (int)((strtotime($today) - strtotime('2020-01-01')) / 86400));
		$chars = '';
		for ($i = 0; $i < 5; $i++) {
			$chars = chr(65 + ($daysSinceRef % 26)) . $chars;
			$daysSinceRef = intdiv($daysSinceRef, 26);
		}
		return $chars;
	}
	private function replacePlaceholderForCode($code) {
		$time = time();
		$code = str_replace("{TIMESTAMP}", $time, $code);
		$code = str_replace("{Y}", wp_date("Y", $time), $code);
		$code = str_replace("{m}", wp_date("m", $time), $code);
		$code = str_replace("{d}", wp_date("d", $time), $code);
		$code = str_replace("{H}", wp_date("H", $time), $code);
		$code = str_replace("{i}", wp_date("i", $time), $code);
		$code = str_replace("{s}", wp_date("s", $time), $code);
		return $code;
	}
	public function addRetrictionCodeToOrder($code, $list_id, $order_id, $product_id = 0, $item_id = 0) {
		if (!empty($code)) {
			return $this->addWoocommerceInfoToCode([
										'code'=>$code,
										'list_id'=>intval($list_id),
										'order_id'=>$order_id,
										'product_id'=>$product_id,
										'item_id'=>$item_id,
										'is_restriction_purchase'=>1
										]);
		}
	}
	private function addWoocommerceInfoToCode($data, $trigger_webhooks = true) {
		// $data = ['code'=>$data["code"], 'list_id'=>$list_id, 'order_id'=>$order_id, 'product_id'=>$product_id, 'item_id'=>$item_id]
		if (!isset($data['code'])) throw new Exception("#9601 code parameter is missing");
		if (!isset($data['order_id'])) throw new Exception("#9602 order id parameter is missing");
		//if (!isset($data['product_id'])) throw new Exception("#9603 product id is missing");
		//if (!isset($data['item_id'])) throw new Exception("#9604 item id is missing"); // position within the order
		$codeObj = $this->MAIN->getCore()->retrieveCodeByCode($data['code']);
		$metaObj = $this->MAIN->getCore()->encodeMetaValuesAndFillObject($codeObj['meta'], $codeObj);

		$key = 'woocommerce';
		if (isset($data['is_restriction_purchase']) && $data['is_restriction_purchase'] == 1) {
			$key = 'wc_rp';
		}

		$order = wc_get_order( $data['order_id'] );
		$metaObj[$key] = ['order_id'=>$data['order_id'], 'creation_date'=>wp_date("Y-m-d H:i:s"), 'creation_date_tz'=>wp_timezone_string()];
		if (isset($data['product_id'])) $metaObj[$key]['product_id'] = $data['product_id'];
		if (isset($data['item_id'])) $metaObj[$key]['item_id'] = $data['item_id'];
		$metaObj[$key]['user_id'] = intval($order->get_user_id());

		$codeObj['meta'] = $this->MAIN->getCore()->json_encode_with_error_handling($metaObj);
		$this->MAIN->getDB()->update("codes", ["meta"=>$codeObj['meta']], ['id'=>$codeObj['id']]);

		if($trigger_webhooks) {
			$this->MAIN->getCore()->triggerWebhooks(10, $codeObj);
		}

		do_action( $this->MAIN->_do_action_prefix.'admin_addWoocommerceInfoToCode', $data, $codeObj );

		return $codeObj;
	}
	public function removeWoocommerceOrderInfoFromCode($data) {
		if (!isset($data['code'])) throw new Exception("#9611 code parameter is missing");
		if (class_exists( 'WooCommerce' )) {
			// include Woocommerce file for delete
			if ( !function_exists( 'wc_get_order' ) ) {
				require_once ABSPATH . PLUGINDIR . '/woocommerce/includes/wc-order-functions.php';
			}
			// include Woocommerce file for delete
			if ( !function_exists( 'wc_delete_order_item_meta' ) ) {
				require_once ABSPATH . PLUGINDIR . '/woocommerce/includes/wc-order-item-functions.php';
			}
		}
		// lade code
		$codeObj = $this->MAIN->getCore()->retrieveCodeByCode($data['code']);
		$metaObj = $this->MAIN->getCore()->encodeMetaValuesAndFillObject($codeObj['meta'], $codeObj);
		if ($codeObj['order_id'] > 0 || $metaObj['woocommerce']['order_id'] > 0) {

				// extrahiere item id
				$item_id = isset($metaObj['woocommerce']['item_id']) ? $metaObj['woocommerce']['item_id'] : 0;
				if ($item_id > 0) {

					$existingCodes = wc_get_order_item_meta($item_id , '_saso_eventtickets_product_code', true);
					if (!empty($existingCodes)) {
						$codes = explode(",", $existingCodes);
						$public_ticket_ids_value = wc_get_order_item_meta($item_id , '_saso_eventtickets_public_ticket_ids', true);
						$existing_plublic_ticket_ids = explode(",", $public_ticket_ids_value);
						$_saso_eventtickets_daychooser = wc_get_order_item_meta($item_id , '_saso_eventtickets_daychooser', true);
						$existing_saso_eventtickets_daychooser = explode(",", $_saso_eventtickets_daychooser);
						$public_ticket_ids = [];
						$daychooser = [];
						$new_codes = [];
						foreach($codes as $idx => $code) {
							if ($code != $codeObj["code"]) { // do not re-add the ticket number, that need to be removed
								$new_codes[] = $code;
								if(isset($existing_plublic_ticket_ids[$idx])) {
									$public_ticket_ids[] = $existing_plublic_ticket_ids[$idx];
									$daychooser[] = $existing_saso_eventtickets_daychooser[$idx];
								}
							}
						}
						if (count($new_codes) == 0) {
							$this->MAIN->getWC()->getOrderManager()->deleteCodesEntryOnOrderItem($item_id);
							$this->removeWoocommerceTicketForCode($data);
						} else {
							wc_delete_order_item_meta( $item_id, '_saso_eventtickets_product_code' );
							wc_add_order_item_meta($item_id , '_saso_eventtickets_product_code', implode(",", $codes) );
							wc_delete_order_item_meta( $item_id, "_saso_eventtickets_public_ticket_ids" );
							wc_add_order_item_meta($item_id , "_saso_eventtickets_public_ticket_ids", implode(",", $public_ticket_ids) ) ;
							wc_delete_order_item_meta( $item_id, "_saso_eventtickets_daychooser" );
							wc_add_order_item_meta($item_id , "_saso_eventtickets_daychooser", implode(",", $daychooser) ) ;
						}
					}
				}

		}
		// leere meta woocommerce
		$defMeta = $this->MAIN->getCore()->getMetaObject();
		$metaObj['woocommerce'] = $defMeta['woocommerce'];
		$metaObj['wc_ticket'] = $defMeta['wc_ticket'];
		$codeObj['order_id'] = 0;
		$codeObj['meta'] = $this->MAIN->getCore()->json_encode_with_error_handling($metaObj);

		$felder = ['order_id'=>0, 'semaphorecode'=>'', "meta"=>$codeObj['meta']];
		if ($this->MAIN->isPremium() && method_exists($this->MAIN->getPremiumFunctions(), 'removeWoocommerceOrderInfoFromCode')) {
			$felder = $this->MAIN->getPremiumFunctions()->removeWoocommerceOrderInfoFromCode($codeObj, $felder, $data);
		}
		$where = ['id'=>intval($codeObj['id'])];

		$this->MAIN->getDB()->update("codes", $felder, $where);
		$this->MAIN->getCore()->triggerWebhooks(11, $codeObj);
		do_action( $this->MAIN->_do_action_prefix.'admin_removeWoocommerceOrderInfoFromCode', $data, $codeObj );
		return $codeObj;
	}
	public function removeWoocommerceRstrPurchaseInfoFromCode($data) {
		if (!isset($data['code'])) throw new Exception("#9611 code parameter is missing");
		// lade code
		$codeObj = $this->MAIN->getCore()->retrieveCodeByCode($data['code']);
		$metaObj = $this->MAIN->getCore()->encodeMetaValuesAndFillObject($codeObj['meta'], $codeObj);

		// purchase restrictions do not add an order_id to the code level
		if ($metaObj['wc_rp']['order_id'] > 0) {
			if (class_exists( 'WooCommerce' )) {
				// include Woocommerce file for delete
				if ( !function_exists( 'wc_get_order' ) ) {
					require_once ABSPATH . PLUGINDIR . '/woocommerce/includes/wc-order-functions.php';
				}
				if ( !function_exists( 'wc_delete_order_item_meta' ) ) {
					require_once ABSPATH . PLUGINDIR . '/woocommerce/includes/wc-order-item-functions.php';
				}
				// extrahiere item id
				$item_id = isset($metaObj['wc_rp']['item_id']) ? $metaObj['wc_rp']['item_id'] : 0;
				if ($item_id > 0) {
					$this->MAIN->getWC()->getOrderManager()->deleteRestrictionEntryOnOrderItem($item_id);
				}
			}
		}

		// leere meta wc_rp
		$defMeta = $this->MAIN->getCore()->getMetaObject();
		$metaObj['wc_rp'] = $defMeta['wc_rp'];
		$codeObj['meta'] = $this->MAIN->getCore()->json_encode_with_error_handling($metaObj);

		$felder = ["meta"=>$codeObj['meta']];
		if ($this->MAIN->isPremium() && method_exists($this->MAIN->getPremiumFunctions(), 'removeWoocommerceRstrPurchaseInfoFromCode')) {
			$felder = $this->MAIN->getPremiumFunctions()->removeWoocommerceRstrPurchaseInfoFromCode($codeObj, $felder, $data);
		}
		$where = ['id'=>intval($codeObj['id'])];

		$this->MAIN->getDB()->update("codes", $felder, $where);
		do_action( $this->MAIN->_do_action_prefix.'admin_removeWoocommerceRstrPurchaseInfoFromCode', $data, $codeObj );
		return $codeObj;
	}
	private function generateOnePDFForTicketsBulk($data) {
		if (!isset($data['codes'])) throw new Exception("#9437 codes parameter is missing");
		if (trim($data['codes']) == "") throw new Exception("#9438 codes cannot be empty");
		$codes = explode(",", $data['codes']);
		$handler = $this->MAIN->getTicketHandler();
		$handler->generateOnePDFForCodes($codes);
	}
	private function generateOnePDFForBadgesBulk($data) {
		if (!isset($data['codes'])) throw new Exception("#9439 codes parameter is missing");
		if (trim($data['codes']) == "") throw new Exception("#9440 codes cannot be empty");
		$codes = explode(",", $data['codes']);
		$handler = $this->MAIN->getTicketHandler();
		$handler->generateOneBadgePDFForCodes($codes);
	}
	private function assignTicketListToTicketsBulk($data) {
		if (!isset($data['codes'])) throw new Exception("#9441 codes parameter is missing");
		if (!is_array($data['codes'])) throw new Exception("#9442 codes must be an array");
		set_time_limit(0);
		$felder = ["list_id"=>intval($data['list_id'])];
		foreach($data['ids'] as $v) {
			$where = ["id"=>intval($v)];
			$ret[$v] = $this->MAIN->getDB()->update("codes", $felder, $where);
		}
		return ["count"=>count($data['codes']), "ret"=>$ret];
	}
	private function removeRedeemWoocommerceTicketForCodeBulk($data) {
		if (!isset($data['codes'])) throw new Exception("#9237 codes parameter is missing");
		if (!is_array($data['codes'])) throw new Exception("#9238 codes must be an array");
		set_time_limit(0);
		$ret = [];
		foreach($data['codes'] as $v) {
			$_data = ['code'=>$v];
			$ret[$v] = $this->removeRedeemWoocommerceTicketForCode($_data);
		}
		return ["count"=>count($data['codes']), "ret"=>$ret];
	}
	public function removeRedeemWoocommerceTicketForCode($data, $codeObj = null) {
		if (!isset($data['code'])) throw new Exception("#9621 code parameter is missing");
		// lade code
		if ($codeObj === null) {
			$codeObj = $this->MAIN->getCore()->retrieveCodeByCode($data['code']);
		}
		$metaObj = $this->MAIN->getCore()->encodeMetaValuesAndFillObject($codeObj['meta'], $codeObj);

		// leere meta wc_ticket
		$defMeta = $this->MAIN->getCore()->getMetaObject();
		$metaObj['used'] = $defMeta['used'];
		$metaObj['wc_ticket']['ip'] = "";
		$metaObj['wc_ticket']['userid'] = 0;
		$metaObj['wc_ticket']['_username'] = "";
		$metaObj['wc_ticket']['redeemed_date'] = "";
		$metaObj['wc_ticket']['redeemed_date_tz'] = "";
		$metaObj['wc_ticket']['redeemed_by_admin'] = 0;
		$metaObj['wc_ticket']['redeemed_via_authtoken_id'] = 0;
		$metaObj['wc_ticket']['set_by_admin'] = 0;
		$metaObj['wc_ticket']['set_by_admin_date'] = "";
		$metaObj['wc_ticket']['set_by_admin_date_tz'] = "";
		$metaObj['wc_ticket']['stats_redeemed'] = [];

		$codeObj['meta'] = $this->MAIN->getCore()->json_encode_with_error_handling($metaObj);

		$felder = ["meta"=>$codeObj['meta'], "redeemed"=>0];
		if ($this->MAIN->isPremium() && method_exists($this->MAIN->getPremiumFunctions(), 'removeRedeemWoocommerceTicketForCode')) {
			$felder = $this->MAIN->getPremiumFunctions()->removeRedeemWoocommerceTicketForCode($codeObj, $felder, $data);
		}
		$where = ['id'=>intval($codeObj['id'])];

		$this->MAIN->getDB()->update("codes", $felder, $where);
		$this->MAIN->getCore()->triggerWebhooks(14, $codeObj);
		return $codeObj;
	}
	public function removeWoocommerceTicketForCode($data) {
		if (!isset($data['code'])) throw new Exception("#9625 code parameter is missing");
		if (class_exists( 'WooCommerce' )) {
			// include Woocommerce file for delete
			if ( !function_exists( 'wc_get_order' ) ) {
				require_once ABSPATH . PLUGINDIR . '/woocommerce/includes/wc-order-functions.php';
			}
			if ( !function_exists( 'wc_delete_order_item_meta' ) ) {
				require_once ABSPATH . PLUGINDIR . '/woocommerce/includes/wc-order-item-functions.php';
			}
		}
		// lade code
		$codeObj = $this->MAIN->getCore()->retrieveCodeByCode($data['code']);
		$metaObj = $this->MAIN->getCore()->encodeMetaValuesAndFillObject($codeObj['meta'], $codeObj);
		if ($codeObj['order_id'] > 0 || $metaObj['woocommerce']['order_id'] > 0) {
			if (class_exists( 'WooCommerce' )) {
				// extrahiere item id
				$item_id = isset($metaObj['woocommerce']['item_id']) ? $metaObj['woocommerce']['item_id'] : 0;
				if ($item_id > 0) {
					wc_delete_order_item_meta( $item_id, '_saso_eventtickets_is_ticket' );
				}
			}
		}

		$order_id = intval($metaObj['woocommerce']['order_id']);
		if (class_exists( 'WooCommerce' )) {
			$order = new WC_Order( $order_id );

			// set order note
			$order->add_order_note( "Ticket number: ".$codeObj['code_display']." for order item of product #".intval($metaObj['woocommerce']['product_id'])." removed." );

			$order->update_meta_data( '_saso_eventtickets_order_idcode', "" );
			$order->save();
		}

		// leere meta woocommerce
		$defMeta = $this->MAIN->getCore()->getMetaObject();
		$idcode = $metaObj['wc_ticket']['idcode'];
		$metaObj['wc_ticket'] = $defMeta['wc_ticket'];
		$metaObj['wc_ticket']['idcode'] = $idcode;
		$codeObj['meta'] = $this->MAIN->getCore()->json_encode_with_error_handling($metaObj);

		$felder = ["meta"=>$codeObj['meta']];
		if ($this->MAIN->isPremium() && method_exists($this->MAIN->getPremiumFunctions(), 'removeWoocommerceTicketForCode')) {
			$felder = $this->MAIN->getPremiumFunctions()->removeWoocommerceTicketForCode($codeObj, $felder, $data);
		}
		$where = ['id'=>intval($codeObj['id'])];

		$this->MAIN->getDB()->update("codes", $felder, $where);
		$this->MAIN->getCore()->triggerWebhooks(15, $codeObj);
		do_action( $this->MAIN->_do_action_prefix.'admin_removeWoocommerceTicketForCode', $data, $codeObj );
		return $codeObj;
	}
	private function redeemWoocommerceTicketForCode($data) {
		if (!isset($data['code'])) throw new Exception("#9622 code parameter is missing");
		// lade code
		$codeObj = $this->MAIN->getCore()->retrieveCodeByCode($data['code']);
		$codeObj = apply_filters( $this->MAIN->_add_filter_prefix.'filter_setExpirationDateFromDays', $codeObj );
		$metaObj = $this->MAIN->getCore()->encodeMetaValuesAndFillObject($codeObj['meta'], $codeObj);

		$max_redeem_amount = $this->MAIN->getTicketHandler()->getMaxRedeemAmountOfTicket($codeObj);
		if ($max_redeem_amount > 0) {
			$redeemed_counter = count($metaObj['wc_ticket']['stats_redeemed']);
			if ($redeemed_counter >= $max_redeem_amount) {
				throw new Exception(sprintf(/* translators: 1: redeemed ticket counter 2: max redeem possible */esc_html__('Ticket cannot be redeemed anymore. All redeem operations are used up. %1$d of %2$d.', 'event-tickets-with-ticket-scanner'), $redeemed_counter, $max_redeem_amount));
			}
		}

		$metaObj["wc_ticket"]["_max_redeem_amount"] = $max_redeem_amount;

		// Per-day limit check (last in chain, after total max check)
		$max_per_day = $this->MAIN->getTicketHandler()->getMaxRedeemPerDayOfTicket($codeObj);
		if ($max_per_day > 0) {
			$redeems_today = $this->MAIN->getTicketHandler()->countRedeemsToday($metaObj['wc_ticket']['stats_redeemed']);
			if ($redeems_today >= $max_per_day) {
				throw new Exception(sprintf(
					/* translators: 1: redeems used today 2: max allowed per day */
					esc_html__('Daily redeem limit reached: %1$d of %2$d. Try again tomorrow.', 'event-tickets-with-ticket-scanner'),
					$redeems_today, $max_per_day
				));
			}
		}

		if (empty($metaObj['wc_ticket']['redeemed_date']) || $max_redeem_amount > 1) {
			$stat_redeem = [];
			$metaObj['wc_ticket']['redeemed_date'] = wp_date("Y-m-d H:i:s");
			$metaObj['wc_ticket']['redeemed_date_tz'] = wp_timezone_string();
			$metaObj['wc_ticket']['ip'] = $this->MAIN->getCore()->getRealIpAddr();
			if (isset($data['userid'])) $metaObj['wc_ticket']['userid'] = intval($data['userid']);
			if (is_admin() || isset($data['redeemed_by_admin'])) {
				// kann sein, dass der admin nicht eingeloggt ist (externer mitarbeiter)
				$metaObj['wc_ticket']['redeemed_by_admin'] = get_current_user_id();
			}
			// Authtoken-Pfad: kein WP-User, dafür ID des Tokens als Audit-Spur.
			// Name wird live im fillObject aufgelöst (cached), nicht persistiert.
			if (!empty($data['authtoken_id'])) {
				$metaObj['wc_ticket']['redeemed_via_authtoken_id'] = (int) $data['authtoken_id'];
			}

			$stat_redeem['redeemed_date'] = $metaObj['wc_ticket']['redeemed_date'];
			$stat_redeem['redeemed_date_tz'] = wp_timezone_string();
			$stat_redeem['ip'] = $metaObj['wc_ticket']['ip'];
			$stat_redeem['userid'] = $metaObj['wc_ticket']['userid'];
			$stat_redeem['redeemed_by_admin'] = $metaObj['wc_ticket']['redeemed_by_admin'];
			$stat_redeem['redeemed_via_authtoken_id'] = (int) ($metaObj['wc_ticket']['redeemed_via_authtoken_id'] ?? 0);
			$metaObj['wc_ticket']['stats_redeemed'][] = $stat_redeem;

			// set the last usage
			$metaObj['used']['reg_ip'] = $this->MAIN->getCore()->getRealIpAddr();
			$metaObj['used']['reg_request'] = $metaObj['wc_ticket']['redeemed_date'];
			$metaObj['used']['reg_request_tz'] = $metaObj['wc_ticket']['redeemed_date_tz'];

			if (isset($data['userid'])) $metaObj['used']['reg_userid'] = intval($data['userid']);

			$codeObj['meta'] = $this->MAIN->getCore()->json_encode_with_error_handling($metaObj);

			$felder = ["meta"=>$codeObj['meta'], "redeemed"=>1];
			if ($this->MAIN->isPremium() && method_exists($this->MAIN->getPremiumFunctions(), 'redeemWoocommerceTicketForCode')) {
				$felder = $this->MAIN->getPremiumFunctions()->redeemWoocommerceTicketForCode($codeObj, $felder, $data);
			}
			$where = ['id'=>intval($codeObj['id'])];

			$this->MAIN->getDB()->update("codes", $felder, $where);
			$this->MAIN->getCore()->triggerWebhooks(13, $codeObj);
			do_action( $this->MAIN->_do_action_prefix.'admin_redeemWoocommerceTicketForCode', $data, $codeObj );
		}
		return $codeObj;
	}
	private function setWoocommerceTicketForCode($data) {
		if (!isset($data['code'])) throw new Exception("#9623 code parameter is missing");
		$codeObj = $this->MAIN->getCore()->retrieveCodeByCode($data['code']);
		$metaObj = $this->MAIN->getCore()->encodeMetaValuesAndFillObject($codeObj['meta'], $codeObj);

		if ($metaObj['wc_ticket']['is_ticket'] == 1) return $codeObj; // früher abbruch

		// check order
		if (!isset($metaObj['woocommerce']) || !isset($metaObj['woocommerce']['order_id']) || $metaObj['woocommerce']['order_id'] == 0) throw new Exception("#9624 ticket number is not bound to an order");
		// check if woocommerce exists
		if ( ! class_exists( 'WooCommerce' ) )  throw new Exception("#9625 WooCommerce plugin is missing or not active");

		if ( !function_exists( 'wc_get_order' ) ) {
		    require_once ABSPATH . PLUGINDIR . '/woocommerce/includes/wc-order-functions.php';
		}
		// include Woocommerce file for delete
		if ( !function_exists( 'wc_add_order_item_meta' ) || !function_exists('wc_delete_order_item_meta')) {
			require_once ABSPATH . PLUGINDIR . '/woocommerce/includes/wc-order-item-functions.php';
		}

		$order_id = intval($metaObj['woocommerce']['order_id']);
		$order = new WC_Order( $order_id );

		// set order note
		$order->add_order_note( sprintf(/* translators: %1$s: ticket number */esc_html__('Order item changed to ticket with ticket number: %1$s', 'event-tickets-with-ticket-scanner'), $codeObj['code_display']));

		// set
		$item_id = intval($metaObj['woocommerce']['item_id']);
		wc_delete_order_item_meta( $item_id, '_saso_eventtickets_is_ticket' );
		wc_add_order_item_meta( $item_id, '_saso_eventtickets_is_ticket', '1', true);

		// set codeobj meta and set webhook trigger

		$metaObj['wc_ticket']['set_by_admin'] = get_current_user_id();
		$metaObj['wc_ticket']['set_by_admin_date'] = wp_date("d.m.Y H:i:s");
		$metaObj['wc_ticket']['set_by_admin_date_tz'] = wp_timezone_string();

		$codeObj['meta'] = $this->MAIN->getCore()->json_encode_with_error_handling($metaObj);

		$felder = ["meta"=>$codeObj['meta']];
		if ($this->MAIN->isPremium() && method_exists($this->MAIN->getPremiumFunctions(), 'setWoocommerceTicketForCode')) {
			$felder = $this->MAIN->getPremiumFunctions()->setWoocommerceTicketForCode($codeObj, $felder, $code);
		}
		$where = ['id'=>intval($codeObj['id'])];

		$this->MAIN->getDB()->update("codes", $felder, $where);

		return $this->setWoocommerceTicketInfoForCode($data['code']);
	}
	public function setWoocommerceTicketInfoForCode($code, $namePerTicket="", $valuePerTicket="", $dayPerTicket="", $seatData=null) {
		$codeObj = $this->MAIN->getCore()->retrieveCodeByCode($code);
		$metaObj = $this->MAIN->getCore()->encodeMetaValuesAndFillObject($codeObj['meta'], $codeObj);

		$metaObj['wc_ticket']['is_ticket'] = 1;
		$namePerTicket = trim($namePerTicket);
		if (!empty($namePerTicket)) {
			$metaObj['wc_ticket']['name_per_ticket'] = substr($namePerTicket, 0, 140);
		}
		$valuePerTicket = trim($valuePerTicket);
		if (!empty($valuePerTicket)) {
			$metaObj['wc_ticket']['value_per_ticket'] = substr($valuePerTicket, 0, 140);
		}
		$dayPerTicket = trim($dayPerTicket);
		if (!empty($dayPerTicket)) {
			$metaObj['wc_ticket']['is_daychooser'] = 1;
			$metaObj['wc_ticket']['day_per_ticket'] = substr($dayPerTicket, 0, 10); // 9 should be fine too
		}
		// Seat data for seating plan integration
		if (!empty($seatData) && is_array($seatData) && !empty($seatData['seat_id'])) {
			$metaObj['wc_ticket']['seat_id'] = (int) $seatData['seat_id'];
			$metaObj['wc_ticket']['seat_identifier'] = substr($seatData['seat_identifier'] ?? '', 0, 50);
			$metaObj['wc_ticket']['seat_label'] = substr($seatData['seat_label'] ?? '', 0, 140);
			$metaObj['wc_ticket']['seat_category'] = substr($seatData['seat_category'] ?? '', 0, 50);
		}
		if (empty($metaObj['wc_ticket']['idcode']))	$metaObj['wc_ticket']['idcode'] = crc32($codeObj['id']."-".time());
		$metaObj['wc_ticket']['_url'] = $this->MAIN->getCore()->getTicketURL($codeObj, $metaObj);
		$metaObj['wc_ticket']['_public_ticket_id'] = $this->MAIN->getCore()->getTicketId($codeObj, $metaObj);

		$codeObj['meta'] = $this->MAIN->getCore()->json_encode_with_error_handling($metaObj);

		$felder = ["meta"=>$codeObj['meta']];
		if ($this->MAIN->isPremium() && method_exists($this->MAIN->getPremiumFunctions(), 'setWoocommerceTicketInfoForCode')) {
			$felder = $this->MAIN->getPremiumFunctions()->setWoocommerceTicketInfoForCode($codeObj, $felder, $code);
		}
		$where = ['id'=>intval($codeObj['id'])];

		$this->MAIN->getDB()->update("codes", $felder, $where);
		$this->MAIN->getCore()->triggerWebhooks(12, $codeObj);
		do_action( $this->MAIN->_do_action_prefix.'admin_setWoocommerceTicketInfoForCode', $code, $codeObj );
		return $codeObj;
	}
	public function addCode($data) {
		if (!isset($data['code']) || trim($data['code']) == "") throw new Exception("#201 code parameter missing");
		$id = $this->_addCode($data['code'], isset($data['list_id']) ? intval($data['list_id']) : 0);
		if ($this->MAIN->isPremium() && method_exists($this->MAIN->getPremiumFunctions(), 'updateCodeMetas')) {
			$this->MAIN->getPremiumFunctions()->updateCodeMetas($data['code'], $data);
		}
		do_action( $this->MAIN->_do_action_prefix.'admin_addCode', $data, $id );
		return $id;
	}
	public function addCodes($data) {
		if (!isset($data['codes'])) throw new Exception("#211 codes parameter missing");
		if (!is_array($data['codes'])) throw new Exception("#212 codes parameter must be an array");

		$ret = ['ok'=>[], 'notok'=>[]];

		foreach($data['codes'] as $v) {
			$ok = false;
			try {
				$id = $this->_addCode($v, isset($data['list_id']) ? intval($data['list_id']) : 0);
				$ret['ok'][] = $v;
				$ok = true;
			} catch (Exception $e) {
				$ret['notok'][] = $v;
			}
			try {
				if ($ok && $this->MAIN->isPremium() && method_exists($this->MAIN->getPremiumFunctions(), 'updateCodeMetas')) {
					$this->MAIN->getPremiumFunctions()->updateCodeMetas($v, $data);
				}
			} catch (Exception $e) {}
		}

		$ret['total_size'] = $this->MAIN->getDB()->getCodesSize();
		return $ret;
	}
	private function editCode($data) {
		if (!isset($data['code']) || trim($data['code']) == "") throw new Exception("#206 code parameter missing");
		$data['code'] = $this->MAIN->getCore()->clearCode($data['code']);
		$codeObj = $this->MAIN->getCore()->retrieveCodeByCode($data['code']);
		$felder = [];
		if (isset($data['list_id'])) $felder['list_id'] = intval($data['list_id']);
		if (isset($data['cvv'])) $felder['cvv'] = trim($data['cvv']);
		if (isset($data['code_display'])) $felder['code_display'] = trim($data['code_display']);
		if (isset($data['order_id'])) $felder['order_id'] = intval($data['order_id']);
		if (isset($data['aktiv']) && (intval($data['aktiv']) == 1 || intval($data['aktiv']) == 2)) $felder['aktiv'] = intval($data['aktiv']);
		if ($this->MAIN->isPremium() && method_exists($this->MAIN->getPremiumFunctions(), 'setFelderCodeEdit')) {
			$felder = $this->MAIN->getPremiumFunctions()->setFelderCodeEdit($felder, $data, $codeObj);
		}
		if (count($felder) > 0) {
			$where = ["code"=>$this->MAIN->getDB()->reinigen_in($data['code'])];
			$ret = $this->MAIN->getDB()->update("codes", $felder, $where);
			do_action( $this->MAIN->_do_action_prefix.'admin_editCode', $data, $ret );
			return $ret;
		}
		return "nothing to update";
	}
	public function removeCode($data) {
		if (!isset($data['id'])) throw new Exception("#207 ticket id parameter is missing - cannot remove the ticket");

		$codeObj = $this->MAIN->getCore()->retrieveCodeById($data['id']);
		$data['code'] = $codeObj['code'];

		// entferne code from produkt
		$this->removeRedeemWoocommerceTicketForCode($data);
		$this->removeWoocommerceOrderInfoFromCode($data); // remove order info
		$this->removeWoocommerceRstrPurchaseInfoFromCode($data); // remove wc_rp info

		$code = $this->MAIN->getCore()->clearCode($data['code']);
		//$sql = "delete from ".$this->MAIN->getDB()->getTabelle("codes")." where code = '".$this->MAIN->getDB()->reinigen_in($code)."'";
		$sql = "delete from ".$this->MAIN->getDB()->getTabelle("codes")." where id = ".intval($data['id']);
		$ret = $this->MAIN->getDB()->_db_query($sql);

		return $ret;
	}
	private function removeCodes($data) {
		if (!isset($data['ids'])) throw new Exception("#209 ticket ids parameter is missing - cannot remove tickets");
		if (!is_array($data['ids'])) throw new Exception("#210 ticket ids must be an array");
		foreach($data['ids'] as $v) {
			$sql = "delete from ".$this->MAIN->getDB()->getTabelle("codes")." where id = '".intval($v)."'";
			$this->MAIN->getDB()->_db_query($sql);
		}
		do_action( $this->MAIN->_do_action_prefix.'admin_removeCodes', $data );
		return count($data['ids']);
	}

	/**
	 * Remove all codes/tickets from a specific list
	 * Uses removeCode() for each ticket to ensure proper cleanup of WooCommerce data
	 *
	 * @param array $data Must contain 'list_id'
	 * @return array ['deleted' => int, 'errors' => int]
	 */
	private function removeAllCodesFromList(array $data): array {
		if (!isset($data['list_id'])) {
			throw new Exception("#211 list_id parameter is missing - cannot remove tickets");
		}

		$list_id = intval($data['list_id']);
		if ($list_id <= 0) {
			throw new Exception("#212 list_id must be a positive integer");
		}

		// Get all codes from this list
		$sql = "SELECT id, code FROM " . $this->MAIN->getDB()->getTabelle("codes") . " WHERE list_id = " . $list_id;
		$codes = $this->MAIN->getDB()->_db_select($sql);

		$deleted = 0;
		$errors = 0;

		foreach ($codes as $codeRow) {
			try {
				// Use removeCode to properly clean up WooCommerce data
				$this->removeCode(['id' => $codeRow['id'], 'code' => $codeRow['code']]);
				$deleted++;
			} catch (Exception $e) {
				$errors++;
				$this->MAIN->getCore()->logError("Error deleting code ID " . $codeRow['id'] . ": " . $e->getMessage());
			}
		}

		// Log the bulk deletion
		$this->MAIN->getCore()->logError(
			sprintf("Bulk delete: %d tickets deleted from list ID %d (errors: %d)", $deleted, $list_id, $errors),
			'info'
		);

		do_action($this->MAIN->_do_action_prefix . 'admin_removeAllCodesFromList', $data, $deleted, $errors);

		return ['deleted' => $deleted, 'errors' => $errors];
	}

	private function emptyTableLists($data) {
		$sql = "update ".$this->MAIN->getDB()->getTabelle("codes")." set list_id = 0";
		$this->MAIN->getDB()->_db_query($sql);
		$sql = "delete from ".$this->MAIN->getDB()->getTabelle("lists");
		return $this->MAIN->getDB()->_db_query($sql);
	}
	private function emptyTableCodes($data) {
		$sql = "delete from ".$this->MAIN->getDB()->getTabelle("codes");
		return $this->MAIN->getDB()->_db_query($sql);
	}
	private function exportTableCodes($data) {
		$delimiters = [',',';','|'];
		$filesuffixes = ['.csv','.txt'];
		$orderbys = ['time', 'code', 'code_display', 'list_name'];
		$orderbydirections = ['asc','desc'];
		$delimiter = $delimiters[0];
		$filesuffix = $filesuffixes[0];
		$orderby = $orderbys[0];
		$orderbydirection = $orderbydirections[0];

		$displayAdminAreaColumnRedeemedInfo = $this->isOptionCheckboxActive('displayAdminAreaColumnRedeemedInfo');
		$displayAdminAreaColumnBillingName = $this->isOptionCheckboxActive('displayAdminAreaColumnBillingName');
		$displayAdminAreaColumnBillingCompany = $this->isOptionCheckboxActive('displayAdminAreaColumnBillingCompany');

		$field_options = [
			"displayAdminAreaColumnRedeemedInfo"=>$displayAdminAreaColumnRedeemedInfo,
			"displayAdminAreaColumnBillingName"=>$displayAdminAreaColumnBillingName,
			"displayAdminAreaColumnBillingCompany"=>$displayAdminAreaColumnBillingCompany,
		];
		$fields = $this->getExportColumnFields($field_options);

		if (isset($data['delimiter']) && isset($delimiters[$data['delimiter']-1])) $delimiter = $delimiters[$data['delimiter']-1];
		if (isset($data['filesuffix']) && isset($filesuffixes[$data['filesuffix']-1])) $filesuffix = $filesuffixes[$data['filesuffix']-1];
		if (isset($data['orderby']) && isset($orderbys[$data['orderby']-1])) $orderby = $orderbys[$data['orderby']-1];
		if (isset($data['orderbydirection']) && isset($orderbydirections[$data['orderbydirection']-1])) $orderbydirection = $orderbydirections[$data['orderbydirection']-1];
		set_time_limit(0);
		// hole daten
		$sql = "select a.*, b.name as list_name from ".$this->MAIN->getDB()->getTabelle("codes")." a left join ".$this->MAIN->getDB()->getTabelle("lists")." b on a.list_id = b.id";
		if (isset($data['listchooser']) && !empty($data['listchooser']) && intval($data['listchooser']) > 0) {
			$sql .= " where a.list_id = ".intval($data['listchooser']);
		}
		$sql .= " order by ".$orderby." ".$orderbydirection;
		if (isset($data['rangestart'])) {
			$sql .= " limit ".intval($data['rangestart']);
			if (isset($data['rangeamount'])) {
				$sql .= ", ".intval($data['rangeamount']);
			}
		}
		$daten = $this->MAIN->getDB()->_db_datenholen($sql);
		foreach($daten as &$row) {
			$row = $this->transformMetaObjectToExportColumn($row, $fields, $field_options);
		}
		// sende csv datei
		$filename = "export_codes_sasoEventtickets_".wp_date("YmdHis").$filesuffix;

		if ($this->MAIN->isPremium() && method_exists($this->MAIN->getPremiumFunctions(), 'exportTableCodes')) {
			$ret_ar = $this->MAIN->getPremiumFunctions()->exportTableCodes($data, $daten, $filename, $delimiter);
			if (is_array($ret_ar)) {
				if (isset($ret_ar["daten"])) $daten = $ret_ar["daten"];
				if (isset($ret_ar["filename"])) $filename = $ret_ar["filename"];
				if (isset($ret_ar["delimiter"])) $delimiter = $ret_ar["delimiter"];
			}
		}
		// else send csv data
		SASO_EVENTTICKETS::_basics_sendeDateiCSVvonDBdaten($daten, $filename, $delimiter);
		exit;
	}
	private function getExportColumnFields($options=[]) {
		$fields = [
			'meta_validation', 'meta_validation_first_success', 'meta_validation_first_success_tz', 'meta_validation_first_ip', 'meta_validation_last_success', 'meta_validation_last_success_tz', 'meta_validation_last_ip',
			'meta_user', 'meta_user_reg_approved', 'meta_user_reg_request_date', 'meta_user_reg_request_date_tz', 'meta_user_value', 'meta_user_reg_ip', 'meta_user_reg_userid',
			'meta_expireDate',
			'meta_used', 'meta_used_reg_ip', 'meta_used_reg_request_date', 'meta_used_reg_request_date_tz', 'meta_used_reg_userid',
			'meta_confirmedCount',
			'meta_woocommerce', 'meta_woocommerce_order_id', 'meta_woocommerce_product_id', 'meta_woocommerce_creation_date', 'meta_woocommerce_creation_date_tz', 'meta_woocommerce_item_id', 'meta_woocommerce_user_id',
			'meta_wc_rp', 'meta_wc_rp_order_id', 'meta_wc_rp_product_id', 'meta_wc_rp_creation_date', 'meta_wc_rp_creation_date_tz', 'meta_wc_rp_item_id',
			'meta_wc_ticket', 'meta_wc_ticket_is_ticket', 'meta_wc_ticket_ip', 'meta_wc_ticket_userid', 'meta_wc_ticket_redeemed_date', 'meta_wc_ticket_redeemed_date_tz', 'meta_wc_ticket_redeemed_by_admin', 'meta_wc_ticket_redeemed_via_authtoken_id', 'meta_wc_ticket_redeemed_via_authtoken_name', 'meta_wc_ticket_set_by_admin', 'meta_wc_ticket_set_by_admin_date', 'meta_wc_ticket_set_by_admin_date_tz', 'meta_wc_ticket_idcode', 'meta_wc_ticket_stats_redeemed', 'meta_wc_ticket_public_ticket_id','meta_wc_ticket_customer_name', 'meta_wc_ticket_name_per_ticket', 'meta_wc_ticket_is_daychooser', 'meta_wc_ticket_day_per_ticket', 'meta_wc_ticket_subs', 'meta_wc_ticket_seat_id', 'meta_wc_ticket_seat_identifier', 'meta_wc_ticket_seat_label', 'meta_wc_ticket_seat_category'
			];
		if ($options != null && is_array($options)) {
			if (isset($options["displayAdminAreaColumnBillingName"])) {
				$fields[] = "displayAdminAreaColumnBillingName";
			}
			if (isset($options["displayAdminAreaColumnBillingCompany"])) {
				$fields[] = "displayAdminAreaColumnBillingCompany";
			}
		}
		if ($this->MAIN->isPremium() && method_exists($this->MAIN->getPremiumFunctions(), 'getExportColumnFields')) {
			$fields = $this->MAIN->getPremiumFunctions()->getExportColumnFields($fields);
		}
		return $fields;
	}
	public function transformMetaObjectToExportColumn($row, $fields=null, $options=[]) {
		if ($fields == null) {
			$fields = $this->getExportColumnFields();
		}
		foreach($fields as $v) {
			$row[$v] = '';
		}
		// nehme meta object
		if (!empty($row['meta'])) {
			$metaObj = $this->MAIN->getCore()->encodeMetaValuesAndFillObject($row['meta']);
			// zerlege das object und inhalte in einzelne spalten
			if (isset($metaObj['validation'])) {
				if (!empty($metaObj['validation']['first_success'])) $row['meta_validation_first_success'] = $metaObj['validation']['first_success'];
				if (!empty($metaObj['validation']['first_success_tz'])) $row['meta_validation_first_success_tz'] = $metaObj['validation']['first_success_tz'];
				if (!empty($metaObj['validation']['first_ip'])) $row['meta_validation_first_ip'] = $metaObj['validation']['first_ip'];
				if (!empty($metaObj['validation']['last_success'])) $row['meta_validation_last_success'] = $metaObj['validation']['last_success'];
				if (!empty($metaObj['validation']['last_success_tz'])) $row['meta_validation_last_success_tz'] = $metaObj['validation']['last_success_tz'];
				if (!empty($metaObj['validation']['last_ip'])) $row['meta_validation_last_ip'] = $metaObj['validation']['last_ip'];
			}
			if (isset($metaObj['user'])) {
				if (!empty($metaObj['user']['reg_approved'])) $row['meta_user_reg_approved'] = $metaObj['user']['reg_approved'];
				if (!empty($metaObj['user']['reg_request'])) $row['meta_user_reg_request_date'] = $metaObj['user']['reg_request'];
				if (!empty($metaObj['user']['reg_request_tz'])) $row['meta_user_reg_request_date_tz'] = $metaObj['user']['reg_request_tz'];
				if (!empty($metaObj['user']['reg_userid'])) $row['meta_user_reg_userid'] = $metaObj['user']['reg_userid'];
				if (!empty($metaObj['user']['value'])) $row['meta_user_value'] = $metaObj['user']['value'];
				if (!empty($metaObj['user']['reg_ip'])) $row['meta_user_reg_ip'] = $metaObj['user']['reg_ip'];
				if (!empty($row['meta_user_reg_request_date'])) $row['meta_user'] = 1;
			}
			if (isset($metaObj['expireDate'])) $row['meta_expireDate'] = $metaObj['expireDate'];
			if (isset($metaObj['used'])) {
				if (!empty($metaObj['used']['reg_ip'])) $row['meta_used_reg_ip'] = $metaObj['used']['reg_ip'];
				if (!empty($metaObj['used']['reg_request'])) $row['meta_used_reg_request_date'] = $metaObj['used']['reg_request'];
				if (!empty($metaObj['used']['reg_request_tz'])) $row['meta_used_reg_request_date_tz'] = $metaObj['used']['reg_request_tz'];
				if (!empty($metaObj['used']['reg_userid'])) $row['meta_used_reg_userid'] = $metaObj['used']['reg_userid'];
				if (!empty($row['meta_used_req_request_date'])) $row['meta_used'] = 1;
			}
			if (isset($metaObj['confirmedCount'])) $row['meta_confirmedCount'] = $metaObj['confirmedCount'];
			if (isset($metaObj['woocommerce'])) {
				if (!empty($metaObj['woocommerce']['order_id'])) $row['meta_woocommerce_order_id'] = $metaObj['woocommerce']['order_id'];
				if (!empty($metaObj['woocommerce']['product_id'])) $row['meta_woocommerce_product_id'] = $metaObj['woocommerce']['product_id'];
				if (!empty($metaObj['woocommerce']['creation_date'])) $row['meta_woocommerce_creation_date'] = $metaObj['woocommerce']['creation_date'];
				if (!empty($metaObj['woocommerce']['creation_date_tz'])) $row['meta_woocommerce_creation_date_tz'] = $metaObj['woocommerce']['creation_date_tz'];
				if (!empty($metaObj['woocommerce']['item_id'])) $row['meta_woocommerce_item_id'] = $metaObj['woocommerce']['item_id'];
				if (!empty($metaObj['woocommerce']['user_id'])) $row['meta_woocommerce_user_id'] = $metaObj['woocommerce']['user_id'];
			}
			if (isset($metaObj['wc_rp'])) {
				if (!empty($metaObj['wc_rp']['order_id'])) $row['meta_wc_rp_order_id'] = $metaObj['wc_rp']['order_id'];
				if (!empty($metaObj['wc_rp']['product_id'])) $row['meta_wc_rp_product_id'] = $metaObj['wc_rp']['product_id'];
				if (!empty($metaObj['wc_rp']['creation_date'])) $row['meta_wc_rp_creation_date'] = $metaObj['wc_rp']['creation_date'];
				if (!empty($metaObj['wc_rp']['creation_date_tz'])) $row['meta_wc_rp_creation_date_tz'] = $metaObj['wc_rp']['creation_date_tz'];
				if (!empty($metaObj['wc_rp']['item_id'])) $row['meta_wc_rp_item_id'] = $metaObj['wc_rp']['item_id'];
			}
			if (isset($metaObj['wc_ticket'])) {
				if (!empty($metaObj['wc_ticket']['is_ticket'])) $row['meta_wc_ticket_is_ticket'] = $metaObj['wc_ticket']['is_ticket'];
				if (!empty($metaObj['wc_ticket']['ip'])) $row['meta_wc_ticket_ip'] = $metaObj['wc_ticket']['ip'];
				if (!empty($metaObj['wc_ticket']['userid'])) $row['meta_wc_ticket_userid'] = $metaObj['wc_ticket']['userid'];
				if (!empty($metaObj['wc_ticket']['redeemed_date'])) $row['meta_wc_ticket_redeemed_date'] = $metaObj['wc_ticket']['redeemed_date'];
				if (!empty($metaObj['wc_ticket']['redeemed_date_tz'])) $row['meta_wc_ticket_redeemed_date_tz'] = $metaObj['wc_ticket']['redeemed_date_tz'];
				if (!empty($metaObj['wc_ticket']['redeemed_by_admin'])) $row['meta_wc_ticket_redeemed_by_admin'] = $metaObj['wc_ticket']['redeemed_by_admin'];
				if (!empty($metaObj['wc_ticket']['redeemed_via_authtoken_id'])) {
					$row['meta_wc_ticket_redeemed_via_authtoken_id']   = $metaObj['wc_ticket']['redeemed_via_authtoken_id'];
					$row['meta_wc_ticket_redeemed_via_authtoken_name'] = $metaObj['wc_ticket']['_redeemed_via_authtoken_name'] ?? '';
				}
				if (!empty($metaObj['wc_ticket']['set_by_admin'])) $row['meta_wc_ticket_redeemed_by_admin'] = $metaObj['wc_ticket']['set_by_admin'];
				if (!empty($metaObj['wc_ticket']['set_by_admin_date'])) $row['meta_wc_ticket_set_by_admin_date'] = $metaObj['wc_ticket']['set_by_admin_date'];
				if (!empty($metaObj['wc_ticket']['set_by_admin_date_tz'])) $row['meta_wc_ticket_set_by_admin_date_tz'] = $metaObj['wc_ticket']['set_by_admin_date_tz'];
				if (!empty($metaObj['wc_ticket']['idcode'])) $row['meta_wc_ticket_idcode'] = $metaObj['wc_ticket']['idcode'];
				if (!empty($metaObj['wc_ticket']['stats_redeemed'])) $row['meta_wc_ticket_stats_redeemed'] = $this->MAIN->getCore()->json_encode_with_error_handling($metaObj['wc_ticket']['stats_redeemed']);
				if (!empty($metaObj['wc_ticket']['_public_ticket_id'])) $row['meta_wc_ticket_public_ticket_id'] = $metaObj['wc_ticket']['_public_ticket_id'];
				if (!empty($metaObj['wc_ticket']['name_per_ticket'])) $row['meta_wc_ticket_name_per_ticket'] = $metaObj['wc_ticket']['name_per_ticket'];
				if (!empty($metaObj['wc_ticket']['is_daychooser'])) $row['meta_wc_ticket_is_daychooser'] = $metaObj['wc_ticket']['is_daychooser'];
				if (!empty($metaObj['wc_ticket']['day_per_ticket'])) $row['meta_wc_ticket_day_per_ticket'] = $metaObj['wc_ticket']['day_per_ticket'];
				if (!empty($metaObj['wc_ticket']['subs'])) $row['meta_wc_ticket_subs'] = $metaObj['wc_ticket']['subs'];
				// Seat data
				if (!empty($metaObj['wc_ticket']['seat_id'])) $row['meta_wc_ticket_seat_id'] = $metaObj['wc_ticket']['seat_id'];
				if (!empty($metaObj['wc_ticket']['seat_identifier'])) $row['meta_wc_ticket_seat_identifier'] = $metaObj['wc_ticket']['seat_identifier'];
				if (!empty($metaObj['wc_ticket']['seat_label'])) $row['meta_wc_ticket_seat_label'] = $metaObj['wc_ticket']['seat_label'];
				if (!empty($metaObj['wc_ticket']['seat_category'])) $row['meta_wc_ticket_seat_category'] = $metaObj['wc_ticket']['seat_category'];

				if ($options != null && is_array($options)) {
					if (isset($options["displayAdminAreaColumnBillingName"]) && $options["displayAdminAreaColumnBillingName"]) {
						if (!empty($metaObj['wc_ticket']['_username'])) $row['displayAdminAreaColumnBillingName'] = $metaObj['wc_ticket']['_username'];
						$row['meta_wc_ticket_customer_name'] = $this->getCustomerName($metaObj['woocommerce']['order_id']);
					}
					if (isset($options["displayAdminAreaColumnBillingCompany"]) && $options["displayAdminAreaColumnBillingCompany"]) {
						if (!empty($metaObj['wc_ticket']['_company'])) $row['displayAdminAreaColumnBillingCompany'] = $metaObj['wc_ticket']['_company'];
						$row['meta_wc_ticket_customer_company'] = $this->getCompanyName($metaObj['woocommerce']['order_id']);
					}
				}
			}
		}
		if ($this->MAIN->isPremium() && method_exists($this->MAIN->getPremiumFunctions(), 'transformMetaObjectToExportColumn')) {
			$row = $this->MAIN->getPremiumFunctions()->transformMetaObjectToExportColumn($row);
		}
		return $row;
	}

	public function performJobsAfterDBUpgraded($dbversion="", $dbversion_pre="") {
		if (empty($dbversion)) {
			$dbversion = $this->MAIN->getDB()->dbversion;
		}

		if (version_compare( $dbversion, '1.0', '>' ) && version_compare( $dbversion, '2.0', '<' )) {
			global $wpdb;
			$table = $this->MAIN->getDB()->getTabelle("codes");
			$wpdb->query("UPDATE {$table} SET redeemed = 1 WHERE redeemed = 0 AND meta LIKE '%\"redeemed_date\":\"%' AND meta NOT LIKE '%\"redeemed_date\":\"\"%'");
		}

		// v1.10: Add last_seen column to seat_blocks for heartbeat tracking
		if (version_compare($dbversion, '1.10', '>=') && version_compare($dbversion_pre, '1.10', '<')) {
			global $wpdb;
			$table = $this->MAIN->getDB()->getTabelle('seat_blocks');
			// Check if column exists before adding
			$columns = $wpdb->get_col("DESCRIBE {$table}", 0);
			if (!in_array('last_seen', $columns)) {
				$wpdb->query("ALTER TABLE {$table} ADD COLUMN last_seen datetime DEFAULT NULL AFTER expires_at");
			}
		}

		// v1.11: Migrate options from wp_options to custom table
		if (version_compare($dbversion, '1.11', '>=') && version_compare($dbversion_pre, '1.11', '<')) {
			$this->migrateOptionsToCustomTable();
		}

		// v1.13: Add changed_at index to options_history
		if (version_compare($dbversion, '1.13', '>=') && version_compare($dbversion_pre, '1.13', '<')) {
			global $wpdb;
			$table = $this->MAIN->getDB()->getTabelle('options_history');
			$indexes = $wpdb->get_results("SHOW INDEX FROM {$table} WHERE Key_name = 'changed_at'");
			if (empty($indexes)) {
				$wpdb->query("CREATE INDEX changed_at ON {$table} (changed_at)");
			}
		}

		// Premium and hook calls wrapped in try/catch — an exception here must NOT
		// prevent update_option(db_version) from being saved in installiereTabellen(),
		// otherwise the upgrade re-runs on every request → infinite crash loop.
		try {
			if ($this->MAIN->isPremium() && method_exists($this->MAIN->getPremiumFunctions(), 'performJobsAfterDBUpgraded')) {
				$this->MAIN->getPremiumFunctions()->performJobsAfterDBUpgraded($dbversion, $dbversion_pre);
			}
		} catch (\Throwable $e) {
			$this->logErrorToDB($e, null, 'Premium upgrade job failed: ' . $e->getMessage());
		}
		try {
			do_action( $this->MAIN->_do_action_prefix.'performJobsAfterDBUpgraded', $dbversion, $dbversion_pre );
		} catch (\Throwable $e) {
			$this->logErrorToDB($e, null, 'Upgrade action hook failed: ' . $e->getMessage());
		}
	}

	/**
	 * Migrate all plugin options from wp_options to the custom saso_eventtickets_options table.
	 * Each option is migrated atomically (INSERT IGNORE + DELETE) so a crash at any point
	 * leaves the system in a recoverable state.
	 */
	public function migrateOptionsToCustomTable(): array {
		global $wpdb;
		$table = $this->MAIN->getDB()->getTabelle('options');
		$prefix = $this->MAIN->getPrefix();
		$options = $this->MAIN->getOptions()->getOptions();
		$migrated = 0;
		$skipped = 0;

		// Collect known option keys from the framework definitions
		$knownKeys = [];
		foreach ($options as $option) {
			if ($option['type'] === 'heading' || $option['type'] === 'desc') {
				continue;
			}
			$knownKeys[$option['key']] = true;

			$wpOptionKey = $prefix . $option['key'];
			$value = get_option($wpOptionKey, null);

			if ($value === null) {
				$skipped++;
				continue;
			}

			// Store in custom table (REPLACE — idempotent, updates value if already exists from partial migration)
			$storeValue = is_array($value) ? json_encode($value) : (string) $value;
			$wpdb->replace($table, [
				'option_key'   => $option['key'],
				'option_value' => $storeValue,
				'updated_at'   => wp_date('Y-m-d H:i:s'),
				'updated_by'   => get_current_user_id(),
			]);

			// Delete from wp_options
			delete_option($wpOptionKey);
			$migrated++;
		}

		// Safety net: migrate any remaining wp_options with our prefix that were NOT
		// in the framework definitions (e.g. premium options if premium plugin was not
		// active during this migration, or options from older versions).
		$prefixLen = strlen($prefix);
		$remaining = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s",
				$prefix . '%'
			),
			ARRAY_A
		);
		foreach ($remaining as $row) {
			$key = substr($row['option_name'], $prefixLen);
			// Skip internal/system keys and keys already migrated
			if (empty($key) || isset($knownKeys[$key]) || $key === '_premium_serial_expiration') {
				continue;
			}
			$value = $row['option_value'];
			$storeValue = is_array($value) ? json_encode($value) : (string) $value;
			$wpdb->replace($table, [
				'option_key'   => $key,
				'option_value' => $storeValue,
				'updated_at'   => wp_date('Y-m-d H:i:s'),
				'updated_by'   => get_current_user_id(),
			]);
			delete_option($row['option_name']);
			$migrated++;
		}

		// Mark migration complete
		update_option('saso_eventtickets_options_migrated', '1');
		$this->MAIN->getOptions()->resetMigrationCache();

		return ['migrated' => $migrated, 'skipped' => $skipped];
	}

	/**
	 * Get options change history for DataTable (server-side processing).
	 */
	public function getOptionsHistory(array $data, array $request): array {
		global $wpdb;
		$table = $this->MAIN->getDB()->getTabelle('options_history');
		$usersTable = $wpdb->users;

		$length = isset($request['length']) ? intval($request['length']) : 0;
		$draw = isset($request['draw']) ? intval($request['draw']) : 1;
		$start = isset($request['start']) ? intval($request['start']) : 0;

		$order_column = 'h.changed_at';
		if (isset($request['order'])) {
			$order_columns = ['', 'h.changed_at', 'h.option_key', 'h.old_value', 'h.new_value', 'u.display_name'];
			$colIdx = intval($request['order'][0]['column']);
			if (isset($order_columns[$colIdx]) && $order_columns[$colIdx] !== '') {
				$order_column = $order_columns[$colIdx];
			}
		}
		$order_dir = (isset($request['order'][0]['dir']) && $request['order'][0]['dir'] === 'asc') ? 'asc' : 'desc';

		$search = '';
		if (isset($request['search']['value'])) {
			$search = $this->MAIN->getDB()->reinigen_in($request['search']['value']);
		}

		$sql = "SELECT h.*, u.display_name AS changed_by_name FROM {$table} h LEFT JOIN {$usersTable} u ON h.changed_by = u.ID";
		$whereJoin = '';
		$whereCount = '';
		if ($search !== '') {
			$searchClean = $this->MAIN->getCore()->clearCode($search);
			$whereJoin = " WHERE h.option_key LIKE '%{$searchClean}%' OR h.old_value LIKE '%{$search}%' OR h.new_value LIKE '%{$search}%'";
			$whereCount = "option_key LIKE '%{$searchClean}%' OR old_value LIKE '%{$search}%' OR new_value LIKE '%{$search}%'";
			$sql .= $whereJoin;
		}
		$sql .= " ORDER BY {$order_column} {$order_dir}";
		if ($length > 0) {
			$sql .= " LIMIT {$start}, {$length}";
		}

		$rows = $this->MAIN->getDB()->_db_datenholen($sql);
		$recordsTotal = $this->MAIN->getDB()->_db_getRecordCountOfTable('options_history');
		$recordsFiltered = $recordsTotal;
		if ($whereCount !== '') {
			$recordsFiltered = $this->MAIN->getDB()->_db_getRecordCountOfTable('options_history', $whereCount);
		}

		return [
			'draw' => $draw,
			'recordsTotal' => intval($recordsTotal),
			'recordsFiltered' => intval($recordsFiltered),
			'data' => $rows,
		];
	}

	/**
	 * Revert an option to a previous value from history.
	 */
	public function revertOption(array $data): array {
		if (!isset($data['history_id'])) {
			throw new Exception('history_id is required');
		}
		global $wpdb;
		$table = $this->MAIN->getDB()->getTabelle('options_history');
		$row = $wpdb->get_row($wpdb->prepare(
			"SELECT option_key, old_value FROM {$table} WHERE id = %d", intval($data['history_id'])
		), ARRAY_A);
		if (!$row) {
			throw new Exception('History entry not found');
		}
		$decoded = json_decode($row['old_value'], true);
		$value = is_array($decoded) ? $decoded : $row['old_value'];
		$this->MAIN->getOptions()->changeOption(['key' => $row['option_key'], 'value' => $value]);
		return ['reverted' => true, 'option_key' => $row['option_key']];
	}

	/**
	 * Delete history entries older than 90 days.
	 */
	public function cleanupOptionsHistory(): array {
		global $wpdb;
		$table = $this->MAIN->getDB()->getTabelle('options_history');
		$keep = 10;
		$deleted = 0;
		$keys = $wpdb->get_col("SELECT DISTINCT option_key FROM {$table}");
		foreach ($keys as $key) {
			$minId = $wpdb->get_var($wpdb->prepare(
				"SELECT id FROM {$table} WHERE option_key = %s ORDER BY id DESC LIMIT 1 OFFSET %d",
				$key, $keep
			));
			if ($minId) {
				$deleted += intval($wpdb->query($wpdb->prepare(
					"DELETE FROM {$table} WHERE option_key = %s AND id <= %d",
					$key, $minId
				)));
			}
		}
		return ['deleted' => $deleted];
	}

	function show_user_profile($profileuser) {
		// zeigt infos im user profile an
		$user_id = intval($profileuser->ID);
		if ($this->isOptionCheckboxActive("wcTicketUserProfileDisplayRegisteredNumbers")) {
			echo "<h3>".__('Event Tickets Registered', 'event-tickets-with-ticket-scanner')."</h3>";
			//print_r($profileuser);
			$ret =  $this->MAIN->getMyCodeText($user_id);
			if (empty($ret)) echo "-";
			else echo $ret;
		}
		if ($this->isOptionCheckboxActive("wcTicketUserProfileDisplayBoughtNumbers")) {
			echo "<h3>".__('Event Tickets Numbers', 'event-tickets-with-ticket-scanner')."</h3>";
			$sql = "select * from ".$this->MAIN->getDB()->getTabelle("codes")." where
				json_extract(meta, '$.wc_ticket.is_ticket') = 1 and
				json_extract(meta, '$.woocommerce.user_id') = ".$user_id;
			$codes = $this->MAIN->getDB()->_db_datenholen($sql);
			echo $this->MAIN->getCodesTextAsShortList($codes);
		}
	}

	public function logErrorToDB(Exception $e, $caller_name="", $msg="") {
		$exception_msg = "";
		$trace = [];
		if ($e != null) {
			$exception_msg = $e->getMessage();
		}
		$msg = trim($msg);
		if (count($trace) > 0) {
			//$trace = debug_backtrace();
			$trace = $e->getTrace();
			for($a=0;$a<count($trace);$a++){
				$item = $trace[$a];
				$msg .= "\n";
				if (isset($item["class"])) {
					$msg .= $item["class"]."->";
				}
				if (isset($item["line"])) {
					$msg .= $item["function"]." Line: ".$item["line"];
				}
			}
		}
		if (!is_string($caller_name) || empty($caller_name) || $caller_name == null) {
			$caller_name = "";
			if (count($trace) > 1) {
				$caller_name = $trace[1]["class"]."->".$trace[1]["function"];
			}
		}
		$felder = [
			"time"=>wp_date("y-m-d H:i:s"),
			"exception_msg"=>trim($exception_msg),
			"msg"=>trim($msg),
			"caller_name"=>trim($caller_name)
		];
		$id = $this->MAIN->getDB()->insert("errorlogs", $felder);
	}
	private function emptyTableErrorLogs() {
		$sql = "delete from ".$this->MAIN->getDB()->getTabelle("errorlogs");
		$this->MAIN->getDB()->_db_query($sql);
	}
	private function getErrorLogs($data, $request) {
		$sql = "select * from ".$this->MAIN->getDB()->getTabelle("errorlogs");

		// für datatables
		$length = 0; // wieviele pro seite angezeigt werden sollen (limit)
		if (isset($request['length'])) $length = intval($request['length']);
		$draw = 1; // sequenz zähler, also fortlaufend für jede aktion auf der JS datentabelle
		if (isset($request['draw'])) $draw = intval($request['draw']);
		$start = 0;
		if (isset($request['start'])) $start = intval($request['start']);
		$order_column = "time";

		if (isset($request['order'])) {
			$order_columns = ['', 'time', 'exception_msg', 'caller_name'];
			$order_column = $order_columns[intval($request['order'][0]['column'])];
		}
		$order_dir = "asc";
		if (isset($request['order']) && $request['order'][0]['dir'] == 'desc') $order_dir = "desc";
		$search = "";
		if (isset($request['search'])) $search = $this->MAIN->getDB()->reinigen_in($request['search']['value']);

		$where = "";
		if ($search != "") {
			$where .= " caller_name like '%".$this->MAIN->getCore()->clearCode($search)."%' or exception_msg like '%".$search."%' ";
			$sql .= $where;
		}

		if ($order_column != "") $sql .= " order by ".$order_column." ".$order_dir;
		if ($length > 0) $sql .= " limit ".$start.", ".$length;

		$daten = $this->MAIN->getDB()->_db_datenholen($sql);
		$recordsTotal = $this->MAIN->getDB()->_db_getRecordCountOfTable('errorlogs');
		$recordsTotalFilter = $recordsTotal;
		if (!empty($where)) {
			$recordsTotalFilter = $this->MAIN->getDB()->_db_getRecordCountOfTable('errorlogs', $where);
		}

		return ["draw"=>$draw,
				"recordsTotal"=>intval($recordsTotal),
				"recordsFiltered"=>intval($recordsTotalFilter),
				"data"=>$daten];
	}

	/**
	 * Expose internal DB table info for support diagnostics.
	 * Returns structured JSON with table names, row counts, and column definitions.
	 */
	private function expose(string $type, $data): array {
		if ($type === "tables") {
			$tables = [];
			foreach ($this->MAIN->getDB()->getTables() as $table) {
				$fullName = $this->MAIN->getDB()->getTabelle($table);
				$columns = $this->MAIN->getDB()->_db_datenholen("DESCRIBE " . $fullName);
				$countResult = $this->MAIN->getDB()->_db_datenholen("SELECT COUNT(*) as cnt FROM " . $fullName);
				$tables[$table] = [
					'full_name' => $fullName,
					'row_count' => intval($countResult[0]['cnt'] ?? 0),
					'columns' => array_map(function($col) {
						return $col['Field'] . ' (' . $col['Type'] . ')';
					}, $columns),
				];
			}
			return ['tables' => $tables];
		}
		return ['error' => 'unknown type: ' . $type];
	}

	private function getTicketsForTesting() {
		$sql = "select distinct order_id, a.* from ".$this->MAIN->getDB()->getTabelle("codes")." a
				where order_id > 0 and json_extract(meta, '$.wc_ticket._public_ticket_id') != '' and aktiv = 1
				limit 10";
		$d = $this->MAIN->getDB()->_db_datenholen($sql);
		$tickets = [];
		foreach($d as $codeObj) {
			$metaObj = $this->MAIN->getCore()->encodeMetaValuesAndFillObject($codeObj['meta'], $codeObj);
			$codeObj["meta"] = $this->MAIN->getCore()->json_encode_with_error_handling($metaObj);
			$codeObj['_PRODUCT_NAME'] = "";
			if (isset($metaObj['woocommerce']['product_id']) && intval($metaObj['woocommerce']['product_id']) > 0) {
				$product = wc_get_product( intval($metaObj['woocommerce']['product_id']) );
				if ($product != null) {
					$codeObj['_PRODUCT_NAME'] = trim($product->get_name());
				}
			}
			$tickets[] = $codeObj;
		}
		return $tickets;
	}

	private function testing($data) {

		/*
		$order_id = 566;
		$order = wc_get_order( $order_id );
		$items = $order->get_items();
		$wc = $this->MAIN->getWC();
		foreach($items as $item_id => $item) {
			$wc->woocommerce_order_item_meta_start($item_id, $item, $order);
		}
		echo "\n\n<br><br>";
		$wc->woocommerce_email_order_meta($order, false, true, ["id"=>"customer_completed_order"]);
		*/

		//var_dump($this->getTicketsForTesting());
		//var_dump($this->MAIN->getOptions()->isOptionCheckboxActive('wcTicketDisplayOrderTicketsViewLinkOnMail'));
		//var_dump($this->MAIN->getOptions()->getOption('wcTicketDisplayOrderTicketsViewLinkOnMail'));
		//var_dump($this->MAIN->getOptions()->isOptionCheckboxActive('wcTicketDisplayDownloadAllTicketsPDFButtonOnMail'));
		//var_dump($this->MAIN->getOptions()->getOption('wcTicketDisplayDownloadAllTicketsPDFButtonOnMail'));

		//print_r($this->MAIN->getOptions()->loadOptionFromWP("wcTicketDontShowRedeemBtnOnTicket"));

		//if ( get_option('permalink_structure') ) { echo 'permalinks enabled'; }

		//echo $this->MAIN->getTicketDesignerHandler()->getTemplate();

		//print_r($this->MAIN->getNewPDFObject()->getPossibleFontFamiles());

		//$this->MAIN->getOptions()->deleteOption("wcTicketCompatibilityModeURLPath");

		//print_r($this->MAIN->getOptions()->getOptions());
		/*
		$options = $this->MAIN->getOptions()->getOptions();
		$out = fopen('php://output', 'w');
		foreach ($options as $option) {
			if ($option["type"] == "heading") {
				continue;
			}
			$list = [$option["key"], $option["label"], $option["desc"]];
			fputcsv($out, $list);
		}
		*/

		//$ticket_id = "1234";
		//$qrHandler = $this->MAIN->getTicketQRHandler();
		//$qrHandler->renderPDF($ticket_id, "I");

		//print_r($data);

		//$ret = $this->MAIN->getOptions()->isOptionCheckboxActive("wcassignmentReuseNotusedCodes");
		//echo $ret;

		//$ret = $this->MAIN->getOptions()->getOption("wcassignmentReuseNotusedCodes");
		//print_r($ret);

		//$ret = $this->getOptionValue("wcTicketAttachTicketToMailOf");
		//print_r($ret);
		//print_r($this->MAIN->getOptions()->get_wcTicketAttachTicketToMailOf());

		//print_r($this->MAIN->getCore()->retrieveCodeByCode("UJDWTMEGC"));

		//$this->performJobsAfterDBUpgraded("1.1", "1.0");

		// formatter vom product
		//$formatter = get_post_meta( 23, 'saso_eventtickets_list_formatter_values', true );

		// formatter von code list
		//$listObj = $this->getList(['id'=>1]);
		//$metaObj = $this->MAIN->getCore()->encodeMetaValuesAndFillObjectList($listObj['meta']);
		//$formatter = stripslashes($metaObj['formatter']['format']);
		//return $metaObj['webhooks'];
		//return $this->generateCode($formatter);

		/*
		$code = $this->addCodeFromListForOrder(2, 1, 2, 3);

		echo esc_html($code);
		echo "<br>Display Code";
		$sql = "select * from ".$this->MAIN->getDB()->getTabelle("codes")." where
				code = '". $this->MAIN->getDB()->reinigen_in($code) ."'";
		$d = $this->MAIN->getDB()->_db_datenholen($sql);
		print_r($d);
		echo "<br>Reverting order setting";
		$this->MAIN->getDB()->update("codes", ['semaphorecode'=>"", "order_id"=>0], ["code"=>$code]);
		*/
	}

	/**
	 * Check if format warning should be saved based on counter threshold
	 * @param int $list_id Ticket list ID
	 * @param int $counter Number of attempts needed to generate code
	 * @param string $warningType 'format_limit_threshold_warning' for 50%, 'format_end_warning' for 100%
	 * @return void
	 */
	private function checkAndSaveFormatWarning($list_id, $counter, $warningType = 'format_limit_threshold_warning') {
		try {
			$listObj = $this->getList(['id' => $list_id]);
			$metaObj = $this->MAIN->getCore()->encodeMetaValuesAndFillObjectList($listObj['meta']);

			// Check if email already sent today
			$lastEmail = $metaObj['messages'][$warningType]['last_email'] ?? '';
			$shouldSendEmail = empty($lastEmail);

			if (!empty($lastEmail)) {
				$lastEmailTime = strtotime($lastEmail);
				$timeDiff = (time() - $lastEmailTime) / 3600; // hours
				if ($timeDiff >= 24) {
					$shouldSendEmail = true;
				}
			}

			// Update warning data
			$metaObj['messages'][$warningType]['attempts'] = max($counter, $metaObj['messages'][$warningType]['attempts']);

			if ($shouldSendEmail) {
				$metaObj['messages'][$warningType]['last_email'] = wp_date("Y-m-d H:i:s");
			}

			// Save updated meta directly to DB (not via editList which would clear warnings)
			$newMeta = $this->MAIN->getCore()->json_encode_with_error_handling($metaObj);
			$this->MAIN->getDB()->update('lists', ['meta' => $newMeta], ['id' => intval($list_id)]);

			if ($shouldSendEmail) {
				$severity = ($warningType === 'format_end_warning') ? 'critical' : 'warning';
				$this->sendFormatWarningEmail($list_id, $severity, $counter);
			}

		} catch (Exception $e) {
			// Log error but don't throw
			$this->logErrorToDB($e, "", "checkAndSaveFormatWarning for list $list_id");
		}
	}

	/**
	 * Send email to admin about format exhaustion
	 * @param int $list_id Ticket list ID
	 * @param string $severity 'warning' or 'critical'
	 * @param int $counter Number of attempts
	 * @return void
	 */
	private function sendFormatWarningEmail($list_id, $severity, $counter) {
		try {
			$listObj = $this->getList(['id' => $list_id]);
			$admin_email = get_option('admin_email');
			$blog_name = get_bloginfo('name');
			$site_url = site_url();

			$subject = '';
			$message = '';

			if ($severity === 'critical') {
				$subject = sprintf(__('[%s] CRITICAL: Ticket format exhausted for "%s"', 'event-tickets-with-ticket-scanner'), $blog_name, $listObj['name']);
				$message = sprintf(
					__("WARNING: The ticket number format for list \"%s\" is exhausted!\n\nIt took %d attempts to generate a ticket code.\n\nActions:\n1. Go to: %s\n2. Edit the ticket list\n3. Increase code length or change character set\n\nWithout action, future ticket sales may fail!", 'event-tickets-with-ticket-scanner'),
					$listObj['name'],
					$counter,
					admin_url('admin.php?page=event-tickets-with-ticket-scanner')
				);
			} else {
				$subject = sprintf(__('[%s] WARNING: Ticket format running out for "%s"', 'event-tickets-with-ticket-scanner'), $blog_name, $listObj['name']);
				$message = sprintf(
					__("WARNING: The ticket number format for list \"%s\" is running out of combinations!\n\nIt took %d attempts to generate a ticket code.\n\nRecommended action:\n1. Go to: %s\n2. Edit the ticket list\n3. Consider increasing code length or character set\n\nThis is a proactive warning to prevent future issues.", 'event-tickets-with-ticket-scanner'),
					$listObj['name'],
					$counter,
					admin_url('admin.php?page=event-tickets-with-ticket-scanner')
				);
			}

			$headers = ['Content-Type: text/plain; charset=UTF-8'];
			wp_mail($admin_email, $subject, $message, $headers);

		} catch (Exception $e) {
			$this->logErrorToDB($e, "", "sendFormatWarningEmail for list $list_id");
		}
	}

	/**
	 * Get format warning from ticket list meta
	 * @param int $list_id Ticket list ID
	 * @return array|null ['type'=>'...', 'attempts'=>...] or null
	 */
	public function getFormatWarning($list_id) {
		try {
			$listObj = $this->getList(['id' => $list_id]);
			$metaObj = $this->MAIN->getCore()->encodeMetaValuesAndFillObjectList($listObj['meta']);

			// Check for critical warning first
			if (!empty($metaObj['messages']['format_end_warning']['last_email'])) {
				return [
					'type' => 'critical',
					'attempts' => $metaObj['messages']['format_end_warning']['attempts'],
					'list_name' => $listObj['name']
				];
			}

			// Check for threshold warning
			if (!empty($metaObj['messages']['format_limit_threshold_warning']['last_email'])) {
				return [
					'type' => 'warning',
					'attempts' => $metaObj['messages']['format_limit_threshold_warning']['attempts'],
					'list_name' => $listObj['name']
				];
			}

			return null;
		} catch (Exception $e) {
			return null;
		}
	}

	/**
	 * Clear format warning from ticket list meta
	 * @param int $list_id Ticket list ID
	 * @return void
	 */
	public function clearFormatWarning($list_id) {
		try {
			$listObj = $this->getList(['id' => $list_id]);
			$metaObj = $this->MAIN->getCore()->encodeMetaValuesAndFillObjectList($listObj['meta']);

			// Reset warning data
			$metaObj['messages']['format_limit_threshold_warning'] = [
				'attempts' => 0,
				'last_email' => ''
			];
			$metaObj['messages']['format_end_warning'] = [
				'attempts' => 0,
				'last_email' => ''
			];

			$metaJson = $this->MAIN->getCore()->json_encode_with_error_handling($metaObj);
			$this->MAIN->getDB()->update('lists', ['meta' => $metaJson], ['id' => intval($list_id)]);
		} catch (Exception $e) {
			$this->logErrorToDB($e, "", "clearFormatWarning for list $list_id");
		}
	}

	/**
	 * Check if the license/update server is reachable.
	 * Useful for diagnosing Premium update issues.
	 *
	 * @param array $data
	 * @return array Result with 'success' (bool), 'time' (ms), 'message' (string)
	 */
	public function checkLicenseServer($data) {
		$start_time = microtime(true);

		// Test vollstart.com reachability
		$test_url = 'https://vollstart.com/';
		$response = wp_remote_get($test_url, [
			'timeout' => 10,
			'sslverify' => true,
			'user-agent' => 'Event-Tickets-License-Check/' . $this->MAIN->getPluginVersion()
		]);

		$time_ms = round((microtime(true) - $start_time) * 1000);

		if (is_wp_error($response)) {
			$error_msg = $response->get_error_message();
			return [
				'success' => false,
				'reachable' => false,
				'time' => $time_ms,
				'message' => sprintf(
					__('License server NOT reachable. Error: %s', 'event-tickets-with-ticket-scanner'),
					esc_html($error_msg)
				),
				'error' => $error_msg
			];
		}

		$http_code = wp_remote_retrieve_response_code($response);
		if ($http_code >= 200 && $http_code < 300) {
			return [
				'success' => true,
				'reachable' => true,
				'time' => $time_ms,
				'message' => sprintf(
					__('License server reachable! Response time: %d ms', 'event-tickets-with-ticket-scanner'),
					$time_ms
				),
				'http_code' => $http_code
			];
		}

		return [
			'success' => false,
			'reachable' => false,
			'time' => $time_ms,
			'message' => sprintf(
				__('License server returned unexpected HTTP code: %d', 'event-tickets-with-ticket-scanner'),
				$http_code
			),
			'http_code' => $http_code
		];
	}

	// ── Context-Wizards: dismissed suggestions per user (#232) ──

	/**
	 * Get the list of permanently dismissed suggestion IDs for the current user.
	 *
	 * @return string[]
	 */
	public function getDismissedSuggestions(): array {
		$user_id = get_current_user_id();
		if ($user_id === 0) {
			return [];
		}
		$dismissed = get_user_meta($user_id, 'saso_eventtickets_dismissed_suggestions', true);
		return is_array($dismissed) ? $dismissed : [];
	}

	/**
	 * Permanently dismiss a suggestion for the current user.
	 *
	 * @param array $data Must contain 'suggestion_id'
	 * @return array Updated dismissed list
	 */
	public function dismissSuggestion(array $data): array {
		$user_id = get_current_user_id();
		if ($user_id === 0) {
			throw new \Exception('You must be logged in to dismiss suggestions.');
		}
		$suggestion_id = sanitize_text_field($data['suggestion_id'] ?? '');
		if ($suggestion_id === '') {
			throw new \Exception('Missing suggestion_id parameter.');
		}
		$dismissed = $this->getDismissedSuggestions();
		if (!in_array($suggestion_id, $dismissed, true)) {
			$dismissed[] = $suggestion_id;
			update_user_meta($user_id, 'saso_eventtickets_dismissed_suggestions', $dismissed);
		}
		return ['dismissed' => $dismissed];
	}
}
?>