<?php
/**
 * Tests for Authtoken CRUD methods: getAuthtoken, editAuthtoken,
 * addAuthtoken, removeAuthtoken.
 */

class AuthtokenEditAndRemoveTest extends WP_UnitTestCase {

    private $main;

    public function set_up(): void {
        parent::set_up();
        $this->main = sasoEventtickets::Instance();
    }

    private function createAuthtoken(string $name = ''): int {
        if (empty($name)) {
            $name = 'EditTest Token ' . uniqid();
        }
        return $this->main->getAuthtokenHandler()->addAuthtoken(['name' => $name]);
    }

    // ── getAuthtoken ─────────────────────────────────────────────

    public function test_getAuthtoken_returns_array(): void {
        $id = $this->createAuthtoken();
        $result = $this->main->getAuthtokenHandler()->getAuthtoken(['id' => $id]);
        $this->assertIsArray($result);
        $this->assertEquals($id, intval($result['id']));
    }

    public function test_getAuthtoken_has_expected_keys(): void {
        $id = $this->createAuthtoken();
        $result = $this->main->getAuthtokenHandler()->getAuthtoken(['id' => $id]);
        $this->assertArrayHasKey('name', $result);
        $this->assertArrayHasKey('code', $result);
        $this->assertArrayHasKey('aktiv', $result);
        $this->assertArrayHasKey('meta', $result);
    }

    public function test_getAuthtoken_throws_without_id(): void {
        $this->expectException(Exception::class);
        $this->main->getAuthtokenHandler()->getAuthtoken([]);
    }

    public function test_getAuthtoken_throws_for_nonexistent(): void {
        $this->expectException(Exception::class);
        $this->main->getAuthtokenHandler()->getAuthtoken(['id' => 999999]);
    }

    // ── editAuthtoken ────────────────────────────────────────────

    public function test_editAuthtoken_updates_name(): void {
        $id = $this->createAuthtoken();
        $newName = 'Updated Token ' . uniqid();

        $this->main->getAuthtokenHandler()->editAuthtoken([
            'id' => $id,
            'name' => $newName,
        ]);

        $token = $this->main->getAuthtokenHandler()->getAuthtoken(['id' => $id]);
        $this->assertEquals($newName, $token['name']);
    }

    public function test_editAuthtoken_updates_aktiv(): void {
        $id = $this->createAuthtoken();

        $this->main->getAuthtokenHandler()->editAuthtoken([
            'id' => $id,
            'aktiv' => 0,
        ]);

        $token = $this->main->getAuthtokenHandler()->getAuthtoken(['id' => $id]);
        $this->assertEquals(0, intval($token['aktiv']));
    }

    public function test_editAuthtoken_throws_without_id(): void {
        $this->expectException(Exception::class);
        $this->main->getAuthtokenHandler()->editAuthtoken([]);
    }

    public function test_editAuthtoken_throws_with_empty_name(): void {
        $id = $this->createAuthtoken();
        $this->expectException(Exception::class);
        $this->main->getAuthtokenHandler()->editAuthtoken([
            'id' => $id,
            'name' => '',
        ]);
    }

    public function test_editAuthtoken_sets_changed_timestamp(): void {
        $id = $this->createAuthtoken();

        $this->main->getAuthtokenHandler()->editAuthtoken([
            'id' => $id,
            'aktiv' => 1,
        ]);

        $token = $this->main->getAuthtokenHandler()->getAuthtoken(['id' => $id]);
        $this->assertNotEmpty($token['changed']);
    }

    // ── addAuthtoken ─────────────────────────────────────────────

    public function test_addAuthtoken_returns_positive_id(): void {
        $id = $this->createAuthtoken();
        $this->assertGreaterThan(0, $id);
    }

    public function test_addAuthtoken_throws_without_name(): void {
        $this->expectException(Exception::class);
        $this->main->getAuthtokenHandler()->addAuthtoken([]);
    }

    public function test_addAuthtoken_throws_with_empty_name(): void {
        $this->expectException(Exception::class);
        $this->main->getAuthtokenHandler()->addAuthtoken(['name' => '']);
    }

    public function test_addAuthtoken_creates_code(): void {
        $id = $this->createAuthtoken();
        $token = $this->main->getAuthtokenHandler()->getAuthtoken(['id' => $id]);
        $this->assertNotEmpty($token['code']);
    }

    public function test_addAuthtoken_with_meta_description(): void {
        $id = $this->main->getAuthtokenHandler()->addAuthtoken([
            'name' => 'Meta Token ' . uniqid(),
            'meta' => ['desc' => 'Test description'],
        ]);

        $token = $this->main->getAuthtokenHandler()->getAuthtoken(['id' => $id]);
        $metaObj = json_decode($token['meta'], true);
        $this->assertEquals('Test description', $metaObj['desc']);
    }

    // ── removeAuthtoken ──────────────────────────────────────────

    public function test_removeAuthtoken_deletes_token(): void {
        $id = $this->createAuthtoken();

        // Verify it exists
        $token = $this->main->getAuthtokenHandler()->getAuthtoken(['id' => $id]);
        $this->assertNotEmpty($token);

        // Remove it
        $this->main->getAuthtokenHandler()->removeAuthtoken(['id' => $id]);

        // Verify it's gone
        $this->expectException(Exception::class);
        $this->main->getAuthtokenHandler()->getAuthtoken(['id' => $id]);
    }

    public function test_removeAuthtoken_throws_without_id(): void {
        $this->expectException(Exception::class);
        $this->main->getAuthtokenHandler()->removeAuthtoken([]);
    }
}
