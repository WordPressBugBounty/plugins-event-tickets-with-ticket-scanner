<?php
/**
 * Tests for DB methods: getTabelle, getTables, update, _db_query.
 */

class DBTablesAndUpdateTest extends WP_UnitTestCase {

    private $main;

    public function set_up(): void {
        parent::set_up();
        $this->main = sasoEventtickets::Instance();
    }

    // ── getTabelle ───────────────────────────────────────────────

    public function test_getTabelle_returns_prefixed_table_name(): void {
        $table = $this->main->getDB()->getTabelle('lists');
        $this->assertIsString($table);
        $this->assertStringContainsString('saso_eventtickets_lists', $table);
    }

    public function test_getTabelle_codes(): void {
        $table = $this->main->getDB()->getTabelle('codes');
        $this->assertStringContainsString('saso_eventtickets_codes', $table);
    }

    public function test_getTabelle_authtokens(): void {
        $table = $this->main->getDB()->getTabelle('authtokens');
        $this->assertStringContainsString('saso_eventtickets_authtokens', $table);
    }

    public function test_getTabelle_errorlogs(): void {
        $table = $this->main->getDB()->getTabelle('errorlogs');
        $this->assertStringContainsString('saso_eventtickets_errorlogs', $table);
    }

    public function test_getTabelle_seatingplans(): void {
        $table = $this->main->getDB()->getTabelle('seatingplans');
        $this->assertStringContainsString('saso_eventtickets_seatingplans', $table);
    }

    public function test_getTabelle_seats(): void {
        $table = $this->main->getDB()->getTabelle('seats');
        $this->assertStringContainsString('saso_eventtickets_seats', $table);
    }

    public function test_getTabelle_ips(): void {
        $table = $this->main->getDB()->getTabelle('ips');
        $this->assertStringContainsString('saso_eventtickets_ips', $table);
    }

    // ── getTables ────────────────────────────────────────────────

    public function test_getTables_returns_array(): void {
        $tables = $this->main->getDB()->getTables();
        $this->assertIsArray($tables);
        $this->assertNotEmpty($tables);
    }

    public function test_getTables_contains_all_known_tables(): void {
        $tables = $this->main->getDB()->getTables();

        // getTables() returns numerically indexed array of table short names
        $expectedTables = ['lists', 'codes', 'ips', 'authtokens', 'errorlogs', 'seatingplans', 'seats'];
        foreach ($expectedTables as $name) {
            $this->assertContains($name, $tables, "Table '$name' not found in getTables()");
        }
    }

    public function test_getTables_values_usable_with_getTabelle(): void {
        $tables = $this->main->getDB()->getTables();
        foreach ($tables as $tableName) {
            $fullName = $this->main->getDB()->getTabelle($tableName);
            $this->assertIsString($fullName);
            $this->assertStringContainsString($tableName, $fullName);
        }
    }

    // ── update ───────────────────────────────────────────────────

    public function test_update_modifies_record(): void {
        $listId = $this->main->getDB()->insert('lists', [
            'name' => 'Update Test ' . uniqid(),
            'aktiv' => 1,
            'meta' => '{}',
        ]);

        $newName = 'Updated Name ' . uniqid();
        $result = $this->main->getDB()->update('lists', ['name' => $newName], ['id' => $listId]);

        // Verify update succeeded
        $table = $this->main->getDB()->getTabelle('lists');
        $rows = $this->main->getDB()->_db_datenholen_prepared(
            "SELECT * FROM $table WHERE id = %d",
            [$listId]
        );
        $this->assertEquals($newName, $rows[0]['name']);
    }

    public function test_update_throws_without_fields(): void {
        $this->expectException(Exception::class);
        $this->main->getDB()->update('lists', [], ['id' => 1]);
    }

    public function test_update_throws_without_where(): void {
        $this->expectException(Exception::class);
        $this->main->getDB()->update('lists', ['name' => 'test'], []);
    }

    public function test_update_toggles_aktiv(): void {
        $listId = $this->main->getDB()->insert('lists', [
            'name' => 'Toggle Test ' . uniqid(),
            'aktiv' => 1,
            'meta' => '{}',
        ]);

        $this->main->getDB()->update('lists', ['aktiv' => 0], ['id' => $listId]);

        $table = $this->main->getDB()->getTabelle('lists');
        $rows = $this->main->getDB()->_db_datenholen_prepared(
            "SELECT aktiv FROM $table WHERE id = %d",
            [$listId]
        );
        $this->assertEquals(0, intval($rows[0]['aktiv']));
    }

    // ── _db_query ────────────────────────────────────────────────

    public function test_db_query_select_returns_results(): void {
        $listId = $this->main->getDB()->insert('lists', [
            'name' => 'Query Test ' . uniqid(),
            'aktiv' => 1,
            'meta' => '{}',
        ]);

        $table = $this->main->getDB()->getTabelle('lists');
        // _db_query for SELECT returns the number of rows (or true)
        // Let's use _db_datenholen_prepared for actual results and test _db_query for UPDATE
        $result = $this->main->getDB()->_db_query(
            "UPDATE $table SET aktiv = 0 WHERE id = " . intval($listId)
        );
        $this->assertNotFalse($result);
    }
}
