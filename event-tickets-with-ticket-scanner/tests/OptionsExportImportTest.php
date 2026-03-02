<?php
/**
 * Tests for the Export/Import Options feature.
 */

class OptionsExportImportTest extends WP_UnitTestCase {

    private sasoEventtickets_AdminSettings $admin;

    public function set_up(): void {
        parent::set_up();
        // Create admin user so executeJSON permission checks pass
        $user_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($user_id);
        $this->admin = sasoEventtickets::Instance()->getAdmin();
    }

    /**
     * Call via executeJSON — same path as real AJAX.
     */
    private function callExport(): array {
        return $this->admin->executeJSON('exportOptions', [], true, true);
    }

    private function callImport(array $options): array {
        // Simulate real request: JS sends JSON string, WordPress wp_magic_quotes adds slashes
        $json = addslashes(json_encode($options));
        return $this->admin->executeJSON('importOptions', ['options' => $json], true, true);
    }

    // ── exportOptions ────────────────────────────────────────────

    public function test_exportOptions_returns_valid_structure(): void {
        $result = $this->callExport();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('plugin', $result);
        $this->assertArrayHasKey('version', $result);
        $this->assertArrayHasKey('exported_at', $result);
        $this->assertArrayHasKey('options', $result);
        $this->assertSame('event-tickets-with-ticket-scanner', $result['plugin']);
    }

    public function test_exportOptions_version_matches_plugin(): void {
        $result = $this->callExport();
        $this->assertSame(sasoEventtickets::Instance()->getPluginVersion(), $result['version']);
    }

    public function test_exportOptions_excludes_headings(): void {
        $result = $this->callExport();
        foreach (sasoEventtickets::Instance()->getOptions()->getOptions() as $opt) {
            if ($opt['type'] === 'heading') {
                $this->assertArrayNotHasKey($opt['key'], $result['options']);
            }
        }
    }

    public function test_exportOptions_includes_all_non_heading_options(): void {
        $result = $this->callExport();
        foreach (sasoEventtickets::Instance()->getOptions()->getOptions() as $opt) {
            if ($opt['type'] !== 'heading') {
                $this->assertArrayHasKey($opt['key'], $result['options']);
            }
        }
    }

    public function test_exportOptions_exported_at_is_valid_datetime(): void {
        $result = $this->callExport();
        $this->assertNotFalse(strtotime($result['exported_at']));
    }

    // ── importOptions via executeJSON ────────────────────────────

    public function test_importOptions_returns_count_structure(): void {
        $result = $this->callImport([]);
        $this->assertIsArray($result);
        $this->assertArrayHasKey('imported', $result);
        $this->assertArrayHasKey('skipped', $result);
    }

    public function test_importOptions_with_empty_returns_zero(): void {
        $result = $this->callImport([]);
        $this->assertSame(0, $result['imported']);
        $this->assertSame(0, $result['skipped']);
    }

    public function test_importOptions_skips_unknown_keys(): void {
        $result = $this->callImport([
            'totally_fake_key_xyz' => 'value1',
            'another_unknown_abc' => 'value2',
        ]);
        $this->assertSame(0, $result['imported']);
        $this->assertSame(2, $result['skipped']);
    }

    public function test_importOptions_imports_valid_text_option(): void {
        $textOpt = $this->findFirstTextOption();
        if (!$textOpt) $this->markTestSkipped('No text option found');

        $testValue = 'import_test_' . time();
        $result = $this->callImport([$textOpt['key'] => $testValue]);

        $this->assertSame(1, $result['imported']);
        $this->assertSame($testValue, get_option('sasoEventtickets' . $textOpt['key']));
    }

    public function test_importOptions_mixed_valid_and_unknown(): void {
        $textOpt = $this->findFirstTextOption();
        if (!$textOpt) $this->markTestSkipped('No text option found');

        $result = $this->callImport([
            $textOpt['key'] => 'test_value',
            'fake_nonexistent_key' => 'should_skip',
        ]);
        $this->assertSame(1, $result['imported']);
        $this->assertSame(1, $result['skipped']);
    }

    public function test_importOptions_checkbox_to_zero(): void {
        $key = 'displayAdminAreaColumnConfirmedCount';
        $optionId = 'sasoEventtickets' . $key;

        update_option($optionId, 1, false);
        $this->assertEquals('1', get_option($optionId));

        $result = $this->callImport([$key => 0]);

        $this->assertSame(1, $result['imported']);
        $this->assertEquals(0, intval(get_option($optionId)));
    }

    public function test_importOptions_checkbox_zero_string(): void {
        $key = 'displayAdminAreaColumnConfirmedCount';
        $optionId = 'sasoEventtickets' . $key;

        update_option($optionId, 1, false);
        $result = $this->callImport([$key => "0"]);

        $this->assertSame(1, $result['imported']);
        $this->assertEquals(0, intval(get_option($optionId)));
    }

    public function test_importOptions_checkbox_zero_survives_fresh_reload(): void {
        $key = 'displayAdminAreaColumnConfirmedCount';
        update_option('sasoEventtickets' . $key, 1, false);

        $this->callImport([$key => 0]);

        // Simulate fresh PHP request with new Options instance
        $freshOptions = new sasoEventtickets_Options(sasoEventtickets::Instance(), 'sasoEventtickets');
        $freshOptions->initOptions();
        $this->assertFalse($freshOptions->isOptionCheckboxActive($key));
    }

    // ── wp_magic_quotes regression (stripslashes) ───────────────

    /**
     * WordPress wp_magic_quotes() calls addslashes() on all $_POST values.
     * The JSON string arrives as {\"key\":\"val\"} instead of {"key":"val"}.
     * importOptions must handle this via stripslashes().
     */
    public function test_importOptions_handles_wp_magic_quotes(): void {
        $textOpt = $this->findFirstTextOption();
        if (!$textOpt) $this->markTestSkipped('No text option found');

        $testValue = 'magic_quotes_test_' . time();
        $options = [$textOpt['key'] => $testValue];

        // Simulate wp_magic_quotes: addslashes on the JSON string
        $slashedJson = addslashes(json_encode($options));
        $result = $this->admin->executeJSON('importOptions', ['options' => $slashedJson], true, true);

        $this->assertSame(1, $result['imported']);
        $this->assertSame($testValue, get_option('sasoEventtickets' . $textOpt['key']));
    }

    public function test_importOptions_handles_wp_magic_quotes_checkbox(): void {
        $key = 'displayAdminAreaColumnConfirmedCount';
        $optionId = 'sasoEventtickets' . $key;

        update_option($optionId, 1, false);

        // Simulate wp_magic_quotes on JSON with checkbox=0
        $slashedJson = addslashes(json_encode([$key => 0]));
        $result = $this->admin->executeJSON('importOptions', ['options' => $slashedJson], true, true);

        $this->assertSame(1, $result['imported']);
        $this->assertEquals(0, intval(get_option($optionId)));
    }

    public function test_importOptions_roundtrip_with_wp_magic_quotes(): void {
        $exported = $this->callExport();
        $this->assertNotEmpty($exported['options']);

        // Simulate wp_magic_quotes on the full export payload
        $slashedJson = addslashes(json_encode($exported['options']));
        $result = $this->admin->executeJSON('importOptions', ['options' => $slashedJson], true, true);

        $this->assertSame(count($exported['options']), $result['imported']);
        $this->assertSame(0, $result['skipped']);
    }

    // ── round-trip ───────────────────────────────────────────────

    public function test_export_then_import_roundtrip(): void {
        $exported = $this->callExport();
        $this->assertNotEmpty($exported['options']);

        $result = $this->callImport($exported['options']);
        $this->assertSame(count($exported['options']), $result['imported']);
        $this->assertSame(0, $result['skipped']);
    }

    public function test_roundtrip_checkbox_change_persists(): void {
        $key = 'displayAdminAreaColumnConfirmedCount';
        $optionId = 'sasoEventtickets' . $key;

        // Set checkbox to 1 via changeOption (proper way, updates cache too)
        sasoEventtickets::Instance()->getOptions()->changeOption(['key' => $key, 'value' => 1]);

        // Export with fresh Options instance (simulates separate AJAX request)
        $freshOpts1 = new sasoEventtickets_Options(sasoEventtickets::Instance(), 'sasoEventtickets');
        $freshOpts1->initOptions();
        $val = $freshOpts1->getOptionValue($key);
        $this->assertEquals(1, intval($val), "Checkbox should be 1 after changeOption");

        // User edits JSON: set checkbox to 0, then imports
        $this->callImport([$key => 0]);

        // Verify via another fresh Options instance (simulates page reload)
        $freshOpts2 = new sasoEventtickets_Options(sasoEventtickets::Instance(), 'sasoEventtickets');
        $freshOpts2->initOptions();
        $this->assertFalse($freshOpts2->isOptionCheckboxActive($key));
        $this->assertEquals(0, intval(get_option($optionId)));
    }

    // ── helpers ──────────────────────────────────────────────────

    private function findFirstTextOption(): ?array {
        foreach (sasoEventtickets::Instance()->getOptions()->getOptions() as $opt) {
            if ($opt['type'] === 'text' && !empty($opt['key'])) return $opt;
        }
        return null;
    }
}
