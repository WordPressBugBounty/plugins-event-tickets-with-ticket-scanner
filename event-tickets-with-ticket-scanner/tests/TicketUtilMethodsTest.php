<?php
/**
 * Tests for Ticket utility methods: ermittelCodePosition,
 * get_is_paid_statuses, getUserRedirectURLForCode.
 */

class TicketUtilMethodsTest extends WP_UnitTestCase {

    private $main;

    public function set_up(): void {
        parent::set_up();
        $this->main = sasoEventtickets::Instance();
    }

    // ── ermittelCodePosition ─────────────────────────────────────

    public function test_ermittelCodePosition_finds_first(): void {
        $codes = ['AAA', 'BBB', 'CCC'];
        $result = $this->main->getTicketHandler()->ermittelCodePosition('AAA', $codes);
        $this->assertEquals(1, $result);
    }

    public function test_ermittelCodePosition_finds_second(): void {
        $codes = ['AAA', 'BBB', 'CCC'];
        $result = $this->main->getTicketHandler()->ermittelCodePosition('BBB', $codes);
        $this->assertEquals(2, $result);
    }

    public function test_ermittelCodePosition_finds_third(): void {
        $codes = ['AAA', 'BBB', 'CCC'];
        $result = $this->main->getTicketHandler()->ermittelCodePosition('CCC', $codes);
        $this->assertEquals(3, $result);
    }

    public function test_ermittelCodePosition_not_found_returns_1(): void {
        $codes = ['AAA', 'BBB', 'CCC'];
        $result = $this->main->getTicketHandler()->ermittelCodePosition('ZZZ', $codes);
        $this->assertEquals(1, $result);
    }

    public function test_ermittelCodePosition_empty_array_returns_1(): void {
        $result = $this->main->getTicketHandler()->ermittelCodePosition('AAA', []);
        $this->assertEquals(1, $result);
    }

    // ── get_is_paid_statuses ─────────────────────────────────────

    public function test_get_is_paid_statuses_returns_array(): void {
        $result = $this->main->getTicketHandler()->get_is_paid_statuses();
        $this->assertIsArray($result);
        $this->assertNotEmpty($result);
    }

    public function test_get_is_paid_statuses_contains_completed(): void {
        $result = $this->main->getTicketHandler()->get_is_paid_statuses();
        $this->assertContains('completed', $result);
    }

    public function test_get_is_paid_statuses_contains_processing(): void {
        $result = $this->main->getTicketHandler()->get_is_paid_statuses();
        $this->assertContains('processing', $result);
    }

    // ── get_product ──────────────────────────────────────────────

    public function test_get_product_returns_product(): void {
        if (!class_exists('WC_Product_Simple')) {
            $this->markTestSkipped('WooCommerce not available');
        }

        $product = new WC_Product_Simple();
        $product->set_name('GetProduct Test ' . uniqid());
        $product->set_regular_price('10.00');
        $product->save();

        $result = $this->main->getTicketHandler()->get_product($product->get_id());
        $this->assertInstanceOf(WC_Product::class, $result);
        $this->assertEquals($product->get_id(), $result->get_id());
    }
}
