<?php
/**
 * Tests for options migration from wp_options to custom table (#73).
 */

class OptionsMigrationTest extends WP_UnitTestCase {

	private sasoEventtickets_AdminSettings $admin;
	private sasoEventtickets_Options $options;
	private string $prefix;
	private string $tableName;

	public function set_up(): void {
		parent::set_up();
		$user_id = self::factory()->user->create(['role' => 'administrator']);
		wp_set_current_user($user_id);

		$main = sasoEventtickets::Instance();
		$this->admin = $main->getAdmin();
		$this->options = $main->getOptions();
		$this->prefix = $main->getPrefix();

		global $wpdb;
		$this->tableName = $wpdb->prefix . 'saso_eventtickets_options';

		// Ensure clean state: flag off, custom table empty
		delete_option('saso_eventtickets_options_migrated');
		$wpdb->query("TRUNCATE TABLE {$this->tableName}");
		$this->options->resetMigrationCache();
	}

	public function tear_down(): void {
		global $wpdb;
		delete_option('saso_eventtickets_options_migrated');
		$wpdb->query("TRUNCATE TABLE {$this->tableName}");
		// Clean up any wp_options we set
		foreach ($this->options->getOptionsKeys() as $key) {
			delete_option($this->prefix . $key);
		}
		parent::tear_down();
	}

	// ── Table Existence ─────────────────────────────────────

	public function test_options_table_exists(): void {
		global $wpdb;
		$result = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $this->tableName));
		$this->assertEquals($this->tableName, $result);
	}

	public function test_options_table_has_correct_columns(): void {
		global $wpdb;
		$columns = $wpdb->get_col("DESCRIBE {$this->tableName}", 0);
		$this->assertContains('option_key', $columns);
		$this->assertContains('option_value', $columns);
		$this->assertContains('updated_at', $columns);
		$this->assertContains('updated_by', $columns);
	}

	// ── Migration ───────────────────────────────────────────

	public function test_migration_moves_options_to_custom_table(): void {
		// Set 3 options in wp_options
		update_option($this->prefix . 'displayDateFormat', 'Y-m-d');
		update_option($this->prefix . 'displayTimeFormat', 'H:i:s');
		update_option($this->prefix . 'wcTicketHeading', 'My Tickets');

		$result = $this->admin->migrateOptionsToCustomTable();

		$this->assertGreaterThanOrEqual(3, $result['migrated']);
	}

	public function test_migration_removes_from_wp_options(): void {
		update_option($this->prefix . 'displayDateFormat', 'Y-m-d');

		$this->admin->migrateOptionsToCustomTable();

		$this->assertFalse(get_option($this->prefix . 'displayDateFormat', false));
	}

	public function test_migration_writes_to_custom_table(): void {
		global $wpdb;
		update_option($this->prefix . 'displayDateFormat', 'Y-m-d');

		$this->admin->migrateOptionsToCustomTable();

		$value = $wpdb->get_var($wpdb->prepare(
			"SELECT option_value FROM {$this->tableName} WHERE option_key = %s",
			'displayDateFormat'
		));
		$this->assertEquals('Y-m-d', $value);
	}

	public function test_migration_sets_complete_flag(): void {
		$this->admin->migrateOptionsToCustomTable();

		$this->assertEquals('1', get_option('saso_eventtickets_options_migrated'));
	}

	public function test_migration_is_crash_safe(): void {
		global $wpdb;
		// Simulate: option A already in custom table, option B only in wp_options
		$wpdb->replace($this->tableName, [
			'option_key' => 'displayDateFormat',
			'option_value' => 'Y-m-d',
			'updated_at' => wp_date('Y-m-d H:i:s'),
			'updated_by' => 0,
		]);
		update_option($this->prefix . 'displayTimeFormat', 'H:i:s');

		// Run migration — should handle both cases
		$result = $this->admin->migrateOptionsToCustomTable();

		$this->assertEquals('1', get_option('saso_eventtickets_options_migrated'));
		// Both should be in custom table
		$count = $wpdb->get_var("SELECT COUNT(*) FROM {$this->tableName}");
		$this->assertGreaterThanOrEqual(2, intval($count));
	}

	public function test_migration_preserves_checkbox_zero(): void {
		global $wpdb;
		update_option($this->prefix . 'displayFirstStepsHelp', '0');

		$this->admin->migrateOptionsToCustomTable();

		$value = $wpdb->get_var($wpdb->prepare(
			"SELECT option_value FROM {$this->tableName} WHERE option_key = %s",
			'displayFirstStepsHelp'
		));
		$this->assertEquals('0', $value);
	}

	// ── Read from Custom Table ──────────────────────────────

	public function test_getOptionValue_reads_from_custom_table(): void {
		global $wpdb;
		// First: migrate so flag is set and options framework knows to use custom table
		update_option($this->prefix . 'displayDateFormat', 'old_value');
		$this->admin->migrateOptionsToCustomTable();

		// Now overwrite directly in custom table
		$wpdb->update($this->tableName,
			['option_value' => 'd.m.Y'],
			['option_key' => 'displayDateFormat']
		);

		// Reset cache so options are re-read from custom table
		$this->options->resetMigrationCache();

		$value = $this->options->getOptionValue('displayDateFormat');
		$this->assertEquals('d.m.Y', $value);
	}

	// ── Write to Custom Table ───────────────────────────────

	public function test_changeOption_writes_to_custom_table(): void {
		global $wpdb;
		update_option('saso_eventtickets_options_migrated', '1');
		$this->options->resetMigrationCache();

		$this->options->changeOption(['key' => 'displayDateFormat', 'value' => 'Y/m/d']);

		$value = $wpdb->get_var($wpdb->prepare(
			"SELECT option_value FROM {$this->tableName} WHERE option_key = %s",
			'displayDateFormat'
		));
		$this->assertEquals('Y/m/d', $value);
	}

	public function test_changeOption_updates_memory_cache(): void {
		update_option('saso_eventtickets_options_migrated', '1');
		$this->options->resetMigrationCache();

		$this->options->changeOption(['key' => 'displayDateFormat', 'value' => 'Y/m/d']);

		// Should return the new value without DB query
		$value = $this->options->getOptionValue('displayDateFormat');
		$this->assertEquals('Y/m/d', $value);
	}

	// ── Delete from Custom Table ────────────────────────────

	public function test_deleteOption_removes_from_custom_table(): void {
		global $wpdb;
		update_option('saso_eventtickets_options_migrated', '1');
		$wpdb->replace($this->tableName, [
			'option_key' => 'displayDateFormat',
			'option_value' => 'Y-m-d',
			'updated_at' => wp_date('Y-m-d H:i:s'),
			'updated_by' => 0,
		]);
		$this->options->resetMigrationCache();

		$this->options->deleteOption('displayDateFormat');

		$count = $wpdb->get_var($wpdb->prepare(
			"SELECT COUNT(*) FROM {$this->tableName} WHERE option_key = %s",
			'displayDateFormat'
		));
		$this->assertEquals(0, intval($count));
	}

	public function test_deleteAllOptionValues_truncates_custom_table(): void {
		global $wpdb;
		update_option('saso_eventtickets_options_migrated', '1');
		$wpdb->replace($this->tableName, [
			'option_key' => 'displayDateFormat',
			'option_value' => 'Y-m-d',
			'updated_at' => wp_date('Y-m-d H:i:s'),
			'updated_by' => 0,
		]);
		$wpdb->replace($this->tableName, [
			'option_key' => 'displayTimeFormat',
			'option_value' => 'H:i',
			'updated_at' => wp_date('Y-m-d H:i:s'),
			'updated_by' => 0,
		]);
		$this->options->resetMigrationCache();

		$this->options->deleteAllOptionValues();

		$count = $wpdb->get_var("SELECT COUNT(*) FROM {$this->tableName}");
		$this->assertEquals(0, intval($count));
	}

	// ── Flag Reset on Activation ────────────────────────────

	public function test_plugin_activated_resets_migration_flag_only_if_options_in_wp_options(): void {
		$prefix = sasoEventtickets::Instance()->getPrefix();

		// Case 1: No options in wp_options (normal post-migration state) → flag stays
		update_option('saso_eventtickets_options_migrated', '1');
		delete_option($prefix . 'qrAttachQRFilesToMailAsOnePDF');
		sasoEventtickets::Instance()->plugin_activated();
		$this->assertEquals('1', get_option('saso_eventtickets_options_migrated', '0'),
			'Flag must NOT be deleted when sentinel option is absent (normal state)');

		// Case 2: Options back in wp_options (downgrade scenario) → flag reset
		update_option('saso_eventtickets_options_migrated', '1');
		update_option($prefix . 'qrAttachQRFilesToMailAsOnePDF', '0');
		sasoEventtickets::Instance()->plugin_activated();
		$this->assertNotEquals('1', get_option('saso_eventtickets_options_migrated', '0'),
			'Flag must be deleted when sentinel option exists in wp_options (downgrade)');

		// Clean up
		delete_option($prefix . 'qrAttachQRFilesToMailAsOnePDF');
	}

	// ── Import writes to correct backend ────────────────────

	public function test_importOptions_writes_to_custom_table_when_migrated(): void {
		global $wpdb;
		// Migrate first to set up the flag and table
		update_option($this->prefix . 'displayDateFormat', 'old');
		$this->admin->migrateOptionsToCustomTable();

		// Now import a new value — should go to custom table
		$data = [
			'options' => ['displayDateFormat' => 'd/m/Y'],
		];
		$result = $this->admin->importOptions($data);

		$this->assertEquals(1, $result['imported']);
		$value = $wpdb->get_var($wpdb->prepare(
			"SELECT option_value FROM {$this->tableName} WHERE option_key = %s",
			'displayDateFormat'
		));
		$this->assertEquals('d/m/Y', $value);
	}

	// ── AJAX handler accessible ─────────────────────────────

	public function test_migrateOptionsToCustomTable_via_ajax(): void {
		update_option($this->prefix . 'displayDateFormat', 'Y-m-d');

		$result = $this->admin->executeJSON('migrateOptionsToCustomTable', [], true, true);

		$this->assertIsArray($result);
		$this->assertArrayHasKey('migrated', $result);
		$this->assertEquals('1', get_option('saso_eventtickets_options_migrated'));
	}
}
