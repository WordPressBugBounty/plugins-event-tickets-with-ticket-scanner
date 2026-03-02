<?php
/**
 * Batch 50 — Admin order/ticket cleanup + PDF generation (file mode):
 * - removeWoocommerceOrderInfoFromCode: clears WC order link from code
 * - removeWoocommerceTicketForCode: removes ticket meta from code
 * - outputPDFTicketsForOrder: generate merged PDF for order (file mode)
 * - generateOnePDFForCodes: generate merged PDF for code list (file mode)
 * - outputPDF: generate single ticket PDF (file mode)
 */

class AdminCodeCleanupAndPDFTest extends WP_UnitTestCase {

	private $main;

	public function set_up(): void {
		parent::set_up();
		$this->main = sasoEventtickets::Instance();

		if (!class_exists('WC_Product_Simple')) {
			$this->markTestSkipped('WooCommerce not available');
		}
	}

	/**
	 * Create a full ticket setup: product, order, code linked to order item.
	 */
	private function createFullTicketSetup(): array {
		$product = new WC_Product_Simple();
		$product->set_name('Cleanup Test ' . uniqid());
		$product->set_regular_price('10.00');
		$product->set_status('publish');
		$product->save();
		$pid = $product->get_id();

		update_post_meta($pid, 'saso_eventtickets_is_ticket', 'yes');
		update_post_meta($pid, 'saso_eventtickets_ticket_start_date', '2026-12-25');

		$listId = $this->main->getDB()->insert('lists', [
			'name' => 'Cleanup List ' . uniqid(),
			'aktiv' => 1,
			'meta' => '{}',
		]);

		$order = wc_create_order();
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

		$codeStr = 'CLEAN' . strtoupper(uniqid());
		$this->main->getDB()->insert('codes', [
			'list_id' => $listId,
			'code' => $codeStr,
			'aktiv' => 1,
			'cvv' => '',
			'order_id' => $order->get_id(),
			'user_id' => 0,
			'meta' => $metaJson,
		]);

		// Store code reference in order item meta
		$orderItem = $items[$firstItemId];
		$orderItem->update_meta_data('_saso_eventtickets_product_code', $codeStr);
		$orderItem->update_meta_data('_saso_eventtickets_is_ticket', 'yes');
		$orderItem->save();

		$codeObj = $this->main->getCore()->retrieveCodeByCode($codeStr);

		return [
			'product_id' => $pid,
			'list_id' => $listId,
			'order' => $order,
			'order_id' => $order->get_id(),
			'item_id' => $firstItemId,
			'code' => $codeStr,
			'codeObj' => $codeObj,
		];
	}

	// ── removeWoocommerceOrderInfoFromCode ───────────────────────

	public function test_removeOrderInfoFromCode_clears_order_id(): void {
		$setup = $this->createFullTicketSetup();

		$result = $this->main->getAdmin()->removeWoocommerceOrderInfoFromCode([
			'code' => $setup['code'],
		]);

		$this->assertIsArray($result);

		// Reload and check order_id was reset
		$reloaded = $this->main->getCore()->retrieveCodeByCode($setup['code']);
		$this->assertEquals(0, intval($reloaded['order_id']));
	}

	public function test_removeOrderInfoFromCode_clears_wc_meta(): void {
		$setup = $this->createFullTicketSetup();

		$this->main->getAdmin()->removeWoocommerceOrderInfoFromCode([
			'code' => $setup['code'],
		]);

		$reloaded = $this->main->getCore()->retrieveCodeByCode($setup['code']);
		$metaObj = $this->main->getCore()->encodeMetaValuesAndFillObject($reloaded['meta'], $reloaded);
		$this->assertEquals(0, intval($metaObj['woocommerce']['order_id']));
		$this->assertEquals(0, intval($metaObj['woocommerce']['product_id']));
	}

	public function test_removeOrderInfoFromCode_throws_without_code(): void {
		$this->expectException(Exception::class);
		$this->expectExceptionMessageMatches('/#9611/');
		$this->main->getAdmin()->removeWoocommerceOrderInfoFromCode([]);
	}

	public function test_removeOrderInfoFromCode_throws_for_nonexistent(): void {
		$this->expectException(Exception::class);
		$this->main->getAdmin()->removeWoocommerceOrderInfoFromCode([
			'code' => 'NONEXISTENT_' . uniqid(),
		]);
	}

	// ── removeWoocommerceTicketForCode ───────────────────────────

	public function test_removeTicketForCode_clears_wc_ticket(): void {
		$setup = $this->createFullTicketSetup();

		$result = $this->main->getAdmin()->removeWoocommerceTicketForCode([
			'code' => $setup['code'],
		]);

		$this->assertIsArray($result);

		$reloaded = $this->main->getCore()->retrieveCodeByCode($setup['code']);
		$metaObj = $this->main->getCore()->encodeMetaValuesAndFillObject($reloaded['meta'], $reloaded);
		// wc_ticket should be reset to defaults (empty redeemed_date, etc.)
		$this->assertEmpty($metaObj['wc_ticket']['redeemed_date']);
	}

	public function test_removeTicketForCode_preserves_idcode(): void {
		$setup = $this->createFullTicketSetup();

		// Set an idcode first
		$codeObj = $setup['codeObj'];
		$metaObj = $this->main->getCore()->encodeMetaValuesAndFillObject($codeObj['meta'], $codeObj);
		$metaObj['wc_ticket']['idcode'] = 'TK-12345';
		$this->main->getCore()->saveMetaObject($codeObj, $metaObj);

		$this->main->getAdmin()->removeWoocommerceTicketForCode([
			'code' => $setup['code'],
		]);

		$reloaded = $this->main->getCore()->retrieveCodeByCode($setup['code']);
		$metaObj2 = $this->main->getCore()->encodeMetaValuesAndFillObject($reloaded['meta'], $reloaded);
		// idcode should be preserved
		$this->assertEquals('TK-12345', $metaObj2['wc_ticket']['idcode']);
	}

	public function test_removeTicketForCode_throws_without_code(): void {
		$this->expectException(Exception::class);
		$this->expectExceptionMessageMatches('/#9625/');
		$this->main->getAdmin()->removeWoocommerceTicketForCode([]);
	}

	// ── PDF generation (file mode) ──────────────────────────────

	public function test_outputPDF_file_mode_creates_file(): void {
		$setup = $this->createFullTicketSetup();
		$ticket = $this->main->getTicketHandler();

		$ticket->setCodeObj($setup['codeObj']);
		$ticket->setOrder($setup['order']);

		$filepath = $ticket->outputPDF('F');
		$this->assertNotEmpty($filepath);
		$this->assertFileExists($filepath);

		// Cleanup
		@unlink($filepath);
	}

	public function test_outputPDF_file_is_valid_pdf(): void {
		$setup = $this->createFullTicketSetup();
		$ticket = $this->main->getTicketHandler();

		$ticket->setCodeObj($setup['codeObj']);
		$ticket->setOrder($setup['order']);

		$filepath = $ticket->outputPDF('F');
		$content = file_get_contents($filepath);
		$this->assertStringStartsWith('%PDF', $content);

		@unlink($filepath);
	}

	public function test_outputPDFTicketsForOrder_file_mode(): void {
		$setup = $this->createFullTicketSetup();
		$ticket = $this->main->getTicketHandler();

		// outputPDFTicketsForOrder uses ob_start internally, capture any stray output
		$this->expectOutputRegex('/.*/');

		$filepath = $ticket->outputPDFTicketsForOrder($setup['order'], 'F');
		$this->assertNotEmpty($filepath);
		$this->assertFileExists($filepath);

		$content = file_get_contents($filepath);
		$this->assertStringStartsWith('%PDF', $content);

		@unlink($filepath);
	}

	public function test_generateOnePDFForCodes_file_mode(): void {
		$setup = $this->createFullTicketSetup();
		$ticket = $this->main->getTicketHandler();

		$filepath = $ticket->generateOnePDFForCodes(
			[$setup['code']],
			'test_merged.pdf',
			'F'
		);

		$this->assertNotEmpty($filepath);
		$this->assertFileExists($filepath);

		$content = file_get_contents($filepath);
		$this->assertStringStartsWith('%PDF', $content);

		@unlink($filepath);
	}

	public function test_generateOnePDFForCodes_empty_array(): void {
		$ticket = $this->main->getTicketHandler();
		$result = $ticket->generateOnePDFForCodes([], null, 'F');
		$this->assertNull($result);
	}

	public function test_generateOnePDFForCodes_ignores_invalid_codes(): void {
		$setup = $this->createFullTicketSetup();
		$ticket = $this->main->getTicketHandler();

		// Mix valid and invalid codes - should skip invalid
		$filepath = $ticket->generateOnePDFForCodes(
			['NONEXISTENT123', $setup['code']],
			'test_mixed.pdf',
			'F'
		);

		$this->assertNotEmpty($filepath);
		$this->assertFileExists($filepath);

		@unlink($filepath);
	}
}
