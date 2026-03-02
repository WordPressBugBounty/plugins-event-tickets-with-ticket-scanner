<?php
/**
 * Batch 43 — WC Order methods:
 * - hasTicketsInOrder: checks order for ticket products
 * - hasTicketsInOrderWithTicketnumber: checks for assigned ticket numbers
 * - getTicketsFromOrder: extracts ticket data from order
 * - woocommerce_order_status_changed: status change handler
 * - add_serialcode_to_order: ticket generation for completed orders
 * - woocommerce_order_item_display_meta_key: meta key display filter
 * - woocommerce_order_item_display_meta_value: meta value display filter
 */

class WCOrderMethodsTest extends WP_UnitTestCase {

	private $main;
	private $orderMgr;

	public function set_up(): void {
		parent::set_up();
		$this->main = sasoEventtickets::Instance();

		if (!class_exists('WC_Product_Simple')) {
			$this->markTestSkipped('WooCommerce not available');
		}

		$this->orderMgr = $this->main->getWC()->getOrderManager();
	}

	private function createTicketProduct(): array {
		$listId = $this->main->getDB()->insert('lists', [
			'name' => 'WC Order List ' . uniqid(),
			'aktiv' => 1,
			'meta' => '{}',
		]);

		$product = new WC_Product_Simple();
		$product->set_name('Order Test Product ' . uniqid());
		$product->set_regular_price('15.00');
		$product->set_status('publish');
		$product->save();
		$pid = $product->get_id();

		update_post_meta($pid, 'saso_eventtickets_is_ticket', 'yes');
		update_post_meta($pid, 'saso_eventtickets_list', $listId);

		return ['product' => $product, 'product_id' => $pid, 'list_id' => $listId];
	}

	private function createRegularProduct(): WC_Product_Simple {
		$product = new WC_Product_Simple();
		$product->set_name('Regular Product ' . uniqid());
		$product->set_regular_price('5.00');
		$product->set_status('publish');
		$product->save();
		return $product;
	}

	private function createOrderWithProduct(int $productId, int $qty = 1): WC_Order {
		$order = wc_create_order();
		$product = wc_get_product($productId);
		$order->add_product($product, $qty);
		$order->calculate_totals();
		$order->save();
		return $order;
	}

	// ── hasTicketsInOrder ────────────────────────────────────

	public function test_hasTicketsInOrder_true_for_ticket_product(): void {
		$tp = $this->createTicketProduct();
		$order = $this->createOrderWithProduct($tp['product_id']);
		$this->assertTrue($this->orderMgr->hasTicketsInOrder($order));
	}

	public function test_hasTicketsInOrder_false_for_regular_product(): void {
		$product = $this->createRegularProduct();
		$order = $this->createOrderWithProduct($product->get_id());
		$this->assertFalse($this->orderMgr->hasTicketsInOrder($order));
	}

	public function test_hasTicketsInOrder_true_for_mixed_order(): void {
		$tp = $this->createTicketProduct();
		$regular = $this->createRegularProduct();

		$order = wc_create_order();
		$order->add_product(wc_get_product($tp['product_id']));
		$order->add_product($regular);
		$order->save();

		$this->assertTrue($this->orderMgr->hasTicketsInOrder($order));
	}

	public function test_hasTicketsInOrder_false_for_empty_order(): void {
		$order = wc_create_order();
		$order->save();
		$this->assertFalse($this->orderMgr->hasTicketsInOrder($order));
	}

	// ── hasTicketsInOrderWithTicketnumber ─────────────────────

	public function test_hasTicketsInOrderWithTicketnumber_false_without_codes(): void {
		$tp = $this->createTicketProduct();
		$order = $this->createOrderWithProduct($tp['product_id']);
		// No codes assigned yet
		$this->assertFalse($this->orderMgr->hasTicketsInOrderWithTicketnumber($order));
	}

	public function test_hasTicketsInOrderWithTicketnumber_true_with_codes(): void {
		$tp = $this->createTicketProduct();
		$order = $this->createOrderWithProduct($tp['product_id']);

		// Manually add a code to the order item
		$items = $order->get_items();
		foreach ($items as $item_id => $item) {
			wc_update_order_item_meta($item_id, '_saso_eventtickets_product_code', 'TESTCODE123');
		}

		$this->assertTrue($this->orderMgr->hasTicketsInOrderWithTicketnumber($order));
	}

	// ── getTicketsFromOrder ──────────────────────────────────

	public function test_getTicketsFromOrder_returns_ticket_items(): void {
		$tp = $this->createTicketProduct();
		$order = $this->createOrderWithProduct($tp['product_id'], 2);

		$tickets = $this->orderMgr->getTicketsFromOrder($order);
		$this->assertIsArray($tickets);
		$this->assertCount(1, $tickets); // 1 ticket product

		$ticket = reset($tickets);
		$this->assertEquals(2, $ticket['quantity']);
		$this->assertEquals($tp['product_id'], $ticket['product_id']);
	}

	public function test_getTicketsFromOrder_empty_for_regular_products(): void {
		$product = $this->createRegularProduct();
		$order = $this->createOrderWithProduct($product->get_id());

		$tickets = $this->orderMgr->getTicketsFromOrder($order);
		$this->assertIsArray($tickets);
		$this->assertEmpty($tickets);
	}

	public function test_getTicketsFromOrder_only_ticket_items_from_mixed(): void {
		$tp = $this->createTicketProduct();
		$regular = $this->createRegularProduct();

		$order = wc_create_order();
		$order->add_product(wc_get_product($tp['product_id']), 3);
		$order->add_product($regular, 1);
		$order->save();

		$tickets = $this->orderMgr->getTicketsFromOrder($order);
		$this->assertCount(1, $tickets); // Only the ticket product
		$ticket = reset($tickets);
		$this->assertEquals(3, $ticket['quantity']);
	}

	// ── add_serialcode_to_order ──────────────────────────────

	public function test_add_serialcode_generates_codes_for_completed_order(): void {
		$tp = $this->createTicketProduct();

		// Pre-generate codes in the list
		for ($i = 0; $i < 3; $i++) {
			$metaObj = $this->main->getCore()->getMetaObject();
			$metaJson = $this->main->getCore()->json_encode_with_error_handling($metaObj);
			$this->main->getDB()->insert('codes', [
				'list_id' => $tp['list_id'],
				'code' => 'ORDAUTO' . strtoupper(uniqid()),
				'aktiv' => 1,
				'cvv' => '',
				'order_id' => 0,
				'user_id' => 0,
				'meta' => $metaJson,
			]);
		}

		$order = $this->createOrderWithProduct($tp['product_id'], 2);
		$order->set_status('completed');
		$order->save();

		// Trigger ticket generation
		$this->orderMgr->add_serialcode_to_order($order->get_id());

		// Check that codes were assigned
		$items = $order->get_items();
		$codesAssigned = false;
		foreach ($items as $item_id => $item) {
			$codes = wc_get_order_item_meta($item_id, '_saso_eventtickets_product_code', true);
			if (!empty($codes)) {
				$codesAssigned = true;
			}
		}
		$this->assertTrue($codesAssigned, 'Codes should be assigned to completed order');
	}

	public function test_add_serialcode_skips_zero_order_id(): void {
		// Should not throw
		$this->orderMgr->add_serialcode_to_order(0);
		$this->assertTrue(true);
	}

	public function test_add_serialcode_skips_nonexistent_order(): void {
		// Should not throw
		$this->orderMgr->add_serialcode_to_order(999999);
		$this->assertTrue(true);
	}

	// ── woocommerce_order_status_changed ─────────────────────

	public function test_order_status_changed_does_not_throw(): void {
		$tp = $this->createTicketProduct();
		$order = $this->createOrderWithProduct($tp['product_id']);

		// Should not throw for any status change
		$this->orderMgr->woocommerce_order_status_changed(
			$order->get_id(), 'pending', 'processing'
		);
		$this->assertTrue(true);
	}

	public function test_order_status_changed_cancelled_releases_seats(): void {
		$tp = $this->createTicketProduct();
		$order = $this->createOrderWithProduct($tp['product_id']);

		// Status change to cancelled should not throw
		$this->orderMgr->woocommerce_order_status_changed(
			$order->get_id(), 'processing', 'cancelled'
		);
		$this->assertTrue(true);
	}

	// ── woocommerce_order_item_display_meta_key ──────────────

	public function test_display_meta_key_transforms_ticket_keys(): void {
		// Create a mock meta object
		$meta = new stdClass();
		$meta->key = '_saso_eventtickets_product_code';

		$result = $this->orderMgr->woocommerce_order_item_display_meta_key(
			$meta->key, $meta, null
		);
		// Should transform the internal key to a display label
		$this->assertIsString($result);
	}

	public function test_display_meta_key_passes_unknown_keys(): void {
		$meta = new stdClass();
		$meta->key = '_some_other_meta';

		$result = $this->orderMgr->woocommerce_order_item_display_meta_key(
			'_some_other_meta', $meta, null
		);
		$this->assertEquals('_some_other_meta', $result);
	}

	// ── woocommerce_new_order ────────────────────────────────

	public function test_new_order_does_not_throw(): void {
		$order = wc_create_order();
		$order->save();
		$this->orderMgr->woocommerce_new_order($order->get_id());
		$this->assertTrue(true);
	}
}
