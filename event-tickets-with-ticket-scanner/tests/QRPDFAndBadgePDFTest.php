<?php
/**
 * Batch 53 — QR PDF rendering and Badge PDF filepath:
 * - sasoEventtickets_TicketQR::renderPDF: QR code as PDF (file mode)
 * - sasoEventtickets_TicketBadge::getPDFTicketBadgeFilepath: badge PDF generation
 * - sasoEventtickets_Ticket::generateOneBadgePDFForCodes: batch badge PDF
 */

class QRPDFAndBadgePDFTest extends WP_UnitTestCase {

	private $main;

	public function set_up(): void {
		parent::set_up();
		$this->main = sasoEventtickets::Instance();

		if (!class_exists('WC_Product_Simple')) {
			$this->markTestSkipped('WooCommerce not available');
		}
	}

	private function createFullTicketSetup(): array {
		$product = new WC_Product_Simple();
		$product->set_name('QRBadge Test ' . uniqid());
		$product->set_regular_price('10.00');
		$product->set_status('publish');
		$product->save();
		$pid = $product->get_id();

		update_post_meta($pid, 'saso_eventtickets_is_ticket', 'yes');
		update_post_meta($pid, 'saso_eventtickets_ticket_start_date', '2026-12-25');

		$listId = $this->main->getDB()->insert('lists', [
			'name' => 'QRBadge List ' . uniqid(),
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

		$codeStr = 'QRBADGE' . strtoupper(uniqid());
		$this->main->getDB()->insert('codes', [
			'list_id' => $listId,
			'code' => $codeStr,
			'aktiv' => 1,
			'cvv' => '',
			'order_id' => $order->get_id(),
			'user_id' => 0,
			'meta' => $metaJson,
		]);

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

	// ── TicketQR::renderPDF ─────────────────────────────────────

	public function test_ticketQR_renderPDF_creates_file(): void {
		$this->main->loadOnce('sasoEventtickets_TicketQR');
		$qr = sasoEventtickets_TicketQR::Instance();
		$qr->setFilepath(get_temp_dir());
		$qr->setWidth(50);
		$qr->setHeight(50);

		$filepath = $qr->renderPDF('TESTQR_' . uniqid(), 'F');

		$this->assertNotEmpty($filepath);
		$this->assertFileExists($filepath);

		$content = file_get_contents($filepath);
		$this->assertStringStartsWith('%PDF', $content);

		@unlink($filepath);
	}

	public function test_ticketQR_renderPDF_default_size(): void {
		$this->main->loadOnce('sasoEventtickets_TicketQR');
		$qr = new sasoEventtickets_TicketQR();
		$qr->setFilepath(get_temp_dir());

		$filepath = $qr->renderPDF('QRDEF_' . uniqid(), 'F');

		$this->assertNotEmpty($filepath);
		$this->assertFileExists($filepath);

		@unlink($filepath);
	}

	public function test_ticketQR_renderPDF_custom_size(): void {
		$this->main->loadOnce('sasoEventtickets_TicketQR');
		$qr = new sasoEventtickets_TicketQR();
		$qr->setFilepath(get_temp_dir());
		$qr->setWidth(120);
		$qr->setHeight(120);

		$filepath = $qr->renderPDF('QRCUSTOM_' . uniqid(), 'F');

		$this->assertNotEmpty($filepath);
		$this->assertFileExists($filepath);
		$this->assertGreaterThan(0, filesize($filepath));

		@unlink($filepath);
	}

	// ── TicketBadge::getPDFTicketBadgeFilepath ──────────────────

	public function test_badge_getPDFTicketBadgeFilepath_creates_file(): void {
		$setup = $this->createFullTicketSetup();

		$badgeHandler = $this->main->getTicketBadgeHandler();
		$ticket = $this->main->getTicketHandler();
		$ticket->setCodeObj($setup['codeObj']);
		$ticket->setOrder($setup['order']);

		$filepath = $badgeHandler->getPDFTicketBadgeFilepath(
			$setup['codeObj'],
			get_temp_dir()
		);

		$this->assertNotEmpty($filepath);
		$this->assertFileExists($filepath);

		$content = file_get_contents($filepath);
		$this->assertStringStartsWith('%PDF', $content);

		@unlink($filepath);
	}

	public function test_badge_getPDFTicketBadgeFilepath_filename_contains_order_id(): void {
		$setup = $this->createFullTicketSetup();

		$badgeHandler = $this->main->getTicketBadgeHandler();
		$ticket = $this->main->getTicketHandler();
		$ticket->setCodeObj($setup['codeObj']);
		$ticket->setOrder($setup['order']);

		$filepath = $badgeHandler->getPDFTicketBadgeFilepath(
			$setup['codeObj'],
			get_temp_dir()
		);

		$this->assertStringContainsString('ticketbadge_', $filepath);
		$this->assertStringContainsString((string) $setup['order_id'], $filepath);

		@unlink($filepath);
	}

	// ── generateOneBadgePDFForCodes ─────────────────────────────

	public function test_generateOneBadgePDFForCodes_creates_file(): void {
		$this->expectOutputRegex('/.*/');
		$setup = $this->createFullTicketSetup();
		$ticket = $this->main->getTicketHandler();

		$filepath = $ticket->generateOneBadgePDFForCodes(
			[$setup['code']],
			'test_badges.pdf',
			'F'
		);

		$this->assertNotEmpty($filepath);
		$this->assertFileExists($filepath);

		$content = file_get_contents($filepath);
		$this->assertStringStartsWith('%PDF', $content);

		@unlink($filepath);
	}

	public function test_generateOneBadgePDFForCodes_empty_array(): void {
		$ticket = $this->main->getTicketHandler();
		$result = $ticket->generateOneBadgePDFForCodes([], null, 'F');
		$this->assertNull($result);
	}

	public function test_generateOneBadgePDFForCodes_skips_invalid_codes(): void {
		$setup = $this->createFullTicketSetup();
		$ticket = $this->main->getTicketHandler();

		$filepath = $ticket->generateOneBadgePDFForCodes(
			['NONEXISTENT_' . uniqid(), $setup['code']],
			'test_badges_mixed.pdf',
			'F'
		);

		$this->assertNotEmpty($filepath);
		$this->assertFileExists($filepath);

		@unlink($filepath);
	}

	public function test_generateOneBadgePDFForCodes_default_filename(): void {
		$setup = $this->createFullTicketSetup();
		$ticket = $this->main->getTicketHandler();

		$filepath = $ticket->generateOneBadgePDFForCodes(
			[$setup['code']],
			null,
			'F'
		);

		$this->assertNotEmpty($filepath);
		$this->assertStringContainsString('ticketsbadges_', $filepath);

		@unlink($filepath);
	}
}
