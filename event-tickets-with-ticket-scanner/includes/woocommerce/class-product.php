<?php
/**
 * WooCommerce Product Manager
 *
 * Handles product ticket configuration, variations, and metadata for WooCommerce products.
 *
 * @package    Event_Tickets_With_Ticket_Scanner
 * @subpackage WooCommerce
 * @since      2.9.0
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
	exit;
}

// Load seating base class for constants
require_once dirname(__DIR__) . '/seating/class-seating-base.php';

/**
 * Product Manager Class
 *
 * Manages ticket configuration for WooCommerce products including:
 * - Product meta boxes and fields
 * - Variation settings
 * - Product columns in admin
 * - Ticket list associations
 *
 * @since 2.9.0
 */
if (!class_exists('sasoEventtickets_WC_Product')) {
	class sasoEventtickets_WC_Product extends sasoEventtickets_WC_Base {

		/**
		 * Constructor
		 *
		 * @param sasoEventtickets $main Main plugin instance
		 */
		public function __construct($main) {
			parent::__construct($main);
		}

		/**
		 * Check if product ID is a ticket product
		 *
		 * @param int $product_id Product ID
		 * @return bool
		 */
		public function isTicketByProductId(int $product_id): bool {
			if ($product_id < 1) {
				return false;
			}
			return get_post_meta($product_id, self::META_PRODUCT_IS_TICKET, true) === "yes";
		}

		/**
		 * Get available ticket lists for dropdown
		 *
		 * @return array Ticket lists array
		 */
		public function wc_get_lists(): array {
			$lists = $this->MAIN->getAdmin()->getLists();
			$dropdown_list = ['' => esc_attr__('Deactivate auto-generating ticket', 'event-tickets-with-ticket-scanner')];

			foreach ($lists as $key => $list) {
				$dropdown_list[$list['id']] = $list['name'];
			}

			return $dropdown_list;
		}

		/**
		 * Add Event Tickets tab to product data tabs
		 *
		 * @param array $tabs Existing tabs
		 * @return array Modified tabs
		 */
		public function woocommerce_product_data_tabs(array $tabs): array {
			$tabs['saso_eventtickets_code_woo'] = [
				'label' => _x('Event Tickets', 'label', 'event-tickets-with-ticket-scanner'),
				'title' => _x('Event Tickets', 'title', 'event-tickets-with-ticket-scanner'),
				'target' => 'saso_eventtickets_wc_product_data',
				'class' => ['hide_if_grouped']
			];
			return $tabs;
		}

		/**
		 * Display product data panel content
		 *
		 * Renders the "Event Tickets" tab panel in WooCommerce product edit page.
		 * Includes all ticket configuration fields, date/time settings, and custom options.
		 *
		 * @return void
		 */
		public function woocommerce_product_data_panels(): void {
			$product = wc_get_product(get_the_ID());
			//$is_variable = $product->get_type() == "variable" ? true : false;
			$is_variation = $product->get_type() == "variation" ? true : false;
			$prem_JS_file = "";
			if ($this->MAIN->isPremium() && method_exists($this->MAIN->getPremiumFunctions(), 'getJSBackendFile')) {
				$prem_JS_file = $this->MAIN->getPremiumFunctions()->getJSBackendFile();
			}

			wp_enqueue_style("wp-jquery-ui-dialog");

			wp_register_script(
				'SasoEventticketsValidator_WC_backend',
				trailingslashit( plugin_dir_url( dirname(dirname(__FILE__)) ) ) . 'wc_backend.js?_v='.$this->MAIN->getPluginVersion(),
				array( 'jquery', 'jquery-blockui', 'wp-i18n'),
				(current_user_can("administrator") ? time() : $this->MAIN->getPluginVersion()),
				true );
			wp_localize_script(
				'SasoEventticketsValidator_WC_backend',
				'Ajax_sasoEventtickets_wc', // name der js variable
				[
					'ajaxurl' => admin_url( 'admin-ajax.php' ),
					'_plugin_home_url' =>plugins_url( "", dirname(dirname(__FILE__)) ),
					'prefix'=>$this->MAIN->getPrefix(),
					'nonce' => wp_create_nonce( $this->MAIN->_js_nonce ),
					'action' => $this->MAIN->getPrefix().'_executeWCBackend',
					'product_id'=>isset($_GET['post']) ? intval($_GET['post']) : 0,
					'order_id'=>0,
					'scope'=>'product',
					'_doNotInit'=>true,
					'_max'=>$this->MAIN->getBase()->getMaxValues(),
					'_isPremium'=>$this->MAIN->isPremium(),
					'_isUserLoggedin'=>is_user_logged_in(),
					'_backendJS'=>trailingslashit( plugin_dir_url( dirname(dirname(__FILE__)) ) ) . 'backend.js?_v='.$this->MAIN->getPluginVersion(),
					'_premJS'=>$prem_JS_file,
					'_divAreaId'=>'saso_eventtickets_list_format_area',
					'formatterInputFieldDataId'=>'saso_eventtickets_list_formatter_values'
				] // werte in der js variable
				);
			wp_enqueue_script('SasoEventticketsValidator_WC_backend');
			wp_set_script_translations('SasoEventticketsValidator_WC_backend', 'event-tickets-with-ticket-scanner', dirname(dirname(__DIR__)).'/languages');

			$js_url = "jquery.qrcode.min.js?_v=".$this->MAIN->getPluginVersion();
			wp_enqueue_script(
				'ajax_script2',
				plugins_url( "3rd/".$js_url, dirname(dirname(__FILE__)) ),
				array('jquery', 'jquery-ui-dialog')
			);

			wp_enqueue_style($this->MAIN->getPrefix()."_backendcss", plugins_url( "", dirname(dirname(__FILE__)) ).'/css/styles_backend.css');

			echo '<div id="saso_eventtickets_wc_product_data" class="panel woocommerce_options_panel hidden">';

			if (!$this->MAIN->isPremium()) {
				$mv = $this->MAIN->getMV();
				echo '<p style="color:red;">'.sprintf(/* translators: %d: amount of maximum ticket that can be created */__('With the free basic plugin, you can only <b>create up to %d tickets!</b><br>Make sure your are not selling more tickets :)', 'event-tickets-with-ticket-scanner'), intval($mv['codes_total'])).'<br>'.sprintf(/* translators: 1: start of a-tag 2: end of a-tag */__('Here you can purchase the %1$spremium plugin%2$s for unlimited tickets.', 'event-tickets-with-ticket-scanner'), '<a target="_blank" href="https://vollstart.com/event-tickets-with-ticket-scanner/">', '</a>').'</p>';
			}

			$is_ticket_activated = get_post_meta( get_the_ID(), self::META_PRODUCT_IS_TICKET, true );
			echo '<div class="options_group">';
			woocommerce_wp_checkbox([
				'id'          => self::META_PRODUCT_IS_TICKET,
				'value'       => $is_ticket_activated,
				'label'       => __('Is a ticket sales', 'event-tickets-with-ticket-scanner'),
				'description' => __('Activate this, to generate a ticket number', 'event-tickets-with-ticket-scanner')
			]);
			echo "<p><b>Important:</b> You need to choose a list below, to activate the ticket sale for this product.</p>";
			$ticket_lists = $this->wc_get_lists();
			if (count($ticket_lists) == 1) { // only deactivation option is available
				echo "<p><b>".esc_html__('You have no lists created!', 'event-tickets-with-ticket-scanner')."</b><br>".esc_html__('You need to create a list first within the event tickets admin area, to choose a list from.', 'event-tickets-with-ticket-scanner')."</b></p>";
			}
			$ticket_list_id_choosen = get_post_meta( get_the_ID(), 'saso_eventtickets_list', true );
			if (empty($ticket_list_id_choosen) && $is_ticket_activated != "yes" && count($ticket_lists) > 1) {
				$ticket_list_id_choosen = "1";
			}
			woocommerce_wp_select( array(
				'id'          => 'saso_eventtickets_list',
				'value'       => $ticket_list_id_choosen,
				'label'       => __('List', 'event-tickets-with-ticket-scanner'),
				'description' => __('Choose a list to activate auto-generating ticket numbers/codes for each sold item', 'event-tickets-with-ticket-scanner'),
				'desc_tip'    => true,
				'options'     => $ticket_lists
			) );
			echo '</div>';

			// Seating Plan Section
			echo '<div class="options_group">';
			$planManager = $this->MAIN->getSeating()->getPlanManager();
			$seatingPlans = $planManager->getDropdownOptions(true);

			if (count($seatingPlans) > 1) {
				// Get draft-only plan IDs for warning
				$allPlans = $planManager->getAll(true);
				$draftOnlyPlanIds = [];
				foreach ($allPlans as $plan) {
					if (empty($plan['published_at'])) {
						$draftOnlyPlanIds[] = (int) $plan['id'];
					}
				}

				$seating = $this->MAIN->getSeating();
				$metaSeatingplan = $seating->getMetaProductSeatingplan();
				$metaSeatingRequired = $seating->getMetaProductSeatingRequired();

				$currentPlanId = (int) get_post_meta(get_the_ID(), $metaSeatingplan, true);

				woocommerce_wp_select([
					'id'          => $metaSeatingplan,
					'value'       => $currentPlanId,
					'label'       => __('Seating Plan', 'event-tickets-with-ticket-scanner'),
					'description' => __('Assign a seating plan to this ticket product.', 'event-tickets-with-ticket-scanner'),
					'desc_tip'    => true,
					'options'     => $seatingPlans,
					'custom_attributes' => [
						'data-draft-only-ids' => esc_attr(json_encode($draftOnlyPlanIds))
					]
				]);

				// Warning for draft-only plans
				$showWarning = in_array($currentPlanId, $draftOnlyPlanIds, true);
				echo '<p class="form-field saso-seating-draft-warning" style="' . ($showWarning ? '' : 'display:none;') . '">';
				echo '<span class="description" style="color: #d63638; font-weight: bold;">';
				echo '⚠️ ' . esc_html__('This seating plan has not been published yet. Customers will not see a seat selection until you publish the plan.', 'event-tickets-with-ticket-scanner');
				echo ' <a href="' . esc_url(admin_url('admin.php?page=sasoEventTickets&tab=seating')) . '">' . esc_html__('Go to Seating Plans', 'event-tickets-with-ticket-scanner') . '</a>';
				echo '</span></p>';

				// JavaScript for toggling warning
				?>
				<script>
				jQuery(function($) {
					var $select = $('#<?php echo esc_js($metaSeatingplan); ?>');
					var $warning = $('.saso-seating-draft-warning');
					var draftOnlyIds = <?php echo json_encode($draftOnlyPlanIds); ?>;

					$select.on('change', function() {
						var selectedId = parseInt($(this).val()) || 0;
						if (draftOnlyIds.indexOf(selectedId) !== -1) {
							$warning.show();
						} else {
							$warning.hide();
						}
					});
				});
				</script>
				<?php

				woocommerce_wp_checkbox([
					'id'          => $metaSeatingRequired,
					'value'       => get_post_meta(get_the_ID(), $metaSeatingRequired, true),
					'label'       => __('Seat Selection Required', 'event-tickets-with-ticket-scanner'),
					'description' => __('If enabled: Customer must select a seat before adding to cart (e.g., theater with numbered seats). If disabled: Seat selection is optional (e.g., festival with VIP seats + standing area).', 'event-tickets-with-ticket-scanner')
				]);
			} else {
				echo '<p class="form-field">';
				echo '<label>' . esc_html__('Seating Plan', 'event-tickets-with-ticket-scanner') . '</label>';
				echo '<span class="description">' . esc_html__('No seating plans available.', 'event-tickets-with-ticket-scanner') . ' ';
				echo '<a href="' . esc_url(admin_url('admin.php?page=sasoEventTickets&tab=seating')) . '">';
				echo esc_html__('Create one first', 'event-tickets-with-ticket-scanner') . '</a></span>';
				echo '</p>';
			}
			echo '</div>';

			echo '<div class="options_group">';
			woocommerce_wp_text_input([
				'id'				=> 'saso_eventtickets_event_location',
				'value'       		=> get_post_meta( get_the_ID(), 'saso_eventtickets_event_location', true ),
				'label'       		=> wp_kses_post($this->MAIN->getOptions()->getOptionValue("wcTicketTransLocation")),
				'type'				=> 'text',
				'description' 		=> __('This will be also in the cal entry file.', 'event-tickets-with-ticket-scanner'),
				'desc_tip'    		=> true
			]);
			woocommerce_wp_text_input([
				'id'				=> 'saso_eventtickets_ticket_start_date',
				'value'       		=> get_post_meta( get_the_ID(), 'saso_eventtickets_ticket_start_date', true ),
				'label'       		=> __('Start date event', 'event-tickets-with-ticket-scanner'),
				'type'				=> 'date',
				'custom_attributes'	=> ['data-type'=>'date'],
				'description' 		=> __('Set this to have this printed on the ticket and prevent too early redeemed tickets. Tickets can be redeemed from that day on.', 'event-tickets-with-ticket-scanner'),
				'desc_tip'    		=> true
			]);
			woocommerce_wp_text_input([
				'id'				=> 'saso_eventtickets_ticket_start_time',
				'value'       		=> get_post_meta( get_the_ID(), 'saso_eventtickets_ticket_start_time', true ),
				'label'       		=> __('Start time', 'event-tickets-with-ticket-scanner'),
				'type'				=> 'time',
				'description' 		=> __('Set this to have this printed on the ticket.', 'event-tickets-with-ticket-scanner'),
				'desc_tip'    		=> true
			]);
			woocommerce_wp_text_input([
				'id'				=> 'saso_eventtickets_ticket_end_date',
				'value'       		=> get_post_meta( get_the_ID(), 'saso_eventtickets_ticket_end_date', true ),
				'label'       		=> __('End date event', 'event-tickets-with-ticket-scanner'),
				'type'				=> 'date',
				'custom_attributes'	=> ['data-type'=>'date'],
				'description' 		=> __('Set this to have this printed on the ticket and prevent later the ticket to be still valid. Tickets cannot be redeemed after that day.', 'event-tickets-with-ticket-scanner'),
				'desc_tip'    		=> true
			]);
			woocommerce_wp_text_input([
				'id'				=> 'saso_eventtickets_ticket_end_time',
				'value'       		=> get_post_meta( get_the_ID(), 'saso_eventtickets_ticket_end_time', true ),
				'label'       		=> __('End time', 'event-tickets-with-ticket-scanner'),
				'type'				=> 'time',
				'description' 		=> __('Set this to have this printed on the ticket.', 'event-tickets-with-ticket-scanner'),
				'desc_tip'    		=> true
			]);
			if (true || $is_variation) {
				woocommerce_wp_checkbox([
					'id'          => 'saso_eventtickets_is_date_for_all_variants',
					'value'       => get_post_meta( get_the_ID(), 'saso_eventtickets_is_date_for_all_variants', true ),
					'label'       => __('Date is for all variants', 'event-tickets-with-ticket-scanner'),
					'description' => __('Activate this, to have the entered date printed on all product variants. No effect on simple products.', 'event-tickets-with-ticket-scanner')
				]);
			}
			echo '</div>';

			echo '<div class="options_group">';
			// checkbox to activate the date choosnowser
				// info, that only the time will be taken from the date settings. If no time is set then it will be treated like 0:00 - 23:59.
			woocommerce_wp_checkbox([
				'id'          => 'saso_eventtickets_is_daychooser',
				'value'       => get_post_meta( get_the_ID(), 'saso_eventtickets_is_daychooser', true ),
				'label'       => __('Customer can choose the day', 'event-tickets-with-ticket-scanner'),
				'description' => __('Activate this, to allow your customer to choose a date. If this option is active it will use the start and end date as limits, if provided. And use only the start and end time setting. If the start and end time is not set, then the entrance is allowed from 00:00 till 23:59.', 'event-tickets-with-ticket-scanner')
			]);
			woocommerce_wp_checkbox([
				'id'          => 'saso_eventtickets_only_one_day_for_all_tickets',
				'value'       => get_post_meta( get_the_ID(), 'saso_eventtickets_only_one_day_for_all_tickets', true ),
				'label'       => __('Only one date picker', 'event-tickets-with-ticket-scanner'),
				'description' => __('If this option is active, then only one date is allowed per purchase of this product. If your customer buys 2 or more of this ticket, than only one date picker will be shown for all the tickets. The default is to display for each product in the cart one separated date picker.', 'event-tickets-with-ticket-scanner')
			]);
			// checkboxes to exclude days of week
			woocommerce_wp_select( array(
				'id'          => 'saso_eventtickets_daychooser_exclude_wdays',
				'name' 		  => 'saso_eventtickets_daychooser_exclude_wdays[]',
				'value'       => get_post_meta( get_the_ID(), 'saso_eventtickets_daychooser_exclude_wdays', true ),
				'label'       => __('Choose which days to exclude', 'event-tickets-with-ticket-scanner'),
				'description' => __('To select more than one, hold down the CTRL key. The selected days cannot be choosen by your customer in date chooser.', 'event-tickets-with-ticket-scanner'),
				'desc_tip'    => true,
				'class' => 'cb-admin-multiselect',
				'options'     => [
					"1"=>__('Monday', 'event-tickets-with-ticket-scanner'),
					"2"=>__('Tuesday', 'event-tickets-with-ticket-scanner'),
					"3"=>__('Wednesday', 'event-tickets-with-ticket-scanner'),
					"4"=>__('Thursday', 'event-tickets-with-ticket-scanner'),
					"5"=>__('Friday', 'event-tickets-with-ticket-scanner'),
					"6"=>__('Saturday', 'event-tickets-with-ticket-scanner'),
					"0"=>__('Sunday', 'event-tickets-with-ticket-scanner')
				],
				'custom_attributes' => array('multiple' => 'multiple')
			) );
			// input field for offset first day to choose from in days
			woocommerce_wp_text_input([
				'id'				=> 'saso_eventtickets_daychooser_offset_start',
				'value'       		=> intval(get_post_meta( get_the_ID(), 'saso_eventtickets_daychooser_offset_start', true )),
				'label'       		=> __('Offset days for start date', 'event-tickets-with-ticket-scanner'),
				'type'				=> 'number',
				'custom_attributes'	=> ['step'=>'1', 'min'=>'0'],
				'description' 		=> __('This will set how many days to skip before you allow your customer to choose a date. 0 means starting from the same day, 1 means from tomorrow on and so on. If you set a start date, the the start date will be considered as a minimum starting date.', 'event-tickets-with-ticket-scanner'),
				'desc_tip'    		=> true
			]);
			// input field for offset last day to choose from in days
			woocommerce_wp_text_input([
				'id'				=> 'saso_eventtickets_daychooser_offset_end',
				'value'       		=> intval(get_post_meta( get_the_ID(), 'saso_eventtickets_daychooser_offset_end', true )),
				'label'       		=> __('Offset days for end date', 'event-tickets-with-ticket-scanner'),
				'type'				=> 'number',
				'custom_attributes'	=> ['step'=>'1', 'min'=>'0'],
				'description' 		=> __('This will set how many days in the future do you allow your customer to choose a date. 0 unlimited into the future, 1 means until tomorrow on and so on. If a end date is set, then this option is ignored and the end date is used.', 'event-tickets-with-ticket-scanner'),
				'desc_tip'    		=> true
			]);
			woocommerce_wp_text_input([
				'id'          => 'saso_eventtickets_request_daychooser_per_ticket_label',
				'value'       => get_post_meta( get_the_ID(), 'saso_eventtickets_request_daychooser_per_ticket_label', true ),
				'label'       => __('Label for the date picker', 'event-tickets-with-ticket-scanner'),
				'description' => __('This is how your customer understand what value should be choosen.', 'event-tickets-with-ticket-scanner'),
				'placeholder' => 'Please choose a day #{count}:',
				'desc_tip'    => true
			]);

			echo '</div>';

			echo '<div class="options_group">';
			$_max_redeem_amount = get_post_meta( get_the_ID(), 'saso_eventtickets_ticket_max_redeem_amount', true );
			if (empty($_max_redeem_amount) || $_max_redeem_amount == "0") {
				$max_redeem_amount = 1;
			} else {
				$max_redeem_amount = intval($_max_redeem_amount);
				if ($max_redeem_amount < 1) $max_redeem_amount = 1;
			}
			woocommerce_wp_text_input([
				'id'				=> 'saso_eventtickets_ticket_max_redeem_amount',
				'value'       		=> $max_redeem_amount,
				'label'       		=> __('Max. redeem operations', 'event-tickets-with-ticket-scanner'),
				'type'				=> 'number',
				'custom_attributes'	=> ['step'=>'1', 'min'=>'0'],
				'description' 		=> __('How often do you allow to redeem the ticket? If you set it to 0, you can redeem the ticket unlimited.', 'event-tickets-with-ticket-scanner'),
				'desc_tip'    		=> true
			]);
			woocommerce_wp_textarea_input([
				'id'          => 'saso_eventtickets_ticket_is_ticket_info',
				'value'       => get_post_meta( get_the_ID(), 'saso_eventtickets_ticket_is_ticket_info', true ),
				'label'       => __('Print this on the ticket', 'event-tickets-with-ticket-scanner'),
				'description' => __('This optional information will be displayed on the ticket detail page.', 'event-tickets-with-ticket-scanner'),
				'desc_tip'    => true
			]);

			/*
			woocommerce_wp_checkbox( array(
				'id'          => 'saso_eventtickets_ticket_is_RTL',
				'value'       => get_post_meta( get_the_ID(), 'saso_eventtickets_ticket_is_RTL', true ),
				'label'       => __('Text is RTL', 'event-tickets-with-ticket-scanner'),
				'description' => __('Activate this, to use language from right to left.', 'event-tickets-with-ticket-scanner')
			));
			*/
			echo '</div>';

			echo '<div class="options_group">';
			$saso_eventtickets_ticket_amount_per_item = intval(get_post_meta( get_the_ID(), 'saso_eventtickets_ticket_amount_per_item', true ));
			if ($saso_eventtickets_ticket_amount_per_item < 1) $saso_eventtickets_ticket_amount_per_item = 1;
			woocommerce_wp_text_input([
				'id'				=> 'saso_eventtickets_ticket_amount_per_item',
				'value'       		=> $saso_eventtickets_ticket_amount_per_item,
				'label'       		=> __('Amount of ticket numbers per item sale', 'event-tickets-with-ticket-scanner'),
				'type'				=> 'number',
				'custom_attributes'	=> ['step'=>'1', 'min'=>'1'],
				'description' 		=> __('How many ticket number to assign if one product is sold?', 'event-tickets-with-ticket-scanner'),
				'desc_tip'    		=> true
			]);
			echo '</div>';

			echo '<div class="options_group">';
			woocommerce_wp_checkbox([
				'id'          => 'saso_eventtickets_request_name_per_ticket',
				'value'       => get_post_meta( get_the_ID(), 'saso_eventtickets_request_name_per_ticket', true ),
				'label'       => __('Request a value for each ticket', 'event-tickets-with-ticket-scanner'),
				'description' => __('Activate this, so that your customer can add a value for each ticket. This could be the name or any other value, defined by you. This value will be printed on the ticket. The value is limited to max 140 letters.', 'event-tickets-with-ticket-scanner')
			]);
			woocommerce_wp_text_input([
				'id'          => 'saso_eventtickets_request_name_per_ticket_label',
				'value'       => get_post_meta( get_the_ID(), 'saso_eventtickets_request_name_per_ticket_label', true ),
				'label'       => __('Label for the value', 'event-tickets-with-ticket-scanner'),
				'description' => __('This is how your customer understand what value should be entered.', 'event-tickets-with-ticket-scanner'),
				'placeholder' => 'Name for the ticket #{count}:',
				'desc_tip'    => true
			]);
			woocommerce_wp_checkbox([
				'id'          => 'saso_eventtickets_request_name_per_ticket_mandatory',
				'value'       => get_post_meta( get_the_ID(), 'saso_eventtickets_request_name_per_ticket_mandatory', true ),
				'label'       => __('The value for each ticket is mandatory', 'event-tickets-with-ticket-scanner'),
				'description' => __('Activate this, so that your customer has to enter a value.', 'event-tickets-with-ticket-scanner')
			]);

			echo "<hr>";

			woocommerce_wp_checkbox([
				'id'          => 'saso_eventtickets_request_value_per_ticket',
				'value'       => get_post_meta( get_the_ID(), 'saso_eventtickets_request_value_per_ticket', true ),
				'label'       => __('Request a value for each ticket from dropdown', 'event-tickets-with-ticket-scanner'),
				'description' => __('Activate this, so that your customer can choose a value for each ticket.', 'event-tickets-with-ticket-scanner')
			]);
			woocommerce_wp_textarea_input([
				'id'          => 'saso_eventtickets_request_value_per_ticket_label',
				'value'       => get_post_meta( get_the_ID(), 'saso_eventtickets_request_value_per_ticket_label', true ),
				'label'       => __('Label for the value', 'event-tickets-with-ticket-scanner'),
				'description' => __('This is how your customer understand what value should be choosen.', 'event-tickets-with-ticket-scanner'),
				'placeholder' => 'Please choose a value #{count}:',
				'desc_tip'    => true
			]);
			woocommerce_wp_textarea_input([
				'id'          => 'saso_eventtickets_request_value_per_ticket_values',
				'value'       => get_post_meta( get_the_ID(), 'saso_eventtickets_request_value_per_ticket_values', true ),
				'label'       => __('Values for the dropdown', 'event-tickets-with-ticket-scanner'),
				'description' => __('Enter per line a key value pair like key|value1. If only key is given per line, then the key will be also the value.', 'event-tickets-with-ticket-scanner'),
				'placeholder' => "|Please choose\nkey1|value1\nkey2|value2\nvalue3",
				'desc_tip'    => true
			]);
			woocommerce_wp_text_input([
				'id'          => 'saso_eventtickets_request_value_per_ticket_def',
				'value'       => get_post_meta( get_the_ID(), 'saso_eventtickets_request_value_per_ticket_def', true ),
				'label'       => __('Enter default key for the dropdown (optional)', 'event-tickets-with-ticket-scanner'),
				'description' => __('If not empty, the system will add the value with this key as the default chosen value.', 'event-tickets-with-ticket-scanner'),
				'placeholder' => 'key1',
				'desc_tip'    => true
			]);
			woocommerce_wp_checkbox([
				'id'          => 'saso_eventtickets_request_value_per_ticket_mandatory',
				'value'       => get_post_meta( get_the_ID(), 'saso_eventtickets_request_value_per_ticket_mandatory', true ),
				'label'       => __('The value for each ticket is mandatory', 'event-tickets-with-ticket-scanner'),
				'description' => __('Activate this, so that your customer has to choose a value.', 'event-tickets-with-ticket-scanner')
			]);
			echo '</div>';

			echo '<div class="options_group">';
			woocommerce_wp_checkbox( array(
				'id'            => 'saso_eventtickets_list_formatter',
				'label'			=> __('Use format settings', 'event-tickets-with-ticket-scanner'),
				'description'   => __('If active, then the format below will be used to generate ticket numbers during a purchase of this product.', 'event-tickets-with-ticket-scanner'),
				'value'         => get_post_meta( get_the_ID(), 'saso_eventtickets_list_formatter', true )
			) );
			echo '<input data-id="saso_eventtickets_list_formatter_values" name="saso_eventtickets_list_formatter_values" type="hidden" value="'.esc_js(get_post_meta( get_the_ID(), 'saso_eventtickets_list_formatter_values', true )).'">';
			echo '<div style="padding-top:10px;padding-left:10%;padding-right:20px;"><b>'.esc_html__('The ticket number format settings.', 'event-tickets-with-ticket-scanner').'</b><br><i>'.esc_html__('This will override an existing and active global "serial code formatter pattern for new sales" and also any format settings from the group.', 'event-tickets-with-ticket-scanner').'</i><div id="saso_eventtickets_list_format_area"></div></div>';
			echo '</div>';

			if ($this->MAIN->isPremium() && method_exists($this->MAIN->getPremiumFunctions(), 'saso_eventtickets_wc_product_panels')) {
				$this->MAIN->getPremiumFunctions()->saso_eventtickets_wc_product_panels(get_the_ID());
			}

			echo '</div>';
		}

		/**
		 * Save product meta data
		 *
		 * Processes and saves all ticket configuration fields from POST data to product meta.
		 * Handles checkboxes, text inputs, number fields, arrays, and HTML content.
		 *
		 * @param int $id Product ID
		 * @param WP_Post $post Post object
		 * @return void
		 */
		public function woocommerce_process_product_meta(int $id, $post): void {
			$R = SASO_EVENTTICKETS::getRequest();

			$key = 'saso_eventtickets_list';
			if( isset($R[$key]) && !empty( $R[$key] ) ) {
				update_post_meta( $id, $key, sanitize_text_field($R[$key]) );
			} else {
				delete_post_meta( $id, $key );
			}

			// damit nicht alte Eintragungen gelöscht werden - so kann der kunde upgrade machen und alles ist noch da
			if (version_compare( WC_VERSION, SASO_EVENTTICKETS_PLUGIN_MIN_WC_VER, '>=' )) {
				$key = 'saso_eventtickets_list_sale_restriction';
				if( isset($R[$key]) && ($R[$key] == '0' || !empty( $R[$key] )) ) {
					update_post_meta( $id, $key, sanitize_text_field($R[$key]) );
				} else {
					delete_post_meta( $id, $key );
				}
			}

			$seating = $this->MAIN->getSeating();
			$keys_checkbox = [
				self::META_PRODUCT_IS_TICKET,
				'saso_eventtickets_is_date_for_all_variants',
				'saso_eventtickets_is_daychooser',
				'saso_eventtickets_only_one_day_for_all_tickets',
				'saso_eventtickets_request_name_per_ticket',
				'saso_eventtickets_request_name_per_ticket_mandatory',
				'saso_eventtickets_request_value_per_ticket',
				'saso_eventtickets_request_value_per_ticket_mandatory',
				'saso_eventtickets_ticket_is_RTL',
				'saso_eventtickets_list_formatter',
				$seating->getMetaProductSeatingRequired()
			];
			foreach($keys_checkbox as $key) {
				if( isset( $R[$key] ) ) {
					update_post_meta( $id, $key, 'yes' );
				} else {
					delete_post_meta( $id, $key );
				}
			}

			$keys_inputfields = [
				'saso_eventtickets_event_location',
				'saso_eventtickets_ticket_start_date',
				'saso_eventtickets_ticket_start_time',
				'saso_eventtickets_ticket_end_date',
				'saso_eventtickets_ticket_end_time',
				'saso_eventtickets_request_name_per_ticket_label',
				'saso_eventtickets_request_value_per_ticket_label',
				'saso_eventtickets_request_value_per_ticket_def',
				'saso_eventtickets_list_formatter_values',
				'saso_eventtickets_request_daychooser_per_ticket_label'
			];

			foreach($keys_inputfields as $key) {
				if( isset($R[$key]) && !empty( $R[$key] ) ) {
					update_post_meta( $id, $key, sanitize_text_field($R[$key]) );
				} else {
					delete_post_meta( $id, $key );
				}
			}

			$key = 'saso_eventtickets_daychooser_exclude_wdays';
			if (isset($R[$key])) {
				$array_to_save = [];
				foreach($R[$key] as $v) {
					$v = sanitize_text_field($v);
					$array_to_save[] = $v;
				}
				update_post_meta( $id, $key, $array_to_save );
			} else {
				delete_post_meta( $id, $key );
			}

			$keys_number = [
				'saso_eventtickets_ticket_max_redeem_amount',
				'saso_eventtickets_ticket_amount_per_item',
				'saso_eventtickets_daychooser_offset_start',
				'saso_eventtickets_daychooser_offset_end'
			];
			foreach($keys_number as $key) {
				if( isset($R[$key]) && !empty($R[$key]) || $R[$key] == "0" ) {
					$value = intval($R[$key]);
					if ($value < 0) $value = 1;
					update_post_meta( $id, $key, $value );
				} else {
					delete_post_meta( $id, $key );
				}
			}

			$key = 'saso_eventtickets_ticket_is_ticket_info';
			if( isset($R[$key]) && !empty( $R[$key] ) ) {
				update_post_meta( $id, $key, wp_kses_post($R[$key]) );
			} else {
				delete_post_meta( $id, $key );
			}
			$key = 'saso_eventtickets_request_value_per_ticket_values';
			if( isset($R[$key]) && !empty( $R[$key] ) ) {
				$v = [];
				foreach(explode("\n", $R[$key]) as $entry) {
					$t = explode("|", $entry);
					if (count($t) > 0) {
						$t[0] = sanitize_key(trim($t[0]));
						if (count($t) > 1) {
							$t[1] = sanitize_key(trim($t[1]));
						}
						$v[] = join("|", $t);
					}
				}
				update_post_meta( $id, $key, join("\n", $v));
			} else {
				delete_post_meta( $id, $key );
			}

			// Seating Plan ID
			$seating_key = $seating->getMetaProductSeatingplan();
			$seating_plan_id = isset($_POST[$seating_key]) ? intval($_POST[$seating_key]) : 0;
			if ($seating_plan_id > 0) {
				update_post_meta($id, $seating_key, $seating_plan_id);
			} else {
				delete_post_meta($id, $seating_key);
			}

			if ($this->MAIN->isPremium() && method_exists($this->MAIN->getPremiumFunctions(), 'saso_eventtickets_wc_save_fields')) {
				$this->MAIN->getPremiumFunctions()->saso_eventtickets_wc_save_fields($id, $post);
			}
		}

		/**
		 * Add fields to product variations
		 *
		 * @param int $loop Loop index
		 * @param array $variation_data Variation data
		 * @param WP_Post $variation Variation post object
		 * @return void
		 */
		public function woocommerce_product_after_variable_attributes(int $loop, array $variation_data, $variation): void {
			echo '<div class="form-row form-row-full form-field">';
			woocommerce_wp_checkbox(
				array(
					'id'          => '_saso_eventtickets_is_not_ticket[' . $loop . ']',
					'label'       => __('This variation is NOT a ticket product', 'event-tickets-with-ticket-scanner'),
					'desc_tip'    => 'true',
					'description' => __('This allows you to exclude a variation to be a ticket', 'event-tickets-with-ticket-scanner'),
					'value'       => get_post_meta($variation->ID, self::META_VARIATION_NOT_TICKET, true)
				)
			);
			echo '<div style="border-left: 5px solid #b225cb;padding-left:30px;margin-left:16px;">';
			woocommerce_wp_text_input([
				'id'                => 'saso_eventtickets_ticket_start_date[' . $loop . ']',
				'value'             => get_post_meta($variation->ID, 'saso_eventtickets_ticket_start_date', true),
				'label'             => __('Start date event', 'event-tickets-with-ticket-scanner'),
				'type'              => 'date',
				'custom_attributes' => ['data-type' => 'date'],
				'description'       => __('Set this to have this printed on the ticket and prevent too early redeemed tickets. Tickets can be redeemed from that day on.', 'event-tickets-with-ticket-scanner'),
				'desc_tip'          => true
			]);
			woocommerce_wp_text_input([
				'id'          => 'saso_eventtickets_ticket_start_time[' . $loop . ']',
				'value'       => get_post_meta($variation->ID, 'saso_eventtickets_ticket_start_time', true),
				'label'       => __('Start time', 'event-tickets-with-ticket-scanner'),
				'type'        => 'time',
				'description' => __('Set this to have this printed on the ticket.', 'event-tickets-with-ticket-scanner'),
				'desc_tip'    => true
			]);
			woocommerce_wp_text_input([
				'id'                => 'saso_eventtickets_ticket_end_date[' . $loop . ']',
				'value'             => get_post_meta($variation->ID, 'saso_eventtickets_ticket_end_date', true),
				'label'             => __('End date event', 'event-tickets-with-ticket-scanner'),
				'type'              => 'date',
				'custom_attributes' => ['data-type' => 'date'],
				'description'       => __('Set this to have this printed on the ticket and prevent later the ticket to be still valid. Tickets cannot be redeemed after that day.', 'event-tickets-with-ticket-scanner'),
				'desc_tip'          => true
			]);
			woocommerce_wp_text_input([
				'id'          => 'saso_eventtickets_ticket_end_time[' . $loop . ']',
				'value'       => get_post_meta($variation->ID, 'saso_eventtickets_ticket_end_time', true),
				'label'       => __('End time', 'event-tickets-with-ticket-scanner'),
				'type'        => 'time',
				'description' => __('Set this to have this printed on the ticket.', 'event-tickets-with-ticket-scanner'),
				'desc_tip'    => true
			]);
			echo '</div>';
			echo '</div>';

			echo '<div class="options_group">';
			$saso_eventtickets_ticket_amount_per_item = intval(get_post_meta($variation->ID, 'saso_eventtickets_ticket_amount_per_item', true));
			if ($saso_eventtickets_ticket_amount_per_item < 1) $saso_eventtickets_ticket_amount_per_item = 1;
			woocommerce_wp_text_input([
				'id'                => 'saso_eventtickets_ticket_amount_per_item[' . $loop . ']',
				'value'             => $saso_eventtickets_ticket_amount_per_item,
				'label'             => __('Amount of ticket numbers per item sale', 'event-tickets-with-ticket-scanner'),
				'type'              => 'number',
				'custom_attributes' => ['step' => '1', 'min' => '1'],
				'description'       => __('How many ticket number to assign if one product is sold?', 'event-tickets-with-ticket-scanner'),
				'desc_tip'          => true
			]);

			// Seating Plan Override for Variation
			$seating = $this->MAIN->getSeating();
			$metaVariationSeatingplan = $seating->getMetaVariationSeatingplan();
			$seatingPlans = $seating->getPlanManager()->getDropdownOptions(true);
			$seatingPlans = ['' => __('-- Use Parent Product Setting --', 'event-tickets-with-ticket-scanner')] + $seatingPlans;
			$variation_seating_id = get_post_meta($variation->ID, $metaVariationSeatingplan, true);

			woocommerce_wp_select([
				'id'            => $metaVariationSeatingplan . '[' . $loop . ']',
				'name'          => $metaVariationSeatingplan . '[' . $loop . ']',
				'value'         => $variation_seating_id ?: '',
				'label'         => __('Seating Plan Override', 'event-tickets-with-ticket-scanner'),
				'options'       => $seatingPlans,
				'description'   => __('Override the parent product seating plan for this variation.', 'event-tickets-with-ticket-scanner'),
				'desc_tip'      => true,
				'wrapper_class' => 'form-row form-row-full'
			]);
			echo '</div>';

			if ($this->MAIN->isPremium() && method_exists($this->MAIN->getPremiumFunctions(), 'woocommerce_product_after_variable_attributes')) {
				$this->MAIN->getPremiumFunctions()->woocommerce_product_after_variable_attributes($loop, $variation_data, $variation);
			}
			echo "<hr>";
		}

		/**
		 * Save product variation meta
		 *
		 * @param int $variation_id Variation ID
		 * @param int $i Loop index
		 * @return void
		 */
		public function woocommerce_save_product_variation(int $variation_id, int $i): void {
			$R = SASO_EVENTTICKETS::getRequest();

			// Checkbox - is NOT a ticket
			$key = self::META_VARIATION_NOT_TICKET;
			if (isset($R[$key]) && isset($R[$key][$i])) {
				update_post_meta($variation_id, $key, 'yes');
			} else {
				delete_post_meta($variation_id, $key);
			}

			// Text input fields - dates and times
			$keys = [
				'saso_eventtickets_ticket_start_date',
				'saso_eventtickets_ticket_start_time',
				'saso_eventtickets_ticket_end_date',
				'saso_eventtickets_ticket_end_time'
			];
			foreach ($keys as $key) {
				if (isset($R[$key]) && isset($R[$key][$i])) {
					update_post_meta($variation_id, $key, sanitize_text_field($R[$key][$i]));
				} else {
					delete_post_meta($variation_id, $key);
				}
			}

			// Number field - ticket amount per item
			$key = 'saso_eventtickets_ticket_amount_per_item';
			if (isset($R[$key]) && isset($R[$key][$i])) {
				$value = intval($R[$key][$i]);
				if ($value < 1) $value = 1;
				update_post_meta($variation_id, $key, $value);
			} else {
				delete_post_meta($variation_id, $key);
			}

			// Seating Plan Override for Variation
			$seating_key = $this->MAIN->getSeating()->getMetaVariationSeatingplan();
			if (isset($_POST[$seating_key]) && isset($_POST[$seating_key][$i])) {
				$value = intval($_POST[$seating_key][$i]);
				if ($value > 0) {
					update_post_meta($variation_id, $seating_key, $value);
				} else {
					delete_post_meta($variation_id, $seating_key);
				}
			} else {
				delete_post_meta($variation_id, $seating_key);
			}

			// Premium extension point
			if ($this->MAIN->isPremium() && method_exists($this->MAIN->getPremiumFunctions(), 'woocommerce_save_product_variation')) {
				$this->MAIN->getPremiumFunctions()->woocommerce_save_product_variation($variation_id, $i);
			}
		}

		/**
		 * Add custom columns to product list
		 *
		 * @param array $columns Existing columns
		 * @return array Modified columns
		 */
		public function manage_edit_product_columns(array $columns): array {
			$new_columns = (is_array($columns)) ? $columns : [];
			$new_columns['SASO_EVENTTICKETS_LIST_COLUMN'] = _x('Ticket List', 'label', 'event-tickets-with-ticket-scanner');
			return $new_columns;
		}

		/**
		 * Display custom column content
		 *
		 * @param string $column Column name
		 * @return void
		 */
		public function manage_product_posts_custom_column(string $column): void {
			global $post;

			if ($column == 'SASO_EVENTTICKETS_LIST_COLUMN') {
				$code_list_ids = get_post_meta($post->ID, 'saso_eventtickets_list', true);

				$lists = $this->MAIN->getAdmin()->getLists();
				$dropdown_list = array('' => '-');
				foreach ($lists as $key => $list) {
					$dropdown_list[$list['id']] = $list['name'];
				}

				if (isset($code_list_ids) && !empty($code_list_ids)) {
					echo !empty($dropdown_list[$code_list_ids]) ? esc_html($dropdown_list[$code_list_ids]) : '-';
				} else {
					echo "-";
				}
			}
		}

		/**
		 * Make custom columns sortable
		 *
		 * @param array $columns Existing sortable columns
		 * @return array Modified sortable columns
		 */
		public function manage_edit_product_sortable_columns(array $columns): array {
			$custom = [
				'SASO_EVENTTICKETS_LIST_COLUMN' => 'saso_eventtickets_list'
			];
			return wp_parse_args($custom, $columns);
		}

		/**
		 * Add meta box to product edit page sidebar
		 *
		 * Displays action buttons for downloading event flyers, ICS files, and ticket information.
		 *
		 * @return void
		 */
		public function wc_product_display_side_box(): void {
			?>
			<p>Download Event Flyer</p>
			<button disabled data-id="<?php echo esc_attr($this->MAIN->getPrefix()."btn_download_flyer"); ?>" class="button button-primary">Download Event Flyer</button>
			<p>Download ICS File (cal file)</p>
			<button disabled data-id="<?php echo esc_attr($this->MAIN->getPrefix()."btn_download_ics"); ?>" class="button button-primary">Download ICS File</button>
			<p>Display all Tickets Infos</p>
			<button disabled data-id="<?php echo esc_attr($this->MAIN->getPrefix()."btn_download_ticket_infos"); ?>" class="button button-primary">Print Ticket Infos</button>
			<?php
			do_action( $this->MAIN->_do_action_prefix.'wc_product_display_side_box', [] );
		}

		/**
		 * Download ticket information for a product
		 *
		 * Retrieves all ticket codes associated with a product and returns
		 * product information along with ticket details.
		 *
		 * @param array $data Request data containing product_id
		 * @return array Ticket information and product data
		 */
		public function downloadTicketInfosOfProduct(array $data): array {
			$product_id = intval($data['product_id']);
			$daten = [];
			$product = [];
			if ($product_id > 0) {
				$daten = $this->MAIN->getAdmin()->getCodesByProductId($product_id);
				$productObj = wc_get_product($product_id);
				if ($productObj != null) {
					$product['name'] = $productObj->get_name();
				}
			}
			return ['ticket_infos' => $daten, 'product' => $product];
		}

		/**
		 * Download all tickets for an order as one PDF
		 *
		 * Generates and outputs a combined PDF containing all tickets for an order.
		 * Note: Despite the name suggesting product scope, this method operates on orders.
		 *
		 * @param array $data Request data containing order_id
		 * @param string $filemode File output mode (I=inline, D=download, F=file)
		 * @return void Exits after output
		 */
		public function downloadAllTicketsAsOnePDF(array $data, string $filemode = "I"): void {
			$order_id = intval($data['order_id']);
			if ($order_id > 0) {
				$order = wc_get_order($order_id);
				$ticketHandler = $this->MAIN->getTicketHandler();
				$ticketHandler->outputPDFTicketsForOrder($order);
				exit;
			} else {
				echo "ORDER ID IS WRONG";
				exit;
			}
		}

		/**
		 * Download event flyer PDF for a product
		 *
		 * Generates a PDF flyer with event details including:
		 * - Banner image
		 * - Event title and date
		 * - Location
		 * - QR code linking to product page
		 * - Price (optional)
		 * - Blog information (optional)
		 * - Logo (optional)
		 *
		 * @param array $data Request data containing product_id
		 * @throws Exception If product_id is missing
		 * @return void
		 */
		public function downloadFlyer(array $data): void {
			if (!isset($data['product_id'])) {
				throw new Exception("#6001 " . esc_html__("Product Id for the event flyer is missing", 'event-tickets-with-ticket-scanner'));
			}
			$product_id = intval($data['product_id']);

			$pdf = $this->MAIN->getNewPDFObject();

			// Load product
			$product = wc_get_product($product_id);
			$titel = $product->get_name();
			$short_desc = $product->get_short_description();
			$location = trim(get_post_meta($product_id, 'saso_eventtickets_event_location', true));

			$dateAsString = $this->MAIN->getTicketHandler()->displayTicketDateAsString($product_id);
			$event_date = "";
			if (!empty($dateAsString)) {
				$event_date = '<br><p style="text-align:center;">';
				$event_date .= $dateAsString;
				$event_date .= '</p>';
			}

			$event_url = get_permalink($product->get_id());

			$pdf->setFilemode('I');

			// Add banner image if configured
			$this->addFlyerBanner($pdf);

			// Add title
			$pdf->addPart('<h1 style="text-align:center;">' . esc_html($titel) . '</h1>');

			// Add event date
			if (!empty($event_date)) {
				$pdf->addPart($event_date);
			}

			// Add location
			if (!empty($location)) {
				$pdf->addPart('<p>' . wp_kses_post($this->MAIN->getOptions()->getOptionValue("wcTicketTransLocation")) . " <b>" . wp_kses_post($location) . "</b></p>");
			}

			// Add QR code placeholder and URL
			$pdf->addPart('{QRCODE_INLINE}');
			$pdf->addPart('<br><p style="font-size:9pt;text-align:center;">' . esc_url($event_url) . '</p>');

			// Add short description
			$pdf->addPart('<br><p style="text-align:center;">' . wp_kses_post($short_desc) . '</p>');

			// Add price if enabled
			if (!$this->MAIN->getOptions()->isOptionCheckboxActive('wcTicketFlyerDontDisplayPrice')) {
				$pdf->addPart('<br><br><p style="text-align:center;font-size:18pt;">' . wc_price($product->get_price(), ['decimals' => 2]) . '</p>');
			}

			// Add blog name if enabled
			$wcTicketFlyerDontDisplayBlogName = $this->MAIN->getOptions()->isOptionCheckboxActive('wcTicketFlyerDontDisplayBlogName');
			if (!$wcTicketFlyerDontDisplayBlogName) {
				$pdf->addPart('<br><br><div style="text-align:center;font-size:10pt;"><b>' . get_bloginfo("name") . '</b></div>');
			}

			// Add blog description if enabled
			if (!$this->MAIN->getOptions()->isOptionCheckboxActive('wcTicketFlyerDontDisplayBlogDesc')) {
				if ($wcTicketFlyerDontDisplayBlogName) {
					$pdf->addPart('<br>');
				}
				$pdf->addPart('<div style="text-align:center;font-size:10pt;">' . get_bloginfo("description") . '</div>');
			}

			// Add blog URL if enabled
			if (!$this->MAIN->getOptions()->isOptionCheckboxActive('wcTicketFlyerDontDisplayBlogURL')) {
				$pdf->addPart('<br><div style="text-align:center;font-size:10pt;">' . site_url() . '</div>');
			}

			// Add logo if configured
			$this->addFlyerLogo($pdf);

			// Add plugin credit
			$pdf->addPart('<br><p style="text-align:center;font-size:9pt;">powered by Event Tickets With Ticket Scanner Plugin for Wordpress</p>');

			// Configure QR code
			$pdf->setQRParams(['style' => ['position' => 'C'], 'align' => 'N']);
			$qrTicketPDFPadding = intval($this->MAIN->getOptions()->getOptionValue('qrTicketPDFPadding'));
			$pdf->setQRCodeContent(["text" => $event_url, "style" => ["vpadding" => $qrTicketPDFPadding, "hpadding" => $qrTicketPDFPadding]]);

			// Add background image if configured
			$this->addFlyerBackground($pdf);

			$pdf->render();
			exit;
		}

		/**
		 * Add banner image to flyer PDF
		 *
		 * @param object $pdf PDF object
		 * @return void
		 */
		private function addFlyerBanner($pdf): void {
			$wcTicketFlyerBanner = $this->MAIN->getOptions()->getOptionValue('wcTicketFlyerBanner');
			if (empty($wcTicketFlyerBanner) || intval($wcTicketFlyerBanner) <= 0) {
				return;
			}

			$mediaData = SASO_EVENTTICKETS::getMediaData($wcTicketFlyerBanner);
			$has_banner = false;

			if ($this->MAIN->getOptions()->isOptionCheckboxActive('wcTicketCompatibilityUseURL')) {
				if (!empty($mediaData['url'])) {
					$pdf->addPart('<div style="text-align:center;"><img src="' . $mediaData['url'] . '"></div>');
					$has_banner = true;
				}
			} else {
				if (!empty($mediaData['for_pdf'])) {
					$pdf->addPart('<div style="text-align:center;"><img src="' . $mediaData['for_pdf'] . '"></div>');
					$has_banner = true;
				}
			}

			if ($has_banner) {
				if (isset($mediaData['meta']) && isset($mediaData['meta']['height']) && floatval($mediaData['meta']['height']) > 0) {
					$dpiY = 96;
					if (function_exists("getimagesize")) {
						$imageInfo = getimagesize($mediaData['location']);
						$dpiY = isset($imageInfo['dpi_y']) ? $imageInfo['dpi_y'] : $dpiY;
					}
					$units = $pdf->convertPixelIntoMm($mediaData['meta']['height'] + 10, $dpiY);
					$pdf->setQRParams(['pos' => ['y' => $units]]);
				}
			}
		}

		/**
		 * Add logo image to flyer PDF
		 *
		 * @param object $pdf PDF object
		 * @return void
		 */
		private function addFlyerLogo($pdf): void {
			$wcTicketFlyerLogo = $this->MAIN->getOptions()->getOptionValue('wcTicketFlyerLogo');
			if (empty($wcTicketFlyerLogo) || intval($wcTicketFlyerLogo) <= 0) {
				return;
			}

			$option_wcTicketFlyerLogo = $this->MAIN->getOptions()->getOption('wcTicketFlyerLogo');
			$mediaData = SASO_EVENTTICKETS::getMediaData($wcTicketFlyerLogo);
			$width = "200";

			if (isset($option_wcTicketFlyerLogo['additional']) && isset($option_wcTicketFlyerLogo['additional']['max']) && isset($option_wcTicketFlyerLogo['additional']['max']['width'])) {
				$width = $option_wcTicketFlyerLogo['additional']['max']['width'];
			}

			if ($this->MAIN->getOptions()->isOptionCheckboxActive('wcTicketCompatibilityUseURL')) {
				if (!empty($mediaData['url'])) {
					$pdf->addPart('<br><br><p style="text-align:center;"><img width="' . $width . '" src="' . $mediaData['url'] . '"></p>');
				}
			} else {
				if (!empty($mediaData['for_pdf'])) {
					$pdf->addPart('<br><br><p style="text-align:center;"><img width="' . $width . '" src="' . $mediaData['for_pdf'] . '"></p>');
				}
			}
		}

		/**
		 * Add background image to flyer PDF
		 *
		 * @param object $pdf PDF object
		 * @return void
		 */
		private function addFlyerBackground($pdf): void {
			$wcTicketFlyerBG = $this->MAIN->getOptions()->getOptionValue('wcTicketFlyerBG');
			if (empty($wcTicketFlyerBG) || intval($wcTicketFlyerBG) <= 0) {
				return;
			}

			$mediaData = SASO_EVENTTICKETS::getMediaData($wcTicketFlyerBG);
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
	}
}
