<?php
/**
 * Tests for WC Order ticket detection: hasTicketsInOrder,
 * hasTicketsInOrderWithTicketnumber, getTicketsFromOrder.
 */

class WCOrderTicketDetectionTest extends WP_UnitTestCase {

    private $main;

    public function set_up(): void {
        parent::set_up();
        $this->main = sasoEventtickets::Instance();

        if (!class_exists('WC_Product_Simple')) {
            $this->markTestSkipped('WooCommerce not available');
        }
    }

    private function createTicketProduct(int $listId): WC_Product_Simple {
        $product = new WC_Product_Simple();
        $product->set_name('Detection Test Product ' . uniqid());
        $product->set_regular_price('10.00');
        $product->set_status('publish');
        $product->save();

        update_post_meta($product->get_id(), 'saso_eventtickets_is_ticket', 'yes');
        update_post_meta($product->get_id(), 'saso_eventtickets_list', $listId);

        return $product;
    }

    private function createNonTicketProduct(): WC_Product_Simple {
        $product = new WC_Product_Simple();
        $product->set_name('Non-Ticket Product ' . uniqid());
        $product->set_regular_price('5.00');
        $product->set_status('publish');
        $product->save();

        return $product;
    }

    private function createList(): int {
        return $this->main->getDB()->insert('lists', [
            'name' => 'Detection Test ' . uniqid(),
            'aktiv' => 1,
            'meta' => '{}',
        ]);
    }

    // ── hasTicketsInOrder ──────────────────────────────────────────

    public function test_hasTicketsInOrder_true_with_ticket(): void {
        $listId = $this->createList();
        $product = $this->createTicketProduct($listId);

        $order = wc_create_order();
        $order->add_product($product, 1);
        $order->save();

        $result = $this->main->getWC()->getOrderManager()->hasTicketsInOrder($order);
        $this->assertTrue($result);
    }

    public function test_hasTicketsInOrder_false_without_ticket(): void {
        $product = $this->createNonTicketProduct();

        $order = wc_create_order();
        $order->add_product($product, 1);
        $order->save();

        $result = $this->main->getWC()->getOrderManager()->hasTicketsInOrder($order);
        $this->assertFalse($result);
    }

    public function test_hasTicketsInOrder_true_mixed_products(): void {
        $listId = $this->createList();
        $ticketProduct = $this->createTicketProduct($listId);
        $normalProduct = $this->createNonTicketProduct();

        $order = wc_create_order();
        $order->add_product($ticketProduct, 1);
        $order->add_product($normalProduct, 1);
        $order->save();

        $result = $this->main->getWC()->getOrderManager()->hasTicketsInOrder($order);
        $this->assertTrue($result);
    }

    public function test_hasTicketsInOrder_false_empty_order(): void {
        $order = wc_create_order();
        $order->save();

        $result = $this->main->getWC()->getOrderManager()->hasTicketsInOrder($order);
        $this->assertFalse($result);
    }

    // ── hasTicketsInOrderWithTicketnumber ──────────────────────────

    public function test_hasTicketsInOrderWithTicketnumber_false_before_generation(): void {
        $listId = $this->createList();
        $product = $this->createTicketProduct($listId);

        $order = wc_create_order();
        $order->add_product($product, 1);
        $order->save();

        // Before ticket generation, no codes assigned
        $result = $this->main->getWC()->getOrderManager()->hasTicketsInOrderWithTicketnumber($order);
        $this->assertFalse($result);
    }

    public function test_hasTicketsInOrderWithTicketnumber_true_after_generation(): void {
        $listId = $this->createList();
        $product = $this->createTicketProduct($listId);

        $order = wc_create_order();
        $order->add_product($product, 1);
        $order->calculate_totals();
        $order->set_status('completed');
        $order->save();

        $this->main->getWC()->getOrderManager()->add_serialcode_to_order($order->get_id());

        // Reload order to pick up meta
        $order = wc_get_order($order->get_id());
        $result = $this->main->getWC()->getOrderManager()->hasTicketsInOrderWithTicketnumber($order);
        $this->assertTrue($result);
    }

    // ── getTicketsFromOrder ───────────────────────────────────────

    public function test_getTicketsFromOrder_returns_array(): void {
        $listId = $this->createList();
        $product = $this->createTicketProduct($listId);

        $order = wc_create_order();
        $order->add_product($product, 2);
        $order->save();

        $result = $this->main->getWC()->getOrderManager()->getTicketsFromOrder($order);
        $this->assertIsArray($result);
    }

    public function test_getTicketsFromOrder_has_correct_quantity(): void {
        $listId = $this->createList();
        $product = $this->createTicketProduct($listId);

        $order = wc_create_order();
        $order->add_product($product, 3);
        $order->save();

        $tickets = $this->main->getWC()->getOrderManager()->getTicketsFromOrder($order);
        $this->assertCount(1, $tickets);
        $firstTicket = reset($tickets);
        $this->assertEquals(3, $firstTicket['quantity']);
        $this->assertEquals($product->get_id(), $firstTicket['product_id']);
    }

    public function test_getTicketsFromOrder_empty_for_non_ticket(): void {
        $product = $this->createNonTicketProduct();

        $order = wc_create_order();
        $order->add_product($product, 1);
        $order->save();

        $tickets = $this->main->getWC()->getOrderManager()->getTicketsFromOrder($order);
        $this->assertEmpty($tickets);
    }

    public function test_getTicketsFromOrder_multiple_ticket_products(): void {
        $listId = $this->createList();
        $product1 = $this->createTicketProduct($listId);
        $product2 = $this->createTicketProduct($listId);

        $order = wc_create_order();
        $order->add_product($product1, 1);
        $order->add_product($product2, 2);
        $order->save();

        $tickets = $this->main->getWC()->getOrderManager()->getTicketsFromOrder($order);
        $this->assertCount(2, $tickets);
    }

    public function test_getTicketsFromOrder_has_expected_keys(): void {
        $listId = $this->createList();
        $product = $this->createTicketProduct($listId);

        $order = wc_create_order();
        $order->add_product($product, 1);
        $order->calculate_totals();
        $order->set_status('completed');
        $order->save();

        $this->main->getWC()->getOrderManager()->add_serialcode_to_order($order->get_id());
        $order = wc_get_order($order->get_id());

        $tickets = $this->main->getWC()->getOrderManager()->getTicketsFromOrder($order);
        $firstTicket = reset($tickets);

        $this->assertArrayHasKey('quantity', $firstTicket);
        $this->assertArrayHasKey('codes', $firstTicket);
        $this->assertArrayHasKey('product_id', $firstTicket);
        $this->assertArrayHasKey('product_id_orig', $firstTicket);
        $this->assertArrayHasKey('order_item_id', $firstTicket);
    }
}
