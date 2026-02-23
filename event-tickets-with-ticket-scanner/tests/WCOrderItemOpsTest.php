<?php
/**
 * Tests for WC Order methods: removeAllNonTicketsFromOrder.
 */

class WCOrderItemOpsTest extends WP_UnitTestCase {

    private $main;

    public function set_up(): void {
        parent::set_up();
        $this->main = sasoEventtickets::Instance();

        if (!class_exists('WC_Product_Simple')) {
            $this->markTestSkipped('WooCommerce not available');
        }
    }

    private function createOrderWithTickets(): array {
        $listId = $this->main->getDB()->insert('lists', [
            'name' => 'WCItemOps Test ' . uniqid(),
            'aktiv' => 1,
            'meta' => '{}',
        ]);

        $product = new WC_Product_Simple();
        $product->set_name('WCItemOps Product ' . uniqid());
        $product->set_regular_price('10.00');
        $product->set_status('publish');
        $product->save();

        update_post_meta($product->get_id(), 'saso_eventtickets_is_ticket', 'yes');
        update_post_meta($product->get_id(), 'saso_eventtickets_list', $listId);

        $order = wc_create_order();
        $order->add_product($product, 2);
        $order->calculate_totals();
        $order->set_status('completed');
        $order->save();

        $this->main->getWC()->getOrderManager()->add_serialcode_to_order($order->get_id());
        $codes = $this->main->getCore()->getCodesByOrderId($order->get_id());

        return [
            'order' => $order,
            'order_id' => $order->get_id(),
            'codes' => $codes,
            'list_id' => $listId,
            'product_id' => $product->get_id(),
        ];
    }

    // ── removeAllNonTicketsFromOrder ──────────────────────────────

    public function test_removeAllNonTicketsFromOrder_returns_true(): void {
        $data = $this->createOrderWithTickets();

        $result = $this->main->getWC()->getOrderManager()->removeAllNonTicketsFromOrder([
            'order_id' => $data['order_id'],
        ]);
        $this->assertTrue($result);
    }

    public function test_removeAllNonTicketsFromOrder_zero_order_id(): void {
        // order_id=0 triggers early return — method still returns true
        $result = $this->main->getWC()->getOrderManager()->removeAllNonTicketsFromOrder([
            'order_id' => 0,
        ]);
        // Verify it doesn't throw and completes (even with invalid ID)
        $this->assertNotNull($result);
    }

    public function test_removeAllNonTicketsFromOrder_keeps_valid_codes(): void {
        $data = $this->createOrderWithTickets();
        $codesBefore = count($data['codes']);

        $this->main->getWC()->getOrderManager()->removeAllNonTicketsFromOrder([
            'order_id' => $data['order_id'],
        ]);

        // Valid codes should still be there
        $codesAfter = $this->main->getCore()->getCodesByOrderId($data['order_id']);
        $this->assertCount($codesBefore, $codesAfter);
    }
}
