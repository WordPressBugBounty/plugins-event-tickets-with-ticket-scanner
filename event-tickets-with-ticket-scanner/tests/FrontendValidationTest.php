<?php
/**
 * Tests for frontend code validation (checkCode, isUsed, markAsUsed).
 */

class FrontendValidationTest extends WP_UnitTestCase {

    private $main;

    public function set_up(): void {
        parent::set_up();
        $this->main = sasoEventtickets::Instance();
    }

    /**
     * Helper: create a list with a code.
     */
    private function createCodeInList(string $codeStr, bool $active = true): array {
        $listId = $this->main->getDB()->insert('lists', [
            'name' => 'FV List ' . uniqid(),
            'aktiv' => 1,
            'meta' => '{}',
        ]);

        $metaObj = $this->main->getCore()->getMetaObject();
        $metaJson = $this->main->getCore()->json_encode_with_error_handling($metaObj);

        $codeId = $this->main->getDB()->insert('codes', [
            'code' => $codeStr,
            'code_display' => $codeStr,
            'cvv' => '',
            'meta' => $metaJson,
            'aktiv' => $active ? 1 : 0,
            'redeemed' => 0,
            'list_id' => $listId,
            'order_id' => 0,
        ]);

        return ['list_id' => $listId, 'code_id' => $codeId, 'code' => $codeStr];
    }

    // ── isUsed ───────────────────────────────────────────────────

    public function test_isUsed_returns_false_for_fresh_code(): void {
        $data = $this->createCodeInList('FRESH' . uniqid());
        $codeObj = $this->main->getCore()->retrieveCodeByCode($data['code']);

        $this->assertFalse($this->main->getFrontend()->isUsed($codeObj));
    }

    public function test_isUsed_returns_true_when_reg_request_set(): void {
        $code = 'USED' . strtoupper(uniqid());
        $data = $this->createCodeInList($code);
        $codeObj = $this->main->getCore()->retrieveCodeByCode($code);

        // Set the used marker in meta
        $metaObj = $this->main->getCore()->encodeMetaValuesAndFillObject($codeObj['meta'], $codeObj);
        $metaObj['used']['reg_request'] = wp_date('Y-m-d H:i:s');
        $metaJson = $this->main->getCore()->json_encode_with_error_handling($metaObj);
        $this->main->getDB()->update('codes', ['meta' => $metaJson], ['id' => $codeObj['id']]);

        // Re-retrieve
        $codeObj = $this->main->getCore()->retrieveCodeByCode($code);
        $this->assertTrue($this->main->getFrontend()->isUsed($codeObj));
    }

    // ── checkCode ────────────────────────────────────────────────

    public function test_checkCode_valid_code_returns_1(): void {
        $code = 'CHECK' . strtoupper(uniqid());
        $this->createCodeInList($code);

        $result = $this->main->getFrontend()->checkCode(['code' => $code]);
        $this->assertIsArray($result);
        $this->assertArrayHasKey('valid', $result);
        // valid=1 means found and active
        $this->assertEquals(1, $result['valid']);
    }

    public function test_checkCode_nonexistent_code_returns_0(): void {
        $result = $this->main->getFrontend()->checkCode(['code' => 'NONEXIST_' . uniqid()]);
        $this->assertEquals(0, $result['valid']);
    }

    public function test_checkCode_inactive_code_returns_2(): void {
        $code = 'INACTIVE' . strtoupper(uniqid());
        $this->createCodeInList($code, false);

        $result = $this->main->getFrontend()->checkCode(['code' => $code]);
        $this->assertEquals(2, $result['valid']);
    }

    public function test_checkCode_missing_code_throws(): void {
        $this->expectException(Exception::class);
        $this->main->getFrontend()->checkCode(['code' => '']);
    }

    public function test_checkCode_no_code_parameter_throws(): void {
        $this->expectException(Exception::class);
        $this->main->getFrontend()->checkCode([]);
    }

    // ── checkCode with CVV ───────────────────────────────────────

    public function test_checkCode_with_cvv_no_cvv_provided_returns_6(): void {
        $code = 'CVV' . strtoupper(uniqid());
        $listId = $this->main->getDB()->insert('lists', [
            'name' => 'CVV List ' . uniqid(),
            'aktiv' => 1,
            'meta' => '{}',
        ]);

        $metaObj = $this->main->getCore()->getMetaObject();
        $metaJson = $this->main->getCore()->json_encode_with_error_handling($metaObj);

        $this->main->getDB()->insert('codes', [
            'code' => $code,
            'code_display' => $code,
            'cvv' => 'SECRET',
            'meta' => $metaJson,
            'aktiv' => 1,
            'redeemed' => 0,
            'list_id' => $listId,
            'order_id' => 0,
        ]);

        // Without CVV, should return 6 (ask for CVV)
        $result = $this->main->getFrontend()->checkCode(['code' => $code]);
        $this->assertEquals(6, $result['valid']);
    }

    public function test_checkCode_with_correct_cvv_returns_1(): void {
        $code = 'CVVOK' . strtoupper(uniqid());
        $listId = $this->main->getDB()->insert('lists', [
            'name' => 'CVV OK List ' . uniqid(),
            'aktiv' => 1,
            'meta' => '{}',
        ]);

        $metaObj = $this->main->getCore()->getMetaObject();
        $metaJson = $this->main->getCore()->json_encode_with_error_handling($metaObj);

        $this->main->getDB()->insert('codes', [
            'code' => $code,
            'code_display' => $code,
            'cvv' => 'SECRET',
            'meta' => $metaJson,
            'aktiv' => 1,
            'redeemed' => 0,
            'list_id' => $listId,
            'order_id' => 0,
        ]);

        $result = $this->main->getFrontend()->checkCode(['code' => $code, 'cvv' => 'SECRET']);
        $this->assertEquals(1, $result['valid']);
    }

    public function test_checkCode_with_wrong_cvv_returns_6(): void {
        $code = 'CVVBAD' . strtoupper(uniqid());
        $listId = $this->main->getDB()->insert('lists', [
            'name' => 'CVV Bad List ' . uniqid(),
            'aktiv' => 1,
            'meta' => '{}',
        ]);

        $metaObj = $this->main->getCore()->getMetaObject();
        $metaJson = $this->main->getCore()->json_encode_with_error_handling($metaObj);

        $this->main->getDB()->insert('codes', [
            'code' => $code,
            'code_display' => $code,
            'cvv' => 'SECRET',
            'meta' => $metaJson,
            'aktiv' => 1,
            'redeemed' => 0,
            'list_id' => $listId,
            'order_id' => 0,
        ]);

        $result = $this->main->getFrontend()->checkCode(['code' => $code, 'cvv' => 'WRONG']);
        $this->assertEquals(6, $result['valid']);
    }

    // ── checkCode expired (premium-only feature) ────────────────

    public function test_checkCodeExpired_returns_false_in_free_version(): void {
        // checkCodeExpired only works with premium plugin
        $code = 'EXP' . strtoupper(uniqid());
        $listId = $this->main->getDB()->insert('lists', [
            'name' => 'Expired List ' . uniqid(),
            'aktiv' => 1,
            'meta' => '{}',
        ]);

        $metaObj = $this->main->getCore()->getMetaObject();
        $metaObj['expire_date'] = '2020-01-01';
        $metaJson = $this->main->getCore()->json_encode_with_error_handling($metaObj);

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
        // In free version, checkCodeExpired always returns false
        $this->assertFalse($this->main->getCore()->checkCodeExpired($codeObj));
    }

    // ── checkCode stolen ─────────────────────────────────────────

    public function test_checkCode_stolen_code_returns_7(): void {
        $code = 'STOLEN' . strtoupper(uniqid());
        $listId = $this->main->getDB()->insert('lists', [
            'name' => 'Stolen List ' . uniqid(),
            'aktiv' => 1,
            'meta' => '{}',
        ]);

        $metaObj = $this->main->getCore()->getMetaObject();
        $metaJson = $this->main->getCore()->json_encode_with_error_handling($metaObj);

        $this->main->getDB()->insert('codes', [
            'code' => $code,
            'code_display' => $code,
            'cvv' => '',
            'meta' => $metaJson,
            'aktiv' => 2, // 2 = stolen
            'redeemed' => 0,
            'list_id' => $listId,
            'order_id' => 0,
        ]);

        $result = $this->main->getFrontend()->checkCode(['code' => $code]);
        $this->assertEquals(7, $result['valid']);
    }
}
