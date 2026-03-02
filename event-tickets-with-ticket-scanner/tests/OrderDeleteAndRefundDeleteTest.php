<?php
/**
 * Tests for order/refund deletion and admin cleanup methods:
 * - woocommerce_delete_order: removes all tickets when order is deleted
 * - woocommerce_pre_delete_order_refund: captures parent order ID
 * - woocommerce_delete_order_refund: regenerates or removes tickets
 * - _getList / _getLists: direct DB list retrieval
 * - removeAllNonTicketsFromOrder: removes invalid placeholder codes
 * - getTicketScannerHTMLBoilerplate: scanner HTML template
 */

class OrderDeleteAndRefundDeleteTest extends WP_UnitTestCase {

	private $main;

	public function set_up(): void {
		parent::set_up();
		$this->main = sasoEventtickets::Instance();

		if (!class_exists('WC_Product_Simple')) {
			$this->markTestSkipped('WooCommerce not available');
		}
	}

	private function createTicketProduct(): array {
		$listId = $this->main->getDB()->insert('lists', [
			'name' => 'Delete Test List ' . uniqid(),
			'aktiv' => 1,
			'meta' => '{}',
		]);

		$product = new WC_Product_Simple();
		$product->set_name('Delete Test Ticket ' . uniqid());
		$product->set_regular_price('20.00');
		$product->set_status('publish');
		$product->save();
		$pid = $product->get_id();

		update_post_meta($pid, 'saso_eventtickets_is_ticket', 'yes');
		update_post_meta($pid, 'saso_eventtickets_list', $listId);

		return ['product' => $product, 'product_id' => $pid, 'list_id' => $listId];
	}

	private function createCompletedOrderWithCodes(WC_Product $product, int $quantity = 2): WC_Order {
		$order = wc_create_order();
		$order->add_product($product, $quantity);
		$order->set_billing_first_name('Delete');
		$order->set_billing_last_name('Test');
		$order->set_billing_email('delete@test.com');
		$order->calculate_totals();
		$order->set_status('completed');
		$order->save();
		return wc_get_order($order->get_id());
	}

	private function getOrderItemId(WC_Order $order): int {
		$items = $order->get_items();
		return key($items);
	}

	private function enableOption(string $key): void {
		update_option('sasoEventtickets' . $key, '1');
		$ref = new ReflectionProperty($this->main->getOptions(), '_options');
		$ref->setAccessible(true);
		$options = $ref->getValue($this->main->getOptions());
		foreach ($options as $idx => $option) {
			$options[$idx]['_isLoaded'] = false;
		}
		$ref->setValue($this->main->getOptions(), $options);
	}

	private function disableOption(string $key): void {
		update_option('sasoEventtickets' . $key, '0');
		$ref = new ReflectionProperty($this->main->getOptions(), '_options');
		$ref->setAccessible(true);
		$options = $ref->getValue($this->main->getOptions());
		foreach ($options as $idx => $option) {
			$options[$idx]['_isLoaded'] = false;
		}
		$ref->setValue($this->main->getOptions(), $options);
	}

	// ── woocommerce_delete_order ────────────────────────────────

	public function test_delete_order_removes_all_ticket_meta(): void {
		$this->enableOption('wcRestrictFreeCodeByOrderRefund');

		$tp = $this->createTicketProduct();
		$order = $this->createCompletedOrderWithCodes($tp['product'], 2);
		$item_id = $this->getOrderItemId($order);

		// Verify codes exist
		$codesStr = wc_get_order_item_meta($item_id, '_saso_eventtickets_product_code', true);
		$this->assertNotEmpty($codesStr);

		// Delete order
		$this->main->getWC()->getOrderManager()->woocommerce_delete_order($order->get_id());

		// Meta should be cleared
		$this->assertEmpty(wc_get_order_item_meta($item_id, '_saso_eventtickets_product_code', true));
		$this->assertEmpty(wc_get_order_item_meta($item_id, '_saso_eventtickets_is_ticket', true));

		$this->disableOption('wcRestrictFreeCodeByOrderRefund');
	}

	// ── woocommerce_pre_delete_order_refund ──────────────────────

	public function test_pre_delete_refund_captures_parent_id(): void {
		$tp = $this->createTicketProduct();
		$order = $this->createCompletedOrderWithCodes($tp['product'], 1);

		$refund = wc_create_refund([
			'order_id' => $order->get_id(),
			'amount' => 0,
			'reason' => 'Test',
		]);

		$orderManager = $this->main->getWC()->getOrderManager();
		$result = $orderManager->woocommerce_pre_delete_order_refund('passthrough', $refund, true);

		$this->assertEquals('passthrough', $result);

		// Check stored parent ID via reflection
		$ref = new ReflectionProperty($orderManager, 'refund_parent_id');
		$ref->setAccessible(true);
		$this->assertEquals($order->get_id(), $ref->getValue($orderManager));
	}

	public function test_pre_delete_refund_handles_null_refund(): void {
		$orderManager = $this->main->getWC()->getOrderManager();
		$result = $orderManager->woocommerce_pre_delete_order_refund('val', null, false);
		$this->assertEquals('val', $result);
	}

	// ── woocommerce_delete_order_refund ──────────────────────────

	public function test_delete_refund_with_parent_regenerates_tickets(): void {
		$tp = $this->createTicketProduct();
		$order = $this->createCompletedOrderWithCodes($tp['product'], 2);

		$codes = $this->main->getCore()->getCodesByOrderId($order->get_id());
		$this->assertCount(2, $codes);

		$refund = wc_create_refund([
			'order_id' => $order->get_id(),
			'amount' => 0,
			'reason' => 'Test',
		]);

		$orderManager = $this->main->getWC()->getOrderManager();

		// Set parent ID (as pre_delete_order_refund would)
		$ref = new ReflectionProperty($orderManager, 'refund_parent_id');
		$ref->setAccessible(true);
		$ref->setValue($orderManager, $order->get_id());

		// This should call add_serialcode_to_order for the parent
		$orderManager->woocommerce_delete_order_refund($refund->get_id());

		// Codes should still exist (regenerated)
		$codesAfter = $this->main->getCore()->getCodesByOrderId($order->get_id());
		$this->assertGreaterThanOrEqual(2, count($codesAfter));
	}

	// ── _getList / _getLists ────────────────────────────────────

	public function test_getList_returns_list_by_id(): void {
		$listId = $this->main->getDB()->insert('lists', [
			'name' => 'GetList Test ' . uniqid(),
			'aktiv' => 1,
			'meta' => '{}',
		]);

		$result = $this->main->getAdmin()->getList(['id' => $listId]);
		$this->assertEquals($listId, $result['id']);
		$this->assertStringContainsString('GetList Test', $result['name']);
	}

	public function test_getList_throws_for_missing_id(): void {
		$this->expectException(Exception::class);
		$this->expectExceptionMessage('#104');
		$this->main->getAdmin()->_getList([]);
	}

	public function test_getList_throws_for_nonexistent_id(): void {
		$this->expectException(Exception::class);
		$this->expectExceptionMessage('#105');
		$this->main->getAdmin()->_getList(['id' => 999999]);
	}

	public function test_getLists_returns_all_lists(): void {
		$name1 = 'ListsTest A ' . uniqid();
		$name2 = 'ListsTest B ' . uniqid();
		$this->main->getDB()->insert('lists', ['name' => $name1, 'aktiv' => 1, 'meta' => '{}']);
		$this->main->getDB()->insert('lists', ['name' => $name2, 'aktiv' => 1, 'meta' => '{}']);

		$lists = $this->main->getAdmin()->getLists();
		$this->assertIsArray($lists);
		$this->assertGreaterThanOrEqual(2, count($lists));

		$names = array_column($lists, 'name');
		$this->assertContains($name1, $names);
		$this->assertContains($name2, $names);
	}

	// ── removeAllNonTicketsFromOrder ─────────────────────────────

	public function test_removeAllNonTicketsFromOrder_keeps_valid_codes(): void {
		$tp = $this->createTicketProduct();
		$order = $this->createCompletedOrderWithCodes($tp['product'], 2);
		$item_id = $this->getOrderItemId($order);

		$codesStr = wc_get_order_item_meta($item_id, '_saso_eventtickets_product_code', true);
		$this->assertNotEmpty($codesStr);
		$originalCodes = explode(',', $codesStr);
		$this->assertCount(2, $originalCodes);

		// All codes are valid — should keep them all
		$result = $this->main->getWC()->getOrderManager()->removeAllNonTicketsFromOrder([
			'order_id' => $order->get_id(),
		]);
		$this->assertTrue($result);

		$codesAfter = wc_get_order_item_meta($item_id, '_saso_eventtickets_product_code', true);
		$codePartsAfter = explode(',', $codesAfter);
		$this->assertCount(2, $codePartsAfter);
	}

	public function test_removeAllNonTicketsFromOrder_removes_placeholder_codes(): void {
		$tp = $this->createTicketProduct();
		$order = $this->createCompletedOrderWithCodes($tp['product'], 1);
		$item_id = $this->getOrderItemId($order);

		// Inject a fake code that doesn't exist in DB
		$realCode = wc_get_order_item_meta($item_id, '_saso_eventtickets_product_code', true);
		$fakeCode = 'FAKE_NONEXISTENT_' . uniqid();
		wc_delete_order_item_meta($item_id, '_saso_eventtickets_product_code');
		wc_add_order_item_meta($item_id, '_saso_eventtickets_product_code', $realCode . ',' . $fakeCode);

		$this->main->getWC()->getOrderManager()->removeAllNonTicketsFromOrder([
			'order_id' => $order->get_id(),
		]);

		$codesAfter = wc_get_order_item_meta($item_id, '_saso_eventtickets_product_code', true);
		$this->assertStringContainsString(trim($realCode), $codesAfter);
		$this->assertStringNotContainsString($fakeCode, $codesAfter);
	}

	public function test_removeAllNonTicketsFromOrder_invalid_order(): void {
		$result = $this->main->getWC()->getOrderManager()->removeAllNonTicketsFromOrder([
			'order_id' => 0,
		]);
		$this->assertTrue($result);
	}

	// ── getTicketScannerHTMLBoilerplate ──────────────────────────

	public function test_getTicketScannerHTMLBoilerplate_returns_html(): void {
		$html = $this->main->getTicketHandler()->getTicketScannerHTMLBoilerplate();

		$this->assertStringContainsString('ticket_scanner_info_area', $html);
		$this->assertStringContainsString('reader', $html);
		$this->assertStringContainsString('ticket_info', $html);
		$this->assertStringContainsString('reader_options', $html);
	}

	public function test_getTicketScannerHTMLBoilerplate_is_filterable(): void {
		$filter = function ($html) {
			return $html . '<!-- custom -->';
		};
		add_filter('saso_eventtickets_ticket_getTicketScannerHTMLBoilerplate', $filter);

		$html = $this->main->getTicketHandler()->getTicketScannerHTMLBoilerplate();
		$this->assertStringContainsString('<!-- custom -->', $html);

		remove_filter('saso_eventtickets_ticket_getTicketScannerHTMLBoilerplate', $filter);
	}
}
