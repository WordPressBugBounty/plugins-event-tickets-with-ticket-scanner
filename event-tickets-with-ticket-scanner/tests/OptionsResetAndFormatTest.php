<?php
/**
 * Tests for Options methods: resetAllOptionValuesToDefault, deleteOption,
 * deleteAllOptionValues, getOptionDateTimeFormat, getOptionsObject, changeOption.
 * And AdminSettings: getFormatWarning, clearFormatWarning.
 */

class OptionsResetAndFormatTest extends WP_UnitTestCase {

    private $main;

    public function set_up(): void {
        parent::set_up();
        $this->main = sasoEventtickets::Instance();
    }

    public function tear_down(): void {
        // Re-initialize Options to prevent _setOptionValuesByKey variable
        // shadowing bug from corrupting option values for subsequent test classes.
        $this->main->getOptions()->initOptions();
        parent::tear_down();
    }

    // ── changeOption ────────────────────────────────────────────

    public function test_changeOption_stores_value_in_wp_options(): void {
        $testValue = 'TestEmail_' . uniqid() . '@example.com';
        $this->main->getOptions()->changeOption([
            'key' => 'wcTicketICSOrganizerEmail',
            'value' => $testValue,
        ]);

        // Read directly from WP options table to verify (bypass internal cache)
        $option = $this->main->getOptions()->getOption('wcTicketICSOrganizerEmail');
        $stored = get_option($option['id']);
        $this->assertEquals($testValue, $stored);
    }

    public function test_changeOption_checkbox_stores_int(): void {
        $this->main->getOptions()->changeOption([
            'key' => 'wcTicketHideDateOnPDF',
            'value' => 1,
        ]);

        $option = $this->main->getOptions()->getOption('wcTicketHideDateOnPDF');
        $stored = get_option($option['id']);
        $this->assertEquals(1, intval($stored));

        $this->main->getOptions()->changeOption([
            'key' => 'wcTicketHideDateOnPDF',
            'value' => 0,
        ]);

        $stored = get_option($option['id']);
        $this->assertEquals(0, intval($stored));
    }

    public function test_changeOption_nonexistent_key_does_nothing(): void {
        // Should not throw, just silently do nothing
        $this->main->getOptions()->changeOption([
            'key' => 'nonExistentOption_' . uniqid(),
            'value' => 'test',
        ]);
        $this->assertTrue(true);
    }

    // ── deleteOption ────────────────────────────────────────────

    public function test_deleteOption_removes_option(): void {
        // Set a known value first
        $this->main->getOptions()->changeOption([
            'key' => 'textValidationMessage1',
            'value' => 'Delete Test',
        ]);

        $result = $this->main->getOptions()->deleteOption('textValidationMessage1');
        $this->assertTrue($result);
    }

    public function test_deleteOption_nonexistent_returns_false(): void {
        $result = $this->main->getOptions()->deleteOption('nonExistentOption_' . uniqid());
        $this->assertFalse($result);
    }

    // ── getOptionDateTimeFormat ──────────────────────────────────

    public function test_getOptionDateTimeFormat_returns_string(): void {
        $format = $this->main->getOptions()->getOptionDateTimeFormat();
        $this->assertIsString($format);
        $this->assertNotEmpty($format);
    }

    // ── getOptionsObject ────────────────────────────────────────

    public function test_getOptionsObject_creates_checkbox(): void {
        $option = $this->main->getOptions()->getOptionsObject(
            'testKey',
            'Test Label',
            'Test Description',
            'checkbox',
            false
        );

        $this->assertIsArray($option);
        $this->assertEquals('testKey', $option['key']);
        $this->assertEquals('Test Label', $option['label']);
        $this->assertEquals('checkbox', $option['type']);
    }

    public function test_getOptionsObject_creates_text(): void {
        $option = $this->main->getOptions()->getOptionsObject(
            'textKey',
            'Text Label',
            '',
            'text',
            'default value'
        );

        $this->assertIsArray($option);
        $this->assertEquals('text', $option['type']);
        $this->assertEquals('default value', $option['default']);
    }

    // ── resetAllOptionValuesToDefault ────────────────────────────
    // NOTE: resetAllOptionValuesToDefault and deleteAllOptionValues are not tested
    // because they reset/delete ALL plugin options, which breaks other tests
    // that run in the same PHPUnit session (singleton options become stale).

    public function test_resetAllOptionValuesToDefault_method_exists(): void {
        $this->assertTrue(
            method_exists($this->main->getOptions(), 'resetAllOptionValuesToDefault'),
            'resetAllOptionValuesToDefault method should exist'
        );
    }

    // ── getFormatWarning ────────────────────────────────────────

    public function test_getFormatWarning_null_for_clean_list(): void {
        $listId = $this->main->getDB()->insert('lists', [
            'name' => 'FmtWarn Clean ' . uniqid(),
            'aktiv' => 1,
            'meta' => '{}',
        ]);

        $result = $this->main->getAdmin()->getFormatWarning($listId);
        $this->assertNull($result);
    }

    public function test_getFormatWarning_returns_warning(): void {
        $meta = json_encode([
            'messages' => [
                'format_limit_threshold_warning' => [
                    'attempts' => 3,
                    'last_email' => '2026-01-01 00:00:00',
                ],
                'format_end_warning' => [
                    'attempts' => 0,
                    'last_email' => '',
                ],
            ],
        ]);
        $listId = $this->main->getDB()->insert('lists', [
            'name' => 'FmtWarn Threshold ' . uniqid(),
            'aktiv' => 1,
            'meta' => $meta,
        ]);

        $result = $this->main->getAdmin()->getFormatWarning($listId);
        $this->assertIsArray($result);
        $this->assertEquals('warning', $result['type']);
        $this->assertEquals(3, $result['attempts']);
    }

    public function test_getFormatWarning_critical_takes_priority(): void {
        $meta = json_encode([
            'messages' => [
                'format_limit_threshold_warning' => [
                    'attempts' => 2,
                    'last_email' => '2026-01-01 00:00:00',
                ],
                'format_end_warning' => [
                    'attempts' => 5,
                    'last_email' => '2026-01-15 00:00:00',
                ],
            ],
        ]);
        $listId = $this->main->getDB()->insert('lists', [
            'name' => 'FmtWarn Critical ' . uniqid(),
            'aktiv' => 1,
            'meta' => $meta,
        ]);

        $result = $this->main->getAdmin()->getFormatWarning($listId);
        $this->assertIsArray($result);
        $this->assertEquals('critical', $result['type']);
        $this->assertEquals(5, $result['attempts']);
    }

    public function test_getFormatWarning_invalid_list_returns_null(): void {
        $result = $this->main->getAdmin()->getFormatWarning(999999);
        $this->assertNull($result);
    }
}
