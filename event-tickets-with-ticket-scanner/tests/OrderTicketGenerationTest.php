<?php
/**
 * Integration tests for order → ticket generation pipeline.
 *
 * These tests create real WooCommerce products/orders and verify
 * the ticket generation pipeline works end-to-end.
 */

class OrderTicketGenerationTest extends WP_UnitTestCase {

    private $main;

    public function set_up(): void {
        parent::set_up();
        $this->main = sasoEventtickets::Instance();

        // Ensure WooCommerce is loaded
        if (!class_exists('WC_Product_Simple')) {
            $this->markTestSkipped('WooCommerce not available');
        }
    }

    /**
     * Helper: create a simple ticket product with a list.
     */
    private function createTicketProduct(): array {
        // Create a ticket list
        $listId = $this->main->getDB()->insert('lists', [
            'name' => 'Order Test List ' . uniqid(),
            'aktiv' => 1,
            'meta' => '{}',
        ]);

        // Create WC product
        $product = new WC_Product_Simple();
        $product->set_name('Test Ticket Product');
        $product->set_regular_price('10.00');
        $product->set_status('publish');
        $product->save();

        $productId = $product->get_id();

        // Mark as ticket product
        update_post_meta($productId, 'saso_eventtickets_is_ticket', 'yes');
        update_post_meta($productId, 'saso_eventtickets_list', $listId);

        return ['product' => $product, 'product_id' => $productId, 'list_id' => $listId];
    }

    /**
     * Helper: create a non-ticket product.
     */
    private function createNonTicketProduct(): WC_Product_Simple {
        $product = new WC_Product_Simple();
        $product->set_name('Regular Product');
        $product->set_regular_price('5.00');
        $product->set_status('publish');
        $product->save();
        return $product;
    }

    /**
     * Helper: create an order with items.
     */
    private function createOrder(array $items, string $status = 'completed'): WC_Order {
        $order = wc_create_order();
        foreach ($items as $item) {
            $order->add_product($item['product'], $item['quantity'] ?? 1);
        }
        $order->calculate_totals();
        $order->set_status($status);
        $order->save();
        return $order;
    }

    // ── hasTicketsInOrder ──────────────────────────────────────

    public function test_hasTicketsInOrder_with_ticket_product(): void {
        $ticket = $this->createTicketProduct();
        $order = $this->createOrder([
            ['product' => $ticket['product']],
        ]);

        $wcOrder = $this->main->getWC()->getOrderManager();
        $this->assertTrue($wcOrder->hasTicketsInOrder($order));
    }

    public function test_hasTicketsInOrder_without_ticket_product(): void {
        $product = $this->createNonTicketProduct();
        $order = $this->createOrder([
            ['product' => $product],
        ]);

        $wcOrder = $this->main->getWC()->getOrderManager();
        $this->assertFalse($wcOrder->hasTicketsInOrder($order));
    }

    public function test_hasTicketsInOrder_mixed_products(): void {
        $ticket = $this->createTicketProduct();
        $regular = $this->createNonTicketProduct();
        $order = $this->createOrder([
            ['product' => $ticket['product']],
            ['product' => $regular],
        ]);

        $wcOrder = $this->main->getWC()->getOrderManager();
        $this->assertTrue($wcOrder->hasTicketsInOrder($order));
    }

    // ── Ticket Generation Pipeline ─────────────────────────────

    public function test_add_serialcode_creates_codes(): void {
        $ticket = $this->createTicketProduct();
        $order = $this->createOrder([
            ['product' => $ticket['product'], 'quantity' => 2],
        ]);

        $wcOrder = $this->main->getWC()->getOrderManager();
        $wcOrder->add_serialcode_to_order($order->get_id());

        // Check codes were created in DB for this order
        $codes = $this->main->getCore()->getCodesByOrderId($order->get_id());
        $this->assertGreaterThanOrEqual(2, count($codes), 'Should have created at least 2 codes for quantity 2');
    }

    public function test_add_serialcode_idempotent(): void {
        $ticket = $this->createTicketProduct();
        $order = $this->createOrder([
            ['product' => $ticket['product'], 'quantity' => 1],
        ]);

        $wcOrder = $this->main->getWC()->getOrderManager();

        // Run twice
        $wcOrder->add_serialcode_to_order($order->get_id());
        $countFirst = count($this->main->getCore()->getCodesByOrderId($order->get_id()));

        $wcOrder->add_serialcode_to_order($order->get_id());
        $countSecond = count($this->main->getCore()->getCodesByOrderId($order->get_id()));

        // Should not create duplicate codes
        $this->assertSame($countFirst, $countSecond, 'Running add_serialcode twice should not create duplicates');
    }

    // ── getTicketsFromOrder ────────────────────────────────────

    public function test_getTicketsFromOrder_returns_ticket_items(): void {
        $ticket = $this->createTicketProduct();
        $order = $this->createOrder([
            ['product' => $ticket['product'], 'quantity' => 3],
        ]);

        // Generate codes first
        $wcOrder = $this->main->getWC()->getOrderManager();
        $wcOrder->add_serialcode_to_order($order->get_id());

        // Refresh order
        $order = wc_get_order($order->get_id());
        $tickets = $wcOrder->getTicketsFromOrder($order);

        $this->assertNotEmpty($tickets);
        $first = reset($tickets);
        $this->assertEquals(3, $first['quantity']);
        $this->assertEquals($ticket['product_id'], $first['product_id']);
    }

    public function test_getTicketsFromOrder_excludes_non_ticket_items(): void {
        $ticket = $this->createTicketProduct();
        $regular = $this->createNonTicketProduct();
        $order = $this->createOrder([
            ['product' => $ticket['product']],
            ['product' => $regular],
        ]);

        $wcOrder = $this->main->getWC()->getOrderManager();
        $wcOrder->add_serialcode_to_order($order->get_id());

        $order = wc_get_order($order->get_id());
        $tickets = $wcOrder->getTicketsFromOrder($order);

        // Should only contain the ticket product, not the regular one
        foreach ($tickets as $t) {
            $this->assertEquals($ticket['product_id'], $t['product_id']);
        }
    }

    // ── Order Status Change Handling ───────────────────────────

    public function test_status_change_to_completed_generates_tickets(): void {
        $ticket = $this->createTicketProduct();

        // Create order in pending status
        $order = $this->createOrder([
            ['product' => $ticket['product'], 'quantity' => 1],
        ], 'pending');

        // No codes yet
        $codesBefore = $this->main->getCore()->getCodesByOrderId($order->get_id());
        $this->assertEmpty($codesBefore);

        // Actually set order to completed (so isOrderPaid returns true),
        // then call the handler as WooCommerce would.
        $order->set_status('completed');
        $order->save();

        $wcOrder = $this->main->getWC()->getOrderManager();
        $wcOrder->woocommerce_order_status_changed($order->get_id(), 'pending', 'completed');

        $codesAfter = $this->main->getCore()->getCodesByOrderId($order->get_id());
        $this->assertNotEmpty($codesAfter);
    }

    public function test_status_change_to_cancelled_does_not_generate_tickets(): void {
        $ticket = $this->createTicketProduct();
        $order = $this->createOrder([
            ['product' => $ticket['product']],
        ], 'pending');

        $wcOrder = $this->main->getWC()->getOrderManager();
        $wcOrder->woocommerce_order_status_changed($order->get_id(), 'pending', 'cancelled');

        $codes = $this->main->getCore()->getCodesByOrderId($order->get_id());
        $this->assertEmpty($codes);
    }

    // ── Code Metadata ──────────────────────────────────────────

    public function test_generated_code_has_correct_metadata(): void {
        $ticket = $this->createTicketProduct();
        $order = $this->createOrder([
            ['product' => $ticket['product'], 'quantity' => 1],
        ]);

        $wcOrder = $this->main->getWC()->getOrderManager();
        $wcOrder->add_serialcode_to_order($order->get_id());

        $codes = $this->main->getCore()->getCodesByOrderId($order->get_id());
        $this->assertNotEmpty($codes);

        $codeObj = $codes[0];
        $metaObj = $this->main->getCore()->encodeMetaValuesAndFillObject($codeObj['meta'], $codeObj);

        $this->assertEquals($order->get_id(), $metaObj['woocommerce']['order_id']);
        $this->assertEquals($ticket['product_id'], $metaObj['woocommerce']['product_id']);
        $this->assertEquals(1, $metaObj['wc_ticket']['is_ticket']);
    }
}
