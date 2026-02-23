<?php
/**
 * Tests for Frontend::countConfirmedStatus method.
 */

class FrontendCountConfirmedTest extends WP_UnitTestCase {

    private $main;

    public function set_up(): void {
        parent::set_up();
        $this->main = sasoEventtickets::Instance();

        if (!class_exists('WC_Product_Simple')) {
            $this->markTestSkipped('WooCommerce not available');
        }
    }

    private function createTicketCode(): array {
        $listId = $this->main->getDB()->insert('lists', [
            'name' => 'Confirmed List ' . uniqid(),
            'aktiv' => 1,
            'meta' => '{}',
        ]);

        $product = new WC_Product_Simple();
        $product->set_name('Confirmed Product ' . uniqid());
        $product->set_regular_price('10.00');
        $product->set_status('publish');
        $product->save();

        update_post_meta($product->get_id(), 'saso_eventtickets_is_ticket', 'yes');
        update_post_meta($product->get_id(), 'saso_eventtickets_list', $listId);

        $order = wc_create_order();
        $order->add_product($product, 1);
        $order->calculate_totals();
        $order->set_status('completed');
        $order->save();

        $om = $this->main->getWC()->getOrderManager();
        $order = wc_get_order($order->get_id());
        $tickets = $om->getTicketsFromOrder($order);
        $ticketValues = array_values($tickets);
        $codes = explode(',', $ticketValues[0]['codes']);
        $code = trim($codes[0]);

        $codeObj = $this->main->getCore()->retrieveCodeByCode($code);

        return [
            'codeObj' => $codeObj,
            'code' => $code,
        ];
    }

    // ── countConfirmedStatus ───────────────────────────────────────

    public function test_countConfirmedStatus_does_not_count_inactive(): void {
        $data = $this->createTicketCode();
        $codeObj = $data['codeObj'];
        $codeObj['aktiv'] = 0; // inactive
        $this->main->getFrontend()->countConfirmedStatus($codeObj);

        // Re-fetch: should not have incremented
        $freshObj = $this->main->getCore()->retrieveCodeByCode($data['code']);
        $meta = json_decode($freshObj['meta'], true);
        $confirmed = isset($meta['confirmedCount']) ? $meta['confirmedCount'] : 0;
        $this->assertEquals(0, $confirmed);
    }

    public function test_countConfirmedStatus_increments_with_force(): void {
        $data = $this->createTicketCode();
        $codeObj = $data['codeObj'];
        $this->main->getFrontend()->countConfirmedStatus($codeObj, true);

        $freshObj = $this->main->getCore()->retrieveCodeByCode($data['code']);
        $meta = json_decode($freshObj['meta'], true);
        $this->assertEquals(1, $meta['confirmedCount']);
    }

    public function test_countConfirmedStatus_sets_first_success_on_first_call(): void {
        $data = $this->createTicketCode();
        $codeObj = $data['codeObj'];
        $this->main->getFrontend()->countConfirmedStatus($codeObj, true);

        $freshObj = $this->main->getCore()->retrieveCodeByCode($data['code']);
        $meta = json_decode($freshObj['meta'], true);
        $this->assertNotEmpty($meta['validation']['first_success']);
    }

    public function test_countConfirmedStatus_increments_on_second_call(): void {
        $data = $this->createTicketCode();
        $codeObj = $data['codeObj'];

        // First call
        $this->main->getFrontend()->countConfirmedStatus($codeObj, true);
        // Re-fetch for second call
        $codeObj = $this->main->getCore()->retrieveCodeByCode($data['code']);
        $this->main->getFrontend()->countConfirmedStatus($codeObj, true);

        $freshObj = $this->main->getCore()->retrieveCodeByCode($data['code']);
        $meta = json_decode($freshObj['meta'], true);
        $this->assertEquals(2, $meta['confirmedCount']);
    }
}
