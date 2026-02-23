<?php
/**
 * Tests for DB utility methods:
 * reinigen_in, getTabelle, getTables, _db_datenholen, _db_query.
 */

class DBSanitizeAndQueryTest extends WP_UnitTestCase {

    private $main;

    public function set_up(): void {
        parent::set_up();
        $this->main = sasoEventtickets::Instance();
    }

    // ── reinigen_in ────────────────────────────────────────────────

    public function test_reinigen_in_trims(): void {
        $result = $this->main->getDB()->reinigen_in('  hello  ');
        $this->assertEquals('hello', $result);
    }

    public function test_reinigen_in_length_limit(): void {
        $result = $this->main->getDB()->reinigen_in('abcdefghij', 5);
        $this->assertEquals(5, strlen($result));
        $this->assertEquals('abcde', $result);
    }

    public function test_reinigen_in_addslashes(): void {
        $result = $this->main->getDB()->reinigen_in("O'Brien", 0, 1);
        $this->assertStringContainsString("O\\'Brien", $result);
    }

    public function test_reinigen_in_no_addslashes(): void {
        $result = $this->main->getDB()->reinigen_in("O'Brien", 0, 0);
        $this->assertEquals("O'Brien", $result);
    }

    public function test_reinigen_in_htmlentities(): void {
        $result = $this->main->getDB()->reinigen_in('<b>bold</b>', 0, 0, 0, 1);
        $this->assertStringContainsString('&lt;b&gt;', $result);
    }

    public function test_reinigen_in_no_htmlentities(): void {
        $result = $this->main->getDB()->reinigen_in('<b>bold</b>', 0, 0, 0, 0);
        $this->assertStringContainsString('<b>', $result);
    }

    // ── getTabelle ─────────────────────────────────────────────────

    public function test_getTabelle_lists_returns_string(): void {
        $result = $this->main->getDB()->getTabelle('lists');
        $this->assertIsString($result);
        $this->assertNotEmpty($result);
    }

    public function test_getTabelle_lists_contains_prefix(): void {
        $result = $this->main->getDB()->getTabelle('lists');
        $this->assertStringContainsString('saso_eventtickets_lists', $result);
    }

    public function test_getTabelle_codes_returns_string(): void {
        $result = $this->main->getDB()->getTabelle('codes');
        $this->assertIsString($result);
        $this->assertStringContainsString('codes', $result);
    }

    public function test_getTabelle_errorlogs_returns_string(): void {
        $result = $this->main->getDB()->getTabelle('errorlogs');
        $this->assertIsString($result);
        $this->assertStringContainsString('errorlogs', $result);
    }

    // ── getTables ──────────────────────────────────────────────────

    public function test_getTables_returns_array(): void {
        $result = $this->main->getDB()->getTables();
        $this->assertIsArray($result);
        $this->assertNotEmpty($result);
    }

    public function test_getTables_contains_expected_tables(): void {
        $tables = $this->main->getDB()->getTables();
        $this->assertContains('lists', $tables);
        $this->assertContains('codes', $tables);
        $this->assertContains('authtokens', $tables);
    }

    // ── _db_datenholen ─────────────────────────────────────────────

    public function test_db_datenholen_returns_array(): void {
        $sql = "SELECT 1 as test_col";
        $result = $this->main->getDB()->_db_datenholen($sql);
        $this->assertIsArray($result);
        $this->assertNotEmpty($result);
    }

    public function test_db_datenholen_lists_query(): void {
        $table = $this->main->getDB()->getTabelle('lists');
        $sql = "SELECT COUNT(*) as cnt FROM {$table}";
        $result = $this->main->getDB()->_db_datenholen($sql);
        $this->assertIsArray($result);
        $this->assertArrayHasKey('cnt', $result[0]);
    }

    // ── _db_query ──────────────────────────────────────────────────

    public function test_db_query_insert_returns_id(): void {
        $table = $this->main->getDB()->getTabelle('lists');
        $name = 'DBQuery Test ' . uniqid();
        $sql = "INSERT INTO {$table} (name, aktiv, meta) VALUES ('" . esc_sql($name) . "', 1, '{}')";
        $result = $this->main->getDB()->_db_query($sql);
        $this->assertIsNumeric($result);
        $this->assertGreaterThan(0, $result);
    }

    public function test_db_query_select_count(): void {
        $table = $this->main->getDB()->getTabelle('lists');
        $sql = "SELECT COUNT(*) FROM {$table}";
        $result = $this->main->getDB()->_db_datenholen($sql);
        $this->assertIsArray($result);
    }
}
