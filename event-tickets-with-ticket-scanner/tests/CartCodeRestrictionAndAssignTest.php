<?php
/**
 * Batch 60 — Cart code restriction and code assignment:
 * - check_code_for_cartitem: cart code restriction validation
 * - addCodeFromListForOrder: ticket generation for order from list
 * - removeWoocommerceOrderInfoFromCode: remove order info from code
 */

class CartCodeRestrictionAndAssignTest extends WP_UnitTestCase {

	private $main;

	public function set_up(): void {
		parent::set_up();
		$this->main = sasoEventtickets::Instance();

		if (!class_exists('WC_Product_Simple')) {
			$this->markTestSkipped('WooCommerce not available');
		}
	}

	// ── check_code_for_cartitem ─────────────────────────────────

	public function test_check_code_empty_returns_zero(): void {
		$frontend = $this->main->getWC()->getFrontendManager();
		$result = $frontend->check_code_for_cartitem(['product_id' => 1], '');
		$this->assertEquals(0, $result);
	}

	public function test_check_code_no_restriction_returns_four(): void {
		$product = new WC_Product_Simple();
		$product->set_name('NoRestrict ' . uniqid());
		$product->set_regular_price('10.00');
		$product->set_status('publish');
		$product->save();

		$frontend = $this->main->getWC()->getFrontendManager();
		$result = $frontend->check_code_for_cartitem(['product_id' => $product->get_id()], 'SOMECODE');
		$this->assertEquals(4, $result);
	}

	public function test_check_code_invalid_returns_three(): void {
		$listId = $this->main->getDB()->insert('lists', [
			'name' => 'RestrList ' . uniqid(),
			'aktiv' => 1,
			'meta' => '{}',
		]);

		$pid = $this->createTicketProduct([
			'saso_eventtickets_list_sale_restriction' => $listId,
		]);

		$frontend = $this->main->getWC()->getFrontendManager();
		$result = $frontend->check_code_for_cartitem(['product_id' => $pid], 'NONEXISTENT' . uniqid());
		$this->assertEquals(3, $result);
	}

	public function test_check_code_valid_returns_one(): void {
		$listId = $this->main->getDB()->insert('lists', [
			'name' => 'ValidList ' . uniqid(),
			'aktiv' => 1,
			'meta' => '{}',
		]);

		$pid = $this->createTicketProduct([
			'saso_eventtickets_list_sale_restriction' => $listId,
		]);

		$codeStr = 'VALID' . strtoupper(uniqid());
		$this->main->getDB()->insert('codes', [
			'list_id' => $listId,
			'code' => $codeStr,
			'aktiv' => 1,
			'cvv' => '',
			'order_id' => 0,
			'user_id' => 0,
			'meta' => '{}',
		]);

		$frontend = $this->main->getWC()->getFrontendManager();
		$result = $frontend->check_code_for_cartitem(['product_id' => $pid], $codeStr);
		$this->assertEquals(1, $result);
	}

	public function test_check_code_inactive_returns_three(): void {
		$listId = $this->main->getDB()->insert('lists', [
			'name' => 'InactList ' . uniqid(),
			'aktiv' => 1,
			'meta' => '{}',
		]);

		$pid = $this->createTicketProduct([
			'saso_eventtickets_list_sale_restriction' => $listId,
		]);

		$codeStr = 'INACT' . strtoupper(uniqid());
		$this->main->getDB()->insert('codes', [
			'list_id' => $listId,
			'code' => $codeStr,
			'aktiv' => 0,
			'cvv' => '',
			'order_id' => 0,
			'user_id' => 0,
			'meta' => '{}',
		]);

		$frontend = $this->main->getWC()->getFrontendManager();
		$result = $frontend->check_code_for_cartitem(['product_id' => $pid], $codeStr);
		$this->assertEquals(3, $result);
	}

	public function test_check_code_wrong_list_returns_three(): void {
		$listId = $this->main->getDB()->insert('lists', [
			'name' => 'RightList ' . uniqid(),
			'aktiv' => 1,
			'meta' => '{}',
		]);
		$otherListId = $this->main->getDB()->insert('lists', [
			'name' => 'WrongList ' . uniqid(),
			'aktiv' => 1,
			'meta' => '{}',
		]);

		$pid = $this->createTicketProduct([
			'saso_eventtickets_list_sale_restriction' => $listId,
		]);

		$codeStr = 'WLST' . strtoupper(uniqid());
		$this->main->getDB()->insert('codes', [
			'list_id' => $otherListId,
			'code' => $codeStr,
			'aktiv' => 1,
			'cvv' => '',
			'order_id' => 0,
			'user_id' => 0,
			'meta' => '{}',
		]);

		$frontend = $this->main->getWC()->getFrontendManager();
		$result = $frontend->check_code_for_cartitem(['product_id' => $pid], $codeStr);
		$this->assertEquals(3, $result);
	}

	// ── addCodeFromListForOrder ──────────────────────────────────

	public function test_addCodeFromListForOrder_creates_code(): void {
		$listId = $this->main->getDB()->insert('lists', [
			'name' => 'AssignList ' . uniqid(),
			'aktiv' => 1,
			'meta' => '{}',
		]);

		$pid = $this->createTicketProduct();
		$order = wc_create_order();
		$product = wc_get_product($pid);
		$order->add_product($product, 1);
		$order->set_status('completed');
		$order->save();

		$items = $order->get_items();
		$firstItemId = array_key_first($items);

		$result = $this->main->getAdmin()->addCodeFromListForOrder(
			$listId,
			$order->get_id(),
			$pid,
			$firstItemId
		);

		// Returns the generated code string
		$this->assertIsString($result);
		$this->assertNotEmpty($result);
	}

	public function test_addCodeFromListForOrder_throws_for_zero_list(): void {
		$this->expectException(Exception::class);
		$this->expectExceptionMessageMatches('/#602/');

		$this->main->getAdmin()->addCodeFromListForOrder(0, 1);
	}

	public function test_addCodeFromListForOrder_throws_for_nonexistent_list(): void {
		$this->expectException(Exception::class);

		$this->main->getAdmin()->addCodeFromListForOrder(999999, 1);
	}

	public function test_addCodeFromListForOrder_reuses_existing_code(): void {
		// wcassignmentReuseNotusedCodes defaults to true
		$listId = $this->main->getDB()->insert('lists', [
			'name' => 'ReuseList ' . uniqid(),
			'aktiv' => 1,
			'meta' => '{}',
		]);

		// Pre-insert an unused code in the list (code_display is used for reuse lookup)
		$preCode = 'PREEXIST' . strtoupper(uniqid());
		$this->main->getDB()->insert('codes', [
			'list_id' => $listId,
			'code' => $preCode,
			'code_display' => $preCode,
			'aktiv' => 1,
			'cvv' => '',
			'order_id' => 0,
			'user_id' => 0,
			'meta' => '{}',
		]);

		$pid = $this->createTicketProduct();
		$order = wc_create_order();
		$product = wc_get_product($pid);
		$order->add_product($product, 1);
		$order->set_status('completed');
		$order->save();

		$items = $order->get_items();
		$firstItemId = array_key_first($items);

		$result = $this->main->getAdmin()->addCodeFromListForOrder(
			$listId,
			$order->get_id(),
			$pid,
			$firstItemId
		);

		// Returns string; the pre-existing code should be reused
		$this->assertIsString($result);
		$this->assertEquals($preCode, $result);
	}

	// ── removeWoocommerceOrderInfoFromCode ───────────────────────

	public function test_removeOrderInfoFromCode_throws_without_code(): void {
		$this->expectException(Exception::class);
		$this->expectExceptionMessageMatches('/#9611/');

		$this->main->getAdmin()->removeWoocommerceOrderInfoFromCode([]);
	}

	public function test_removeOrderInfoFromCode_removes_order_link(): void {
		$listId = $this->main->getDB()->insert('lists', [
			'name' => 'RemoveInfo List ' . uniqid(),
			'aktiv' => 1,
			'meta' => '{}',
		]);

		$pid = $this->createTicketProduct();
		$order = wc_create_order();
		$product = wc_get_product($pid);
		$order->add_product($product, 1);
		$order->set_status('completed');
		$order->save();

		$items = $order->get_items();
		$firstItemId = array_key_first($items);

		$metaObj = $this->main->getCore()->getMetaObject();
		$metaObj['woocommerce']['product_id'] = $pid;
		$metaObj['woocommerce']['item_id'] = $firstItemId;
		$metaObj['woocommerce']['order_id'] = $order->get_id();
		$metaJson = $this->main->getCore()->json_encode_with_error_handling($metaObj);

		$codeStr = 'RMINFO' . strtoupper(uniqid());
		$this->main->getDB()->insert('codes', [
			'list_id' => $listId,
			'code' => $codeStr,
			'aktiv' => 1,
			'cvv' => '',
			'order_id' => $order->get_id(),
			'user_id' => 0,
			'meta' => $metaJson,
		]);

		// Also set order item meta
		$orderItem = $items[$firstItemId];
		$orderItem->update_meta_data('_saso_eventtickets_product_code', $codeStr);
		$orderItem->update_meta_data('_saso_eventtickets_is_ticket', 'yes');
		$orderItem->save();

		// Remove order info
		$this->main->getAdmin()->removeWoocommerceOrderInfoFromCode(['code' => $codeStr]);

		// Verify the code's order_id was reset
		$codeObj = $this->main->getCore()->retrieveCodeByCode($codeStr);
		$this->assertEquals(0, intval($codeObj['order_id']));
	}

	// ── helpers ─────────────────────────────────────────────────

	private function createTicketProduct(array $extra_meta = []): int {
		$product = new WC_Product_Simple();
		$product->set_name('CartAssign ' . uniqid());
		$product->set_regular_price('20.00');
		$product->set_status('publish');
		$product->save();
		$pid = $product->get_id();

		update_post_meta($pid, 'saso_eventtickets_is_ticket', 'yes');
		update_post_meta($pid, 'saso_eventtickets_ticket_start_date', '2026-12-25');

		foreach ($extra_meta as $key => $value) {
			update_post_meta($pid, $key, $value);
		}

		return $pid;
	}
}
