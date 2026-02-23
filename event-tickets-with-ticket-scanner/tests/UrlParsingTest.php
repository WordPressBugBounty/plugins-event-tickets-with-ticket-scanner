<?php
/**
 * Tests for URL parsing and parameter replacement.
 */

class UrlParsingTest extends WP_UnitTestCase {

    private $main;

    public function set_up(): void {
        parent::set_up();
        $this->main = sasoEventtickets::Instance();
    }

    // ── getTicketURLComponents ────────────────────────────────────

    public function test_getTicketURLComponents_standard_url(): void {
        $core = $this->main->getCore();
        $url = '/ticket/ABC-123-DEF';

        $result = $core->getTicketURLComponents($url);
        $this->assertIsArray($result);
        $this->assertEquals('ABC-123-DEF', $result['foundcode']);
        $this->assertEquals('ABC', $result['idcode']);
        $this->assertEquals('123', $result['order_id']);
        $this->assertEquals('DEF', $result['code']);
    }

    public function test_getTicketURLComponents_with_pdf_request(): void {
        $core = $this->main->getCore();
        $url = '/ticket/ABC-123-DEF?pdf';

        $result = $core->getTicketURLComponents($url);
        $this->assertTrue($result['_isPDFRequest']);
        $this->assertFalse($result['_isICSRequest']);
        $this->assertFalse($result['_isBadgeRequest']);
    }

    public function test_getTicketURLComponents_with_ics_request(): void {
        $core = $this->main->getCore();
        $url = '/ticket/ABC-123-DEF?ics';

        $result = $core->getTicketURLComponents($url);
        $this->assertFalse($result['_isPDFRequest']);
        $this->assertTrue($result['_isICSRequest']);
    }

    public function test_getTicketURLComponents_with_badge_request(): void {
        $core = $this->main->getCore();
        $url = '/ticket/ABC-123-DEF?badge';

        $result = $core->getTicketURLComponents($url);
        $this->assertTrue($result['_isBadgeRequest']);
    }

    public function test_getTicketURLComponents_with_trailing_slash(): void {
        $core = $this->main->getCore();
        $url = '/ticket/ABC-123-DEF/';

        $result = $core->getTicketURLComponents($url);
        $this->assertEquals('ABC', $result['idcode']);
        $this->assertEquals('123', $result['order_id']);
        $this->assertEquals('DEF', $result['code']);
    }

    public function test_getTicketURLComponents_invalid_url_throws(): void {
        $core = $this->main->getCore();

        $this->expectException(Exception::class);
        $core->getTicketURLComponents('/ticket/');
    }

    public function test_getTicketURLComponents_incomplete_id_throws(): void {
        $core = $this->main->getCore();

        $this->expectException(Exception::class);
        $core->getTicketURLComponents('/ticket/ABC-123');
    }

    public function test_getTicketURLComponents_result_keys(): void {
        $core = $this->main->getCore();
        $url = '/ticket/ABC-123-DEF';

        $result = $core->getTicketURLComponents($url);
        $this->assertArrayHasKey('foundcode', $result);
        $this->assertArrayHasKey('idcode', $result);
        $this->assertArrayHasKey('order_id', $result);
        $this->assertArrayHasKey('code', $result);
        $this->assertArrayHasKey('_request', $result);
        $this->assertArrayHasKey('_isPDFRequest', $result);
        $this->assertArrayHasKey('_isICSRequest', $result);
        $this->assertArrayHasKey('_isBadgeRequest', $result);
    }

    // ── replaceURLParameters ─────────────────────────────────────

    public function test_replaceURLParameters_replaces_code(): void {
        $core = $this->main->getCore();
        $codeObj = ['code' => 'TESTCODE123', 'code_display' => 'TEST-CODE-123'];

        $url = 'https://example.com/webhook?code={CODE}';
        $result = $core->replaceURLParameters($url, $codeObj);
        $this->assertEquals('https://example.com/webhook?code=TESTCODE123', $result);
    }

    public function test_replaceURLParameters_replaces_codedisplay(): void {
        $core = $this->main->getCore();
        $codeObj = ['code' => 'TESTCODE123', 'code_display' => 'TEST-CODE-123'];

        $url = 'https://example.com/webhook?display={CODEDISPLAY}';
        $result = $core->replaceURLParameters($url, $codeObj);
        $this->assertEquals('https://example.com/webhook?display=TEST-CODE-123', $result);
    }

    public function test_replaceURLParameters_replaces_multiple_placeholders(): void {
        $core = $this->main->getCore();
        $codeObj = ['code' => 'ABC', 'code_display' => 'A-B-C'];

        $url = 'https://example.com/?code={CODE}&display={CODEDISPLAY}';
        $result = $core->replaceURLParameters($url, $codeObj);
        $this->assertStringContainsString('code=ABC', $result);
        $this->assertStringContainsString('display=A-B-C', $result);
    }

    public function test_replaceURLParameters_empty_code_leaves_empty(): void {
        $core = $this->main->getCore();
        $codeObj = [];

        $url = 'https://example.com/?code={CODE}';
        $result = $core->replaceURLParameters($url, $codeObj);
        $this->assertEquals('https://example.com/?code=', $result);
    }

    public function test_replaceURLParameters_userid_when_logged_out(): void {
        wp_set_current_user(0);
        $core = $this->main->getCore();
        $codeObj = ['code' => 'X'];

        $url = 'https://example.com/?user={USERID}';
        $result = $core->replaceURLParameters($url, $codeObj);
        $this->assertEquals('https://example.com/?user=', $result);
    }

    public function test_replaceURLParameters_userid_when_logged_in(): void {
        $userId = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($userId);

        $core = $this->main->getCore();
        $codeObj = ['code' => 'X'];

        $url = 'https://example.com/?user={USERID}';
        $result = $core->replaceURLParameters($url, $codeObj);
        $this->assertEquals("https://example.com/?user={$userId}", $result);
    }

    public function test_replaceURLParameters_list_placeholder(): void {
        $core = $this->main->getCore();

        // Create a list
        $listId = $this->main->getDB()->insert('lists', [
            'name' => 'URL Test List',
            'aktiv' => 1,
            'meta' => '{}',
        ]);

        $codeObj = ['code' => 'X', 'list_id' => $listId];

        $url = 'https://example.com/?list={LIST}';
        $result = $core->replaceURLParameters($url, $codeObj);
        $this->assertStringContainsString('list=' . urlencode('URL Test List'), $result);
    }

    public function test_replaceURLParameters_no_placeholders_unchanged(): void {
        $core = $this->main->getCore();
        $codeObj = ['code' => 'X'];

        $url = 'https://example.com/plain-url';
        $result = $core->replaceURLParameters($url, $codeObj);
        $this->assertEquals('https://example.com/plain-url', $result);
    }
}
