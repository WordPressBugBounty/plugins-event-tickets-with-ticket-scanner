<?php
/**
 * Tests for WooCommerce variation-specific ticket handling.
 * Variations can be excluded from ticket generation, have their own dates, etc.
 */

class VariationTicketTest extends WP_UnitTestCase {

    private $main;

    public function set_up(): void {
        parent::set_up();
        $this->main = sasoEventtickets::Instance();

        if (!class_exists('WC_Product_Variable')) {
            $this->markTestSkipped('WooCommerce not available');
        }
    }

    /**
     * Helper: create a variable product with a variation.
     */
    private function createVariableProduct(bool $isTicket = true): array {
        $listId = $this->main->getDB()->insert('lists', [
            'name' => 'Var List ' . uniqid(),
            'aktiv' => 1,
            'meta' => '{}',
        ]);

        $product = new WC_Product_Variable();
        $product->set_name('Variable Ticket ' . uniqid());
        $product->set_status('publish');
        $product->save();

        if ($isTicket) {
            update_post_meta($product->get_id(), 'saso_eventtickets_is_ticket', 'yes');
            update_post_meta($product->get_id(), 'saso_eventtickets_list', $listId);
        }

        $attribute = new WC_Product_Attribute();
        $attribute->set_name('Type');
        $attribute->set_options(['VIP', 'Standard']);
        $attribute->set_visible(true);
        $attribute->set_variation(true);
        $product->set_attributes([$attribute]);
        $product->save();

        $var1 = new WC_Product_Variation();
        $var1->set_parent_id($product->get_id());
        $var1->set_attributes(['type' => 'VIP']);
        $var1->set_regular_price('50.00');
        $var1->save();

        $var2 = new WC_Product_Variation();
        $var2->set_parent_id($product->get_id());
        $var2->set_attributes(['type' => 'Standard']);
        $var2->set_regular_price('25.00');
        $var2->save();

        return [
            'product' => $product,
            'product_id' => $product->get_id(),
            'list_id' => $listId,
            'var_vip' => $var1,
            'var_standard' => $var2,
        ];
    }

    // ── Variation not-ticket exclusion ────────────────────────────

    public function test_variation_not_ticket_meta_persists(): void {
        $data = $this->createVariableProduct();

        update_post_meta($data['var_standard']->get_id(), '_saso_eventtickets_is_not_ticket', 'yes');

        $notTicket = get_post_meta($data['var_standard']->get_id(), '_saso_eventtickets_is_not_ticket', true);
        $this->assertEquals('yes', $notTicket);

        // VIP should not have the exclusion
        $vipNotTicket = get_post_meta($data['var_vip']->get_id(), '_saso_eventtickets_is_not_ticket', true);
        $this->assertEmpty($vipNotTicket);
    }

    public function test_excluded_variation_generates_no_codes(): void {
        $data = $this->createVariableProduct();

        // Mark Standard as NOT a ticket
        update_post_meta($data['var_standard']->get_id(), '_saso_eventtickets_is_not_ticket', 'yes');

        // Order the excluded variation
        $order = wc_create_order();
        $order->add_product($data['var_standard'], 2);
        $order->calculate_totals();
        $order->set_status('completed');
        $order->save();

        $this->main->getWC()->getOrderManager()->add_serialcode_to_order($order->get_id());

        $codes = $this->main->getCore()->getCodesByOrderId($order->get_id());
        $this->assertEmpty($codes, 'Excluded variation should not generate codes');
    }

    public function test_included_variation_generates_codes(): void {
        $data = $this->createVariableProduct();

        // Order VIP (not excluded)
        $order = wc_create_order();
        $order->add_product($data['var_vip'], 1);
        $order->calculate_totals();
        $order->set_status('completed');
        $order->save();

        $this->main->getWC()->getOrderManager()->add_serialcode_to_order($order->get_id());

        $codes = $this->main->getCore()->getCodesByOrderId($order->get_id());
        $this->assertCount(1, $codes, 'VIP variation should generate 1 code');
    }

    // ── Variation-specific dates ──────────────────────────────────

    public function test_variation_specific_dates_override_parent(): void {
        $data = $this->createVariableProduct();
        $varId = $data['var_vip']->get_id();

        // Set parent dates
        update_post_meta($data['product_id'], 'saso_eventtickets_ticket_start_date', '2026-01-01');
        update_post_meta($data['product_id'], 'saso_eventtickets_ticket_end_date', '2026-12-31');

        // Override on variation
        update_post_meta($varId, 'saso_eventtickets_ticket_start_date', '2026-06-01');
        update_post_meta($varId, 'saso_eventtickets_ticket_end_date', '2026-06-30');

        $ticket = $this->main->getTicketHandler();
        $result = $ticket->calcDateStringAllowedRedeemFrom($varId);

        $this->assertEquals('2026-06-01', $result['ticket_start_date']);
        $this->assertEquals('2026-06-30', $result['ticket_end_date']);
    }

    // ── Variation-specific ticket amount per item ─────────────────

    public function test_variation_amount_per_item(): void {
        $data = $this->createVariableProduct();

        // Set variation-specific amount per item
        update_post_meta($data['var_vip']->get_id(), 'saso_eventtickets_ticket_amount_per_item', 3);

        $order = wc_create_order();
        $order->add_product($data['var_vip'], 2);
        $order->calculate_totals();
        $order->set_status('completed');
        $order->save();

        $this->main->getWC()->getOrderManager()->add_serialcode_to_order($order->get_id());

        // 2 items × 3 tickets/item = 6 codes
        $codes = $this->main->getCore()->getCodesByOrderId($order->get_id());
        $this->assertCount(6, $codes, '2 VIP items × 3 tickets/item = 6 codes');
    }

    // ── Mixed variation order ─────────────────────────────────────

    public function test_mixed_variation_order_excluded_and_included(): void {
        $data = $this->createVariableProduct();

        // Exclude Standard
        update_post_meta($data['var_standard']->get_id(), '_saso_eventtickets_is_not_ticket', 'yes');

        $order = wc_create_order();
        $order->add_product($data['var_vip'], 1);
        $order->add_product($data['var_standard'], 2);
        $order->calculate_totals();
        $order->set_status('completed');
        $order->save();

        $this->main->getWC()->getOrderManager()->add_serialcode_to_order($order->get_id());

        $codes = $this->main->getCore()->getCodesByOrderId($order->get_id());
        // Only VIP generates codes (1), Standard excluded (0)
        $this->assertCount(1, $codes, 'Only VIP variation should generate codes');
    }
}
