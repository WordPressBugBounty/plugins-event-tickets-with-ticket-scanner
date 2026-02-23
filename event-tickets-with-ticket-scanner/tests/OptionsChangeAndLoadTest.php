<?php
/**
 * Tests for Options change and loading methods:
 * changeOption, getOptions (full list), loadOptionFromWP,
 * getOption with lazy loading.
 */

class OptionsChangeAndLoadTest extends WP_UnitTestCase {

    private $main;

    public function set_up(): void {
        parent::set_up();
        $this->main = sasoEventtickets::Instance();
    }

    public function tear_down(): void {
        // Re-initialize Options to prevent cache corruption
        $this->main->getOptions()->initOptions();
        parent::tear_down();
    }

    // ── changeOption ─────────────────────────────────────────────

    public function test_changeOption_updates_value(): void {
        $options = $this->main->getOptions();
        $originalValue = $options->getOptionValue('wcTicketHeading');

        $options->changeOption(['key' => 'wcTicketHeading', 'value' => 'Changed Title']);
        // Force reload
        $options->initOptions();
        $newValue = $options->getOptionValue('wcTicketHeading');
        $this->assertEquals('Changed Title', $newValue);

        // Restore
        $options->changeOption(['key' => 'wcTicketHeading', 'value' => $originalValue]);
    }

    public function test_changeOption_trims_text_value(): void {
        $options = $this->main->getOptions();
        $options->changeOption(['key' => 'wcTicketHeading', 'value' => '  Trimmed Title  ']);
        $options->initOptions();
        $value = $options->getOptionValue('wcTicketHeading');
        $this->assertEquals('Trimmed Title', $value);
    }

    public function test_changeOption_checkbox_stores_int(): void {
        $options = $this->main->getOptions();
        $options->changeOption(['key' => 'wcTicketAllowRedeemOnlyPaid', 'value' => 1]);
        $options->initOptions();
        $result = $options->isOptionCheckboxActive('wcTicketAllowRedeemOnlyPaid');
        $this->assertTrue($result);

        // Reset
        $options->changeOption(['key' => 'wcTicketAllowRedeemOnlyPaid', 'value' => 0]);
    }

    // ── getOptions (full list) ───────────────────────────────────

    public function test_getOptions_returns_array(): void {
        $options = $this->main->getOptions()->getOptions();
        $this->assertIsArray($options);
        $this->assertNotEmpty($options);
    }

    public function test_getOptions_entries_have_value_loaded(): void {
        $options = $this->main->getOptions()->getOptions();
        foreach ($options as $opt) {
            $this->assertTrue($opt['_isLoaded'], "Option {$opt['key']} should be loaded");
        }
    }

    public function test_getOptions_entries_have_key_and_type(): void {
        $options = $this->main->getOptions()->getOptions();
        foreach ($options as $opt) {
            $this->assertArrayHasKey('key', $opt);
            $this->assertArrayHasKey('type', $opt);
        }
    }

    // ── loadOptionFromWP ─────────────────────────────────────────

    public function test_loadOptionFromWP_returns_saved_value(): void {
        $options = $this->main->getOptions();
        $options->changeOption(['key' => 'wcTicketHeading', 'value' => 'WP Load Test']);
        $options->initOptions();

        $opt = $options->getOption('wcTicketHeading');
        // loadOptionFromWP uses the option ID (prefixed)
        $result = $options->loadOptionFromWP('wcTicketHeading');
        $this->assertEquals('WP Load Test', $result);
    }

    public function test_loadOptionFromWP_default_for_missing(): void {
        $options = $this->main->getOptions();
        $result = $options->loadOptionFromWP('nonexistent_option_' . uniqid(), 'fallback_value');
        $this->assertEquals('fallback_value', $result);
    }

    // ── getOption lazy loading ────────────────────────────────────

    public function test_getOption_lazy_loads_value(): void {
        $options = $this->main->getOptions();
        // Set a known value
        $options->changeOption(['key' => 'wcTicketHeading', 'value' => 'Lazy Load Test']);
        $options->initOptions();

        $opt = $options->getOption('wcTicketHeading');
        $this->assertIsArray($opt);
        // After getOption, _isLoaded should become true after calling getOptions
        $allOptions = $options->getOptions();
        foreach ($allOptions as $o) {
            if ($o['key'] === 'wcTicketHeading') {
                $this->assertTrue($o['_isLoaded']);
                break;
            }
        }
    }

    // ── getOptionValue default handling ───────────────────────────

    public function test_getOptionValue_returns_default_for_unknown(): void {
        $result = $this->main->getOptions()->getOptionValue('completely_unknown_key_' . uniqid(), 'my_default');
        $this->assertEquals('my_default', $result);
    }

    public function test_getOptionValue_returns_value_for_known(): void {
        $options = $this->main->getOptions();
        $options->changeOption(['key' => 'wcTicketHeading', 'value' => 'Known Value Test']);
        $options->initOptions();

        $result = $options->getOptionValue('wcTicketHeading');
        $this->assertEquals('Known Value Test', $result);
    }
}
