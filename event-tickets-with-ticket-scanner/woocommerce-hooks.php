<?php
include_once(plugin_dir_path(__FILE__)."init_file.php");
if (!defined('SASO_EVENTTICKETS_PLUGIN_MIN_WC_VER')) define( 'SASO_EVENTTICKETS_PLUGIN_MIN_WC_VER', '4.0' );

use Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController;

class sasoEventtickets_WC {
	private $MAIN;

	/**
	 * Product manager instance
	 *
	 * @var sasoEventtickets_WC_Product|null
	 */
	private $productManager = null;

	/**
	 * Email attachment handler instance
	 *
	 * @var sasoEventtickets_WC_Email|null
	 */
	private $emailHandler = null;

	/**
	 * Order manager instance
	 *
	 * @var sasoEventtickets_WC_Order|null
	 */
	private $orderManager = null;

	/**
	 * Frontend handler instance
	 *
	 * @var sasoEventtickets_WC_Frontend|null
	 */
	private $frontendManager = null;

	/**
	 * Singleton pattern - get instance
	 *
	 * @return sasoEventtickets_WC
	 */
	public static function Instance() {
		static $inst = null;
		if ($inst === null) {
			$inst = new sasoEventtickets_WC();
		}
		return $inst;
	}

	public function __construct() {
		global $sasoEventtickets;
		$this->MAIN = $sasoEventtickets;
		// NOTE: Manager classes are now lazy-loaded via getters (getProductManager, getEmailHandler, getOrderManager)
		// This prevents unnecessary instantiation and follows the plugin's lazy loading pattern
	}

	/**
	 * Load a WooCommerce integration class from /includes/woocommerce/
	 *
	 * @param string $filename The class file name (e.g., 'class-product.php')
	 * @param string $className The class name to check if already loaded
	 * @return void
	 */
	private function loadWCClass(string $filename, string $className): void {
		if (class_exists($className)) {
			return;
		}

		// Load base class first
		$basePath = plugin_dir_path(__FILE__) . 'includes/woocommerce/class-base.php';
		if (file_exists($basePath) && !class_exists('sasoEventtickets_WC_Base')) {
			require_once $basePath;
		}

		// Load requested class
		$path = plugin_dir_path(__FILE__) . 'includes/woocommerce/' . $filename;
		if (file_exists($path)) {
			require_once $path;
		}
	}

	/**
	 * Get Product Manager instance (lazy loading)
	 *
	 * @return sasoEventtickets_WC_Product
	 */
	public function getProductManager() {
		if ($this->productManager === null) {
			$this->loadWCClass('class-product.php', 'sasoEventtickets_WC_Product');
			$this->productManager = new sasoEventtickets_WC_Product($this->MAIN);
		}
		return $this->productManager;
	}

	/**
	 * Get Email Handler instance (lazy loading)
	 *
	 * @return sasoEventtickets_WC_Email
	 */
	public function getEmailHandler() {
		if ($this->emailHandler === null) {
			$this->loadWCClass('class-email.php', 'sasoEventtickets_WC_Email');
			$this->emailHandler = new sasoEventtickets_WC_Email($this->MAIN);
		}
		return $this->emailHandler;
	}

	/**
	 * Get Order Manager instance (lazy loading)
	 *
	 * @return sasoEventtickets_WC_Order
	 */
	public function getOrderManager() {
		if ($this->orderManager === null) {
			$this->loadWCClass('class-order.php', 'sasoEventtickets_WC_Order');
			$this->orderManager = new sasoEventtickets_WC_Order($this->MAIN);
		}
		return $this->orderManager;
	}

	/**
	 * Get Frontend Manager instance (lazy loading)
	 *
	 * @return sasoEventtickets_WC_Frontend
	 */
	public function getFrontendManager() {
		if ($this->frontendManager === null) {
			$this->loadWCClass('class-frontend.php', 'sasoEventtickets_WC_Frontend');
			$this->frontendManager = new sasoEventtickets_WC_Frontend($this->MAIN);
		}
		return $this->frontendManager;
	}

	public function executeJSON($a, $data=[], $just_ret=false) {
		$ret = "";
		$justJSON = false;
		try {
			switch (trim($a)) {
				case "downloadFlyer":
					$ret = $this->getProductManager()->downloadFlyer($data);
					break;
				case "downloadICSFile":
					$product_id = intval($data['product_id']);
					$this->MAIN->getTicketHandler()->sendICSFileByProductId($product_id);
					exit;
				case "downloadTicketInfosOfProduct":
					$ret = $this->getProductManager()->downloadTicketInfosOfProduct($data);
					break;
				case "downloadAllTicketsAsOnePDF":
					$ret = $this->getProductManager()->downloadAllTicketsAsOnePDF($data);
					break;
				case "removeAllTicketsFromOrder":
					$ret = $this->getOrderManager()->removeAllTicketsFromOrder($data);
					break;
				case "removeAllNonTicketsFromOrder":
					$ret = $this->getOrderManager()->removeAllNonTicketsFromOrder($data);
					break;
				case "downloadPDFTicketBadge":
					$this->MAIN->getAdmin()->downloadPDFTicketBadge($data);
					exit;
				default:
					throw new Exception("#6000 ".sprintf(/* translators: %s: name of called function */esc_html__('function "%s" in wc backend not implemented', 'event-tickets-with-ticket-scanner'), $a));
			}
		} catch(Exception $e) {
			$this->MAIN->getAdmin()->logErrorToDB($e);
			if ($just_ret) throw $e;
			return wp_send_json_error ($e->getMessage());
		}
		if ($just_ret) return $ret;
		if ($justJSON) return wp_send_json($ret);
		else return wp_send_json_success( $ret );
	}

	// =========================================================================
	// PUBLIC API - Backwards Compatibility for Premium Plugin
	// TODO: Remove after premium version > 1.5.6 is released and uses direct
	//       manager calls (e.g., getOrderManager()->getTicketsFromOrder())
	// =========================================================================

	/**
	 * Get all tickets from order
	 *
	 * @deprecated Use getOrderManager()->getTicketsFromOrder() instead
	 * @todo Remove after premium > 1.5.6 - used in sasoEventtickets_PremiumFunctions.php:157
	 *
	 * @param WC_Order $order Order object
	 * @return array Tickets array with product info and codes
	 */
	public function getTicketsFromOrder($order) {
		return $this->getOrderManager()->getTicketsFromOrder($order);
	}

	/**
	 * Generate ticket codes for an order
	 *
	 * @deprecated Use getOrderManager()->add_serialcode_to_order() instead
	 * @todo Remove after premium > 1.5.6 - used in sasoEventtickets_PremiumFunctions.php:1455
	 *
	 * @param int $order_id Order ID
	 * @return void
	 */
	public function add_serialcode_to_order($order_id) {
		$this->getOrderManager()->add_serialcode_to_order(intval($order_id));
	}

    public function add_meta_boxes($post_type, $post) {
		$screen = $post_type;
		if ($screen == null) { // add HPOS support from woocommerce
			$screen = class_exists( '\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController' ) && wc_get_container()->get( CustomOrdersTableController::class )->custom_orders_table_usage_is_enabled()
			? wc_get_page_screen_id( 'shop-order' )
			: 'shop_order';
		}

		if( $screen == 'product' ) {
			// Only show meta box for ticket products
			if( !$this->getProductManager()->isTicketByProductId($post->ID) ) return;
			add_meta_box(
				$this->MAIN->getPrefix()."_wc_product_webhook", // Unique ID
				esc_html_x('Event Tickets', 'title', 'event-tickets-with-ticket-scanner'),  // Box title
				[$this, 'wc_product_display_side_box'],  // Content callback, must be of type callable
				$screen,
				'side',
				'high'
			);
		} elseif ($screen == "shop_order" || $screen == "woocommerce_page_wc-orders") {
			add_meta_box(
				$this->MAIN->getPrefix()."_wc_order_webhook_basic", // Unique ID
				esc_html_x('Event Tickets', 'title', 'event-tickets-with-ticket-scanner'),  // Box title
				[$this, 'wc_order_display_side_box'],  // Content callback, must be of type callable
				$screen,
				'side',
				'high'
			);
		}
    }

    public function wc_product_display_side_box() {
		$this->getProductManager()->wc_product_display_side_box();
    }

	public function wc_order_display_side_box($post_or_order_object) {
		$this->getOrderManager()->wc_order_display_side_box($post_or_order_object);
	}

}
?>