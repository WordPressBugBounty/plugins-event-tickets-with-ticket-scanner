<?php
/**
 * Tests for Core ticket URL methods:
 * getTicketURLBase, getTicketURLPath, getTicketURLComponents,
 * getTicketId, getTicketURL.
 */

class CoreTicketURLComponentsTest extends WP_UnitTestCase {

    private $main;

    public function set_up(): void {
        parent::set_up();
        $this->main = sasoEventtickets::Instance();
    }

    // ── getTicketURLBase ───────────────────────────────────────────

    public function test_getTicketURLBase_returns_string(): void {
        $result = $this->main->getCore()->getTicketURLBase();
        $this->assertIsString($result);
        $this->assertNotEmpty($result);
    }

    public function test_getTicketURLBase_ends_with_slash(): void {
        $result = $this->main->getCore()->getTicketURLBase();
        $this->assertStringEndsWith('/', $result);
    }

    public function test_getTicketURLBase_default_path(): void {
        $result = $this->main->getCore()->getTicketURLBase(true);
        $this->assertIsString($result);
        $this->assertStringEndsWith('/', $result);
    }

    // ── getTicketURLPath ───────────────────────────────────────────

    public function test_getTicketURLPath_returns_string(): void {
        $result = $this->main->getCore()->getTicketURLPath();
        $this->assertIsString($result);
        $this->assertNotEmpty($result);
    }

    public function test_getTicketURLPath_default_path(): void {
        $result = $this->main->getCore()->getTicketURLPath(true);
        $this->assertIsString($result);
    }

    // ── getTicketId ────────────────────────────────────────────────

    public function test_getTicketId_empty_for_incomplete_codeObj(): void {
        $codeObj = ['id' => 1];
        $metaObj = [];
        $result = $this->main->getCore()->getTicketId($codeObj, $metaObj);
        $this->assertEquals('', $result);
    }

    public function test_getTicketId_with_valid_data(): void {
        $codeObj = ['code' => 'ABC123', 'order_id' => 42];
        $metaObj = ['wc_ticket' => ['idcode' => 'XYZID']];
        $result = $this->main->getCore()->getTicketId($codeObj, $metaObj);
        $this->assertStringContainsString('XYZID', $result);
        $this->assertStringContainsString('42', $result);
        $this->assertStringContainsString('ABC123', $result);
    }

    // ── getTicketURL ───────────────────────────────────────────────

    public function test_getTicketURL_contains_base(): void {
        $codeObj = ['code' => 'DEF456', 'order_id' => 99];
        $metaObj = ['wc_ticket' => ['idcode' => 'URLID']];
        $result = $this->main->getCore()->getTicketURL($codeObj, $metaObj);
        $this->assertIsString($result);
        $this->assertNotEmpty($result);
    }

    public function test_getTicketURL_contains_code(): void {
        $codeObj = ['code' => 'URLTEST', 'order_id' => 55];
        $metaObj = ['wc_ticket' => ['idcode' => 'TESTID']];
        $result = $this->main->getCore()->getTicketURL($codeObj, $metaObj);
        $this->assertStringContainsString('URLTEST', $result);
    }

    // ── getTicketURLComponents ──────────────────────────────────────

    public function test_getTicketURLComponents_returns_array(): void {
        $base = $this->main->getCore()->getTicketURLBase();
        $url = $base . 'TESTID-42-ABC123';
        $result = $this->main->getCore()->getTicketURLComponents($url);
        $this->assertIsArray($result);
    }
}
