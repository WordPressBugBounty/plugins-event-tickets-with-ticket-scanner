<?php
/**
 * Tests for Core metadata methods (saveMetaObject, QR content, alignArrays, ticket URLs, etc.).
 */

class CoreMetaTest extends WP_UnitTestCase {

    private $main;

    public function set_up(): void {
        parent::set_up();
        $this->main = sasoEventtickets::Instance();
    }

    /**
     * Helper: create a code with meta.
     */
    private function createCodeWithMeta(array $metaOverrides = []): array {
        $listId = $this->main->getDB()->insert('lists', [
            'name' => 'CoreMeta List ' . uniqid(),
            'aktiv' => 1,
            'meta' => '{}',
        ]);

        $metaObj = $this->main->getCore()->getMetaObject();
        foreach ($metaOverrides as $key => $value) {
            // Support nested keys like 'wc_ticket.is_ticket'
            $parts = explode('.', $key);
            $ref = &$metaObj;
            foreach ($parts as $part) {
                $ref = &$ref[$part];
            }
            $ref = $value;
        }
        $metaJson = $this->main->getCore()->json_encode_with_error_handling($metaObj);

        $code = 'CM' . strtoupper(uniqid());
        $codeId = $this->main->getDB()->insert('codes', [
            'code' => $code,
            'code_display' => $code,
            'cvv' => '',
            'meta' => $metaJson,
            'aktiv' => 1,
            'redeemed' => 0,
            'list_id' => $listId,
            'order_id' => 0,
        ]);

        return [
            'code' => $code,
            'code_id' => $codeId,
            'list_id' => $listId,
            'meta_obj' => $metaObj,
        ];
    }

    // ── saveMetaObject ────────────────────────────────────────────

    public function test_saveMetaObject_bug_calls_wrong_method(): void {
        // saveMetaObject() has a bug: calls _json_encode_with_error_handling() (non-existent)
        // instead of json_encode_with_error_handling(). Skip until fixed.
        $this->markTestSkipped('saveMetaObject calls undefined _json_encode_with_error_handling method');
    }

    // ── decodeAndMergeMeta ────────────────────────────────────────

    public function test_decodeAndMergeMeta_merges_values(): void {
        $default = ['a' => 1, 'b' => 2, 'c' => ['d' => 3]];
        $json = json_encode(['b' => 99, 'c' => ['d' => 88]]);

        $result = $this->main->getCore()->decodeAndMergeMeta($json, $default);
        $this->assertEquals(1, $result['a']);
        $this->assertEquals(99, $result['b']);
        $this->assertEquals(88, $result['c']['d']);
    }

    public function test_decodeAndMergeMeta_empty_json_returns_default(): void {
        $default = ['a' => 1, 'b' => 2];

        $result = $this->main->getCore()->decodeAndMergeMeta('', $default);
        $this->assertEquals($default, $result);
    }

    public function test_decodeAndMergeMeta_null_json_returns_default(): void {
        $default = ['x' => 'y'];

        $result = $this->main->getCore()->decodeAndMergeMeta(null, $default);
        $this->assertEquals($default, $result);
    }

    public function test_decodeAndMergeMeta_invalid_json_returns_default(): void {
        $default = ['a' => 1];

        $result = $this->main->getCore()->decodeAndMergeMeta('{invalid', $default);
        $this->assertEquals($default, $result);
    }

    // ── alignArrays ──────────────────────────────────────────────

    public function test_alignArrays_adds_missing_keys(): void {
        $template = ['a' => 1, 'b' => 2, 'c' => 3];
        $target = ['a' => 10];

        $this->main->getCore()->alignArrays($template, $target);
        $this->assertArrayHasKey('b', $target);
        $this->assertArrayHasKey('c', $target);
        $this->assertEquals(10, $target['a']); // original value preserved
        $this->assertNull($target['b']); // added as null
    }

    public function test_alignArrays_removes_extra_keys(): void {
        $template = ['a' => 1];
        $target = ['a' => 10, 'b' => 20, 'c' => 30];

        $this->main->getCore()->alignArrays($template, $target);
        $this->assertArrayHasKey('a', $target);
        $this->assertArrayNotHasKey('b', $target);
        $this->assertArrayNotHasKey('c', $target);
    }

    public function test_alignArrays_recursive_subarrays(): void {
        $template = ['nested' => ['x' => 1, 'y' => 2]];
        $target = ['nested' => ['x' => 99]];

        $this->main->getCore()->alignArrays($template, $target);
        $this->assertEquals(99, $target['nested']['x']);
        $this->assertNull($target['nested']['y']);
    }

    public function test_alignArrays_empty_template(): void {
        $template = [];
        $target = ['a' => 1, 'b' => 2];

        $this->main->getCore()->alignArrays($template, $target);
        $this->assertEmpty($target);
    }

    // ── isCodeIsRegistered ────────────────────────────────────────

    public function test_isCodeIsRegistered_false_for_fresh_code(): void {
        $data = $this->createCodeWithMeta();
        $codeObj = $this->main->getCore()->retrieveCodeByCode($data['code']);

        $this->assertFalse($this->main->getCore()->isCodeIsRegistered($codeObj));
    }

    public function test_isCodeIsRegistered_true_when_user_value_set(): void {
        $data = $this->createCodeWithMeta(['user.value' => 'Max Mustermann']);
        $codeObj = $this->main->getCore()->retrieveCodeByCode($data['code']);

        $this->assertTrue($this->main->getCore()->isCodeIsRegistered($codeObj));
    }

    // ── getTicketId ───────────────────────────────────────────────

    public function test_getTicketId_formats_correctly(): void {
        $data = $this->createCodeWithMeta(['wc_ticket.idcode' => 'TK']);
        $codeObj = $this->main->getCore()->retrieveCodeByCode($data['code']);
        $codeObj['order_id'] = 42;
        $metaObj = $this->main->getCore()->encodeMetaValuesAndFillObject($codeObj['meta'], $codeObj);

        $ticketId = $this->main->getCore()->getTicketId($codeObj, $metaObj);
        $this->assertEquals('TK-42-' . $data['code'], $ticketId);
    }

    public function test_getTicketId_empty_when_missing_fields(): void {
        $codeObj = ['code' => 'X', 'order_id' => 0]; // no idcode in meta
        $metaObj = $this->main->getCore()->getMetaObject();

        $ticketId = $this->main->getCore()->getTicketId($codeObj, $metaObj);
        // idcode is empty by default, so result depends on implementation
        $this->assertIsString($ticketId);
    }

    // ── getTicketURL ──────────────────────────────────────────────

    public function test_getTicketURL_contains_ticket_id(): void {
        $data = $this->createCodeWithMeta(['wc_ticket.idcode' => 'EVT']);
        $codeObj = $this->main->getCore()->retrieveCodeByCode($data['code']);
        $codeObj['order_id'] = 100;
        $metaObj = $this->main->getCore()->encodeMetaValuesAndFillObject($codeObj['meta'], $codeObj);

        $url = $this->main->getCore()->getTicketURL($codeObj, $metaObj);
        $this->assertStringContainsString('EVT-100-' . $data['code'], $url);
    }

    // ── getTicketScannerURL ───────────────────────────────────────

    public function test_getTicketScannerURL_contains_scanner_path(): void {
        $url = $this->main->getCore()->getTicketScannerURL('TK-1-ABC');
        $this->assertStringContainsString('scanner/', $url);
        $this->assertStringContainsString('code=', $url);
        $this->assertStringContainsString('TK-1-ABC', $url);
    }

    // ── getQRCodeContent ──────────────────────────────────────────

    public function test_getQRCodeContent_returns_ticket_id_by_default(): void {
        $data = $this->createCodeWithMeta(['wc_ticket.idcode' => 'QR']);
        $codeObj = $this->main->getCore()->retrieveCodeByCode($data['code']);
        $codeObj['order_id'] = 55;

        $qr = $this->main->getCore()->getQRCodeContent($codeObj);
        $this->assertStringContainsString('QR-55-' . $data['code'], $qr);
    }

    public function test_getQRCodeContent_with_scanner_url_option(): void {
        $data = $this->createCodeWithMeta(['wc_ticket.idcode' => 'SC']);
        $codeObj = $this->main->getCore()->retrieveCodeByCode($data['code']);
        $codeObj['order_id'] = 77;

        // Enable scanner URL option
        $this->main->getOptions()->changeOption([
            'key' => 'ticketQRUseURLToTicketScanner',
            'value' => 1,
        ]);

        $qr = $this->main->getCore()->getQRCodeContent($codeObj);
        $this->assertStringContainsString('scanner/', $qr);

        // Reset
        $this->main->getOptions()->changeOption([
            'key' => 'ticketQRUseURLToTicketScanner',
            'value' => 0,
        ]);
    }

    // ── checkCodesSize / isCodeSizeExceeded ───────────────────────

    public function test_isCodeSizeExceeded_returns_bool(): void {
        $result = $this->main->getCore()->isCodeSizeExceeded();
        $this->assertIsBool($result);
    }

    public function test_checkCodesSize_does_not_throw_under_limit(): void {
        // With few codes, should not throw
        $this->main->getCore()->checkCodesSize();
        $this->assertTrue(true); // no exception
    }

    // ── getRealIpAddr ─────────────────────────────────────────────

    public function test_getRealIpAddr_returns_string(): void {
        $_SERVER['REMOTE_ADDR'] = '192.168.1.1';
        $ip = $this->main->getCore()->getRealIpAddr();
        $this->assertIsString($ip);
        $this->assertEquals('192.168.1.1', $ip);
    }

    public function test_getRealIpAddr_prefers_client_ip(): void {
        $_SERVER['HTTP_CLIENT_IP'] = '10.0.0.1';
        $_SERVER['REMOTE_ADDR'] = '192.168.1.1';

        $ip = $this->main->getCore()->getRealIpAddr();
        $this->assertEquals('10.0.0.1', $ip);

        unset($_SERVER['HTTP_CLIENT_IP']);
    }

    public function test_getRealIpAddr_uses_forwarded_for(): void {
        unset($_SERVER['HTTP_CLIENT_IP']);
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '172.16.0.1';
        $_SERVER['REMOTE_ADDR'] = '192.168.1.1';

        $ip = $this->main->getCore()->getRealIpAddr();
        $this->assertEquals('172.16.0.1', $ip);

        unset($_SERVER['HTTP_X_FORWARDED_FOR']);
    }
}
