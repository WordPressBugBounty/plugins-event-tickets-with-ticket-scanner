<?php
/**
 * Tests for WC Order ticket number methods:
 * hasTicketsInOrderWithTicketnumber, getTicketsFromOrder,
 * removeAllTicketsFromOrder, removeAllNonTicketsFromOrder.
 */

class WCOrderTicketNumberTest extends WP_UnitTestCase {

    private $main;

    public function set_up(): void {
        parent::set_up();
        $this->main = sasoEventtickets::Instance();

        if (!class_exists('WC_Product_Simple')) {
            $this->markTestSkipped('WooCommerce not available');
        }
    }

    private function createTicketOrder(): array {
        $listId = $this->main->getDB()->insert('lists', [
            'name' => 'TicketNum List ' . uniqid(),
            'aktiv' => 1,
            'meta' => '{}',
        ]);

        $product = new WC_Product_Simple();
        $product->set_name('TicketNum Product ' . uniqid());
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

        return [
            'order' => $order,
            'product' => $product,
            'list_id' => $listId,
            'order_id' => $order->get_id(),
        ];
    }

    // ── hasTicketsInOrderWithTicketnumber ─────────────────────────

    public function test_hasTicketsInOrderWithTicketnumber_false_for_non_ticket_order(): void {
        $product = new WC_Product_Simple();
        $product->set_name('NonTicketNum Product ' . uniqid());
        $product->set_regular_price('10.00');
        $product->set_status('publish');
        $product->save();

        $order = wc_create_order();
        $order->add_product($product, 1);
        $order->calculate_totals();
        $order->set_status('completed');
        $order->save();

        $om = $this->main->getWC()->getOrderManager();
        $this->assertFalse($om->hasTicketsInOrderWithTicketnumber($order));
    }

    public function test_hasTicketsInOrderWithTicketnumber_true_after_generation(): void {
        $data = $this->createTicketOrder();
        $om = $this->main->getWC()->getOrderManager();
        $om->add_serialcode_to_order($data['order_id']);
        // After generating codes, should have ticket numbers
        // Refresh order to pick up meta changes
        $order = wc_get_order($data['order_id']);
        $this->assertTrue($om->hasTicketsInOrderWithTicketnumber($order));
    }

    // ── getTicketsFromOrder ──────────────────────────────────────

    public function test_getTicketsFromOrder_returns_array(): void {
        $data = $this->createTicketOrder();
        $om = $this->main->getWC()->getOrderManager();
        $om->add_serialcode_to_order($data['order_id']);
        $order = wc_get_order($data['order_id']);

        $tickets = $om->getTicketsFromOrder($order);
        $this->assertIsArray($tickets);
        $this->assertNotEmpty($tickets);
    }

    public function test_getTicketsFromOrder_has_expected_keys(): void {
        $data = $this->createTicketOrder();
        $om = $this->main->getWC()->getOrderManager();
        $om->add_serialcode_to_order($data['order_id']);
        $order = wc_get_order($data['order_id']);

        $tickets = $om->getTicketsFromOrder($order);
        $ticketValues = array_values($tickets);
        $first = $ticketValues[0];
        $this->assertArrayHasKey('product_id', $first);
        $this->assertArrayHasKey('codes', $first);
    }

    public function test_getTicketsFromOrder_empty_for_non_ticket_order(): void {
        $product = new WC_Product_Simple();
        $product->set_name('NonTicket Product ' . uniqid());
        $product->set_regular_price('10.00');
        $product->set_status('publish');
        $product->save();

        $order = wc_create_order();
        $order->add_product($product, 1);
        $order->calculate_totals();
        $order->set_status('completed');
        $order->save();

        $om = $this->main->getWC()->getOrderManager();
        $tickets = $om->getTicketsFromOrder($order);
        $this->assertIsArray($tickets);
        $this->assertEmpty($tickets);
    }

    // ── removeAllTicketsFromOrder ─────────────────────────────────

    public function test_removeAllTicketsFromOrder_returns_true(): void {
        $data = $this->createTicketOrder();
        $om = $this->main->getWC()->getOrderManager();
        $om->add_serialcode_to_order($data['order_id']);

        $result = $om->removeAllTicketsFromOrder(['order_id' => $data['order_id']]);
        $this->assertTrue($result);
    }

    public function test_removeAllTicketsFromOrder_clears_codes(): void {
        $data = $this->createTicketOrder();
        $om = $this->main->getWC()->getOrderManager();
        $om->add_serialcode_to_order($data['order_id']);

        // Verify codes exist before removal
        $order = wc_get_order($data['order_id']);
        $this->assertTrue($om->hasTicketsInOrderWithTicketnumber($order));

        // Remove all tickets
        $om->removeAllTicketsFromOrder(['order_id' => $data['order_id']]);

        // Verify codes are gone
        $order = wc_get_order($data['order_id']);
        $this->assertFalse($om->hasTicketsInOrderWithTicketnumber($order));
    }

    // ── removeAllNonTicketsFromOrder ──────────────────────────────

    public function test_removeAllNonTicketsFromOrder_returns_true(): void {
        $data = $this->createTicketOrder();
        $om = $this->main->getWC()->getOrderManager();
        $om->add_serialcode_to_order($data['order_id']);

        $result = $om->removeAllNonTicketsFromOrder(['order_id' => $data['order_id']]);
        $this->assertTrue($result);
    }

    public function test_removeAllNonTicketsFromOrder_preserves_valid_codes(): void {
        $data = $this->createTicketOrder();
        $om = $this->main->getWC()->getOrderManager();
        $om->add_serialcode_to_order($data['order_id']);

        // Should still have tickets after removing non-tickets
        $om->removeAllNonTicketsFromOrder(['order_id' => $data['order_id']]);
        $order = wc_get_order($data['order_id']);
        $this->assertTrue($om->hasTicketsInOrderWithTicketnumber($order));
    }
}
