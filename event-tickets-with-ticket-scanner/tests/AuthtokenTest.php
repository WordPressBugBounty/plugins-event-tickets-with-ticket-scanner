<?php
/**
 * Integration tests for the Authtoken system (scanner access control).
 */

class AuthtokenTest extends WP_UnitTestCase {

    private sasoEventtickets_Authtoken $auth;

    public function set_up(): void {
        parent::set_up();
        $this->auth = sasoEventtickets::Instance()->getAuthtokenHandler();
    }

    // ── addAuthtoken ───────────────────────────────────────────

    public function test_addAuthtoken_returns_id(): void {
        $id = $this->auth->addAuthtoken(['name' => 'PHPUnit Token ' . uniqid()]);
        $this->assertGreaterThan(0, $id);
    }

    public function test_addAuthtoken_throws_without_name(): void {
        $this->expectException(Exception::class);
        $this->auth->addAuthtoken([]);
    }

    public function test_addAuthtoken_throws_with_empty_name(): void {
        $this->expectException(Exception::class);
        $this->auth->addAuthtoken(['name' => '']);
    }

    // ── getAuthtokens ──────────────────────────────────────────

    public function test_getAuthtokens_returns_array(): void {
        $this->auth->addAuthtoken(['name' => 'List Test ' . uniqid()]);
        $tokens = $this->auth->getAuthtokens();
        $this->assertIsArray($tokens);
        $this->assertNotEmpty($tokens);
    }

    // ── getAuthtokenByCode ─────────────────────────────────────

    public function test_getAuthtokenByCode_finds_token(): void {
        $id = $this->auth->addAuthtoken(['name' => 'ByCode Test ' . uniqid()]);

        // Get all to find the code
        $tokens = $this->auth->getAuthtokens();
        $code = null;
        foreach ($tokens as $t) {
            if ((int)$t['id'] === $id) {
                $code = $t['code'];
                break;
            }
        }
        $this->assertNotNull($code);

        $found = $this->auth->getAuthtokenByCode($code);
        $this->assertEquals($id, $found['id']);
    }

    public function test_getAuthtokenByCode_throws_for_invalid(): void {
        $this->expectException(Exception::class);
        $this->auth->getAuthtokenByCode('nonexistent_code_xyz_' . uniqid());
    }

    public function test_getAuthtokenByCode_throws_for_empty(): void {
        $this->expectException(Exception::class);
        $this->auth->getAuthtokenByCode('');
    }

    // ── checkAccessForAuthtoken ────────────────────────────────

    public function test_checkAccess_valid_token(): void {
        $id = $this->auth->addAuthtoken(['name' => 'Access Test ' . uniqid()]);
        $tokens = $this->auth->getAuthtokens();
        $code = null;
        foreach ($tokens as $t) {
            if ((int)$t['id'] === $id) {
                $code = $t['code'];
                break;
            }
        }

        $this->assertTrue($this->auth->checkAccessForAuthtoken($code));
    }

    public function test_checkAccess_invalid_token_returns_false(): void {
        $this->assertFalse($this->auth->checkAccessForAuthtoken('invalid_code_' . uniqid()));
    }

    public function test_checkAccess_empty_returns_false(): void {
        $this->assertFalse($this->auth->checkAccessForAuthtoken(''));
    }

    // ── editAuthtoken ──────────────────────────────────────────

    public function test_editAuthtoken_changes_name(): void {
        $id = $this->auth->addAuthtoken(['name' => 'Before Edit']);
        $newName = 'After Edit ' . uniqid();
        $this->auth->editAuthtoken(['id' => $id, 'name' => $newName]);

        $token = $this->auth->getAuthtoken(['id' => $id]);
        $this->assertSame($newName, $token['name']);
    }

    public function test_editAuthtoken_deactivate(): void {
        $id = $this->auth->addAuthtoken(['name' => 'Deactivate Test ' . uniqid()]);

        // Deactivate
        $this->auth->editAuthtoken(['id' => $id, 'aktiv' => 0]);

        // getAuthtokenByCode should fail (only finds aktiv=1)
        $tokens = $this->auth->getAuthtokens();
        $found = null;
        foreach ($tokens as $t) {
            if ((int)$t['id'] === $id) {
                $found = $t;
                break;
            }
        }
        $this->assertNotNull($found);
        $this->assertEquals(0, $found['aktiv']);
    }

    public function test_editAuthtoken_throws_without_id(): void {
        $this->expectException(Exception::class);
        $this->auth->editAuthtoken(['name' => 'No ID']);
    }

    // ── removeAuthtoken ────────────────────────────────────────

    public function test_removeAuthtoken_deletes(): void {
        $id = $this->auth->addAuthtoken(['name' => 'Remove Test ' . uniqid()]);

        $this->auth->removeAuthtoken(['id' => $id]);

        // Should not be in list anymore
        $tokens = $this->auth->getAuthtokens();
        $ids = array_column($tokens, 'id');
        $this->assertNotContains($id, $ids);
    }

    public function test_removeAuthtoken_throws_without_id(): void {
        $this->expectException(Exception::class);
        $this->auth->removeAuthtoken([]);
    }

    // ── isProductAllowedByAuthToken ────────────────────────────

    public function test_isProductAllowed_no_restriction_allows_all(): void {
        $id = $this->auth->addAuthtoken(['name' => 'No Restrict ' . uniqid()]);
        $tokens = $this->auth->getAuthtokens();
        $code = null;
        foreach ($tokens as $t) {
            if ((int)$t['id'] === $id) {
                $code = $t['code'];
                break;
            }
        }

        // No bound_to_products set → should allow any product
        $this->assertTrue($this->auth->isProductAllowedByAuthToken($code, [123, 456]));
    }

    public function test_isProductAllowed_empty_product_ids_allows(): void {
        $id = $this->auth->addAuthtoken(['name' => 'Empty IDs ' . uniqid()]);
        $tokens = $this->auth->getAuthtokens();
        $code = null;
        foreach ($tokens as $t) {
            if ((int)$t['id'] === $id) {
                $code = $t['code'];
                break;
            }
        }

        $this->assertTrue($this->auth->isProductAllowedByAuthToken($code, []));
    }
}
