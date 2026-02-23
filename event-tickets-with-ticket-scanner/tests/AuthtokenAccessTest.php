<?php
/**
 * Tests for Authtoken methods: checkAccessForAuthtoken,
 * isProductAllowedByAuthToken, getAuthtokenByCode, getAuthtokens.
 */

class AuthtokenAccessTest extends WP_UnitTestCase {

    private $main;

    public function set_up(): void {
        parent::set_up();
        $this->main = sasoEventtickets::Instance();
    }

    private function createActiveAuthtoken(): array {
        $code = 'AUTH' . strtoupper(uniqid());
        $metaObj = $this->main->getCore()->getMetaObjectAuthtoken();
        $metaJson = $this->main->getCore()->json_encode_with_error_handling($metaObj);

        $id = $this->main->getDB()->insert('authtokens', [
            'code' => $code,
            'name' => 'Test Token ' . uniqid(),
            'aktiv' => 1,
            'meta' => $metaJson,
        ]);

        return ['id' => $id, 'code' => $code];
    }

    // ── checkAccessForAuthtoken ───────────────────────────────────

    public function test_checkAccessForAuthtoken_true_for_valid(): void {
        $token = $this->createActiveAuthtoken();
        $result = $this->main->getAuthtokenHandler()->checkAccessForAuthtoken($token['code']);
        $this->assertTrue($result);
    }

    public function test_checkAccessForAuthtoken_false_for_invalid(): void {
        $result = $this->main->getAuthtokenHandler()->checkAccessForAuthtoken('NONEXISTENT_' . uniqid());
        $this->assertFalse($result);
    }

    public function test_checkAccessForAuthtoken_false_for_empty(): void {
        $result = $this->main->getAuthtokenHandler()->checkAccessForAuthtoken('');
        $this->assertFalse($result);
    }

    public function test_checkAccessForAuthtoken_false_for_inactive(): void {
        $code = 'INACT' . strtoupper(uniqid());
        $metaObj = $this->main->getCore()->getMetaObjectAuthtoken();
        $metaJson = $this->main->getCore()->json_encode_with_error_handling($metaObj);

        $this->main->getDB()->insert('authtokens', [
            'code' => $code,
            'name' => 'Inactive Token',
            'aktiv' => 0,
            'meta' => $metaJson,
        ]);

        $result = $this->main->getAuthtokenHandler()->checkAccessForAuthtoken($code);
        $this->assertFalse($result);
    }

    // ── getAuthtokenByCode ───────────────────────────────────────

    public function test_getAuthtokenByCode_returns_array(): void {
        $token = $this->createActiveAuthtoken();
        $result = $this->main->getAuthtokenHandler()->getAuthtokenByCode($token['code']);
        $this->assertIsArray($result);
        $this->assertEquals($token['code'], $result['code']);
    }

    public function test_getAuthtokenByCode_throws_for_empty(): void {
        $this->expectException(Exception::class);
        $this->main->getAuthtokenHandler()->getAuthtokenByCode('');
    }

    public function test_getAuthtokenByCode_throws_for_nonexistent(): void {
        $this->expectException(Exception::class);
        $this->main->getAuthtokenHandler()->getAuthtokenByCode('NOTFOUND_' . uniqid());
    }

    // ── getAuthtokens ────────────────────────────────────────────

    public function test_getAuthtokens_returns_array(): void {
        $result = $this->main->getAuthtokenHandler()->getAuthtokens();
        $this->assertIsArray($result);
    }

    public function test_getAuthtokens_contains_created_token(): void {
        $token = $this->createActiveAuthtoken();
        $tokens = $this->main->getAuthtokenHandler()->getAuthtokens();

        $found = false;
        foreach ($tokens as $t) {
            if ($t['code'] === $token['code']) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, "Created authtoken not found in getAuthtokens()");
    }

    // ── isProductAllowedByAuthToken ──────────────────────────────

    public function test_isProductAllowedByAuthToken_true_no_restriction(): void {
        $token = $this->createActiveAuthtoken();
        // No bound_to_products set → all products allowed
        $result = $this->main->getAuthtokenHandler()->isProductAllowedByAuthToken($token['code'], [42]);
        $this->assertTrue($result);
    }

    public function test_isProductAllowedByAuthToken_true_empty_products(): void {
        $token = $this->createActiveAuthtoken();
        $result = $this->main->getAuthtokenHandler()->isProductAllowedByAuthToken($token['code'], []);
        $this->assertTrue($result);
    }

    public function test_isProductAllowedByAuthToken_true_for_allowed_product(): void {
        $code = 'PROD' . strtoupper(uniqid());
        $metaObj = $this->main->getCore()->getMetaObjectAuthtoken();
        $metaObj['ticketscanner']['bound_to_products'] = '42,100,200';
        $metaJson = $this->main->getCore()->json_encode_with_error_handling($metaObj);

        $this->main->getDB()->insert('authtokens', [
            'code' => $code,
            'name' => 'Restricted Token',
            'aktiv' => 1,
            'meta' => $metaJson,
        ]);

        $result = $this->main->getAuthtokenHandler()->isProductAllowedByAuthToken($code, [42]);
        $this->assertTrue($result);
    }

    public function test_isProductAllowedByAuthToken_false_for_disallowed_product(): void {
        $code = 'DENY' . strtoupper(uniqid());
        $metaObj = $this->main->getCore()->getMetaObjectAuthtoken();
        $metaObj['ticketscanner']['bound_to_products'] = '42,100';
        $metaJson = $this->main->getCore()->json_encode_with_error_handling($metaObj);

        $this->main->getDB()->insert('authtokens', [
            'code' => $code,
            'name' => 'Restricted Token',
            'aktiv' => 1,
            'meta' => $metaJson,
        ]);

        $result = $this->main->getAuthtokenHandler()->isProductAllowedByAuthToken($code, [999]);
        $this->assertFalse($result);
    }
}
