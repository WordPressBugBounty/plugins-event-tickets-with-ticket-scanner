<?php
/**
 * Seating Integration Base Class
 *
 * Provides shared constants for all Seating classes.
 * Keeps it minimal - uses existing infrastructure from sasoEventtickets_Base.
 *
 * @package    Event_Tickets_With_Ticket_Scanner
 * @subpackage Seating
 * @since      2.8.0
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
	exit;
}

/**
 * Base Class for Seating Integration
 *
 * Only constants and minimal shared utilities.
 * Limit checks use $this->MAIN->getBase()->getMaxValue()
 *
 * @since 2.8.0
 */
if (!class_exists('sasoEventtickets_Seating_Base')) {
	abstract class sasoEventtickets_Seating_Base {

		/**
		 * Main plugin instance
		 *
		 * @var sasoEventtickets
		 */
		protected $MAIN;

		// =====================================================================
		// Meta Key Methods (using prefix from MAIN)
		// =====================================================================

		/**
		 * Get meta key prefix (public keys)
		 *
		 * @return string Prefix like 'sasoEventtickets_'
		 */
		protected function getMetaPrefix(): string {
			return $this->MAIN->getPrefix() . '_';
		}

		/**
		 * Get meta key prefix (private keys, prefixed with underscore)
		 *
		 * @return string Prefix like '_sasoEventtickets_'
		 */
		protected function getMetaPrefixPrivate(): string {
			return '_' . $this->MAIN->getPrefix() . '_';
		}

		/**
		 * Get meta key: Seatingplan ID assigned to product
		 *
		 * @return string Meta key
		 */
		public function getMetaProductSeatingplan(): string {
			return $this->getMetaPrefix() . 'seatingplan_id';
		}

		/**
		 * Get meta key: Product requires seat selection (value: "yes")
		 *
		 * @return string Meta key
		 */
		public function getMetaProductSeatingRequired(): string {
			return $this->getMetaPrefix() . 'seating_required';
		}

		/**
		 * Get meta key: Seatingplan ID override for variation (private)
		 *
		 * @return string Meta key
		 */
		public function getMetaVariationSeatingplan(): string {
			return $this->getMetaPrefixPrivate() . 'seatingplan_id';
		}

		/**
		 * Get meta key: Selected seat data on order item (JSON, private)
		 *
		 * @return string Meta key
		 */
		public function getMetaOrderItemSeat(): string {
			return $this->getMetaPrefixPrivate() . 'seat';
		}

		/**
		 * Get meta key: Seat block ID reference on order item (private)
		 *
		 * @return string Meta key
		 */
		public function getMetaOrderItemSeatBlockId(): string {
			return $this->getMetaPrefixPrivate() . 'seat_block_id';
		}

		/**
		 * Get meta key: Selected seat in cart item (JSON, private)
		 *
		 * @return string Meta key
		 */
		public function getMetaCartItemSeat(): string {
			return $this->getMetaPrefixPrivate() . 'seat_selection';
		}

		/**
		 * Get form field name for seat selection input
		 *
		 * @return string Field name
		 */
		public function getFieldSeatSelection(): string {
			return $this->getMetaPrefix() . 'seat_selection';
		}

		// =====================================================================
		// Seat Block Status Constants
		// =====================================================================

		/** @var string Status: temporarily blocked (session-based) */
		public const STATUS_BLOCKED = 'blocked';

		/** @var string Status: confirmed (order completed) */
		public const STATUS_CONFIRMED = 'confirmed';

		/** @var string Status: released (refund/cancel) */
		public const STATUS_RELEASED = 'released';

		// =====================================================================
		// Block Type Constants
		// =====================================================================

		/** @var string Block type: session-based temporary block */
		public const BLOCK_TYPE_SESSION = 'session';

		/** @var string Block type: order-based confirmed block */
		public const BLOCK_TYPE_ORDER = 'order';

		/** @var string Block type: admin-created block */
		public const BLOCK_TYPE_ADMIN = 'admin';

		// =====================================================================
		// Layout Type Constants
		// =====================================================================

		/** @var string Layout type: simple text/dropdown */
		public const LAYOUT_SIMPLE = 'simple';

		/** @var string Layout type: visual SVG/Canvas editor */
		public const LAYOUT_VISUAL = 'visual';

		// =====================================================================
		// Default Values
		// =====================================================================

		/** @var int Default seat block timeout in minutes */
		public const DEFAULT_BLOCK_TIMEOUT_MINUTES = 15;

		/**
		 * Constructor
		 *
		 * @param sasoEventtickets $main Main plugin instance
		 */
		public function __construct($main) {
			$this->MAIN = $main;
		}

		/**
		 * Get table name with prefix
		 *
		 * @param string $table Table name without prefix
		 * @return string Full table name
		 */
		protected function getTable(string $table): string {
			return $this->MAIN->getDB()->getTabelle($table);
		}

		/**
		 * Get WooCommerce session ID
		 *
		 * @return string|null Session ID or null if not available
		 */
		protected function getSessionId(): ?string {
			if (function_exists('WC') && WC()->session) {
				return WC()->session->get_customer_id();
			}
			return null;
		}

		// =====================================================================
		// Meta Object Pattern (from Core.php)
		// =====================================================================

		/**
		 * Get meta object structure with all defaults
		 *
		 * IMPORTANT: This defines ALL possible meta fields with defaults.
		 * - New fields added here are automatically available for old stored data
		 * - Premium plugin can add fields via hook without basic plugin update
		 * - Always add new fields HERE first before using them anywhere in code
		 *
		 * Each child class MUST implement this with their specific fields.
		 *
		 * @return array Meta object structure with all defaults
		 */
		abstract public function getMetaObject(): array;

		/**
		 * Decode and merge stored meta with defaults
		 *
		 * Uses Core's generic decodeAndMergeMeta() with this class's getMetaObject().
		 *
		 * @param string|null $metaJson JSON meta string from DB
		 * @return array Merged meta array with all fields guaranteed
		 */
		protected function decodeAndMergeMeta(?string $metaJson): array {
			return $this->MAIN->getCore()->decodeAndMergeMeta($metaJson, $this->getMetaObject());
		}

		// =====================================================================
		// Product-Plan Resolution (Shared)
		// =====================================================================

		/**
		 * Resolve seating plan ID for a product (with variation support)
		 *
		 * Checks in this order:
		 * 1. Variation-specific plan (if variationId given)
		 * 2. If productId is a variation: check it, then fall back to parent
		 * 3. Main product plan
		 *
		 * Note: Caller is responsible for WPML normalization via getWPMLProductId()
		 *
		 * @param int $productId Product ID (or variation ID)
		 * @param int|null $variationId Variation ID (optional)
		 * @return int|null Plan ID or null if no plan found
		 */
		protected function resolvePlanIdForProduct(int $productId, ?int $variationId = null): ?int {
			$planId = null;

			// Check variation first if given
			if ($variationId) {
				$planId = get_post_meta($variationId, $this->getMetaVariationSeatingplan(), true);
			}

			// Also check if productId itself is a variation
			if (empty($planId) && get_post_type($productId) === 'product_variation') {
				$planId = get_post_meta($productId, $this->getMetaVariationSeatingplan(), true);
				// Fall back to parent product
				if (empty($planId)) {
					$parentId = wp_get_post_parent_id($productId);
					if ($parentId) {
						$planId = get_post_meta($parentId, $this->getMetaProductSeatingplan(), true);
					}
				}
			}

			// Check main product
			if (empty($planId)) {
				$planId = get_post_meta($productId, $this->getMetaProductSeatingplan(), true);
			}

			return empty($planId) ? null : (int) $planId;
		}

		/**
		 * Get seating plan for a product (with variation support)
		 *
		 * Returns full plan data - use for admin/internal operations.
		 * Note: Caller is responsible for WPML normalization.
		 *
		 * @param int $productId Product ID (or variation ID)
		 * @param int|null $variationId Variation ID (optional)
		 * @return array|null Plan data or null
		 */
		public function getPlanForProduct(int $productId, ?int $variationId = null): ?array {
			// Validate product ID
			if ($productId <= 0) {
				$this->MAIN->getDB()->logError('getPlanForProduct: Invalid product ID: ' . $productId);
				return null;
			}

			$planId = $this->resolvePlanIdForProduct($productId, $variationId);
			return $planId ? $this->MAIN->getSeating()->getPlanManager()->getById($planId) : null;
		}
	}
}
