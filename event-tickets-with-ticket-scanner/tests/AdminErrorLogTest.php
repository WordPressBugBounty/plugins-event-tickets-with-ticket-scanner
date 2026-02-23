<?php
/**
 * Tests for Admin error logging and DB utility:
 * logErrorToDB, getCodesSize, getErrorlogs.
 */

class AdminErrorLogTest extends WP_UnitTestCase {

    private $main;

    public function set_up(): void {
        parent::set_up();
        $this->main = sasoEventtickets::Instance();
    }

    // ── logErrorToDB ─────────────────────────────────────────────

    public function test_logErrorToDB_does_not_throw(): void {
        $e = new Exception('Test error message');
        // Should not throw
        $this->main->getAdmin()->logErrorToDB($e, 'TestCaller', 'Extra info');
        $this->assertTrue(true); // If we get here, no exception was thrown
    }

    public function test_logErrorToDB_inserts_record(): void {
        $uniqueMsg = 'UniqueError_' . uniqid();
        $e = new Exception($uniqueMsg);
        $this->main->getAdmin()->logErrorToDB($e, 'TestCaller');

        // Check if error is in DB
        $sql = "SELECT * FROM " . $this->main->getDB()->getTabelle('errorlogs')
             . " WHERE exception_msg LIKE '%" . esc_sql($uniqueMsg) . "%'";
        $results = $this->main->getDB()->_db_datenholen($sql);
        $this->assertNotEmpty($results);
        $this->assertStringContainsString($uniqueMsg, $results[0]['exception_msg']);
    }

    public function test_logErrorToDB_stores_caller_name(): void {
        $uniqueCaller = 'Caller_' . uniqid();
        $e = new Exception('caller test');
        $this->main->getAdmin()->logErrorToDB($e, $uniqueCaller);

        $sql = "SELECT * FROM " . $this->main->getDB()->getTabelle('errorlogs')
             . " WHERE caller_name = '" . esc_sql($uniqueCaller) . "'";
        $results = $this->main->getDB()->_db_datenholen($sql);
        $this->assertNotEmpty($results);
        $this->assertEquals($uniqueCaller, $results[0]['caller_name']);
    }

    // ── getCodesSize ─────────────────────────────────────────────

    public function test_getCodesSize_returns_numeric(): void {
        $result = $this->main->getDB()->getCodesSize();
        $this->assertIsNumeric($result);
    }

    public function test_getCodesSize_non_negative(): void {
        $result = $this->main->getDB()->getCodesSize();
        $this->assertGreaterThanOrEqual(0, intval($result));
    }

    // ── _db_getRecordCountOfTable ────────────────────────────────

    public function test_getRecordCountOfTable_for_lists(): void {
        $result = $this->main->getDB()->_db_getRecordCountOfTable('lists');
        $this->assertIsNumeric($result);
    }

    public function test_getRecordCountOfTable_increases_after_insert(): void {
        $before = intval($this->main->getDB()->_db_getRecordCountOfTable('lists'));

        $this->main->getDB()->insert('lists', [
            'name' => 'CountTest List ' . uniqid(),
            'aktiv' => 1,
            'meta' => '{}',
        ]);

        $after = intval($this->main->getDB()->_db_getRecordCountOfTable('lists'));
        $this->assertGreaterThan($before, $after);
    }

    public function test_getRecordCountOfTable_with_where(): void {
        $listName = 'WhereTest List ' . uniqid();
        $this->main->getDB()->insert('lists', [
            'name' => $listName,
            'aktiv' => 1,
            'meta' => '{}',
        ]);

        $result = $this->main->getDB()->_db_getRecordCountOfTable(
            'lists',
            "name = '" . esc_sql($listName) . "'"
        );
        $this->assertEquals(1, intval($result));
    }
}
