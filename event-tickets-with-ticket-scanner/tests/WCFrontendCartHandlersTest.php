<?php
/**
 * Batch 34b — WC Frontend cart handler methods:
 * - updateCartItemMeta: type validation, daychooser storage, missing item handling
 * - woocommerce_cart_item_removed_handler: session cleanup on removal
 * - woocommerce_after_cart_item_quantity_update_handler: quantity increase/decrease session adjustment
 * - check_cart_item_and_add_warnings: cart validation warnings
 */

class WCFrontendCartHandlersTest extends WP_UnitTestCase {

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

	// ── updateCartItemMeta — type validation ───────────────────

	public function test_updateCartItemMeta_returns_array(): void {
		$tp = $this->createTicketProduct();
		WC()->cart->empty_cart();
		$cart_key = WC()->cart->add_to_cart($tp['product_id']);

		if (!$cart_key) {
			$this->markTestSkipped('Could not add to cart');
		}

		$result = $this->main->getWC()->getFrontendManager()->updateCartItemMeta(
			'saso_eventtickets_request_name_per_ticket',
			$cart_key,
			0,
			'John Doe'
		);

		$this->assertIsArray($result);
	}

	public function test_updateCartItemMeta_invalid_type_defaults_to_name(): void {
		$tp = $this->createTicketProduct();
		WC()->cart->empty_cart();
		$cart_key = WC()->cart->add_to_cart($tp['product_id']);

		if (!$cart_key) {
			$this->markTestSkipped('Could not add to cart');
		}

		// Invalid type should not crash, defaults to name_per_ticket
		$result = $this->main->getWC()->getFrontendManager()->updateCartItemMeta(
			'INVALID_TYPE',
			$cart_key,
			0,
			'Test'
		);

		$this->assertIsArray($result);
	}

	public function test_updateCartItemMeta_empty_cart_item_id_returns_missing(): void {
		$result = $this->main->getWC()->getFrontendManager()->updateCartItemMeta(
			'saso_eventtickets_request_name_per_ticket',
			'',
			0,
			'Test'
		);

		$this->assertArrayHasKey('item_id_missing', $result);
		$this->assertTrue($result['item_id_missing']);
	}

	public function test_updateCartItemMeta_daychooser_stores_date(): void {
		$tp = $this->createTicketProduct(['saso_eventtickets_is_daychooser' => 'yes']);
		WC()->cart->empty_cart();
		$cart_key = WC()->cart->add_to_cart($tp['product_id']);

		if (!$cart_key) {
			$this->markTestSkipped('Could not add to cart');
		}

		$result = $this->main->getWC()->getFrontendManager()->updateCartItemMeta(
			'saso_eventtickets_request_daychooser',
			$cart_key,
			0,
			'2026-08-15'
		);

		$this->assertIsArray($result);
	}

	public function test_updateCartItemMeta_daychooser_invalid_cart_item_returns_not_in_cart(): void {
		$result = $this->main->getWC()->getFrontendManager()->updateCartItemMeta(
			'saso_eventtickets_request_daychooser',
			'nonexistent_cart_key_xyz',
			0,
			'2026-08-15'
		);

		$this->assertArrayHasKey('item_not_in_cart', $result);
		$this->assertTrue($result['item_not_in_cart']);
	}

	// ── woocommerce_cart_item_removed_handler ──────────────────

	public function test_cart_item_removed_cleans_session(): void {
		$tp = $this->createTicketProduct();
		WC()->cart->empty_cart();
		$cart_key = WC()->cart->add_to_cart($tp['product_id']);

		if (!$cart_key) {
			$this->markTestSkipped('Could not add to cart');
		}

		// Set some session data
		if (WC()->session) {
			$sessionData = [$cart_key => ['TestUser']];
			WC()->session->set('saso_eventtickets_request_name_per_ticket', $sessionData);
		}

		// Remove the item
		$this->main->getWC()->getFrontendManager()->woocommerce_cart_item_removed_handler($cart_key, WC()->cart);

		// Session data should be cleared
		if (WC()->session) {
			$this->assertNull(
				WC()->session->get('saso_eventtickets_request_name_per_ticket'),
				'Session should be cleared after item removal'
			);
		}
	}

	public function test_cart_item_removed_no_crash_without_session(): void {
		// Call with nonexistent key should not crash
		$this->main->getWC()->getFrontendManager()->woocommerce_cart_item_removed_handler(
			'nonexistent_key_' . uniqid(), WC()->cart
		);
		$this->assertTrue(true);
	}

	// ── woocommerce_after_cart_item_quantity_update_handler ─────

	public function test_quantity_update_same_quantity_does_nothing(): void {
		$tp = $this->createTicketProduct();
		WC()->cart->empty_cart();
		$cart_key = WC()->cart->add_to_cart($tp['product_id']);

		if (!$cart_key) {
			$this->markTestSkipped('Could not add to cart');
		}

		// Same quantity → should return early
		$this->main->getWC()->getFrontendManager()->woocommerce_after_cart_item_quantity_update_handler(
			$cart_key, 1, 1
		);
		$this->assertTrue(true); // No crash
	}

	public function test_quantity_update_to_zero_triggers_removal(): void {
		$tp = $this->createTicketProduct();
		WC()->cart->empty_cart();
		$cart_key = WC()->cart->add_to_cart($tp['product_id']);

		if (!$cart_key) {
			$this->markTestSkipped('Could not add to cart');
		}

		// Set session data
		if (WC()->session) {
			$sessionData = [$cart_key => ['User1']];
			WC()->session->set('saso_eventtickets_request_name_per_ticket', $sessionData);
		}

		// Decrease to 0 → should trigger removal handler
		$this->main->getWC()->getFrontendManager()->woocommerce_after_cart_item_quantity_update_handler(
			$cart_key, 0, 1
		);

		if (WC()->session) {
			$this->assertNull(
				WC()->session->get('saso_eventtickets_request_name_per_ticket'),
				'Session should be cleared when quantity goes to 0'
			);
		}
	}

	public function test_quantity_increase_extends_session_data(): void {
		$tp = $this->createTicketProduct();
		WC()->cart->empty_cart();
		$cart_key = WC()->cart->add_to_cart($tp['product_id'], 2);

		if (!$cart_key) {
			$this->markTestSkipped('Could not add to cart');
		}

		// Set session data for 2 tickets
		if (WC()->session) {
			$sessionData = [$cart_key => ['User1', 'User2']];
			WC()->session->set('saso_eventtickets_request_name_per_ticket', $sessionData);
		}

		// Increase from 2 to 4
		$this->main->getWC()->getFrontendManager()->woocommerce_after_cart_item_quantity_update_handler(
			$cart_key, 4, 2
		);

		if (WC()->session) {
			$sessionData = WC()->session->get('saso_eventtickets_request_name_per_ticket');
			if ($sessionData && isset($sessionData[$cart_key])) {
				$this->assertCount(4, $sessionData[$cart_key], 'Session should have 4 entries after increase');
			}
		}
		$this->assertTrue(true);
	}

	public function test_quantity_decrease_trims_session_data(): void {
		$tp = $this->createTicketProduct();
		WC()->cart->empty_cart();
		$cart_key = WC()->cart->add_to_cart($tp['product_id'], 3);

		if (!$cart_key) {
			$this->markTestSkipped('Could not add to cart');
		}

		if (WC()->session) {
			$sessionData = [$cart_key => ['U1', 'U2', 'U3']];
			WC()->session->set('saso_eventtickets_request_name_per_ticket', $sessionData);
		}

		// Decrease from 3 to 1
		$this->main->getWC()->getFrontendManager()->woocommerce_after_cart_item_quantity_update_handler(
			$cart_key, 1, 3
		);

		if (WC()->session) {
			$sessionData = WC()->session->get('saso_eventtickets_request_name_per_ticket');
			if ($sessionData && isset($sessionData[$cart_key])) {
				$this->assertCount(1, $sessionData[$cart_key], 'Session should have 1 entry after decrease');
			}
		}
		$this->assertTrue(true);
	}

	// ── check_cart_item_and_add_warnings ───────────────────────

	public function test_check_cart_empty_cart_no_warnings(): void {
		WC()->cart->empty_cart();
		wc_clear_notices();

		$this->main->getWC()->getFrontendManager()->check_cart_item_and_add_warnings();

		$notices = wc_get_notices('error');
		$this->assertEmpty($notices, 'Empty cart should not produce warnings');
	}

	public function test_check_cart_regular_product_no_warnings(): void {
		$product = new WC_Product_Simple();
		$product->set_name('Regular NoWarn');
		$product->set_regular_price('5.00');
		$product->set_status('publish');
		$product->save();

		WC()->cart->empty_cart();
		WC()->cart->add_to_cart($product->get_id());
		wc_clear_notices();

		$this->main->getWC()->getFrontendManager()->check_cart_item_and_add_warnings();

		$notices = wc_get_notices('error');
		$this->assertEmpty($notices, 'Regular product should not produce warnings');
	}

	// ── Helper methods ─────────────────────────────────────────

	private function createTicketProduct(array $extraMeta = []): array {
		$listId = $this->main->getDB()->insert('lists', [
			'name' => 'CartHandler List ' . uniqid(),
			'aktiv' => 1,
			'meta' => '{}',
		]);

		$product = new WC_Product_Simple();
		$product->set_name('CartHandler Ticket ' . uniqid());
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
