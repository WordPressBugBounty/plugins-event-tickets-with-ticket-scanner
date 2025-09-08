<?php
include_once(plugin_dir_path(__FILE__)."init_file.php");
class sasoEventtickets_Options {
	private $_options;
	private $MAIN;
	private $_prefix;
	public function __construct($MAIN, $_prefix) {
		$this->MAIN = $MAIN;
		$this->_prefix = $_prefix;
	}
	private function getBase() {
		return $this->MAIN->getBase();
	}
	public function initOptions() {
		$order_status = [];
		if (function_exists("wc_get_order_statuses")) {
			$order_status = wc_get_order_statuses();
		}

		$this->_options = [];

		$this->_options[] = $this->getOptionsObject('h99', esc_html__("Display options", 'event-tickets-with-ticket-scanner'),"","heading");
		$this->_options[] = $this->getOptionsObject('displayDateFormat', esc_html__("Your own date format", 'event-tickets-with-ticket-scanner'), esc_html__("If left empty, default will be 'Y/m/d'. Using the php date function format. Y=year, m=month, d=day H:hours, i:minutes, s=seconds", 'event-tickets-with-ticket-scanner'),"text", "Y/m/d", [], true);
		$this->_options[] = $this->getOptionsObject('displayTimeFormat', esc_html__("Your own time format", 'event-tickets-with-ticket-scanner'), esc_html__("If left empty, default will be 'H:i'. Using the php date function format. H=hours with leading 0, i=minutes with leading zero, s=seconds", 'event-tickets-with-ticket-scanner'),"text", "H:i", [], true);
		//$this->_options[] = $this->getOptionsObject('displayDateFormatDatePicker', esc_html__("Date format of the date picker", 'event-tickets-with-ticket-scanner'), esc_html__("If left empty, default will be 'yy-mm-dd'. Using the jquery datepicker format.", 'event-tickets-with-ticket-scanner'). __("<ul><li>d - day of month (no leading zero)</li><li>dd - day of month (two digit)</li><li>o - day of the year (no leading zeros)</li><li>oo - day of the year (three digit)</li><li>D - day name short</li><li>DD - day name long</li><li>m - month of year (no leading zero)</li><li>mm - month of year (two digit)</li><li>M - month name short</li><li>MM - month name long</li><li>y - year (two digit)</li><li>yy - year (four digit)</li></ul>", 'event-tickets-with-ticket-scanner'),"text", "yy-mm-dd", [], true);
		$this->_options[] = $this->getOptionsObject('displayAdminAreaColumnConfirmedCount', esc_html__("Display the column 'confirmed count' of the ticket", 'event-tickets-with-ticket-scanner'), esc_html__("If active, then a new column within the admin area for each ticket will be shown with the confirmed count value.", 'event-tickets-with-ticket-scanner'), "checkbox");
		$this->_options[] = $this->getOptionsObject('displayAdminAreaColumnRedeemedInfo', esc_html__("Display a column with the information how often the ticket is redeemed", 'event-tickets-with-ticket-scanner'), esc_html__("If active, then a new column within the admin area for each ticket will be shown with the redeem ticket information. This feature can be very slow.", 'event-tickets-with-ticket-scanner'), "checkbox");
		$this->_options[] = $this->getOptionsObject('displayAdminAreaColumnBillingName', esc_html__("Display a column with the name of the buyer", 'event-tickets-with-ticket-scanner'), __('If active, then a new column within the admin area for each ticket will be shown with the billing name. <b>This feature can be very slow.</b>', 'event-tickets-with-ticket-scanner'),"checkbox");
		$this->_options[] = $this->getOptionsObject('displayAdminAreaColumnBillingCompany', esc_html__("Display a column with the billing company of the order", 'event-tickets-with-ticket-scanner'), __('If active, then a new column within the admin area for each ticket will be shown with the billing company. <b>This feature can be very slow.</b>', 'event-tickets-with-ticket-scanner'),"checkbox");

		$this->_options[] = $this->getOptionsObject('h0a', "Access","","heading");
		$this->_options[] = $this->getOptionsObject('allowOnlySepcificRoleAccessToAdmin', "Allow only specific roles access to the admin area","If active, then only the administrator and the choosen roles area allowed to access this admin area.","checkbox", true, [], true, 'https://youtu.be/YRC-isNcWu4');
		$all_roles = wp_roles()->roles;
		$editable_roles = apply_filters('editable_roles', $all_roles);
		$additional = [ "multiple"=>1, "values"=>[["label"=>"No role execept Administrator allowed", "value"=>"-"]] ];
		foreach($editable_roles as $key => $value) {
			if ($key == "administrator") continue;
			$additional['values'][] = ["label"=>$value['name'], "value"=>$key];
		}
		$this->_options[] = $this->getOptionsObject('adminAreaAllowedRoles', "Allow the specific role to access the backend of the event ticket", "If a role is chosen, then the user with this role is allowed to access the event ticket admin area. This will not exclude the 'administrator', if the option is activated.", "dropdown",	"-", $additional, false);
		$this->_options[] = $this->getOptionsObject('wcTicketAllowOnlyLoggedinToDownload', "Allow only logged in users to download their tickets","If active, then only logged in users can download and see the ticket, calendar file and the bagde.","checkbox", false, [], true, '');
		$this->_options[] = $this->getOptionsObject('wcTicketAllowOnlyLoggedinToDownloadRedirectURL', "URL where not logged in users should be redirected to","If option wcTicketAllowOnlyLoggedinToDownload is active, then the not logged in users will be redirected to this URL. If the URL is empty, then a message will be shown.","text", '', [], false, '');

		$options = [];
		$options[] = [
			'key'=>'h12a',
			'label'=>__("Ticket scanner", 'event-tickets-with-ticket-scanner'),
			'desc'=>"",
			'type'=>"heading"
			];
		$all_roles = wp_roles()->roles;
		$editable_roles = $all_roles;
		$additional = [ "values"=>[["label"=>esc_attr__("No login required to access scanner", 'event-tickets-with-ticket-scanner'), "value"=>"-"]] ];
		foreach($editable_roles as $key => $value) {
			$additional['values'][] = ["label"=>translate_user_role($value['name']), "value"=>$key];
		}
		$options[] = [
				'key'=>'wcTicketScannerAllowedRoles',
				'label'=>__("Allow the specific role to access the ticket scanner", 'event-tickets-with-ticket-scanner'),
				'desc'=>__("If a role is chosen, then the user with this role is allowed to use the ticket scanner. This will not exclude the 'administrator', if the option is activated.", 'event-tickets-with-ticket-scanner'),
				'type'=>"dropdown",
				'def'=>"-",
				'additional'=>$additional,
				'isPublic'=>false,
				'_doc_video'=>'https://youtu.be/VsgAYhgf_iA'
				];
		$options[] = ['key'=>'wcTicketOnlyLoggedInScannerAllowed', 'label'=>__('Allow logged in user as adminstrator to open the ticket scanner', 'event-tickets-with-ticket-scanner'), 'desc'=>__('If active, only logged-in user can scan a ticket. It is also testing if the user is an administrator.', 'event-tickets-with-ticket-scanner'), 'type'=>'checkbox', '_doc'=>['video'=>'https://youtu.be/rnv4HULJNHM']];
		$options[] = ['key'=>'wcTicketAllowRedeemOnlyPaid', 'label'=>__('Allow to redeem ticket only if it is paid', 'event-tickets-with-ticket-scanner'), 'desc'=>__('If active, only paid and not refunded or cancelled tickets can be redeemed by the ticket scanner. Normal users can anyway not redeem unpaid tickets by themself.', 'event-tickets-with-ticket-scanner'), 'type'=>'checkbox', 'def'=>false, '_doc_video'=>'https://youtu.be/nS2J7CYb6eM'];
		$options[] = ['key'=>'wcTicketScanneCountRetrieveAsConfirmed', 'label'=>__('Count each ticket scan with the ticket scanner as a confirmed status check', 'event-tickets-with-ticket-scanner'), 'desc'=>__('If active, each ticket scan will be counted treated as a confirmed validation check and increase the confirmed status check counter. Only if the ticket is active.', 'event-tickets-with-ticket-scanner'), 'type'=>'checkbox', '_doc_video'=>'https://youtu.be/BUuV9FDR7ww'];
		$options[] = ['key'=>'wcTicketScannerDisplayConfirmedCount', 'label'=>__('Display confirmed status checks on the ticket scanner view', 'event-tickets-with-ticket-scanner'), 'desc'=>__('If active, the confirmed validation checks are displayed whith the retrieved ticket on the ticket scanner.', 'event-tickets-with-ticket-scanner'), 'type'=>'checkbox'];
		$options[] = ['key'=>'wcTicketDontAllowRedeemTicketBeforeStart', 'label'=>__('Do not allow tickets to be redeemed before starting date', 'event-tickets-with-ticket-scanner'), 'desc'=>__('If active, the ticket can only be redeemed at the start date and during the event.', 'event-tickets-with-ticket-scanner'), 'type'=>'checkbox', '_doc_video'=>'https://youtu.be/GBJqyxmu3jE'];
		$options[] = ['key'=>'wcTicketOffsetAllowRedeemTicketBeforeStart', 'label'=>__('How many hours before the event can the ticket be redeemed?', 'event-tickets-with-ticket-scanner'), 'desc'=>__('The hours will be subtracted from the starting time of the event. Only used if the option "wcTicketDontAllowRedeemTicketBeforeStart" is active.', 'event-tickets-with-ticket-scanner'), 'type'=>'number', 'def'=>1, "additional"=>["min"=>0], '_doc_video'=>'https://youtu.be/RL6d-hTJxes'];
		$options[] = ['key'=>'wcTicketAllowRedeemTicketAfterEnd', 'label'=>__('Allow tickets to be redeemed after ending date and time', 'event-tickets-with-ticket-scanner'), 'desc'=>__('If active, the ticket can be redeemed after the end date and time of the event. If the product has no end date, it will be ignored. If the product just have a date and no time, then the time will be set to 23:59 for the test.', 'event-tickets-with-ticket-scanner'), 'type'=>'checkbox', '_doc_video'=>'https://youtu.be/gVXnsBsEGNI'];
		$options[] = ['key'=>'ticketScannerDontRememberCamChoice', 'label'=>__('Do not store the chosen cam device id on your browser', 'event-tickets-with-ticket-scanner'), 'desc'=>__('To speed up the scanning start, the camera device id is stored within the browser for the ticket scanner. If you do not want this, you can deactivate this option. Additionally you can use the button within the ticket scanner at the bottom to clear the stored device id from your browser.', 'event-tickets-with-ticket-scanner'), 'type'=>'checkbox', '_doc_video'=>'https://youtu.be/myaUMHgGHZg'];
		$options[] = ['key'=>'ticketScannerDontShowOptionControls', 'label'=>__('Do not show the option controls', 'event-tickets-with-ticket-scanner'), 'desc'=>__('Hide the options of the ticket scanner from the ticket scanner view. So the person who is scanning cannot change the options. The presets are taking as default. If not active, the users choice on the ticket scanner will be used.', 'event-tickets-with-ticket-scanner'), 'type'=>'checkbox', '_doc_video'=>'https://youtu.be/CnmTN1K-Z1o'];
		$options[] = ['key'=>'ticketScannerDontShowBtnPDF', 'label'=>__('Do not show the PDF download button', 'event-tickets-with-ticket-scanner'), 'desc'=>__('Hide the PDF button on the ticket scanner.', 'event-tickets-with-ticket-scanner'), 'type'=>'checkbox', '_doc_video'=>'https://youtu.be/0P9nEVbKy0M'];
		$options[] = ['key'=>'ticketScannerDontShowBtnBadge', 'label'=>__('Do not show the Badge download button', 'event-tickets-with-ticket-scanner'), 'desc'=>__('Hide the Badge button on the ticket scanner.', 'event-tickets-with-ticket-scanner'), 'type'=>'checkbox', '_doc_video'=>'https://youtu.be/0P9nEVbKy0M'];
		$options[] = ['key'=>'ticketScannerDontShowOptionControls', 'label'=>__('Do not show the option controls', 'event-tickets-with-ticket-scanner'), 'desc'=>__('Hide the options of the ticket scanner from the ticket scanner view. So the person who is scanning cannot change the options. The presets are taking as default. If not active, the users choice on the ticket scanner will be used.', 'event-tickets-with-ticket-scanner'), 'type'=>'checkbox'];
		$options[] = ['key'=>'ticketScannerStartCamWithoutButtonClicked', 'label'=>__('Preset: Start cam to scan next ticket immediately', 'event-tickets-with-ticket-scanner'), 'desc'=>__('If active, the ticket scanner will skip the scan-next-button and start the cam immediately.', 'event-tickets-with-ticket-scanner'), 'type'=>'checkbox', '_doc_video'=>'https://youtu.be/itsipS8HNbw'];
		$options[] = ['key'=>'ticketScannerScanAndRedeemImmediately', 'label'=>__('Preset: Scan and Redeem immediately', 'event-tickets-with-ticket-scanner'), 'desc'=>__('If active, the ticket scanner will be preset with the option to scan the ticket and redeem it with the scan.', 'event-tickets-with-ticket-scanner'), 'type'=>'checkbox', '_doc_video'=>'https://youtu.be/wzWTwWJg7QA'];
		$options[] = ['key'=>'ticketScannerHideTicketInformation', 'label'=>__('Preset: Hide ticket information', 'event-tickets-with-ticket-scanner'), 'desc'=>__('If active, the ticket information wil not be shown.', 'event-tickets-with-ticket-scanner'), 'type'=>'checkbox', '_doc_video'=>'https://youtu.be/StDkB_u0PZc'];
		$options[] = ['key'=>'ticketScannerHideTicketInformationShowShortDesc', 'label'=>__('Preset: Display short description if ticket information is hidden', 'event-tickets-with-ticket-scanner'), 'desc'=>__('If active, the ticket short description will be shown at the top after the ticket is retrieved. It is only executed if the ticket information is hidden.', 'event-tickets-with-ticket-scanner'), 'type'=>'checkbox', '_doc_video'=>''];
		$additional = [ "multiple"=>0, "values"=>[ ["label"=>__('Do not change the order status', 'event-tickets-with-ticket-scanner'), "value"=>"1"] ] ];
		foreach($order_status as $key => $value) {
			$additional['values'][] = ["label"=>$value, "value"=>$key];
		}
		$options[] = [
				'key'=>'ticketScannerSetOrderStatusAfterRedeem',
				'label'=>__("Choose the new order status if you redeem successfully a ticket", 'event-tickets-with-ticket-scanner'),
				'desc'=>__("In doubt, do not play with it. :) If an order status is choosen and the ticket is redeemed successfully, then the order status will be set to your choice. If none is selected then nothing happens with the order.", 'event-tickets-with-ticket-scanner'),
				'type'=>"dropdown",
				'additional'=>$additional,
				'isPublic'=>false
				];
		$options[] = [
				'key'=>'ticketScannerSetOrderStatusAfterTicketView',
				'label'=>__("Choose the new order status if your customer view the ticket details and/or download the PDF ticket", 'event-tickets-with-ticket-scanner'),
				'desc'=>__("In doubt, do not play with it. :) If an order status is choosen and the ticket is viewed online or the PDF is downloaded, then the order status will be set to your choice. This includes the order detail view and the download of the order PDF ticket. If none is selected then nothing happens with the order. <b>Warning: </b> If you change the order to eg. refunded and the option is activate, then the order will be set to your new order, just because your customer was downloading the PDF ticket! For almost all uses cases this option makes no sense - it is just for a few special use cases needed.", 'event-tickets-with-ticket-scanner'),
				'type'=>"dropdown",
				'additional'=>$additional,
				'isPublic'=>false
				];
		$options[] = ['key'=>'ticketScannerDisplayTimes', 'label'=>__('Display server and ticket times on the ticket scannner', 'event-tickets-with-ticket-scanner'), 'desc'=>__('If active, then the server time, time zone and ticket times are displayed additional to the ticket scanner info. Ticket times, only if available.', 'event-tickets-with-ticket-scanner'), 'type'=>'checkbox'];

		$options[] = [
			'key'=>'h12b3',
			'label'=>__("Compatibility Mode", 'event-tickets-with-ticket-scanner'),
			'desc'=>__("These settings can help to make it work, in case you adjusted your server and/or Wordpress settings.", 'event-tickets-with-ticket-scanner'),
			'type'=>"heading"
			];
			$options[] = ['key'=>'wcTicketCompatibilityModeURLPath', 'label'=>__("Ticket detail URL path", 'event-tickets-with-ticket-scanner'), 'desc'=>__("If left empty, default will be using the default ticket detail page from within the plugin folder. On some installations this leads to a 403 problem. If the the default ticket detail view of the plugin is not working try to set the ticket detail URL path. Make sure that the URL path does not exists, otherwise the page will be shown instead of the ticket. Example of a URL path 'event-tickets/myticket' or 'event-tickets/ticket-details/'. Any leading and trailing slash '/' will be ignored.", 'event-tickets-with-ticket-scanner'), 'type'=>"text", '_doc_video'=>'https://youtu.be/ZWDsHs_SYc8'];
			$options[] = ['key'=>'wcTicketCompatibilityMode', 'label'=>__("Compatibility mode for ticket URL"), 'desc'=>__("If your theme is showing the 404 title or the ticket is not rendered at all, then you can try to use this compatibility mode. If active, then the URL /ticket/XYZ will be /ticket/?code=XYZ URL for the link to the ticket detail and ticket PDF page. Some themes causing issues with the normal mode."), 'type'=>"checkbox", '_doc_video'=>'https://youtu.be/KhJXtuBnr10'];
			$options[] = ['key'=>'wcTicketCompatibilityUseURL', 'label'=>__("Compatibility mode for ticket images using URL instead of file location"), 'desc'=>__("If your images on the PDF are not shown then this option trigger not to use the file location but an URL to your image. <b>Note:</b> Your firewall need to allow your system to call itsself to download the image that will be added to the PDF."), 'type'=>"checkbox", '_doc_video'=>''];
			$options[] = ['key'=>'wcTicketActivateOBFlush', 'label'=>__("Activate ob_end_flush"), 'desc'=>__("Some plugins and/or themes are injecting a ob (caching) operation and this can harm the PDF generation. If you experience, that your PDF ticket is not rendered, you can try to activate this option. But it can slow down a bit your wordpress installation!"), 'type'=>"checkbox", '_doc_video'=>'https://youtu.be/8ynFKPc-xKE'];
			$options[] = ['key'=>'wcTicketCompatibilityModeRestURL', 'label'=>__("Rest Service URL path", 'event-tickets-with-ticket-scanner'), 'desc'=>__("In case your ticket scanner cannot call the Rest service, because your setup is using a different location for the wordpress system, then you can add here the URL to your system. If left empty, default will be using the default retrieved from your server. You can add only FQDN, like 'https://yourdomain'. This will be concatenated to the /wp-json/...", 'event-tickets-with-ticket-scanner'), 'type'=>"text", '_doc_video'=>'https://youtu.be/I8CVpGNwLtI'];

		$options[] = [
				'key'=>'h12',
				'label'=>__("Woocommerce ticket sale", 'event-tickets-with-ticket-scanner'),
				'desc'=>__("You can assign a list to a product and this will generate or re-use a ticket from this list as a ticket number. It will be printed on the purchase information to the customer.", 'event-tickets-with-ticket-scanner'),
				'type'=>"heading"
				];
		//$options[] = ['key'=>'wcTicketDontShowRedeemBtnOnTicket', 'label'=>__("Do not show the redeem button on the ticket detail view for the client", 'event-tickets-with-ticket-scanner'),'desc'=>__("If active, it will not add the self-redeem button on the ticket detail view.", 'event-tickets-with-ticket-scanner'),'type'=>"checkbox", 'def'=>'', 'additional'=>[]];
		$options[] = ['key'=>'wcTicketShowRedeemBtnOnTicket', 'label'=>__("Show the redeem button on the ticket detail view for the client", 'event-tickets-with-ticket-scanner'),'desc'=>__("If active, it will add the self-redeem button on the ticket detail view.", 'event-tickets-with-ticket-scanner'),'type'=>"checkbox", 'def'=>'', 'additional'=>[], '_doc_video'=>'https://youtu.be/IH5Uqf023FE'];
		$options[] = ['key'=>'wcTicketShowInputFieldsOnCheckoutPage', 'label'=>__("Show the input fields on the checkout page", 'event-tickets-with-ticket-scanner'),'desc'=>__("If active, it will add the input fields to ask for values configured on the product. Eg. name, date picker.", 'event-tickets-with-ticket-scanner'),'type'=>"checkbox", 'def'=>false, 'additional'=>[]];
		$options[] = ['key'=>'wcTicketPrefixTextCode', 'label'=>__("Text that will be added before the ticket number on the PDF invoice, order table and order details", 'event-tickets-with-ticket-scanner'), 'desc'=>__("If left empty, default will be 'Ticket number:'", 'event-tickets-with-ticket-scanner'), 'type'=>"text", 'def'=>__("Ticket number:", 'event-tickets-with-ticket-scanner'), 'additional'=>[], 'isPublic'=>false, '_doc_video'=>'https://youtu.be/uP6l8_6qLG4'];
		$options[] = ['key'=>'wcTicketDontDisplayPDFButtonOnDetail', 'label'=>__("Hide the PDF download button on ticket detail page", 'event-tickets-with-ticket-scanner'), 'desc'=>__("If active, it will not display the PDF download button on the ticket detail view. But the PDF can still be generated with the URL.", 'event-tickets-with-ticket-scanner'), 'type'=>"checkbox", 'def'=>"", '_doc_video'=>'https://youtu.be/nF1fNu3HGOQ'];
		$options[] = ['key'=>'wcTicketDisplayOrderTicketsViewLinkOnMail', 'label'=>__("Display the order detail view link with all tickets in one page in the purchase order email", 'event-tickets-with-ticket-scanner'), 'desc'=>__("If active, a link to see all tickets QR codes within the purchase email to the client. This speeds up the entrance for groups and family ticket purchase. It wil be below the order details table.", 'event-tickets-with-ticket-scanner'), 'type'=>"checkbox", 'def'=>"1", '_doc_video'=>'https://youtu.be/iNgJLj8a2iE'];
		$options[] = ['key'=>'wcTicketDisplayOrderTicketsViewLinkOnCheckout', 'label'=>__("Display the order detail view link with all tickets in one page on the checkout page", 'event-tickets-with-ticket-scanner'), 'desc'=>__("If active, a link to see all tickets QR codes within the checkout page will be placed. Only if the purchase has tickets. This speeds up the entrance for groups and family ticket purchase. It wil be above the order details table.", 'event-tickets-with-ticket-scanner'), 'type'=>"checkbox", '_doc_video'=>'https://youtu.be/P71ImAU0u3U'];
		$options[] = ['key'=>'wcTicketDisplayDownloadAllTicketsPDFButtonOnMail', 'label'=>__("Display all tickets in one PDF download button/link on purchase order email", 'event-tickets-with-ticket-scanner'), 'desc'=>__("If active, a link to download all tickets as one PDF within the purchase email to the client. It will be below the order details table.", 'event-tickets-with-ticket-scanner'), 'type'=>"checkbox", 'def'=>"", '_doc_video'=>'https://youtu.be/LCjfoNT9pcY'];
		$options[] = ['key'=>'wcTicketDisplayDownloadAllTicketsPDFButtonOnCheckout', 'label'=>__("Display all tickets in one PDF download button/link on the checkout page", 'event-tickets-with-ticket-scanner'), 'desc'=>__("If active, a link to download all tickets as one PDF on the checkout page above the order details will be placed. Only if the purchase has tickets.", 'event-tickets-with-ticket-scanner'), 'type'=>"checkbox", 'def'=>"", '_doc_video'=>'https://youtu.be/pZUuVDqhPiI'];
		$options[] = ['key'=>'wcTicketDisplayDownloadAllTicketsPDFButtonOnOrderdetail', 'label'=>__("Display all tickets in one PDF download button/link on the order detail view", 'event-tickets-with-ticket-scanner'), 'desc'=>__("If active, a link to download all tickets as one PDF on the order detail page below the tickets will be placed. Only if the purchase has tickets.", 'event-tickets-with-ticket-scanner'), 'type'=>"checkbox", 'def'=>""];
		$options[] = ['key'=>'wcTicketDontDisplayPDFButtonOnMail', 'label'=>__("Hide the PDF download button/link on purchase order email", 'event-tickets-with-ticket-scanner'), 'desc'=>__("If active, it will not display the PDF download option for a single ticket on the purchase email to the client. But the PDF can still be generated with the URL.", 'event-tickets-with-ticket-scanner'), 'type'=>"checkbox", 'def'=>"", '_doc_video'=>'https://youtu.be/8ZttYE1RFWY'];
		$options[] = ['key'=>'wcTicketDontDisplayDetailLinkOnMail', 'label'=>__("Hide the ticket detail page link on purchase order email", 'event-tickets-with-ticket-scanner'), 'desc'=>__("If active, it will not display the URWeL to the ticket detail page on the purchase email to the client.", 'event-tickets-with-ticket-scanner'), 'type'=>"checkbox", 'def'=>"", '_doc_video'=>'https://youtu.be/yIiiI3qRKWY'];
		$options[] = ['key'=>'wcTicketLabelPDFDownloadHeading', 'label'=>__("Heading for the Ticket Download section within the purchase order email", 'event-tickets-with-ticket-scanner'), 'desc'=>__("If left empty, default will be 'Download Tickets' as the heading for the section below the order details table.", 'event-tickets-with-ticket-scanner'), 'type'=>"text", 'def'=>__("Download Tickets", 'event-tickets-with-ticket-scanner'), '_doc_video'=>'https://youtu.be/9B0X8OunLyE', '_do_not_trim'=>true];
		$options[] = ['key'=>'wcTicketLabelPDFDownload', 'label'=>__("Text that will be added as the PDF Ticket download label", 'event-tickets-with-ticket-scanner'), 'desc'=>__("If left empty, default will be 'Download PDF Ticket' on the button and on the link within the purchase email.", 'event-tickets-with-ticket-scanner'), 'type'=>"text", 'def'=>__("Download PDF Ticket", 'event-tickets-with-ticket-scanner'), '_doc_video'=>'https://youtu.be/TDo86oywJpw'];
		$options[] = ['key'=>'wcTicketLabelOrderDetailView', 'label'=>__("Text that will be added as the Order Ticket detail view label", 'event-tickets-with-ticket-scanner'), 'desc'=>__("If left empty, default will be 'Open Tickets' on the link within the purchase email.", 'event-tickets-with-ticket-scanner'), 'type'=>"text", 'def'=>__("Open Tickets", 'event-tickets-with-ticket-scanner'), '_doc_video'=>'https://youtu.be/p2OslJXaOQk'];
		$options[] = ['key'=>'wcTicketSetOrderToCompleteIfAllOrderItemsAreTickets', 'label'=>__("Set the order automatically to completed, if all purchased products are tickets", 'event-tickets-with-ticket-scanner'), 'desc'=>__("If active and all items of the order are tickets, then it will set the order status to completed if the order status is 'processing' and all purchased items in the order are tickets.", 'event-tickets-with-ticket-scanner'), 'type'=>"checkbox", 'def'=>"", '_doc_video'=>'https://youtu.be/roFt6yf7V6Y'];
		$options[] = ['key'=>'wcTicketHideTicketAfterEventEnd', 'label'=>__("Hide ticket product after the event", 'event-tickets-with-ticket-scanner'), 'desc'=>__("If active, then the plugin will search, once per day at 0:05, all ticket products that are public. Checks if the event date is set and expireed, and then set it to 'hidden' if so. <b>Important: this is not working for day chooser tickets date, where your customer can select the event date! The system will use the end date, that is set on the end date value, to hide the product.</b>", 'event-tickets-with-ticket-scanner'), 'type'=>"checkbox", 'def'=>"", '_doc_video'=>'https://youtu.be/vKQjVjfUnGc'];
		$options[] = ['key'=>'wcTicketLabelCartForName', 'label'=>__("Label for error message on cart for missing text value", 'event-tickets-with-ticket-scanner'), 'desc'=>__("You can use the placeholder {PRODUCT_NAME} for the product name. If left empty, default will be 'The product {PRODUCT_NAME} requires a value for checkout.' as the error message on the cart.", 'event-tickets-with-ticket-scanner'), 'type'=>"text", 'def'=>__('The product "{PRODUCT_NAME}" requires a value for checkout.', 'event-tickets-with-ticket-scanner')];
		$options[] = ['key'=>'wcTicketLabelCartForValue', 'label'=>__("Label for error message on cart for not choosen dropdown value", 'event-tickets-with-ticket-scanner'), 'desc'=>__("You can use the placeholder {PRODUCT_NAME} for the product name. If left empty, default will be 'The product {PRODUCT_NAME} requires a value from the dropdown for checkout.' as the error message on the cart.", 'event-tickets-with-ticket-scanner'), 'type'=>"text", 'def'=>__('The product "{PRODUCT_NAME}" requires a value from the dropdown for checkout.', 'event-tickets-with-ticket-scanner')];
		$options[] = ['key'=>'wcTicketLabelCartForDaychooser', 'label'=>__("Label for error message on cart for not choosen a date", 'event-tickets-with-ticket-scanner'), 'desc'=>__("You can use the placeholder {PRODUCT_NAME} and {count} for the product name. If left empty, default will be 'The product {PRODUCT_NAME} requires a value from the dropdown for checkout.' as the error message on the cart.", 'event-tickets-with-ticket-scanner'), 'type'=>"text", 'def'=>__('The product "{PRODUCT_NAME}" requires a valid date.', 'event-tickets-with-ticket-scanner')];
		$options[] = ['key'=>'wcTicketLabelCartForDaychooserInvalidDate', 'label'=>__("Label for error message on cart for wrong date", 'event-tickets-with-ticket-scanner'), 'desc'=>__("You can use the placeholder {PRODUCT_NAME} and {count} for the product name. If left empty, default will be 'The product {PRODUCT_NAME} requires a valid date.' as the error message on the cart.", 'event-tickets-with-ticket-scanner'), 'type'=>"text", 'def'=>__('The product "{PRODUCT_NAME}" requires a valid date.', 'event-tickets-with-ticket-scanner')];
		$options[] = ['key'=>'wcTicketLabelCartForDaychooserPassedDate', 'label'=>__("Label for error message on cart if the date is in the past", 'event-tickets-with-ticket-scanner'), 'desc'=>__("You can use the placeholder {PRODUCT_NAME} and {count} for the product name. If left empty, default will be 'The product {PRODUCT_NAME} requires a date from today or in the future.' as the error message on the cart.", 'event-tickets-with-ticket-scanner'), 'type'=>"text", 'def'=>__('The product {PRODUCT_NAME} requires a date from today or in the future.', 'event-tickets-with-ticket-scanner')];

		$options[] = [
			'key'=>'h12b2',
			'label'=>__("Ticket PDF settings", 'event-tickets-with-ticket-scanner'),
			'desc'=>"",
			'type'=>"heading"
			];
		$options[] = ['key'=>'wcTicketPDFFontSize', 'label'=>__("Font size for text on the ticket PDF", 'event-tickets-with-ticket-scanner'), 'desc'=>__("Please choose a font size between 6pt and 16pt.", 'event-tickets-with-ticket-scanner'), 'type'=>"dropdown", 'def'=>10, "additional"=>[ "values"=>[["label"=>"6pt", "value"=>6], ["label"=>"7pt", "value"=>7], ["label"=>"8pt", "value"=>8], ["label"=>"9pt", "value"=>9], ["label"=>"10pt", "value"=>10], ["label"=>"11pt", "value"=>11], ["label"=>"12pt", "value"=>12], ["label"=>"13pt", "value"=>13], ["label"=>"14pt", "value"=>14], ["label"=>"15pt", "value"=>15], ["label"=>"16pt", "value"=>16]]], '_doc_video'=>'https://youtu.be/dhdPDE_zuwY'];

		$font_families = $this->MAIN->getNewPDFObject()->getPossibleFontFamiles();
		$font_infos = $this->MAIN->getNewPDFObject()->getFontInfos();
		$font_def = $font_families["default"];
		$additional = [ "values"=>[] ];
		sort($font_families["fonts"]);
		foreach($font_families["fonts"] as $font) {
			$label = ["label"=>$font, "value"=>$font];
			if (isset($font_infos[$font]) && isset($font_infos[$font]['name'])) {
				$label['label'] .= " (".$font_infos[$font]['name'].")";
			}
			if (isset($font_infos[$font]) && isset($font_infos[$font]['lang_support']) && !empty($font_infos[$font]['lang_support'])) {
				$label['label'] .= " - ".$font_infos[$font]['lang_support'];
			}
			$additional['values'][] = $label;
		}
		$options[] = ['key'=>'wcTicketPDFFontFamily', 'label'=>__("Font family for text on the ticket PDF", 'event-tickets-with-ticket-scanner'), 'desc'=>__("If you need special characters you might change the font.", 'event-tickets-with-ticket-scanner'), 'type'=>"dropdown", 'def'=>$font_def, "additional"=>$additional, '_doc_video'=>'https://youtu.be/e-8tS_kv3SU' ];
		$options[] = ['key'=>'wcTicketPDFStripHTML', 'label'=>__("Strip HTML from text", 'event-tickets-with-ticket-scanner'), 'desc'=>__("If you experience issues with the rendered PDF, then you can change the settings here to strip some not garanteed supported elements or choose even to display the HTML code (helps for debug purpose).", 'event-tickets-with-ticket-scanner'), 'type'=>"dropdown", 'def'=>2, "additional"=>[ "values"=>[["label"=>__("No HTML strip", 'event-tickets-with-ticket-scanner'), "value"=>1], ["label"=>__("Remove unsupported HTML (default)", 'event-tickets-with-ticket-scanner'), "value"=>2], ["label"=>__("Show HTML Tags as text (Debugging)", 'event-tickets-with-ticket-scanner'), "value"=>3]]], '_doc_video'=>'https://youtu.be/nLKu9cxH95w' ];
		$options[] = ['key'=>'wcTicketPDFDisplayVariantName', 'label'=>__("Display product variant name", 'event-tickets-with-ticket-scanner'), 'desc'=>__("If active, the variant name(s) will be display below the title without its variant id. Just the variant value. If more than one variant is choosen, then the delimiter will be a blank space.", 'event-tickets-with-ticket-scanner'), 'type'=>"checkbox", '_doc_video'=>'https://youtu.be/mGIgFK_tpH0' ];
		$options[] = ['key'=>'wcTicketDisplayShortDesc', 'label'=>__("Display the short description of the product on the ticket", 'event-tickets-with-ticket-scanner'), 'desc'=>__("If active, it will be printed on the ticket detail view.", 'event-tickets-with-ticket-scanner'), 'type'=>"checkbox", 'def'=>"", 'additional'=>[], '_doc_video'=>'https://youtu.be/5iawZi4_KOk'];
		$options[] = ['key'=>'wcTicketDisplayCustomerNote', 'label'=>__("Display the customer note of the order on the ticket", 'event-tickets-with-ticket-scanner'), 'desc'=>__("If active, it will be printed on the ticket detail view.", 'event-tickets-with-ticket-scanner'), 'type'=>"checkbox", 'def'=>"", 'additional'=>[], '_doc_video'=>'https://youtu.be/muzZWYo0eaE'];
		$options[] = ['key'=>'wcTicketDontDisplayCustomer', 'label'=>__("Hide the customer name and address on the ticket", 'event-tickets-with-ticket-scanner'), 'desc'=>__("If active, it will not print the customer information on the ticket detail view.", 'event-tickets-with-ticket-scanner'), 'type'=>"checkbox", 'def'=>"", 'additional'=>[], '_doc_video'=>'https://youtu.be/wkDLm421pQ8'];
		$options[] = ['key'=>'wcTicketDontDisplayPayment', 'label'=>__("Hide the payment method on the ticket", 'event-tickets-with-ticket-scanner'), 'desc'=>__("If active, it will not print the payment details on the ticket detail view.", 'event-tickets-with-ticket-scanner'), 'type'=>"checkbox", 'def'=>"", 'additional'=>[], '_doc_video'=>'https://youtu.be/iLvQQ9BvSZI'];
		$options[] = ['key'=>'wcTicketDontDisplayPrice', 'label'=>__("Hide your ticket price.", 'event-tickets-with-ticket-scanner'), 'desc'=>__("If active, the ticket price will not be displayed on the ticket and the PDF ticket. The ticket scanner will still display the price.", 'event-tickets-with-ticket-scanner'), 'type'=>"checkbox", 'def'=>"", '_doc_video'=>'https://youtu.be/vV_1D0DgQ8g'];
		// is already defined on the payment options $options[] = ['key'=>'wcTicketDisplayUsedCouponCode', 'label'=>__("Display the used coupon code.", 'event-tickets-with-ticket-scanner'), 'desc'=>__("If active, the used coupon code will be added to the PDF ticket.", 'event-tickets-with-ticket-scanner'), 'type'=>"checkbox", 'def'=>""];
		$options[] = ['key'=>'wcTicketDisplayProductAddons', 'label'=>__('Display the add ons of the purchased items of the order on the ticket', 'event-tickets-with-ticket-scanner'), 'desc'=>__("If active, it will print all the add on products of the order on the ticket. You can use the woocommerce (from another plugin) function 'wc_product_addons_get_product_addons', if it exists, otherwise the default template will iterate over the meta property '_product_addons'.", 'event-tickets-with-ticket-scanner'), 'type'=>"checkbox", 'def'=>"", 'additional'=>[], '_doc_video'=>'https://youtu.be/UgUk591MxSc'];
		$options[] = ['key'=>'wcTicketDisplayPurchasedItemFromOrderOnTicket', 'label'=>__('Display the purchased items of the order on the ticket', 'event-tickets-with-ticket-scanner'), 'desc'=>__("If active, it will print all the products of the order on the ticket. The ticket product will be excluded from the list.", 'event-tickets-with-ticket-scanner'), 'type'=>"checkbox", 'def'=>"", 'additional'=>[]];
		$options[] = ['key'=>'wcTicketDisplayPurchasedTicketQuantity', 'label'=>__("Display the quantity of the purchased item on the ticket.", 'event-tickets-with-ticket-scanner'), 'desc'=>__("If active, it will print the amount of the purchased tickets on the ticket.", 'event-tickets-with-ticket-scanner'), 'type'=>"checkbox", 'def'=>"", 'additional'=>[], '_doc_video'=>'https://youtu.be/cvjU_AtCBlw'];
		$options[] = ['key'=>'wcTicketDisplayTicketListName', 'label'=>__("Display the ticket list name on the ticket.", 'event-tickets-with-ticket-scanner'), 'desc'=>__("If active, it will print the name of the ticket list.", 'event-tickets-with-ticket-scanner'), 'type'=>"checkbox", 'def'=>"", 'additional'=>[], '_doc_video'=>'https://youtu.be/txjtMQlTwQY'];
		$options[] = ['key'=>'wcTicketDisplayTicketListDesc', 'label'=>__("Display the ticket list description on the ticket.", 'event-tickets-with-ticket-scanner'), 'desc'=>__("If active, it will print the description of the ticket list on the ticket.", 'event-tickets-with-ticket-scanner'), 'type'=>"checkbox", 'def'=>"", 'additional'=>[], '_doc_video'=>'https://youtu.be/LpnQksmZm6w'];
		$options[] = ['key'=>'wcTicketPrefixTextTicketQuantity', 'label'=>__("Text that will be added to the PDF if the option <b>'Display the quantity of the purchased tickets'</b> is activated.", 'event-tickets-with-ticket-scanner'), 'desc'=>__("If left empty, default will be '{TICKET_POSITION} of {TICKET_TOTAL_AMOUNT} Tickets'. {TICKET_POSITION} will be replaced with the position within the quantity of the item purchase. {TICKET_TOTAL_AMOUNT} will be replaced with the quantity of the purchased tickets for the order.", 'event-tickets-with-ticket-scanner'), 'type'=>"text", 'def'=>__("{TICKET_POSITION} of {TICKET_TOTAL_AMOUNT} Tickets", 'event-tickets-with-ticket-scanner'), 'additional'=>[], 'isPublic'=>false, '_doc_video'=>'https://youtu.be/lBUQVkkMR90'];
		$options[] = ['key'=>'wcTicketDisplayTicketUserValue', 'label'=>__("Display the registered user value on the ticket.", 'event-tickets-with-ticket-scanner'), 'desc'=>__("If active, it will print the registered user value on the ticket. The value and the label for it are only displayed, if the registered user value is not empty.", 'event-tickets-with-ticket-scanner'), 'type'=>"checkbox", 'def'=>"", 'additional'=>[], '_doc_video'=>'https://youtu.be/z4aNIMfeJFU'];
		$options[] = ['key'=>'wcTicketDontDisplayBlogName', 'label'=>__("Hide your wordpress name", 'event-tickets-with-ticket-scanner'), 'desc'=>__("If active, it will not display the wordpress name.", 'event-tickets-with-ticket-scanner'), 'type'=>"checkbox", 'def'=>"", '_doc_video'=>'https://youtu.be/XG2NQaOZ9MQ'];
		$options[] = ['key'=>'wcTicketDontDisplayBlogDesc', 'label'=>__("Hide your blog description", 'event-tickets-with-ticket-scanner'), 'desc'=>__("If active, it will not display the wordpress description.", 'event-tickets-with-ticket-scanner'), 'type'=>"checkbox", 'def'=>"", '_doc_video'=>'https://youtu.be/qAjqxS0ju14'];
		$options[] = ['key'=>'wcTicketDontDisplayBlogURL', 'label'=>__("Hide your wordpress URL", 'event-tickets-with-ticket-scanner'), 'desc'=>__("If active, it will not display the wordpress URL.", 'event-tickets-with-ticket-scanner'), 'type'=>"checkbox", 'def'=>"", '_doc_video'=>'https://youtu.be/4-bT1REPGgY'];
		$options[] = ['key'=>'wcTicketAdditionalTextBottom', 'label'=>__("You can display additional text on the PDF ticket", 'event-tickets-with-ticket-scanner'), 'desc'=>__('If you enter text here, then it will be added to the PDF ticket at the bottom part. You can add some corporate details if needed.', 'event-tickets-with-ticket-scanner'), 'type'=>"textarea", 'def'=>"", "additional"=>["rows"=>5], '_doc_video'=>'https://youtu.be/abpt3we8g-A'];
		$options[] = ['key'=>'wcTicketTicketLogo', 'label'=>__("Display a small logo (max. 300x300px) at the bottom in the center", 'event-tickets-with-ticket-scanner'), 'desc'=>__("If a media file is chosen, the logo will be placed on the ticket PDF.", 'event-tickets-with-ticket-scanner'), 'type'=>"media", 'def'=>""
						, 'additional'=>[
							'max'=>['width'=>200,'height'=>200],
							'button'=>esc_attr__('Choose logo for the ticket PDF', 'event-tickets-with-ticket-scanner'),
							'msg_error'=>[
								'width'=>__('Too big! Choose an image with smaller size. Max 300px width, otherwise it will look not good on your ticket.', 'event-tickets-with-ticket-scanner')
							]
						], '_doc_video'=>'https://youtu.be/h73JTqf20og'
					];
		$options[] = ['key'=>'wcTicketTicketBanner', 'label'=>__("Display a banner image at the top of the PDF", 'event-tickets-with-ticket-scanner'), 'desc'=>__("If a media file is chosen, the banner will be placed on the ticket PDF.", 'event-tickets-with-ticket-scanner'), 'type'=>"media", 'def'=>""
						, 'additional'=>[
							'min'=>['width'=>600],
							'button'=>esc_attr__('Choose banner image for the ticket PDF', 'event-tickets-with-ticket-scanner'),
							'msg_error_min'=>[
								'width'=>__('Too small! Choose an image with bigger size. Min 600px width, otherwise it will look not good on your ticket.', 'event-tickets-with-ticket-scanner')
								]
							]
						, '_doc_video'=>'https://youtu.be/k75miUvm1cc'
					];
		$options[] = ['key'=>'wcTicketTicketBG', 'label'=>__("Display a background image at the center of the PDF", 'event-tickets-with-ticket-scanner'), 'desc'=>__("If a media file is chosen, the image will be placed on the ticket PDF.", 'event-tickets-with-ticket-scanner'), 'type'=>"media", 'def'=>""
						, 'additional'=>[
							'button'=>esc_attr__('Choose background image for the ticket PDF', 'event-tickets-with-ticket-scanner')
						]
						, '_doc_video'=>'https://youtu.be/o-avTDm8gKY'
					];
		$options[] = ['key'=>'wcTicketTicketAttachPDFOnTicket', 'label'=>__("Attach additional PDF to the PDF ticket", 'event-tickets-with-ticket-scanner'), 'desc'=>__("If a PDF file is chosen, the PDF will be attached to the PDF ticket.", 'event-tickets-with-ticket-scanner'), 'type'=>"media", 'def'=>""
						, 'additional'=>[
							'type_filter'=>'*',
							'button'=>esc_attr__('Choose PDF to be added to the ticket PDF', 'event-tickets-with-ticket-scanner')
							]
						, '_doc_video'=>'https://www.youtube.com/watch?v=YvpcNsfjNC8'
					];

		$options[] = ['key'=>'h16', 'label'=>__("Ticket Designer", 'event-tickets-with-ticket-scanner'), 'desc'=>__("You can design your ticket look & feel. You are able to preview your ticket design within the second ticket design textarea. This template will be used on the ticket detail view, ticket PDF", 'event-tickets-with-ticket-scanner'), 'type'=>"heading"];
		$options[] = ['key'=>'wcTicketTemplateUseDefault', 'label'=>__("Use the default template for the ticket", 'event-tickets-with-ticket-scanner'), 'desc'=>__("If active, then the ticket template code will not be used. Best for beginners, who do not want to adjust the ticket template code. If the ticket template code is empty, then it will also use the default template code.", 'event-tickets-with-ticket-scanner'), 'type'=>"checkbox", 'def'=>"", '_doc_video'=>'https://youtu.be/sV1L2MJtq8M'];
		$options[] = ['key'=>'h16_desc', 'label'=>__('The plugin is using the Twig template engine (3.7.1). This is a well documented tempklate engine that gives you a great freedom.<br><a target="_blank" href="https://twig.symfony.com/doc/3.x/">Open Documentation of Twig</a>', 'event-tickets-with-ticket-scanner'), 'desc'=>"You can use the following variables:<ul><li>PRODUCT</li><li>PRODUCT_PARENT</li><li>PRODUCT_ORIGINAL (in case you use WPML plugin, might be helpful - all the event tickets settings are on the original product)</li><li>PRODUCT_PARENT_ORIGINAL (in case you use WPML plugin, might be helpful - all the event tickets settings are on the original parent product - for variant/variable product)</li><li>OPTIONS</li><li>TICKET</li><li>ORDER</li><li>ORDER_ITEM</li><li>CODEOBJ</li><li>METAOBJ</li><li>LISTOBJ</li><li>LIST_METAOBJ</li><li>is_variation</li><li>forPDFOutput</li><li>isScanner</li><li>WPDB</li></ul>ACF support: you can use the function get_field to retrieve an ACF field value. You need to provide the product_id. e.g. {{ get_field('some_value', PRODUCT_PARENT.get_id)|escape }} or {{ get_field('some_value', PRODUCT_PARENT.get_id)|escape('wp_kses_post')|raw }} and so on.", 'type'=>"desc"];
		$options[] = ['key'=>'wcTicketPDFZeroMargin', 'label'=>__("Do not use padding within the PDF ticket", 'event-tickets-with-ticket-scanner'), 'desc'=>__("If active, then the PDF content will start directly from the beginning of the paper. You need to add your own padding and margin within the template.", 'event-tickets-with-ticket-scanner'), 'type'=>"checkbox", 'def'=>"", '_doc_video'=>'https://youtu.be/2Ek2qkjHNAY'];
		$options[] = ['key'=>'wcTicketPDFisRTL', 'label'=>__("BETA Use RTL for PDF", 'event-tickets-with-ticket-scanner'), 'desc'=>__("This feature is in Beta. This means, good results are not guaranteed, still optimizing this. If active, the PDF will be generated with RTL option active.", 'event-tickets-with-ticket-scanner'), 'type'=>"checkbox", 'def'=>"", '_doc_video'=>'https://youtu.be/7xmNgRmcrH0'];
		$options[] = ['key'=>'wcTicketSizeWidth', 'label'=>__('Size in mm for the width', 'event-tickets-with-ticket-scanner'), 'desc'=>__('Will be used to set the width of the PDF. If empty or zero or lower than 20, the default of 210 will be used.', 'event-tickets-with-ticket-scanner'), 'type'=>'number', 'def'=>210, "additional"=>["min"=>20], '_doc_video'=>'https://youtu.be/c2XtUY2l1OM'];
		$options[] = ['key'=>'wcTicketSizeHeight', 'label'=>__('Size in mm for the height', 'event-tickets-with-ticket-scanner'), 'desc'=>__('Will be used to set the height of the PDF. If empty or zero or lower than 20, the default of 297 will be used.', 'event-tickets-with-ticket-scanner'), 'type'=>'number', 'def'=>297, "additional"=>["min"=>20], '_doc_video'=>'https://youtu.be/c2XtUY2l1OM'];
		$options[] = ['key'=>'wcTicketQRSize', 'label'=>__('Size for the QR code image on the PDF', 'event-tickets-with-ticket-scanner'), 'desc'=>__('Will be used to set the width and height of the QR code image on the PDF ticket. If empty or zero, the default of 50 will be used.', 'event-tickets-with-ticket-scanner'), 'type'=>'number', 'def'=>50, "additional"=>["min"=>0], '_doc_video'=>'https://youtu.be/c2XtUY2l1OM'];
		$options[] = ['key'=>'wcTicketDesignerTemplate', 'label'=>__("The TWIG HTML value for the ticket. Use <b>{QRCODE_INLINE}</b> to place the QR-Code anywhere", 'event-tickets-with-ticket-scanner'), 'desc'=>__('If left empty, default will be used. Check out this additional information about how you could use it: <a href="https://vollstart.com/posts/events/documentation/option-wcticketdesignertemplate/" target="_blank">Option Documentation</a>', 'event-tickets-with-ticket-scanner'), 'type'=>"textarea", 'def'=>$this->MAIN->getTicketDesignerHandler()->getDefaultTemplate(), "additional"=>["rows"=>30], '_doc_video'=>'https://youtu.be/aAfZIwFE7Zk'];

		$options[] = ['key'=>'h16a', 'label'=>__("Ticket Designer Test", 'event-tickets-with-ticket-scanner'), 'desc'=>"", 'type'=>"heading"];
		$options[] = ['key'=>'wcTicketPDFZeroMarginTest', 'label'=>__("Do not use padding within the <b>test</b> PDF ticket", 'event-tickets-with-ticket-scanner'), 'desc'=>__("If active, then the PDF content will start directly from the beginning of the paper. You need to add your own padding and margin within the template.", 'event-tickets-with-ticket-scanner'), 'type'=>"checkbox", 'def'=>"", '_doc_video'=>'https://youtu.be/jewIPLsu5nw'];
		$options[] = ['key'=>'wcTicketPDFisRTLTest', 'label'=>__("BETA Use RTL for PDF <b>test</b>", 'event-tickets-with-ticket-scanner'), 'desc'=>__("This feature is in Beta. This means, good results are not guaranteed, still optimizing this. If active, the PDF will be generated with RTL option active.", 'event-tickets-with-ticket-scanner'), 'type'=>"checkbox", 'def'=>""];
		$options[] = ['key'=>'wcTicketSizeWidthTest', 'label'=>__('Size in mm for the width of the <b>test</b>', 'event-tickets-with-ticket-scanner'), 'desc'=>__('Will be used to set the width of the PDF. If empty or zero, the default of 80 will be used.', 'event-tickets-with-ticket-scanner'), 'type'=>'number', 'def'=>210, "additional"=>["min"=>20], '_doc_video'=>'https://youtu.be/ylgo0rvn9SA'];
		$options[] = ['key'=>'wcTicketSizeHeightTest', 'label'=>__('Size in mm for the height of the <b>test</b>', 'event-tickets-with-ticket-scanner'), 'desc'=>__('Will be used to set the height of the PDF. If empty or zero, the default of 120 will be used.', 'event-tickets-with-ticket-scanner'), 'type'=>'number', 'def'=>297, "additional"=>["min"=>20], '_doc_video'=>'https://youtu.be/ylgo0rvn9SA'];
		$options[] = ['key'=>'wcTicketQRSizeTest', 'label'=>__('Size for the QR code image on the <b>test PDF</b>', 'event-tickets-with-ticket-scanner'), 'desc'=>__('Will be used to set the width and height of the QR code image on the PDF ticket. If empty or zero, the default of 50 will be used.', 'event-tickets-with-ticket-scanner'), 'type'=>'number', 'def'=>50, "additional"=>["min"=>0], '_doc_video'=>'https://youtu.be/ylgo0rvn9SA'];
		$options[] = ['key'=>'wcTicketDesignerTemplateTest', 'label'=>__("The template screen <b>test code</b> - TWIG HTML value for the testing the ticket. Use <b>{QRCODE_INLINE}</b> to place the QR-Code anywhere", 'event-tickets-with-ticket-scanner'), 'desc'=>__('Only for administrator role. Within the admin ticket detail view, you can start the ticket detail page to view this template code. The easiest way is to open the preview in another browser window. Everytime you did a change and clicked out of the textarea, you can reload your ticket preview browser window to see the changes in effect. If you leave it empty, then the default template will be used.', 'event-tickets-with-ticket-scanner'), 'type'=>"editor", 'def'=>"", "additional"=>["rows"=>30,"height"=>"500px"], '_doc_video'=>'https://youtu.be/y5mp7JLTLgo'];

		$options[] = [
			'key'=>'h12b1',
			'label'=>__("Ticket Translations", 'event-tickets-with-ticket-scanner'),
			'desc'=>'<a href="https://youtu.be/7ifrGSGTz3E" target="_blank">Video Explainer Part1</a> and <a href="https://youtu.be/SmrdxjwRocY" target="_blank">Video Explainer Part2</a>',
			'type'=>"heading",
			'_doc_video'=>''
			];
		$options[] = ['key'=>'wcTicketHeading', 'label'=>__("Ticket title", 'event-tickets-with-ticket-scanner'), 'desc'=>__("This is the title of the ticket", 'event-tickets-with-ticket-scanner'), 'type'=>"text", 'def'=>__("Ticket", 'event-tickets-with-ticket-scanner')];
		$options[] = ['key'=>'wcTicketTransExpired', 'label'=>__("Label 'EXPIRED' on the event date", 'event-tickets-with-ticket-scanner'), 'desc'=>"", 'type'=>"text", 'def'=>__("EXPIRED", 'event-tickets-with-ticket-scanner')];
		$options[] = ['key'=>'wcTicketTransLocation', 'label'=>__("Label 'Location' heading on for the event location", 'event-tickets-with-ticket-scanner'), 'desc'=>"", 'type'=>"text", 'def'=>__("Location", 'event-tickets-with-ticket-scanner')];
		$options[] = ['key'=>'wcTicketTransCustomer', 'label'=>__("Label 'Customer' heading on the customer details", 'event-tickets-with-ticket-scanner'), 'desc'=>"", 'type'=>"text", 'def'=>__("Customer", 'event-tickets-with-ticket-scanner')];
		$options[] = ['key'=>'wcTicketTransPaymentDetail', 'label'=>__("Label 'Payment details' heading on the payment details", 'event-tickets-with-ticket-scanner'), 'desc'=>"", 'type'=>"text", 'def'=>__("Payment details", 'event-tickets-with-ticket-scanner')];
		$options[] = ['key'=>'wcTicketTransPaymentDetailPaidAt', 'label'=>__("Label 'Order paid at' on the payment details", 'event-tickets-with-ticket-scanner'), 'desc'=>"", 'type'=>"text", 'def'=>__("Order paid at:", 'event-tickets-with-ticket-scanner')];
		$options[] = ['key'=>'wcTicketTransPaymentDetailCompletedAt', 'label'=>__("Label 'Order completed at' on the payment details", 'event-tickets-with-ticket-scanner'), 'desc'=>"", 'type'=>"text", 'def'=>__("Order completed at:", 'event-tickets-with-ticket-scanner')];
		$options[] = ['key'=>'wcTicketTransPaymentDetailPaidVia', 'label'=>__("Label 'Paid via' on the payment details", 'event-tickets-with-ticket-scanner'), 'desc'=>"", 'type'=>"text", 'def'=>__("Paid via:", 'event-tickets-with-ticket-scanner')];
		$options[] = ['key'=>'wcTicketTransPaymentDetailFreeTicket', 'label'=>__("Label 'Free ticket' on the payment details", 'event-tickets-with-ticket-scanner'), 'desc'=>"", 'type'=>"text", 'def'=>__("Free ticket", 'event-tickets-with-ticket-scanner')];
		$options[] = ['key'=>'wcTicketTransPaymentDetailCouponUsed', 'label'=>__("Label 'Coupon used' on the payment details", 'event-tickets-with-ticket-scanner'), 'desc'=>__("It will display which coupon was used.", 'event-tickets-with-ticket-scanner'), 'type'=>"text", 'def'=>__("Coupon used:", 'event-tickets-with-ticket-scanner')];
		$options[] = ['key'=>'wcTicketTransTicket', 'label'=>__("Label 'Ticket' for the ticket number", 'event-tickets-with-ticket-scanner'), 'desc'=>"", 'type'=>"text", 'def'=>__("Ticket:", 'event-tickets-with-ticket-scanner')];
		$options[] = ['key'=>'wcTicketTransPrice', 'label'=>__("Label 'Price' for the paid price", 'event-tickets-with-ticket-scanner'), 'desc'=>"", 'type'=>"text", 'def'=>__("Price:", 'event-tickets-with-ticket-scanner')];
		$options[] = ['key'=>'wcTicketTransProductPrice', 'label'=>__("Label 'Original price' for the ticket number", 'event-tickets-with-ticket-scanner'), 'desc'=>"", 'type'=>"text", 'def'=>__("Original price:", 'event-tickets-with-ticket-scanner')];
		$options[] = ['key'=>'wcTicketTransTicketRedeemed', 'label'=>__("Label 'Ticket redeemed' for the customer notice", 'event-tickets-with-ticket-scanner'), 'desc'=>"", 'type'=>"text", 'def'=>__("Ticket redeemed", 'event-tickets-with-ticket-scanner')];
		$options[] = ['key'=>'wcTicketTransRedeemDate', 'label'=>__("Label 'Redeemed at' for the customer notice", 'event-tickets-with-ticket-scanner'), 'desc'=>"", 'type'=>"text", 'def'=>__("Last Redeemed:", 'event-tickets-with-ticket-scanner')];
		$options[] = ['key'=>'wcTicketTransTicketValid', 'label'=>__("Label 'Ticket valid' for the customer notice", 'event-tickets-with-ticket-scanner'), 'desc'=>"", 'type'=>"text", 'def'=>__("Ticket valid", 'event-tickets-with-ticket-scanner')];
		$options[] = ['key'=>'wcTicketTransRefreshPage', 'label'=>__("Label 'Refresh page' for the button", 'event-tickets-with-ticket-scanner'), 'desc'=>"", 'type'=>"text", 'def'=>__("Refresh page", 'event-tickets-with-ticket-scanner')];
		$options[] = ['key'=>'wcTicketTransRedeemQuestion', 'label'=>__("Label 'Do you want to redeem the ticket?' for the question to your client", 'event-tickets-with-ticket-scanner'), 'desc'=>"", 'type'=>"text", 'def'=>__("Do you want to redeem the ticket? Typically this is done at the entrance. This will mark this ticket as redeemed.", 'event-tickets-with-ticket-scanner')];
		$options[] = ['key'=>'wcTicketTransBtnRedeemTicket', 'label'=>sprintf(/* translators: %s: default value */__("Label '%s' for the button to your client", 'event-tickets-with-ticket-scanner'), __("Redeem Ticket", 'event-tickets-with-ticket-scanner')), 'desc'=>"", 'type'=>"text", 'def'=>__("Redeem Ticket", 'event-tickets-with-ticket-scanner')];
		$options[] = ['key'=>'wcTicketTransTicketExpired', 'label'=>sprintf(/* translators: %s: default value */__("Label Error '%s' for the customer notice", 'event-tickets-with-ticket-scanner'), __("Ticket expired", 'event-tickets-with-ticket-scanner')), 'desc'=>"", 'type'=>"text", 'def'=>__("Ticket expired", 'event-tickets-with-ticket-scanner')];
		$options[] = ['key'=>'wcTicketTransTicketIsStolen', 'label'=>__("Label Error 'Ticket is STOLEN' for the customer notice", 'event-tickets-with-ticket-scanner'), 'desc'=>"", 'type'=>"text", 'def'=>__("Ticket is STOLEN", 'event-tickets-with-ticket-scanner')];
		$options[] = ['key'=>'wcTicketTransTicketNotValid', 'label'=>__("Label Error 'Ticket is not valid' for the customer notice", 'event-tickets-with-ticket-scanner'), 'desc'=>"", 'type'=>"text", 'def'=>__("Ticket is not valid", 'event-tickets-with-ticket-scanner')];
		$options[] = ['key'=>'wcTicketTransTicketNumberWrong', 'label'=>__("Label Error 'Ticket number is wrong' for the customer notice", 'event-tickets-with-ticket-scanner'), 'desc'=>"", 'type'=>"text", 'def'=>__("Ticket number is wrong", 'event-tickets-with-ticket-scanner')];
		$options[] = ['key'=>'wcTicketTransRedeemMaxAmount', 'label'=>__("Text for max redeem amount for the customer notice on the PDF ticket", 'event-tickets-with-ticket-scanner'), 'desc'=>sprintf(/* translators: %s: max amount ticket redeem */__("This text will be added to the PDF ticket only if the ticket can be redeemed more than one time! Use the placeholder %s to display the amount.", 'event-tickets-with-ticket-scanner'), '{MAX_REDEEM_AMOUNT}'), 'type'=>"text", 'def'=>sprintf(/* translators: %s: max amount ticket redeem */__("You can redeem this ticket <b>%s times</b> within the valid period.", 'event-tickets-with-ticket-scanner'), '{MAX_REDEEM_AMOUNT}')];
		$options[] = ['key'=>'wcTicketTransRedeemedAmount', 'label'=>__("Text for redeemed amount for the customer notice on the ticket", 'event-tickets-with-ticket-scanner'), 'desc'=>sprintf(/* translators: 1: amount redeemed ticket 2: max amount ticket redeem */__('This text will be added to the ticket scanner and ticket detail page view. Only if the ticket can be redeemed more than one time! Use the placeholders %1$s and %2$s and to display the amounts.', 'event-tickets-with-ticket-scanner'), '{REDEEMED_AMOUNT}', '{MAX_REDEEM_AMOUNT}'), 'type'=>"text", 'def'=>sprintf(/* translators: 1: amount redeemed ticket 2: max amount ticket redeem */__('You have used this ticket %1$s of %2$s.', 'event-tickets-with-ticket-scanner'), '{REDEEMED_AMOUNT}', '{MAX_REDEEM_AMOUNT}')];
		$options[] = ['key'=>'wcTicketTransTicketNotValidToEarly', 'label'=>__("Label Error 'Event did not started yet' for the customer notice", 'event-tickets-with-ticket-scanner'), 'desc'=>"Will be shown on the ticket scanner, if the ticket is too early scanned.", 'type'=>"text", 'def'=>__("Event did not started yet", 'event-tickets-with-ticket-scanner')];
		$options[] = ['key'=>'wcTicketTransTicketNotValidToLate', 'label'=>__("Label Error 'Too late. Event started already' for the ticket scanner", 'event-tickets-with-ticket-scanner'), 'desc'=>"Will be shown on the ticket scanner, if the ticket is too late scanned.", 'type'=>"text", 'def'=>__("Too late. Event started already", 'event-tickets-with-ticket-scanner')];
		$options[] = ['key'=>'wcTicketTransTicketNotValidToLateEndEvent', 'label'=>__("Label Error 'Too late. Event ended already' for the ticket scanner", 'event-tickets-with-ticket-scanner'), 'desc'=>"Will be shown on the ticket scanner, if the ticket is too late scanned.", 'type'=>"text", 'def'=>__("Too late. Event ended already", 'event-tickets-with-ticket-scanner')];
		$options[] = ['key'=>'wcTicketTransDisplayTicketUserValue', 'label'=>__("Label User registered value on the ticket", 'event-tickets-with-ticket-scanner'), 'desc'=>"Will be shown on the ticket, if the corresponding ticket option is activated and the registered user value is not empty.", 'type'=>"text", 'def'=>__("User value:", 'event-tickets-with-ticket-scanner')];

		$badgeHTMLDefault = $this->MAIN->getTicketBadgeHandler()->getDefaultTemplate();
		$desc = $this->MAIN->getTicketBadgeHandler()->getReplacementTagsExplanation();
		$options[] = ['key'=>'h15', 'label'=>__("Ticket Badge", 'event-tickets-with-ticket-scanner'), 'desc'=>__("You can download a badge for each ticket. This badge can be give to your customer so they can wear it as a name badge. You can download the badge PDF within the ticket detail view.", 'event-tickets-with-ticket-scanner'), 'type'=>"heading"];
		$options[] = ['key'=>'wcTicketBadgeDisplayButtonOnDetail', 'label'=>__("Show ticket badge download button on ticket detail page", 'event-tickets-with-ticket-scanner'), 'desc'=>__("If active, it will display the ticket badge file download button on the ticket detail view.", 'event-tickets-with-ticket-scanner'), 'type'=>"checkbox", 'def'=>"", '_doc_video'=>'https://youtu.be/w0F14PVbWig'];
		$options[] = ['key'=>'wcTicketBadgeLabelDownload', 'label'=>__("Text that will be added as the ticket badge file download label", 'event-tickets-with-ticket-scanner'), 'desc'=>sprintf(/* translators: %s: default value */__('If left empty, default will be "%s"', 'event-tickets-with-ticket-scanner'), __("Download ticket badge", 'event-tickets-with-ticket-scanner')), 'type'=>"text", 'def'=>__("Download ticket badge", 'event-tickets-with-ticket-scanner'), '_doc_video'=>'https://youtu.be/w0F14PVbWig'];
		$options[] = ['key'=>'wcTicketBadgeAttachLinkToMail', 'label'=>__("Attach the ticket badge download link to the WooCommerce mails", 'event-tickets-with-ticket-scanner'), 'desc'=>__("If active, the ticket badge download link will be added to the mails.", 'event-tickets-with-ticket-scanner'), 'type'=>"checkbox", 'def'=>"", '_doc_video'=>'https://youtu.be/S-s6MG50ccA'];
		$options[] = ['key'=>'wcTicketBadgeAttachFileToMail', 'label'=>__("Attach the ticket badge file to the WooCommerce mails", 'event-tickets-with-ticket-scanner'), 'desc'=>__("If active, the ticket badge file will be added as an attachment to the mails.", 'event-tickets-with-ticket-scanner'), 'type'=>"checkbox", 'def'=>"", '_doc_video'=>'https://youtu.be/gzmFvj30wmY'];
		$options[] = ['key'=>'wcTicketBadgeAttachFileToMailAsOnePDF', 'label'=>__("Attach all ticket badges of an order to the WooCommerce mails as one PDF", 'event-tickets-with-ticket-scanner'), 'desc'=>__("If active, the ticket badge files are merged into one PDF and will be added as an attachment to the mails.", 'event-tickets-with-ticket-scanner'), 'type'=>"checkbox", 'def'=>"", '_doc_video'=>'https://youtu.be/MVwIATKAKJw'];
		$options[] = ['key'=>'wcTicketBadgePDFisRTL', 'label'=>__("BETA Use RTL for PDF", 'event-tickets-with-ticket-scanner'), 'desc'=>__("This feature is in Beta. This means, good results are not guaranteed, still optimizing this. If active, the PDF will be generated with RTL option active.", 'event-tickets-with-ticket-scanner'), 'type'=>"checkbox", 'def'=>""];
		$options[] = ['key'=>'wcTicketBadgeSizeWidth', 'label'=>__('Size in mm for the width', 'event-tickets-with-ticket-scanner'), 'desc'=>__('Will be used to set the width of the PDF for the badge. If empty or zero, the default of 80 will be used.', 'event-tickets-with-ticket-scanner'), 'type'=>'number', 'def'=>80, "additional"=>["min"=>20], '_doc_video'=>'https://youtu.be/dbgyRvkmXL0'];
		$options[] = ['key'=>'wcTicketBadgeSizeHeight', 'label'=>__('Size in mm for the height', 'event-tickets-with-ticket-scanner'), 'desc'=>__('Will be used to set the height of the PDF for the badge. If empty or zero, the default of 120 will be used.', 'event-tickets-with-ticket-scanner'), 'type'=>'number', 'def'=>120, "additional"=>["min"=>20], '_doc_video'=>'https://youtu.be/dbgyRvkmXL0'];
		$options[] = ['key'=>'wcTicketBadgeQRSize', 'label'=>__('Size for the QR code image on the PDF', 'event-tickets-with-ticket-scanner'), 'desc'=>__('Will be used to set the width and height of the QR code image on the PDF ticket. If empty or zero, the default of 50 will be used.', 'event-tickets-with-ticket-scanner'), 'type'=>'number', 'def'=>50, "additional"=>["min"=>0], '_doc_video'=>'https://youtu.be/dbgyRvkmXL0'];
		$options[] = ['key'=>'wcTicketBadgeBG', 'label'=>__("Display a background image image at the center of the PDF.", 'event-tickets-with-ticket-scanner'), 'desc'=>__("If a media file is choosen, the image will be placed on the ticket flyer.", 'event-tickets-with-ticket-scanner'), 'type'=>"media", 'def'=>""
			, 'additional'=>[
				'button'=>esc_attr__('Choose background image for the ticket badge', 'event-tickets-with-ticket-scanner')
			]
			, '_doc_video'=>'https://youtu.be/Lzz34dWWvWI'
		];
		$options[] = ['key'=>'wcTicketBadgeText', 'label'=>__("The HTML value for the PDF", 'event-tickets-with-ticket-scanner'), 'desc'=>__('If left empty, default will be used.', 'event-tickets-with-ticket-scanner'), 'type'=>"textarea", 'def'=>$badgeHTMLDefault, "additional"=>["rows"=>10], '_doc_video'=>'https://youtu.be/xmn1t8QPxwQ'];
		$options[] = ['key'=>'h15_desc', 'label'=>__("Possible Tags", 'event-tickets-with-ticket-scanner'), 'desc'=>$desc, 'type'=>"desc"];

		$options[] = ['key'=>'h12d', 'label'=>__("Calendar file (ICS)", 'event-tickets-with-ticket-scanner'), 'desc'=>__("The ICS calendar file will cointain the event info and date (if added). This allows your customer to add the event easily from within the email to their calendar. Will work on most mail client.", 'event-tickets-with-ticket-scanner'), 'type'=>"heading"];
		$options[] = ['key'=>'wcTicketDontDisplayICSButtonOnDetail', 'label'=>__("Hide the ICS calendar file download button on ticket detail page", 'event-tickets-with-ticket-scanner'), 'desc'=>__("If active, it will not display the calendar file download button on the ticket detail view. It will be only shown if the ticket product has a starting date.", 'event-tickets-with-ticket-scanner'), 'type'=>"checkbox", 'def'=>"", '_doc_video'=>'https://youtu.be/ieTQLrQqd2U'];
		$options[] = ['key'=>'wcTicketLabelICSDownload', 'label'=>__("Text that will be added as the ICS calendar file download label", 'event-tickets-with-ticket-scanner'), 'desc'=>sprintf(/* translators: %s: default value */__('If left empty, default will be "%s"', 'event-tickets-with-ticket-scanner'), __("Download calendar file", 'event-tickets-with-ticket-scanner')), 'type'=>"text", 'def'=>__("Download calendar file", 'event-tickets-with-ticket-scanner'), '_doc_video'=>'https://youtu.be/4Xs7XlqZbcQ'];
		$options[] = ['key'=>'wcTicketAttachICSToMail', 'label'=>__("Attach the ICS calendar file to the WooCommerce mails", 'event-tickets-with-ticket-scanner'), 'desc'=>__("If active, the ICS calendar file will be added as an attachment to the mails (order complete, customer note, customer invoice and processing order)", 'event-tickets-with-ticket-scanner'), 'type'=>"checkbox", 'def'=>"", '_doc_video'=>'https://youtu.be/H6RUH4B3KJk'];
		$options[] = ['key'=>'wcTicketDisplayDateOnMail', 'label'=>__("Show the event date on purchase order email", 'event-tickets-with-ticket-scanner'), 'desc'=>__("If active and a date is set on the product, then it will display the date of the event on the purchase email to the client.", 'event-tickets-with-ticket-scanner'), 'type'=>"checkbox", 'def'=>"", '_doc_video'=>'https://youtu.be/B8CqwD1XUSY'];
		$options[] = ['key'=>'wcTicketDisplayDateOnPrdDetail', 'label'=>__("Show the event date on the product detail page for your customer", 'event-tickets-with-ticket-scanner'), 'desc'=>__("If active and a date is set on the product, then it will display the date of the event on the product detail page to the client.", 'event-tickets-with-ticket-scanner'), 'type'=>"checkbox", 'def'=>"", '_doc_video'=>'https://youtu.be/uIk49qlTMIg'];
		$options[] = ['key'=>'wcTicketHideDateOnPDF', 'label'=>__("Hide the event date on the ticket", 'event-tickets-with-ticket-scanner'), 'desc'=>__("If active the event date is not shown on the ticket.", 'event-tickets-with-ticket-scanner'), 'type'=>"checkbox", 'def'=>"", '_doc_video'=>'https://youtu.be/Vbj4pTx-Z4o'];
		$options[] = ['key'=>'wcTicketICSOrganizerEmail', 'label'=>__("Email address for organizer entry", 'event-tickets-with-ticket-scanner'), 'desc'=>__("If set then the organizer tag will be added to the ICS file. The organizer name will be your website name", 'event-tickets-with-ticket-scanner'), 'type'=>"text", '_doc_video'=>'https://youtu.be/bC_NyPMv2_c'];

		$options[] = [
			'key'=>'h12b',
			'label'=>__("Ticket Redirect", 'event-tickets-with-ticket-scanner'),
			'desc'=>__("If you customer redeem their own ticket, you can redirect them to another page. For this, the feature 'Do not show the redeem button on the ticket detail view for the client' has to be NOT checked.<br>If you also use the user redirect, then this option will be evaluated first!", 'event-tickets-with-ticket-scanner'),
			'type'=>"heading"
			];
		$options[] = ['key'=>'wcTicketRedirectUser', 'label'=>__("Activate redirect the user after redeeming their own ticket.", 'event-tickets-with-ticket-scanner'), 'desc'=>__("If active, the user will be redirected to the URL your provide below.", 'event-tickets-with-ticket-scanner'), 'type'=>"checkbox", 'def'=>"", '_doc_video'=>'https://youtu.be/1BMrPGWWLo8'];
		$options[] = ['key'=>'wcTicketRedirectUserURL', 'label'=>__("URL to redirect the user, if the ticket was redeemed.", 'event-tickets-with-ticket-scanner'), 'desc'=>__("The URL can be relative like '/page/' or absolute 'https//domain/url/'.<br>You can use these placeholder for your URL:<ul><li><b>{USERID}</b>: Will be replaced with the userid if the user is loggedin or empty</li><li><b>{CODE}</b>: Will be replaced with the ticket number (without the delimiters)</li><li><b>{CODEDISPLAY}</b>: Will be replaced with the ticket number (WITH the delimiters)</li><li><b>{IP}</b>: The IP address of the user</li><li><b>{LIST}</b>: Name of the list if assigned</li><li><b>{LIST_DESC}</b>: Description of the assigned list</li><li><a href='#replacementtags'>More tags here</a></li></ul>", 'event-tickets-with-ticket-scanner'), 'type'=>"text", 'def'=>"", '_doc_video'=>'https://youtu.be/k_sewEOiSb8'];

		$options[] = [
			'key'=>'h12c',
			'label'=>__("Event Flyer", 'event-tickets-with-ticket-scanner'),
			'desc'=>__("You can download a PDF flyer for your event within the product detail view. Control the components to be displayed.", 'event-tickets-with-ticket-scanner'),
			'type'=>"heading"
			];
		$options[] = ['key'=>'wcTicketFlyerDontDisplayBlogName', 'label'=>__("Hide your wordpress name.", 'event-tickets-with-ticket-scanner'), 'desc'=>__("If active, it will not display the wordpress name.", 'event-tickets-with-ticket-scanner'), 'type'=>"checkbox", 'def'=>"", '_doc_video'=>'https://youtu.be/wR-v9mUNwzE'];
		$options[] = ['key'=>'wcTicketFlyerDontDisplayBlogDesc', 'label'=>__("Hide your wordpress description.", 'event-tickets-with-ticket-scanner'), 'desc'=>__("If active, it will not display the wordpress description.", 'event-tickets-with-ticket-scanner'), 'type'=>"checkbox", 'def'=>"", '_doc_video'=>'https://youtu.be/O4Cca8m8EWU'];
		$options[] = ['key'=>'wcTicketFlyerDontDisplayBlogURL', 'label'=>__("Hide your wordpress URL.", 'event-tickets-with-ticket-scanner'), 'desc'=>__("If active, it will not display the wordpress URL.", 'event-tickets-with-ticket-scanner'), 'type'=>"checkbox", 'def'=>"", '_doc_video'=>'https://youtu.be/vu2cr-xMNP8'];
		$options[] = ['key'=>'wcTicketFlyerDontDisplayPrice', 'label'=>__("Hide your ticket price.", 'event-tickets-with-ticket-scanner'), 'desc'=>__("If active, the ticket price will not be displayed.", 'event-tickets-with-ticket-scanner'), 'type'=>"checkbox", 'def'=>"", '_doc_video'=>'https://youtu.be/cR3n1T5MOYM'];
		$options[] = ['key'=>'wcTicketFlyerLogo', 'label'=>__("Display a small logo (max. 300x300px) at the bottom in the center.", 'event-tickets-with-ticket-scanner'), 'desc'=>__("If a media file is choosen, the logo will be placed on the flyer.", 'event-tickets-with-ticket-scanner'), 'type'=>"media", '_doc_video'=>'https://youtu.be/Cc8xOsBq3wc', 'def'=>""
						, 'additional'=>[
							'max'=>['width'=>200,'height'=>200],
							'button'=>esc_attr__('Choose logo for the ticket flyer', 'event-tickets-with-ticket-scanner'),
							'msg_error'=>[
								'width'=>__('Too big! Choose an image with smaller size. Max 300px width, otherwise it will look not good on your flyer.', 'event-tickets-with-ticket-scanner')
							]
						]
					];
		$options[] = ['key'=>'wcTicketFlyerBanner', 'label'=>__("Display a banner image at the top of the PDF.", 'event-tickets-with-ticket-scanner'), 'desc'=>__("If a media file is choosen, the banner will be placed on the flyer.", 'event-tickets-with-ticket-scanner'), 'type'=>"media", '_doc_video'=>'https://youtu.be/Cc8xOsBq3wc', 'def'=>""
					, 'additional'=>[
						'min'=>['width'=>600],
						'button'=>esc_attr__('Choose banner image for the ticket flyer', 'event-tickets-with-ticket-scanner'),
						'msg_error_min'=>[
							'width'=>__('Too small! Choose an image with bigger size. Min 600px width, otherwise it will look not good on your flyer.', 'event-tickets-with-ticket-scanner')
							]
						]
					];
		$options[] = ['key'=>'wcTicketFlyerBG', 'label'=>__("Display a background image at the center of the PDF.", 'event-tickets-with-ticket-scanner'), 'desc'=>__("If a media file is choosen, the image will be placed on the ticket flyer.", 'event-tickets-with-ticket-scanner'), 'type'=>"media", '_doc_video'=>'https://youtu.be/Cc8xOsBq3wc', 'def'=>""
					, 'additional'=>[
						'button'=>esc_attr__('Choose background image for the ticket flyer', 'event-tickets-with-ticket-scanner')
						]
					];

		$options[] = ['key'=>'h20', 'label'=>__("User profile", 'event-tickets-with-ticket-scanner'), 'desc'=>"", 'type'=>"heading"];
		$options[] = ['key'=>'wcTicketUserProfileDisplayRegisteredNumbers', 'label'=>__("Display registered ticket numbers within the user profile", 'event-tickets-with-ticket-scanner'), 'desc'=>"", 'type'=>"checkbox", 'def'=>"", '_doc_video'=>'https://youtu.be/Cc8xOsBq3wc'];
		$options[] = ['key'=>'wcTicketUserProfileDisplayBoughtNumbers', 'label'=>__("Display bought ticket numbers within the user profile", 'event-tickets-with-ticket-scanner'), 'desc'=>"", 'type'=>"checkbox", 'def'=>"", '_doc_video'=>'https://youtu.be/SVu7tNXLZUs'];
		$options[] = ['key'=>'wcTicketUserProfileDisplayTicketDetailURL', 'label'=>__("Display the ticket detail page URL", 'event-tickets-with-ticket-scanner'), 'desc'=>"", 'type'=>"checkbox", 'def'=>"", '_doc_video'=>'https://youtu.be/GSpNww6kQ5c'];
		$options[] = ['key'=>'wcTicketUserProfileDisplayRedeemAmount', 'label'=>__("Display the redeem information for the ticket", 'event-tickets-with-ticket-scanner'), 'desc'=>"", 'type'=>"checkbox", 'def'=>""];

		foreach($options as $o) {
			$this->_options[] = $this->getOptionsObject(
				$o['key'], $o['label'], $o['desc'], $o['type'],
				isset($o['def']) ? $o['def'] : null,
				isset($o['additional']) ? $o['additional'] : [],
				isset($o['isPublic']) ? $o['isPublic'] : false,
				isset($o['_doc_video']) ? $o['_doc_video'] : '',
				isset($o['_do_not_trim']) ? $o['_do_not_trim'] : ''
			);
		}

		$this->_options[] = $this->getOptionsObject('h0', __("Validator Form for ticket number check", 'event-tickets-with-ticket-scanner'),"","heading");
		$this->_options[] = $this->getOptionsObject('textValidationButtonLabel', __("Your own check button label", 'event-tickets-with-ticket-scanner'), __("If left empty, default will be 'Check'", 'event-tickets-with-ticket-scanner'),"text", "Check", [], true);
		$this->_options[] = $this->getOptionsObject('textValidationInputPlaceholder', esc_html__("Your own input field placeholder text", 'event-tickets-with-ticket-scanner'), __("If left empty, default will be 'XXYYYZZ'", 'event-tickets-with-ticket-scanner'),"text", __("XXYYYZZ", 'event-tickets-with-ticket-scanner'), [], true);
		$this->_options[] = $this->getOptionsObject('textValidationBtnBgColor', esc_html__("Your own background color of the button", 'event-tickets-with-ticket-scanner'), __("If left empty, default will be <span style='color:#007bff;'>'#007bff'</span>", 'event-tickets-with-ticket-scanner'),"text", "", [], true);
		$this->_options[] = $this->getOptionsObject('textValidationBtnBrdColor', esc_html__("Your own border color of the button", 'event-tickets-with-ticket-scanner'), __("If left empty, default will be <span style='color:#007bff;'>'#007bff'</span>", 'event-tickets-with-ticket-scanner'),"text", "", [], true);
		$this->_options[] = $this->getOptionsObject('textValidationBtnTextColor', esc_html__("Your own text color of the button", 'event-tickets-with-ticket-scanner'), __("If left empty, default will be 'white'", 'event-tickets-with-ticket-scanner'),"text", "", [], true);

		$this->_options[] = $this->getOptionsObject('h1', "Validation Messages","","heading");
		$this->_options[] = $this->getOptionsObject('textValidationMessage1', __("Your own 'Ticket confirmed' message", 'event-tickets-with-ticket-scanner'), __("If left empty, default will be 'Ticket confirmed'", 'event-tickets-with-ticket-scanner'),"text", __("Ticket confirmed", 'event-tickets-with-ticket-scanner'), [], true);
		$this->_options[] = $this->getOptionsObject('textValidationMessage0', __("Your own 'Ticket not found' message", 'event-tickets-with-ticket-scanner'), __("If left empty, default will be 'Ticket not found'", 'event-tickets-with-ticket-scanner'),"text", __("Ticket not found", 'event-tickets-with-ticket-scanner'), [], true);
		$this->_options[] = $this->getOptionsObject('textValidationMessage2', __("Your own 'Ticket inactive' message", 'event-tickets-with-ticket-scanner'), __("If left empty, default will be 'Please contact support for further investigation'", 'event-tickets-with-ticket-scanner'),"text", __("Please contact support for further investigation", 'event-tickets-with-ticket-scanner'), [], true);
		$this->_options[] = $this->getOptionsObject('textValidationMessage3', __("Your own 'Ticket is already registered to a user' message", 'event-tickets-with-ticket-scanner'), __("If left empty, default will be 'Is registered to a user'", 'event-tickets-with-ticket-scanner'),"text", __("Is registered to a user", 'event-tickets-with-ticket-scanner'), [], true);
		$this->_options[] = $this->getOptionsObject('textValidationMessage4', __("Your own 'Ticket expired' message", 'event-tickets-with-ticket-scanner'), __("If left empty, default will be 'Ticket expired'", 'event-tickets-with-ticket-scanner'),"text", __("Ticket expired", 'event-tickets-with-ticket-scanner'), [], true);
		$this->_options[] = $this->getOptionsObject('textValidationMessage6', __("Your own 'Ticket and CVV is not valid' message", 'event-tickets-with-ticket-scanner'), __("If left empty, default will be 'Ticket and CVV is not valid'.", 'event-tickets-with-ticket-scanner'),"text", __("Ticket and CVV is not valid", 'event-tickets-with-ticket-scanner'), [], true);
		$this->_options[] = $this->getOptionsObject('textValidationMessage7', __("Your own 'Ticket stolen' message", 'event-tickets-with-ticket-scanner'), __("If left empty, default will be 'Ticket stolen'. You could set it to be more precise e.g.: 'The Ticket is reported as stolen'", 'event-tickets-with-ticket-scanner'),"text", __("Ticket is stolen", 'event-tickets-with-ticket-scanner'), [], true);
		$this->_options[] = $this->getOptionsObject('textValidationMessage8', __("Your own 'Ticket is redeemed' message", 'event-tickets-with-ticket-scanner'), __("If left empty, default will be 'Ticket is redeemed'", 'event-tickets-with-ticket-scanner'), "text",__("Ticket is redeemed", 'event-tickets-with-ticket-scanner'), [], true);

		$this->_options[] = $this->getOptionsObject('h2', __("Logged in user only", 'event-tickets-with-ticket-scanner'),"","heading");
		$this->_options[] = $this->getOptionsObject('onlyForLoggedInWPuser', __("Allow only logged in wordpress user to enter a ticket number for validation", 'event-tickets-with-ticket-scanner'), __("If active and the user is not logged in, then the input fields will be disabled", 'event-tickets-with-ticket-scanner'), "checkbox", "", [], true);
		$this->_options[] = $this->getOptionsObject('onlyForLoggedInWPuserMessage', __("Your own 'Only for logged in user' message", 'event-tickets-with-ticket-scanner'), __("If left empty, default will be 'You need to log in to use the ticket validator'", 'event-tickets-with-ticket-scanner'),"text", __("You need to log in to use the ticket validator", 'event-tickets-with-ticket-scanner'), [], true, 'https://youtu.be/_EpIfAJoXio');

		/* // brauchen wir später mit der Übertragung und auch gut, wenn einer Tickets ohne order verkauft. erstmal deaktivieren
		$this->_options[] = $this->getOptionsObject('h5', "Register user to ticket","Useful, if you are selling tickets for guest and do not have their name on it","heading");
		$this->_options[] = $this->getOptionsObject('allowUserRegisterCode', "Allow your users to register themself for a code.","If active, the user will get the option to register with an 'email address' (or your registration value text) to the code. <b>IMPORTANT</b>: If activate, the redirect option will executed after the registration.", "checkbox", "", [], true);
		$this->_options[] = $this->getOptionsObject('textRegisterButton', "Your own button label 'Register for this code'","If left empty, default will be 'Register for this code'","text", "Register for this code", [], true);
		$this->_options[] = $this->getOptionsObject('textRegisterValue', "Your own label for the user registration value question","If left empty, default will be 'Enter your email address'","text", "Enter your email address", [], true);
		$this->_options[] = $this->getOptionsObject('textRegisterSaved', "Your own message for the 'user registration value is stored' operation","If left empty, default will be 'Your code is registered to you'","text", "Your code is registered to you", [], true);
		$this->_options[] = $this->getOptionsObject('allowUserRegisterCodeWPuserid', "Track wordpress userid","If active and the user is logged in, then the userid will be stored to the registration information.");
		$this->_options[] = $this->getOptionsObject('allowUserRegisterSkipValueQuestion', "Skip asking for the registration value, if the user is logged in","If active and the user is logged in, then question of 'Register for this code' will be not shown and the 'is stored text' will be displayed immediately.", "checkbox", "", [], true);

		$this->_options[] = $this->getOptionsObject('h6', "Display registered information of a ticket","","heading");
		$this->_options[] = $this->getOptionsObject('displayUserRegistrationOfCode', "Display the collected information of a registration to a ticket.", 'Usefull if your codes are certificatins and you want if somebody type in the ticket number to see who it belongs to.');
		$this->_options[] = $this->getOptionsObject('displayUserRegistrationPreText', "Your own pre-text for the display of the collected information","If not empty, it will be added one line above the registered information to the ticket","text", "");
		$this->_options[] = $this->getOptionsObject('displayUserRegistrationAfterText', "Your own after-text for the display of the collected information","If not empty, it will be added one line below the registered information to the ticket","text", "");
		*/

		$this->_options[] = $this->getOptionsObject('h8', __("User redirection", 'event-tickets-with-ticket-scanner'),"","heading");
		$this->_options[] = $this->getOptionsObject('userJSRedirectActiv', __("Activate redirect the user after a valid ticket was found.", 'event-tickets-with-ticket-scanner'), __("If active, the user will be redirected to the URL your provide below.", 'event-tickets-with-ticket-scanner'), "checkbox", "", [], true, 'https://youtu.be/C9W8q7fsDMc');
		$this->_options[] = $this->getOptionsObject('userJSRedirectIfSameUserRegistered', __("Redirect already registered tickets and the user is the same.", 'event-tickets-with-ticket-scanner'), __("If active, the user will be redirected to the URL your provide below, even if the ticket is registered already and user checking is the same user that is registered to the ticket. It will not be executed, if the 'one time usage restriction is active'. The user needs to be logged in for the system to recognize the user.", 'event-tickets-with-ticket-scanner'), "checkbox", "", [], true, 'https://youtu.be/r82u5GB-RPY');
		$this->_options[] = $this->getOptionsObject('userJSRedirectURL', __("URL to redirect the user, if the ticket is valid.", 'event-tickets-with-ticket-scanner'), __("The URL can be relative like '/page/' or absolute 'https//domain/url/'.<br>You can use these placeholder for your URL:<ul><li><b>{USERID}</b>: Will be replaced with the userid if the user is loggedin or empty</li><li><b>{CODE}</b>: Will be replaced with the ticket number (without the delimiters)</li><li><b>{CODEDISPLAY}</b>: Will be replaced with the ticket number (WITH the delimiters)</li><li><b>{IP}</b>: The IP address of the user</li><li><b>{LIST}</b>: Name of the list if assigned</li><li><b>{LIST_DESC}</b>: Description of the assigned list</li><li><a href='#replacementtags'>More tags here</a></li></ul>", 'event-tickets-with-ticket-scanner'), "text", "", [], false, 'https://youtu.be/r82u5GB-RPY');
		$this->_options[] = $this->getOptionsObject('userJSRedirectBtnLabel', __("Button label to click for the user to be redirected", 'event-tickets-with-ticket-scanner'), __("Only if filled out, the button will be displayed. If you left this field empty, then the user will be redirected immediately if the ticket is valid, without a button to click.", 'event-tickets-with-ticket-scanner'),"text", "", [], false, 'https://youtu.be/iyqCa8UN4ZU');

		$this->_options[] = $this->getOptionsObject('h9', __("Webhooks", 'event-tickets-with-ticket-scanner'),"","heading");
		$this->_options[] = $this->getOptionsObject('webhooksActiv', __("Activate webhooks to call a service with the validation check.", 'event-tickets-with-ticket-scanner'), __("If active, each validation request from a user will trigger an URL from the server side to another URL. Be carefull. This could slow down the validation check. It depends how fast your service URLs are responding.", 'event-tickets-with-ticket-scanner')."<br>".__("The URL can be relative like '/page/' or absolute 'https//domain/url/'.<br>You can use these placeholder for your URL:<ul><li><b>{USERID}</b>: Will be replaced with the userid if the user is loggedin or empty</li><li><b>{CODE}</b>: Will be replaced with the ticket number (without the delimiters)</li><li><b>{CODEDISPLAY}</b>: Will be replaced with the ticket number (WITH the delimiters)</li><li><b>{IP}</b>: The IP address of the user</li><li><b>{LIST}</b>: Name of the list if assigned</li><li><b>{LIST_DESC}</b>: Description of the assigned list</li><li><a href='#replacementtags'>More tags here</a></li></ul>", 'event-tickets-with-ticket-scanner'), "checkbox", null, [], false, 'https://youtu.be/mcPFTaNBKhM');
		$this->_options[] = $this->getOptionsObject('webhookURLinactive', __("URL to your service if the checked ticket <b>is inactive</b>.", 'event-tickets-with-ticket-scanner'), __("Only triggered, if not empty.", 'event-tickets-with-ticket-scanner'), "text", "", [], false, 'https://youtu.be/dnqcfX7neoA');
		$this->_options[] = $this->getOptionsObject('webhookURLvalid', __("URL to your service if the checked ticket <b>is valid</b>.", 'event-tickets-with-ticket-scanner'), __("Only triggered, if not empty.", 'event-tickets-with-ticket-scanner'), "text", "", [], false, 'https://youtu.be/quOVvR3lQYk');
		$this->_options[] = $this->getOptionsObject('webhookURLinvalid', __("URL to your service if the checked ticket <b>is invalid</b> (not found).", 'event-tickets-with-ticket-scanner'), __("Only triggered, if not empty.", 'event-tickets-with-ticket-scanner'), "text", "", [], false, 'https://youtu.be/GVi0u9r4RrY');
		$this->_options[] = $this->getOptionsObject('webhookURLregister', __("URL to your service if <b>someone register to this ticket</b>.", 'event-tickets-with-ticket-scanner'), __("Only triggered, if not empty.", 'event-tickets-with-ticket-scanner'), "text", "", [], false, 'https://youtu.be/0MahVL76wn4');
		$this->_options[] = $this->getOptionsObject('webhookURLisregistered', __("URL to your service if the checked ticket is already <b>registered to someone</b>.", 'event-tickets-with-ticket-scanner'), __("Only triggered, if not empty.", 'event-tickets-with-ticket-scanner'), "text", "", [], false, 'https://youtu.be/cs1HFXWPz2s');
		$this->_options[] = $this->getOptionsObject('webhookURLsetused', __("URL to your service if the checked ticket is valid and is <b>marked to be used the first time</b>.", 'event-tickets-with-ticket-scanner'), __("Only triggered, if not empty.", 'event-tickets-with-ticket-scanner'), "text", "", [], false, 'https://youtu.be/DxCXj4twTWE');
		$this->_options[] = $this->getOptionsObject('webhookURLmarkedused', __("URL to your service if the checked ticket is already <b>marked as used and checked again</b>.", 'event-tickets-with-ticket-scanner'), __("Only triggered, if not empty.", 'event-tickets-with-ticket-scanner'), "text", "", [], false, 'https://youtu.be/5oMK7O8gSUo');
		$this->_options[] = $this->getOptionsObject('webhookURLrestrictioncodeused', __("URL to your service if an order item is bought using a restriction code.", 'event-tickets-with-ticket-scanner'), __("Only triggered, if not empty.", 'event-tickets-with-ticket-scanner'), "text", "", [], false, 'https://youtu.be/4NS1ehjXqb0');
		//$this->_options[] = $this->getOptionsObject('webhookURLaddwcinfotocode', __("URL to your service if a code received WooCommerce data, if a 'code was purchased'.", 'event-tickets-with-ticket-scanner'), __("Only triggered, if not empty.", 'event-tickets-with-ticket-scanner'), "text", "");
		//$this->_options[] = $this->getOptionsObject('webhookURLwcremove', __("URL to your service if the WooCommerce data is removed from the code.'.", 'event-tickets-with-ticket-scanner'),__("Only triggered, if not empty.", 'event-tickets-with-ticket-scanner'), "text", "");
		$this->_options[] = $this->getOptionsObject('webhookURLaddwcticketsold', __("URL to your service if the WooCommerce ticket is sold.", 'event-tickets-with-ticket-scanner'), __("Only triggered, if not empty. This webhook is called for each ticket number within a purchase, if the ticket has an order assigned to it.", 'event-tickets-with-ticket-scanner'), "text", "", [], false, 'https://youtu.be/JraL989cKH4');
		$this->_options[] = $this->getOptionsObject('webhookURLaddwcticketinfoset', __("URL to your service if the WooCommerce ticket data is set for this ticket number.", 'event-tickets-with-ticket-scanner'), __("Only triggered, if not empty.", 'event-tickets-with-ticket-scanner'), "text", "", [], false, 'https://youtu.be/BWgYVtIkV70');
		$this->_options[] = $this->getOptionsObject('webhookURLaddwcticketredeemed', __("URL to your service if the WooCommerce ticket is redeemed.", 'event-tickets-with-ticket-scanner'), __("Only triggered, if not empty.", 'event-tickets-with-ticket-scanner'), "text", "", [], false, 'https://youtu.be/xiUZwo2tO-U');
		$this->_options[] = $this->getOptionsObject('webhookURLaddwcticketunredeemed', __("URL to your service if the WooCommerce ticket is un-redeemed.", 'event-tickets-with-ticket-scanner'), __("Only triggered, if not empty.", 'event-tickets-with-ticket-scanner'), "text", "", [], false, 'https://youtu.be/tt5V7Q1ADf4');
		$this->_options[] = $this->getOptionsObject('webhookURLaddwcticketinforemoved', __("URL to your service if the WooCommerce ticket data is removed from the ticket number.", 'event-tickets-with-ticket-scanner'), __("Only triggered, if not empty.", 'event-tickets-with-ticket-scanner'), "text", "", [], false, 'https://youtu.be/HD3TB8t9MBI');

		$this->_options[] = $this->getOptionsObject('h10', __("Woocommerce product ticket assignment", 'event-tickets-with-ticket-scanner'),"","heading");
		if (!$this->MAIN->isPremium()) {
			$this->_options[] = $this->getOptionsObject('wcassignmentTextNoCodePossible', __("Text that will be used, if you do not have <b>premium</b> and run out of free ticket amount. This text will be added to the WooCoomerce purchase information instead of the ticket number", 'event-tickets-with-ticket-scanner'), __("If left empty, default will be 'Please contact our support for the ticket'", 'event-tickets-with-ticket-scanner'),"text", __("Please contact our support for the ticket", 'event-tickets-with-ticket-scanner'), [], true);
		}
		$this->_options[] = $this->getOptionsObject('wcRestrictFreeCodeByOrderRefund', __("Clear the ticket number if the order was deleted, canceled or refunded", 'event-tickets-with-ticket-scanner'), __("If the order is deleted, cancelled or the status is set to 'refunded', then the WooCommerce order information is removed from the ticket number(s). If the option 'one time usage' is active, then the ticket number will be unmarked as used.", 'event-tickets-with-ticket-scanner'), "checkbox", true, [], false, 'https://youtu.be/KARe2flFweU');
		$this->_options[] = $this->getOptionsObject('wcassignmentOrderItemRefund', __("Clear the ticket number if the order item was partially refunded", 'event-tickets-with-ticket-scanner'), __("If the order item is refunded, then the ticket(s) will be removed. If the option 'one time usage' is active, then the ticket number will be unmarked as used.", 'event-tickets-with-ticket-scanner'), "checkbox", false, [], false, 'https://youtu.be/twAenbYVCNg');
		$this->_options[] = $this->getOptionsObject('wcassignmentReuseNotusedCodes', __("Reuse ticket from the ticket list assigned to the woocommerce product, that are not already used by a woocommerce purchase.", 'event-tickets-with-ticket-scanner'),__("If active, the system will try to use an existing ticket from the ticket list that is free. If no free ticket number could be found, a new ticket will be created and assigned to the purchase.", 'event-tickets-with-ticket-scanner'), "checkbox", true, [], false, 'https://youtu.be/74fEg7FC6Qw');
		$this->_options[] = $this->getOptionsObject('wcassignmentDoNotPutCVVOnEmail', __("Do not print the ticket number CVV on the confirmation to the customer.", 'event-tickets-with-ticket-scanner'), __("If active, the assigned CVV will not be printed on the email", 'event-tickets-with-ticket-scanner'), "checkbox", "", [], false, 'https://youtu.be/kfsm0jXJwv0');
		$this->_options[] = $this->getOptionsObject('wcassignmentDoNotPutCVVOnPDF', __("Do not print the ticket number CVV on the PDF invoice woocommerce purchase.", 'event-tickets-with-ticket-scanner'), __("If active, the assigned CVV will not be printed on the PDF", 'event-tickets-with-ticket-scanner'), "checkbox", "", [], false, 'https://youtu.be/eAWq5bAVEVM');
		$this->_options[] = $this->getOptionsObject('wcassignmentDoNotPutOnEmail', __("Do not put the ticket in the emails to the customer", 'event-tickets-with-ticket-scanner'), __("If active, the assigned ticket number and other ticket related information will not be put in the email", 'event-tickets-with-ticket-scanner'), "checkbox", "", []);
		$this->_options[] = $this->getOptionsObject('wcassignmentDoNotPutOnPDF', __("Do not print the ticket on the PDF invoice woocommerce purchase.", 'event-tickets-with-ticket-scanner'), __("If active, the assigned ticket will not be printed on the PDF", 'event-tickets-with-ticket-scanner'), "checkbox", "", []);
		$this->_options[] = $this->getOptionsObject('wcassignmentUseGlobalSerialFormatter', __("Set the ticket number formatter pattern for new sales.", 'event-tickets-with-ticket-scanner'), __("If active, the a new ticket will generated using the following settings", 'event-tickets-with-ticket-scanner'), "checkbox", "", [], false, 'https://youtu.be/hB6wW4_o2qc');
		$this->_options[] = $this->getOptionsObject('wcassignmentUseGlobalSerialFormatter_values', "","", "text", "", ["doNotRender"=>1]);

		$this->_options[] = $this->getOptionsObject('h13', __("Display ticket number to your loggedin user", 'event-tickets-with-ticket-scanner'), sprintf(/* translators: %s: shortcode */__("You can display the tickets assigned to an user with this shortcode %s.", 'event-tickets-with-ticket-scanner'), '<b>[sasoEventTicketsValidator_code]</b>'),"heading");
		$this->_options[] = $this->getOptionsObject('userDisplayCodePrefix', __("Text that will be added before the ticket number(s) for the user are displayed.", 'event-tickets-with-ticket-scanner'), "","text", __("Your ticket number(s):", 'event-tickets-with-ticket-scanner'), [], false, 'https://youtu.be/Ur-WvEROXUY');
		$this->_options[] = $this->getOptionsObject('userDisplayCodePrefixAlways', __("Display the prefix text always.", 'event-tickets-with-ticket-scanner'), __("If active, your prefix text will be rendered always. Even if the user is not logged in or do not have any tickets assigned to her yet.", 'event-tickets-with-ticket-scanner'), "checkbox", "", [], false, 'https://youtu.be/DbSor75q8Qk');
		$this->_options[] = $this->getOptionsObject('userDisplayCodeSeperator', __("Text or letter to be used as a seperator for ticket numbers of the user.", 'event-tickets-with-ticket-scanner'), __("If the user has more than one ticket number assigned to her, then this text will be used to seperate them for display the numbers. If left empty, then it will be ', ' as a default.", 'event-tickets-with-ticket-scanner'),"text", ", ", [], false, 'https://youtu.be/RESdZnylRD8');

		$this->_options[] = $this->getOptionsObject('h14', __("QR code", 'event-tickets-with-ticket-scanner'), __("You can generate QR code images for your ticket numbers.", 'event-tickets-with-ticket-scanner'),"heading");
		$this->_options[] = $this->getOptionsObject('qrTicketPDFPadding', __("Padding for your QR code on the PDF", 'event-tickets-with-ticket-scanner'), __("For dark backgrounds it could be helpfull to add a white border to the QR code. The size lets you add a border. If you need one, then 4 is a good value.", 'event-tickets-with-ticket-scanner'), "number", 0, ['min'=>0], false, 'https://youtu.be/t0jDO6Km5Pk');
		$this->_options[] = $this->getOptionsObject('qrDirectURL', __("URL for the QR image", 'event-tickets-with-ticket-scanner'), __("The URL should be absolute, if you like to provide the generated QR image to your customers. The image can be retrieved within the event ticket area. The ticket number detail contains a button for it.<br>You can use these placeholder for your URL:<ul><li><b>{CODE}</b>: Will be replaced with the number (without the delimiters)</li><li><b>{CODEDISPLAY}</b>: Will be replaced with the number (WITH the delimiters)</li><b>{LIST}</b>: Name of the list if assigned</li><li><b>{LIST_DESC}</b>: Description of the assigned list</li><li><a href='#replacementtags'>You could use more tags.</a> But it is not recommend, since the QR code is generated within the admin area.</li></ul>", 'event-tickets-with-ticket-scanner'), "text", "", [], false, 'https://youtu.be/bYfviB7x9Xc');
		$this->_options[] = $this->getOptionsObject('ticketQRUseURLToTicketScanner', __("Add to the ticket QR code the full URL to the ticket scanner with the public ticket id", 'event-tickets-with-ticket-scanner'), __("If active, then the URL to your ticket scanner with the public ticket id will be used instead of only the public ticket id.", 'event-tickets-with-ticket-scanner'), "checkbox", "", [], false, 'https://youtu.be/zjpqpnvXxT0');
		$this->_options[] = $this->getOptionsObject('qrUseOwnQRContent', __("Use my QR content - I will use my own ticket scanner", 'event-tickets-with-ticket-scanner'), __("If active, then the QR content will use the following content. You will not be able to scan the tickets with the ticket scanner, because the format cannot be recognized by the plugin ticket scanner.", 'event-tickets-with-ticket-scanner'), "checkbox", "", [], false, 'https://youtu.be/eYdlEeBs1Rw');
		$this->_options[] = $this->getOptionsObject('qrOwnQRContent', __("My QR content", 'event-tickets-with-ticket-scanner'), __("Please make sure that you do not enter too many information, the more you add the finer the QR reader need to be able to scan.<br>You can use these placeholder for your content:<ul><li><b>{CODE}</b>: Will be replaced with the number (without the delimiters)</li><li><b>{CODEDISPLAY}</b>: Will be replaced with the number (WITH the delimiters)</li><b>{LIST}</b>: Name of the list if assigned</li><li><b>{LIST_DESC}</b>: Description of the assigned list</li><li><a href='#replacementtags'>You could use more tags.</a> But it is not recommend, since the QR code is generated within the admin area.</li></ul>", 'event-tickets-with-ticket-scanner'), "textarea", "{WC_TICKET__PUBLIC_TICKET_ID}", ["rows"=>5], false, 'https://youtu.be/LGhf7lfsHY4');
		$this->_options[] = $this->getOptionsObject('qrAttachQRImageToEmail', __("Attach QR image to purchase email", 'event-tickets-with-ticket-scanner'), __("If active, then the QR as an image will be attached to the purchase email. The settings are taken from the ticket settings for purchase email.", 'event-tickets-with-ticket-scanner'), "checkbox", "", [], false, 'https://youtu.be/8RzYNBgOHxw');
		$this->_options[] = $this->getOptionsObject('qrAttachQRPdfToEmail', __("Attach QR pdf to purchase email", 'event-tickets-with-ticket-scanner'), __("If active, then the QR as an pdf will be attached to the purchase email. The settings are taken from the ticket settings for purchase email.", 'event-tickets-with-ticket-scanner'), "checkbox", "", [], false, 'https://youtu.be/lIer7r3U5q0');
		$this->_options[] = $this->getOptionsObject('qrAttachQRFilesToMailAsOnePDF', __("Attach QR PDF to purchase email as one PDF instead of single PDFs", 'event-tickets-with-ticket-scanner'), __("If active, the ticket QR code files are merged into one PDF and will be added as an attachment to the mails.", 'event-tickets-with-ticket-scanner'), "checkbox", "", [], false, 'https://youtu.be/8ZsXV95XGnw');

		if ($this->MAIN->isPremium() && method_exists($this->MAIN->getPremiumFunctions(), '_initOptions')) {
			$this->_options = $this->MAIN->getPremiumFunctions()->_initOptions($this->_options);
		}
	}
	public function getOptionsObject($key, $label, $desc="",$type="checkbox",$def=null,$additional=[], $isPublic=false, $doc_video='', $do_not_trim=false) {
		if ($def == null) {
			switch($type) {
				case "number":
				case "checkbox":
					$def = 0;
					break;
				default:
					$def = "";
			}
		}
		return [
			'key'=>$key,
			'id'=>$this->_prefix.$key,
			'label'=>$label,
			'desc'=>$desc,
			'value'=>0,
			'type'=>$type,
			'default'=>$def,
			'additional'=>$additional,
			'isPublic'=>$isPublic,
			'_isLoaded'=>false,
			'_doc_video'=>$doc_video,
			'_do_not_trim'=>$do_not_trim
		];
	}
	public function loadOptionFromWP($option_id, $default=null, $prefix=null) {
		if ($prefix == null) $prefix = $this->_prefix;
		return get_option( $prefix.$option_id, $default );
	}
	public function getOptions() {
		foreach($this->_options as $idx => $option) {
			if ($option['_isLoaded'] == false) {
				$v = get_option( $option['id'], $option['default']);
				if (!is_array($v)) {
					$v = stripslashes($v);
				}
				$option['value'] = $v;
				$option['_isLoaded'] = true;
				$this->_options[$idx] = $option;
			}
		}
		return $this->_options;
	}
	public function getOptionsKeys() {
		$keys = [];
		foreach($this->_options as $option) {
			$keys[] = $option["key"];
		}
		return $keys;
	}
	public function getOptionsOnlyPublic() {
		$ret = [];
		$options = $this->getOptions();
		foreach($options as $option) {
			if ($option['isPublic'] == true) {
				$ret[] = $option;
			}
		}
		return $ret;
	}
	public function getOption($key) {
		$o = null;
		$key = trim($key);
		if (empty($key)) return $o;
		$options = $this->getOptions();
		foreach($options as $option) {
			if ($option['key'] === $key) {
				$o = $option;
				break;
			}
		}
		return $o;
	}
	private function _setOptionValuesByKey($key, $field, $value) {
		foreach ($this->_options as $idx => $value) {
			if ($value['key'] == $key) {
				$this->_options[$idx][$field] = $value;
				break;
			}
		}
	}
	public function resetAllOptionValuesToDefault() {
		$allOption = $this->getOptions();
		foreach ($allOption as $key => $singleOption) {
			$data = [];
			$key= $singleOption['key'];
			$default= $singleOption['default'];
			$data = array("key"=>$key, "value"=>$default);
			$this->changeOption($data);
		}
		do_action( $this->MAIN->_do_action_prefix.'options_resetAllOptionValuesToDefault', $allOption );
		return true;
	}
	public function deleteAllOptionValues() {
		$allOption = $this->getOptions();
		foreach ($allOption as $option) {
			$this->deleteOption($option['key']);
		}
		do_action( $this->MAIN->_do_action_prefix.'options_deleteAllOptionValues', $allOption );
		return true;
	}
	public function deleteOption($key) {
		foreach ($this->_options as $idx => $value) {
			if ($value['key'] == $key) {
				delete_option( $value['id'] );
				unset($this->_options[$idx]);
				return true;
			}
		}
		do_action( $this->MAIN->_do_action_prefix.'options_deleteOption', $key);
		return false;
	}
	public function changeOption($data) {
		$option = $this->getOption($data['key']);
		if ($option != null) {
			if ($option['type'] == "checkbox") {
				$v = intval($data['value']);
			} else {
				if (is_array($data['value'])) {
					array_walk($data['value'], "trim");
				} else {
					if (isset($option['_do_not_trim']) && $option['_do_not_trim']) {
						$data['value'] = $data['value'];
					} else {
						$data['value'] = trim($data['value']);
					}
				}
				$v = $data['value'];
			}
			update_option($option['id'], $v, false);
			$this->_setOptionValuesByKey($data['key'], 'value', $v);
		}
		do_action( $this->MAIN->_do_action_prefix.'changeOption', $data);
	}
	public function getOptionValue($name, $def="") {
		$option = $this->getOption($name);
		if ($option == null) return $def;
		return $this->_getOptionValue($option);
	}
	private function _getOptionValue($option) {
		$ret = "";
		if ($option == null) return $ret;

		if (is_array($option['value'])) {
			$ret = $option['value'];
			if (count($option['value']) == "") $ret = $option['default'];
		} else {
			if (isset($option['_do_not_trim']) && $option['_do_not_trim']) {
				$ret = $option['value'] == "" ? $option['default'] : $option['value'];
			} else {
				$ret = trim($option['value']) == "" ? $option['default'] : $option['value'];
			}
		}
		return $ret;
	}
	public function isOptionCheckboxActive($optionname) {
		$option = $this->getOption($optionname);
		if ($option == null) return false;
		$val = $this->_getOptionValue($option);
		if (intval($val) == 1 || boolval($val)  == true || $val == "true" || $val == "yes") return true;
		return false;
	}

	public function getOptionDateFormat() {
		$date_format = $this->getOptionValue('displayDateFormat');
		try {
			$d = date($date_format, current_time("timestamp"));
		} catch(Exception $e) {
			$date_format = 'Y/m/d';
		}
		return $date_format;
	}
	public function getOptionTimeFormat() {
		$date_format = $this->getOptionValue('displayTimeFormat');
		try {
			$d = date($date_format, current_time("timestamp"));
		} catch(Exception $e) {
			$date_format = 'H:i';
		}
		return $date_format;
	}
	public function getOptionDateTimeFormat() {
		$date_format = $this->getOptionDateFormat();
		$time_format = $this->getOptionTimeFormat();
		// check if the date values are working
		try {
			$d = date($date_format." ".$time_format, current_time("timestamp"));
		} catch(Exception $e) {
			$date_format = 'Y/m/d';
		}
		return $date_format." ".$time_format;
	}

	public function get_wcTicketAttachTicketToMailOf() {
		$ret = [
			"customer_processing_order",
			"customer_completed_order"
		];
		if ($this->MAIN->isPremium()) {
			$ret = $this->getOptionValue("wcTicketAttachTicketToMailOf");
			if (!is_array($ret)) {
				$ret = [$ret];
			}
		}
		$ret = apply_filters( $this->MAIN->_add_filter_prefix.'options_get_wcTicketAttachTicketToMailOf', $ret );
		return $ret;
	}

}
?>