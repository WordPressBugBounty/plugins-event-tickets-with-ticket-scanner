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
	}

	public function initHandlers() {
		add_action('add_meta_boxes', [$this, 'wc_order_add_meta_boxes']);

		add_action($this->MAIN->_do_action_prefix."repairTables", [$this, "repairTables"], 10, 1);
		add_filter($this->MAIN->_add_filter_prefix."woocommerce_email_attachments", [$this, "woocommerce_email_attachments"], 10, 3);
		add_filter($this->MAIN->_add_filter_prefix.'getJSRedirectURL', [$this, 'getJSRedirectURL'], 1, 1);
		add_action($this->MAIN->_do_action_prefix.'changeOption', [$this, 'updatePremiumOption'], 10, 1);

		add_filter($this->MAIN->_add_filter_prefix.'wcTicketTicketBanner', [$this, 'filter_wcTicketTicketBanner'], 1, 2);
		add_filter($this->MAIN->_add_filter_prefix.'wcTicketTicketLogo', [$this, 'filter_wcTicketTicketLogo'], 1, 2);
		add_filter($this->MAIN->_add_filter_prefix.'wcTicketTicketBG', [$this, 'filter_wcTicketTicketBG'], 1, 2);

		add_action($this->MAIN->_do_action_prefix."trackIPForPDFView", [$this, "trackIPForPDFView"], 10, 1);
		add_action($this->MAIN->_do_action_prefix."trackIPForPDFOneView", [$this, "trackIPForPDFOneView"], 10, 1);
		add_action($this->MAIN->_do_action_prefix."trackIPForICSDownload", [$this, "trackIPForICSDownload"], 10, 1);
		add_action($this->MAIN->_do_action_prefix."trackIPForTicketScannerCheck", [$this, "trackIPForTicketScannerCheck"], 10, 1);
		add_action($this->MAIN->_do_action_prefix."trackIPForTicketView", [$this, "trackIPForTicketView"], 10, 1);

		add_filter($this->MAIN->_add_filter_prefix.'filter_updateExpirationInfo', [$this, 'filter_updateExpirationInfo'], 1, 1);
		add_filter($this->MAIN->_add_filter_prefix.'filter_setExpirationDateFromDays', [$this, 'filter_setExpirationDateFromDays'], 1, 1);

		add_action($this->MAIN->_do_action_prefix.'wc_product_display_side_box', [$this, 'wc_product_display_side_box'], 15, 0);
	}

	public function wc_product_display_side_box() {
		$this->getTicketStats()->wc_product_display_side_box();
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
		$ret = $attachments;
		$allowed_emails = $this->_getOptionValue("wcTicketAttachTicketToMailOf");
		/*
		if (
			$email_id == 'customer_completed_order' ||
			$email_id == 'customer_note' ||
			$email_id == 'customer_invoice' ||
			$email_id == 'customer_processing_order'
			)
			*/
		if (in_array($email_id, $allowed_emails)) {

			$wcTicketAttachTicketToMail = $this->_isOptionCheckboxActive('wcTicketAttachTicketToMail');
			if ($wcTicketAttachTicketToMail) {
				$codes = [];
				$items = $order->get_items();
				// check if order contains tickets
				foreach($items as $item_id => $item) {
					if (get_post_meta($item->get_product_id(), 'saso_eventtickets_is_ticket', true) == "yes") {
						$_codes = wc_get_order_item_meta($item_id , '_saso_eventtickets_product_code',true);
						$codes = array_merge($codes, explode(",", $_codes));
					}
				}
				// if ticket prods, then generate the PDF

				$wcTicketAttachTicketToMailMax = intval($this->_getOptionValue("wcTicketAttachTicketToMailMax"));
				if (count($codes) > 0 && count($codes) < $wcTicketAttachTicketToMailMax) {
					$sasoEventtickets_Ticket = sasoEventtickets_Ticket::Instance($_SERVER["REQUEST_URI"]);
					//$sasoEventtickets_Ticket->init();
					$sasoEventtickets_Ticket->setOrder($order);
					foreach($codes as $code) {
						try {
							$codeObj = $this->getCore()->retrieveCodeByCode($code);
						} catch (Exception $e) {
							continue;
						}
						$sasoEventtickets_Ticket->setCodeObj($codeObj);
						// attach PDF
						$ret[] = $sasoEventtickets_Ticket->outputPDF("F");
					}
				}
			}

			if (version_compare( $this->MAIN->getPluginVersion(), '1.4.4', '>' )) {
				$wcTicketAttachTicketToMailAsOnePDF = $this->_isOptionCheckboxActive('wcTicketAttachTicketToMailAsOnePDF');
				if ($wcTicketAttachTicketToMailAsOnePDF) {
					if (count($ret) > 0) { // files schon erzeugt
						if (count($ret) > 1) { // nur wenn mehr als ein Ticket angehängt wurde
							$filename = "tickets_".$order->get_id().".pdf";
							$ret[] = $this->MAIN->getCore()->mergePDFs($ret, $filename, "F", false);
						}
					} else {
						$ret[] = $this->MAIN->getTicketHandler()->outputPDFTicketsForOrder($order, "F");
					}
				}
			}

		}
		return $ret;
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

	/**
	* exposed to basic
	*/
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

	/**
	* exposed to basic
	*/
	public function getTicketStats() {
		if ($this->TICKET_STATS == null) {
			include_once plugin_dir_path(__FILE__)."sasoEventTickets_Ticket_Stats.php";
			$this->TICKET_STATS = new sasoEventTickets_Ticket_Stats($this->MAIN, $this);
		}
		return $this->TICKET_STATS;
	}

	private function logError(Exception $e, $addition_msg="") {
		$trace = debug_backtrace();
		$caller = "";
		if (count($trace) > 0) {
			$caller = $trace[1]["class"]."->".$trace[1]["function"];
			$addition_msg .= "<br>Line: ".$trace[1]["line"];
		}
		if (method_exists($this->getAdmin(), 'logErrorToDB')) {
			$this->getAdmin()->logErrorToDB($e, $caller, $addition_msg);
		} else {
			echo $e->getMessage();
			echo "<br>";
			echo $caller;
			echo "<br>";
			echo $addition_msg;
			print_r($_REQUEST);
			if (count($trace) > 0) {
				print_r($trace[1]);
				print_r($trace[2]);
			}
		}
	}

	/**
	* exposed to basic
	*/
	public function redeemWoocommerceTicketForCode($codeObj, $felder, $data) {
		try {
			$metaObj = $this->getCore()->encodeMetaValuesAndFillObject($codeObj['meta'], $codeObj);
			if (isset($metaObj['woocommerce']) && isset($metaObj['woocommerce']['product_id'])) {
				$this->getTicketStats()->addTicketRedeemedEntry($metaObj['woocommerce']['product_id'], $codeObj['code']);
			}
		} catch (Exception $e) {
			$this->logError($e);
		}
		return $felder;
	}

	function wc_order_add_meta_boxes() {
		global $post_type;
		global $post;

		if( $post_type != 'shop_order' ) return;

		if (!$this->orderCheckedForSerials) {
			$this->orderCheckedForSerials = true;
			$order = new WC_Order( $post->ID );
			if (!$order) return "";
			foreach ( $order->get_items() as $item_id => $item ) {
				if( $item->get_product_id() ){
					// check ob dieses Item ein Product ist, das eine serial haben darf
					$code_list_id = get_post_meta($item->get_product_id(), 'saso_eventtickets_list', true);
					if (!empty($code_list_id)) {
						$this->orderContainsProductsWithPossibleSerial = true;
						// check ob serial schon gesetzt ist
						$serial_code = $item->get_meta('_saso_eventtickets_product_code');
						if (empty($serial_code)) {
							$this->orderCouldHaveMoreSerials = true; // mind 1 serial reicht, um den Button nicht zu zeigen
							break;
						}
					}
				}
			}
		}

		//if ($this->orderCouldHaveMoreSerials) {
			$this->wc_order_addJSFileAndHandlerBackend($this->orderCouldHaveMoreSerials);
			add_meta_box(
				$this->uniqueIDMetaBox_Webhook, // Unique ID
				'Event Tickets Premium',  // Box title
				[$this, 'wc_order_display_side_box'],  // Content callback, must be of type callable
				'shop_order',
				'side',
				'high'
			);
		//}
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
			(current_user_can("administrator") ? SASO_EVENTTICKETS::time() : $this->MAIN->getPluginVersion()),
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

	/**
	* exposed to basic
	*/
	public function _initOptions($options=[]){
		try {
			$_options = [];
			foreach($options as $option) {
				// davor
				if ($option['key'] == 'h8') {
					$_options[] = $this->MAIN->getOptions()->getOptionsObject('h3', "Track IP <span style='color:green;'><b>(Premium Feature)</b></span>","","heading");
					$_options[] = $this->MAIN->getOptions()->getOptionsObject('trackIPCodeChecker', "Track IP of user using the ticket number validation", "If active, each code check will be tracked. IP address, time and code.");
					$_options[] = $this->MAIN->getOptions()->getOptionsObject('trackIPForPDFView', "Track IP of user downloading the ticket PDF", "");
					$_options[] = $this->MAIN->getOptions()->getOptionsObject('trackIPForPDFOneView', "Track IP of user downloading the all-in-one ticket PDF", "");
					$_options[] = $this->MAIN->getOptions()->getOptionsObject('trackIPForICSDownload', "Track IP of user downloading the ICS file", "");
					$_options[] = $this->MAIN->getOptions()->getOptionsObject('trackIPForTicketScannerCheck', "Track IP of user using the ticket scanner to retrieve a ticket", "");
					$_options[] = $this->MAIN->getOptions()->getOptionsObject('trackIPForTicketView', "Track IP of user viewing the ticket detail page", "");
					//not anymore implemented: $_options[] = $this->MAIN->getOptions()->getOptionsObject('trackIPCodeWPuserid', "Capture wordpress userid.","If active and the user is logged in, then the userid will be stored to the IP.");
				}

				if ($option['key'] == 'h8') {
					$_options[] = $this->MAIN->getOptions()->getOptionsObject('h7', "Protection with IP Blocker  <span style='color:green;'><b>(Premium Feature)</b></span>","","heading");
					$_options[] = $this->MAIN->getOptions()->getOptionsObject('activateUserIPBlock', "Activate a time delay for code validation requests with the same IP.","If active, the IP address of the user will be tracked. If the a request with the same IP within the defined time is made, the execution is delayed. Fighting back against brute force attacks. Basically it says: 'Block IP after X requests with bad results within X minutes for X minutes'");
					$_options[] = $this->MAIN->getOptions()->getOptionsObject('activateUserIPBlockOnlyNonValidCalls', "Do the checks only for non-valid checks.","If active, the IP address of the user will be only tracked, if the check result is not 'successfull valid'.");
					$_options[] = $this->MAIN->getOptions()->getOptionsObject('activateUserIPBlockAllowedRequests', "How many tracked IP requests of the same IP are allowed?","After reached the amount of requests within the specified time, the IP block is activated","number", 1, ['min'=>0]);
					$_options[] = $this->MAIN->getOptions()->getOptionsObject('activateUserIPBlockAllowedRequestsWithinTime', "After how many minutes should the tracked IPs be deleted?","The tracked IPs are used to calculate the amount for the IP block. After this amount of minutes, the IP entries will be removed from the database.","number", 15, ['min'=>1]);
					$_options[] = $this->MAIN->getOptions()->getOptionsObject('activateUserIPBlockForTime', "Amount of minutes to block  new requests of the same IP?","After the block is executed, this is the time before a new request will be accepted. After this amount of time the IP block will be removed from the database.","number", 5, ['min'=>1]);
					$_options[] = $this->MAIN->getOptions()->getOptionsObject('activateUserIPBlockMessage', "Message if the IP block is activated?","","text", "Sorry, the server is busy. Please try later again.");
				}

				if ($option['key'] == 'h12b2') {
					if (version_compare( SASO_EVENTTICKETS_PLUGIN_VERSION, '1.0.5', '>' )) {
						$_options[] = $this->MAIN->getOptions()->getOptionsObject('wcTicketAttachTicketToMailMax', "How many PDF tickets should be attached to the purchase email? <span style='color:green;'><b>(Premium Feature)</b></span>","Be careful. Many mail service provider limiting the filesize of the attachements to 5MB. If your PDF contains images, it can quickly become big.", "number", 21, ['min'=>0]);
						$_options[] = $this->MAIN->getOptions()->getOptionsObject('wcTicketAttachTicketToMail', "Attach PDF tickets to the purchase email. <span style='color:green;'><b>(Premium Feature)</b></span>","If active and the customer bought less than the amount of option wcTicketAttachTicketToMailMax for tickets, then the PDF ticket(s) will be attached to the purchase emails. For each ticket number you will have one PDF in the email to your customer. So for 5 codes, there will be 5 PDFs attached.");
						$_options[] = $this->MAIN->getOptions()->getOptionsObject('wcTicketAttachTicketToMailAsOnePDF', "Attach all PDF tickets as only one PDF to the purchase email. <span style='color:green;'><b>(Premium Feature)</b></span>","If active and the customer bought less than the amount of option wcTicketAttachTicketToMailMax for tickets, then the PDF ticket(s) will be merged into one PDF and attached to the purchase emails. So for 5 codes, there will be 1 PDF with at least 5 pages attached.");
					}
					if (version_compare( SASO_EVENTTICKETS_PLUGIN_VERSION, '1.2.7', '>' )) {
						$additional = [
							"multiple"=>1,
							"values"=>[
								["label"=>"New Order", "value"=>"new_order"],
								["label"=>"On hold to customer", "value"=>"customer_on_hold_order"],
								["label"=>"Processing order to customer", "value"=>"customer_processing_order"],
								["label"=>"Order completed to customer", "value"=>"customer_completed_order"],
								["label"=>"Order refunded to customer", "value"=>"customer_refunded_order"],
								["label"=>"Order partial refunded to customer", "value"=>"customer_partially_refunded_order"],
								["label"=>"Order cancelled", "value"=>"cancelled_order"],
								["label"=>"Order failed", "value"=>"failed_order"],
								["label"=>"Reset password to customer", "value"=>"customer_reset_password"],
								["label"=>"Invoice to customer", "value"=>"customer_invoice"],
								["label"=>"New account to customer", "value"=>"customer_new_account"],
								["label"=>"Note to customer", "value"=>"customer_note"]
							]
						];
						$def = ["customer_completed_order","customer_invoice","customer_processing_order"];
						$_options[] = $this->MAIN->getOptions()->getOptionsObject('wcTicketAttachTicketToMailOf', "Attach PDF tickets to these emails. <span style='color:green;'><b>(Premium Feature)</b></span>","Only the selected mails will have PDF ticket(s).", "dropdown", $def, $additional);
					}
				}

				$_options[] = $option;

				// danach
				if ($option['key'] == 'webhookURLaddwcticketinforemoved') {
					$_options[] = $this->MAIN->getOptions()->getOptionsObject('webhookURLexpired', "URL to your service if the checked ticket number is valid but <b>expired</b>.  <span style='color:green;'><b>(Premium Feature)</b></span>","Only triggered, if not empty.", "text", "");
					$_options[] = $this->MAIN->getOptions()->getOptionsObject('webhookURLipblocking', "URL to your service if user is <b>blocked by the ip block for the first time</b>.  <span style='color:green;'><b>(Premium Feature)</b></span>","Only triggered, if not empty.", "text", "");
					$_options[] = $this->MAIN->getOptions()->getOptionsObject('webhookURLipblocked', "URL to your service for each request the user is <b>still blocked by the ip block</b>.  <span style='color:green;'><b>(Premium Feature)</b></span>","Only triggered, if not empty.", "text", "");
				}

			}

			$_options[] = $this->MAIN->getOptions()->getOptionsObject('h13', "Expiration and Validity <span style='color:green;'><b>(Premium Feature)</b></span>","Your codes can expire. The expiration counter will be triggered after the first successful validation. Beside this options, you can set the expiration date directly on your code or code list.","heading");
			$_options[] = $this->MAIN->getOptions()->getOptionsObject('expireActivateForAllCodesWithoutExpDate', "Activate global expiration feature for all codes without an explicit expiration date","If active, the first successfully validation of a code will be used to calculate the expiration date. If not activated, you can still set an expiration date on codes or code lists."); // activate expiration check for no expirate codes
			$_options[] = $this->MAIN->getOptions()->getOptionsObject('expireDaysForNoDate', "How many days after successful validation of a code should it expire?","If the number is 0 days, then it will not be set to expire.","number", 0, ['min'=>0]); // expired after X days
			$_options[] = $this->MAIN->getOptions()->getOptionsObject('expireUpdateOldCodes', "Set expiration date for old restrictions codes too","If active, this will update the expiration date on codes, that have been already successfully validated.");
			$_options[] = $this->MAIN->getOptions()->getOptionsObject('expireWCSetDateWithSale', "Set expiration date for purchased codes immediately","If active, then the expiration date will be evaluated using an assigned expiration days value.<br>(order: first checks for the days of the code, then for days of the code list, then for global days - if global is activated)");
			$_options[] = $this->MAIN->getOptions()->getOptionsObject('expireWCSetDateWithSaleAndUseEndTime', "Use end time set on product to determine the expiration date","If active, then the expiration date will be evaluated using an assigned expiration days value and the end time set on the product.");

			$_options[] = $this->MAIN->getOptions()->getOptionsObject('h16', "Branding <span style='color:green;'><b>(Premium Feature)</b></span>","","heading");
			$_options[] = $this->MAIN->getOptions()->getOptionsObject('brandingHidePluginBannerText', "Do not display the plugin banner text on the PDF ticket.","If active, my banner for the plugin will not be added to the PDF ticket. This quality sign will maybe reduce the free advertisment for my plugin and limit the amount of support I can afford to give :( This is not my fulltime job.");


			$options = $_options;

			if (method_exists($this->getTicketStats(), 'initOptions')) {
				$options = $this->getTicketStats()->initOptions($options);
			}

			// serial abfrage
			$serial = $this->getMySerial();
			$noption = $this->MAIN->getOptions()->getOptionsObject('serial', "Enter your premium serial to activate the premium features","If you do not have one, please contact the support@vollstart.de from the email address you used to purchase the premium plugin.","text", $serial);
			array_unshift($options, $noption);

		} catch (Exception $e) {
			$this->logError($e);
		}
		return $options;
	}

	/**
	* exposed to basic
	*/
	public function encodeMetaValuesAndFillObject($metaObj, $codeObj=null) {
		try {
			if ($metaObj['expiration']['days'] == 0 || empty($metaObj['expiration']['date'])) {
				if ( $metaObj['confirmedCount'] > 0 && $this->_isOptionCheckboxActive('expireUpdateOldCodes') ) {
					if ( isset($metaObj['validation']) && !empty($metaObj['validation']['first_success']) ) {
						$metaObj = $this->setExpirationDaysFromOptionValue($metaObj); // noch nicht in db gespeichert
					}
				}
			}
		} catch (Exception $e) {
			$this->logError($e);
		}

		return $metaObj;
	}

	public function isCodeExpired($codeObj) {
		$metaObj = $this->getCore()->encodeMetaValuesAndFillObject($codeObj['meta']);

		$expired = false;
		// check ob expiration datum gesetzt ist -> vergleichen
		if (isset($metaObj['expiration']) && !empty($metaObj['expiration']['date'])) {
			$d = strtotime($metaObj['expiration']['date']);
			if ($d > 0 && $d < SASO_EVENTTICKETS::time()) $expired = true;
		}

		// check ob expiration days gesetzt sind -> vergleiche mit dem first_success
		if ($expired == false && !empty($metaObj['validation']['first_success'])) {
			if (isset($metaObj['expiration']) && $metaObj['expiration']['days'] > 0) {
				$d = strtotime($metaObj['validation']['first_success']." + ".$metaObj['expiration']['days']);
				if ($d > 0 && $d < SASO_EVENTTICKETS::time()) $expired = true;
			}
		}

		if ($expired == false && $codeObj['list_id'] > 0 && !empty($metaObj['validation']['first_success'])) {
			$listObj = $this->getCore()->getListById($codeObj['list_id']);
			$metaObjList = $this->getCore()->encodeMetaValuesAndFillObjectList($listObj['meta']);
			if ($metaObjList['expiration']['days'] > 0) {
				$d = strtotime($metaObj['validation']['first_success']." + ".$metaObjList['expiration']['days']);
				if ($d > 0 && $d < SASO_EVENTTICKETS::time()) $expired = true;
			}
		}

		// ist code ein ticket ? - wird auch auf dem ticket_scanner.js gemacht. und im rest_service. hier evtl nicht mehr nötig
		if ($expired == false && $metaObj['wc_ticket']['is_ticket'] == 1 && $metaObj['woocommerce']['product_id'] != 0) {
			$product_id = $metaObj['woocommerce']['product_id'];
			$ticket_end_date = trim(get_post_meta( $product_id, 'saso_eventtickets_ticket_end_date', true ));
			if (!empty($ticket_end_date)) {
				$ticket_end_time = trim(get_post_meta( $product_id, 'saso_eventtickets_ticket_end_time', true ));
				if (empty($ticket_end_time)) {
					$ticket_end_time = "23:59:59";
				}
				//$timezone_id = wp_timezone_string();
				$d = strtotime($ticket_end_date." ".$ticket_end_time);
				if ($d > 0 && $d < SASO_EVENTTICKETS::time()) $expired = true;
			}
		}

		// global expiration is set during the Metaobject creation
		// so no need to check it here again

		return $expired;
	}

	/**
	* exposed to basic
	*/
	public function checkCodeExpired($codeObj) {
		$expired = false;
		try {
			$expired = $this->isCodeExpired($codeObj);
			if ($expired == true){
				$this->getCore()->triggerWebhooks(4, $codeObj);
			}
		} catch (Exception $e) {
			$this->logError($e);
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

	/**
	* exposed to basic
	*/
	public function maxValues() {
		return ['codes'=>100000,'codes_total'=>0,'lists'=>0,'storeip'=>false,'allowuserreg'=>false];
	}

	public function isPremium() {
		return true;
	}

	/**
	 * exposed to basic
	 */
	public function executeJSON($c, $data=[]) {
		if (!is_admin()) throw new Exception("Please login");
		$ret = "";
		try {
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
					$this->changeOption($data);
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
				case "emptyIPBlocksAndTracks":
					$ret = $this->emptyIPBlocksAndTracks($data);
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
		} catch (Exception $e) {
			$this->logError($e);
		}
		if ($justJSON) return wp_send_json($ret);
		return $ret;
	}

	/**
	* exposed to basic
	*/
	public function executeFrontendJSON($d, $data=[]) {
		if (!is_admin()) throw new Exception("Please login");
		$ret = "";
		try {
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
		} catch (Exception $e) {
			$this->logError($e);
		}

		return $ret;
	}

	private function removeExpirationFromCode($data) {
		if (!isset($data['code'])) throw new Exception("#9401 code is missing");
		$codeObj = $this->getCore()->retrieveCodeByCode($data['code']);
		$metaObj = $this->getCore()->encodeMetaValuesAndFillObject($codeObj['meta']);

		// remove expiration
		$metaObj['expiration'] = $this->getDefaultMetaValueOfExpiration();

		// update meta
		return $this->speicherMetaObjekt($codeObj, $metaObj);
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
		foreach($data['ids'] as $v) {
			$this->getDB()->update("codes", ["aktiv"=>0], ['id'=>$v]);
		}
		return count($data['ids']);
	}
	private function activateCodes($data) {
		if (!isset($data['ids'])) throw new Exception("#9243 ids are missing");
		if (!is_array($data['ids'])) throw new Exception("#9244 ids must be an array");
		foreach($data['ids'] as $v) {
			$this->getDB()->update("codes", ["aktiv"=>1], ['id'=>$v]);
		}
		return count($data['ids']);
	}
	private function stolenCodes($data) {
		if (!isset($data['ids'])) throw new Exception("#9245 ids are missing");
		if (!is_array($data['ids'])) throw new Exception("#9246 ids must be an array");
		foreach($data['ids'] as $v) {
			$this->getDB()->update("codes", ["aktiv"=>2], ['id'=>$v]);
		}
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
	}
	private function getMetaOfCode($data) {
		if (!isset($data['code'])) throw new Exception("#9101 code is missing");
		$codeObj = $this->getCore()->retrieveCodeByCode($data['code']);
		$metaObj = $this->getCore()->encodeMetaValuesAndFillObject($codeObj['meta']);
		return $metaObj;
	}
	private function getIPsForCode($data) {
		if (!isset($data['code'])) throw new Exception("#9101 code is missing");
		$db = $this->getDB();
		$sql = "select * from ".$db->getTabelle("ips")." where
				code = '".$db->reinigen_in($data['code'])."'
				order by time desc";
		return $db->_db_datenholen($sql);
	}

	/**
	 * exposed to basic
	 */
	public function addJSFrontFile() {
		try {
			wp_enqueue_script(
				'ajax_script_'.$this->_prefix.'_premium',
				$this->getJSFrontFile()
			);
		} catch (Exception $e) {
			$this->logError($e);
		}
	}

	/**
	 * exposed to basic
	 */
	public function getJSBackendFile() {
		$url = "";
		try {
			$url = plugins_url("backend.js?p=".SASO_EVENTTICKETS_PREMIUM_PLUGIN_VERSION."&v=".$this->_JSversion, __FILE__);
		} catch (Exception $e) {
			$this->logError($e);
		}
		return $url;
	}

	/**
	 * exposed to basic
	 */
	public function getJSFrontFile() {
		$url = "";
		try {
			$url = plugins_url("validator.js?p=".SASO_EVENTTICKETS_PREMIUM_PLUGIN_VERSION."&v=".$this->_JSversion, __FILE__);
		} catch (Exception $e) {
			$this->logError($e);
		}
		return $url;
	}

	/**
	 * exposed to basic
	 */
	public function setFelderListEdit($felder, $data, $listObj, $metaObj) {
		// erhalte erstmal alte values -> kein check ob die option noch besteht odr so
		try {
			$metaValueChanged = false;
			if (isset($data['meta'])) {
				if (isset($data['meta']['oneTimeUseOfRegisterAmount'])) {
					$metaObj['oneTimeUseOfRegisterAmount'] = intval($data['meta']['oneTimeUseOfRegisterAmount']);
					$metaValueChanged = true;
				}
				if (isset($data['meta']['expiration_days'])) {
					$metaObj['expiration']['days'] = intval($data['meta']['expiration_days']);
					$metaValueChanged = true;
				}
				if (isset($data['meta']['pdfticket_images'])) {
					if (isset($data['meta']['pdfticket_images']['logo'])) {
						$metaObj['pdfticket_images']['logo'] = trim($data['meta']['pdfticket_images']['logo']);
						$metaValueChanged = true;
					}
					if (isset($data['meta']['pdfticket_images']['banner'])) {
						$metaObj['pdfticket_images']['banner'] = trim($data['meta']['pdfticket_images']['banner']);
						$metaValueChanged = true;
					}
					if (isset($data['meta']['pdfticket_images']['bg'])) {
						$metaObj['pdfticket_images']['bg'] = trim($data['meta']['pdfticket_images']['bg']);
						$metaValueChanged = true;
					}
				}
			}
			if ($metaValueChanged) {
				$felder['meta'] = $this->_json_encode_with_error_handling($metaObj);
			}
		} catch (Exception $e) {
			$this->logError($e);
		}
		return $felder;
	}

	/**
	* exposed to basic
	*/
	public function setFelderCodeEdit($felder, $data, $codeObj) {
		if (isset($data['aktiv'])) $felder['aktiv'] = intval($data['aktiv']);
		return $felder;
	}

	/**
	* exposed to basic
	*/
	public function getExportColumnFields($fields) {
		if (is_array($fields)) {
			$fields[] = 'meta_expiration';
			$fields[] = 'meta_expiration_date';
			$fields[] = 'meta_expiration_days';
		} else {
			$this->logError(new Exception("fields is not an array"));
		}
		return $fields;
	}

	/**
	* exposed to basic
	*/
	public function transformMetaObjectToExportColumn($row) {
		try {
			if (!empty($row['meta'])) {
				$metaObj = $this->getCore()->encodeMetaValuesAndFillObject($row['meta']);
				if (isset($metaObj['expiration'])) {
					if (!empty($metaObj['expiration']['date'])) $row['meta_expiration_date'] = $metaObj['expiration']['date'];
					if (!empty($metaObj['expiration']['days'])) $row['meta_expiration_days'] = $metaObj['expiration']['days'];
				}
			}
		} catch (Exception $e) {
			$this->logError($e);
		}
		return $row;
	}

	private function emptyIPBlocksAndTracks($data) {
		$this->_emptyIPBlocksAndTracks();
		return "OK";
	}
	private function _emptyIPBlocksAndTracks() {
		$db = $this->getPremDB();
		$sql = "delete from ".$db->getTabelle("ipblocks");
		$db->_db_query($sql);
		$sql = "delete from ".$db->getTabelle("ipblocktrack");
		$db->_db_query($sql);
	}
	private function addIPBlockTrackEntry($ip) {
		$db = $this->getPremDB();
		$felder = ["time"=>SASO_EVENTTICKETS::date("Y-m-d H:i:s"),"ip"=>$ip];
		$db->insert("ipblocktrack", $felder);
	}
	public function checkForIPBlock($data) {
		//$this->_emptyIPBlocksAndTracks();
		if (!$this->_isOptionCheckboxActive('activateUserIPBlock')) return $data;

		$db = $this->getPremDB();
		$ip = $this->getCORE()->getRealIpAddr();

		// lösche abgelaufene blocks
		$sql = "delete from ".$db->getTabelle("ipblocks")." where ende < '".SASO_EVENTTICKETS::date("Y-m-d H:i:s")."'";
		$db->_db_query($sql);
		// check db ob block besteht
		$sql = "select * from ".$db->getTabelle("ipblocks")." where ip = '".$db->reinigen_in($ip)."' limit 1";
		$ret = $db->_db_datenholen($sql);
		if (count($ret) > 0) {
			$v = trim($this->_getOptionValue('activateUserIPBlockMessage'));
			try {
				$codeObj = $this->getCore()->retrieveCodeByCode($data['code'], false);
			} catch(Exception $e) {
				// code do not exists
				$codeObj = ['code' =>$data['code']];
			}
			$this->getCore()->triggerWebhooks(9, $codeObj);
			throw new Exception($v);
		}

		// lösche IPs nach der warte zeit
		$minutes = intval($this->_getOptionValue('activateUserIPBlockAllowedRequestsWithinTime'));
		$sql = "delete from ".$db->getTabelle("ipblocktrack")." where time < '".SASO_EVENTTICKETS::date("Y-m-d H:i:s", SASO_EVENTTICKETS::time() - (60*$minutes))."'";
		$db->_db_query($sql);
		// speicher, ip in DB für tracking - jetzt auch für jeden call
		if (!$this->_isOptionCheckboxActive('activateUserIPBlockOnlyNonValidCalls')) { // wird also später im after code check gemacht
			$this->addIPBlockTrackEntry($ip);
		}
		// hole anzahl einträge für ip
		$anzahl = $db->_db_getRecordCountOfTable('ipblocktrack', "ip='".$db->reinigen_in($ip)."'");
		// wenn block aktiviert werden muss, dann antworten und exception werfen, um die nächste verarbeitung zu unterbinden
		$anzahlAllowed = intval($this->_getOptionValue('activateUserIPBlockAllowedRequests'));
		if ($anzahl > $anzahlAllowed) {
			$dauer = intval($this->_getOptionValue('activateUserIPBlockForTime'));
			$db->insert("ipblocks", ['time'=>SASO_EVENTTICKETS::date("Y-m-d H:i:s"), "ip"=>$ip, "ende"=>SASO_EVENTTICKETS::date("Y-m-d H:i:s", SASO_EVENTTICKETS::time() + (60*$dauer))]);
			$v = trim($this->_getOptionValue('activateUserIPBlockMessage'));
			try {
				$codeObj = $this->getCore()->retrieveCodeByCode($data['code'], false);
			} catch (Exception $e) {
				// code do not exists
				$codeObj = ['code' =>$data['code']];
			}
			$this->getCore()->triggerWebhooks(8, $codeObj);
			throw new Exception($v);
		}

		return $data;
	}
	public function checkForIPBlockNonValidCalls($codeObj) {
		if ($this->_isOptionCheckboxActive('activateUserIPBlock')) {
			$doTrack = true;
			if ($this->_isOptionCheckboxActive('activateUserIPBlockOnlyNonValidCalls')) {
				if ($codeObj['_valid'] != 1 && $codeObj['_valid'] != 6) {
					$doTrack = false;
				}
			}
			if ($doTrack) {
				$ip = $this->getCore()->getRealIpAddr();
				$this->addIPBlockTrackEntry($ip);
			}
		}
	}

	/**
	* exposed to basic
	*/
	public function beforeCheckCodePre($data) {
		return $data;
	}

	/**
	* exposed to basic
	*/
	public function beforeCheckCode($data) {
		try {
			$data = $this->checkForIPBlock($data);
		} catch (Exception $e) {
			$this->logError($e);
		}
		return $data;
	}

	/**
	* exposed to basic
	*/
	public function afterCheckCodePre($codeObj) {
		// check ob nun das expiration date gesetzt werden soll
		// kann auch aufgerufen werden, wenn der Code not found war.
		try {
			if (isset($codeObj['id'])) {
				if (isset($codeObj['meta'])) {
					$metaObj = $this->getCore()->encodeMetaValuesAndFillObject($codeObj['meta']);

					if ($metaObj['expiration']['date'] == "" && $metaObj['expiration']['days'] == 0) {
						if ($metaObj['validation']['first_success'] != "") {
							if( $this->_isOptionCheckboxActive('expireActivateForAllCodesWithoutExpDate') ) {
								// check ob das das erste success ist
								if ($metaObj['confirmedCount'] == 1 && $codeObj['_valid'] == 1) {
									// nimm global tage und setze mittels $metaObj['validation']['first_success']
									$metaObj = $this->setExpirationDaysFromOptionValue($metaObj);
									if ($metaObj['expiration']['days'] > 0) {
										$codeObj = $this->speicherMetaObjekt($codeObj, $metaObj);
									}
								}
							}
						}
					}

				}
			} else {
				// not found
			}
			$this->checkForIPBlockNonValidCalls($codeObj);
			$codeObj = $this->trackIPCheckCode($codeObj);
		} catch (Exception $e) {
			$this->logError($e);
		}
		return $codeObj;
	}

	/**
	* exposed to basic
	*/
	public function afterCheckCode($codeObj) {
		return $codeObj;
	}

	private function setExpirationDaysFromOptionValue($metaObj) {
		$days = intval($this->_getOptionValue('expireDaysForNoDate'));
		$metaObj['expiration']['days'] = $days;
		return $metaObj;
	}
	public function trackIPCheckCode($codeObj) {
		if ($this->_isOptionCheckboxActive('trackIPCodeChecker')) {
			$this->saveIPTracker($codeObj, "Validation");
		}
		return $codeObj;
	}
	public function trackIPForPDFView($codeObj) {
		if ($this->_isOptionCheckboxActive('trackIPForPDFView')) {
			$this->saveIPTracker($codeObj, "PDF download");
		}
	}
	public function trackIPForPDFOneView($order) {
		if ($this->_isOptionCheckboxActive('trackIPForPDFOneView')) {
			$data = ['code'=>$order->get_id()];
			$this->saveIPTracker($data, "PDF (all-in-one) download");
		}
	}
	public function trackIPForICSDownload($codeObj) {
		if ($this->_isOptionCheckboxActive('trackIPForICSDownload')) {
			$this->saveIPTracker($codeObj, "ICS download");
		}
	}
	public function trackIPForTicketScannerCheck($codeObj) {
		if ($this->_isOptionCheckboxActive('trackIPForTicketScannerCheck')) {
			$this->saveIPTracker($codeObj, "Ticket Scanner ticket scanned");
		}
	}
	public function trackIPForTicketView($codeObj) {
		if ($this->_isOptionCheckboxActive('trackIPForTicketView')) {
			$this->saveIPTracker($codeObj, "Ticket Detail viewed");
		}
	}

	private function saveIPTracker($codeObj, $action="Validation") {
		if (!isset($codeObj['_valid'])) $codeObj['_valid'] = -1;
		if (!isset($codeObj['_data_code'])) $codeObj['_data_code'] = $codeObj['code'];
		$ip = $this->getCore()->getRealIpAddr();
		$felder = ["time"=>SASO_EVENTTICKETS::date("Y-m-d H:i:s"),
			"code"=>$codeObj['_data_code'],
			"valid"=>intval($codeObj['_valid']),
			"ip"=>$ip,
			"action"=>trim($action)
		];
		// eigentlich sollte diese Tabelle über prem gesteuert werden :( - so muss man neue felder per basic plugin einführen.
		$this->getDB()->insert("ips", $felder);
	}

	private function getIPs($data, $request) {
		$sql = "select * from ".$this->getDB()->getTabelle("ips");

		// für datatables
		$length = 0;
		if (isset($request['length'])) $length = intval($request['length']);
		$draw = 1; // sequenz zähler, also fortlaufend für jede aktion auf der JS datentabelle
		if (isset($request['draw'])) $draw = intval($request['draw']);
		$start = 0;
		if (isset($request['start'])) $start = intval($request['start']);
		$order_column = "time";
		if (isset($request['order'])) $order_column = array('ip','code','time','valid','action')[intval($request['order'][0]['column'])];
		$order_dir = "asc";
		if (isset($request['order']) && $request['order'][0]['dir'] == 'desc') $order_dir = "desc";
		$search = "";
		if (isset($request['search'])) $search = $this->getDB()->reinigen_in($request['search']['value']);

		$where = "";
		if ($search != "") {
			$sql .= " where ";
			$sql .= " code like '%".$this->getCore()->clearCode($search)."%' or ip like '%".$search."%' or time like '%".$search."%' ";
			$where .= " code like '%".$this->getCore()->clearCode($search)."%' or ip like '%".$search."%' or time like '%".$search."%' ";
		}
		if ($order_column != "") $sql .= " order by ".$order_column." ".$order_dir;
		if ($length > 0)
			$sql .= " limit ".$start.", ".$length;

		$daten = $this->getDB()->_db_datenholen($sql);
		$recordsTotal = $this->getDB()->_db_getRecordCountOfTable('ips');
		$recordsTotalFilter = ($search == "") ? $recordsTotal : $this->getDB()->_db_getRecordCountOfTable('ips', $where);

		return ["draw"=>$draw,
				"recordsTotal"=>intval($recordsTotal),
				"recordsFiltered"=>intval($recordsTotalFilter),
				"data"=>$daten];
	}

	private function _json_encode_with_error_handling($object) {
		return $this->getCore()->json_encode_with_error_handling($object);
	}

	private function emptyTableIPs($data) {
		$sql = "delete from ".$this->getDB()->getTabelle("ips");
		return $this->getDB()->_db_query($sql);
	}

	private function deleteIPListTillDate($data) {
		if (!isset($data['delete_till_date'])) throw new Exception("#9301 correct untill deletion date missing");
		$till_date = SASO_EVENTTICKETS::date("Y-m-d 23:59:59", strtotime(sanitize_text_field($data['delete_till_date'])));
		$db = $this->getDB();
		$sql = "delete from ".$db->getTabelle("ips")." where time < '".$db->reinigen_in($till_date)."'";
		return $db->_db_query($sql);
	}

	private function requestSerialsForOrder($data) {
		if (!isset($data['order_id'])) throw new Exception("#9302 order id missing");

		$this->MAIN->getWC()->add_serialcode_to_order(intval($data['order_id']));
		return "ok";
	}

	/**
	* exposed to basic
	*/
	public function saso_eventtickets_wc_product_panels($product_id) { // add woocommerce product options
		// zeige expiration days field
		try {
			echo '<div class="options_group">';
			woocommerce_wp_text_input( array(
				'id'                => 'saso_eventtickets_expiration_days',
				'value'             => intval(get_post_meta( $product_id, 'saso_eventtickets_expiration_days', true )),
				'label'             => 'Days till the ticket expires',
				'type'				=> 'number',
				'custom_attributes'	=> ['step'=>'1', 'min'=>'0'],
				'description'       => 'If set to zero, then no expiration will be activated. If the ticket or the ticket list contains an expiration date or days, then this will be used. If you activate the option "Set expiration date for purchased tickets immediately", then expiration date will be set using the expiration days. Otherwise if you redeem the ticket the first time, these days are added as a expiration date.',
				'desc_tip'    		=> true
			) );
			echo '</div>';

			echo '<div class="options_group">';
			$readonly = [];
			if(!$this->_isOptionCheckboxActive("userJSRedirectActiv")) {
				$readonly = ['readonly'=>'readonly'];
				echo '<p style="color:red;">You need to activate first the "user redirect" option to use this feature.</p>';
			}
			woocommerce_wp_checkbox( array(
				'id'          => 'saso_eventtickets_prem_userJSRedirectActiv',
				'value'       => get_post_meta( $product_id, 'saso_eventtickets_prem_userJSRedirectActiv', true ),
				'label'       => 'Activate URL redirect',
				'description' => 'Activate this, to redirect the user if the ticket was validated on the validation form. Works only if the global redirect option is activated.',
				'custom_attributes' => $readonly
			) );
			woocommerce_wp_text_input( array(
				'id'                => 'saso_eventtickets_prem_userJSRedirectURL',
				'value'             => get_post_meta( $product_id, 'saso_eventtickets_prem_userJSRedirectURL', true ),
				'label'             => 'URL to redirect the user, if the ticket is valid',
				'description'       => "If the global redirect option is activated. The URL can be relative like '/page/' or absolute 'https//domain/url/'. You can use these placeholder for your URL:<b>{USERID}</b>, <b>{CODE}</b>, <b>{CODEDISPLAY}</b>, {IP}, {LIST}, {LIST_DESC} and the other placeholder.",
				'desc_tip'    		=> true,
				'custom_attributes' => $readonly
			) );
			echo '</div>';

			$this->wc_order_addJSFileAndHandlerBackend($this->orderCouldHaveMoreSerials);
			echo "<div><h3>Overwrite images on the PDF ticket</h3>";
			woocommerce_wp_text_input( array(
				'id'                => 'saso_eventtickets_prem_wcTicketTicketLogo',
				'value'             => get_post_meta( $product_id, 'saso_eventtickets_prem_wcTicketTicketLogo', true ),
				'label'             => 'Display a small logo (max. 300x300px) at the bottom in the center',
				'description'       => "If a media file is chosen, the logo will be placed on the ticket PDF. It will overwrite the logo from the main options if set.",
				'desc_tip'    		=> true
			) );
			woocommerce_wp_text_input( array(
				'id'                => 'saso_eventtickets_prem_wcTicketTicketBanner',
				'value'             => get_post_meta( $product_id, 'saso_eventtickets_prem_wcTicketTicketBanner', true ),
				'label'             => 'Display a banner image image at the top of the PDF',
				'description'       => "If a media file is chosen, the banner will be placed on the ticket PDF. It will overwrite the logo from the main options if set.",
				'desc_tip'    		=> true
			) );
			woocommerce_wp_text_input( array(
				'id'                => 'saso_eventtickets_prem_wcTicketTicketBG',
				'value'             => get_post_meta( $product_id, 'saso_eventtickets_prem_wcTicketTicketBG', true ),
				'label'             => 'Display a background image image at the center of the PDF',
				'description'       => "If a media file is chosen, the image will be placed on the ticket PDF. It will overwrite the logo from the main options if set.",
				'desc_tip'    		=> true
			) );
			echo "</div>";
		} catch (Exception $e) {
			$this->logError($e);
		}
	}

	/**
	* exposed to basic
	*/
	public function saso_eventtickets_wc_save_fields($id, $post) { // save woocommerce product options
		try {
			$key = 'saso_eventtickets_expiration_days';
			if( !empty( $_POST[$key] ) ) {
				update_post_meta( $id, $key, intval($_POST[$key]) );
			} else {
				delete_post_meta( $id, $key );
			}
			$key = 'saso_eventtickets_prem_userJSRedirectActiv';
			if( !empty( $_POST[$key] ) ) {
				update_post_meta( $id, $key, isset($_POST[$key]) ? 'yes' : 'no' );
			} else {
				delete_post_meta( $id, $key );
			}

			$key = 'saso_eventtickets_prem_userJSRedirectURL';
			if( !empty( $_POST[$key] ) ) {
				update_post_meta( $id, $key, sanitize_text_field($_POST[$key]));
			} else {
				delete_post_meta( $id, $key );
			}

			$key = 'saso_eventtickets_prem_wcTicketTicketLogo';
			if( !empty( $_POST[$key] ) ) {
				update_post_meta( $id, $key, sanitize_text_field($_POST[$key]));
			} else {
				delete_post_meta( $id, $key );
			}
			$key = 'saso_eventtickets_prem_wcTicketTicketBanner';
			if( !empty( $_POST[$key] ) ) {
				update_post_meta( $id, $key, sanitize_text_field($_POST[$key]));
			} else {
				delete_post_meta( $id, $key );
			}
			$key = 'saso_eventtickets_prem_wcTicketTicketBG';
			if( !empty( $_POST[$key] ) ) {
				update_post_meta( $id, $key, sanitize_text_field($_POST[$key]));
			} else {
				delete_post_meta( $id, $key );
			}
		} catch (Exception $e) {
			$this->logError($e);
		}
	}
		/**
	* exposed to basic
	*/
	public function woocommerce_product_after_variable_attributes($loop, $variation_data, $variation) {
		echo '<div class="options_group">';
		$days = get_post_meta( $variation->ID, 'saso_eventtickets_expiration_days', true );
		if (empty($days)) $days = -1;
		woocommerce_wp_text_input( array(
			'id'                => 'saso_eventtickets_expiration_days['.$loop.']',
			'value'             => intval($days),
			'label'             => 'Days till the ticket expires',
			'type'				=> 'number',
			'custom_attributes'	=> ['step'=>'1', 'min'=>'-1'],
			'description'       => 'If set to -1, then the value of the main product will be used. If set to zero, then no expiration will be activated. If the ticket or the ticket list contains an expiration date or days, then this will be used. If you activate the option "Set expiration date for purchased tickets immediately", then expiration date will be set using the expiration days. Otherwise if you redeem the ticket the first time, these days are added as a expiration date.',
			'desc_tip'    		=> true
		) );
		echo '</div>';
	}
	/**
	* exposed to basic
	*/
	public function woocommerce_save_product_variation($variation_id, $i) {
		$key = 'saso_eventtickets_expiration_days';
		if( isset($_POST[$key]) && isset($_POST[$key][$i]) ) {
			update_post_meta( $variation_id, $key, intval($_POST[$key][$i]) );
		} else {
			delete_post_meta( $variation_id, $key );
		}
	}

	private function getDefaultMetaValueOfExpiration() {
		return ['date'=>'', 'days'=>0];
	}

	/**
	* exposed to basic
	*/
	public function getMetaObject($metaObj) {
		try {
			// expiration date is fix und wird zuerst ausgewertet (direkt über add code, edit code), expiration days werden jedes mal berechnet und hinzugefügt auf den first_success
			$metaObj['expiration'] = $this->getDefaultMetaValueOfExpiration();
		} catch (Exception $e) {
			$this->logError($e);
		}
		return $metaObj;
	}

	/**
	* exposed to basic
	*/
	public function getMetaObjectList($metaObj) {
		if (is_array($metaObj)) {
			$metaObj['expiration'] = ['days'=>0];
			$metaObj['pdfticket_images'] = ['logo'=>'', 'banner'=>'', 'bg'=>''];
		} else {
			$this->logError(new Exception("metaobj is not an array"));
		}
		return $metaObj;
	}

	private function speicherMetaObjekt($codeObj, $metaObj) {
		// speicher meta
		$codeObj['meta'] = $this->_json_encode_with_error_handling($metaObj);
		$this->getDB()->update("codes", ["meta"=>$codeObj['meta']], ['id'=>$codeObj['id']]);
		return $codeObj;
	}

	/**
	* exposed to basic
	*/
	public function removeWoocommerceRstrPurchaseInfoFromCode($codeObj, $felder, $data) {
		try {
			return $this->removeWoocommerceOrderInfoFromCode($codeObj, $felder, $data);
		} catch (Exception $e) {
			$this->logError($e);
		}
	}

	/**
	* exposed to basic
	*/
	 public function removeWoocommerceOrderInfoFromCode($codeObj, $felder, $data) {
		try {
			$metaObj = $this->getCore()->encodeMetaValuesAndFillObject($codeObj['meta']);
			// expiration values entfernen
			$metaObj['expiration'] = $this->getDefaultMetaValueOfExpiration();
			$codeObj = $this->speicherMetaObjekt($codeObj, $metaObj);
			$felder["meta"] = $codeObj['meta'];
		} catch (Exception $e) {
			$this->logError($e);
		}
		return $felder;
	}

	/**
	* exposed to basic
	*/
	public function addCodeFromListForOrderAfter($codeObj) {
		try {
			$codeObj = $this->filter_updateExpirationInfo($codeObj);
		} catch (Exception $e) {
			$this->logError($e);
		}
		return $codeObj;
	}

	public function filter_updateExpirationInfo($codeObj) {
		$metaObj = $this->getCore()->encodeMetaValuesAndFillObject($codeObj['meta']);

		// if code has not an expiration date set - no action
		if (!empty($metaObj['expiration']['date'])) return $codeObj;

		$product_id = 0;
		if (isset($metaObj['woocommerce']) && isset($metaObj['woocommerce']['product_id'])) {
			$product_id = intval($metaObj['woocommerce']['product_id']);
		}

		if ($this->_isOptionCheckboxActive("expireWCSetDateWithSale")) {
			$basetimestamp = SASO_EVENTTICKETS::time();
			$expiration_date = "";

			if ($product_id > 0) {
				$endTimeOfProduct = "";
				$useEndTimeOfProduct = $this->_isOptionCheckboxActive("expireWCSetDateWithSaleAndUseEndTime");
				if ($useEndTimeOfProduct) {
					$endTimeOfProduct = get_post_meta( $product_id, 'saso_eventtickets_ticket_end_time', true );
					if (!empty($endTimeOfProduct)) {
						$basetimestamp = strtotime(SASO_EVENTTICKETS::date("Y-m-d ".$endTimeOfProduct));
					}
				} else {
					// check if order is set
					$order_id = intval($metaObj['woocommerce']['order_id']);
					if ($order_id > 0) {
						$order = wc_get_order( $order_id );
						// use order creation time
						$dt = new DateTime($order->get_date_created());
						//if ($order->get_date_completed()) {
							//$dt = new DateTime($order->get_date_completed());
						//}
						$order->get_date_completed();
						$basetimestamp = strtotime($dt->format("Y-m-d H:i"));
					}
				}

				$product = wc_get_product($product_id);
				$is_variation = $product->get_type() == "variation" ? true : false;

				$v_expiration_days = intval(get_post_meta( $product_id, 'saso_eventtickets_expiration_days', true ));
				if ($is_variation) {
					if ($v_expiration_days < 0) { // -1, so take the value of the parent product
						$product_parent_id = $product->get_parent_id();
						$v_expiration_days = intval(get_post_meta( $product_parent_id, 'saso_eventtickets_expiration_days', true ));
					}
				}
				if (empty($expiration_date) && $v_expiration_days > 0) {
					$expiration_date = SASO_EVENTTICKETS::date("Y-m-d H:i:s", strtotime('+'.intval($v_expiration_days).' day', $basetimestamp));
				}
			}

			// take days from code
			if (empty($expiration_date) && $metaObj['expiration']['days'] > 0) {
				$expiration_date = SASO_EVENTTICKETS::date("Y-m-d H:i:s", strtotime('+'.intval($metaObj['expiration']['days'].' day', $basetimestamp)));
			}

			// or take days from code list
			if (empty($expiration_date) && $codeObj['list_id'] > 0) {
				try {
					$listObj = $this->getCore()->getListById($codeObj['list_id']);
					$metaObjList = $this->getCore()->encodeMetaValuesAndFillObjectList($listObj['meta']);
					if ($metaObjList['expiration']['days'] > 0) {
						$expiration_date = SASO_EVENTTICKETS::date("Y-m-d H:i:s", strtotime('+'.intval($metaObjList['expiration']['days'].' day', $basetimestamp)));
					}
				} catch(Exception $e) {}
			}

			// or is global active and then take the global days
			if (empty($expiration_date) && $this->_isOptionCheckboxActive("expireActivateForAllCodesWithoutExpDate")) {
				$days = intval($metaObj['expiration']['days']);
				$days_global = intval($this->_getOptionValue('expireDaysForNoDate'));
				if ($days == 0 && $days_global > 0) {
					$days = $days_global;
				}
				$expiration_date = SASO_EVENTTICKETS::date("Y-m-d H:i:s", strtotime('+'.$days.' day', $basetimestamp));
			}

			// set expiration date
			if (!empty($expiration_date)) {
				$metaObj['expiration']['date'] = $expiration_date;
				$codeObj = $this->speicherMetaObjekt($codeObj, $metaObj);
			}
		} else {
			// take days from product product_id if set higher than 0
			if (intval($metaObj['expiration']['days']) == 0 && $product_id > 0) {
				$v_expiration_days = intval(get_post_meta( $product_id, 'saso_eventtickets_expiration_days', true ));
				if ($v_expiration_days > 0) {
					$metaObj['expiration']['days'] = $v_expiration_days;
					$codeObj = $this->speicherMetaObjekt($codeObj, $metaObj);
				}
			}
		}
		return $codeObj;
	}
	public function filter_setExpirationDateFromDays($codeObj) {
		$metaObj = $this->getCore()->encodeMetaValuesAndFillObject($codeObj['meta']);
		// if code has not an expiration date set - no action
		if (!empty($metaObj['expiration']['date'])) return $codeObj;
		$days = intval($metaObj['expiration']['days']);
		if ($days > 0) {
			$expiration_date = SASO_EVENTTICKETS::date("Y-m-d H:i:s", strtotime('+'.$days.' day'));
			$metaObj['expiration']['date'] = $expiration_date;
			$codeObj = $this->speicherMetaObjekt($codeObj, $metaObj);
		}
		return $codeObj;
	}

	/**
	* exposed to basic
	*/
	/**
	 * aktualisiert metas die nur für premium sind
	 * @param newcode ist ein code oder evtl inkls cvv code
	 * @param daat assoc array mit meta data
	 */
	public function updateCodeMetas($newcode, $data) {
		try {
			// add extra meta informations, that are only for premium
			$cvv = "";
			$teile = explode(";", $newcode);
			if (count($teile) > 1) {
				$newcode = trim($teile[0]);
				$cvv = trim($teile[1]);
			}
			$code = $this->getCore()->clearCode($newcode);

			$expiration_date = isset($data['expiration_date']) ? $data['expiration_date'] : "";
			$expiration_days = isset($data['expiration_days']) ? $data['expiration_days'] : 0;

			$codeObj = $this->getCore()->retrieveCodeByCode($code);

			if (!empty($expiration_date) || $expiration_days > 0) {
				// lade metaobj
				$metaObj = $this->getCore()->encodeMetaValuesAndFillObject($codeObj['meta']);

				if($expiration_date != "") {
					$d = SASO_EVENTTICKETS::date("Y-m-d H:i:s", strtotime($expiration_date));
					$metaObj['expiration']['date'] = SASO_EVENTTICKETS::date("Y-m-d H:i:s", strtotime($expiration_date));
				} else {
					$metaObj['expiration']['days'] = intval($expiration_days);
				}
				// speicher meta
				$codeObj = $this->speicherMetaObjekt($codeObj, $metaObj);
			}
		} catch (Exception $e) {
			$this->logError($e);
		}
	}

	public function getJSRedirectURL($codeObj) {
		$url = "";
		if (!empty($codeObj['order_id'])) {
			$metaObj = $this->getCore()->encodeMetaValuesAndFillObject($codeObj['meta']);
			if ($metaObj['woocommerce']['product_id'] > 0) {
				$is_userRedirectActive = get_post_meta( $metaObj['woocommerce']['product_id'], 'saso_eventtickets_prem_userJSRedirectActiv', true );
				if ($is_userRedirectActive == "yes") {
					$url = trim(get_post_meta( $metaObj['woocommerce']['product_id'], 'saso_eventtickets_prem_userJSRedirectURL', true ));
				}
			}
		}
		return $url;
	}

}
?>