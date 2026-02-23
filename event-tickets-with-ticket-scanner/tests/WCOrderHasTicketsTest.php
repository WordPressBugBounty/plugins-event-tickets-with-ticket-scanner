<?php
/**
 * Tests for WC Order hasTicketsInOrder method
 * and related Messenger instantiation.
 */

class WCOrderHasTicketsTest extends WP_UnitTestCase {

    private $main;

    public function set_up(): void {
        parent::set_up();
        $this->main = sasoEventtickets::Instance();

        if (!class_exists('WC_Product_Simple')) {
            $this->markTestSkipped('WooCommerce not available');
        }
    }

    // ── hasTicketsInOrder ──────────────────────────────────────────

    public function test_hasTicketsInOrder_false_for_non_ticket_order(): void {
        $product = new WC_Product_Simple();
        $product->set_name('NonTicket ' . uniqid());
        $product->set_regular_price('10.00');
        $product->set_status('publish');
        $product->save();

        $order = wc_create_order();
        $order->add_product($product, 1);
        $order->calculate_totals();
        $order->save();

        $om = $this->main->getWC()->getOrderManager();
        $this->assertFalse($om->hasTicketsInOrder($order));
    }

    public function test_hasTicketsInOrder_true_for_ticket_order(): void {
        $listId = $this->main->getDB()->insert('lists', [
            'name' => 'HasTickets List ' . uniqid(),
            'aktiv' => 1,
            'meta' => '{}',
        ]);

        $product = new WC_Product_Simple();
        $product->set_name('Ticket ' . uniqid());
        $product->set_regular_price('10.00');
        $product->set_status('publish');
        $product->save();

        update_post_meta($product->get_id(), 'saso_eventtickets_is_ticket', 'yes');
        update_post_meta($product->get_id(), 'saso_eventtickets_list', $listId);

        $order = wc_create_order();
        $order->add_product($product, 1);
        $order->calculate_totals();
        $order->save();

        $om = $this->main->getWC()->getOrderManager();
        $this->assertTrue($om->hasTicketsInOrder($order));
    }

    public function test_hasTicketsInOrder_true_for_mixed_order(): void {
        $listId = $this->main->getDB()->insert('lists', [
            'name' => 'MixedOrder List ' . uniqid(),
            'aktiv' => 1,
            'meta' => '{}',
        ]);

        $ticketProduct = new WC_Product_Simple();
        $ticketProduct->set_name('Ticket ' . uniqid());
        $ticketProduct->set_regular_price('10.00');
        $ticketProduct->set_status('publish');
        $ticketProduct->save();
        update_post_meta($ticketProduct->get_id(), 'saso_eventtickets_is_ticket', 'yes');
        update_post_meta($ticketProduct->get_id(), 'saso_eventtickets_list', $listId);

        $normalProduct = new WC_Product_Simple();
        $normalProduct->set_name('Normal ' . uniqid());
        $normalProduct->set_regular_price('5.00');
        $normalProduct->set_status('publish');
        $normalProduct->save();

        $order = wc_create_order();
        $order->add_product($ticketProduct, 1);
        $order->add_product($normalProduct, 1);
        $order->calculate_totals();
        $order->save();

        $om = $this->main->getWC()->getOrderManager();
        $this->assertTrue($om->hasTicketsInOrder($order));
    }

    public function test_hasTicketsInOrder_false_for_empty_order(): void {
        $order = wc_create_order();
        $order->save();

        $om = $this->main->getWC()->getOrderManager();
        $this->assertFalse($om->hasTicketsInOrder($order));
    }
}
