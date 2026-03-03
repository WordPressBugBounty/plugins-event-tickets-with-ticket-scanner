<?php
/**
 * Tests for options change history (#73 Part 2).
 */

class OptionsHistoryTest extends WP_UnitTestCase {

	private sasoEventtickets $main;
	private sasoEventtickets_AdminSettings $admin;
	private sasoEventtickets_Options $options;
	private string $historyTable;
	private int $userId;

	public function set_up(): void {
		parent::set_up();
		$this->userId = self::factory()->user->create(['role' => 'administrator']);
		wp_set_current_user($this->userId);

		$this->main = sasoEventtickets::Instance();
		$this->admin = $this->main->getAdmin();
		$this->options = $this->main->getOptions();

		global $wpdb;
		$this->historyTable = $wpdb->prefix . 'saso_eventtickets_options_history';

		// Reinitialize options to restore any deleted options from previous tests
		$this->options->initOptions();

		// Start with empty history
		$wpdb->query("DELETE FROM {$this->historyTable}");
	}

	public function tear_down(): void {
		global $wpdb;
		$wpdb->query("DELETE FROM {$this->historyTable}");
		parent::tear_down();
	}

	// ── Table Existence ─────────────────────────────────────

	public function test_history_table_exists(): void {
		global $wpdb;
		$result = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $this->historyTable));
		$this->assertEquals($this->historyTable, $result);
	}

	public function test_history_table_has_correct_columns(): void {
		global $wpdb;
		$columns = $wpdb->get_col("DESCRIBE {$this->historyTable}", 0);
		$this->assertContains('id', $columns);
		$this->assertContains('option_key', $columns);
		$this->assertContains('old_value', $columns);
		$this->assertContains('new_value', $columns);
		$this->assertContains('changed_by', $columns);
		$this->assertContains('changed_at', $columns);
	}

	// ── changeOption logs history ───────────────────────────

	public function test_changeOption_creates_history_entry(): void {
		global $wpdb;
		$this->options->changeOption(['key' => 'displayDateFormat', 'value' => 'Y-m-d']);

		$count = intval($wpdb->get_var("SELECT COUNT(*) FROM {$this->historyTable} WHERE option_key = 'displayDateFormat'"));
		$this->assertGreaterThanOrEqual(1, $count);
	}

	public function test_changeOption_stores_old_and_new_value(): void {
		global $wpdb;

		// First set to a known value
		$this->options->changeOption(['key' => 'displayDateFormat', 'value' => 'Y-m-d']);
		// Now change again
		$this->options->changeOption(['key' => 'displayDateFormat', 'value' => 'd.m.Y']);

		$row = $wpdb->get_row(
			"SELECT * FROM {$this->historyTable} WHERE option_key = 'displayDateFormat' AND new_value = 'd.m.Y'",
			ARRAY_A
		);
		$this->assertNotNull($row);
		$this->assertEquals('Y-m-d', $row['old_value']);
		$this->assertEquals('d.m.Y', $row['new_value']);
	}

	public function test_changeOption_stores_user_id(): void {
		global $wpdb;
		$this->options->changeOption(['key' => 'displayDateFormat', 'value' => 'Y-m-d']);

		$row = $wpdb->get_row(
			"SELECT * FROM {$this->historyTable} WHERE option_key = 'displayDateFormat' ORDER BY id DESC LIMIT 1",
			ARRAY_A
		);
		$this->assertNotNull($row);
		$this->assertEquals($this->userId, intval($row['changed_by']));
	}

	// ── Same value = no log ─────────────────────────────────

	public function test_same_value_does_not_log(): void {
		global $wpdb;

		// Set to a value
		$this->options->changeOption(['key' => 'displayDateFormat', 'value' => 'Y-m-d']);
		$countBefore = intval($wpdb->get_var("SELECT COUNT(*) FROM {$this->historyTable}"));

		// Set to the same value again
		$this->options->changeOption(['key' => 'displayDateFormat', 'value' => 'Y-m-d']);
		$countAfter = intval($wpdb->get_var("SELECT COUNT(*) FROM {$this->historyTable}"));

		$this->assertEquals($countBefore, $countAfter);
	}

	// ── deleteOption logs history ───────────────────────────

	public function test_deleteOption_creates_history_entry(): void {
		global $wpdb;

		// Set a value first
		$this->options->changeOption(['key' => 'displayDateFormat', 'value' => 'Y-m-d']);
		$countBefore = intval($wpdb->get_var("SELECT COUNT(*) FROM {$this->historyTable}"));

		$this->options->deleteOption('displayDateFormat');
		$countAfter = intval($wpdb->get_var("SELECT COUNT(*) FROM {$this->historyTable}"));

		$this->assertGreaterThan($countBefore, $countAfter);
	}

	public function test_deleteOption_logs_empty_new_value(): void {
		global $wpdb;

		$this->options->changeOption(['key' => 'displayDateFormat', 'value' => 'Y-m-d']);
		$this->options->deleteOption('displayDateFormat');

		$row = $wpdb->get_row(
			"SELECT * FROM {$this->historyTable} WHERE option_key = 'displayDateFormat' AND new_value = '' ORDER BY id DESC LIMIT 1",
			ARRAY_A
		);
		$this->assertNotNull($row);
		$this->assertEquals('', $row['new_value']);
	}

	// ── getOptionsHistory (DataTable) ───────────────────────

	public function test_getOptionsHistory_returns_datatable_format(): void {
		$this->options->changeOption(['key' => 'displayDateFormat', 'value' => 'Y-m-d']);

		$request = ['draw' => 1, 'start' => 0, 'length' => 10];
		$result = $this->admin->getOptionsHistory([], $request);

		$this->assertArrayHasKey('draw', $result);
		$this->assertArrayHasKey('recordsTotal', $result);
		$this->assertArrayHasKey('recordsFiltered', $result);
		$this->assertArrayHasKey('data', $result);
		$this->assertGreaterThanOrEqual(1, $result['recordsTotal']);
	}

	public function test_getOptionsHistory_search_filters(): void {
		$this->options->changeOption(['key' => 'displayDateFormat', 'value' => 'Y-m-d']);
		$this->options->changeOption(['key' => 'displayTimeFormat', 'value' => 'H:i']);

		// Search for displayDate
		$request = ['draw' => 1, 'start' => 0, 'length' => 10, 'search' => ['value' => 'displayDate']];
		$result = $this->admin->getOptionsHistory([], $request);

		$this->assertGreaterThanOrEqual(1, $result['recordsFiltered']);
		foreach ($result['data'] as $row) {
			$this->assertStringContainsString('displayDate', $row['option_key']);
		}
	}

	// ── revertOption ────────────────────────────────────────

	public function test_revertOption_restores_old_value(): void {
		global $wpdb;

		$this->options->changeOption(['key' => 'displayDateFormat', 'value' => 'ORIGINAL']);
		$this->options->changeOption(['key' => 'displayDateFormat', 'value' => 'CHANGED']);

		// Get the history entry for the second change
		$historyId = $wpdb->get_var(
			"SELECT id FROM {$this->historyTable} WHERE option_key = 'displayDateFormat' AND old_value = 'ORIGINAL' ORDER BY id DESC LIMIT 1"
		);
		$this->assertNotNull($historyId);

		$result = $this->admin->revertOption(['history_id' => $historyId]);

		$this->assertTrue($result['reverted']);
		$this->assertEquals('displayDateFormat', $result['option_key']);
		$this->assertEquals('ORIGINAL', $this->options->getOptionValue('displayDateFormat'));
	}

	public function test_revertOption_creates_new_history_entry(): void {
		global $wpdb;

		$this->options->changeOption(['key' => 'displayDateFormat', 'value' => 'FIRST']);
		$this->options->changeOption(['key' => 'displayDateFormat', 'value' => 'SECOND']);

		$countBefore = intval($wpdb->get_var("SELECT COUNT(*) FROM {$this->historyTable}"));
		$historyId = $wpdb->get_var(
			"SELECT id FROM {$this->historyTable} WHERE option_key = 'displayDateFormat' AND old_value = 'FIRST' ORDER BY id DESC LIMIT 1"
		);
		$this->admin->revertOption(['history_id' => $historyId]);
		$countAfter = intval($wpdb->get_var("SELECT COUNT(*) FROM {$this->historyTable}"));

		$this->assertGreaterThan($countBefore, $countAfter);
	}

	// ── cleanupOptionsHistory (keep last 10 per key) ───────

	public function test_cleanup_deletes_entries_beyond_10_per_key(): void {
		global $wpdb;

		// Insert 12 entries for one option key
		for ($i = 1; $i <= 12; $i++) {
			$wpdb->insert($this->historyTable, [
				'option_key' => 'testOption',
				'old_value'  => 'val_' . ($i - 1),
				'new_value'  => 'val_' . $i,
				'changed_by' => $this->userId,
				'changed_at' => date('Y-m-d H:i:s', strtotime("-{$i} hours")),
			]);
		}

		$this->assertEquals(12, intval($wpdb->get_var("SELECT COUNT(*) FROM {$this->historyTable} WHERE option_key = 'testOption'")));

		$result = $this->admin->cleanupOptionsHistory();

		$this->assertEquals(2, $result['deleted']);
		$this->assertEquals(10, intval($wpdb->get_var("SELECT COUNT(*) FROM {$this->historyTable} WHERE option_key = 'testOption'")));
	}

	public function test_cleanup_keeps_entries_at_or_below_10(): void {
		global $wpdb;

		// Insert 5 entries — should all be kept
		for ($i = 1; $i <= 5; $i++) {
			$wpdb->insert($this->historyTable, [
				'option_key' => 'testOption',
				'old_value'  => 'val_' . ($i - 1),
				'new_value'  => 'val_' . $i,
				'changed_by' => $this->userId,
				'changed_at' => date('Y-m-d H:i:s'),
			]);
		}

		$result = $this->admin->cleanupOptionsHistory();

		$this->assertEquals(0, $result['deleted']);
		$this->assertEquals(5, intval($wpdb->get_var("SELECT COUNT(*) FROM {$this->historyTable}")));
	}

	public function test_cleanup_handles_multiple_keys_independently(): void {
		global $wpdb;

		// 12 entries for keyA, 3 for keyB
		for ($i = 1; $i <= 12; $i++) {
			$wpdb->insert($this->historyTable, [
				'option_key' => 'keyA',
				'old_value'  => 'a' . ($i - 1),
				'new_value'  => 'a' . $i,
				'changed_by' => $this->userId,
				'changed_at' => date('Y-m-d H:i:s', strtotime("-{$i} hours")),
			]);
		}
		for ($i = 1; $i <= 3; $i++) {
			$wpdb->insert($this->historyTable, [
				'option_key' => 'keyB',
				'old_value'  => 'b' . ($i - 1),
				'new_value'  => 'b' . $i,
				'changed_by' => $this->userId,
				'changed_at' => date('Y-m-d H:i:s'),
			]);
		}

		$result = $this->admin->cleanupOptionsHistory();

		$this->assertEquals(2, $result['deleted']);
		$this->assertEquals(10, intval($wpdb->get_var("SELECT COUNT(*) FROM {$this->historyTable} WHERE option_key = 'keyA'")));
		$this->assertEquals(3, intval($wpdb->get_var("SELECT COUNT(*) FROM {$this->historyTable} WHERE option_key = 'keyB'")));
	}
}
