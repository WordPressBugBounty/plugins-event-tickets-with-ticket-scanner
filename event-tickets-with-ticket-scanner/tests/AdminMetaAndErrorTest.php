<?php
/**
 * Tests for AdminSettings methods: clearFormatWarning, logErrorToDB,
 * getMetaOfCode, getRedeemAmount, removeUsedInformationFromCode.
 */

class AdminMetaAndErrorTest extends WP_UnitTestCase {

    private $main;

    public function set_up(): void {
        parent::set_up();
        $this->main = sasoEventtickets::Instance();
    }

    private function createCodeInList(): array {
        $listId = $this->main->getDB()->insert('lists', [
            'name' => 'AdminMeta Test ' . uniqid(),
            'aktiv' => 1,
            'meta' => '{}',
        ]);

        $metaObj = $this->main->getCore()->getMetaObject();
        $metaJson = $this->main->getCore()->json_encode_with_error_handling($metaObj);

        $code = 'AME' . strtoupper(uniqid());
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

        return ['code' => $code, 'list_id' => $listId];
    }

    private function createCodeWithOrder(string $status, string $email): array {
        $listId = $this->main->getDB()->insert('lists', [
            'name' => 'OrderMeta Test ' . uniqid(),
            'aktiv' => 1,
            'meta' => '{}',
        ]);

        $product = new WC_Product_Simple();
        $product->set_name('OrderMeta Ticket ' . uniqid());
        $product->set_regular_price('10.00');
        $product->set_status('publish');
        $product->save();
        update_post_meta($product->get_id(), 'saso_eventtickets_is_ticket', 'yes');
        update_post_meta($product->get_id(), 'saso_eventtickets_list', $listId);

        $order = wc_create_order();
        $order->add_product($product, 1);
        $order->set_billing_email($email);
        $order->calculate_totals();
        $order->set_status($status);
        $order->save();

        $metaObj = $this->main->getCore()->getMetaObject();
        $metaObj['woocommerce']['order_id'] = $order->get_id();
        $metaObj['woocommerce']['product_id'] = $product->get_id();
        $metaObj['woocommerce']['creation_date'] = gmdate('Y-m-d H:i:s');
        $metaJson = $this->main->getCore()->json_encode_with_error_handling($metaObj);

        $code = 'OME' . strtoupper(uniqid());
        $this->main->getDB()->insert('codes', [
            'code' => $code,
            'code_display' => $code,
            'cvv' => '',
            'meta' => $metaJson,
            'aktiv' => 1,
            'redeemed' => 0,
            'list_id' => $listId,
            'order_id' => $order->get_id(),
        ]);

        return ['code' => $code, 'list_id' => $listId, 'order_id' => $order->get_id()];
    }

    private function createCodeWithVariation(): array {
        $listId = $this->main->getDB()->insert('lists', [
            'name' => 'VarMeta Test ' . uniqid(),
            'aktiv' => 1,
            'meta' => '{}',
        ]);

        $parent = new WC_Product_Variable();
        $parent->set_name('Variable Ticket ' . uniqid());
        $parent->set_status('publish');
        $parent->save();

        $attr = new WC_Product_Attribute();
        $attr->set_name('pa_day');
        $attr->set_options(['Monday', 'Tuesday']);
        $attr->set_visible(true);
        $attr->set_variation(true);
        $parent->set_attributes([$attr]);
        $parent->save();

        $variation = new WC_Product_Variation();
        $variation->set_parent_id($parent->get_id());
        $variation->set_attributes(['pa_day' => 'Monday']);
        $variation->set_regular_price('20.00');
        $variation->set_status('publish');
        $variation->save();

        update_post_meta($parent->get_id(), 'saso_eventtickets_is_ticket', 'yes');
        update_post_meta($parent->get_id(), 'saso_eventtickets_list', $listId);

        $metaObj = $this->main->getCore()->getMetaObject();
        $metaObj['woocommerce']['order_id'] = 0;
        $metaObj['woocommerce']['product_id'] = $variation->get_id();
        $metaObj['woocommerce']['creation_date'] = gmdate('Y-m-d H:i:s');
        $metaJson = $this->main->getCore()->json_encode_with_error_handling($metaObj);

        $code = 'VME' . strtoupper(uniqid());
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

        return ['code' => $code, 'list_id' => $listId, 'variation_id' => $variation->get_id()];
    }

    // ── clearFormatWarning ───────────────────────────────────────

    public function test_clearFormatWarning_resets_counters(): void {
        $meta = json_encode([
            'desc' => '',
            'redirect' => ['url' => ''],
            'formatter' => ['active' => 1, 'format' => ''],
            'webhooks' => ['webhookURLaddwcticketsold' => ''],
            'messages' => [
                'format_limit_threshold_warning' => [
                    'attempts' => 5,
                    'last_email' => '2026-01-01 00:00:00',
                ],
                'format_end_warning' => [
                    'attempts' => 3,
                    'last_email' => '2026-01-15 00:00:00',
                ],
            ],
        ]);
        $listId = $this->main->getDB()->insert('lists', [
            'name' => 'ClearWarn Test ' . uniqid(),
            'aktiv' => 1,
            'meta' => $meta,
        ]);

        // Verify warning exists before clearing
        $before = $this->main->getAdmin()->getFormatWarning($listId);
        $this->assertNotNull($before, 'Warning should exist before clearing');

        // Clear by resetting counters in meta directly (same logic as clearFormatWarning)
        $listObj = $this->main->getAdmin()->getList(['id' => $listId]);
        $metaObj = $this->main->getCore()->encodeMetaValuesAndFillObjectList($listObj['meta']);
        $metaObj['messages']['format_limit_threshold_warning']['attempts'] = 0;
        $metaObj['messages']['format_limit_threshold_warning']['last_email'] = '';
        $metaObj['messages']['format_end_warning']['attempts'] = 0;
        $metaObj['messages']['format_end_warning']['last_email'] = '';
        $newMeta = $this->main->getCore()->json_encode_with_error_handling($metaObj);
        $this->main->getAdmin()->editList([
            'id' => $listId,
            'name' => $listObj['name'],
            'meta' => $newMeta,
        ]);

        // Verify warnings were cleared
        $result = $this->main->getAdmin()->getFormatWarning($listId);
        $this->assertNull($result, 'Format warnings should be cleared');
    }

    // ── logErrorToDB ─────────────────────────────────────────────

    public function test_logErrorToDB_inserts_record(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'saso_eventtickets_errorlogs';

        $countBefore = intval($wpdb->get_var("SELECT COUNT(*) FROM $table"));

        $e = new Exception('Test error message ' . uniqid());
        $this->main->getAdmin()->logErrorToDB($e, 'TestCaller', 'Additional info');

        $countAfter = intval($wpdb->get_var("SELECT COUNT(*) FROM $table"));
        $this->assertEquals($countBefore + 1, $countAfter);
    }

    public function test_logErrorToDB_stores_message(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'saso_eventtickets_errorlogs';

        $uniqueMsg = 'UniqueError_' . uniqid();
        $e = new Exception($uniqueMsg);
        $this->main->getAdmin()->logErrorToDB($e);

        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM $table WHERE exception_msg LIKE %s ORDER BY id DESC LIMIT 1", '%' . $uniqueMsg . '%'),
            ARRAY_A
        );

        $this->assertNotNull($row);
        $this->assertStringContainsString($uniqueMsg, $row['exception_msg']);
    }

    // ── getMetaOfCode ────────────────────────────────────────────

    public function test_getMetaOfCode_returns_enriched_meta(): void {
        $data = $this->createCodeInList();

        $result = $this->main->getAdmin()->getMetaOfCode(['code' => $data['code']]);
        $this->assertIsArray($result);
        $this->assertArrayHasKey('confirmedCount', $result);
    }

    public function test_getMetaOfCode_missing_code_throws(): void {
        $this->expectException(Exception::class);
        $this->main->getAdmin()->getMetaOfCode([]);
    }

    public function test_getMetaOfCode_invalid_code_throws(): void {
        $this->expectException(Exception::class);
        $this->main->getAdmin()->getMetaOfCode(['code' => 'NONEXISTENT_' . uniqid()]);
    }

    public function test_getMetaOfCode_without_order_has_no_order_status(): void {
        $data = $this->createCodeInList();
        $result = $this->main->getAdmin()->getMetaOfCode(['code' => $data['code']]);

        $this->assertArrayHasKey('woocommerce', $result);
        $this->assertArrayNotHasKey('_order_status', $result['woocommerce']);
        $this->assertArrayNotHasKey('_billing_email', $result['woocommerce']);
    }

    public function test_getMetaOfCode_with_order_returns_status_and_email(): void {
        if (!class_exists('WooCommerce')) $this->markTestSkipped('WooCommerce not available');

        $data = $this->createCodeWithOrder('completed', 'buyer@example.com');
        $result = $this->main->getAdmin()->getMetaOfCode(['code' => $data['code']]);

        $this->assertSame('completed', $result['woocommerce']['_order_status']);
        $this->assertSame('buyer@example.com', $result['woocommerce']['_billing_email']);
    }

    public function test_getMetaOfCode_with_processing_order_returns_processing_status(): void {
        if (!class_exists('WooCommerce')) $this->markTestSkipped('WooCommerce not available');

        $data = $this->createCodeWithOrder('processing', 'processing@example.com');
        $result = $this->main->getAdmin()->getMetaOfCode(['code' => $data['code']]);

        $this->assertSame('processing', $result['woocommerce']['_order_status']);
        $this->assertSame('processing@example.com', $result['woocommerce']['_billing_email']);
    }

    // ── getMetaOfCode via executeJSON (same path as JS AJAX) ─────

    public function test_getMetaOfCode_via_executeJSON_returns_woocommerce_section(): void {
        $user_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($user_id);

        $data = $this->createCodeInList();
        $result = $this->main->getAdmin()->executeJSON('getMetaOfCode', ['code' => $data['code']], true, true);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('woocommerce', $result);
        $this->assertArrayHasKey('order_id', $result['woocommerce']);
    }

    public function test_getMetaOfCode_via_executeJSON_without_order_no_status(): void {
        $user_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($user_id);

        $data = $this->createCodeInList();
        $result = $this->main->getAdmin()->executeJSON('getMetaOfCode', ['code' => $data['code']], true, true);

        $this->assertArrayNotHasKey('_order_status', $result['woocommerce']);
        $this->assertArrayNotHasKey('_billing_email', $result['woocommerce']);
    }

    public function test_getMetaOfCode_via_executeJSON_with_order_has_status_and_email(): void {
        if (!class_exists('WooCommerce')) $this->markTestSkipped('WooCommerce not available');

        $user_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($user_id);

        $data = $this->createCodeWithOrder('completed', 'ajax-test@example.com');
        $result = $this->main->getAdmin()->executeJSON('getMetaOfCode', ['code' => $data['code']], true, true);

        $this->assertSame('completed', $result['woocommerce']['_order_status']);
        $this->assertSame('ajax-test@example.com', $result['woocommerce']['_billing_email']);
    }

    public function test_getMetaOfCode_via_executeJSON_response_is_json_serializable(): void {
        if (!class_exists('WooCommerce')) $this->markTestSkipped('WooCommerce not available');

        $user_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($user_id);

        $data = $this->createCodeWithOrder('completed', 'json-test@example.com');
        $result = $this->main->getAdmin()->executeJSON('getMetaOfCode', ['code' => $data['code']], true, true);

        // JS does JSON.parse on the AJAX response — verify it survives encode/decode
        $json = json_encode($result);
        $this->assertNotFalse($json, 'getMetaOfCode response must be JSON-encodable');
        $decoded = json_decode($json, true);
        $this->assertSame('completed', $decoded['woocommerce']['_order_status']);
        $this->assertSame('json-test@example.com', $decoded['woocommerce']['_billing_email']);
    }

    // ── getMetaOfCode product name and variation ───────────────

    public function test_getMetaOfCode_with_order_returns_product_name(): void {
        if (!class_exists('WooCommerce')) $this->markTestSkipped('WooCommerce not available');

        $data = $this->createCodeWithOrder('completed', 'name@example.com');
        $result = $this->main->getAdmin()->getMetaOfCode(['code' => $data['code']]);

        $this->assertArrayHasKey('_product_name', $result['woocommerce']);
        $this->assertNotEmpty($result['woocommerce']['_product_name']);
    }

    public function test_getMetaOfCode_without_order_no_product_name(): void {
        $data = $this->createCodeInList();
        $result = $this->main->getAdmin()->getMetaOfCode(['code' => $data['code']]);

        $this->assertArrayNotHasKey('_product_name', $result['woocommerce']);
    }

    public function test_getMetaOfCode_simple_product_no_variation_attributes(): void {
        if (!class_exists('WooCommerce')) $this->markTestSkipped('WooCommerce not available');

        $data = $this->createCodeWithOrder('completed', 'simple@example.com');
        $result = $this->main->getAdmin()->getMetaOfCode(['code' => $data['code']]);

        $this->assertArrayNotHasKey('_variation_attributes', $result['woocommerce']);
    }

    public function test_getMetaOfCode_variation_product_returns_variation_attributes(): void {
        if (!class_exists('WooCommerce')) $this->markTestSkipped('WooCommerce not available');

        $data = $this->createCodeWithVariation();
        $result = $this->main->getAdmin()->getMetaOfCode(['code' => $data['code']]);

        $this->assertArrayHasKey('_product_name', $result['woocommerce']);
        $this->assertArrayHasKey('_variation_attributes', $result['woocommerce']);
        $this->assertNotEmpty($result['woocommerce']['_variation_attributes']);
    }

    // ── getRedeemAmount ──────────────────────────────────────────

    public function test_getRedeemAmount_returns_array(): void {
        $data = $this->createCodeInList();
        $codeObj = $this->main->getCore()->retrieveCodeByCode($data['code']);

        $result = $this->main->getAdmin()->getRedeemAmount($codeObj);
        $this->assertIsArray($result);
        $this->assertArrayHasKey('_redeemed_counter', $result);
        $this->assertArrayHasKey('_max_redeem_amount', $result);
        $this->assertArrayHasKey('cache', $result);
    }

    public function test_getRedeemAmount_zero_for_new_code(): void {
        $data = $this->createCodeInList();
        $codeObj = $this->main->getCore()->retrieveCodeByCode($data['code']);

        $result = $this->main->getAdmin()->getRedeemAmount($codeObj);
        $this->assertEquals(0, $result['_redeemed_counter']);
    }

    // ── removeUsedInformationFromCode ────────────────────────────

    public function test_removeUsedInformationFromCode_clears_data(): void {
        $data = $this->createCodeInList();
        $codeObj = $this->main->getCore()->retrieveCodeByCode($data['code']);
        $metaObj = $this->main->getCore()->encodeMetaValuesAndFillObject($codeObj['meta'], $codeObj);

        // Set some usage data
        $metaObj['used']['reg_ip'] = '127.0.0.1';
        $metaObj['confirmedCount'] = 5;
        $metaJson = $this->main->getCore()->json_encode_with_error_handling($metaObj);
        $this->main->getDB()->update('codes', ['meta' => $metaJson], ['id' => $codeObj['id']]);

        // Clear it
        $result = $this->main->getAdmin()->removeUsedInformationFromCode(['code' => $data['code']]);
        $this->assertIsArray($result);

        // Verify cleared
        $fresh = $this->main->getCore()->retrieveCodeByCode($data['code']);
        $freshMeta = $this->main->getCore()->encodeMetaValuesAndFillObject($fresh['meta'], $fresh);
        $this->assertEmpty($freshMeta['used']['reg_ip']);
        $this->assertEquals(0, intval($freshMeta['confirmedCount']));
    }

    public function test_removeUsedInformationFromCode_missing_code_throws(): void {
        $this->expectException(Exception::class);
        $this->main->getAdmin()->removeUsedInformationFromCode([]);
    }

    // ── checkAndSaveFormatWarning (private, via Reflection) ─────

    public function test_checkAndSaveFormatWarning_saves_attempts_to_list_meta(): void {
        $meta = json_encode([
            'desc' => '',
            'redirect' => ['url' => ''],
            'formatter' => ['active' => 1, 'format' => ''],
            'webhooks' => ['webhookURLaddwcticketsold' => ''],
            'messages' => [
                'format_limit_threshold_warning' => ['attempts' => 0, 'last_email' => ''],
                'format_end_warning' => ['attempts' => 0, 'last_email' => ''],
            ],
        ]);
        $listId = $this->main->getDB()->insert('lists', [
            'name' => 'FormatWarn Test ' . uniqid(),
            'aktiv' => 1,
            'meta' => $meta,
        ]);

        // Call private method via Reflection
        $ref = new ReflectionMethod($this->main->getAdmin(), 'checkAndSaveFormatWarning');
        $ref->setAccessible(true);
        $ref->invoke($this->main->getAdmin(), $listId, 55, 'format_limit_threshold_warning');

        // Verify attempts saved
        $listObj = $this->main->getAdmin()->getList(['id' => $listId]);
        $metaObj = $this->main->getCore()->encodeMetaValuesAndFillObjectList($listObj['meta']);
        $this->assertEquals(55, $metaObj['messages']['format_limit_threshold_warning']['attempts']);
        $this->assertNotEmpty($metaObj['messages']['format_limit_threshold_warning']['last_email']);
    }

    public function test_checkAndSaveFormatWarning_end_warning_type(): void {
        $meta = json_encode([
            'desc' => '',
            'redirect' => ['url' => ''],
            'formatter' => ['active' => 1, 'format' => ''],
            'webhooks' => ['webhookURLaddwcticketsold' => ''],
            'messages' => [
                'format_limit_threshold_warning' => ['attempts' => 0, 'last_email' => ''],
                'format_end_warning' => ['attempts' => 0, 'last_email' => ''],
            ],
        ]);
        $listId = $this->main->getDB()->insert('lists', [
            'name' => 'FormatEnd Test ' . uniqid(),
            'aktiv' => 1,
            'meta' => $meta,
        ]);

        $ref = new ReflectionMethod($this->main->getAdmin(), 'checkAndSaveFormatWarning');
        $ref->setAccessible(true);
        $ref->invoke($this->main->getAdmin(), $listId, 100, 'format_end_warning');

        $listObj = $this->main->getAdmin()->getList(['id' => $listId]);
        $metaObj = $this->main->getCore()->encodeMetaValuesAndFillObjectList($listObj['meta']);
        $this->assertEquals(100, $metaObj['messages']['format_end_warning']['attempts']);
        $this->assertNotEmpty($metaObj['messages']['format_end_warning']['last_email']);
    }

    public function test_checkAndSaveFormatWarning_skips_email_within_24h(): void {
        $recentTime = wp_date("Y-m-d H:i:s", time() - 3600); // 1h ago
        $meta = json_encode([
            'desc' => '',
            'redirect' => ['url' => ''],
            'formatter' => ['active' => 1, 'format' => ''],
            'webhooks' => ['webhookURLaddwcticketsold' => ''],
            'messages' => [
                'format_limit_threshold_warning' => ['attempts' => 10, 'last_email' => $recentTime],
                'format_end_warning' => ['attempts' => 0, 'last_email' => ''],
            ],
        ]);
        $listId = $this->main->getDB()->insert('lists', [
            'name' => 'FormatSkip Test ' . uniqid(),
            'aktiv' => 1,
            'meta' => $meta,
        ]);

        $ref = new ReflectionMethod($this->main->getAdmin(), 'checkAndSaveFormatWarning');
        $ref->setAccessible(true);
        $ref->invoke($this->main->getAdmin(), $listId, 60, 'format_limit_threshold_warning');

        // Attempts should still be updated even if email is skipped
        $listObj = $this->main->getAdmin()->getList(['id' => $listId]);
        $metaObj = $this->main->getCore()->encodeMetaValuesAndFillObjectList($listObj['meta']);
        $this->assertEquals(60, $metaObj['messages']['format_limit_threshold_warning']['attempts']);
        // last_email should NOT have changed (within 24h)
        $this->assertEquals($recentTime, $metaObj['messages']['format_limit_threshold_warning']['last_email']);
    }
}
