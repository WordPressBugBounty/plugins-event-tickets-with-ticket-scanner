<?php
/**
 * Tests for AdminSettings getRedeemAmount method.
 */

class AdminRedeemAmountTest extends WP_UnitTestCase {

    private $main;

    public function set_up(): void {
        parent::set_up();
        $this->main = sasoEventtickets::Instance();

        if (!class_exists('WC_Product_Simple')) {
            $this->markTestSkipped('WooCommerce not available');
        }
    }

    private function createTicketCodeObj(): array {
        $listId = $this->main->getDB()->insert('lists', [
            'name' => 'RedeemAmt List ' . uniqid(),
            'aktiv' => 1,
            'meta' => '{}',
        ]);

        $product = new WC_Product_Simple();
        $product->set_name('RedeemAmt Product ' . uniqid());
        $product->set_regular_price('10.00');
        $product->set_status('publish');
        $product->save();

        update_post_meta($product->get_id(), 'saso_eventtickets_is_ticket', 'yes');
        update_post_meta($product->get_id(), 'saso_eventtickets_list', $listId);
        update_post_meta($product->get_id(), 'saso_eventtickets_ticket_max_redeem_amount', 3);

        $order = wc_create_order();
        $order->add_product($product, 1);
        $order->calculate_totals();
        $order->set_status('completed');
        $order->save();

        // Get the generated code
        $om = $this->main->getWC()->getOrderManager();
        $order = wc_get_order($order->get_id());
        $tickets = $om->getTicketsFromOrder($order);
        $ticketValues = array_values($tickets);
        $codes = explode(',', $ticketValues[0]['codes']);
        $code = trim($codes[0]);

        $codeObj = $this->main->getCore()->retrieveCodeByCode($code);

        return [
            'codeObj' => $codeObj,
            'product' => $product,
            'order' => $order,
        ];
    }

    // ── getRedeemAmount ────────────────────────────────────────────

    public function test_getRedeemAmount_returns_array(): void {
        $data = $this->createTicketCodeObj();
        $result = $this->main->getAdmin()->getRedeemAmount($data['codeObj']);
        $this->assertIsArray($result);
        $this->assertArrayHasKey('_redeemed_counter', $result);
        $this->assertArrayHasKey('_max_redeem_amount', $result);
    }

    public function test_getRedeemAmount_counter_starts_at_zero(): void {
        $data = $this->createTicketCodeObj();
        $result = $this->main->getAdmin()->getRedeemAmount($data['codeObj']);
        $this->assertEquals(0, $result['_redeemed_counter']);
    }

    public function test_getRedeemAmount_max_from_product_meta(): void {
        $data = $this->createTicketCodeObj();
        $result = $this->main->getAdmin()->getRedeemAmount($data['codeObj']);
        $this->assertEquals(3, $result['_max_redeem_amount']);
    }

    public function test_getRedeemAmount_returns_cache(): void {
        $data = $this->createTicketCodeObj();
        $result = $this->main->getAdmin()->getRedeemAmount($data['codeObj']);
        $this->assertArrayHasKey('cache', $result);
        $this->assertIsArray($result['cache']);
    }

    public function test_getRedeemAmount_throws_without_meta(): void {
        $this->expectException(Exception::class);
        $this->main->getAdmin()->getRedeemAmount(['id' => 1]);
    }
}
