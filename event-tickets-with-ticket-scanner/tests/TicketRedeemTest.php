<?php
/**
 * Tests for ticket redemption logic.
 */

class TicketRedeemTest extends WP_UnitTestCase {

    private sasoEventtickets_Ticket $ticket;

    public function set_up(): void {
        parent::set_up();
        $this->ticket = sasoEventtickets::Instance()->getTicketHandler();
    }

    // ── countRedeemsToday ──────────────────────────────────────
    // (extended from CountRedeemsTodayTest — more edge cases)

    public function test_countRedeemsToday_multiple_today(): void {
        $today = wp_date('Y-m-d');
        $entries = [];
        for ($i = 0; $i < 10; $i++) {
            $entries[] = ['redeemed_date' => $today . sprintf(' %02d:00:00', $i)];
        }
        $this->assertSame(10, $this->ticket->countRedeemsToday($entries));
    }

    public function test_countRedeemsToday_only_future_dates_not_counted(): void {
        $tomorrow = wp_date('Y-m-d', strtotime('+1 day'));
        $entries = [
            ['redeemed_date' => $tomorrow . ' 12:00:00'],
        ];
        $this->assertSame(0, $this->ticket->countRedeemsToday($entries));
    }

    public function test_countRedeemsToday_missing_key_skipped(): void {
        $entries = [
            ['other_key' => 'value'],
            [],
        ];
        $this->assertSame(0, $this->ticket->countRedeemsToday($entries));
    }

    // ── getTimes ───────────────────────────────────────────────

    public function test_getTimes_returns_expected_keys(): void {
        $times = $this->ticket->getTimes();
        $this->assertIsArray($times);
        $this->assertArrayHasKey('time', $times);
        $this->assertArrayHasKey('timestamp', $times);
        $this->assertArrayHasKey('UTC_time', $times);
        $this->assertArrayHasKey('timezone', $times);
    }

    public function test_getTimes_timestamp_is_current(): void {
        $times = $this->ticket->getTimes();
        $now = time();
        $this->assertLessThan(10, abs($now - $times['timestamp']), 'Timestamp should be near current time');
    }

    // ── get_is_paid_statuses ───────────────────────────────────

    public function test_get_is_paid_statuses_returns_array(): void {
        $statuses = $this->ticket->get_is_paid_statuses();
        $this->assertIsArray($statuses);
        $this->assertNotEmpty($statuses);
    }

    public function test_get_is_paid_statuses_includes_completed(): void {
        $statuses = $this->ticket->get_is_paid_statuses();
        // WooCommerce considers 'completed' as paid
        $this->assertContains('completed', $statuses);
    }

    public function test_get_is_paid_statuses_includes_processing(): void {
        $statuses = $this->ticket->get_is_paid_statuses();
        $this->assertContains('processing', $statuses);
    }

    // ── Redemption End-to-End (with DB) ────────────────────────

    public function test_redeem_cycle_with_real_code(): void {
        if (!class_exists('WC_Product_Simple')) {
            $this->markTestSkipped('WooCommerce not available');
        }

        $main = sasoEventtickets::Instance();

        // Create list
        $listId = $main->getDB()->insert('lists', [
            'name' => 'Redeem E2E ' . uniqid(),
            'aktiv' => 1,
            'meta' => '{}',
        ]);

        // Create product
        $product = new WC_Product_Simple();
        $product->set_name('Redeem Test Product');
        $product->set_regular_price('10.00');
        $product->set_status('publish');
        $product->save();
        $productId = $product->get_id();
        update_post_meta($productId, 'saso_eventtickets_is_ticket', 'yes');
        update_post_meta($productId, 'saso_eventtickets_list', $listId);

        // Create order
        $order = wc_create_order();
        $order->add_product($product, 1);
        $order->calculate_totals();
        $order->set_status('completed');
        $order->save();

        // Generate tickets
        $wcOrder = $main->getWC()->getOrderManager();
        $wcOrder->add_serialcode_to_order($order->get_id());

        // Get the generated code
        $codes = $main->getCore()->getCodesByOrderId($order->get_id());
        $this->assertNotEmpty($codes, 'Codes should have been generated');

        $codeObj = $codes[0];

        // Code should not be redeemed yet
        $this->assertEquals(0, $codeObj['redeemed']);

        // Mark as redeemed via DB
        $main->getDB()->update('codes', ['redeemed' => 1], ['id' => $codeObj['id']]);

        // Verify redeemed
        $updated = $main->getCore()->retrieveCodeById($codeObj['id']);
        $this->assertEquals(1, $updated['redeemed']);
    }

    // ── Meta round-trip ────────────────────────────────────────

    public function test_meta_stats_redeemed_round_trip(): void {
        $main = sasoEventtickets::Instance();

        $listId = $main->getDB()->insert('lists', [
            'name' => 'Meta RT ' . uniqid(),
            'aktiv' => 1,
            'meta' => '{}',
        ]);

        $metaObj = $main->getCore()->getMetaObject();
        $today = wp_date('Y-m-d H:i:s');
        $metaObj['wc_ticket']['stats_redeemed'] = [
            ['redeemed_date' => $today, 'ip' => '127.0.0.1'],
            ['redeemed_date' => $today, 'ip' => '127.0.0.2'],
        ];
        $metaJson = $main->getCore()->json_encode_with_error_handling($metaObj);

        $code = 'METART' . strtoupper(uniqid());
        $codeId = $main->getDB()->insert('codes', [
            'code' => $code,
            'code_display' => $code,
            'cvv' => '',
            'meta' => $metaJson,
            'aktiv' => 1,
            'redeemed' => 1,
            'list_id' => $listId,
            'order_id' => 0,
        ]);

        // Retrieve and decode
        $retrieved = $main->getCore()->retrieveCodeByCode($code);
        $decodedMeta = $main->getCore()->encodeMetaValuesAndFillObject($retrieved['meta'], $retrieved);

        $this->assertCount(2, $decodedMeta['wc_ticket']['stats_redeemed']);
        $this->assertSame('127.0.0.1', $decodedMeta['wc_ticket']['stats_redeemed'][0]['ip']);

        // countRedeemsToday should find them
        $todayCount = $this->ticket->countRedeemsToday($decodedMeta['wc_ticket']['stats_redeemed']);
        $this->assertSame(2, $todayCount);
    }
}
