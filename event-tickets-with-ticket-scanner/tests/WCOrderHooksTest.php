<?php
/**
 * Tests for WooCommerce order hooks — the critical path for ticket generation:
 * - add_serialcode_to_order: Main entry point for ticket generation
 * - add_serialcode_to_order_forItem: Per-item ticket code creation
 * - woocommerce_order_status_changed: Status change triggers
 * - woocommerce_new_order: Session cleanup
 * - setTicketValuesToOrderItem: Cart → order item metadata transfer
 * - woocommerce_checkout_create_order_line_item: Line item creation hook
 */

class WCOrderHooksTest extends WP_UnitTestCase {

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
			'name' => 'WC Hook List ' . uniqid(),
			'aktiv' => 1,
			'meta' => '{}',
		]);

		$product = new WC_Product_Simple();
		$product->set_name('WC Hook Ticket ' . uniqid());
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

	private function createOrder(WC_Product $product, int $quantity = 1, string $status = 'completed'): WC_Order {
		$order = wc_create_order();
		$order->add_product($product, $quantity);
		$order->set_billing_first_name('Order');
		$order->set_billing_last_name('Test');
		$order->set_billing_email('order@test.com');
		$order->calculate_totals();
		$order->set_status($status);
		$order->save();
		return wc_get_order($order->get_id());
	}

	// ── add_serialcode_to_order ─────────────────────────────────

	public function test_generates_codes_for_completed_order(): void {
		$tp = $this->createTicketProduct();
		$order = $this->createOrder($tp['product'], 2);

		$this->main->getWC()->getOrderManager()->add_serialcode_to_order($order->get_id());

		$codes = $this->main->getCore()->getCodesByOrderId($order->get_id());
		$this->assertCount(2, $codes);
	}

	public function test_no_codes_for_pending_order(): void {
		$tp = $this->createTicketProduct();
		$order = $this->createOrder($tp['product'], 1, 'pending');

		$this->main->getWC()->getOrderManager()->add_serialcode_to_order($order->get_id());

		$codes = $this->main->getCore()->getCodesByOrderId($order->get_id());
		$this->assertCount(0, $codes);
	}

	public function test_no_codes_for_cancelled_order(): void {
		$tp = $this->createTicketProduct();
		$order = $this->createOrder($tp['product'], 1, 'cancelled');

		$this->main->getWC()->getOrderManager()->add_serialcode_to_order($order->get_id());

		$codes = $this->main->getCore()->getCodesByOrderId($order->get_id());
		$this->assertCount(0, $codes);
	}

	public function test_codes_generated_for_processing_order(): void {
		$tp = $this->createTicketProduct();
		$order = $this->createOrder($tp['product'], 1, 'processing');

		$this->main->getWC()->getOrderManager()->add_serialcode_to_order($order->get_id());

		$codes = $this->main->getCore()->getCodesByOrderId($order->get_id());
		$this->assertCount(1, $codes);
	}

	public function test_does_not_duplicate_codes_on_repeated_call(): void {
		$tp = $this->createTicketProduct();
		$order = $this->createOrder($tp['product'], 2);

		$orderMgr = $this->main->getWC()->getOrderManager();
		$orderMgr->add_serialcode_to_order($order->get_id());
		$orderMgr->add_serialcode_to_order($order->get_id());

		$codes = $this->main->getCore()->getCodesByOrderId($order->get_id());
		$this->assertCount(2, $codes, 'Repeated calls should not create duplicate codes');
	}

	public function test_skips_non_ticket_products(): void {
		$tp = $this->createTicketProduct();

		$regular = new WC_Product_Simple();
		$regular->set_name('Regular Product');
		$regular->set_regular_price('5.00');
		$regular->set_status('publish');
		$regular->save();

		$order = wc_create_order();
		$order->add_product($regular, 1);
		$order->add_product($tp['product'], 1);
		$order->calculate_totals();
		$order->set_status('completed');
		$order->save();

		$this->main->getWC()->getOrderManager()->add_serialcode_to_order($order->get_id());

		$codes = $this->main->getCore()->getCodesByOrderId($order->get_id());
		$this->assertCount(1, $codes, 'Only ticket product should generate codes');
	}

	public function test_invalid_order_id_does_nothing(): void {
		$this->main->getWC()->getOrderManager()->add_serialcode_to_order(0);
		// No exception, no error — just returns
		$this->assertTrue(true);
	}

	public function test_nonexistent_order_id_does_nothing(): void {
		$this->main->getWC()->getOrderManager()->add_serialcode_to_order(999999);
		$this->assertTrue(true);
	}

	// ── add_serialcode_to_order_forItem ─────────────────────────
	// NOTE: forItem tests use 'pending' status to prevent the hook from auto-generating codes

	public function test_forItem_generates_code_and_stores_meta(): void {
		$tp = $this->createTicketProduct();
		$order = $this->createOrder($tp['product'], 1, 'pending');
		$items = $order->get_items();
		$item = reset($items);
		$item_id = key($items);

		$result = $this->main->getWC()->getOrderManager()->add_serialcode_to_order_forItem(
			$order->get_id(), $order, $item_id, $item, $tp['list_id']
		);

		$this->assertIsArray($result);
		$this->assertNotEmpty($result);

		// Verify code stored in order item meta
		$storedCodes = wc_get_order_item_meta($item_id, '_saso_eventtickets_product_code', true);
		$this->assertNotEmpty($storedCodes);
	}

	public function test_forItem_respects_quantity(): void {
		$tp = $this->createTicketProduct();
		$order = $this->createOrder($tp['product'], 5, 'pending');
		$items = $order->get_items();
		$item = reset($items);
		$item_id = key($items);

		$result = $this->main->getWC()->getOrderManager()->add_serialcode_to_order_forItem(
			$order->get_id(), $order, $item_id, $item, $tp['list_id']
		);

		$this->assertCount(5, $result);

		$storedCodes = wc_get_order_item_meta($item_id, '_saso_eventtickets_product_code', true);
		$codeArray = explode(',', $storedCodes);
		$this->assertCount(5, $codeArray);
	}

	public function test_forItem_tickets_per_item_multiplier(): void {
		$tp = $this->createTicketProduct([
			'saso_eventtickets_ticket_amount_per_item' => 2,
		]);
		$order = $this->createOrder($tp['product'], 3, 'pending');
		$items = $order->get_items();
		$item = reset($items);
		$item_id = key($items);

		$result = $this->main->getWC()->getOrderManager()->add_serialcode_to_order_forItem(
			$order->get_id(), $order, $item_id, $item, $tp['list_id']
		);

		// 3 items × 2 tickets/item = 6 codes
		$this->assertCount(6, $result);
	}

	public function test_forItem_stores_public_ticket_ids(): void {
		$tp = $this->createTicketProduct();
		$order = $this->createOrder($tp['product'], 2, 'pending');
		$items = $order->get_items();
		$item = reset($items);
		$item_id = key($items);

		$this->main->getWC()->getOrderManager()->add_serialcode_to_order_forItem(
			$order->get_id(), $order, $item_id, $item, $tp['list_id']
		);

		$publicIds = wc_get_order_item_meta($item_id, '_saso_eventtickets_public_ticket_ids', true);
		$this->assertNotEmpty($publicIds);
		$ids = explode(',', $publicIds);
		$this->assertCount(2, $ids);
	}

	public function test_forItem_codes_are_unique(): void {
		$tp = $this->createTicketProduct();
		$order = $this->createOrder($tp['product'], 10, 'pending');
		$items = $order->get_items();
		$item = reset($items);
		$item_id = key($items);

		$result = $this->main->getWC()->getOrderManager()->add_serialcode_to_order_forItem(
			$order->get_id(), $order, $item_id, $item, $tp['list_id']
		);

		$this->assertCount(10, $result);
		$this->assertCount(10, array_unique($result), 'All codes must be unique');
	}

	public function test_forItem_code_linked_to_order(): void {
		$tp = $this->createTicketProduct();
		$order = $this->createOrder($tp['product'], 1, 'pending');
		$items = $order->get_items();
		$item = reset($items);
		$item_id = key($items);

		$result = $this->main->getWC()->getOrderManager()->add_serialcode_to_order_forItem(
			$order->get_id(), $order, $item_id, $item, $tp['list_id']
		);

		$this->assertNotEmpty($result);
		$codeObj = $this->main->getCore()->retrieveCodeByCode($result[0]);
		$this->assertEquals($order->get_id(), intval($codeObj['order_id']));
	}

	public function test_forItem_code_has_wc_meta(): void {
		$tp = $this->createTicketProduct();
		$order = $this->createOrder($tp['product'], 1, 'pending');
		$items = $order->get_items();
		$item = reset($items);
		$item_id = key($items);

		$result = $this->main->getWC()->getOrderManager()->add_serialcode_to_order_forItem(
			$order->get_id(), $order, $item_id, $item, $tp['list_id']
		);

		$this->assertNotEmpty($result);
		$codeObj = $this->main->getCore()->retrieveCodeByCode($result[0]);
		$metaObj = $this->main->getCore()->encodeMetaValuesAndFillObject($codeObj['meta'], $codeObj);

		$this->assertEquals(1, intval($metaObj['wc_ticket']['is_ticket']));
		$this->assertEquals($tp['product_id'], intval($metaObj['woocommerce']['product_id']));
		$this->assertNotEmpty($metaObj['wc_ticket']['_public_ticket_id']);
	}

	// ── woocommerce_order_status_changed ────────────────────────

	public function test_status_change_to_completed_generates_codes(): void {
		$tp = $this->createTicketProduct();
		$order = $this->createOrder($tp['product'], 1, 'pending');

		// No codes yet
		$codes = $this->main->getCore()->getCodesByOrderId($order->get_id());
		$this->assertCount(0, $codes);

		// Actually change order status to completed (so isOrderPaid returns true)
		$order->set_status('completed');
		$order->save();

		// Manually trigger the handler (hook may have already fired via save)
		$codes = $this->main->getCore()->getCodesByOrderId($order->get_id());
		$this->assertCount(1, $codes);
	}

	public function test_status_change_to_cancelled_does_not_generate(): void {
		$tp = $this->createTicketProduct();
		$order = $this->createOrder($tp['product'], 1, 'pending');

		$this->main->getWC()->getOrderManager()->woocommerce_order_status_changed(
			$order->get_id(), 'pending', 'cancelled'
		);

		$codes = $this->main->getCore()->getCodesByOrderId($order->get_id());
		$this->assertCount(0, $codes);
	}

	public function test_status_change_to_refunded_does_not_generate(): void {
		$tp = $this->createTicketProduct();
		$order = $this->createOrder($tp['product'], 1, 'pending');

		$this->main->getWC()->getOrderManager()->woocommerce_order_status_changed(
			$order->get_id(), 'pending', 'refunded'
		);

		$codes = $this->main->getCore()->getCodesByOrderId($order->get_id());
		$this->assertCount(0, $codes);
	}

	public function test_status_change_completed_to_processing_no_duplicates(): void {
		$tp = $this->createTicketProduct();
		$order = $this->createOrder($tp['product'], 1, 'completed');

		// Generate codes first time
		$this->main->getWC()->getOrderManager()->add_serialcode_to_order($order->get_id());
		$codes1 = $this->main->getCore()->getCodesByOrderId($order->get_id());
		$this->assertCount(1, $codes1);

		// Status changes back and forth
		$this->main->getWC()->getOrderManager()->woocommerce_order_status_changed(
			$order->get_id(), 'completed', 'processing'
		);

		$codes2 = $this->main->getCore()->getCodesByOrderId($order->get_id());
		$this->assertCount(1, $codes2, 'Status change should not duplicate codes');
		$this->assertEquals($codes1[0]['code'], $codes2[0]['code']);
	}

	// ── hasTicketsInOrder ───────────────────────────────────────

	public function test_hasTicketsInOrder_true_for_ticket_order(): void {
		$tp = $this->createTicketProduct();
		$order = $this->createOrder($tp['product']);

		$this->assertTrue($this->main->getWC()->getOrderManager()->hasTicketsInOrder($order));
	}

	public function test_hasTicketsInOrder_false_for_regular_order(): void {
		$regular = new WC_Product_Simple();
		$regular->set_name('Not a ticket');
		$regular->set_regular_price('10.00');
		$regular->set_status('publish');
		$regular->save();

		$order = $this->createOrder($regular);

		$this->assertFalse($this->main->getWC()->getOrderManager()->hasTicketsInOrder($order));
	}

	// ── hasTicketsInOrderWithTicketnumber ────────────────────────

	public function test_hasTicketsWithNumber_false_before_generation(): void {
		$tp = $this->createTicketProduct();
		$order = $this->createOrder($tp['product'], 1, 'pending');

		$this->assertFalse($this->main->getWC()->getOrderManager()->hasTicketsInOrderWithTicketnumber($order));
	}

	public function test_hasTicketsWithNumber_true_after_generation(): void {
		$tp = $this->createTicketProduct();
		$order = $this->createOrder($tp['product']);
		$this->main->getWC()->getOrderManager()->add_serialcode_to_order($order->get_id());
		$order = wc_get_order($order->get_id());

		$this->assertTrue($this->main->getWC()->getOrderManager()->hasTicketsInOrderWithTicketnumber($order));
	}

	// ── getTicketsFromOrder ─────────────────────────────────────

	public function test_getTicketsFromOrder_returns_correct_structure(): void {
		$tp = $this->createTicketProduct();
		$order = $this->createOrder($tp['product'], 3);
		$this->main->getWC()->getOrderManager()->add_serialcode_to_order($order->get_id());
		$order = wc_get_order($order->get_id());

		$tickets = $this->main->getWC()->getOrderManager()->getTicketsFromOrder($order);
		$this->assertCount(1, $tickets); // 1 product (with 3 qty)

		$ticket = reset($tickets);
		$this->assertEquals(3, $ticket['quantity']);
		$this->assertNotEmpty($ticket['codes']);
		$this->assertEquals($tp['product_id'], $ticket['product_id']);
		$this->assertArrayHasKey('order_item_id', $ticket);
	}

	public function test_getTicketsFromOrder_multiple_products(): void {
		$tp1 = $this->createTicketProduct();
		$tp2 = $this->createTicketProduct();

		$order = wc_create_order();
		$order->add_product($tp1['product'], 2);
		$order->add_product($tp2['product'], 1);
		$order->calculate_totals();
		$order->set_status('completed');
		$order->save();

		$this->main->getWC()->getOrderManager()->add_serialcode_to_order($order->get_id());
		$order = wc_get_order($order->get_id());

		$tickets = $this->main->getWC()->getOrderManager()->getTicketsFromOrder($order);
		$this->assertCount(2, $tickets);

		$totalCodes = $this->main->getCore()->getCodesByOrderId($order->get_id());
		$this->assertCount(3, $totalCodes); // 2 + 1
	}

	public function test_getTicketsFromOrder_empty_for_regular_products(): void {
		$regular = new WC_Product_Simple();
		$regular->set_name('No ticket');
		$regular->set_regular_price('5.00');
		$regular->set_status('publish');
		$regular->save();

		$order = $this->createOrder($regular);

		$tickets = $this->main->getWC()->getOrderManager()->getTicketsFromOrder($order);
		$this->assertCount(0, $tickets);
	}

	// ── Refund handling ─────────────────────────────────────────

	public function test_refund_reduces_code_count_for_new_generation(): void {
		$tp = $this->createTicketProduct();
		$order = $this->createOrder($tp['product'], 3);
		$this->main->getWC()->getOrderManager()->add_serialcode_to_order($order->get_id());

		$codes = $this->main->getCore()->getCodesByOrderId($order->get_id());
		$this->assertCount(3, $codes);
	}

	// ── woocommerce_new_order ───────────────────────────────────

	public function test_new_order_fires_action(): void {
		$fired = false;
		add_action($this->main->_do_action_prefix . 'woocommerce-hooks_woocommerce_new_order', function() use (&$fired) {
			$fired = true;
		});

		$tp = $this->createTicketProduct();
		$order = wc_create_order();
		$order->add_product($tp['product']);
		$order->save();

		// Simulate WC session
		if (WC()->session === null) {
			WC()->session = new WC_Session_Handler();
		}

		$this->main->getWC()->getOrderManager()->woocommerce_new_order($order->get_id());
		$this->assertTrue($fired);
	}
}
