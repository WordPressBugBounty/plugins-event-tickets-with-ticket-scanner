<?php
/**
 * WooCommerce Integration Base Class
 *
 * Provides shared utilities and common methods for all WooCommerce integration classes.
 *
 * @package    Event_Tickets_With_Ticket_Scanner
 * @subpackage WooCommerce
 * @since      2.9.0
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
	exit;
}

/**
 * Base Class for WooCommerce Integration
 *
 * Provides common functionality shared across all WooCommerce integration classes.
 * All specialized WC classes should extend this base class.
 *
 * @since 2.9.0
 */
if (!class_exists('sasoEventtickets_WC_Base')) {
	abstract class sasoEventtickets_WC_Base {

		/**
		 * Main plugin instance
		 *
		 * @var sasoEventtickets
		 */
		protected $MAIN;

		// =====================================================================
		// Order Item Meta Keys (stored on WC_Order_Item)
		// =====================================================================

		/** @var string Ticket codes assigned to order item (comma-separated) */
		public const META_ORDER_ITEM_CODES = '_saso_eventtickets_product_code';

		/** @var string Public ticket IDs for order item (comma-separated) */
		public const META_ORDER_ITEM_PUBLIC_IDS = '_saso_eventtickets_public_ticket_ids';

		/** @var string Flag indicating order item is a ticket (value: 1) */
		public const META_ORDER_ITEM_IS_TICKET = '_saso_eventtickets_is_ticket';

		/** @var string Selected days for day chooser tickets (comma-separated) */
		public const META_ORDER_ITEM_DAYCHOOSER = '_saso_eventtickets_daychooser';

		/** @var string Code list ID for the ticket */
		public const META_ORDER_ITEM_CODE_LIST = '_saso_eventticket_code_list';

		/** @var string Restriction code used for purchase */
		public const META_ORDER_ITEM_RESTRICTION = '_saso_eventticket_list_sale_restriction';

		/** @var string Seat IDs for display (comma-separated) */
		public const META_ORDER_ITEM_SEAT_IDS = '_sasoEventtickets_seat_ids';

		/** @var string Seat labels for display (JSON array) */
		public const META_ORDER_ITEM_SEAT_LABELS = '_sasoEventtickets_seat_labels';

		/** @var string Seating plan ID for the selected seats */
		public const META_ORDER_ITEM_SEATING_PLAN_ID = '_sasoEventtickets_seating_plan_id';

		// =====================================================================
		// Order Meta Keys (stored on WC_Order)
		// =====================================================================

		/** @var string Unique order identification code */
		public const META_ORDER_IDCODE = '_saso_eventtickets_order_idcode';

		// =====================================================================
		// Product Meta Keys (stored on product post meta)
		// =====================================================================

		/** @var string Flag indicating product is a ticket (value: "yes") */
		public const META_PRODUCT_IS_TICKET = 'saso_eventtickets_is_ticket';

		/** @var string Ticket list ID for the product */
		public const META_PRODUCT_LIST = 'saso_eventtickets_list';

		/** @var string Flag indicating product uses day chooser (value: "yes") */
		public const META_PRODUCT_IS_DAYCHOOSER = 'saso_eventtickets_is_daychooser';

		/** @var string Number of tickets per quantity item */
		public const META_PRODUCT_TICKETS_PER_ITEM = 'saso_eventtickets_ticket_amount_per_item';

		/** @var string Product restriction code list ID */
		public const META_PRODUCT_RESTRICTION = 'saso_eventtickets_list_sale_restriction';

		// =====================================================================
		// Variation Meta Keys (stored on variation post meta)
		// =====================================================================

		/** @var string Flag indicating variation is NOT a ticket (value: "yes") */
		public const META_VARIATION_NOT_TICKET = '_saso_eventtickets_is_not_ticket';

		// =====================================================================
		// Session/Request Keys
		// =====================================================================

		/** @var string Session key for day chooser selection */
		public const SESSION_KEY_DAYCHOOSER = '_saso_eventtickets_request_daychooser';

		// =====================================================================
		// Legacy constants (for backwards compatibility)
		// =====================================================================

		/** @deprecated Use META_PRODUCT_RESTRICTION instead */
		public const META_KEY_CODELIST_RESTRICTION = 'saso_eventtickets_list_sale_restriction';

		/** @deprecated Use META_ORDER_ITEM_RESTRICTION instead */
		public const META_KEY_CODELIST_RESTRICTION_ORDER_ITEM = '_saso_eventticket_list_sale_restriction';

		/**
		 * Constructor
		 *
		 * @param sasoEventtickets $main Main plugin instance
		 */
		public function __construct($main) {
			$this->MAIN = $main;
		}

		/**
		 * Set a value in WooCommerce session
		 *
		 * @param string $name Session key name (without prefix)
		 * @param mixed $value Value to store
		 * @return bool True on success, false if session not available
		 */
		protected function session_set_value(string $name, $value): bool {
			$prefix = $this->MAIN->_prefix_session;
			$key = $prefix . $name;
			if (WC() !== null && WC()->session !== null) {
				WC()->session->set($key, $value);
				return true;
			}
			return false;
		}

		/**
		 * Get a value from WooCommerce session
		 *
		 * @param string $name Session key name (without prefix)
		 * @return mixed|null Value or null if not found/session unavailable
		 */
		protected function session_get_value(string $name) {
			$prefix = $this->MAIN->_prefix_session;
			$key = $prefix . $name;
			if (WC() !== null && WC()->session !== null) {
				return WC()->session->get($key);
			}
			return null;
		}

	}
}
