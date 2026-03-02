<?php
/**
 * Tests for WC Frontend cart handlers:
 * - woocommerce_add_to_cart_handler: stores daychooser dates in cart item
 * - woocommerce_update_cart_validation_handler: validates quantity changes for seated items
 * - woocommerce_checkout_update_order_meta: stores restriction codes (legacy)
 */

class WCFrontendCartHandlerTest extends WP_UnitTestCase {

	private $main;

	public function set_up(): void {
		parent::set_up();
		$this->main = sasoEventtickets::Instance();

		if (!class_exists('WC_Product_Simple')) {
			$this->markTestSkipped('WooCommerce not available');
		}
	}

	private function createTicketProduct(array $extraMeta = []): array {
		$listId = $this->main->getDB()->insert('lists', [
			'name' => 'CartHandler List ' . uniqid(),
			'aktiv' => 1,
			'meta' => '{}',
		]);

		$product = new WC_Product_Simple();
		$product->set_name('CartHandler Ticket ' . uniqid());
		$product->set_regular_price('15.00');
		$product->set_status('publish');
		$product->save();
		$pid = $product->get_id();

		update_post_meta($pid, 'saso_eventtickets_is_ticket', 'yes');
		update_post_meta($pid, 'saso_eventtickets_list', $listId);

		foreach ($extraMeta as $key => $value) {
			update_post_meta($pid, $key, $value);
		}

		return ['product' => $product, 'product_id' => $pid, 'list_id' => $listId];
	}

	// ── woocommerce_add_to_cart_handler: daychooser ─────────────

	public function test_add_to_cart_handler_stores_daychooser_dates(): void {
		$tp = $this->createTicketProduct([
			'saso_eventtickets_is_daychooser' => 'yes',
		]);

		WC()->cart->empty_cart();

		$_REQUEST['is_daychooser'] = '1';
		$_REQUEST[sasoEventtickets_WC_Frontend::FIELD_KEY] = '2026-08-15';

		$cart_item_key = WC()->cart->add_to_cart($tp['product_id'], 2);

		if ($cart_item_key) {
			// The handler is called by WC hook automatically, but let's also call it directly
			$this->main->getWC()->getFrontendManager()->woocommerce_add_to_cart_handler(
				$cart_item_key, $tp['product_id'], 2, 0, [], []
			);

			$cart_contents = WC()->cart->get_cart();
			$item = $cart_contents[$cart_item_key];

			$daychooserKey = '_saso_eventtickets_request_daychooser';
			$this->assertArrayHasKey($daychooserKey, $item);
			$this->assertIsArray($item[$daychooserKey]);
			// Should have 2 date entries (one per quantity)
			$this->assertGreaterThanOrEqual(2, count($item[$daychooserKey]));
			$this->assertEquals('2026-08-15', $item[$daychooserKey][0]);
		} else {
			$this->markTestSkipped('Could not add to cart');
		}

		unset($_REQUEST['is_daychooser'], $_REQUEST[sasoEventtickets_WC_Frontend::FIELD_KEY]);
		WC()->cart->empty_cart();
	}

	public function test_add_to_cart_handler_skips_without_daychooser_flag(): void {
		$tp = $this->createTicketProduct();

		WC()->cart->empty_cart();
		$cart_item_key = WC()->cart->add_to_cart($tp['product_id'], 1);

		if ($cart_item_key) {
			$this->main->getWC()->getFrontendManager()->woocommerce_add_to_cart_handler(
				$cart_item_key, $tp['product_id'], 1, 0, [], []
			);

			$cart_contents = WC()->cart->get_cart();
			$item = $cart_contents[$cart_item_key];

			$daychooserKey = '_saso_eventtickets_request_daychooser';
			$this->assertArrayNotHasKey($daychooserKey, $item);
		} else {
			$this->markTestSkipped('Could not add to cart');
		}

		WC()->cart->empty_cart();
	}

	public function test_add_to_cart_handler_handles_nonexistent_key(): void {
		$tp = $this->createTicketProduct();

		WC()->cart->empty_cart();

		// Call with a key that doesn't exist in cart — should just return
		$this->main->getWC()->getFrontendManager()->woocommerce_add_to_cart_handler(
			'nonexistent_key', $tp['product_id'], 1, 0, [], []
		);

		$this->assertTrue(true); // No exception = pass

		WC()->cart->empty_cart();
	}

	// ── woocommerce_update_cart_validation_handler ───────────────

	public function test_update_cart_validation_passes_when_no_seats(): void {
		$tp = $this->createTicketProduct();

		WC()->cart->empty_cart();
		$cart_item_key = WC()->cart->add_to_cart($tp['product_id'], 2);

		if ($cart_item_key) {
			$cart = WC()->cart->get_cart();
			$values = $cart[$cart_item_key];

			$result = $this->main->getWC()->getFrontendManager()->woocommerce_update_cart_validation_handler(
				true, $cart_item_key, $values, 3
			);

			$this->assertTrue($result);
		} else {
			$this->markTestSkipped('Could not add to cart');
		}

		WC()->cart->empty_cart();
	}

	public function test_update_cart_validation_passes_through_false(): void {
		// If already failed, should return false without further checks
		$result = $this->main->getWC()->getFrontendManager()->woocommerce_update_cart_validation_handler(
			false, 'any_key', [], 1
		);

		$this->assertFalse($result);
	}

	public function test_update_cart_validation_passes_for_same_quantity(): void {
		$tp = $this->createTicketProduct();

		WC()->cart->empty_cart();
		$cart_item_key = WC()->cart->add_to_cart($tp['product_id'], 2);

		if ($cart_item_key) {
			$cart = WC()->cart->get_cart();
			$values = $cart[$cart_item_key];

			// Same quantity = 2
			$result = $this->main->getWC()->getFrontendManager()->woocommerce_update_cart_validation_handler(
				true, $cart_item_key, $values, 2
			);

			$this->assertTrue($result);
		} else {
			$this->markTestSkipped('Could not add to cart');
		}

		WC()->cart->empty_cart();
	}

	public function test_update_cart_validation_passes_for_nonexistent_key(): void {
		WC()->cart->empty_cart();

		$result = $this->main->getWC()->getFrontendManager()->woocommerce_update_cart_validation_handler(
			true, 'nonexistent_cart_key', [], 5
		);

		$this->assertTrue($result);

		WC()->cart->empty_cart();
	}

	// ── woocommerce_checkout_update_order_meta (legacy) ─────────

	public function test_checkout_update_order_meta_skips_when_restriction_disabled(): void {
		// wcRestrictPurchase is disabled by default
		$tp = $this->createTicketProduct();

		$order = wc_create_order();
		$order->add_product($tp['product'], 1);
		$order->set_status('completed');
		$order->save();

		// Should not crash and just return
		$this->main->getWC()->getOrderManager()->woocommerce_checkout_update_order_meta(
			$order->get_id(), []
		);

		$this->assertTrue(true); // No exception = pass
	}
}
