<?php
/**
 * Tests for Authtoken CRUD operations: addAuthtoken, getAuthtoken, getAuthtokens,
 * editAuthtoken, removeAuthtoken, getAuthtokenByCode.
 */

class AuthtokenCRUDTest extends WP_UnitTestCase {

    private $main;

    public function set_up(): void {
        parent::set_up();
        $this->main = sasoEventtickets::Instance();
    }

    // ── addAuthtoken ────────────────────────────────────────────

    public function test_addAuthtoken_creates_token(): void {
        $handler = $this->main->getAuthtokenHandler();
        $name = 'Test Token ' . uniqid();
        $id = $handler->addAuthtoken(['name' => $name]);

        $this->assertIsInt($id);
        $this->assertGreaterThan(0, $id);

        // Verify it exists
        $token = $handler->getAuthtoken(['id' => $id]);
        $this->assertEquals($name, $token['name']);
        $this->assertEquals(1, intval($token['aktiv']));
    }

    public function test_addAuthtoken_missing_name_throws(): void {
        $handler = $this->main->getAuthtokenHandler();
        $this->expectException(Exception::class);
        $handler->addAuthtoken([]);
    }

    public function test_addAuthtoken_empty_name_throws(): void {
        $handler = $this->main->getAuthtokenHandler();
        $this->expectException(Exception::class);
        $handler->addAuthtoken(['name' => '']);
    }

    public function test_addAuthtoken_generates_unique_code(): void {
        $handler = $this->main->getAuthtokenHandler();
        $id1 = $handler->addAuthtoken(['name' => 'Token A ' . uniqid()]);
        $id2 = $handler->addAuthtoken(['name' => 'Token B ' . uniqid()]);

        $token1 = $handler->getAuthtoken(['id' => $id1]);
        $token2 = $handler->getAuthtoken(['id' => $id2]);

        $this->assertNotEquals($token1['code'], $token2['code']);
    }

    public function test_addAuthtoken_strips_html(): void {
        $handler = $this->main->getAuthtokenHandler();
        $id = $handler->addAuthtoken(['name' => '<script>evil</script>Clean Name']);

        $token = $handler->getAuthtoken(['id' => $id]);
        $this->assertStringNotContainsString('<script>', $token['name']);
        $this->assertStringContainsString('Clean Name', $token['name']);
    }

    public function test_addAuthtoken_inactive(): void {
        $handler = $this->main->getAuthtokenHandler();
        $id = $handler->addAuthtoken(['name' => 'Inactive ' . uniqid(), 'aktiv' => 0]);

        $token = $handler->getAuthtoken(['id' => $id]);
        $this->assertEquals(0, intval($token['aktiv']));
    }

    // ── getAuthtoken ────────────────────────────────────────────

    public function test_getAuthtoken_returns_token(): void {
        $handler = $this->main->getAuthtokenHandler();
        $id = $handler->addAuthtoken(['name' => 'Get Test ' . uniqid()]);

        $token = $handler->getAuthtoken(['id' => $id]);
        $this->assertIsArray($token);
        $this->assertEquals($id, intval($token['id']));
    }

    public function test_getAuthtoken_missing_id_throws(): void {
        $handler = $this->main->getAuthtokenHandler();
        $this->expectException(Exception::class);
        $handler->getAuthtoken([]);
    }

    public function test_getAuthtoken_invalid_id_throws(): void {
        $handler = $this->main->getAuthtokenHandler();
        $this->expectException(Exception::class);
        $handler->getAuthtoken(['id' => 999999]);
    }

    // ── getAuthtokens ───────────────────────────────────────────

    public function test_getAuthtokens_returns_all(): void {
        $handler = $this->main->getAuthtokenHandler();
        $handler->addAuthtoken(['name' => 'List Test A ' . uniqid()]);
        $handler->addAuthtoken(['name' => 'List Test B ' . uniqid()]);

        $tokens = $handler->getAuthtokens();
        $this->assertIsArray($tokens);
        $this->assertGreaterThanOrEqual(2, count($tokens));

        // Each token should have metaObj
        foreach ($tokens as $t) {
            $this->assertArrayHasKey('metaObj', $t);
            $this->assertIsArray($t['metaObj']);
        }
    }

    // ── getAuthtokenByCode ──────────────────────────────────────

    public function test_getAuthtokenByCode_returns_token(): void {
        $handler = $this->main->getAuthtokenHandler();
        $id = $handler->addAuthtoken(['name' => 'ByCode Test ' . uniqid()]);
        $token = $handler->getAuthtoken(['id' => $id]);

        $found = $handler->getAuthtokenByCode($token['code']);
        $this->assertEquals($id, intval($found['id']));
    }

    public function test_getAuthtokenByCode_empty_throws(): void {
        $handler = $this->main->getAuthtokenHandler();
        $this->expectException(Exception::class);
        $handler->getAuthtokenByCode('');
    }

    public function test_getAuthtokenByCode_invalid_throws(): void {
        $handler = $this->main->getAuthtokenHandler();
        $this->expectException(Exception::class);
        $handler->getAuthtokenByCode('NONEXISTENT_CODE_' . uniqid());
    }

    public function test_getAuthtokenByCode_inactive_throws(): void {
        $handler = $this->main->getAuthtokenHandler();
        $id = $handler->addAuthtoken(['name' => 'Inactive ByCode ' . uniqid(), 'aktiv' => 0]);
        $token = $handler->getAuthtoken(['id' => $id]);

        $this->expectException(Exception::class);
        $handler->getAuthtokenByCode($token['code']);
    }

    // ── editAuthtoken ───────────────────────────────────────────

    public function test_editAuthtoken_updates_name(): void {
        $handler = $this->main->getAuthtokenHandler();
        $id = $handler->addAuthtoken(['name' => 'Old Name ' . uniqid()]);

        $newName = 'New Name ' . uniqid();
        $handler->editAuthtoken(['id' => $id, 'name' => $newName]);

        $token = $handler->getAuthtoken(['id' => $id]);
        $this->assertEquals($newName, $token['name']);
    }

    public function test_editAuthtoken_toggles_aktiv(): void {
        $handler = $this->main->getAuthtokenHandler();
        $id = $handler->addAuthtoken(['name' => 'Toggle ' . uniqid()]);

        $handler->editAuthtoken(['id' => $id, 'aktiv' => 0]);
        $token = $handler->getAuthtoken(['id' => $id]);
        $this->assertEquals(0, intval($token['aktiv']));

        $handler->editAuthtoken(['id' => $id, 'aktiv' => 1]);
        $token = $handler->getAuthtoken(['id' => $id]);
        $this->assertEquals(1, intval($token['aktiv']));
    }

    public function test_editAuthtoken_missing_id_throws(): void {
        $handler = $this->main->getAuthtokenHandler();
        $this->expectException(Exception::class);
        $handler->editAuthtoken([]);
    }

    public function test_editAuthtoken_empty_name_throws(): void {
        $handler = $this->main->getAuthtokenHandler();
        $id = $handler->addAuthtoken(['name' => 'EmptyEdit ' . uniqid()]);

        $this->expectException(Exception::class);
        $handler->editAuthtoken(['id' => $id, 'name' => '']);
    }

    // ── removeAuthtoken ─────────────────────────────────────────

    public function test_removeAuthtoken_deletes(): void {
        $handler = $this->main->getAuthtokenHandler();
        $id = $handler->addAuthtoken(['name' => 'Remove Test ' . uniqid()]);

        // Verify exists
        $token = $handler->getAuthtoken(['id' => $id]);
        $this->assertNotNull($token);

        // Delete
        $handler->removeAuthtoken(['id' => $id]);

        // Should be gone
        $this->expectException(Exception::class);
        $handler->getAuthtoken(['id' => $id]);
    }

    public function test_removeAuthtoken_missing_id_throws(): void {
        $handler = $this->main->getAuthtokenHandler();
        $this->expectException(Exception::class);
        $handler->removeAuthtoken([]);
    }
}
