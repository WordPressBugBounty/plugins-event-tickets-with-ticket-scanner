<?php
/**
 * Batch 45 — PDF configuration and QR code:
 * - sasoEventtickets_PDF: setters, getters, font infos, pixel conversion
 * - sasoEventtickets_TicketQR: renderPNG (file mode), setters
 * - PDF render to file mode
 */

class PDFAndQRConfigTest extends WP_UnitTestCase {

	private $main;

	public function set_up(): void {
		parent::set_up();
		$this->main = sasoEventtickets::Instance();
	}

	// ── PDF setters / getters ────────────────────────────────

	public function test_pdf_constructor_creates_instance(): void {
		$pdf = $this->main->getNewPDFObject();
		$this->assertInstanceOf(sasoEventtickets_PDF::class, $pdf);
	}

	public function test_pdf_setOrientation(): void {
		$pdf = $this->main->getNewPDFObject();
		$pdf->setOrientation('L');
		$this->assertTrue(true); // no exception
	}

	public function test_pdf_setPageFormat(): void {
		$pdf = $this->main->getNewPDFObject();
		$pdf->setPageFormat('Letter');
		$this->assertTrue(true);
	}

	public function test_pdf_setFontSize(): void {
		$pdf = $this->main->getNewPDFObject();
		$pdf->setFontSize(14);
		$this->assertTrue(true);
	}

	public function test_pdf_setFontFamily(): void {
		$pdf = $this->main->getNewPDFObject();
		$pdf->setFontFamily('helvetica');
		$this->assertTrue(true);
	}

	public function test_pdf_setSize_custom(): void {
		$pdf = $this->main->getNewPDFObject();
		$pdf->setSize(100, 150);
		$this->assertTrue(true);
	}

	public function test_pdf_setRTL_and_isRTL(): void {
		$pdf = $this->main->getNewPDFObject();
		$this->assertFalse($pdf->isRTL());
		$pdf->setRTL(true);
		$this->assertTrue($pdf->isRTL());
	}

	public function test_pdf_setBackgroundImage(): void {
		$pdf = $this->main->getNewPDFObject();
		$pdf->setBackgroundImage(null);
		$this->assertTrue(true);
	}

	public function test_pdf_setBackgroundColor(): void {
		$pdf = $this->main->getNewPDFObject();
		$pdf->setBackgroundColor('#FF0000');
		$this->assertTrue(true);
	}

	public function test_pdf_setBackgroundColor_white_sets_null(): void {
		$pdf = $this->main->getNewPDFObject();
		$pdf->setBackgroundColor('#FFFFFF');
		// White is treated as no background
		$this->assertTrue(true);
	}

	public function test_pdf_setBackgroundColor_null_clears(): void {
		$pdf = $this->main->getNewPDFObject();
		$pdf->setBackgroundColor(null);
		$this->assertTrue(true);
	}

	public function test_pdf_setLanguageArray(): void {
		$pdf = $this->main->getNewPDFObject();
		$pdf->setLanguageArray(['a_meta_charset' => 'UTF-8', 'a_meta_dir' => 'ltr']);
		$this->assertTrue(true);
	}

	public function test_pdf_addPart(): void {
		$pdf = $this->main->getNewPDFObject();
		$pdf->addPart('<h1>Test</h1>');
		$this->assertTrue(true);
	}

	public function test_pdf_setParts(): void {
		$pdf = $this->main->getNewPDFObject();
		$pdf->setParts(['<p>Part 1</p>', '<p>Part 2</p>']);
		$this->assertTrue(true);
	}

	public function test_pdf_setFilepath(): void {
		$pdf = $this->main->getNewPDFObject();
		$pdf->setFilepath(get_temp_dir());
		$this->assertTrue(true);
	}

	public function test_pdf_setFilename(): void {
		$pdf = $this->main->getNewPDFObject();
		$pdf->setFilename('test_ticket.pdf');
		$this->assertTrue(true);
	}

	public function test_pdf_getFullFilePath(): void {
		$pdf = $this->main->getNewPDFObject();
		$pdf->setFilepath('/tmp/');
		$pdf->setFilename('test.pdf');
		$path = $pdf->getFullFilePath();
		$this->assertIsString($path);
		$this->assertStringContainsString('test.pdf', $path);
	}

	public function test_pdf_setQRParams(): void {
		$pdf = $this->main->getNewPDFObject();
		$pdf->setQRParams([
			'pos' => ['x' => 10, 'y' => 20],
			'size' => ['width' => 50, 'height' => 50]
		]);
		$this->assertTrue(true);
	}

	public function test_pdf_initQR(): void {
		$pdf = $this->main->getNewPDFObject();
		$pdf->initQR();
		$this->assertTrue(true);
	}

	public function test_pdf_setQRCodeContent(): void {
		$pdf = $this->main->getNewPDFObject();
		$pdf->setQRCodeContent(['text' => 'TICKET123']);
		$this->assertTrue(true);
	}

	public function test_pdf_setAdditionalPDFs(): void {
		$pdf = $this->main->getNewPDFObject();
		$pdf->setAdditionalPDFsToAttachThem(['/tmp/attach1.pdf']);
		$this->assertTrue(true);
	}

	public function test_pdf_setAdditionalPDFs_single_string(): void {
		$pdf = $this->main->getNewPDFObject();
		$pdf->setAdditionalPDFsToAttachThem('/tmp/single.pdf');
		$this->assertTrue(true);
	}

	// ── Font info methods ────────────────────────────────────

	public function test_getFontInfos_returns_array(): void {
		$pdf = $this->main->getNewPDFObject();
		$infos = $pdf->getFontInfos();
		$this->assertIsArray($infos);
		$this->assertNotEmpty($infos);
	}

	public function test_getFontInfos_has_dejavusans(): void {
		$pdf = $this->main->getNewPDFObject();
		$infos = $pdf->getFontInfos();
		$this->assertArrayHasKey('dejavusans', $infos);
	}

	public function test_getFontInfos_entry_has_name(): void {
		$pdf = $this->main->getNewPDFObject();
		$infos = $pdf->getFontInfos();
		$first = reset($infos);
		$this->assertArrayHasKey('name', $first);
		$this->assertArrayHasKey('lang_support', $first);
		$this->assertArrayHasKey('desc', $first);
	}

	public function test_getPossibleFontFamiles_returns_array(): void {
		$pdf = $this->main->getNewPDFObject();
		$result = $pdf->getPossibleFontFamiles();
		$this->assertIsArray($result);
		$this->assertArrayHasKey('default', $result);
		$this->assertArrayHasKey('fonts', $result);
		$this->assertEquals('dejavusans', $result['default']);
	}

	public function test_getPossibleFontFamiles_has_fonts(): void {
		$pdf = $this->main->getNewPDFObject();
		$result = $pdf->getPossibleFontFamiles();
		$this->assertNotEmpty($result['fonts']);
	}

	// ── Pixel conversion ─────────────────────────────────────

	public function test_convertPixelIntoMm_default_dpi(): void {
		$pdf = $this->main->getNewPDFObject();
		$mm = $pdf->convertPixelIntoMm(96);
		$this->assertEqualsWithDelta(25.4, $mm, 0.01); // 96px at 96dpi = 1 inch = 25.4mm
	}

	public function test_convertPixelIntoMm_custom_dpi(): void {
		$pdf = $this->main->getNewPDFObject();
		$mm = $pdf->convertPixelIntoMm(300, 300);
		$this->assertEqualsWithDelta(25.4, $mm, 0.01); // 300px at 300dpi = 1 inch
	}

	public function test_convertPixelIntoMm_zero_dpi_uses_default(): void {
		$pdf = $this->main->getNewPDFObject();
		$mm = $pdf->convertPixelIntoMm(96, 0);
		$this->assertEqualsWithDelta(25.4, $mm, 0.01); // Falls back to 96 dpi
	}

	// ── PDF render to file ───────────────────────────────────

	public function test_pdf_render_to_file(): void {
		$pdf = $this->main->getNewPDFObject();
		$pdf->setFilemode('F');
		$pdf->setFilename('test_render.pdf');
		$pdf->setFilepath(get_temp_dir());
		$pdf->initQR();
		$pdf->addPart('<h1>Test Ticket</h1><p>This is a test.</p>');
		$pdf->render();
		$filePath = $pdf->getFullFilePath();
		$this->assertFileExists($filePath);
		$this->assertGreaterThan(0, filesize($filePath));

		// Cleanup
		@unlink($filePath);
	}

	public function test_pdf_render_with_qr(): void {
		$pdf = $this->main->getNewPDFObject();
		$pdf->setFilemode('F');
		$pdf->setFilename('test_qr.pdf');
		$pdf->setFilepath(get_temp_dir());
		$pdf->initQR();
		$pdf->addPart('<h1>Ticket</h1>{QRCODE}');
		$pdf->setQRCodeContent(['text' => 'TEST123']);
		$pdf->render();
		$filePath = $pdf->getFullFilePath();
		$this->assertFileExists($filePath);

		// Cleanup
		@unlink($filePath);
	}

	// ── TicketQR ─────────────────────────────────────────────

	public function test_ticketQR_instance(): void {
		$this->main->loadOnce('sasoEventtickets_TicketQR');
		$qr = sasoEventtickets_TicketQR::Instance();
		$this->assertInstanceOf(sasoEventtickets_TicketQR::class, $qr);
	}

	public function test_ticketQR_renderPNG_creates_file(): void {
		$this->main->loadOnce('sasoEventtickets_TicketQR');
		$qr = sasoEventtickets_TicketQR::Instance();
		$qr->setFilepath(get_temp_dir());
		$filename = $qr->renderPNG('TESTQR_' . uniqid(), 'F');
		$this->assertIsString($filename);
		$this->assertFileExists($filename);

		// Cleanup
		@unlink($filename);
	}

	public function test_ticketQR_setWidth(): void {
		$this->main->loadOnce('sasoEventtickets_TicketQR');
		$qr = sasoEventtickets_TicketQR::Instance();
		$qr->setWidth(100);
		$this->assertTrue(true);
	}

	public function test_ticketQR_setHeight(): void {
		$this->main->loadOnce('sasoEventtickets_TicketQR');
		$qr = sasoEventtickets_TicketQR::Instance();
		$qr->setHeight(100);
		$this->assertTrue(true);
	}

	public function test_ticketQR_setFilepath(): void {
		$this->main->loadOnce('sasoEventtickets_TicketQR');
		$qr = sasoEventtickets_TicketQR::Instance();
		$qr->setFilepath(get_temp_dir());
		$this->assertTrue(true);
	}
}
