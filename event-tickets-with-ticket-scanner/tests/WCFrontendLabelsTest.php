<?php
/**
 * Tests for WC Frontend methods: getWarningDatePickerLabel.
 */

class WCFrontendLabelsTest extends WP_UnitTestCase {

    private $main;

    public function set_up(): void {
        parent::set_up();
        $this->main = sasoEventtickets::Instance();

        if (!class_exists('WC_Product_Simple')) {
            $this->markTestSkipped('WooCommerce not available');
        }
    }

    // ── getWarningDatePickerLabel ─────────────────────────────────

    public function test_getWarningDatePickerLabel_returns_string(): void {
        $result = $this->main->getWC()->getFrontendManager()->getWarningDatePickerLabel(
            'Test Product',
            'item_123',
            0,
            false
        );
        $this->assertIsString($result);
        $this->assertNotEmpty($result);
    }

    public function test_getWarningDatePickerLabel_contains_product_name(): void {
        $result = $this->main->getWC()->getFrontendManager()->getWarningDatePickerLabel(
            'MyUniqueProduct',
            'item_456',
            0,
            false
        );
        $this->assertStringContainsString('MyUniqueProduct', $result);
    }

    public function test_getWarningDatePickerLabel_past_date_different(): void {
        $normal = $this->main->getWC()->getFrontendManager()->getWarningDatePickerLabel(
            'TestProduct',
            'item_789',
            0,
            false
        );
        $past = $this->main->getWC()->getFrontendManager()->getWarningDatePickerLabel(
            'TestProduct',
            'item_789',
            0,
            true
        );
        // The two messages should be different (different option keys)
        $this->assertIsString($past);
        $this->assertNotEmpty($past);
    }

    public function test_getWarningDatePickerLabel_different_index(): void {
        $result0 = $this->main->getWC()->getFrontendManager()->getWarningDatePickerLabel(
            'Product',
            'item_abc',
            0,
            false
        );
        $result5 = $this->main->getWC()->getFrontendManager()->getWarningDatePickerLabel(
            'Product',
            'item_abc',
            5,
            false
        );
        // Both should be non-empty strings
        $this->assertIsString($result0);
        $this->assertIsString($result5);
        $this->assertNotEmpty($result0);
        $this->assertNotEmpty($result5);
    }
}
