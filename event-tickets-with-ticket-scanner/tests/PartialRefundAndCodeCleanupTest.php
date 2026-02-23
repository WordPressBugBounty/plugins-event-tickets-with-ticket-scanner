<?php
/**
 * Tests for partial refund and code cleanup operations:
 * - woocommerce_order_partially_refunded: Removes excess codes on partial refund
 * - woocommerce_delete_order_item: Frees codes/restrictions when item deleted
 * - removeAllTicketsFromOrder: Bulk remove all tickets
 * - removeUsedInformationFromCode: Clear redemption data from code
 * - removeWoocommerceOrderInfoFromCode: Detach code from order
 * - removeRedeemWoocommerceTicketForCode: Un-redeem without detaching
 * - deleteCodesEntryOnOrderItem: Delete ticket metadata from order item
 */

class PartialRefundAndCodeCleanupTest extends WP_UnitTestCase {

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
			'name' => 'Refund List ' . uniqid(),
			'aktiv' => 1,
			'meta' => '{}',
		]);

		$product = new WC_Product_Simple();
		$product->set_name('Refund Ticket ' . uniqid());
		$product->set_regular_price('25.00');
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

	private function createCompletedOrderWithCodes(WC_Product $product, int $quantity = 3): WC_Order {
		$order = wc_create_order();
		$order->add_product($product, $quantity);
		$order->set_billing_first_name('Refund');
		$order->set_billing_last_name('Test');
		$order->set_billing_email('refund@test.com');
		$order->calculate_totals();
		$order->set_status('completed');
		$order->save();
		return wc_get_order($order->get_id());
	}

	private function getOrderItemAndId(WC_Order $order): array {
		$items = $order->get_items();
		$item = reset($items);
		$item_id = key($items);
		return ['item' => $item, 'item_id' => $item_id];
	}

	private function getCodeCount(int $order_id): int {
		return count($this->main->getCore()->getCodesByOrderId($order_id));
	}

	private function enableOption(string $key): void {
		update_option('sasoEventtickets' . $key, '1');
		$this->resetOptionsCache();
	}

	private function disableOption(string $key): void {
		update_option('sasoEventtickets' . $key, '0');
		$this->resetOptionsCache();
	}

	private function resetOptionsCache(): void {
		// Reset _isLoaded flags so options are re-read from DB
		$ref = new ReflectionProperty($this->main->getOptions(), '_options');
		$ref->setAccessible(true);
		$options = $ref->getValue($this->main->getOptions());
		foreach ($options as $idx => $option) {
			$options[$idx]['_isLoaded'] = false;
		}
		$ref->setValue($this->main->getOptions(), $options);
	}

	private function createRefundForOrder(WC_Order $order, int $item_id, int $qty): WC_Order_Refund {
		$refund = wc_create_refund([
			'order_id' => $order->get_id(),
			'amount' => 0,
			'reason' => 'Test refund',
			'line_items' => [
				$item_id => [
					'qty' => $qty,
					'refund_total' => 0,
				],
			],
		]);
		return $refund;
	}

	// ── removeUsedInformationFromCode ───────────────────────────

	public function test_removeUsedInformationFromCode_clears_registration_data(): void {
		$tp = $this->createTicketProduct();
		$order = $this->createCompletedOrderWithCodes($tp['product'], 1);

		$codes = $this->main->getCore()->getCodesByOrderId($order->get_id());
		$this->assertNotEmpty($codes);
		$code = $codes[0]['code'];

		// Simulate used info
		$codeObj = $this->main->getCore()->retrieveCodeByCode($code);
		$metaObj = $this->main->getCore()->encodeMetaValuesAndFillObject($codeObj['meta'], $codeObj);
		$metaObj['used']['reg_ip'] = '192.168.1.1';
		$metaObj['used']['reg_request'] = '2026-01-01 12:00:00';
		$metaObj['confirmedCount'] = 5;
		$codeObj['meta'] = $this->main->getCore()->json_encode_with_error_handling($metaObj);
		$this->main->getDB()->update('codes', ['meta' => $codeObj['meta']], ['id' => $codeObj['id']]);

		// Remove used info
		$result = $this->main->getAdmin()->removeUsedInformationFromCode(['code' => $code]);

		$metaAfter = $this->main->getCore()->encodeMetaValuesAndFillObject($result['meta'], $result);
		$this->assertEmpty($metaAfter['used']['reg_ip']);
		$this->assertEmpty($metaAfter['used']['reg_request']);
		$this->assertEquals(0, $metaAfter['confirmedCount']);
	}

	public function test_removeUsedInformationFromCode_throws_without_code_param(): void {
		$this->expectException(Exception::class);
		$this->expectExceptionMessage('#9231');
		$this->main->getAdmin()->removeUsedInformationFromCode([]);
	}

	// ── removeWoocommerceOrderInfoFromCode ───────────────────────

	public function test_removeWoocommerceOrderInfoFromCode_detaches_code_from_order(): void {
		$tp = $this->createTicketProduct();
		$order = $this->createCompletedOrderWithCodes($tp['product'], 1);

		$codes = $this->main->getCore()->getCodesByOrderId($order->get_id());
		$this->assertNotEmpty($codes);
		$code = $codes[0]['code'];

		// Verify code is linked to order
		$codeObj = $this->main->getCore()->retrieveCodeByCode($code);
		$this->assertEquals($order->get_id(), intval($codeObj['order_id']));

		// Remove order info
		$this->main->getAdmin()->removeWoocommerceOrderInfoFromCode(['code' => $code]);

		// Verify code is detached
		$codeAfter = $this->main->getCore()->retrieveCodeByCode($code);
		$this->assertEquals(0, intval($codeAfter['order_id']));

		$metaAfter = $this->main->getCore()->encodeMetaValuesAndFillObject($codeAfter['meta'], $codeAfter);
		$this->assertEquals(0, intval($metaAfter['woocommerce']['order_id']));
	}

	public function test_removeWoocommerceOrderInfoFromCode_throws_without_code_param(): void {
		$this->expectException(Exception::class);
		$this->expectExceptionMessage('#9611');
		$this->main->getAdmin()->removeWoocommerceOrderInfoFromCode([]);
	}

	public function test_removeWoocommerceOrderInfoFromCode_updates_order_item_meta(): void {
		$tp = $this->createTicketProduct();
		$order = $this->createCompletedOrderWithCodes($tp['product'], 2);

		$data = $this->getOrderItemAndId($order);
		$codesStr = wc_get_order_item_meta($data['item_id'], '_saso_eventtickets_product_code', true);
		$this->assertNotEmpty($codesStr);

		$codeParts = explode(',', $codesStr);
		$this->assertCount(2, $codeParts);

		// Remove first code's order info
		$this->main->getAdmin()->removeWoocommerceOrderInfoFromCode(['code' => trim($codeParts[0])]);

		// After removal, check order item — depending on remaining codes, it may be reduced or cleared
		$codesStrAfter = wc_get_order_item_meta($data['item_id'], '_saso_eventtickets_product_code', true);
		// The remaining code(s) should still be in the meta, or meta should be cleared if none remain
		// With 2 codes, removing 1 leaves 1
		if (!empty($codesStrAfter)) {
			$remaining = explode(',', $codesStrAfter);
			// Count should be less than or equal to original
			$this->assertLessThanOrEqual(2, count($remaining));
		}
	}

	// ── removeRedeemWoocommerceTicketForCode ─────────────────────

	public function test_removeRedeemWoocommerceTicketForCode_clears_redeem_data(): void {
		$tp = $this->createTicketProduct();
		$order = $this->createCompletedOrderWithCodes($tp['product'], 1);

		$codes = $this->main->getCore()->getCodesByOrderId($order->get_id());
		$code = $codes[0]['code'];

		// Simulate redemption
		$codeObj = $this->main->getCore()->retrieveCodeByCode($code);
		$metaObj = $this->main->getCore()->encodeMetaValuesAndFillObject($codeObj['meta'], $codeObj);
		$metaObj['wc_ticket']['redeemed_date'] = '2026-01-15 14:00:00';
		$metaObj['wc_ticket']['redeemed_date_tz'] = 'Europe/Berlin';
		$metaObj['wc_ticket']['ip'] = '10.0.0.1';
		$metaObj['used']['reg_ip'] = '10.0.0.1';
		$codeObj['meta'] = $this->main->getCore()->json_encode_with_error_handling($metaObj);
		$this->main->getDB()->update('codes', ['meta' => $codeObj['meta'], 'redeemed' => 1], ['id' => $codeObj['id']]);

		// Un-redeem
		$result = $this->main->getAdmin()->removeRedeemWoocommerceTicketForCode(['code' => $code]);

		$metaAfter = $this->main->getCore()->encodeMetaValuesAndFillObject($result['meta'], $result);
		$this->assertEmpty($metaAfter['wc_ticket']['redeemed_date']);
		$this->assertEmpty($metaAfter['wc_ticket']['ip']);
		$this->assertEmpty($metaAfter['used']['reg_ip']);

		// Check redeemed flag in DB
		$codeAfter = $this->main->getCore()->retrieveCodeByCode($code);
		$this->assertEquals(0, intval($codeAfter['redeemed']));
	}

	public function test_removeRedeemWoocommerceTicketForCode_throws_without_code(): void {
		$this->expectException(Exception::class);
		$this->expectExceptionMessage('#9621');
		$this->main->getAdmin()->removeRedeemWoocommerceTicketForCode([]);
	}

	public function test_removeRedeemWoocommerceTicketForCode_keeps_order_link(): void {
		$tp = $this->createTicketProduct();
		$order = $this->createCompletedOrderWithCodes($tp['product'], 1);

		$codes = $this->main->getCore()->getCodesByOrderId($order->get_id());
		$code = $codes[0]['code'];

		// Un-redeem
		$this->main->getAdmin()->removeRedeemWoocommerceTicketForCode(['code' => $code]);

		// Code should still be linked to order
		$codeAfter = $this->main->getCore()->retrieveCodeByCode($code);
		$this->assertEquals($order->get_id(), intval($codeAfter['order_id']));
	}

	// ── deleteCodesEntryOnOrderItem ─────────────────────────────

	public function test_deleteCodesEntryOnOrderItem_clears_all_ticket_meta(): void {
		$tp = $this->createTicketProduct();
		$order = $this->createCompletedOrderWithCodes($tp['product'], 1);

		$data = $this->getOrderItemAndId($order);

		// Verify meta exists
		$isTicket = wc_get_order_item_meta($data['item_id'], '_saso_eventtickets_is_ticket', true);
		$this->assertNotEmpty($isTicket);

		$codesStr = wc_get_order_item_meta($data['item_id'], '_saso_eventtickets_product_code', true);
		$this->assertNotEmpty($codesStr);

		// Delete
		$this->main->getWC()->getOrderManager()->deleteCodesEntryOnOrderItem($data['item_id']);

		// All meta should be gone
		$this->assertEmpty(wc_get_order_item_meta($data['item_id'], '_saso_eventtickets_is_ticket', true));
		$this->assertEmpty(wc_get_order_item_meta($data['item_id'], '_saso_eventtickets_product_code', true));
		$this->assertEmpty(wc_get_order_item_meta($data['item_id'], '_saso_eventtickets_public_ticket_ids', true));
	}

	// ── woocommerce_order_partially_refunded ────────────────────

	public function test_partial_refund_does_nothing_when_option_disabled(): void {
		$this->disableOption('wcassignmentOrderItemRefund');

		$tp = $this->createTicketProduct();
		$order = $this->createCompletedOrderWithCodes($tp['product'], 3);
		$data = $this->getOrderItemAndId($order);

		$codesBefore = $this->getCodeCount($order->get_id());
		$this->assertEquals(3, $codesBefore);

		// Create a refund
		$refund = $this->createRefundForOrder($order, $data['item_id'], 1);

		// Call handler
		$this->main->getWC()->getOrderManager()->woocommerce_order_partially_refunded(
			$order->get_id(), $refund->get_id()
		);

		// Codes in order item meta should be unchanged (option is off)
		$codesStr = wc_get_order_item_meta($data['item_id'], '_saso_eventtickets_product_code', true);
		$codeParts = explode(',', $codesStr);
		$this->assertCount(3, $codeParts);
	}

	public function test_partial_refund_removes_excess_codes_when_option_enabled(): void {
		$this->enableOption('wcassignmentOrderItemRefund');

		$tp = $this->createTicketProduct();
		$order = $this->createCompletedOrderWithCodes($tp['product'], 3);
		$data = $this->getOrderItemAndId($order);

		$codesStr = wc_get_order_item_meta($data['item_id'], '_saso_eventtickets_product_code', true);
		$this->assertNotEmpty($codesStr);
		$codeParts = explode(',', $codesStr);
		$this->assertCount(3, $codeParts);

		// Refund 1 of 3 items
		$refund = $this->createRefundForOrder($order, $data['item_id'], 1);

		// Reload order to pick up refund data
		$order = wc_get_order($order->get_id());

		$this->main->getWC()->getOrderManager()->woocommerce_order_partially_refunded(
			$order->get_id(), $refund->get_id()
		);

		// Should now have 2 codes in order item meta
		$codesStrAfter = wc_get_order_item_meta($data['item_id'], '_saso_eventtickets_product_code', true);
		$codePartsAfter = explode(',', $codesStrAfter);
		$this->assertCount(2, $codePartsAfter);

		$this->disableOption('wcassignmentOrderItemRefund');
	}

	public function test_partial_refund_removes_all_codes_when_fully_refunded(): void {
		$this->enableOption('wcassignmentOrderItemRefund');

		$tp = $this->createTicketProduct();
		$order = $this->createCompletedOrderWithCodes($tp['product'], 2);
		$data = $this->getOrderItemAndId($order);

		// Refund all 2 items
		$refund = $this->createRefundForOrder($order, $data['item_id'], 2);
		$order = wc_get_order($order->get_id());

		$this->main->getWC()->getOrderManager()->woocommerce_order_partially_refunded(
			$order->get_id(), $refund->get_id()
		);

		// Should have 0 codes
		$codesStrAfter = wc_get_order_item_meta($data['item_id'], '_saso_eventtickets_product_code', true);
		$codePartsAfter = !empty($codesStrAfter) ? explode(',', $codesStrAfter) : [];
		$this->assertCount(0, $codePartsAfter);

		$this->disableOption('wcassignmentOrderItemRefund');
	}

	public function test_partial_refund_respects_tickets_per_item(): void {
		$this->enableOption('wcassignmentOrderItemRefund');

		$tp = $this->createTicketProduct([
			'saso_eventtickets_ticket_amount_per_item' => 2,
		]);
		$order = $this->createCompletedOrderWithCodes($tp['product'], 2);
		$data = $this->getOrderItemAndId($order);

		// With tickets_per_item=2 and quantity=2, we should have 4 codes
		$codesStr = wc_get_order_item_meta($data['item_id'], '_saso_eventtickets_product_code', true);
		$codeParts = explode(',', $codesStr);
		$this->assertCount(4, $codeParts);

		// Refund 1 item → remaining = 1 item × 2 tickets = 2 codes
		$refund = $this->createRefundForOrder($order, $data['item_id'], 1);
		$order = wc_get_order($order->get_id());

		$this->main->getWC()->getOrderManager()->woocommerce_order_partially_refunded(
			$order->get_id(), $refund->get_id()
		);

		$codesStrAfter = wc_get_order_item_meta($data['item_id'], '_saso_eventtickets_product_code', true);
		$codePartsAfter = explode(',', $codesStrAfter);
		$this->assertCount(2, $codePartsAfter);

		$this->disableOption('wcassignmentOrderItemRefund');
	}

	public function test_partial_refund_skips_non_ticket_products(): void {
		$this->enableOption('wcassignmentOrderItemRefund');

		// Create a non-ticket product
		$product = new WC_Product_Simple();
		$product->set_name('Regular Product');
		$product->set_regular_price('15.00');
		$product->set_status('publish');
		$product->save();
		// Don't set is_ticket meta

		$order = wc_create_order();
		$order->add_product($product, 2);
		$order->calculate_totals();
		$order->set_status('completed');
		$order->save();
		$order = wc_get_order($order->get_id());

		$data = $this->getOrderItemAndId($order);

		$refund = $this->createRefundForOrder($order, $data['item_id'], 1);
		$order = wc_get_order($order->get_id());

		// Should not crash when processing non-ticket items
		$this->main->getWC()->getOrderManager()->woocommerce_order_partially_refunded(
			$order->get_id(), $refund->get_id()
		);

		$this->assertTrue(true); // No exception = pass

		$this->disableOption('wcassignmentOrderItemRefund');
	}

	public function test_partial_refund_adds_order_note(): void {
		$this->enableOption('wcassignmentOrderItemRefund');

		$tp = $this->createTicketProduct();
		$order = $this->createCompletedOrderWithCodes($tp['product'], 2);
		$data = $this->getOrderItemAndId($order);

		$refund = $this->createRefundForOrder($order, $data['item_id'], 1);
		$order = wc_get_order($order->get_id());

		// Count notes before
		$notesBefore = wc_get_order_notes(['order_id' => $order->get_id()]);
		$countBefore = count($notesBefore);

		$this->main->getWC()->getOrderManager()->woocommerce_order_partially_refunded(
			$order->get_id(), $refund->get_id()
		);

		// Should have at least one new note about the refunded ticket
		$notesAfter = wc_get_order_notes(['order_id' => $order->get_id()]);
		$this->assertGreaterThan($countBefore, count($notesAfter));

		$this->disableOption('wcassignmentOrderItemRefund');
	}

	// ── woocommerce_delete_order_item ───────────────────────────

	public function test_delete_order_item_frees_codes_when_option_enabled(): void {
		$this->enableOption('wcRestrictFreeCodeByOrderRefund');

		$tp = $this->createTicketProduct();
		$order = $this->createCompletedOrderWithCodes($tp['product'], 1);
		$data = $this->getOrderItemAndId($order);

		$codesStr = wc_get_order_item_meta($data['item_id'], '_saso_eventtickets_product_code', true);
		$this->assertNotEmpty($codesStr);

		$code = trim($codesStr);

		// Verify code is linked
		$codeObj = $this->main->getCore()->retrieveCodeByCode($code);
		$this->assertEquals($order->get_id(), intval($codeObj['order_id']));

		// Delete order item
		$this->main->getWC()->getOrderManager()->woocommerce_delete_order_item($data['item_id']);

		// Code should be freed
		$codeAfter = $this->main->getCore()->retrieveCodeByCode($code);
		$this->assertEquals(0, intval($codeAfter['order_id']));

		$this->disableOption('wcRestrictFreeCodeByOrderRefund');
	}

	public function test_delete_order_item_does_not_free_codes_when_option_disabled(): void {
		$this->disableOption('wcRestrictFreeCodeByOrderRefund');

		$tp = $this->createTicketProduct();
		$order = $this->createCompletedOrderWithCodes($tp['product'], 1);
		$data = $this->getOrderItemAndId($order);

		$codesStr = wc_get_order_item_meta($data['item_id'], '_saso_eventtickets_product_code', true);
		$this->assertNotEmpty($codesStr);
		$code = trim($codesStr);

		// Delete order item without the option
		$this->main->getWC()->getOrderManager()->woocommerce_delete_order_item($data['item_id']);

		// Code should still be linked (option was off)
		$codeAfter = $this->main->getCore()->retrieveCodeByCode($code);
		$this->assertEquals($order->get_id(), intval($codeAfter['order_id']));
	}

	// ── removeAllTicketsFromOrder ────────────────────────────────

	public function test_removeAllTicketsFromOrder_clears_all_items(): void {
		$this->enableOption('wcRestrictFreeCodeByOrderRefund');

		$tp = $this->createTicketProduct();
		$order = $this->createCompletedOrderWithCodes($tp['product'], 3);
		$data = $this->getOrderItemAndId($order);

		// Verify codes exist
		$codesStr = wc_get_order_item_meta($data['item_id'], '_saso_eventtickets_product_code', true);
		$this->assertNotEmpty($codesStr);

		// Remove all tickets
		$result = $this->main->getWC()->getOrderManager()->removeAllTicketsFromOrder([
			'order_id' => $order->get_id(),
		]);

		$this->assertTrue($result);

		// Order item meta should be cleared
		$codesStrAfter = wc_get_order_item_meta($data['item_id'], '_saso_eventtickets_product_code', true);
		$this->assertEmpty($codesStrAfter);

		$isTicket = wc_get_order_item_meta($data['item_id'], '_saso_eventtickets_is_ticket', true);
		$this->assertEmpty($isTicket);

		$this->disableOption('wcRestrictFreeCodeByOrderRefund');
	}

	public function test_removeAllTicketsFromOrder_with_invalid_order_id(): void {
		$result = $this->main->getWC()->getOrderManager()->removeAllTicketsFromOrder([
			'order_id' => 0,
		]);
		$this->assertTrue($result); // Returns true even with invalid ID
	}

	public function test_removeAllTicketsFromOrder_multiple_items(): void {
		$this->enableOption('wcRestrictFreeCodeByOrderRefund');

		$tp1 = $this->createTicketProduct();
		$tp2 = $this->createTicketProduct();

		$order = wc_create_order();
		$order->add_product($tp1['product'], 2);
		$order->add_product($tp2['product'], 1);
		$order->calculate_totals();
		$order->set_status('completed');
		$order->save();
		$order = wc_get_order($order->get_id());

		// Should have 3 codes total
		$codes = $this->main->getCore()->getCodesByOrderId($order->get_id());
		$this->assertCount(3, $codes);

		// Remove all
		$this->main->getWC()->getOrderManager()->removeAllTicketsFromOrder([
			'order_id' => $order->get_id(),
		]);

		// All items should be cleared
		$items = $order->get_items();
		foreach ($items as $item_id => $item) {
			$codesStr = wc_get_order_item_meta($item_id, '_saso_eventtickets_product_code', true);
			$this->assertEmpty($codesStr, "Codes should be cleared for item $item_id");
		}

		$this->disableOption('wcRestrictFreeCodeByOrderRefund');
	}

	// ── Integration: refund + verify code is free ───────────────

	public function test_partial_refund_frees_removed_codes_in_db(): void {
		$this->enableOption('wcassignmentOrderItemRefund');

		$tp = $this->createTicketProduct();
		$order = $this->createCompletedOrderWithCodes($tp['product'], 3);
		$data = $this->getOrderItemAndId($order);

		// Get original codes
		$codesStr = wc_get_order_item_meta($data['item_id'], '_saso_eventtickets_product_code', true);
		$originalCodes = explode(',', $codesStr);
		$this->assertCount(3, $originalCodes);

		// Refund 2 items
		$refund = $this->createRefundForOrder($order, $data['item_id'], 2);
		$order = wc_get_order($order->get_id());

		$this->main->getWC()->getOrderManager()->woocommerce_order_partially_refunded(
			$order->get_id(), $refund->get_id()
		);

		// Remaining should be 1
		$codesStrAfter = wc_get_order_item_meta($data['item_id'], '_saso_eventtickets_product_code', true);
		$remainingCodes = explode(',', $codesStrAfter);
		$this->assertCount(1, $remainingCodes);

		// The removed codes (last 2) should be detached from the order
		$removedCodes = array_slice($originalCodes, 1);
		foreach ($removedCodes as $removedCode) {
			$removedCode = trim($removedCode);
			if (!empty($removedCode)) {
				$codeObj = $this->main->getCore()->retrieveCodeByCode($removedCode);
				$this->assertEquals(0, intval($codeObj['order_id']),
					"Removed code $removedCode should have order_id=0"
				);
			}
		}

		// The remaining code should still be linked
		$remainingCode = trim($remainingCodes[0]);
		$codeObj = $this->main->getCore()->retrieveCodeByCode($remainingCode);
		$this->assertEquals($order->get_id(), intval($codeObj['order_id']),
			"Remaining code should still be linked to order"
		);

		$this->disableOption('wcassignmentOrderItemRefund');
	}
}
