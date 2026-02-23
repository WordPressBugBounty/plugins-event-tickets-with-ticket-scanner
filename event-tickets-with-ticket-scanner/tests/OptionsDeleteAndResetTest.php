<?php
/**
 * Tests for Options methods: deleteOption, getOptionsObject,
 * getOptionValue (via Admin wrapper), isOptionCheckboxActive (via Admin wrapper).
 */

class OptionsDeleteAndResetTest extends WP_UnitTestCase {

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

    // ── getOptionValue (via Admin) ───────────────────────────────

    public function test_getOptionValue_returns_value(): void {
        $result = $this->main->getAdmin()->getOptionValue('wcTicketLabelTicketcode');
        $this->assertIsString($result);
    }

    public function test_getOptionValue_returns_default_for_unknown(): void {
        $result = $this->main->getAdmin()->getOptionValue('nonexistent_option_xyz', 'my_default');
        $this->assertEquals('my_default', $result);
    }

    // ── isOptionCheckboxActive (via Admin) ────────────────────────

    public function test_isOptionCheckboxActive_returns_bool(): void {
        $result = $this->main->getAdmin()->isOptionCheckboxActive('wcTicketCompatibilityMode');
        $this->assertIsBool($result);
    }

    // ── deleteOption ──────────────────────────────────────────────

    public function test_deleteOption_returns_false_for_unknown(): void {
        $result = $this->main->getOptions()->deleteOption('nonexistent_key_xyz_' . uniqid());
        $this->assertFalse($result);
    }

    public function test_deleteOption_method_exists(): void {
        $this->assertTrue(method_exists($this->main->getOptions(), 'deleteOption'));
    }

    // ── getOptionsObject ─────────────────────────────────────────

    public function test_getOptionsObject_returns_array(): void {
        $result = $this->main->getOptions()->getOptionsObject(
            'test_key',
            'Test Label'
        );
        $this->assertIsArray($result);
        $this->assertEquals('test_key', $result['key']);
        $this->assertEquals('Test Label', $result['label']);
    }

    public function test_getOptionsObject_has_default_type(): void {
        $result = $this->main->getOptions()->getOptionsObject(
            'test_key2',
            'Test Label 2'
        );
        $this->assertArrayHasKey('type', $result);
    }

    public function test_getOptionsObject_with_custom_type(): void {
        $result = $this->main->getOptions()->getOptionsObject(
            'test_key3',
            'Test Label 3',
            'Description',
            'text',
            'custom_default'
        );
        $this->assertEquals('text', $result['type']);
        $this->assertEquals('custom_default', $result['default']);
    }
}
