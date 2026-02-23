<?php
/**
 * Tests for Options methods: getOptionDateFormat, getOptionTimeFormat,
 * getOptionsKeys, getOptionsOnlyPublic, loadOptionFromWP,
 * get_wcTicketAttachTicketToMailOf.
 */

class OptionsFormatsTest extends WP_UnitTestCase {

    private $main;

    public function set_up(): void {
        parent::set_up();
        $this->main = sasoEventtickets::Instance();
    }

    // ── getOptionDateFormat ──────────────────────────────────────

    public function test_getOptionDateFormat_returns_string(): void {
        $format = $this->main->getOptions()->getOptionDateFormat();
        $this->assertIsString($format);
        $this->assertNotEmpty($format);
    }

    public function test_getOptionDateFormat_produces_valid_date(): void {
        $format = $this->main->getOptions()->getOptionDateFormat();
        $result = wp_date($format);
        $this->assertIsString($result);
        $this->assertNotEmpty($result);
    }

    // ── getOptionTimeFormat ──────────────────────────────────────

    public function test_getOptionTimeFormat_returns_string(): void {
        $format = $this->main->getOptions()->getOptionTimeFormat();
        $this->assertIsString($format);
        $this->assertNotEmpty($format);
    }

    public function test_getOptionTimeFormat_produces_valid_time(): void {
        $format = $this->main->getOptions()->getOptionTimeFormat();
        $result = wp_date($format);
        $this->assertIsString($result);
        $this->assertNotEmpty($result);
    }

    // ── getOptionsKeys ───────────────────────────────────────────

    public function test_getOptionsKeys_returns_array(): void {
        $keys = $this->main->getOptions()->getOptionsKeys();
        $this->assertIsArray($keys);
        $this->assertNotEmpty($keys);
    }

    public function test_getOptionsKeys_contains_known_keys(): void {
        $keys = $this->main->getOptions()->getOptionsKeys();
        $this->assertContains('wcTicketICSOrganizerEmail', $keys);
        $this->assertContains('wcTicketHideDateOnPDF', $keys);
    }

    // ── getOptionsOnlyPublic ─────────────────────────────────────

    public function test_getOptionsOnlyPublic_returns_array(): void {
        $options = $this->main->getOptions()->getOptionsOnlyPublic();
        $this->assertIsArray($options);
    }

    public function test_getOptionsOnlyPublic_all_are_public(): void {
        $options = $this->main->getOptions()->getOptionsOnlyPublic();
        foreach ($options as $option) {
            $this->assertTrue($option['isPublic'], 'All returned options should be public');
        }
    }

    // ── loadOptionFromWP ─────────────────────────────────────────

    public function test_loadOptionFromWP_returns_default_for_nonexistent(): void {
        $result = $this->main->getOptions()->loadOptionFromWP('nonexistent_option_xyz', 'fallback');
        $this->assertEquals('fallback', $result);
    }

    public function test_loadOptionFromWP_returns_stored_value(): void {
        // Use a custom prefix to avoid needing protected $_prefix
        update_option('testprefix_load_option_xyz', 'stored_value');

        $result = $this->main->getOptions()->loadOptionFromWP('load_option_xyz', null, 'testprefix_');
        $this->assertEquals('stored_value', $result);

        delete_option('testprefix_load_option_xyz');
    }

    // ── get_wcTicketAttachTicketToMailOf ──────────────────────────

    public function test_get_wcTicketAttachTicketToMailOf_returns_array(): void {
        $result = $this->main->getOptions()->get_wcTicketAttachTicketToMailOf();
        $this->assertIsArray($result);
        $this->assertNotEmpty($result);
    }

    public function test_get_wcTicketAttachTicketToMailOf_contains_default_hooks(): void {
        $result = $this->main->getOptions()->get_wcTicketAttachTicketToMailOf();
        // Free version defaults
        $this->assertContains('customer_processing_order', $result);
        $this->assertContains('customer_completed_order', $result);
    }
}
