<?php
/**
 * Tests for Ticket methods: getWPMLProductId, isSubscriptionActive.
 */

class TicketWPMLAndSubscriptionTest extends WP_UnitTestCase {

    private $main;

    public function set_up(): void {
        parent::set_up();
        $this->main = sasoEventtickets::Instance();
    }

    // ── getWPMLProductId ──────────────────────────────────────────

    public function test_getWPMLProductId_returns_same_id_without_wpml(): void {
        // Without WPML, the filter returns the same ID
        $result = $this->main->getTicketHandler()->getWPMLProductId(42);
        $this->assertEquals(42, $result);
    }

    public function test_getWPMLProductId_returns_original_for_zero(): void {
        $result = $this->main->getTicketHandler()->getWPMLProductId(0);
        $this->assertEquals(0, $result);
    }

    public function test_getWPMLProductId_returns_original_for_null(): void {
        $result = $this->main->getTicketHandler()->getWPMLProductId(null);
        $this->assertNull($result);
    }

    public function test_getWPMLProductId_handles_real_product(): void {
        if (!class_exists('WC_Product_Simple')) {
            $this->markTestSkipped('WooCommerce not available');
        }

        $product = new WC_Product_Simple();
        $product->set_name('WPML Test ' . uniqid());
        $product->set_regular_price('10.00');
        $product->save();

        $result = $this->main->getTicketHandler()->getWPMLProductId($product->get_id());
        // Without WPML, should return same product ID
        $this->assertEquals($product->get_id(), $result);
    }

    // ── isSubscriptionActive ──────────────────────────────────────

    public function test_isSubscriptionActive_returns_bool(): void {
        $result = $this->main->getTicketHandler()->isSubscriptionActive();
        $this->assertIsBool($result);
    }

    public function test_isSubscriptionActive_lifetime_is_active(): void {
        // Set lifetime subscription data
        $info = $this->main->getTicketHandler()->get_expiration();
        $info['timestamp'] = -1;
        $info['subscription_type'] = 'lifetime';
        update_option('saso_eventtickets_expiration', $info);

        $result = $this->main->getTicketHandler()->isSubscriptionActive();
        $this->assertTrue($result);
    }

    public function test_isSubscriptionActive_consecutive_failures_field_exists(): void {
        // Verify the expiration info has the consecutive_failures field
        $info = $this->main->getTicketHandler()->get_expiration();
        $this->assertArrayHasKey('consecutive_failures', $info);
        $this->assertIsNumeric($info['consecutive_failures']);
    }
}
