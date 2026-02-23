<?php
/**
 * Tests for DB methods: _db_getRecordCountOfTable, _db_datenholen_prepared.
 */

class DBOperationsTest extends WP_UnitTestCase {

    private $main;

    public function set_up(): void {
        parent::set_up();
        $this->main = sasoEventtickets::Instance();
    }

    // ── _db_getRecordCountOfTable ────────────────────────────────

    public function test_getRecordCountOfTable_returns_numeric(): void {
        $count = $this->main->getDB()->_db_getRecordCountOfTable('lists');
        $this->assertIsNumeric($count);
        $this->assertGreaterThanOrEqual(0, intval($count));
    }

    public function test_getRecordCountOfTable_reflects_inserts(): void {
        $before = $this->main->getDB()->_db_getRecordCountOfTable('lists');

        $this->main->getDB()->insert('lists', [
            'name' => 'Count Test ' . uniqid(),
            'aktiv' => 1,
            'meta' => '{}',
        ]);

        $after = $this->main->getDB()->_db_getRecordCountOfTable('lists');
        $this->assertEquals($before + 1, $after);
    }

    public function test_getRecordCountOfTable_with_where(): void {
        $uniqueName = 'WhereCount_' . uniqid();
        $this->main->getDB()->insert('lists', [
            'name' => $uniqueName,
            'aktiv' => 1,
            'meta' => '{}',
        ]);

        $count = $this->main->getDB()->_db_getRecordCountOfTable(
            'lists',
            "name = '" . esc_sql($uniqueName) . "'"
        );
        $this->assertEquals(1, $count);
    }

    public function test_getRecordCountOfTable_codes_table(): void {
        $count = $this->main->getDB()->_db_getRecordCountOfTable('codes');
        $this->assertIsNumeric($count);
        $this->assertGreaterThanOrEqual(0, intval($count));
    }

    // ── _db_datenholen_prepared ───────────────────────────────────

    public function test_datenholen_prepared_returns_results(): void {
        $listId = $this->main->getDB()->insert('lists', [
            'name' => 'Prepared Test ' . uniqid(),
            'aktiv' => 1,
            'meta' => '{}',
        ]);

        $table = $this->main->getDB()->getTabelle('lists');
        $results = $this->main->getDB()->_db_datenholen_prepared(
            "SELECT * FROM $table WHERE id = %d",
            [$listId]
        );

        $this->assertIsArray($results);
        $this->assertCount(1, $results);
        $this->assertEquals($listId, intval($results[0]['id']));
    }

    public function test_datenholen_prepared_empty_for_nonexistent(): void {
        $table = $this->main->getDB()->getTabelle('lists');
        $results = $this->main->getDB()->_db_datenholen_prepared(
            "SELECT * FROM $table WHERE id = %d",
            [999999]
        );

        $this->assertIsArray($results);
        $this->assertEmpty($results);
    }

    public function test_datenholen_prepared_string_param(): void {
        $uniqueName = 'PrepStr_' . uniqid();
        $this->main->getDB()->insert('lists', [
            'name' => $uniqueName,
            'aktiv' => 1,
            'meta' => '{}',
        ]);

        $table = $this->main->getDB()->getTabelle('lists');
        $results = $this->main->getDB()->_db_datenholen_prepared(
            "SELECT * FROM $table WHERE name = %s",
            [$uniqueName]
        );

        $this->assertCount(1, $results);
        $this->assertEquals($uniqueName, $results[0]['name']);
    }
}
