<?php
/**
 * Tests for sasoEventtickets_Core pure/near-pure methods.
 */

class CoreMethodsTest extends WP_UnitTestCase {

    private sasoEventtickets_Core $core;

    public function set_up(): void {
        parent::set_up();
        $this->core = sasoEventtickets::Instance()->getCore();
    }

    // ── clearCode ──────────────────────────────────────────────

    public function test_clearCode_removes_spaces(): void {
        $this->assertSame('ABC123', $this->core->clearCode('ABC 123'));
    }

    public function test_clearCode_removes_colons(): void {
        $this->assertSame('ABC123', $this->core->clearCode('ABC:123'));
    }

    public function test_clearCode_removes_dashes(): void {
        $this->assertSame('ABC123', $this->core->clearCode('ABC-123'));
    }

    public function test_clearCode_strips_html_tags(): void {
        $this->assertSame('ABC123', $this->core->clearCode('<b>ABC123</b>'));
    }

    public function test_clearCode_trims_whitespace(): void {
        $this->assertSame('ABC123', $this->core->clearCode('  ABC123  '));
    }

    public function test_clearCode_url_decodes(): void {
        // urldecode runs AFTER str_replace, so %20 becomes space in output
        $this->assertSame('ABC 123', $this->core->clearCode('ABC%20123'));
    }

    public function test_clearCode_combined_sanitization(): void {
        // Order: replace(- : space) → strip_tags → urldecode → trim
        // ' <i>AB-C:D E%20F</i> ' → 'ABCDE%20F' (tags+dash+colon+space removed)
        // → urldecode → 'ABCDE F' → trim → 'ABCDE F'
        $this->assertSame('ABCDE F', $this->core->clearCode(' <i>AB-C:D E%20F</i> '));
    }

    public function test_clearCode_empty_string(): void {
        $this->assertSame('', $this->core->clearCode(''));
    }

    // ── decodeAndMergeMeta ─────────────────────────────────────

    public function test_decodeAndMergeMeta_null_returns_defaults(): void {
        $defaults = ['a' => 1, 'b' => ['c' => 2]];
        $result = $this->core->decodeAndMergeMeta(null, $defaults);
        $this->assertSame($defaults, $result);
    }

    public function test_decodeAndMergeMeta_empty_string_returns_defaults(): void {
        $defaults = ['a' => 1];
        $result = $this->core->decodeAndMergeMeta('', $defaults);
        $this->assertSame($defaults, $result);
    }

    public function test_decodeAndMergeMeta_invalid_json_returns_defaults(): void {
        $defaults = ['a' => 1];
        $result = $this->core->decodeAndMergeMeta('{broken', $defaults);
        $this->assertSame($defaults, $result);
    }

    public function test_decodeAndMergeMeta_merges_values(): void {
        $defaults = ['a' => 1, 'b' => 2, 'c' => 3];
        $json = '{"b": 99}';
        $result = $this->core->decodeAndMergeMeta($json, $defaults);
        $this->assertSame(1, $result['a']);
        $this->assertSame(99, $result['b']);
        $this->assertSame(3, $result['c']);
    }

    public function test_decodeAndMergeMeta_deep_merge(): void {
        $defaults = ['outer' => ['inner1' => 'a', 'inner2' => 'b']];
        $json = '{"outer": {"inner1": "X"}}';
        $result = $this->core->decodeAndMergeMeta($json, $defaults);
        $this->assertSame('X', $result['outer']['inner1']);
        $this->assertSame('b', $result['outer']['inner2']);
    }

    // ── getMetaObject ──────────────────────────────────────────

    public function test_getMetaObject_returns_array(): void {
        $meta = $this->core->getMetaObject();
        $this->assertIsArray($meta);
    }

    public function test_getMetaObject_has_required_keys(): void {
        $meta = $this->core->getMetaObject();
        $requiredKeys = ['validation', 'user', 'used', 'confirmedCount', 'woocommerce', 'wc_rp', 'wc_ticket'];
        foreach ($requiredKeys as $key) {
            $this->assertArrayHasKey($key, $meta, "Missing key: $key");
        }
    }

    public function test_getMetaObject_wc_ticket_has_stats_redeemed(): void {
        $meta = $this->core->getMetaObject();
        $this->assertArrayHasKey('stats_redeemed', $meta['wc_ticket']);
        $this->assertSame([], $meta['wc_ticket']['stats_redeemed']);
    }

    public function test_getMetaObject_wc_ticket_has_required_fields(): void {
        $meta = $this->core->getMetaObject();
        $fields = ['is_ticket', 'redeemed_date', 'idcode', 'name_per_ticket', 'value_per_ticket', 'day_per_ticket'];
        foreach ($fields as $field) {
            $this->assertArrayHasKey($field, $meta['wc_ticket'], "wc_ticket missing: $field");
        }
    }

    public function test_getMetaObject_validation_has_required_fields(): void {
        $meta = $this->core->getMetaObject();
        $fields = ['first_success', 'first_ip', 'last_success', 'last_ip'];
        foreach ($fields as $field) {
            $this->assertArrayHasKey($field, $meta['validation'], "validation missing: $field");
        }
    }

    public function test_getMetaObject_woocommerce_has_order_and_product_id(): void {
        $meta = $this->core->getMetaObject();
        $this->assertArrayHasKey('order_id', $meta['woocommerce']);
        $this->assertArrayHasKey('product_id', $meta['woocommerce']);
        $this->assertSame(0, $meta['woocommerce']['order_id']);
        $this->assertSame(0, $meta['woocommerce']['product_id']);
    }

    // ── getMetaObjectKeyList ───────────────────────────────────

    public function test_getMetaObjectKeyList_flat_array(): void {
        $meta = ['name' => 'test', 'value' => 42];
        $keys = $this->core->getMetaObjectKeyList($meta);
        $this->assertSame(['META_NAME', 'META_VALUE'], $keys);
    }

    public function test_getMetaObjectKeyList_nested_array(): void {
        $meta = ['outer' => ['inner' => 'val']];
        $keys = $this->core->getMetaObjectKeyList($meta);
        $this->assertSame(['META_OUTER_INNER'], $keys);
    }

    public function test_getMetaObjectKeyList_custom_prefix(): void {
        $meta = ['foo' => 'bar'];
        $keys = $this->core->getMetaObjectKeyList($meta, 'TAG_');
        $this->assertSame(['TAG_FOO'], $keys);
    }

    public function test_getMetaObjectKeyList_real_meta_contains_expected_keys(): void {
        $meta = $this->core->getMetaObject();
        $keys = $this->core->getMetaObjectKeyList($meta);
        $this->assertContains('META_VALIDATION_FIRST_SUCCESS', $keys);
        $this->assertContains('META_WOOCOMMERCE_ORDER_ID', $keys);
        $this->assertContains('META_WC_TICKET_REDEEMED_DATE', $keys);
        $this->assertContains('META_WC_TICKET_IDCODE', $keys);
        $this->assertContains('META_USER_REG_USERID', $keys);
    }

    // ── getTicketId ────────────────────────────────────────────

    public function test_getTicketId_correct_format(): void {
        $codeObj = ['code' => 'abc123', 'order_id' => 42];
        $metaObj = ['wc_ticket' => ['idcode' => 'XYZ']];
        $result = $this->core->getTicketId($codeObj, $metaObj);
        $this->assertSame('XYZ-42-abc123', $result);
    }

    public function test_getTicketId_missing_code_returns_empty(): void {
        $codeObj = ['order_id' => 42];
        $metaObj = ['wc_ticket' => ['idcode' => 'XYZ']];
        $result = $this->core->getTicketId($codeObj, $metaObj);
        $this->assertSame('', $result);
    }

    public function test_getTicketId_missing_idcode_returns_empty(): void {
        $codeObj = ['code' => 'abc123', 'order_id' => 42];
        $metaObj = ['wc_ticket' => []];
        $result = $this->core->getTicketId($codeObj, $metaObj);
        $this->assertSame('', $result);
    }

    // ── json_encode_with_error_handling ────────────────────────

    public function test_json_encode_simple_array(): void {
        $result = $this->core->json_encode_with_error_handling(['a' => 1, 'b' => 'test']);
        $decoded = json_decode($result, true);
        $this->assertSame(1, $decoded['a']);
        $this->assertSame('test', $decoded['b']);
    }

    public function test_json_encode_empty_array(): void {
        $result = $this->core->json_encode_with_error_handling([]);
        $this->assertSame('[]', $result);
    }

    public function test_json_encode_nested_structure(): void {
        $data = ['level1' => ['level2' => ['value' => 42]]];
        $result = $this->core->json_encode_with_error_handling($data);
        $decoded = json_decode($result, true);
        $this->assertSame(42, $decoded['level1']['level2']['value']);
    }

    public function test_json_encode_numeric_check(): void {
        // JSON_NUMERIC_CHECK flag converts numeric strings to numbers
        $result = $this->core->json_encode_with_error_handling(['num' => '42']);
        $decoded = json_decode($result, true);
        $this->assertSame(42, $decoded['num']);
    }

    // ── alignArrays ───────────────────────────────────────────

    public function test_alignArrays_adds_missing_keys(): void {
        $template = ['a' => 1, 'b' => 2, 'c' => 3];
        $target = ['a' => 10];
        $this->core->alignArrays($template, $target);
        $this->assertArrayHasKey('b', $target);
        $this->assertArrayHasKey('c', $target);
        $this->assertSame(10, $target['a']); // keeps existing value
    }

    public function test_alignArrays_removes_extra_keys(): void {
        $template = ['a' => 1];
        $target = ['a' => 10, 'b' => 20, 'c' => 30];
        $this->core->alignArrays($template, $target);
        $this->assertArrayHasKey('a', $target);
        $this->assertArrayNotHasKey('b', $target);
        $this->assertArrayNotHasKey('c', $target);
    }

    public function test_alignArrays_recursive(): void {
        $template = ['outer' => ['a' => 1, 'b' => 2]];
        $target = ['outer' => ['a' => 10, 'extra' => 99]];
        $this->core->alignArrays($template, $target);
        $this->assertArrayHasKey('b', $target['outer']);
        $this->assertArrayNotHasKey('extra', $target['outer']);
        $this->assertSame(10, $target['outer']['a']);
    }

    public function test_alignArrays_missing_subarray_added_empty(): void {
        $template = ['nested' => ['x' => 1]];
        $target = [];
        $this->core->alignArrays($template, $target);
        $this->assertArrayHasKey('nested', $target);
        // nested is added as [] first, then recursion adds 'x' => null
        $this->assertArrayHasKey('x', $target['nested']);
        $this->assertNull($target['nested']['x']);
    }

    // ── getMetaObjectList + getMetaObjectAuthtoken ─────────────

    public function test_getMetaObjectList_returns_array(): void {
        $meta = $this->core->getMetaObjectList();
        $this->assertIsArray($meta);
    }

    public function test_getMetaObjectAuthtoken_returns_array(): void {
        $meta = $this->core->getMetaObjectAuthtoken();
        $this->assertIsArray($meta);
    }

    public function test_getMetaObjectAuthtoken_has_ticketscanner_key(): void {
        $meta = $this->core->getMetaObjectAuthtoken();
        $this->assertArrayHasKey('ticketscanner', $meta);
    }

    // ── getDefaultMetaValueOfSubs ──────────────────────────────

    public function test_getDefaultMetaValueOfSubs_returns_array(): void {
        $subs = $this->core->getDefaultMetaValueOfSubs();
        $this->assertIsArray($subs);
    }
}
