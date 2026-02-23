<?php
/**
 * Integration tests for the Options system.
 */

class OptionsTest extends WP_UnitTestCase {

    private sasoEventtickets_Options $options;

    public function set_up(): void {
        parent::set_up();
        $this->options = sasoEventtickets::Instance()->getOptions();
    }

    // ── getOptions ─────────────────────────────────────────────

    public function test_getOptions_returns_non_empty_array(): void {
        $opts = $this->options->getOptions();
        $this->assertIsArray($opts);
        $this->assertNotEmpty($opts);
    }

    public function test_getOptions_each_has_key_and_type(): void {
        $opts = $this->options->getOptions();
        foreach ($opts as $opt) {
            $this->assertArrayHasKey('key', $opt, 'Option missing key field');
            $this->assertArrayHasKey('type', $opt, "Option '{$opt['key']}' missing type field");
        }
    }

    public function test_getOptions_known_option_exists(): void {
        $opts = $this->options->getOptions();
        $keys = array_column($opts, 'key');
        $this->assertContains('wcTicketScannerAllowedRoles', $keys);
    }

    // ── getOptionsKeys ─────────────────────────────────────────

    public function test_getOptionsKeys_returns_strings(): void {
        $keys = $this->options->getOptionsKeys();
        $this->assertIsArray($keys);
        $this->assertNotEmpty($keys);
        foreach ($keys as $key) {
            $this->assertIsString($key);
        }
    }

    // ── getOption ──────────────────────────────────────────────

    public function test_getOption_returns_known_option(): void {
        $opt = $this->options->getOption('wcTicketScannerAllowedRoles');
        $this->assertIsArray($opt);
        $this->assertSame('wcTicketScannerAllowedRoles', $opt['key']);
    }

    public function test_getOption_returns_null_for_unknown(): void {
        $opt = $this->options->getOption('this_option_does_not_exist_xyz');
        $this->assertNull($opt);
    }

    // ── getOptionValue ─────────────────────────────────────────

    public function test_getOptionValue_returns_default_for_unknown(): void {
        $val = $this->options->getOptionValue('nonexistent_xyz', 'fallback');
        $this->assertSame('fallback', $val);
    }

    // ── isOptionCheckboxActive ─────────────────────────────────

    public function test_isOptionCheckboxActive_returns_bool(): void {
        $result = $this->options->isOptionCheckboxActive('displayFirstStepsHelp');
        $this->assertIsBool($result);
    }

    // ── changeOption round-trip ────────────────────────────────

    public function test_changeOption_and_read_back(): void {
        // Find a text option to test with
        $opts = $this->options->getOptions();
        $textOpt = null;
        foreach ($opts as $opt) {
            if ($opt['type'] === 'text' && !empty($opt['key'])) {
                $textOpt = $opt;
                break;
            }
        }
        if ($textOpt === null) {
            $this->markTestSkipped('No text option found for round-trip test');
        }

        $key = $textOpt['key'];
        $testValue = 'phpunit_test_value_' . time();

        $this->options->changeOption(['key' => $key, 'value' => $testValue]);

        // Force reload
        $freshValue = get_option('sasoEventtickets' . $key);
        $this->assertSame($testValue, $freshValue);
    }

    // ── date/time format getters ───────────────────────────────

    public function test_getOptionDateFormat_returns_string(): void {
        $format = $this->options->getOptionDateFormat();
        $this->assertIsString($format);
        $this->assertNotEmpty($format);
    }

    public function test_getOptionTimeFormat_returns_string(): void {
        $format = $this->options->getOptionTimeFormat();
        $this->assertIsString($format);
        $this->assertNotEmpty($format);
    }

    public function test_getOptionDateTimeFormat_combines_date_and_time(): void {
        $dateFormat = $this->options->getOptionDateFormat();
        $timeFormat = $this->options->getOptionTimeFormat();
        $combined = $this->options->getOptionDateTimeFormat();
        $this->assertStringContainsString($dateFormat, $combined);
        $this->assertStringContainsString($timeFormat, $combined);
    }

    // ── getOptionsOnlyPublic ───────────────────────────────────

    public function test_getOptionsOnlyPublic_subset_of_all(): void {
        $all = $this->options->getOptions();
        $public = $this->options->getOptionsOnlyPublic();
        $this->assertLessThanOrEqual(count($all), count($public));
    }

    // ── get_wcTicketAttachTicketToMailOf ────────────────────────

    public function test_get_wcTicketAttachTicketToMailOf_returns_array(): void {
        $result = $this->options->get_wcTicketAttachTicketToMailOf();
        $this->assertIsArray($result);
    }
}
