<?php
/**
 * Integration tests for the database layer (codes, lists CRUD).
 */

class DatabaseTest extends WP_UnitTestCase {

    private $db;
    private sasoEventtickets_Core $core;

    public function set_up(): void {
        parent::set_up();
        $main = sasoEventtickets::Instance();
        $this->db = $main->getDB();
        $this->core = $main->getCore();
    }

    // ── Table names ────────────────────────────────────────────

    public function test_getTabelle_codes(): void {
        global $wpdb;
        $expected = $wpdb->prefix . 'saso_eventtickets_codes';
        $this->assertSame($expected, $this->db->getTabelle('codes'));
    }

    public function test_getTabelle_lists(): void {
        global $wpdb;
        $expected = $wpdb->prefix . 'saso_eventtickets_lists';
        $this->assertSame($expected, $this->db->getTabelle('lists'));
    }

    public function test_getTabelle_authtokens(): void {
        global $wpdb;
        $expected = $wpdb->prefix . 'saso_eventtickets_authtokens';
        $this->assertSame($expected, $this->db->getTabelle('authtokens'));
    }

    public function test_getTables_returns_all_tables(): void {
        $tables = $this->db->getTables();
        $this->assertIsArray($tables);
        // getTables() returns indexed array of table short names
        $this->assertContains('codes', $tables);
        $this->assertContains('lists', $tables);
        $this->assertContains('authtokens', $tables);
        $this->assertContains('seatingplans', $tables);
        $this->assertContains('seats', $tables);
    }

    // ── Lists CRUD ─────────────────────────────────────────────

    public function test_insert_and_retrieve_list(): void {
        $listName = 'PHPUnit Test List ' . uniqid();
        $id = $this->db->insert('lists', [
            'name' => $listName,
            'aktiv' => 1,
            'meta' => '{}',
        ]);
        $this->assertGreaterThan(0, $id);

        $list = $this->core->getListById($id);
        $this->assertSame($listName, $list['name']);
        $this->assertEquals(1, $list['aktiv']);
    }

    public function test_update_list(): void {
        $id = $this->db->insert('lists', [
            'name' => 'Before Update ' . uniqid(),
            'aktiv' => 1,
            'meta' => '{}',
        ]);
        $newName = 'After Update ' . uniqid();
        $this->db->update('lists', ['name' => $newName], ['id' => $id]);

        $list = $this->core->getListById($id);
        $this->assertSame($newName, $list['name']);
    }

    // ── Codes CRUD ─────────────────────────────────────────────

    public function test_insert_and_retrieve_code(): void {
        // First create a list
        $listId = $this->db->insert('lists', [
            'name' => 'Code Test List ' . uniqid(),
            'aktiv' => 1,
            'meta' => '{}',
        ]);

        $code = 'PHPUNIT' . strtoupper(uniqid());
        $metaObj = $this->core->getMetaObject();
        $metaJson = $this->core->json_encode_with_error_handling($metaObj);

        $codeId = $this->db->insert('codes', [
            'code' => $code,
            'code_display' => $code,
            'cvv' => '1234',
            'meta' => $metaJson,
            'aktiv' => 1,
            'redeemed' => 0,
            'list_id' => $listId,
            'order_id' => 0,
        ]);
        $this->assertGreaterThan(0, $codeId);

        // Retrieve by code
        $retrieved = $this->core->retrieveCodeByCode($code);
        $this->assertSame($code, $retrieved['code']);
        $this->assertEquals($listId, $retrieved['list_id']);
        $this->assertEquals(0, $retrieved['redeemed']);
    }

    public function test_retrieve_code_by_id(): void {
        $listId = $this->db->insert('lists', [
            'name' => 'ById Test ' . uniqid(),
            'aktiv' => 1,
            'meta' => '{}',
        ]);

        $code = 'BYID' . strtoupper(uniqid());
        $codeId = $this->db->insert('codes', [
            'code' => $code,
            'code_display' => $code,
            'cvv' => '5678',
            'meta' => '{}',
            'aktiv' => 1,
            'redeemed' => 0,
            'list_id' => $listId,
            'order_id' => 0,
        ]);

        $retrieved = $this->core->retrieveCodeById($codeId);
        $this->assertSame($code, $retrieved['code']);
    }

    public function test_getCodesSize_increments(): void {
        $sizeBefore = $this->db->getCodesSize();

        $listId = $this->db->insert('lists', [
            'name' => 'Size Test ' . uniqid(),
            'aktiv' => 1,
            'meta' => '{}',
        ]);

        $this->db->insert('codes', [
            'code' => 'SIZE' . strtoupper(uniqid()),
            'code_display' => '',
            'cvv' => '',
            'meta' => '{}',
            'aktiv' => 1,
            'redeemed' => 0,
            'list_id' => $listId,
            'order_id' => 0,
        ]);

        $sizeAfter = $this->db->getCodesSize();
        $this->assertEquals($sizeBefore + 1, $sizeAfter);
    }

    public function test_getCodesByOrderId_returns_matching(): void {
        $listId = $this->db->insert('lists', [
            'name' => 'Order Test ' . uniqid(),
            'aktiv' => 1,
            'meta' => '{}',
        ]);

        $orderId = 99999;
        $code1 = 'ORD1' . strtoupper(uniqid());
        $code2 = 'ORD2' . strtoupper(uniqid());

        $this->db->insert('codes', [
            'code' => $code1,
            'code_display' => $code1,
            'cvv' => '',
            'meta' => '{}',
            'aktiv' => 1,
            'redeemed' => 0,
            'list_id' => $listId,
            'order_id' => $orderId,
        ]);
        $this->db->insert('codes', [
            'code' => $code2,
            'code_display' => $code2,
            'cvv' => '',
            'meta' => '{}',
            'aktiv' => 1,
            'redeemed' => 0,
            'list_id' => $listId,
            'order_id' => $orderId,
        ]);

        $codes = $this->core->getCodesByOrderId($orderId);
        $this->assertCount(2, $codes);
        $foundCodes = array_column($codes, 'code');
        $this->assertContains($code1, $foundCodes);
        $this->assertContains($code2, $foundCodes);
    }

    public function test_update_code_redeemed_flag(): void {
        $listId = $this->db->insert('lists', [
            'name' => 'Redeem Flag Test ' . uniqid(),
            'aktiv' => 1,
            'meta' => '{}',
        ]);

        $code = 'REDF' . strtoupper(uniqid());
        $codeId = $this->db->insert('codes', [
            'code' => $code,
            'code_display' => $code,
            'cvv' => '',
            'meta' => '{}',
            'aktiv' => 1,
            'redeemed' => 0,
            'list_id' => $listId,
            'order_id' => 0,
        ]);

        // Mark as redeemed
        $this->db->update('codes', ['redeemed' => 1], ['id' => $codeId]);

        $retrieved = $this->core->retrieveCodeById($codeId);
        $this->assertEquals(1, $retrieved['redeemed']);
    }

    // ── reinigen_in (input sanitization) ───────────────────────

    public function test_reinigen_in_trims(): void {
        $this->assertSame('hello', $this->db->reinigen_in('  hello  '));
    }

    public function test_reinigen_in_length_limit(): void {
        $result = $this->db->reinigen_in('abcdefghij', 5);
        $this->assertSame(5, strlen($result));
    }

    public function test_reinigen_in_html_entities(): void {
        $result = $this->db->reinigen_in('<script>alert(1)</script>', 0, 0, 0, 1);
        $this->assertStringNotContainsString('<script>', $result);
    }

    // ── insert validation ──────────────────────────────────────

    public function test_insert_throws_on_empty_fields(): void {
        $this->expectException(Exception::class);
        $this->db->insert('codes', []);
    }

    public function test_update_throws_on_empty_where(): void {
        $this->expectException(Exception::class);
        $this->db->update('codes', ['aktiv' => 1], []);
    }
}
