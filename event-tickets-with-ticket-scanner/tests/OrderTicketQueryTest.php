<?php
/**
 * Tests for WC Order ticket query methods (hasTicketsInOrder, getTicketsFromOrder, removeAllTicketsFromOrder).
 */

class OrderTicketQueryTest extends WP_UnitTestCase {

    private $main;

    public function set_up(): void {
        parent::set_up();
        $this->main = sasoEventtickets::Instance();

        if (!class_exists('WC_Product_Simple')) {
            $this->markTestSkipped('WooCommerce not available');
        }
    }

    /**
     * Helper: create a ticket product with list.
     */
    private function createTicketProduct(): array {
        $listId = $this->main->getDB()->insert('lists', [
            'name' => 'OTQ List ' . uniqid(),
            'aktiv' => 1,
            'meta' => '{}',
        ]);

        $product = new WC_Product_Simple();
        $product->set_name('OTQ Ticket');
        $product->set_regular_price('10.00');
        $product->set_status('publish');
        $product->save();

        update_post_meta($product->get_id(), 'saso_eventtickets_is_ticket', 'yes');
        update_post_meta($product->get_id(), 'saso_eventtickets_list', $listId);

        return ['product' => $product, 'list_id' => $listId];
    }

    /**
     * Helper: create a non-ticket product.
     */
    private function createNonTicketProduct(): WC_Product_Simple {
        $product = new WC_Product_Simple();
        $product->set_name('Non-Ticket Product');
        $product->set_regular_price('5.00');
        $product->set_status('publish');
        $product->save();

        return $product;
    }

    // ── hasTicketsInOrder ─────────────────────────────────────────

    public function test_hasTicketsInOrder_true_with_ticket(): void {
        $tp = $this->createTicketProduct();

        $order = wc_create_order();
        $order->add_product($tp['product'], 1);
        $order->save();

        $orderManager = $this->main->getWC()->getOrderManager();
        $this->assertTrue($orderManager->hasTicketsInOrder($order));
    }

    public function test_hasTicketsInOrder_false_without_ticket(): void {
        $product = $this->createNonTicketProduct();

        $order = wc_create_order();
        $order->add_product($product, 1);
        $order->save();

        $orderManager = $this->main->getWC()->getOrderManager();
        $this->assertFalse($orderManager->hasTicketsInOrder($order));
    }

    public function test_hasTicketsInOrder_mixed_products(): void {
        $tp = $this->createTicketProduct();
        $nonTicket = $this->createNonTicketProduct();

        $order = wc_create_order();
        $order->add_product($nonTicket, 1);
        $order->add_product($tp['product'], 1);
        $order->save();

        $orderManager = $this->main->getWC()->getOrderManager();
        $this->assertTrue($orderManager->hasTicketsInOrder($order));
    }

    // ── hasTicketsInOrderWithTicketnumber ──────────────────────────

    public function test_hasTicketsInOrderWithTicketnumber_false_before_generation(): void {
        $tp = $this->createTicketProduct();

        $order = wc_create_order();
        $order->add_product($tp['product'], 1);
        $order->save();

        $orderManager = $this->main->getWC()->getOrderManager();
        // Ticket product in order but no codes generated yet
        $this->assertFalse($orderManager->hasTicketsInOrderWithTicketnumber($order));
    }

    public function test_hasTicketsInOrderWithTicketnumber_true_after_generation(): void {
        $tp = $this->createTicketProduct();

        $order = wc_create_order();
        $order->add_product($tp['product'], 1);
        $order->calculate_totals();
        $order->set_status('completed');
        $order->save();

        // Generate codes
        $this->main->getWC()->getOrderManager()->add_serialcode_to_order($order->get_id());

        // Re-fetch order
        $order = wc_get_order($order->get_id());
        $orderManager = $this->main->getWC()->getOrderManager();
        $this->assertTrue($orderManager->hasTicketsInOrderWithTicketnumber($order));
    }

    // ── getTicketsFromOrder ───────────────────────────────────────

    public function test_getTicketsFromOrder_returns_ticket_products_only(): void {
        $tp = $this->createTicketProduct();
        $nonTicket = $this->createNonTicketProduct();

        $order = wc_create_order();
        $order->add_product($nonTicket, 1);
        $order->add_product($tp['product'], 2);
        $order->save();

        $orderManager = $this->main->getWC()->getOrderManager();
        $tickets = $orderManager->getTicketsFromOrder($order);

        $this->assertCount(1, $tickets);

        $ticket = reset($tickets);
        $this->assertEquals(2, $ticket['quantity']);
        $this->assertEquals($tp['product']->get_id(), $ticket['product_id']);
    }

    public function test_getTicketsFromOrder_empty_for_non_ticket_order(): void {
        $product = $this->createNonTicketProduct();

        $order = wc_create_order();
        $order->add_product($product, 1);
        $order->save();

        $orderManager = $this->main->getWC()->getOrderManager();
        $tickets = $orderManager->getTicketsFromOrder($order);
        $this->assertEmpty($tickets);
    }

    public function test_getTicketsFromOrder_includes_codes_after_generation(): void {
        $tp = $this->createTicketProduct();

        $order = wc_create_order();
        $order->add_product($tp['product'], 1);
        $order->calculate_totals();
        $order->set_status('completed');
        $order->save();

        $this->main->getWC()->getOrderManager()->add_serialcode_to_order($order->get_id());

        $order = wc_get_order($order->get_id());
        $tickets = $this->main->getWC()->getOrderManager()->getTicketsFromOrder($order);

        $ticket = reset($tickets);
        $this->assertNotEmpty($ticket['codes']);
    }

    // ── removeAllTicketsFromOrder ─────────────────────────────────

    public function test_removeAllTicketsFromOrder_returns_true(): void {
        $tp = $this->createTicketProduct();

        $order = wc_create_order();
        $order->add_product($tp['product'], 1);
        $order->calculate_totals();
        $order->set_status('completed');
        $order->save();

        $this->main->getWC()->getOrderManager()->add_serialcode_to_order($order->get_id());

        $result = $this->main->getWC()->getOrderManager()->removeAllTicketsFromOrder([
            'order_id' => $order->get_id(),
        ]);
        $this->assertTrue($result);
    }

    public function test_removeAllTicketsFromOrder_clears_codes(): void {
        $tp = $this->createTicketProduct();

        $order = wc_create_order();
        $order->add_product($tp['product'], 2);
        $order->calculate_totals();
        $order->set_status('completed');
        $order->save();

        $this->main->getWC()->getOrderManager()->add_serialcode_to_order($order->get_id());

        // Verify codes exist
        $codesBefore = $this->main->getCore()->getCodesByOrderId($order->get_id());
        $this->assertNotEmpty($codesBefore);

        // Remove
        $this->main->getWC()->getOrderManager()->removeAllTicketsFromOrder([
            'order_id' => $order->get_id(),
        ]);

        // After removal, order item meta should be cleared
        $order = wc_get_order($order->get_id());
        $hasTickets = $this->main->getWC()->getOrderManager()->hasTicketsInOrderWithTicketnumber($order);
        $this->assertFalse($hasTickets);
    }
}
