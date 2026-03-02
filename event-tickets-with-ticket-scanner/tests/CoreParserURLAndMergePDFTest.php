<?php
/**
 * Batch 52 — Core parser, URL helpers, and PDF merge:
 * - parser_search_loop: loop template parsing
 * - getTicketScannerURL: scanner URL generation
 * - getTicketURLComponents: ticket URL parsing
 * - mergePDFs: PDF file merging (file mode)
 */

class CoreParserURLAndMergePDFTest extends WP_UnitTestCase {

	private $main;
	private $core;

	public function set_up(): void {
		parent::set_up();
		$this->main = sasoEventtickets::Instance();
		$this->core = $this->main->getCore();
	}

	// ── parser_search_loop ──────────────────────────────────────

	public function test_parser_search_loop_finds_loop(): void {
		$text = '<p>Before</p>{{LOOP ORDER.items AS item}}<li>{{item.name}}</li>{{LOOPEND}}<p>After</p>';
		$result = $this->core->parser_search_loop($text);

		$this->assertIsArray($result);
		$this->assertEquals('ORDER.items', $result['collection']);
		$this->assertEquals('item', $result['item_var']);
		$this->assertStringContainsString('{{item.name}}', $result['loop_part']);
	}

	public function test_parser_search_loop_returns_positions(): void {
		$text = 'AAA{{LOOP X AS y}}content{{LOOPEND}}BBB';
		$result = $this->core->parser_search_loop($text);

		$this->assertIsArray($result);
		$this->assertEquals(3, $result['pos_start']);
		$this->assertGreaterThan($result['pos_start'], $result['pos_end']);
	}

	public function test_parser_search_loop_no_loop_returns_false(): void {
		$text = '<p>No loop here</p>';
		$result = $this->core->parser_search_loop($text);
		$this->assertFalse($result);
	}

	public function test_parser_search_loop_empty_returns_false(): void {
		$result = $this->core->parser_search_loop('');
		$this->assertFalse($result);
	}

	public function test_parser_search_loop_null_returns_false(): void {
		$result = $this->core->parser_search_loop(null);
		$this->assertFalse($result);
	}

	public function test_parser_search_loop_incomplete_loop_returns_false(): void {
		$text = '{{LOOP ORDER.items AS item}}<li>no end tag</li>';
		$result = $this->core->parser_search_loop($text);
		$this->assertFalse($result);
	}

	public function test_parser_search_loop_with_spaces(): void {
		// parser_search_loop requires {{LOOP (no space before LOOP)
		$text = '{{LOOP PRODUCT.attrs AS attr }}{{attr.value}}{{LOOPEND}}';
		$result = $this->core->parser_search_loop($text);

		$this->assertIsArray($result);
		$this->assertEquals('PRODUCT.attrs', $result['collection']);
		$this->assertEquals('attr', $result['item_var']);
	}

	public function test_parser_search_loop_returns_found_str(): void {
		$text = 'prefix{{LOOP A AS b}}inner{{LOOPEND}}suffix';
		$result = $this->core->parser_search_loop($text);

		$this->assertArrayHasKey('found_str', $result);
		$this->assertStringContainsString('{{LOOP', $result['found_str']);
		$this->assertStringContainsString('{{LOOPEND}}', $result['found_str']);
	}

	// ── getTicketScannerURL ─────────────────────────────────────

	public function test_getTicketScannerURL_contains_ticket_id(): void {
		$url = $this->core->getTicketScannerURL('TESTCODE123');
		$this->assertStringContainsString('TESTCODE123', $url);
	}

	public function test_getTicketScannerURL_contains_scanner_path(): void {
		$url = $this->core->getTicketScannerURL('ABC');
		$this->assertStringContainsString('scanner/', $url);
	}

	public function test_getTicketScannerURL_contains_code_param(): void {
		$url = $this->core->getTicketScannerURL('XYZ');
		$this->assertStringContainsString('code=', $url);
	}

	public function test_getTicketScannerURL_urlencodes_special_chars(): void {
		$url = $this->core->getTicketScannerURL('CODE WITH SPACES');
		$this->assertStringContainsString('CODE+WITH+SPACES', $url);
	}

	public function test_getTicketScannerURL_fires_filter(): void {
		$filtered = false;
		add_filter($this->main->_add_filter_prefix . 'core_getTicketScannerURL', function ($url) use (&$filtered) {
			$filtered = true;
			return $url;
		});

		$this->core->getTicketScannerURL('TEST');
		$this->assertTrue($filtered);
	}

	// ── getTicketURLComponents ──────────────────────────────────

	public function test_getTicketURLComponents_parses_standard_url(): void {
		$base = $this->core->getTicketURLBase();
		$url = $base . 'ID001-42-CODEX';

		$result = $this->core->getTicketURLComponents($url);

		$this->assertIsArray($result);
		$this->assertEquals('ID001', $result['idcode']);
		$this->assertEquals('42', $result['order_id']);
		$this->assertEquals('CODEX', $result['code']);
		$this->assertFalse($result['_isPDFRequest']);
		$this->assertFalse($result['_isICSRequest']);
		$this->assertFalse($result['_isBadgeRequest']);
	}

	public function test_getTicketURLComponents_detects_pdf_request(): void {
		$base = $this->core->getTicketURLBase();
		$url = $base . 'ID001-42-CODEX?pdf';

		$result = $this->core->getTicketURLComponents($url);

		$this->assertTrue($result['_isPDFRequest']);
		$this->assertFalse($result['_isICSRequest']);
	}

	public function test_getTicketURLComponents_detects_ics_request(): void {
		$base = $this->core->getTicketURLBase();
		$url = $base . 'ID001-42-CODEX?ics';

		$result = $this->core->getTicketURLComponents($url);

		$this->assertTrue($result['_isICSRequest']);
		$this->assertFalse($result['_isPDFRequest']);
	}

	public function test_getTicketURLComponents_detects_badge_request(): void {
		$base = $this->core->getTicketURLBase();
		$url = $base . 'ID001-42-CODEX?badge';

		$result = $this->core->getTicketURLComponents($url);

		$this->assertTrue($result['_isBadgeRequest']);
	}

	public function test_getTicketURLComponents_throws_for_invalid(): void {
		$base = $this->core->getTicketURLBase();
		$url = $base . 'ONLYONEPART';

		$this->expectException(Exception::class);
		$this->core->getTicketURLComponents($url);
	}

	public function test_getTicketURLComponents_fires_filter(): void {
		$filtered = false;
		add_filter($this->main->_add_filter_prefix . 'core_getTicketURLComponents', function ($parts) use (&$filtered) {
			$filtered = true;
			return $parts;
		});

		$base = $this->core->getTicketURLBase();
		$this->core->getTicketURLComponents($base . 'A-1-B');
		$this->assertTrue($filtered);
	}

	// ── mergePDFs (file mode) ───────────────────────────────────

	public function test_mergePDFs_creates_merged_file(): void {
		$this->expectOutputRegex('/.*/');
		// Create two simple PDFs first
		$pdf1 = $this->main->getNewPDFObject();
		$pdf1->setFilemode('F');
		$pdf1->setFilepath(get_temp_dir());
		$pdf1->setFilename('merge_test_1.pdf');
		$pdf1->initQR();
		$pdf1->addPart('<h1>Page 1</h1>');
		$pdf1->render();
		$path1 = $pdf1->getFullFilePath();

		$pdf2 = $this->main->getNewPDFObject();
		$pdf2->setFilemode('F');
		$pdf2->setFilepath(get_temp_dir());
		$pdf2->setFilename('merge_test_2.pdf');
		$pdf2->initQR();
		$pdf2->addPart('<h1>Page 2</h1>');
		$pdf2->render();
		$path2 = $pdf2->getFullFilePath();

		$this->assertFileExists($path1);
		$this->assertFileExists($path2);

		$merged = $this->core->mergePDFs(
			[$path1, $path2],
			'merged_output.pdf',
			'F',
			true
		);

		$this->assertNotEmpty($merged);
		$this->assertFileExists($merged);

		$content = file_get_contents($merged);
		$this->assertStringStartsWith('%PDF', $content);

		// Source files should be deleted after merge
		$this->assertFileDoesNotExist($path1);
		$this->assertFileDoesNotExist($path2);

		@unlink($merged);
	}

	public function test_mergePDFs_no_delete_preserves_files(): void {
		$pdf1 = $this->main->getNewPDFObject();
		$pdf1->setFilemode('F');
		$pdf1->setFilepath(get_temp_dir());
		$pdf1->setFilename('merge_nodelete_1.pdf');
		$pdf1->initQR();
		$pdf1->addPart('<h1>Keep Me</h1>');
		$pdf1->render();
		$path1 = $pdf1->getFullFilePath();

		$merged = $this->core->mergePDFs(
			[$path1],
			'merged_keep.pdf',
			'F',
			false
		);

		$this->assertFileExists($merged);
		// Source file should still exist
		$this->assertFileExists($path1);

		@unlink($path1);
		@unlink($merged);
	}

	public function test_mergePDFs_empty_array_returns_null(): void {
		$result = $this->core->mergePDFs([], 'empty.pdf', 'F');
		$this->assertNull($result);
	}
}
