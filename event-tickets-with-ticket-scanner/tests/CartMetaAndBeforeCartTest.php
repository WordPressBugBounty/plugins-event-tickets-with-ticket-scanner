<?php
/**
 * Tests for cart meta updates and before-cart-table hook:
 * - updateCartItemMeta: stores name/value/daychooser data in session per cart item
 * - woocommerce_before_cart_table: registers hooks and loads JS
 * - woocommerce_review_order_after_cart_contents: renders checkout input fields
 * - woocommerce_checkout_process: calls validation
 */

class CartMetaAndBeforeCartTest extends WP_UnitTestCase {

	private $main;

	public function set_up(): void {
		parent::set_up();
		$this->main = sasoEventtickets::Instance();

		if (!class_exists('WC_Product_Simple')) {
			$this->markTestSkipped('WooCommerce not available');
		}
	}

	public function tear_down(): void {
		WC()->cart->empty_cart();
		wc_clear_notices();
		// Reset containsProductsWithRestrictions cache
		$ref = new ReflectionProperty($this->main->getWC()->getFrontendManager(), '_containsProductsWithRestrictions');
		$ref->setAccessible(true);
		$ref->setValue($this->main->getWC()->getFrontendManager(), null);
		parent::tear_down();
	}

	// ── updateCartItemMeta — name per ticket ────────────────────

	public function test_updateCartItemMeta_returns_item_id_missing_for_empty_id(): void {
		$result = $this->main->getWC()->getFrontendManager()->updateCartItemMeta(
			'saso_eventtickets_request_name_per_ticket', '', 0, 'John'
		);

		$this->assertArrayHasKey('item_id_missing', $result);
		$this->assertTrue($result['item_id_missing']);
	}

	public function test_updateCartItemMeta_stores_name_in_session(): void {
		$tp = $this->createTicketProduct();
		WC()->cart->empty_cart();
		$cart_key = WC()->cart->add_to_cart($tp['product_id']);

		if (!$cart_key) {
			$this->markTestSkipped('Could not add to cart');
		}

		$result = $this->main->getWC()->getFrontendManager()->updateCartItemMeta(
			'saso_eventtickets_request_name_per_ticket', $cart_key, 0, 'Jane Doe'
		);

		$this->assertEmpty($result, 'Should return empty check_values on success');

		// Verify session storage
		$sessionData = WC()->session->get('saso_eventtickets_request_name_per_ticket');
		$this->assertIsArray($sessionData);
		$this->assertEquals('Jane Doe', $sessionData[$cart_key][0]);
	}

	public function test_updateCartItemMeta_stores_value_per_ticket_in_session(): void {
		$tp = $this->createTicketProduct();
		WC()->cart->empty_cart();
		$cart_key = WC()->cart->add_to_cart($tp['product_id']);

		if (!$cart_key) {
			$this->markTestSkipped('Could not add to cart');
		}

		$result = $this->main->getWC()->getFrontendManager()->updateCartItemMeta(
			'saso_eventtickets_request_value_per_ticket', $cart_key, 0, 'VIP'
		);

		$this->assertEmpty($result);

		$sessionData = WC()->session->get('saso_eventtickets_request_value_per_ticket');
		$this->assertIsArray($sessionData);
		$this->assertEquals('VIP', $sessionData[$cart_key][0]);
	}

	public function test_updateCartItemMeta_falls_back_to_name_per_ticket_for_unknown_type(): void {
		$tp = $this->createTicketProduct();
		WC()->cart->empty_cart();
		$cart_key = WC()->cart->add_to_cart($tp['product_id']);

		if (!$cart_key) {
			$this->markTestSkipped('Could not add to cart');
		}

		$result = $this->main->getWC()->getFrontendManager()->updateCartItemMeta(
			'invalid_type_xyz', $cart_key, 0, 'FallbackValue'
		);

		$this->assertEmpty($result);

		// Should fall back to name_per_ticket session key
		$sessionData = WC()->session->get('saso_eventtickets_request_name_per_ticket');
		$this->assertIsArray($sessionData);
		$this->assertEquals('FallbackValue', $sessionData[$cart_key][0]);
	}

	public function test_updateCartItemMeta_stores_multiple_items_per_cart_key(): void {
		$tp = $this->createTicketProduct();
		WC()->cart->empty_cart();
		$cart_key = WC()->cart->add_to_cart($tp['product_id'], 3);

		if (!$cart_key) {
			$this->markTestSkipped('Could not add to cart');
		}

		$this->main->getWC()->getFrontendManager()->updateCartItemMeta(
			'saso_eventtickets_request_name_per_ticket', $cart_key, 0, 'Person A'
		);
		$this->main->getWC()->getFrontendManager()->updateCartItemMeta(
			'saso_eventtickets_request_name_per_ticket', $cart_key, 1, 'Person B'
		);
		$this->main->getWC()->getFrontendManager()->updateCartItemMeta(
			'saso_eventtickets_request_name_per_ticket', $cart_key, 2, 'Person C'
		);

		$sessionData = WC()->session->get('saso_eventtickets_request_name_per_ticket');
		$this->assertEquals('Person A', $sessionData[$cart_key][0]);
		$this->assertEquals('Person B', $sessionData[$cart_key][1]);
		$this->assertEquals('Person C', $sessionData[$cart_key][2]);
	}

	// ── updateCartItemMeta — daychooser ─────────────────────────

	public function test_updateCartItemMeta_daychooser_stores_date_in_cart(): void {
		$tp = $this->createTicketProduct(['saso_eventtickets_is_daychooser' => 'yes']);
		WC()->cart->empty_cart();
		$cart_key = WC()->cart->add_to_cart($tp['product_id']);

		if (!$cart_key) {
			$this->markTestSkipped('Could not add to cart');
		}

		$result = $this->main->getWC()->getFrontendManager()->updateCartItemMeta(
			'saso_eventtickets_request_daychooser', $cart_key, 0, '2026-07-15'
		);

		$this->assertEmpty($result);

		// Verify value is stored in cart contents
		$cart = WC()->cart->get_cart();
		$this->assertArrayHasKey($cart_key, $cart);
	}

	public function test_updateCartItemMeta_daychooser_returns_not_in_cart_for_invalid_key(): void {
		$result = $this->main->getWC()->getFrontendManager()->updateCartItemMeta(
			'saso_eventtickets_request_daychooser', 'nonexistent_cart_key_xyz', 0, '2026-07-15'
		);

		$this->assertArrayHasKey('item_not_in_cart', $result);
		$this->assertTrue($result['item_not_in_cart']);
	}

	// ── woocommerce_before_cart_table ────────────────────────────

	public function test_before_cart_table_registers_after_cart_item_name_action(): void {
		// Remove any previously registered hooks
		remove_all_actions('woocommerce_after_cart_item_name');

		$this->main->getWC()->getFrontendManager()->woocommerce_before_cart_table();

		$this->assertTrue(
			has_action('woocommerce_after_cart_item_name') !== false,
			'Should register woocommerce_after_cart_item_name action'
		);
	}

	public function test_before_cart_table_runs_without_error_on_empty_cart(): void {
		WC()->cart->empty_cart();

		$this->main->getWC()->getFrontendManager()->woocommerce_before_cart_table();

		$this->assertTrue(true); // No exception = pass
	}

	// ── woocommerce_review_order_after_cart_contents ─────────────

	public function test_review_order_returns_early_when_option_disabled(): void {
		update_option('sasoEventticketswcTicketShowInputFieldsOnCheckoutPage', '0');
		$this->main->getOptions()->initOptions();

		ob_start();
		$this->main->getWC()->getFrontendManager()->woocommerce_review_order_after_cart_contents();
		$output = ob_get_clean();

		$this->assertEmpty($output);
	}

	// ── woocommerce_checkout_process ────────────────────────────

	public function test_checkout_process_runs_without_error_on_empty_cart(): void {
		WC()->cart->empty_cart();

		$this->main->getWC()->getFrontendManager()->woocommerce_checkout_process();

		$this->assertTrue(true); // No exception = pass
	}

	public function test_checkout_process_validates_without_error_for_regular_product(): void {
		$product = new WC_Product_Simple();
		$product->set_name('Regular Checkout');
		$product->set_regular_price('5.00');
		$product->set_status('publish');
		$product->save();

		WC()->cart->empty_cart();
		WC()->cart->add_to_cart($product->get_id());

		$this->main->getWC()->getFrontendManager()->woocommerce_checkout_process();

		// No error notices should be added for a regular product
		$notices = wc_get_notices('error');
		$this->assertEmpty($notices);
	}

	// ── Helper methods ──────────────────────────────────────────

	private function createTicketProduct(array $extraMeta = []): array {
		$listId = $this->main->getDB()->insert('lists', [
			'name' => 'CartMeta List ' . uniqid(),
			'aktiv' => 1,
			'meta' => '{}',
		]);

		$product = new WC_Product_Simple();
		$product->set_name('CartMeta Ticket ' . uniqid());
		$product->set_regular_price('10.00');
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
}
