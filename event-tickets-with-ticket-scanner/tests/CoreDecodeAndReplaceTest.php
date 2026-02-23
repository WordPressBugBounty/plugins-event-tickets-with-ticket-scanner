<?php
/**
 * Tests for Core methods: decodeAndMergeMeta, setMetaObj, replaceURLParameters.
 */

class CoreDecodeAndReplaceTest extends WP_UnitTestCase {

    private $main;

    public function set_up(): void {
        parent::set_up();
        $this->main = sasoEventtickets::Instance();
    }

    // ── decodeAndMergeMeta ───────────────────────────────────────

    public function test_decodeAndMergeMeta_merges_values(): void {
        $defaults = ['a' => 1, 'b' => 2, 'c' => 3];
        $json = json_encode(['b' => 20, 'c' => 30]);

        $result = $this->main->getCore()->decodeAndMergeMeta($json, $defaults);

        $this->assertEquals(1, $result['a']);
        $this->assertEquals(20, $result['b']);
        $this->assertEquals(30, $result['c']);
    }

    public function test_decodeAndMergeMeta_returns_defaults_for_empty(): void {
        $defaults = ['x' => 1, 'y' => 2];
        $result = $this->main->getCore()->decodeAndMergeMeta('', $defaults);

        $this->assertEquals(1, $result['x']);
        $this->assertEquals(2, $result['y']);
    }

    public function test_decodeAndMergeMeta_returns_defaults_for_null(): void {
        $defaults = ['x' => 1];
        $result = $this->main->getCore()->decodeAndMergeMeta(null, $defaults);

        $this->assertEquals(1, $result['x']);
    }

    public function test_decodeAndMergeMeta_returns_defaults_for_invalid_json(): void {
        $defaults = ['x' => 1];
        $result = $this->main->getCore()->decodeAndMergeMeta('not-json', $defaults);

        $this->assertEquals(1, $result['x']);
    }

    public function test_decodeAndMergeMeta_recursive_merge(): void {
        $defaults = ['nested' => ['a' => 1, 'b' => 2]];
        $json = json_encode(['nested' => ['b' => 20]]);

        $result = $this->main->getCore()->decodeAndMergeMeta($json, $defaults);

        $this->assertEquals(1, $result['nested']['a']);
        $this->assertEquals(20, $result['nested']['b']);
    }

    // ── setMetaObj ───────────────────────────────────────────────

    public function test_setMetaObj_adds_metaObj_to_codeObj(): void {
        $listId = $this->main->getDB()->insert('lists', [
            'name' => 'SetMeta Test ' . uniqid(),
            'aktiv' => 1,
            'meta' => '{}',
        ]);

        $metaObj = $this->main->getCore()->getMetaObject();
        $metaJson = $this->main->getCore()->json_encode_with_error_handling($metaObj);

        $code = 'SET' . strtoupper(uniqid());
        $this->main->getDB()->insert('codes', [
            'code' => $code,
            'code_display' => $code,
            'cvv' => '',
            'meta' => $metaJson,
            'aktiv' => 1,
            'redeemed' => 0,
            'list_id' => $listId,
            'order_id' => 0,
        ]);

        $codeObj = $this->main->getCore()->retrieveCodeByCode($code);
        $result = $this->main->getCore()->setMetaObj($codeObj);

        $this->assertArrayHasKey('metaObj', $result);
        $this->assertIsArray($result['metaObj']);
    }

    public function test_setMetaObj_does_not_overwrite_existing(): void {
        $codeObj = [
            'code' => 'TEST',
            'meta' => '{}',
            'metaObj' => ['custom' => 'value'],
        ];

        $result = $this->main->getCore()->setMetaObj($codeObj);
        $this->assertEquals('value', $result['metaObj']['custom']);
    }

    // ── replaceURLParameters ─────────────────────────────────────

    public function test_replaceURLParameters_replaces_code(): void {
        $codeObj = ['code' => 'ABC123', 'code_display' => 'ABC-123'];
        $url = 'https://example.com/ticket/{CODE}';

        $result = $this->main->getCore()->replaceURLParameters($url, $codeObj);
        $this->assertStringContainsString('ABC123', $result);
        $this->assertStringNotContainsString('{CODE}', $result);
    }

    public function test_replaceURLParameters_replaces_codedisplay(): void {
        $codeObj = ['code' => 'ABC123', 'code_display' => 'ABC-123'];
        $url = 'https://example.com/ticket/{CODEDISPLAY}';

        $result = $this->main->getCore()->replaceURLParameters($url, $codeObj);
        $this->assertStringContainsString('ABC-123', $result);
    }

    public function test_replaceURLParameters_no_placeholders(): void {
        $codeObj = ['code' => 'ABC123', 'code_display' => 'ABC-123'];
        $url = 'https://example.com/static-url';

        $result = $this->main->getCore()->replaceURLParameters($url, $codeObj);
        $this->assertEquals('https://example.com/static-url', $result);
    }

    public function test_replaceURLParameters_replaces_ip(): void {
        $_SERVER['REMOTE_ADDR'] = '192.168.1.1';
        $codeObj = ['code' => 'ABC'];
        $url = 'https://example.com/?ip={IP}';

        $result = $this->main->getCore()->replaceURLParameters($url, $codeObj);
        $this->assertStringContainsString('192.168.1.1', $result);
    }
}
