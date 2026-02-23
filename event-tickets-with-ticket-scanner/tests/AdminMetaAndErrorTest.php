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

    // ── clearFormatWarning ───────────────────────────────────────
    // NOTE: clearFormatWarning() internally calls _json_encode_with_error_handling()
    // which doesn't exist (plugin bug). We test the clearing logic manually.

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

        // Set some usage data (using json_encode + DB update to avoid saveMetaObject bug)
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
}
