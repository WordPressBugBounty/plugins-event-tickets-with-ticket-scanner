<?php
/**
 * Tests for the daychooser (day-per-ticket) feature.
 */

class DaychooserTest extends WP_UnitTestCase {

    private $main;

    public function set_up(): void {
        parent::set_up();
        $this->main = sasoEventtickets::Instance();

        if (!class_exists('WC_Product_Simple')) {
            $this->markTestSkipped('WooCommerce not available');
        }
    }

    /**
     * Helper: create a daychooser product.
     */
    private function createDaychooserProduct(array $extra = []): array {
        $listId = $this->main->getDB()->insert('lists', [
            'name' => 'DC List ' . uniqid(),
            'aktiv' => 1,
            'meta' => '{}',
        ]);

        $product = new WC_Product_Simple();
        $product->set_name('Daychooser Product');
        $product->set_regular_price('10.00');
        $product->set_status('publish');
        $product->save();
        $pid = $product->get_id();

        update_post_meta($pid, 'saso_eventtickets_is_ticket', 'yes');
        update_post_meta($pid, 'saso_eventtickets_list', $listId);
        update_post_meta($pid, 'saso_eventtickets_is_daychooser', 'yes');

        foreach ($extra as $key => $value) {
            update_post_meta($pid, $key, $value);
        }

        return ['product' => $product, 'product_id' => $pid, 'list_id' => $listId];
    }

    // ── calcDateStringAllowedRedeemFrom with daychooser ──────────

    public function test_daychooser_flag_detected(): void {
        $dc = $this->createDaychooserProduct();
        $ticket = $this->main->getTicketHandler();

        $result = $ticket->calcDateStringAllowedRedeemFrom($dc['product_id']);
        $this->assertTrue($result['is_daychooser']);
    }

    public function test_daychooser_offsets_persisted(): void {
        $dc = $this->createDaychooserProduct([
            'saso_eventtickets_daychooser_offset_start' => 3,
            'saso_eventtickets_daychooser_offset_end' => 60,
        ]);
        $ticket = $this->main->getTicketHandler();

        $result = $ticket->calcDateStringAllowedRedeemFrom($dc['product_id']);
        $this->assertEquals(3, $result['daychooser_offset_start']);
        $this->assertEquals(60, $result['daychooser_offset_end']);
    }

    public function test_daychooser_exclude_weekdays(): void {
        $dc = $this->createDaychooserProduct([
            'saso_eventtickets_daychooser_exclude_wdays' => ['0', '6'], // Sun, Sat
        ]);
        $ticket = $this->main->getTicketHandler();

        $result = $ticket->calcDateStringAllowedRedeemFrom($dc['product_id']);
        $this->assertIsArray($result['daychooser_exclude_wdays']);
        $this->assertContains('0', $result['daychooser_exclude_wdays']);
        $this->assertContains('6', $result['daychooser_exclude_wdays']);
    }

    public function test_daychooser_with_code_obj_uses_day_per_ticket(): void {
        $dc = $this->createDaychooserProduct([
            'saso_eventtickets_ticket_start_date' => '2026-01-01',
            'saso_eventtickets_ticket_end_date' => '2026-12-31',
        ]);

        // Create a code with day_per_ticket in meta
        $metaObj = $this->main->getCore()->getMetaObject();
        $metaObj['wc_ticket']['is_daychooser'] = 1;
        $metaObj['wc_ticket']['day_per_ticket'] = '2026-06-15';
        $metaJson = $this->main->getCore()->json_encode_with_error_handling($metaObj);

        $code = 'DC' . strtoupper(uniqid());
        $this->main->getDB()->insert('codes', [
            'code' => $code,
            'code_display' => $code,
            'cvv' => '',
            'meta' => $metaJson,
            'aktiv' => 1,
            'redeemed' => 0,
            'list_id' => $dc['list_id'],
            'order_id' => 0,
        ]);

        $codeObj = $this->main->getCore()->retrieveCodeByCode($code);
        $ticket = $this->main->getTicketHandler();
        $result = $ticket->calcDateStringAllowedRedeemFrom($dc['product_id'], $codeObj);

        $this->assertTrue($result['is_daychooser_value_set']);
        $this->assertEquals('2026-06-15', $result['ticket_start_date']);
        $this->assertEquals('2026-06-15', $result['ticket_end_date']);
    }

    // ── Daychooser code generation stores date ───────────────────

    public function test_daychooser_order_generates_codes(): void {
        $dc = $this->createDaychooserProduct([
            'saso_eventtickets_ticket_start_date' => '2026-01-01',
            'saso_eventtickets_ticket_end_date' => '2026-12-31',
        ]);

        $order = wc_create_order();
        $order->add_product($dc['product'], 1);
        $order->calculate_totals();
        $order->set_status('completed');
        $order->save();

        // Set daychooser value on the order item
        $items = $order->get_items();
        $itemId = array_key_first($items);
        wc_add_order_item_meta($itemId, '_saso_eventtickets_daychooser', '2026-06-15');

        // Generate codes
        $wcOrder = $this->main->getWC()->getOrderManager();
        $wcOrder->add_serialcode_to_order($order->get_id());

        $codes = $this->main->getCore()->getCodesByOrderId($order->get_id());
        $this->assertNotEmpty($codes, 'Daychooser product should generate codes');

        // Verify the code is linked to the correct order
        $codeObj = $codes[0];
        $metaObj = $this->main->getCore()->encodeMetaValuesAndFillObject($codeObj['meta'], $codeObj);
        $this->assertEquals($order->get_id(), $metaObj['woocommerce']['order_id']);
    }

    // ── Non-daychooser product ───────────────────────────────────

    public function test_non_daychooser_product_flag_false(): void {
        $product = new WC_Product_Simple();
        $product->set_name('Non DC Product');
        $product->set_regular_price('10.00');
        $product->set_status('publish');
        $product->save();

        update_post_meta($product->get_id(), 'saso_eventtickets_is_ticket', 'yes');

        $ticket = $this->main->getTicketHandler();
        $result = $ticket->calcDateStringAllowedRedeemFrom($product->get_id());
        $this->assertFalse($result['is_daychooser']);
    }
}
