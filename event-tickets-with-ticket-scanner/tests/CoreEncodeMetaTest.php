<?php
/**
 * Tests for Core methods: encodeMetaValuesAndFillObject,
 * encodeMetaValuesAndFillObjectAuthtoken.
 */

class CoreEncodeMetaTest extends WP_UnitTestCase {

    private $main;

    public function set_up(): void {
        parent::set_up();
        $this->main = sasoEventtickets::Instance();
    }

    private function createCodeInList(): array {
        $listId = $this->main->getDB()->insert('lists', [
            'name' => 'EncodeMeta Test ' . uniqid(),
            'aktiv' => 1,
            'meta' => '{}',
        ]);

        $metaObj = $this->main->getCore()->getMetaObject();
        $metaJson = $this->main->getCore()->json_encode_with_error_handling($metaObj);

        $code = 'ENC' . strtoupper(uniqid());
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

    // ── encodeMetaValuesAndFillObject ────────────────────────────

    public function test_encodeMetaValuesAndFillObject_returns_array(): void {
        $data = $this->createCodeInList();
        $codeObj = $this->main->getCore()->retrieveCodeByCode($data['code']);

        $result = $this->main->getCore()->encodeMetaValuesAndFillObject($codeObj['meta'], $codeObj);
        $this->assertIsArray($result);
    }

    public function test_encodeMetaValuesAndFillObject_has_default_keys(): void {
        $data = $this->createCodeInList();
        $codeObj = $this->main->getCore()->retrieveCodeByCode($data['code']);

        $result = $this->main->getCore()->encodeMetaValuesAndFillObject($codeObj['meta'], $codeObj);
        $this->assertArrayHasKey('user', $result);
        $this->assertArrayHasKey('used', $result);
        $this->assertArrayHasKey('wc_ticket', $result);
        $this->assertArrayHasKey('woocommerce', $result);
        $this->assertArrayHasKey('confirmedCount', $result);
        $this->assertArrayHasKey('validation', $result);
    }

    public function test_encodeMetaValuesAndFillObject_resolves_empty_usernames(): void {
        $data = $this->createCodeInList();
        $codeObj = $this->main->getCore()->retrieveCodeByCode($data['code']);

        $result = $this->main->getCore()->encodeMetaValuesAndFillObject($codeObj['meta'], $codeObj);
        // No user registered, so _reg_username should be empty
        $this->assertEquals('', $result['user']['_reg_username']);
        $this->assertEquals('', $result['used']['_reg_username']);
        $this->assertEquals('', $result['wc_ticket']['_username']);
    }

    public function test_encodeMetaValuesAndFillObject_resolves_real_user(): void {
        $userId = self::factory()->user->create([
            'first_name' => 'TestFirst',
            'last_name' => 'TestLast',
            'user_login' => 'testencodeuser_' . uniqid(),
        ]);

        $data = $this->createCodeInList();
        $codeObj = $this->main->getCore()->retrieveCodeByCode($data['code']);
        $metaObj = $this->main->getCore()->getMetaObject();
        $metaObj['user']['reg_userid'] = $userId;
        $metaJson = $this->main->getCore()->json_encode_with_error_handling($metaObj);

        $result = $this->main->getCore()->encodeMetaValuesAndFillObject($metaJson, $codeObj);
        $this->assertStringContainsString('TestFirst', $result['user']['_reg_username']);
        $this->assertStringContainsString('TestLast', $result['user']['_reg_username']);
    }

    public function test_encodeMetaValuesAndFillObject_invalid_userid_shows_error(): void {
        $data = $this->createCodeInList();
        $codeObj = $this->main->getCore()->retrieveCodeByCode($data['code']);
        $metaObj = $this->main->getCore()->getMetaObject();
        $metaObj['user']['reg_userid'] = 999999;
        $metaJson = $this->main->getCore()->json_encode_with_error_handling($metaObj);

        $result = $this->main->getCore()->encodeMetaValuesAndFillObject($metaJson, $codeObj);
        $this->assertStringContainsString('NOT EXISTS', $result['user']['_reg_username']);
    }

    public function test_encodeMetaValuesAndFillObject_without_codeObj(): void {
        $metaObj = $this->main->getCore()->getMetaObject();
        $metaJson = $this->main->getCore()->json_encode_with_error_handling($metaObj);

        $result = $this->main->getCore()->encodeMetaValuesAndFillObject($metaJson);
        $this->assertIsArray($result);
        $this->assertArrayHasKey('user', $result);
    }

    public function test_encodeMetaValuesAndFillObject_fills_validation_from_used(): void {
        $data = $this->createCodeInList();
        $codeObj = $this->main->getCore()->retrieveCodeByCode($data['code']);
        $metaObj = $this->main->getCore()->getMetaObject();
        $metaObj['confirmedCount'] = 3;
        $metaObj['used']['reg_request'] = '2026-01-15 10:00:00';
        $metaObj['used']['reg_request_tz'] = 'Europe/Berlin';
        $metaObj['used']['reg_ip'] = '10.0.0.1';
        $metaJson = $this->main->getCore()->json_encode_with_error_handling($metaObj);

        $result = $this->main->getCore()->encodeMetaValuesAndFillObject($metaJson, $codeObj);
        $this->assertEquals('2026-01-15 10:00:00', $result['validation']['first_success']);
        $this->assertEquals('Europe/Berlin', $result['validation']['first_success_tz']);
        $this->assertEquals('10.0.0.1', $result['validation']['first_ip']);
    }

    public function test_encodeMetaValuesAndFillObject_preserves_stored_values(): void {
        $data = $this->createCodeInList();
        $codeObj = $this->main->getCore()->retrieveCodeByCode($data['code']);
        $metaObj = $this->main->getCore()->getMetaObject();
        $metaObj['user']['value'] = 'stored_user@example.com';
        $metaObj['confirmedCount'] = 7;
        $metaJson = $this->main->getCore()->json_encode_with_error_handling($metaObj);

        $result = $this->main->getCore()->encodeMetaValuesAndFillObject($metaJson, $codeObj);
        $this->assertEquals('stored_user@example.com', $result['user']['value']);
        $this->assertEquals(7, $result['confirmedCount']);
    }

    // ── encodeMetaValuesAndFillObjectAuthtoken ───────────────────

    public function test_encodeMetaValuesAndFillObjectAuthtoken_returns_array(): void {
        $metaObj = $this->main->getCore()->getMetaObjectAuthtoken();
        $metaJson = $this->main->getCore()->json_encode_with_error_handling($metaObj);

        $result = $this->main->getCore()->encodeMetaValuesAndFillObjectAuthtoken($metaJson);
        $this->assertIsArray($result);
    }

    public function test_encodeMetaValuesAndFillObjectAuthtoken_fills_defaults(): void {
        $result = $this->main->getCore()->encodeMetaValuesAndFillObjectAuthtoken('{}');
        $this->assertIsArray($result);
        $this->assertArrayHasKey('desc', $result);
        $this->assertArrayHasKey('ticketscanner', $result);
    }

    public function test_encodeMetaValuesAndFillObjectAuthtoken_preserves_values(): void {
        $meta = json_encode(['desc' => 'Test description', 'ticketscanner' => ['bound_to_products' => '42']]);
        $result = $this->main->getCore()->encodeMetaValuesAndFillObjectAuthtoken($meta);
        $this->assertEquals('Test description', $result['desc']);
        $this->assertEquals('42', $result['ticketscanner']['bound_to_products']);
    }

    public function test_encodeMetaValuesAndFillObjectAuthtoken_empty_string(): void {
        $result = $this->main->getCore()->encodeMetaValuesAndFillObjectAuthtoken('');
        $this->assertIsArray($result);
    }
}
