<?php
/**
 * End-to-end tests for the complete ticket lifecycle:
 * Product → Order → Code generation → Query → Redeem → Verify
 */

class FullTicketFlowTest extends WP_UnitTestCase {

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
    private function createTicketProduct(array $extraMeta = []): array {
        $listId = $this->main->getDB()->insert('lists', [
            'name' => 'Flow List ' . uniqid(),
            'aktiv' => 1,
            'meta' => '{}',
        ]);

        $product = new WC_Product_Simple();
        $product->set_name('Flow Ticket ' . uniqid());
        $product->set_regular_price('25.00');
        $product->set_status('publish');
        $product->save();
        $pid = $product->get_id();

        update_post_meta($pid, 'saso_eventtickets_is_ticket', 'yes');
        update_post_meta($pid, 'saso_eventtickets_list', $listId);

        foreach ($extraMeta as $key => $value) {
            update_post_meta($pid, $key, $value);
        }

        return ['product' => $product, 'product_id' => $pid, 'list_id' => $listId];
    }

    /**
     * Helper: create an order and generate codes.
     */
    private function createOrderWithCodes(WC_Product $product, int $quantity = 1): WC_Order {
        $order = wc_create_order();
        $order->add_product($product, $quantity);
        $order->set_billing_first_name('Test');
        $order->set_billing_last_name('Customer');
        $order->set_billing_email('test@example.com');
        $order->calculate_totals();
        $order->set_status('completed');
        $order->save();

        $this->main->getWC()->getOrderManager()->add_serialcode_to_order($order->get_id());

        return wc_get_order($order->get_id());
    }

    // ── Full lifecycle: create → generate → query → validate ─────

    public function test_full_lifecycle_single_ticket(): void {
        $tp = $this->createTicketProduct([
            'saso_eventtickets_ticket_start_date' => '2026-07-01',
            'saso_eventtickets_ticket_end_date' => '2026-12-31',
        ]);
        $order = $this->createOrderWithCodes($tp['product']);

        // 1. Verify codes were generated
        $codes = $this->main->getCore()->getCodesByOrderId($order->get_id());
        $this->assertCount(1, $codes);

        // 2. Verify code is valid via frontend checkCode
        $code = $codes[0]['code'];
        $result = $this->main->getFrontend()->checkCode(['code' => $code]);
        $this->assertEquals(1, $result['valid']);

        // 3. Verify code metadata has WooCommerce info
        $codeObj = $this->main->getCore()->retrieveCodeByCode($code);
        $metaObj = $this->main->getCore()->encodeMetaValuesAndFillObject($codeObj['meta'], $codeObj);
        $this->assertEquals($order->get_id(), intval($metaObj['woocommerce']['order_id']));
        $this->assertEquals($tp['product_id'], intval($metaObj['woocommerce']['product_id']));
        $this->assertEquals(1, intval($metaObj['wc_ticket']['is_ticket']));

        // 4. Verify order has tickets
        $this->assertTrue($this->main->getWC()->getOrderManager()->hasTicketsInOrder($order));
        $this->assertTrue($this->main->getWC()->getOrderManager()->hasTicketsInOrderWithTicketnumber($order));

        // 5. Verify getTicketsFromOrder returns correct data
        $tickets = $this->main->getWC()->getOrderManager()->getTicketsFromOrder($order);
        $this->assertCount(1, $tickets);
        $ticket = reset($tickets);
        $this->assertEquals(1, $ticket['quantity']);
        $this->assertNotEmpty($ticket['codes']);

        // 6. Verify getCodesByProductId
        $productCodes = $this->main->getAdmin()->getCodesByProductId($tp['product_id']);
        $this->assertCount(1, $productCodes);

        // 7. Code is not used yet
        $this->assertFalse($this->main->getFrontend()->isUsed($codeObj));

        // 8. Verify QR code content
        $qr = $this->main->getCore()->getQRCodeContent($codeObj);
        $this->assertNotEmpty($qr);
    }

    public function test_full_lifecycle_multiple_tickets(): void {
        $tp = $this->createTicketProduct();
        $order = $this->createOrderWithCodes($tp['product'], 3);

        // 3 codes should be generated
        $codes = $this->main->getCore()->getCodesByOrderId($order->get_id());
        $this->assertCount(3, $codes);

        // All codes should be unique
        $codeStrings = array_column($codes, 'code');
        $this->assertCount(3, array_unique($codeStrings));

        // All codes should be valid
        foreach ($codeStrings as $codeStr) {
            $result = $this->main->getFrontend()->checkCode(['code' => $codeStr]);
            $this->assertEquals(1, $result['valid'], "Code $codeStr should be valid");
        }
    }

    public function test_full_lifecycle_with_amount_per_item(): void {
        $tp = $this->createTicketProduct([
            'saso_eventtickets_ticket_amount_per_item' => 3,
        ]);
        $order = $this->createOrderWithCodes($tp['product'], 2);

        // 2 items × 3 tickets/item = 6 codes
        $codes = $this->main->getCore()->getCodesByOrderId($order->get_id());
        $this->assertCount(6, $codes);
    }

    // ── Order with mixed products ────────────────────────────────

    public function test_order_with_mixed_ticket_and_non_ticket(): void {
        $tp = $this->createTicketProduct();

        $nonTicket = new WC_Product_Simple();
        $nonTicket->set_name('Regular Product');
        $nonTicket->set_regular_price('5.00');
        $nonTicket->set_status('publish');
        $nonTicket->save();

        $order = wc_create_order();
        $order->add_product($nonTicket, 1);
        $order->add_product($tp['product'], 2);
        $order->calculate_totals();
        $order->set_status('completed');
        $order->save();

        $this->main->getWC()->getOrderManager()->add_serialcode_to_order($order->get_id());

        $order = wc_get_order($order->get_id());
        $codes = $this->main->getCore()->getCodesByOrderId($order->get_id());
        $this->assertCount(2, $codes, 'Only ticket products should generate codes');

        // getTicketsFromOrder should only include the ticket product
        $tickets = $this->main->getWC()->getOrderManager()->getTicketsFromOrder($order);
        $this->assertCount(1, $tickets);
    }

    // ── addCodeFromListForOrder ───────────────────────────────────

    public function test_addCodeFromListForOrder_generates_unique_code(): void {
        $listId = $this->main->getDB()->insert('lists', [
            'name' => 'AutoGen ' . uniqid(),
            'aktiv' => 1,
            'meta' => '{}',
        ]);

        $order = wc_create_order();
        $order->save();

        $code1 = $this->main->getAdmin()->addCodeFromListForOrder($listId, $order->get_id());
        $code2 = $this->main->getAdmin()->addCodeFromListForOrder($listId, $order->get_id());

        $this->assertNotEmpty($code1);
        $this->assertNotEmpty($code2);
        $this->assertNotEquals($code1, $code2);
    }

    public function test_addCodeFromListForOrder_invalid_list_throws(): void {
        $this->expectException(Exception::class);
        $this->main->getAdmin()->addCodeFromListForOrder(0, 1);
    }

    // ── Customer name in order context ────────────────────────────

    public function test_customer_name_available_after_code_generation(): void {
        $tp = $this->createTicketProduct();
        $order = $this->createOrderWithCodes($tp['product']);

        $customerName = $this->main->getAdmin()->getCustomerName($order->get_id());
        $this->assertStringContainsString('Test', $customerName);
        $this->assertStringContainsString('Customer', $customerName);
    }

    // ── Frontend getOptions ───────────────────────────────────────

    public function test_frontend_getOptions_returns_array(): void {
        $options = $this->main->getFrontend()->getOptions();
        $this->assertIsArray($options);
    }

    // ── Ticket date display in full flow ──────────────────────────

    public function test_ticket_date_displayed_correctly_for_generated_code(): void {
        $tp = $this->createTicketProduct([
            'saso_eventtickets_ticket_start_date' => '2026-08-15',
            'saso_eventtickets_ticket_start_time' => '19:00:00',
            'saso_eventtickets_ticket_end_date' => '2026-08-15',
            'saso_eventtickets_ticket_end_time' => '23:00:00',
        ]);
        $order = $this->createOrderWithCodes($tp['product']);

        $dateStr = $this->main->getTicketHandler()->displayTicketDateAsString($tp['product_id']);
        $this->assertStringContainsString('2026', $dateStr);
        $this->assertStringContainsString('19:00', $dateStr);
    }

    // ── ICS generation for product with order ─────────────────────

    public function test_ics_generation_for_ticket_product(): void {
        $tp = $this->createTicketProduct([
            'saso_eventtickets_ticket_start_date' => '2026-09-01',
            'saso_eventtickets_ticket_start_time' => '10:00:00',
            'saso_eventtickets_ticket_end_date' => '2026-09-01',
            'saso_eventtickets_ticket_end_time' => '18:00:00',
        ]);

        $ics = $this->main->getTicketHandler()->generateICSFile($tp['product']);
        $this->assertStringContainsString('BEGIN:VCALENDAR', $ics);
        $this->assertStringContainsString('DTSTART:', $ics);
        $this->assertStringContainsString('SUMMARY:', $ics);
    }

    // ── Remove all tickets from order ─────────────────────────────

    public function test_remove_all_tickets_clears_order_ticket_data(): void {
        $tp = $this->createTicketProduct();
        $order = $this->createOrderWithCodes($tp['product'], 2);

        // Verify tickets exist
        $this->assertTrue($this->main->getWC()->getOrderManager()->hasTicketsInOrderWithTicketnumber($order));

        // Remove all tickets
        $this->main->getWC()->getOrderManager()->removeAllTicketsFromOrder([
            'order_id' => $order->get_id(),
        ]);

        // Verify ticket numbers are cleared
        $order = wc_get_order($order->get_id());
        $this->assertFalse($this->main->getWC()->getOrderManager()->hasTicketsInOrderWithTicketnumber($order));
    }
}
