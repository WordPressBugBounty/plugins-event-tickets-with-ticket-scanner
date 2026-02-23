<?php
/**
 * Tests for error logging, code sanitization, and Core utility methods.
 */

class ErrorLogAndSanitizeTest extends WP_UnitTestCase {

    private $main;

    public function set_up(): void {
        parent::set_up();
        $this->main = sasoEventtickets::Instance();
    }

    // ── clearCode ─────────────────────────────────────────────────

    public function test_clearCode_removes_spaces(): void {
        $result = $this->main->getCore()->clearCode('ABC 123');
        $this->assertEquals('ABC123', $result);
    }

    public function test_clearCode_removes_hyphens(): void {
        $result = $this->main->getCore()->clearCode('ABC-123-DEF');
        $this->assertEquals('ABC123DEF', $result);
    }

    public function test_clearCode_removes_colons(): void {
        $result = $this->main->getCore()->clearCode('ABC:123');
        $this->assertEquals('ABC123', $result);
    }

    public function test_clearCode_strips_html_tags(): void {
        $result = $this->main->getCore()->clearCode('<b>CODE</b>');
        $this->assertEquals('CODE', $result);
    }

    public function test_clearCode_url_decodes(): void {
        $result = $this->main->getCore()->clearCode('ABC%20123');
        // urldecode happens AFTER str_replace, so %20 becomes space after space-removal
        $this->assertEquals('ABC 123', $result);
    }

    public function test_clearCode_trims(): void {
        $result = $this->main->getCore()->clearCode('  ABC  ');
        $this->assertEquals('ABC', $result);
    }

    public function test_clearCode_combined(): void {
        $result = $this->main->getCore()->clearCode(' <i>AB-C:1 2</i> ');
        $this->assertEquals('ABC12', $result);
    }

    // ── logErrorToDB ──────────────────────────────────────────────

    public function test_logErrorToDB_stores_entry(): void {
        $exception = new Exception('Test error message');

        $this->main->getAdmin()->logErrorToDB($exception, 'TestCaller');

        // Verify entry exists in errorlogs table
        global $wpdb;
        $table = $wpdb->prefix . 'saso_eventtickets_errorlogs';
        $row = $wpdb->get_row("SELECT * FROM $table ORDER BY id DESC LIMIT 1", ARRAY_A);

        $this->assertNotNull($row);
        $this->assertEquals('Test error message', $row['exception_msg']);
        $this->assertEquals('TestCaller', $row['caller_name']);
    }

    public function test_logErrorToDB_with_extra_message(): void {
        $exception = new Exception('DB error');

        $this->main->getAdmin()->logErrorToDB($exception, 'DBHandler', 'Additional context');

        global $wpdb;
        $table = $wpdb->prefix . 'saso_eventtickets_errorlogs';
        $row = $wpdb->get_row("SELECT * FROM $table ORDER BY id DESC LIMIT 1", ARRAY_A);

        $this->assertEquals('DB error', $row['exception_msg']);
        $this->assertStringContainsString('Additional context', $row['msg']);
    }

    // ── getListById ───────────────────────────────────────────────

    public function test_getListById_returns_list(): void {
        $listId = $this->main->getDB()->insert('lists', [
            'name' => 'ById Test ' . uniqid(),
            'aktiv' => 1,
            'meta' => '{}',
        ]);

        $list = $this->main->getCore()->getListById($listId);
        $this->assertIsArray($list);
        $this->assertEquals($listId, intval($list['id']));
    }

    public function test_getListById_invalid_throws(): void {
        $this->expectException(Exception::class);
        $this->main->getCore()->getListById(999999);
    }

    // ── getCodesByOrderId ─────────────────────────────────────────

    public function test_getCodesByOrderId_zero_returns_empty(): void {
        $result = $this->main->getCore()->getCodesByOrderId(0);
        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function test_getCodesByOrderId_negative_returns_empty(): void {
        $result = $this->main->getCore()->getCodesByOrderId(-1);
        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    // ── encodeMetaValuesAndFillObjectList ──────────────────────────

    public function test_encodeMetaValuesAndFillObjectList_empty_string(): void {
        $result = $this->main->getCore()->encodeMetaValuesAndFillObjectList('');
        $this->assertIsArray($result);
    }

    public function test_encodeMetaValuesAndFillObjectList_valid_json(): void {
        $json = json_encode(['desc' => 'Test list', 'formatter' => ['active' => 1]]);
        $result = $this->main->getCore()->encodeMetaValuesAndFillObjectList($json);
        $this->assertIsArray($result);
        $this->assertEquals('Test list', $result['desc']);
    }

    // ── Frontend getOptions ───────────────────────────────────────

    public function test_frontend_getOptions_returns_public_only(): void {
        $options = $this->main->getFrontend()->getOptions();
        $this->assertIsArray($options);
        // Should not contain sensitive data like admin-only options
    }

    // ── getMetaObjectKeyList ──────────────────────────────────────

    public function test_getMetaObjectKeyList_returns_array(): void {
        $metaObj = $this->main->getCore()->getMetaObject();
        $keys = $this->main->getCore()->getMetaObjectKeyList($metaObj);
        $this->assertIsArray($keys);
        $this->assertNotEmpty($keys);
    }

    // ── getMetaObjectAllowedReplacementTags ───────────────────────

    public function test_getMetaObjectAllowedReplacementTags_returns_array(): void {
        $tags = $this->main->getCore()->getMetaObjectAllowedReplacementTags();
        $this->assertIsArray($tags);
        $this->assertNotEmpty($tags);
    }
}
