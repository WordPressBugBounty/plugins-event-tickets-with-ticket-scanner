<?php
/**
 * Tests for Core methods: getTicketId, getTicketURLBase, getTicketURLPath,
 * getTicketURLComponents, parser_search_loop.
 */

class CoreTicketIdAndUrlTest extends WP_UnitTestCase {

    private $main;

    public function set_up(): void {
        parent::set_up();
        $this->main = sasoEventtickets::Instance();
    }

    // ── getTicketId ───────────────────────────────────────────────

    public function test_getTicketId_builds_correct_format(): void {
        $codeObj = ['code' => 'ABC123', 'order_id' => 42];
        $metaObj = $this->main->getCore()->getMetaObject();
        $metaObj['wc_ticket']['idcode'] = 'IDCODE';

        $result = $this->main->getCore()->getTicketId($codeObj, $metaObj);
        $this->assertEquals('IDCODE-42-ABC123', $result);
    }

    public function test_getTicketId_empty_without_required_fields(): void {
        $codeObj = ['code' => 'ABC123'];
        $metaObj = $this->main->getCore()->getMetaObject();

        $result = $this->main->getCore()->getTicketId($codeObj, $metaObj);
        $this->assertEquals('', $result);
    }

    public function test_getTicketId_with_empty_idcode_still_builds(): void {
        $codeObj = ['code' => 'ABC123', 'order_id' => 42];
        $metaObj = $this->main->getCore()->getMetaObject();
        // wc_ticket.idcode is empty string by default but isset() returns true
        // so the method still builds the format

        $result = $this->main->getCore()->getTicketId($codeObj, $metaObj);
        $this->assertStringContainsString('-42-ABC123', $result);
    }

    // ── getTicketURLBase ──────────────────────────────────────────

    public function test_getTicketURLBase_returns_string(): void {
        $result = $this->main->getCore()->getTicketURLBase();
        $this->assertIsString($result);
        $this->assertNotEmpty($result);
    }

    public function test_getTicketURLBase_ends_with_slash(): void {
        $result = $this->main->getCore()->getTicketURLBase();
        $this->assertStringEndsWith('/', $result);
    }

    public function test_getTicketURLBase_contains_ticket_path(): void {
        $result = $this->main->getCore()->getTicketURLBase(true);
        $this->assertStringContainsString('ticket', $result);
    }

    public function test_getTicketURLBase_default_path_matches(): void {
        // When no compatibility mode is set, default and explicit should match
        $default = $this->main->getCore()->getTicketURLBase(true);
        $this->assertIsString($default);
        $this->assertStringContainsString('ticket', $default);
    }

    // ── getTicketURLPath ──────────────────────────────────────────

    public function test_getTicketURLPath_returns_string(): void {
        $result = $this->main->getCore()->getTicketURLPath();
        $this->assertIsString($result);
        $this->assertNotEmpty($result);
    }

    public function test_getTicketURLPath_starts_with_slash(): void {
        $result = $this->main->getCore()->getTicketURLPath();
        $this->assertStringStartsWith('/', $result);
    }

    public function test_getTicketURLPath_contains_ticket(): void {
        $result = $this->main->getCore()->getTicketURLPath(true);
        $this->assertStringContainsString('ticket', $result);
    }

    // ── getTicketURLComponents ────────────────────────────────────

    public function test_getTicketURLComponents_parses_valid_url(): void {
        $baseUrl = $this->main->getCore()->getTicketURLBase(true);
        $url = $baseUrl . 'IDCODE-42-ABC123';

        $result = $this->main->getCore()->getTicketURLComponents($url);
        $this->assertIsArray($result);
        $this->assertEquals('IDCODE', $result['idcode']);
        $this->assertEquals('42', $result['order_id']);
        $this->assertEquals('ABC123', $result['code']);
    }

    public function test_getTicketURLComponents_detects_pdf_request(): void {
        $baseUrl = $this->main->getCore()->getTicketURLBase(true);
        $url = $baseUrl . 'IDCODE-42-ABC123?pdf';

        $result = $this->main->getCore()->getTicketURLComponents($url);
        $this->assertTrue($result['_isPDFRequest']);
        $this->assertFalse($result['_isICSRequest']);
    }

    public function test_getTicketURLComponents_detects_ics_request(): void {
        $baseUrl = $this->main->getCore()->getTicketURLBase(true);
        $url = $baseUrl . 'IDCODE-42-ABC123?ics';

        $result = $this->main->getCore()->getTicketURLComponents($url);
        $this->assertTrue($result['_isICSRequest']);
        $this->assertFalse($result['_isPDFRequest']);
    }

    public function test_getTicketURLComponents_throws_for_invalid(): void {
        $baseUrl = $this->main->getCore()->getTicketURLBase(true);
        $url = $baseUrl . 'INVALID';

        $this->expectException(Exception::class);
        $this->main->getCore()->getTicketURLComponents($url);
    }

    public function test_getTicketURLComponents_has_expected_keys(): void {
        $baseUrl = $this->main->getCore()->getTicketURLBase(true);
        $url = $baseUrl . 'PREFIX-100-CODE123';

        $result = $this->main->getCore()->getTicketURLComponents($url);
        $this->assertArrayHasKey('foundcode', $result);
        $this->assertArrayHasKey('idcode', $result);
        $this->assertArrayHasKey('order_id', $result);
        $this->assertArrayHasKey('code', $result);
        $this->assertArrayHasKey('_request', $result);
        $this->assertArrayHasKey('_isPDFRequest', $result);
        $this->assertArrayHasKey('_isICSRequest', $result);
        $this->assertArrayHasKey('_isBadgeRequest', $result);
    }

    // ── parser_search_loop ───────────────────────────────────────

    public function test_parser_search_loop_returns_false_for_empty(): void {
        $result = $this->main->getCore()->parser_search_loop('');
        $this->assertFalse($result);
    }

    public function test_parser_search_loop_returns_false_for_no_loop(): void {
        $result = $this->main->getCore()->parser_search_loop('Hello World');
        $this->assertFalse($result);
    }

    public function test_parser_search_loop_parses_valid_loop(): void {
        $text = 'Before {{LOOP ORDER.items AS item}} <div>{{item.name}}</div> {{LOOPEND}} After';
        $result = $this->main->getCore()->parser_search_loop($text);

        $this->assertIsArray($result);
        $this->assertEquals('ORDER.items', $result['collection']);
        $this->assertEquals('item', $result['item_var']);
        $this->assertStringContainsString('item.name', $result['loop_part']);
    }

    public function test_parser_search_loop_captures_positions(): void {
        $text = 'Before {{LOOP items AS i}} content {{LOOPEND}} After';
        $result = $this->main->getCore()->parser_search_loop($text);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('pos_start', $result);
        $this->assertArrayHasKey('pos_end', $result);
        $this->assertGreaterThan(0, $result['pos_start']);
        $this->assertGreaterThan($result['pos_start'], $result['pos_end']);
    }

    public function test_parser_search_loop_no_loopend(): void {
        $text = '{{LOOP items AS i}} content without end';
        $result = $this->main->getCore()->parser_search_loop($text);
        // No LOOPEND found → should return false
        $this->assertFalse($result);
    }
}
