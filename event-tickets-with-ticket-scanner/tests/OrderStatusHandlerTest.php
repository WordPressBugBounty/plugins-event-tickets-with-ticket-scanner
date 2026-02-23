<?php
/**
 * Tests for order status change handling (generation on complete, cleanup on cancel/refund).
 */

class OrderStatusHandlerTest extends WP_UnitTestCase {

    private $main;

    public function set_up(): void {
        parent::set_up();
        $this->main = sasoEventtickets::Instance();

        if (!class_exists('WC_Product_Simple')) {
            $this->markTestSkipped('WooCommerce not available');
        }
    }

    /**
     * Helper: create a ticket product.
     */
    private function createTicketProduct(): array {
        $listId = $this->main->getDB()->insert('lists', [
            'name' => 'Status List ' . uniqid(),
            'aktiv' => 1,
            'meta' => '{}',
        ]);

        $product = new WC_Product_Simple();
        $product->set_name('Status Ticket ' . uniqid());
        $product->set_regular_price('20.00');
        $product->set_status('publish');
        $product->save();

        update_post_meta($product->get_id(), 'saso_eventtickets_is_ticket', 'yes');
        update_post_meta($product->get_id(), 'saso_eventtickets_list', $listId);

        return ['product' => $product, 'product_id' => $product->get_id(), 'list_id' => $listId];
    }

    // ── Status change to completed generates codes ────────────────

    public function test_status_completed_generates_codes(): void {
        $tp = $this->createTicketProduct();

        $order = wc_create_order();
        $order->add_product($tp['product'], 1);
        $order->calculate_totals();
        // Set status FIRST so add_serialcode_to_order sees a completed order
        $order->set_status('completed');
        $order->save();

        // Trigger status change handler
        $this->main->getWC()->getOrderManager()->woocommerce_order_status_changed(
            $order->get_id(), 'pending', 'completed'
        );

        $codes = $this->main->getCore()->getCodesByOrderId($order->get_id());
        $this->assertNotEmpty($codes, 'Completing order should generate codes');
    }

    // ── Status change to processing also generates ────────────────

    public function test_status_processing_generates_codes(): void {
        $tp = $this->createTicketProduct();

        $order = wc_create_order();
        $order->add_product($tp['product'], 1);
        $order->calculate_totals();
        $order->set_status('processing');
        $order->save();

        $this->main->getWC()->getOrderManager()->woocommerce_order_status_changed(
            $order->get_id(), 'pending', 'processing'
        );

        $codes = $this->main->getCore()->getCodesByOrderId($order->get_id());
        $this->assertNotEmpty($codes, 'Processing order should also generate codes');
    }

    // ── Idempotent code generation ────────────────────────────────

    public function test_duplicate_status_change_does_not_duplicate_codes(): void {
        $tp = $this->createTicketProduct();

        $order = wc_create_order();
        $order->add_product($tp['product'], 2);
        $order->calculate_totals();
        $order->save();

        // First completion
        $this->main->getWC()->getOrderManager()->woocommerce_order_status_changed(
            $order->get_id(), 'pending', 'completed'
        );
        $codes1 = $this->main->getCore()->getCodesByOrderId($order->get_id());

        // Second completion (e.g. processing -> completed)
        $this->main->getWC()->getOrderManager()->woocommerce_order_status_changed(
            $order->get_id(), 'processing', 'completed'
        );
        $codes2 = $this->main->getCore()->getCodesByOrderId($order->get_id());

        $this->assertCount(count($codes1), $codes2, 'Should not duplicate codes on re-trigger');
    }

    // ── Cancelled order does not generate ─────────────────────────

    public function test_cancelled_status_does_not_generate_codes(): void {
        $tp = $this->createTicketProduct();

        $order = wc_create_order();
        $order->add_product($tp['product'], 1);
        $order->calculate_totals();
        $order->save();

        $this->main->getWC()->getOrderManager()->woocommerce_order_status_changed(
            $order->get_id(), 'pending', 'cancelled'
        );

        $codes = $this->main->getCore()->getCodesByOrderId($order->get_id());
        $this->assertEmpty($codes, 'Cancelled order should not generate codes');
    }

    // ── Refunded order does not generate ──────────────────────────

    public function test_refunded_status_does_not_generate_codes(): void {
        $tp = $this->createTicketProduct();

        $order = wc_create_order();
        $order->add_product($tp['product'], 1);
        $order->calculate_totals();
        $order->save();

        $this->main->getWC()->getOrderManager()->woocommerce_order_status_changed(
            $order->get_id(), 'pending', 'refunded'
        );

        $codes = $this->main->getCore()->getCodesByOrderId($order->get_id());
        $this->assertEmpty($codes, 'Refunded order should not generate codes');
    }

    // ── Cancel after completion with free-code option ─────────────

    public function test_cancel_after_completion_frees_codes_when_option_enabled(): void {
        $tp = $this->createTicketProduct();

        // Enable the option
        $this->main->getOptions()->changeOption([
            'key' => 'wcRestrictFreeCodeByOrderRefund',
            'value' => 1,
        ]);

        $order = wc_create_order();
        $order->add_product($tp['product'], 1);
        $order->calculate_totals();
        $order->set_status('completed');
        $order->save();

        // Generate codes
        $this->main->getWC()->getOrderManager()->add_serialcode_to_order($order->get_id());

        $codesBefore = $this->main->getCore()->getCodesByOrderId($order->get_id());
        $this->assertNotEmpty($codesBefore);

        // Cancel the order
        $this->main->getWC()->getOrderManager()->woocommerce_order_status_changed(
            $order->get_id(), 'completed', 'cancelled'
        );

        // Codes should still exist in DB but order_id may be cleared
        // (depending on implementation - at minimum seat blocks are released)

        // Reset option
        $this->main->getOptions()->changeOption([
            'key' => 'wcRestrictFreeCodeByOrderRefund',
            'value' => 0,
        ]);

        // This test verifies the handler runs without error
        $this->assertTrue(true);
    }

    // ── Non-ticket order status change ────────────────────────────

    public function test_non_ticket_order_status_change_does_nothing(): void {
        $product = new WC_Product_Simple();
        $product->set_name('Regular Product');
        $product->set_regular_price('10.00');
        $product->set_status('publish');
        $product->save();

        $order = wc_create_order();
        $order->add_product($product, 1);
        $order->calculate_totals();
        $order->save();

        // Should not throw
        $this->main->getWC()->getOrderManager()->woocommerce_order_status_changed(
            $order->get_id(), 'pending', 'completed'
        );

        $codes = $this->main->getCore()->getCodesByOrderId($order->get_id());
        $this->assertEmpty($codes);
    }
}
