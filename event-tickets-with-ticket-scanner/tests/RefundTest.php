<?php
/**
 * Tests for WooCommerce refund handling and ticket removal.
 */

class RefundTest extends WP_UnitTestCase {

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
            'name' => 'Refund Test List ' . uniqid(),
            'aktiv' => 1,
            'meta' => '{}',
        ]);

        $product = new WC_Product_Simple();
        $product->set_name('Refund Test Product');
        $product->set_regular_price('10.00');
        $product->set_status('publish');
        $product->save();
        $productId = $product->get_id();

        update_post_meta($productId, 'saso_eventtickets_is_ticket', 'yes');
        update_post_meta($productId, 'saso_eventtickets_list', $listId);

        return ['product' => $product, 'product_id' => $productId, 'list_id' => $listId];
    }

    /**
     * Helper: create an order with ticket product and generate codes.
     */
    private function createOrderWithTickets(int $quantity = 2): array {
        $ticket = $this->createTicketProduct();

        $order = wc_create_order();
        $order->add_product($ticket['product'], $quantity);
        $order->calculate_totals();
        $order->set_status('completed');
        $order->save();

        // Generate tickets
        $wcOrder = $this->main->getWC()->getOrderManager();
        $wcOrder->add_serialcode_to_order($order->get_id());

        // Refresh order
        $order = wc_get_order($order->get_id());

        return [
            'order' => $order,
            'ticket' => $ticket,
        ];
    }

    // ── Ticket generation baseline ───────────────────────────────

    public function test_order_generates_correct_number_of_codes(): void {
        $data = $this->createOrderWithTickets(3);
        $codes = $this->main->getCore()->getCodesByOrderId($data['order']->get_id());
        $this->assertCount(3, $codes);
    }

    // ── Partial refund ───────────────────────────────────────────

    public function test_partial_refund_removes_codes(): void {
        // Enable refund option
        $this->main->getOptions()->changeOption(['key' => 'wcassignmentOrderItemRefund', 'value' => 1]);

        $data = $this->createOrderWithTickets(3);
        $order = $data['order'];

        // Get codes before refund
        $codesBefore = $this->main->getCore()->getCodesByOrderId($order->get_id());
        $this->assertCount(3, $codesBefore);

        // Create a partial refund for 1 item
        $items = $order->get_items();
        $itemId = array_key_first($items);

        $refund = wc_create_refund([
            'order_id' => $order->get_id(),
            'amount' => '10.00',
            'line_items' => [
                $itemId => [
                    'qty' => 1,
                    'refund_total' => 10.00,
                ],
            ],
        ]);

        $this->assertNotWPError($refund);

        // Trigger the refund handler
        $wcOrder = $this->main->getWC()->getOrderManager();
        $wcOrder->woocommerce_order_partially_refunded($order->get_id(), $refund->get_id());

        // Get order item meta — should have fewer codes
        $order = wc_get_order($order->get_id());
        $items = $order->get_items();
        $item = $items[$itemId];
        $codesStr = wc_get_order_item_meta($itemId, '_saso_eventtickets_product_code', true);

        if (!empty($codesStr)) {
            $remainingCodes = explode(',', $codesStr);
            $this->assertCount(2, $remainingCodes, 'Should have 2 codes after refunding 1 of 3');
        }
    }

    public function test_partial_refund_disabled_option_does_nothing(): void {
        // Disable refund option
        $this->main->getOptions()->changeOption(['key' => 'wcassignmentOrderItemRefund', 'value' => 0]);

        $data = $this->createOrderWithTickets(2);
        $order = $data['order'];

        $items = $order->get_items();
        $itemId = array_key_first($items);

        $codesStrBefore = wc_get_order_item_meta($itemId, '_saso_eventtickets_product_code', true);

        // Manually call the handler (don't use wc_create_refund which triggers hooks)
        $wcOrder = $this->main->getWC()->getOrderManager();
        $wcOrder->woocommerce_order_partially_refunded($order->get_id(), 0);

        // Codes should be unchanged when option is disabled
        $codesStrAfter = wc_get_order_item_meta($itemId, '_saso_eventtickets_product_code', true);
        $this->assertEquals($codesStrBefore, $codesStrAfter);
    }

    // ── Full refund (all items) ──────────────────────────────────

    public function test_full_refund_removes_all_codes(): void {
        $this->main->getOptions()->changeOption(['key' => 'wcassignmentOrderItemRefund', 'value' => 1]);

        $data = $this->createOrderWithTickets(2);
        $order = $data['order'];

        $items = $order->get_items();
        $itemId = array_key_first($items);

        // Refund all items
        $refund = wc_create_refund([
            'order_id' => $order->get_id(),
            'amount' => '20.00',
            'line_items' => [
                $itemId => [
                    'qty' => 2,
                    'refund_total' => 20.00,
                ],
            ],
        ]);

        $wcOrder = $this->main->getWC()->getOrderManager();
        $wcOrder->woocommerce_order_partially_refunded($order->get_id(), $refund->get_id());

        // Should have 0 codes remaining
        $codesStr = wc_get_order_item_meta($itemId, '_saso_eventtickets_product_code', true);
        if (!empty($codesStr)) {
            $remainingCodes = array_filter(explode(',', $codesStr));
            $this->assertCount(0, $remainingCodes, 'Should have 0 codes after full refund');
        } else {
            $this->assertEmpty($codesStr);
        }
    }

    // ── Order notes on refund ────────────────────────────────────

    public function test_refund_adds_order_note(): void {
        $this->main->getOptions()->changeOption(['key' => 'wcassignmentOrderItemRefund', 'value' => 1]);

        $data = $this->createOrderWithTickets(2);
        $order = $data['order'];

        $items = $order->get_items();
        $itemId = array_key_first($items);

        $refund = wc_create_refund([
            'order_id' => $order->get_id(),
            'amount' => '10.00',
            'line_items' => [
                $itemId => [
                    'qty' => 1,
                    'refund_total' => 10.00,
                ],
            ],
        ]);

        $wcOrder = $this->main->getWC()->getOrderManager();
        $wcOrder->woocommerce_order_partially_refunded($order->get_id(), $refund->get_id());

        // Check that order notes mention refunded ticket
        $notes = wc_get_order_notes(['order_id' => $order->get_id()]);
        $noteTexts = array_map(function ($n) { return $n->content; }, $notes);
        $combined = implode(' ', $noteTexts);

        $this->assertStringContainsString('Refunded ticket', $combined);
    }

    // ── releaseSeatsByOrderId ────────────────────────────────────

    public function test_releaseSeatsByOrderId_clears_blocks(): void {
        $planManager = $this->main->getSeating()->getPlanManager();
        $seatManager = $this->main->getSeating()->getSeatManager();
        $blockManager = $this->main->getSeating()->getBlockManager();

        // Clean seating tables
        global $wpdb;
        $wpdb->query("DELETE FROM {$wpdb->prefix}saso_eventtickets_seat_blocks");
        $wpdb->query("DELETE FROM {$wpdb->prefix}saso_eventtickets_seats");
        $wpdb->query("DELETE FROM {$wpdb->prefix}saso_eventtickets_seatingplans");

        $planId = $planManager->create(['name' => 'Refund Seat ' . uniqid()]);
        $seatId = $seatManager->create($planId, ['seat_identifier' => 'R1']);

        $product = new WC_Product_Simple();
        $product->set_name('Seat Refund Product');
        $product->set_regular_price('25.00');
        $product->set_status('publish');
        $product->save();
        $productId = $product->get_id();

        $sessionId = 'refund_seat_' . uniqid();
        $result = $blockManager->blockSeat($seatId, $productId, $sessionId);
        $this->assertTrue($result['success'], 'blockSeat should succeed');

        $blockManager->confirmBlock($result['block_id'], 1, 1, 1);

        // Release by order ID
        $blockManager->releaseSeatsByOrderId(1);

        // Seat should be available
        $available = $blockManager->isSeatAvailable($seatId, $productId);
        $this->assertTrue($available);
    }
}
