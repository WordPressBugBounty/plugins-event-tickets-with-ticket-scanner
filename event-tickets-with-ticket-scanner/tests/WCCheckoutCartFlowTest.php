<?php
/**
 * Tests for WooCommerce checkout and cart flow:
 * - setTicketValuesToOrderItem: Session → order item meta transfer
 * - woocommerce_checkout_create_order_line_item: Line item creation hook
 * - woocommerce_add_to_cart_validation_handler: Add-to-cart validation (daychooser)
 * - woocommerce_cart_item_removed_handler: Cart item removal cleanup
 * - woocommerce_after_cart_item_quantity_update_handler: Cart quantity change
 * - check_cart_item_and_add_warnings: Cart validation warnings
 * - woocommerce_checkout_process: Checkout validation
 */

class WCCheckoutCartFlowTest extends WP_UnitTestCase {

	private $main;

	public function set_up(): void {
		parent::set_up();
		$this->main = sasoEventtickets::Instance();

		if (!class_exists('WC_Product_Simple')) {
			$this->markTestSkipped('WooCommerce not available');
		}
	}

	// ── Helpers ─────────────────────────────────────────────────

	private function createTicketProduct(array $extraMeta = []): array {
		$listId = $this->main->getDB()->insert('lists', [
			'name' => 'Checkout List ' . uniqid(),
			'aktiv' => 1,
			'meta' => '{}',
		]);

		$product = new WC_Product_Simple();
		$product->set_name('Checkout Ticket ' . uniqid());
		$product->set_regular_price('20.00');
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

	private function createOrder(WC_Product $product, int $quantity = 1, string $status = 'pending'): WC_Order {
		$order = wc_create_order();
		$order->add_product($product, $quantity);
		$order->set_billing_first_name('Cart');
		$order->set_billing_last_name('Test');
		$order->set_billing_email('cart@test.com');
		$order->calculate_totals();
		$order->set_status($status);
		$order->save();
		return wc_get_order($order->get_id());
	}

	private function getOrderItemAndId(WC_Order $order): array {
		$items = $order->get_items();
		$item = reset($items);
		$item_id = key($items);
		return ['item' => $item, 'item_id' => $item_id];
	}

	// ── setTicketValuesToOrderItem ─────────────────────────────

	public function test_setTicketValuesToOrderItem_transfers_name_per_ticket(): void {
		$tp = $this->createTicketProduct();
		$order = $this->createOrder($tp['product']);
		$data = $this->getOrderItemAndId($order);

		$cart_item_key = 'test_cart_key_' . uniqid();

		// Set session value for name per ticket
		WC()->session->set('saso_eventtickets_request_name_per_ticket', [
			$cart_item_key => ['Alice', 'Bob'],
		]);

		// Add a cart item so get_cart_item works
		WC()->cart->empty_cart();
		$added = WC()->cart->add_to_cart($tp['product_id'], 2);

		// We need to call with a real cart item key — use the one we just added
		if ($added) {
			$cart_contents = WC()->cart->get_cart();
			$real_key = array_key_first($cart_contents);

			// Re-set session with real key
			WC()->session->set('saso_eventtickets_request_name_per_ticket', [
				$real_key => ['Alice', 'Bob'],
			]);

			$this->main->getWC()->getOrderManager()->setTicketValuesToOrderItem($data['item'], $real_key);
			$data['item']->save();

			$stored = $data['item']->get_meta('saso_eventtickets_request_name_per_ticket');
			$this->assertEquals(['Alice', 'Bob'], $stored);
		} else {
			$this->markTestSkipped('Could not add to cart');
		}

		WC()->cart->empty_cart();
	}

	public function test_setTicketValuesToOrderItem_transfers_value_per_ticket(): void {
		$tp = $this->createTicketProduct();
		$order = $this->createOrder($tp['product']);
		$data = $this->getOrderItemAndId($order);

		WC()->cart->empty_cart();
		$added = WC()->cart->add_to_cart($tp['product_id'], 1);

		if ($added) {
			$cart_contents = WC()->cart->get_cart();
			$real_key = array_key_first($cart_contents);

			WC()->session->set('saso_eventtickets_request_value_per_ticket', [
				$real_key => ['VIP'],
			]);

			$this->main->getWC()->getOrderManager()->setTicketValuesToOrderItem($data['item'], $real_key);
			$data['item']->save();

			$stored = $data['item']->get_meta('saso_eventtickets_request_value_per_ticket');
			$this->assertEquals(['VIP'], $stored);
		} else {
			$this->markTestSkipped('Could not add to cart');
		}

		WC()->cart->empty_cart();
	}

	public function test_setTicketValuesToOrderItem_no_session_no_meta(): void {
		$tp = $this->createTicketProduct();
		$order = $this->createOrder($tp['product']);
		$data = $this->getOrderItemAndId($order);

		WC()->cart->empty_cart();
		$added = WC()->cart->add_to_cart($tp['product_id'], 1);

		if ($added) {
			$cart_contents = WC()->cart->get_cart();
			$real_key = array_key_first($cart_contents);

			// Clear session
			WC()->session->__unset('saso_eventtickets_request_name_per_ticket');
			WC()->session->__unset('saso_eventtickets_request_value_per_ticket');

			$this->main->getWC()->getOrderManager()->setTicketValuesToOrderItem($data['item'], $real_key);
			$data['item']->save();

			$stored = $data['item']->get_meta('saso_eventtickets_request_name_per_ticket');
			$this->assertEmpty($stored);
		} else {
			$this->markTestSkipped('Could not add to cart');
		}

		WC()->cart->empty_cart();
	}

	public function test_setTicketValuesToOrderItem_transfers_daychooser_from_cart(): void {
		$tp = $this->createTicketProduct([
			'saso_eventtickets_is_daychooser' => 'yes',
		]);
		$order = $this->createOrder($tp['product']);
		$data = $this->getOrderItemAndId($order);

		WC()->cart->empty_cart();
		$added = WC()->cart->add_to_cart($tp['product_id'], 1);

		if ($added) {
			$cart_contents = WC()->cart->get_cart();
			$real_key = array_key_first($cart_contents);

			// Inject daychooser value directly into cart item data
			$daychooserKey = '_saso_eventtickets_request_daychooser';
			WC()->cart->cart_contents[$real_key][$daychooserKey] = '2026-06-15';

			$this->main->getWC()->getOrderManager()->setTicketValuesToOrderItem($data['item'], $real_key);
			$data['item']->save();

			$stored = $data['item']->get_meta($daychooserKey);
			$this->assertEquals('2026-06-15', $stored);
		} else {
			$this->markTestSkipped('Could not add to cart');
		}

		WC()->cart->empty_cart();
	}

	// ── woocommerce_checkout_create_order_line_item ─────────────

	public function test_checkout_create_order_line_item_calls_setTicketValues(): void {
		$tp = $this->createTicketProduct();
		$order = $this->createOrder($tp['product']);
		$data = $this->getOrderItemAndId($order);

		WC()->cart->empty_cart();
		$added = WC()->cart->add_to_cart($tp['product_id'], 1);

		if ($added) {
			$cart_contents = WC()->cart->get_cart();
			$real_key = array_key_first($cart_contents);
			$values = $cart_contents[$real_key];

			WC()->session->set('saso_eventtickets_request_name_per_ticket', [
				$real_key => ['Checkout Person'],
			]);

			$this->main->getWC()->getOrderManager()->woocommerce_checkout_create_order_line_item(
				$data['item'], $real_key, $values, $order
			);
			$data['item']->save();

			$stored = $data['item']->get_meta('saso_eventtickets_request_name_per_ticket');
			$this->assertEquals(['Checkout Person'], $stored);
		} else {
			$this->markTestSkipped('Could not add to cart');
		}

		WC()->cart->empty_cart();
	}

	public function test_checkout_create_order_line_item_without_restriction_code(): void {
		$tp = $this->createTicketProduct();
		$order = $this->createOrder($tp['product']);
		$data = $this->getOrderItemAndId($order);

		WC()->cart->empty_cart();
		$added = WC()->cart->add_to_cart($tp['product_id'], 1);

		if ($added) {
			$cart_contents = WC()->cart->get_cart();
			$real_key = array_key_first($cart_contents);
			$values = $cart_contents[$real_key];

			// No restriction code in values
			$this->assertArrayNotHasKey('_saso_eventticket_list_sale_restriction', $values);

			$this->main->getWC()->getOrderManager()->woocommerce_checkout_create_order_line_item(
				$data['item'], $real_key, $values, $order
			);
			$data['item']->save();

			// Restriction meta should not be set
			$restriction = $data['item']->get_meta('_saso_eventticket_list_sale_restriction');
			$this->assertEmpty($restriction);
		} else {
			$this->markTestSkipped('Could not add to cart');
		}

		WC()->cart->empty_cart();
	}

	// ── woocommerce_add_to_cart_validation_handler ──────────────

	public function test_add_to_cart_validation_passes_for_non_daychooser(): void {
		$tp = $this->createTicketProduct();

		$result = $this->main->getWC()->getFrontendManager()->woocommerce_add_to_cart_validation_handler(
			true, $tp['product_id'], 1
		);

		$this->assertTrue($result);
	}

	public function test_add_to_cart_validation_fails_for_daychooser_without_date(): void {
		$tp = $this->createTicketProduct([
			'saso_eventtickets_is_daychooser' => 'yes',
		]);

		// Simulate daychooser request without date
		$_REQUEST['is_daychooser'] = '1';
		$_REQUEST[sasoEventtickets_WC_Frontend::FIELD_KEY] = '';

		$result = $this->main->getWC()->getFrontendManager()->woocommerce_add_to_cart_validation_handler(
			true, $tp['product_id'], 1
		);

		$this->assertFalse($result);

		unset($_REQUEST['is_daychooser'], $_REQUEST[sasoEventtickets_WC_Frontend::FIELD_KEY]);
	}

	public function test_add_to_cart_validation_fails_for_past_date(): void {
		$tp = $this->createTicketProduct([
			'saso_eventtickets_is_daychooser' => 'yes',
		]);

		$_REQUEST['is_daychooser'] = '1';
		$_REQUEST[sasoEventtickets_WC_Frontend::FIELD_KEY] = '2020-01-01';

		$result = $this->main->getWC()->getFrontendManager()->woocommerce_add_to_cart_validation_handler(
			true, $tp['product_id'], 1
		);

		$this->assertFalse($result);

		unset($_REQUEST['is_daychooser'], $_REQUEST[sasoEventtickets_WC_Frontend::FIELD_KEY]);
	}

	public function test_add_to_cart_validation_passes_for_future_date(): void {
		$tp = $this->createTicketProduct([
			'saso_eventtickets_is_daychooser' => 'yes',
		]);

		$_REQUEST['is_daychooser'] = '1';
		$_REQUEST[sasoEventtickets_WC_Frontend::FIELD_KEY] = '2030-12-31';

		$result = $this->main->getWC()->getFrontendManager()->woocommerce_add_to_cart_validation_handler(
			true, $tp['product_id'], 1
		);

		$this->assertTrue($result);

		unset($_REQUEST['is_daychooser'], $_REQUEST[sasoEventtickets_WC_Frontend::FIELD_KEY]);
	}

	// ── woocommerce_cart_item_removed_handler ───────────────────

	public function test_cart_item_removed_cleans_up_session(): void {
		$tp = $this->createTicketProduct();

		WC()->cart->empty_cart();
		$added = WC()->cart->add_to_cart($tp['product_id'], 1);

		if ($added) {
			$cart_contents = WC()->cart->get_cart();
			$real_key = array_key_first($cart_contents);

			// Set session data
			WC()->session->set('saso_eventtickets_request_name_per_ticket', [
				$real_key => ['Person A'],
			]);

			// Call the removed handler
			$this->main->getWC()->getFrontendManager()->woocommerce_cart_item_removed_handler($real_key, WC()->cart);

			$remaining = WC()->session->get('saso_eventtickets_request_name_per_ticket');
			// Session key should be unset
			$this->assertNull($remaining);
		} else {
			$this->markTestSkipped('Could not add to cart');
		}

		WC()->cart->empty_cart();
	}

	public function test_cart_item_removed_ignores_unknown_key(): void {
		// Calling with a key that has no session data should not fail
		$this->main->getWC()->getFrontendManager()->woocommerce_cart_item_removed_handler('nonexistent_key', WC()->cart);
		$this->assertTrue(true); // No exception = pass
	}

	// ── woocommerce_after_cart_item_quantity_update_handler ──────

	public function test_quantity_update_same_quantity_noop(): void {
		$tp = $this->createTicketProduct();

		WC()->cart->empty_cart();
		$added = WC()->cart->add_to_cart($tp['product_id'], 2);

		if ($added) {
			$cart_contents = WC()->cart->get_cart();
			$real_key = array_key_first($cart_contents);

			WC()->session->set('saso_eventtickets_request_name_per_ticket', [
				$real_key => ['Alice', 'Bob'],
			]);

			// Same quantity = no-op
			$this->main->getWC()->getFrontendManager()->woocommerce_after_cart_item_quantity_update_handler(
				$real_key, 2, 2
			);

			$stored = WC()->session->get('saso_eventtickets_request_name_per_ticket');
			$this->assertCount(2, $stored[$real_key]);
		} else {
			$this->markTestSkipped('Could not add to cart');
		}

		WC()->cart->empty_cart();
	}

	public function test_quantity_update_increase_extends_session_data(): void {
		$tp = $this->createTicketProduct();

		WC()->cart->empty_cart();
		$added = WC()->cart->add_to_cart($tp['product_id'], 2);

		if ($added) {
			$cart_contents = WC()->cart->get_cart();
			$real_key = array_key_first($cart_contents);

			WC()->session->set('saso_eventtickets_request_name_per_ticket', [
				$real_key => ['Alice', 'Bob'],
			]);

			// Increase from 2 to 4
			$this->main->getWC()->getFrontendManager()->woocommerce_after_cart_item_quantity_update_handler(
				$real_key, 4, 2
			);

			$stored = WC()->session->get('saso_eventtickets_request_name_per_ticket');
			$this->assertCount(4, $stored[$real_key]);
			// New entries should be filled with last value
			$this->assertEquals('Bob', $stored[$real_key][2]);
			$this->assertEquals('Bob', $stored[$real_key][3]);
		} else {
			$this->markTestSkipped('Could not add to cart');
		}

		WC()->cart->empty_cart();
	}

	public function test_quantity_update_to_zero_triggers_removal(): void {
		$tp = $this->createTicketProduct();

		WC()->cart->empty_cart();
		$added = WC()->cart->add_to_cart($tp['product_id'], 1);

		if ($added) {
			$cart_contents = WC()->cart->get_cart();
			$real_key = array_key_first($cart_contents);

			WC()->session->set('saso_eventtickets_request_name_per_ticket', [
				$real_key => ['Alice'],
			]);

			// Set to 0 = triggers removal handler
			$this->main->getWC()->getFrontendManager()->woocommerce_after_cart_item_quantity_update_handler(
				$real_key, 0, 1
			);

			$stored = WC()->session->get('saso_eventtickets_request_name_per_ticket');
			$this->assertNull($stored);
		} else {
			$this->markTestSkipped('Could not add to cart');
		}

		WC()->cart->empty_cart();
	}

	// ── woocommerce_add_cart_item_data_handler ──────────────────

	public function test_add_cart_item_data_returns_data_unchanged_without_seating(): void {
		$tp = $this->createTicketProduct();

		$cart_data = ['test_key' => 'test_value'];
		$result = $this->main->getWC()->getFrontendManager()->woocommerce_add_cart_item_data_handler(
			$cart_data, $tp['product_id'], 0
		);

		$this->assertArrayHasKey('test_key', $result);
		$this->assertEquals('test_value', $result['test_key']);
	}

	// ── check_cart_item_and_add_warnings ─────────────────────────

	public function test_check_cart_item_no_warnings_for_simple_ticket(): void {
		$tp = $this->createTicketProduct();

		WC()->cart->empty_cart();
		$added = WC()->cart->add_to_cart($tp['product_id'], 1);

		if ($added) {
			// Clear any existing notices
			wc_clear_notices();

			$this->main->getWC()->getFrontendManager()->check_cart_item_and_add_warnings();

			$errors = wc_get_notices('error');
			$this->assertEmpty($errors);
		} else {
			$this->markTestSkipped('Could not add to cart');
		}

		WC()->cart->empty_cart();
		wc_clear_notices();
	}

	public function test_check_cart_item_warning_when_mandatory_name_missing(): void {
		$tp = $this->createTicketProduct([
			'saso_eventtickets_request_name_per_ticket' => 'yes',
			'saso_eventtickets_request_name_per_ticket_mandatory' => 'yes',
		]);

		WC()->cart->empty_cart();
		$added = WC()->cart->add_to_cart($tp['product_id'], 1);

		if ($added) {
			wc_clear_notices();
			// Don't set any name in session
			WC()->session->__unset('saso_eventtickets_request_name_per_ticket');

			$this->main->getWC()->getFrontendManager()->check_cart_item_and_add_warnings();

			$errors = wc_get_notices('error');
			$this->assertNotEmpty($errors, 'Should have error when mandatory name is missing');
		} else {
			$this->markTestSkipped('Could not add to cart');
		}

		WC()->cart->empty_cart();
		wc_clear_notices();
	}

	public function test_check_cart_item_no_warning_when_name_provided(): void {
		$tp = $this->createTicketProduct([
			'saso_eventtickets_request_name_per_ticket' => 'yes',
			'saso_eventtickets_request_name_per_ticket_mandatory' => 'yes',
		]);

		WC()->cart->empty_cart();
		$added = WC()->cart->add_to_cart($tp['product_id'], 1);

		if ($added) {
			$cart_contents = WC()->cart->get_cart();
			$real_key = array_key_first($cart_contents);

			wc_clear_notices();
			WC()->session->set('saso_eventtickets_request_name_per_ticket', [
				$real_key => ['John Doe'],
			]);

			$this->main->getWC()->getFrontendManager()->check_cart_item_and_add_warnings();

			$errors = wc_get_notices('error');
			$this->assertEmpty($errors, 'Should have no error when name is provided');
		} else {
			$this->markTestSkipped('Could not add to cart');
		}

		WC()->cart->empty_cart();
		wc_clear_notices();
	}

	public function test_check_cart_item_warning_when_mandatory_value_missing(): void {
		$tp = $this->createTicketProduct([
			'saso_eventtickets_request_value_per_ticket' => 'yes',
			'saso_eventtickets_request_value_per_ticket_mandatory' => 'yes',
		]);

		WC()->cart->empty_cart();
		$added = WC()->cart->add_to_cart($tp['product_id'], 1);

		if ($added) {
			wc_clear_notices();
			WC()->session->__unset('saso_eventtickets_request_value_per_ticket');

			$this->main->getWC()->getFrontendManager()->check_cart_item_and_add_warnings();

			$errors = wc_get_notices('error');
			$this->assertNotEmpty($errors, 'Should have error when mandatory value is missing');
		} else {
			$this->markTestSkipped('Could not add to cart');
		}

		WC()->cart->empty_cart();
		wc_clear_notices();
	}

	// ── woocommerce_checkout_process ────────────────────────────

	public function test_checkout_process_delegates_to_check_cart_items(): void {
		$tp = $this->createTicketProduct();

		WC()->cart->empty_cart();
		$added = WC()->cart->add_to_cart($tp['product_id'], 1);

		if ($added) {
			wc_clear_notices();
			$this->main->getWC()->getFrontendManager()->woocommerce_checkout_process();
			$errors = wc_get_notices('error');
			// Simple ticket without mandatory fields should pass
			$this->assertEmpty($errors);
		} else {
			$this->markTestSkipped('Could not add to cart');
		}

		WC()->cart->empty_cart();
		wc_clear_notices();
	}

	// ── Integration: full checkout flow ─────────────────────────

	public function test_full_checkout_flow_generates_tickets(): void {
		$tp = $this->createTicketProduct();
		$order = $this->createOrder($tp['product'], 3, 'pending');

		// Verify no codes yet
		$codes = $this->main->getCore()->getCodesByOrderId($order->get_id());
		$this->assertCount(0, $codes);

		// Complete order → trigger hook
		$order->set_status('completed');
		$order->save();

		$codes = $this->main->getCore()->getCodesByOrderId($order->get_id());
		$this->assertCount(3, $codes);

		// Each code should have the correct order_id
		foreach ($codes as $code) {
			$this->assertEquals($order->get_id(), $code['order_id']);
		}
	}

	public function test_full_checkout_flow_stores_is_ticket_meta(): void {
		$tp = $this->createTicketProduct();
		$order = $this->createOrder($tp['product'], 1, 'pending');

		$order->set_status('completed');
		$order->save();

		$data = $this->getOrderItemAndId($order);
		$isTicket = wc_get_order_item_meta($data['item_id'], '_saso_eventtickets_is_ticket', true);
		$this->assertEquals(1, intval($isTicket));
	}

	public function test_full_checkout_flow_stores_codes_in_item_meta(): void {
		$tp = $this->createTicketProduct();
		$order = $this->createOrder($tp['product'], 2, 'pending');

		$order->set_status('completed');
		$order->save();

		$data = $this->getOrderItemAndId($order);
		$codesStr = wc_get_order_item_meta($data['item_id'], '_saso_eventtickets_product_code', true);
		$this->assertNotEmpty($codesStr);

		$codeParts = explode(',', $codesStr);
		$this->assertCount(2, $codeParts);
	}

	public function test_full_checkout_flow_stores_public_ticket_ids(): void {
		$tp = $this->createTicketProduct();
		$order = $this->createOrder($tp['product'], 1, 'pending');

		$order->set_status('completed');
		$order->save();

		$data = $this->getOrderItemAndId($order);
		$publicIds = wc_get_order_item_meta($data['item_id'], '_saso_eventtickets_public_ticket_ids', true);
		$this->assertNotEmpty($publicIds);
	}
}
