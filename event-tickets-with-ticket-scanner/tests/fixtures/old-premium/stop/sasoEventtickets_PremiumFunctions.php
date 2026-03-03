<?php
include(plugin_dir_path(__FILE__)."init_file.php");
class sasoEventtickets_PremiumFunctions {
	private $DB;
	private $PREMDB;
	public $_pluginpathbasic;
	private $_options;
	private $_prefix;
	private $_JSversion = "";

	private $uniqueIDMetaBox_Webhook;
	private $orderCheckedForSerials = false;
	private $orderContainsProductsWithPossibleSerial = false;
	private $orderCouldHaveMoreSerials = false;

	private $MAIN;
	private $TICKET_STATS;

	public function __construct($BASE, $_pluginpathbasic="", $_prefix="", $db=null) {
		global $sasoEventtickets;
		if ($sasoEventtickets != null) {
			$this->MAIN = $sasoEventtickets;
		} else {
			if (is_a($BASE, 'vollstart_Base')) {
				$this->MAIN = $BASE->MAIN; // funktioniert erst ab basic 2.2.3
			} else {
				$this->MAIN = $BASE;
			}
		}

		$this->_prefix = empty($_prefix) ? $this->MAIN->getPrefix() :  $_prefix;
		$this->_JSversion = $this->MAIN->getPluginVersion();
		$this->uniqueIDMetaBox_Webhook = $this->_prefix."_wc_order_webhook";
		$this->_pluginpathbasic = SASO_EVENTTICKETS_PLUGIN_DIR_PATH;
		$this->init();
	}

	private function init() {
		add_action($this->MAIN->_do_action_prefix.'changeOption', [$this, 'updatePremiumOption'], 10, 1);
	}

	public function filter_wcTicketTicketBanner($image_id, $product_id) {
		$code_list_id = get_post_meta($product_id, 'saso_eventtickets_list', true);
		if (!empty($code_list_id)) {
			$listObj = $this->getAdmin()->getList(['id'=>$code_list_id]);
			$list_metaObj = $this->getCore()->encodeMetaValuesAndFillObjectList($listObj['meta']);
			// check ob liste einen image hat
			if (isset($list_metaObj['pdfticket_images']) && isset($list_metaObj['pdfticket_images']['banner'])) {
				$image_id_list = intval($list_metaObj['pdfticket_images']['banner']);
				if ($image_id_list > 0) $image_id = $image_id_list;
			}
		}

		$image_id_product = intval(get_post_meta( $product_id, 'saso_eventtickets_prem_wcTicketTicketBanner', true ));
		if ($image_id_product > 0) return $image_id_product;
		return $image_id;
	}
	public function filter_wcTicketTicketLogo($image_id, $product_id) {
		$code_list_id = get_post_meta($product_id, 'saso_eventtickets_list', true);
		if (!empty($code_list_id)) {
			$listObj = $this->getAdmin()->getList(['id'=>$code_list_id]);
			$list_metaObj = $this->getCore()->encodeMetaValuesAndFillObjectList($listObj['meta']);
			// check ob liste einen image hat
			if (isset($list_metaObj['pdfticket_images']) && isset($list_metaObj['pdfticket_images']['logo'])) {
				$image_id_list = intval($list_metaObj['pdfticket_images']['logo']);
				if ($image_id_list > 0) $image_id = $image_id_list;
			}
		}

		$image_id_product = intval(get_post_meta( $product_id, 'saso_eventtickets_prem_wcTicketTicketLogo', true ));
		if ($image_id_product > 0) return $image_id_product;
		return $image_id;
	}
	public function filter_wcTicketTicketBG($image_id, $product_id) {
		$code_list_id = get_post_meta($product_id, 'saso_eventtickets_list', true);
		if (!empty($code_list_id)) {
			$listObj = $this->getAdmin()->getList(['id'=>$code_list_id]);
			$list_metaObj = $this->getCore()->encodeMetaValuesAndFillObjectList($listObj['meta']);
			// check ob liste einen image hat
			if (isset($list_metaObj['pdfticket_images']) && isset($list_metaObj['pdfticket_images']['bg'])) {
				$image_id_list = intval($list_metaObj['pdfticket_images']['bg']);
				if ($image_id_list > 0) $image_id = $image_id_list;
			}
		}

		$image_id_product = intval(get_post_meta( $product_id, 'saso_eventtickets_prem_wcTicketTicketBG', true ));
		if ($image_id_product > 0) return $image_id_product;
		return $image_id;
	}

	public function woocommerce_email_attachments($attachments, $email_id, $order) {
		return $attachments;
	}

	public function _basics_sendeDateiCSVvonDBdaten($daten, $filename, $delimiter=";") {
		header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
        header('Content-Description: File Transfer');
        header('Content-type: text/csv');
    	header('Content-Disposition: attachment; filename="'.$filename.'"');
        header('Expires: 0');
        header('Pragma: public');

		ob_end_clean();
		$out = fopen('php://output', 'w');

		if (count($daten) > 0) {
			fputcsv($out, array_keys($daten[0]), $delimiter);
			foreach($daten as $value) {
				fputcsv($out, array_values($value), $delimiter);
			}
		} else {
			fputcsv($out, array("no data"), $delimiter);
		}
		fclose($out);
	}

	public function repairTables($force=false) {
		$this->getPremDB()->installiereTabellen($force);
	}

	public function getPremDB() {
		if ($this->PREMDB != null) return $this->PREMDB;
		include plugin_dir_path(__FILE__)."db.php";
    	$this->PREMDB = new sasoEventTicketsPremiumDB($this->MAIN);
		$this->PREMDB->installiereTabellen();
    	return $this->PREMDB;
	}
	public function getDBVersion() {
		$this->getPremDB();
		return $this->PREMDB->dbversion;
	}
	public function getDB() {
		return $this->MAIN->getDB();
	}
	private function getBase() {
		return $this->MAIN->getBase();
	}
	private function getAdmin() {
		return $this->MAIN->getAdmin();
	}
	private function getCore() {
		return $this->MAIN->getCORE();
	}
	public function getTicketStats() {
		if ($this->TICKET_STATS == null) {
			include_once plugin_dir_path(__FILE__)."sasoEventTickets_Ticket_Stats.php";
			$this->TICKET_STATS = new sasoEventTickets_Ticket_Stats($this->MAIN, $this);
		}
		return $this->TICKET_STATS;
	}

	public function redeemWoocommerceTicketForCode($codeObj, $felder, $data) {
		$metaObj = $this->getCore()->encodeMetaValuesAndFillObject($codeObj['meta'], $codeObj);
		if (isset($metaObj['woocommerce']) && isset($metaObj['woocommerce']['product_id'])) {
			$this->getTicketStats()->addTicketRedeemedEntry($metaObj['woocommerce']['product_id'], $codeObj['code']);
		}
		return $felder;
	}

	private function initWCProduct() {
		$this->getTicketStats()->initWCProduct();
	}
	private function initWCOrder() {
		// zeige Btn, um ticket zu generieren
		add_action('add_meta_boxes', [$this, 'wc_order_add_meta_boxes']);
	}

	function wc_order_add_meta_boxes() {
		global $post_type;
		global $post;
		if( $post_type != 'shop_order' ) return;
	}
	function wc_order_display_side_box($post) {
		// check if code information is given
		?>
		<p>Add tickets and codes to the sold items</p>
		<button disabled data-id="<?php echo esc_attr($this->_prefix."btn_add_eventtickets_serial"); ?>" class="button button-primary">Add Ticket or Restriction Codes</button>
		<?php
	}
	private function wc_order_addJSFileAndHandlerBackend($enabled=true) {
		wp_enqueue_media(); // damit der media chooser von wordpress geladen wird
		wp_register_script(
			$this->_prefix.'WC_Order_Ajax_Backend',
			trailingslashit( plugin_dir_url( __FILE__ ) ) . 'wc_backend.js',
			array( 'jquery', 'jquery-blockui' ),
			(current_user_can("administrator") ? time() : $this->MAIN->getPluginVersion()),
			true );
		wp_localize_script(
			$this->_prefix.'WC_Order_Ajax_Backend',
			'phpObjectPrem', // name der js variable
 			[
 				'_action' => $this->_prefix.'_executeAdminSettings',
 				'ajaxurl' => admin_url( 'admin-ajax.php' ),
				'nonce' => wp_create_nonce( $this->_prefix ),
 				'order_id'=>isset($_GET['post']) ? intval($_GET['post']) : 0,
 				'btn_id_webhook'=>[$this->_prefix."btn_add_eventtickets_serial"],
				'enabled'=>$enabled
 			] // werte in der js variable
 			);
      	wp_enqueue_script($this->_prefix.'WC_Order_Ajax_Backend');
 	}

	/*
	* called by BASE now
	*/
	public function _initOptions($options=[]){
		if ( class_exists( 'WooCommerce' ) ) {
			$this->initWCOrder();
			$this->initWCProduct();
		}
		$_options = [];
		foreach($options as $option) {
			$_options[] = $option;
		}

		$options = $_options;

		if (method_exists($this->getTicketStats(), 'initOptions')) {
			$options = $this->getTicketStats()->initOptions($options);
		}

		// serial abfrage
		$serial = $this->getMySerial();
		$noption = $this->MAIN->getOptions()->getOptionsObject('serial', "Enter your premium serial to activate the premium features","If you do not have one, please contact the support@vollstart.de from the email address you used to purchase the premium plugin.","text", $serial);
		array_unshift($options, $noption);

		return $options;
	}

	public function encodeMetaValuesAndFillObject($metaObj, $codeObj=null) {
		return $metaObj;
	}
	public function isCodeExpired($codeObj) {
		$metaObj = $this->getCore()->encodeMetaValuesAndFillObject($codeObj['meta']);

		$expired = false;

		return $expired;
	}
	public function checkCodeExpired($codeObj) {
		$expired = $this->isCodeExpired($codeObj);

		if ($expired == true){
			$this->getCore()->triggerWebhooks(4, $codeObj);
		}

		return $expired;
	}

	//TODO: make it static
	private function getMySerial() {
		$serial = get_option( "saso-event-tickets-premium_serial", "" );
		if (empty($serial)) {
			$serial = $this->_getOptionValue("serial");
			if (!empty($serial)) {
				$this->changeOption(["serial"=>$serial]);
			}
		}
		return $serial;
	}
	private function _getOptionValue($name) {
		return $this->MAIN->getOptions()->getOptionValue($name);
	}
	private function _isOptionCheckboxActive($optionname) {
		return $this->MAIN->getOptions()->isOptionCheckboxActive($optionname);
	}
	public function maxValues() {
		return ['codes'=>1,'codes_total'=>1,'lists'=>1,'storeip'=>false,'allowuserreg'=>false];
	}
	public function isPremium() {
		return true;
	}

	public function executeJSON($c, $data=[]) {
		if (!is_admin()) throw new Exception("Please login");
		$ret = "";
		$justJSON = false;
		switch (trim($c)) {
			case "getIPsForCode":
				$ret = $this->getIPsForCode($data);
				break;
			case "getMetaOfCode":
				$ret = $this->getMetaOfCode($data);
				break;
			case "getOptions":
				$ret = $this->getOptions();
				break;
			case "changeOption":
				$ret = $this->changeOption($data);
				break;
			case "getIPs":
				$ret = $this->getIPs($data, $_GET);
				$justJSON = true;
				break;
			case "emptyTableIPs":
				$ret = $this->emptyTableIPs($data);
				break;
			case "deactivateCodes":
				$ret = $this->deactivateCodes($data);
				break;
			case "activateCodes":
				$ret = $this->activateCodes($data);
				break;
			case "stolenCodes":
				$ret = $this->stolenCodes($data);
				break;
			case "removeExpirationFromCode":
				$ret = $this->removeExpirationFromCode($data);
				break;
			case "setExpirationFromCode":
				$ret = $this->setExpirationFromCode($data);
				break;
			case "deleteIPListTillDate":
				$ret = $this->deleteIPListTillDate($data);
				break;
			case "requestSerialsForOrder":
				$ret = $this->requestSerialsForOrder($data);
				break;
			case "ticketStatsExecute":
				$ret = $this->getTicketStats()->executeJSON($data);
				break;
			default:
				throw new Exception("prem function '".$c."' not implemented");
		}
		if ($justJSON) return wp_send_json($ret);
		return $ret;
	}
	public function executeFrontendJSON($d, $data=[]) {
		if (!is_admin()) throw new Exception("Please login");
		$ret = "";
		$justJSON = false;
		switch (trim($d)) {
			/*
			case "registerToCode":
				$ret = $this->frontend_registerToCode($data);
				break;
			*/
			default:
				throw new Exception("function ".$d." not implemented");
		}
		if ($justJSON) return wp_send_json($ret);
		return $ret;
	}

	private function removeExpirationFromCode($data) {
		if (!isset($data['code'])) throw new Exception("#9401 code is missing");
		return $codeObj;
	}
	private function setExpirationFromCode($data) {
		if (!isset($data['code'])) throw new Exception("#9402 code is missing");
		$codeObj = $this->getCore()->retrieveCodeByCode($data['code']);
		$metaObj = $this->getCore()->encodeMetaValuesAndFillObject($codeObj['meta']);

		return $this->updateCodeMetas($data['code'], $data);
	}
	private function deactivateCodes($data) {
		if (!isset($data['ids'])) throw new Exception("#9241 ids are missing");
		if (!is_array($data['ids'])) throw new Exception("#9242 ids must be an array");
		return count($data['ids']);
	}
	private function activateCodes($data) {
		if (!isset($data['ids'])) throw new Exception("#9243 ids are missing");
		if (!is_array($data['ids'])) throw new Exception("#9244 ids must be an array");
		return count($data['ids']);
	}
	private function stolenCodes($data) {
		if (!isset($data['ids'])) throw new Exception("#9245 ids are missing");
		if (!is_array($data['ids'])) throw new Exception("#9246 ids must be an array");
		return count($data['ids']);
	}

	public function getOptions() {
		return $this->MAIN->getOptions()->getOptions();
	}
	public function getOption($key) {
		return $this->MAIN->getOptions()->getOption($key);
	}
	public function updatePremiumOption($data) {
		return $this->changeOption($data);
	}
	private function changeOption($data) {
		if ($data['key'] == "serial") {
			//TODO: speicher serial in vollstart als methode realisieren
			update_option("saso-event-tickets-premium_serial", trim($data['value']));
		}
		//$this->MAIN->getOptions()->changeOption($data);
	}
	private function getMetaOfCode($data) {
		if (!isset($data['code'])) throw new Exception("#9101 code is missing");
		$codeObj = $this->getCore()->retrieveCodeByCode($data['code']);
		$metaObj = $this->getCore()->encodeMetaValuesAndFillObject($codeObj['meta']);
		return $metaObj;
	}
	private function getIPsForCode($data) {
		if (!isset($data['code'])) throw new Exception("#9101 code is missing");
		return [];
	}

	public function addJSFrontFile() {
      	wp_enqueue_script(
            'ajax_script_'.$this->_prefix.'_premium',
            $this->getJSFrontFile()
        );
	}
	public function getJSBackendFile() {
		return plugins_url("backend.js?p=".SASO_EVENTTICKETS_PREMIUM_PLUGIN_VERSION."&v=".$this->_JSversion, __FILE__);
	}
	public function getJSFrontFile() {
		return plugins_url("validator.js?p=".SASO_EVENTTICKETS_PREMIUM_PLUGIN_VERSION."&v=".$this->_JSversion, __FILE__);
	}
	public function setFelderListEdit($felder, $data, $listObj, $metaObj) {
		return $felder;
	}
	public function setFelderCodeEdit($felder, $data, $codeObj) {
		return $felder;
	}
	public function getExportColumnFields($fields) {
		return $fields;
	}
	public function transformMetaObjectToExportColumn($row) {
		return $row;
	}
	private function _emptyIPBlocksAndTracks() {
	}
	private function addIPBlockTrackEntry($ip) {
	}
	public function checkForIPBlock($data) {
		return $data;
	}
	public function checkForIPBlockNonValidCalls($codeObj) {
	}

	public function beforeCheckCodePre($data) {
		return $data;
	}
	public function beforeCheckCode($data) {
		$data = $this->checkForIPBlock($data);
		return $data;
	}
	public function afterCheckCodePre($codeObj) {
		return $codeObj;
	}
	public function afterCheckCode($codeObj) {
		return $codeObj;
	}
	private function setExpirationDaysFromOptionValue($metaObj) {
		return $metaObj;
	}
	public function trackIPCheckCode($codeObj) {
		return $codeObj;
	}
	public function trackIPForPDFView($codeObj) {
	}
	public function trackIPForPDFOneView($order) {
	}
	public function trackIPForICSDownload($codeObj) {
	}
	public function trackIPForTicketScannerCheck($codeObj) {
	}
	public function trackIPForTicketView($codeObj) {
	}

	private function saveIPTracker($codeObj, $action="Validation") {
	}

	private function getIPs($data, $request) {
		return ["draw"=>1,
				"recordsTotal"=>0,
				"recordsFiltered"=>0,
				"data"=>[]
		];
	}

	private function _json_encode_with_error_handling($object) {
		return $this->getCore()->json_encode_with_error_handling($object);
	}

	private function emptyTableIPs($data) {
	}

	private function deleteIPListTillDate($data) {
	}

	private function requestSerialsForOrder($data) {
		return "ok";
	}

	// add woocommerce product options
	public function saso_eventtickets_wc_product_panels($product_id) {

	}
	// save woocommerce product options
	public function saso_eventtickets_wc_save_fields($id, $post) {
	}

	private function getDefaultMetaValueOfExpiration() {
		return ['date'=>'', 'days'=>0];
	}

	public function getMetaObject($metaObj) {
		return $metaObj;
	}

	public function getMetaObjectList($metaObj) {
		return $metaObj;
	}

	private function speicherMetaObjekt($codeObj, $metaObj) {
		return $codeObj;
	}

	public function removeWoocommerceRstrPurchaseInfoFromCode($codeObj, $felder, $data) {
		return $this->removeWoocommerceOrderInfoFromCode($codeObj, $felder, $data);
	}
	public function removeWoocommerceOrderInfoFromCode($codeObj, $felder, $data) {
		return $felder;
	}

	public function addCodeFromListForOrderAfter($codeObj) {
		$codeObj = $this->filter_updateExpirationInfo($codeObj);
	}

	public function filter_updateExpirationInfo($codeObj) {
		return $codeObj;
	}
	public function filter_setExpirationDateFromDays($codeObj) {
		return $codeObj;
	}

	/**
	 * aktualisiert metas die nur für premium sind
	 * @param newcode ist ein code oder evtl inkls cvv code
	 * @param daat assoc array mit meta data
	 */
	public function updateCodeMetas($newcode, $data) {
		return $codeObj;
	}

	public function getJSRedirectURL($codeObj) {
		return $url;
	}

}
?>