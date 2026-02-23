<?php
/**
 * Tests for Core meta object structure methods:
 * getMetaObject, getMetaObjectKeyList, getMetaObjectAllowedReplacementTags,
 * getMetaObjectList, getMetaObjectAuthtoken, encodeMetaValuesAndFillObjectAuthtoken.
 */

class CoreMetaObjectStructureTest extends WP_UnitTestCase {

    private $main;

    public function set_up(): void {
        parent::set_up();
        $this->main = sasoEventtickets::Instance();
    }

    // ── getMetaObject ──────────────────────────────────────────────

    public function test_getMetaObject_returns_array(): void {
        $result = $this->main->getCore()->getMetaObject();
        $this->assertIsArray($result);
        $this->assertNotEmpty($result);
    }

    public function test_getMetaObject_has_validation_key(): void {
        $result = $this->main->getCore()->getMetaObject();
        $this->assertArrayHasKey('validation', $result);
    }

    public function test_getMetaObject_has_user_key(): void {
        $result = $this->main->getCore()->getMetaObject();
        $this->assertArrayHasKey('user', $result);
    }

    public function test_getMetaObject_validation_has_expected_subkeys(): void {
        $result = $this->main->getCore()->getMetaObject();
        $validation = $result['validation'];
        $this->assertArrayHasKey('first_success', $validation);
        $this->assertArrayHasKey('last_success', $validation);
        $this->assertArrayHasKey('first_ip', $validation);
        $this->assertArrayHasKey('last_ip', $validation);
    }

    // ── getMetaObjectKeyList ───────────────────────────────────────

    public function test_getMetaObjectKeyList_returns_array(): void {
        $metaObj = $this->main->getCore()->getMetaObject();
        $keys = $this->main->getCore()->getMetaObjectKeyList($metaObj);
        $this->assertIsArray($keys);
        $this->assertNotEmpty($keys);
    }

    public function test_getMetaObjectKeyList_keys_are_uppercase(): void {
        $metaObj = $this->main->getCore()->getMetaObject();
        $keys = $this->main->getCore()->getMetaObjectKeyList($metaObj);
        foreach ($keys as $key) {
            $this->assertEquals(strtoupper($key), $key, "Key '$key' should be uppercase");
        }
    }

    public function test_getMetaObjectKeyList_starts_with_META_prefix(): void {
        $metaObj = $this->main->getCore()->getMetaObject();
        $keys = $this->main->getCore()->getMetaObjectKeyList($metaObj);
        foreach ($keys as $key) {
            $this->assertStringStartsWith('META_', $key);
        }
    }

    public function test_getMetaObjectKeyList_custom_prefix(): void {
        $metaObj = $this->main->getCore()->getMetaObject();
        $keys = $this->main->getCore()->getMetaObjectKeyList($metaObj, 'TICKET_');
        foreach ($keys as $key) {
            $this->assertStringStartsWith('TICKET_', $key);
        }
    }

    // ── getMetaObjectAllowedReplacementTags ─────────────────────────

    public function test_getMetaObjectAllowedReplacementTags_returns_array(): void {
        $result = $this->main->getCore()->getMetaObjectAllowedReplacementTags();
        $this->assertIsArray($result);
        $this->assertNotEmpty($result);
    }

    public function test_getMetaObjectAllowedReplacementTags_has_user_value(): void {
        $tags = $this->main->getCore()->getMetaObjectAllowedReplacementTags();
        $keys = array_column($tags, 'key');
        $this->assertContains('USER_VALUE', $keys);
    }

    public function test_getMetaObjectAllowedReplacementTags_has_woocommerce_keys(): void {
        $tags = $this->main->getCore()->getMetaObjectAllowedReplacementTags();
        $keys = array_column($tags, 'key');
        $this->assertContains('WOOCOMMERCE_ORDER_ID', $keys);
        $this->assertContains('WOOCOMMERCE_PRODUCT_ID', $keys);
    }

    // ── getMetaObjectList ──────────────────────────────────────────

    public function test_getMetaObjectList_returns_array(): void {
        $result = $this->main->getCore()->getMetaObjectList();
        $this->assertIsArray($result);
    }

    public function test_getMetaObjectList_has_desc_key(): void {
        $result = $this->main->getCore()->getMetaObjectList();
        $this->assertArrayHasKey('desc', $result);
    }

    public function test_getMetaObjectList_has_formatter_key(): void {
        $result = $this->main->getCore()->getMetaObjectList();
        $this->assertArrayHasKey('formatter', $result);
    }

    public function test_getMetaObjectList_has_webhooks_key(): void {
        $result = $this->main->getCore()->getMetaObjectList();
        $this->assertArrayHasKey('webhooks', $result);
    }

    // ── getMetaObjectAuthtoken ─────────────────────────────────────

    public function test_getMetaObjectAuthtoken_returns_array(): void {
        $result = $this->main->getCore()->getMetaObjectAuthtoken();
        $this->assertIsArray($result);
    }

    public function test_getMetaObjectAuthtoken_has_desc_key(): void {
        $result = $this->main->getCore()->getMetaObjectAuthtoken();
        $this->assertArrayHasKey('desc', $result);
    }

    public function test_getMetaObjectAuthtoken_has_ticketscanner_key(): void {
        $result = $this->main->getCore()->getMetaObjectAuthtoken();
        $this->assertArrayHasKey('ticketscanner', $result);
    }

    // ── encodeMetaValuesAndFillObjectAuthtoken ──────────────────────

    public function test_encodeMetaValuesAndFillObjectAuthtoken_empty_string(): void {
        $result = $this->main->getCore()->encodeMetaValuesAndFillObjectAuthtoken('');
        $this->assertIsArray($result);
        $this->assertArrayHasKey('desc', $result);
    }

    public function test_encodeMetaValuesAndFillObjectAuthtoken_json_string(): void {
        $json = json_encode(['desc' => 'Test description', 'ticketscanner' => ['bound_to_products' => '']]);
        $result = $this->main->getCore()->encodeMetaValuesAndFillObjectAuthtoken($json);
        $this->assertIsArray($result);
        $this->assertEquals('Test description', $result['desc']);
    }
}
