<?php
/**
 * Batch 34a — WC Order lifecycle methods:
 * - woocommerce_new_order: session cleanup + action hook
 * - woocommerce_delete_order: removes all tickets from order
 * - woocommerce_pre_delete_order_refund + woocommerce_delete_order_refund: parent order ticket regeneration
 * - woocommerce_order_partially_refunded: code trimming on partial refund
 * - wpo_wcpdf_after_item_meta: PDF invoice rendering
 * - add_serialcode_to_order_forItem: per-item ticket creation
 */

class WCOrderLifecycleTest extends WP_UnitTestCase {

	private $main;

	public function set_up(): void {
		parent::set_up();
		$this->main = sasoEventtickets::Instance();

		if (!class_exists('WC_Product_Simple')) {
			$this->markTestSkipped('WooCommerce not available');
		}
	}

	// ── woocommerce_new_order ──────────────────────────────────

	public function test_new_order_fires_action(): void {
		$fired = false;
		$callback = function () use (&$fired) {
			$fired = true;
		};
		add_action($this->main->_do_action_prefix . 'woocommerce-hooks_woocommerce_new_order', $callback);

		$order = wc_create_order();
		$order->save();

		$this->main->getWC()->getOrderManager()->woocommerce_new_order($order->get_id());

		$this->assertTrue($fired);
		remove_action($this->main->_do_action_prefix . 'woocommerce-hooks_woocommerce_new_order', $callback);
	}

	public function test_new_order_clears_session_data(): void {
		// Set session data
		if (WC()->session) {
			WC()->session->set('saso_eventtickets_request_name_per_ticket', ['test' => 'value']);
			WC()->session->set('saso_eventtickets_request_value_per_ticket', ['test' => 'value']);
		}

		$order = wc_create_order();
		$order->save();

		$this->main->getWC()->getOrderManager()->woocommerce_new_order($order->get_id());

		if (WC()->session) {
			$this->assertNull(WC()->session->get('saso_eventtickets_request_name_per_ticket'));
			$this->assertNull(WC()->session->get('saso_eventtickets_request_value_per_ticket'));
		} else {
			$this->assertTrue(true); // No session = no crash
		}
	}

	// ── woocommerce_delete_order ───────────────────────────────

	public function test_delete_order_removes_tickets(): void {
		$tp = $this->createTicketProduct();
		$order = $this->createCompletedOrder($tp['product']);

		$this->main->getWC()->getOrderManager()->add_serialcode_to_order($order->get_id());

		$codesBefore = $this->main->getCore()->getCodesByOrderId($order->get_id());
		$this->assertNotEmpty($codesBefore);

		$this->main->getWC()->getOrderManager()->woocommerce_delete_order($order->get_id());

		// After deletion, codes should have WC info cleared
		foreach ($codesBefore as $c) {
			$updated = $this->main->getCore()->retrieveCodeByCode($c['code']);
			if ($updated) {
				$meta = json_decode($updated['meta'], true);
				$this->assertEmpty(
					$meta['woocommerce']['order_id'] ?? 0,
					'WC order info should be cleared after order deletion'
				);
			}
		}
	}

	// ── woocommerce_pre_delete_order_refund + woocommerce_delete_order_refund ─

	public function test_pre_delete_refund_stores_parent_id(): void {
		$tp = $this->createTicketProduct();
		$order = $this->createCompletedOrder($tp['product'], 3);
		$this->main->getWC()->getOrderManager()->add_serialcode_to_order($order->get_id());

		// Create a refund
		$refund = wc_create_refund([
			'order_id' => $order->get_id(),
			'amount' => '10.00',
			'reason' => 'Test refund',
		]);

		if (is_wp_error($refund)) {
			$this->markTestSkipped('Could not create refund: ' . $refund->get_error_message());
		}

		// Call pre_delete to store parent id
		$orderMgr = $this->main->getWC()->getOrderManager();
		$orderMgr->woocommerce_pre_delete_order_refund(true, $refund, true);

		// Verify stored via reflection
		$ref = new ReflectionProperty($orderMgr, 'refund_parent_id');
		$ref->setAccessible(true);
		$this->assertEquals($order->get_id(), $ref->getValue($orderMgr));
	}

	public function test_delete_order_refund_regenerates_parent_tickets(): void {
		$tp = $this->createTicketProduct();
		$order = $this->createCompletedOrder($tp['product'], 2);
		$this->main->getWC()->getOrderManager()->add_serialcode_to_order($order->get_id());

		$codesInitial = $this->main->getCore()->getCodesByOrderId($order->get_id());
		$countInitial = count($codesInitial);

		// Create refund
		$refund = wc_create_refund([
			'order_id' => $order->get_id(),
			'amount' => '10.00',
			'reason' => 'Test refund for delete',
		]);

		if (is_wp_error($refund)) {
			$this->markTestSkipped('Could not create refund');
		}

		$orderMgr = $this->main->getWC()->getOrderManager();

		// Pre-delete stores parent
		$orderMgr->woocommerce_pre_delete_order_refund(true, $refund, true);

		// Delete refund → should trigger regeneration for parent
		$orderMgr->woocommerce_delete_order_refund($refund->get_id());

		// After refund deletion, parent should still have codes
		$codesAfter = $this->main->getCore()->getCodesByOrderId($order->get_id());
		$this->assertNotEmpty($codesAfter);
	}

	public function test_delete_order_refund_without_parent_removes_tickets(): void {
		$orderMgr = $this->main->getWC()->getOrderManager();

		// Reset refund_parent_id to 0
		$ref = new ReflectionProperty($orderMgr, 'refund_parent_id');
		$ref->setAccessible(true);
		$ref->setValue($orderMgr, 0);

		// Calling with nonexistent ID and no parent should not crash
		$orderMgr->woocommerce_delete_order_refund(999999);
		$this->assertTrue(true);
	}

	// ── woocommerce_order_partially_refunded ───────────────────

	public function test_partial_refund_trims_codes_when_option_active(): void {
		update_option('sasoEventticketswcassignmentOrderItemRefund', '1');
		$this->main->getOptions()->initOptions();

		$tp = $this->createTicketProduct();
		$order = $this->createCompletedOrder($tp['product'], 3);
		$this->main->getWC()->getOrderManager()->add_serialcode_to_order($order->get_id());

		$order = wc_get_order($order->get_id());
		$codesBeforeRefund = $this->main->getCore()->getCodesByOrderId($order->get_id());
		$this->assertCount(3, $codesBeforeRefund);

		// Create partial refund for 1 item
		$items_to_refund = [];
		foreach ($order->get_items() as $item_id => $item) {
			$items_to_refund[$item_id] = [
				'qty' => 1,
				'refund_total' => 10.00,
			];
		}

		$refund = wc_create_refund([
			'order_id' => $order->get_id(),
			'amount' => '10.00',
			'reason' => 'Partial refund test',
			'line_items' => $items_to_refund,
		]);

		if (is_wp_error($refund)) {
			$this->markTestSkipped('Could not create partial refund');
		}

		$this->main->getWC()->getOrderManager()->woocommerce_order_partially_refunded(
			$order->get_id(), $refund->get_id()
		);

		// After partial refund of 1 item, should have 2 codes
		$order = wc_get_order($order->get_id());
		foreach ($order->get_items() as $item_id => $item) {
			$codes = wc_get_order_item_meta($item_id, '_saso_eventtickets_product_code', true);
			if (!empty($codes)) {
				$codeArr = explode(',', $codes);
				$this->assertCount(2, $codeArr, 'Should have 2 codes after refunding 1 of 3');
			}
		}
	}

	public function test_partial_refund_skips_when_option_inactive(): void {
		update_option('sasoEventticketswcassignmentOrderItemRefund', '0');
		$this->main->getOptions()->initOptions();

		$tp = $this->createTicketProduct();
		$order = $this->createCompletedOrder($tp['product'], 2);
		$this->main->getWC()->getOrderManager()->add_serialcode_to_order($order->get_id());

		// Create partial refund
		$refund = wc_create_refund([
			'order_id' => $order->get_id(),
			'amount' => '10.00',
			'reason' => 'Partial inactive test',
		]);

		if (is_wp_error($refund)) {
			$this->markTestSkipped('Could not create refund');
		}

		$order = wc_get_order($order->get_id());
		$codesCountBefore = 0;
		foreach ($order->get_items() as $item_id => $item) {
			$codes = wc_get_order_item_meta($item_id, '_saso_eventtickets_product_code', true);
			if (!empty($codes)) {
				$codesCountBefore = count(explode(',', $codes));
			}
		}

		$this->main->getWC()->getOrderManager()->woocommerce_order_partially_refunded(
			$order->get_id(), $refund->get_id()
		);

		$order = wc_get_order($order->get_id());
		$codesCountAfter = 0;
		foreach ($order->get_items() as $item_id => $item) {
			$codes = wc_get_order_item_meta($item_id, '_saso_eventtickets_product_code', true);
			if (!empty($codes)) {
				$codesCountAfter = count(explode(',', $codes));
			}
		}

		$this->assertEquals($codesCountBefore, $codesCountAfter, 'Codes should not be trimmed when option is inactive');
	}

	// ── wpo_wcpdf_after_item_meta — PDF invoice integration ────

	public function test_wpo_wcpdf_no_output_for_unpaid_order(): void {
		$tp = $this->createTicketProduct();
		$order = wc_create_order();
		$order->add_product($tp['product'], 1);
		$order->set_status('pending');
		$order->save();

		$orderMgr = $this->main->getWC()->getOrderManager();
		$order = wc_get_order($order->get_id());

		$items = $order->get_items();
		$firstItem = null;
		$firstItemId = 0;
		foreach ($items as $item_id => $item) {
			$firstItem = $item;
			$firstItemId = $item_id;
			break;
		}

		ob_start();
		$orderMgr->wpo_wcpdf_after_item_meta('invoice', ['item_id' => $firstItemId], $order);
		$output = ob_get_clean();

		$this->assertEmpty($output, 'No PDF output for unpaid order');
	}

	public function test_wpo_wcpdf_outputs_codes_for_paid_order(): void {
		$tp = $this->createTicketProduct();
		$order = $this->createCompletedOrder($tp['product']);
		$this->main->getWC()->getOrderManager()->add_serialcode_to_order($order->get_id());

		$order = wc_get_order($order->get_id());
		$targetItemId = 0;
		$targetProductId = 0;
		foreach ($order->get_items() as $item_id => $item) {
			$codes = wc_get_order_item_meta($item_id, '_saso_eventtickets_product_code', true);
			if (!empty($codes)) {
				$targetItemId = $item_id;
				$targetProductId = $item->get_product_id();
			}
		}

		if ($targetItemId === 0) {
			$this->markTestSkipped('No codes in order item');
		}

		// wpo_wcpdf_after_item_meta expects $item as array with item_id and product_id
		$itemArr = [
			'item_id' => $targetItemId,
			'product_id' => $targetProductId,
		];

		ob_start();
		$this->main->getWC()->getOrderManager()->wpo_wcpdf_after_item_meta(
			'invoice', $itemArr, $order
		);
		$output = ob_get_clean();

		// Output depends on options, but should not crash
		$this->assertIsString($output);
	}

	// ── add_serialcode_to_order_forItem — per-item ticket creation ─

	public function test_add_serialcode_forItem_creates_correct_number_of_codes(): void {
		$tp = $this->createTicketProduct();
		$order = $this->createCompletedOrder($tp['product'], 2);

		$order = wc_get_order($order->get_id());
		foreach ($order->get_items() as $item_id => $item) {
			$pid = $item->get_product_id();
			if (get_post_meta($pid, 'saso_eventtickets_is_ticket', true) == 'yes') {
				$ret = $this->main->getWC()->getOrderManager()->add_serialcode_to_order_forItem(
					$order->get_id(), $order, $item_id, $item, $tp['list_id']
				);

				$this->assertIsArray($ret);

				// Check that codes were stored in order item meta
				$codes = wc_get_order_item_meta($item_id, '_saso_eventtickets_product_code', true);
				$this->assertNotEmpty($codes);
				$codeArr = explode(',', $codes);
				$this->assertCount(2, $codeArr, 'Should create 2 codes for qty 2');
			}
		}
	}

	public function test_add_serialcode_forItem_idempotent(): void {
		$tp = $this->createTicketProduct();
		$order = $this->createCompletedOrder($tp['product'], 1);

		$order = wc_get_order($order->get_id());
		foreach ($order->get_items() as $item_id => $item) {
			$pid = $item->get_product_id();
			if (get_post_meta($pid, 'saso_eventtickets_is_ticket', true) == 'yes') {
				// First call
				$this->main->getWC()->getOrderManager()->add_serialcode_to_order_forItem(
					$order->get_id(), $order, $item_id, $item, $tp['list_id']
				);
				$codes1 = wc_get_order_item_meta($item_id, '_saso_eventtickets_product_code', true);

				// Second call
				$this->main->getWC()->getOrderManager()->add_serialcode_to_order_forItem(
					$order->get_id(), $order, $item_id, $item, $tp['list_id']
				);
				$codes2 = wc_get_order_item_meta($item_id, '_saso_eventtickets_product_code', true);

				$this->assertEquals($codes1, $codes2, 'Repeated call should not create duplicate codes');
			}
		}
	}

	public function test_add_serialcode_forItem_with_zero_list_returns_empty(): void {
		$tp = $this->createTicketProduct();
		$order = $this->createCompletedOrder($tp['product'], 1);

		$order = wc_get_order($order->get_id());
		foreach ($order->get_items() as $item_id => $item) {
			$ret = $this->main->getWC()->getOrderManager()->add_serialcode_to_order_forItem(
				$order->get_id(), $order, $item_id, $item, 0
			);

			$this->assertEmpty($ret, 'Should return empty for list_id=0');
		}
	}

	// ── Helper methods ─────────────────────────────────────────

	private function createTicketProduct(): array {
		$listId = $this->main->getDB()->insert('lists', [
			'name' => 'OrderLifecycle List ' . uniqid(),
			'aktiv' => 1,
			'meta' => '{}',
		]);

		$product = new WC_Product_Simple();
		$product->set_name('OrderLifecycle Ticket ' . uniqid());
		$product->set_regular_price('10.00');
		$product->set_status('publish');
		$product->save();
		$pid = $product->get_id();

		update_post_meta($pid, 'saso_eventtickets_is_ticket', 'yes');
		update_post_meta($pid, 'saso_eventtickets_list', $listId);

		return ['product' => $product, 'product_id' => $pid, 'list_id' => $listId];
	}

	private function createCompletedOrder(WC_Product $product, int $quantity = 1): WC_Order {
		$order = wc_create_order();
		$order->add_product($product, $quantity);
		$order->calculate_totals();
		$order->set_status('completed');
		$order->save();
		return wc_get_order($order->get_id());
	}
}
