<?php
/**
 * Tests for event time windows and ticket URL components.
 */

class EventTimeWindowTest extends WP_UnitTestCase {

    private $main;

    public function set_up(): void {
        parent::set_up();
        $this->main = sasoEventtickets::Instance();

        if (!class_exists('WC_Product_Simple')) {
            $this->markTestSkipped('WooCommerce not available');
        }
    }

    /**
     * Helper: create a simple ticket product with dates.
     */
    private function createProductWithDates(
        ?string $startDate = null,
        ?string $startTime = null,
        ?string $endDate = null,
        ?string $endTime = null
    ): int {
        $product = new WC_Product_Simple();
        $product->set_name('Date Test Product');
        $product->set_regular_price('10.00');
        $product->set_status('publish');
        $product->save();
        $productId = $product->get_id();

        // Create a list
        $listId = $this->main->getDB()->insert('lists', [
            'name' => 'Date Test List ' . uniqid(),
            'aktiv' => 1,
            'meta' => '{}',
        ]);

        update_post_meta($productId, 'saso_eventtickets_is_ticket', 'yes');
        update_post_meta($productId, 'saso_eventtickets_list', $listId);

        if ($startDate !== null) {
            update_post_meta($productId, 'saso_eventtickets_ticket_start_date', $startDate);
        }
        if ($startTime !== null) {
            update_post_meta($productId, 'saso_eventtickets_ticket_start_time', $startTime);
        }
        if ($endDate !== null) {
            update_post_meta($productId, 'saso_eventtickets_ticket_end_date', $endDate);
        }
        if ($endTime !== null) {
            update_post_meta($productId, 'saso_eventtickets_ticket_end_time', $endTime);
        }

        return $productId;
    }

    // ── calcDateStringAllowedRedeemFrom ──────────────────────────

    public function test_calcDateStringAllowedRedeemFrom_returns_array(): void {
        $productId = $this->createProductWithDates('2026-06-15', '10:00:00', '2026-06-15', '22:00:00');
        $ticket = $this->main->getTicketHandler();

        $result = $ticket->calcDateStringAllowedRedeemFrom($productId);
        $this->assertIsArray($result);
    }

    public function test_calcDateStringAllowedRedeemFrom_has_required_keys(): void {
        $productId = $this->createProductWithDates('2026-06-15', '10:00:00', '2026-06-15', '22:00:00');
        $ticket = $this->main->getTicketHandler();

        $result = $ticket->calcDateStringAllowedRedeemFrom($productId);
        $this->assertArrayHasKey('ticket_start_date', $result);
        $this->assertArrayHasKey('ticket_end_date', $result);
        $this->assertArrayHasKey('ticket_start_time', $result);
        $this->assertArrayHasKey('ticket_end_time', $result);
        $this->assertArrayHasKey('is_date_set', $result);
        $this->assertArrayHasKey('is_end_date_set', $result);
        $this->assertArrayHasKey('redeem_allowed_from', $result);
        $this->assertArrayHasKey('redeem_allowed_until', $result);
        $this->assertArrayHasKey('server_time', $result);
        $this->assertArrayHasKey('redeem_allowed_from_timestamp', $result);
        $this->assertArrayHasKey('redeem_allowed_until_timestamp', $result);
        $this->assertArrayHasKey('server_time_timestamp', $result);
    }

    public function test_calcDateStringAllowedRedeemFrom_with_dates(): void {
        $productId = $this->createProductWithDates('2026-06-15', '10:00:00', '2026-06-15', '22:00:00');
        $ticket = $this->main->getTicketHandler();

        $result = $ticket->calcDateStringAllowedRedeemFrom($productId);
        $this->assertEquals('2026-06-15', $result['ticket_start_date']);
        $this->assertEquals('10:00:00', $result['ticket_start_time']);
        $this->assertEquals('2026-06-15', $result['ticket_end_date']);
        $this->assertEquals('22:00:00', $result['ticket_end_time']);
        $this->assertTrue($result['is_date_set']);
        $this->assertTrue($result['is_end_date_set']);
        $this->assertTrue($result['is_start_time_set']);
        $this->assertTrue($result['is_end_time_set']);
    }

    public function test_calcDateStringAllowedRedeemFrom_no_dates(): void {
        $productId = $this->createProductWithDates();
        $ticket = $this->main->getTicketHandler();

        $result = $ticket->calcDateStringAllowedRedeemFrom($productId);
        $this->assertFalse($result['is_date_set']);
        // When no start date, defaults to today
        $this->assertEquals(wp_date('Y-m-d'), $result['ticket_start_date']);
    }

    public function test_calcDateStringAllowedRedeemFrom_no_end_date_uses_start(): void {
        $productId = $this->createProductWithDates('2026-08-01', '09:00:00');
        $ticket = $this->main->getTicketHandler();

        $result = $ticket->calcDateStringAllowedRedeemFrom($productId);
        // When no end date, it uses the start date
        $this->assertEquals('2026-08-01', $result['ticket_end_date']);
        $this->assertFalse($result['is_end_date_set']);
    }

    public function test_calcDateStringAllowedRedeemFrom_no_end_time_defaults_to_2359(): void {
        $productId = $this->createProductWithDates('2026-08-01', '09:00:00', '2026-08-01');
        $ticket = $this->main->getTicketHandler();

        $result = $ticket->calcDateStringAllowedRedeemFrom($productId);
        $this->assertEquals('23:59:59', $result['ticket_end_time']);
        $this->assertFalse($result['is_end_time_set']);
    }

    public function test_calcDateStringAllowedRedeemFrom_timestamps_are_integers(): void {
        $productId = $this->createProductWithDates('2026-06-15', '10:00:00', '2026-06-15', '22:00:00');
        $ticket = $this->main->getTicketHandler();

        $result = $ticket->calcDateStringAllowedRedeemFrom($productId);
        $this->assertIsInt($result['ticket_start_date_timestamp']);
        $this->assertIsInt($result['ticket_end_date_timestamp']);
        $this->assertIsInt($result['redeem_allowed_from_timestamp']);
        $this->assertIsInt($result['redeem_allowed_until_timestamp']);
        $this->assertIsInt($result['server_time_timestamp']);
    }

    public function test_calcDateStringAllowedRedeemFrom_past_event_too_late(): void {
        $productId = $this->createProductWithDates('2020-01-01', '10:00:00', '2020-01-01', '22:00:00');
        $ticket = $this->main->getTicketHandler();

        $result = $ticket->calcDateStringAllowedRedeemFrom($productId);
        $this->assertTrue($result['redeem_allowed_too_late']);
    }

    public function test_calcDateStringAllowedRedeemFrom_future_event_not_too_late(): void {
        $productId = $this->createProductWithDates('2030-01-01', '10:00:00', '2030-01-01', '22:00:00');
        $ticket = $this->main->getTicketHandler();

        $result = $ticket->calcDateStringAllowedRedeemFrom($productId);
        $this->assertFalse($result['redeem_allowed_too_late']);
    }

    public function test_calcDateStringAllowedRedeemFrom_daychooser_flag(): void {
        $productId = $this->createProductWithDates('2026-06-15');
        update_post_meta($productId, 'saso_eventtickets_is_daychooser', 'yes');

        $ticket = $this->main->getTicketHandler();
        $result = $ticket->calcDateStringAllowedRedeemFrom($productId);
        $this->assertTrue($result['is_daychooser']);
    }

    public function test_calcDateStringAllowedRedeemFrom_parsed_parts(): void {
        $productId = $this->createProductWithDates('2026-06-15', '10:30:00', '2026-06-15', '22:45:00');
        $ticket = $this->main->getTicketHandler();

        $result = $ticket->calcDateStringAllowedRedeemFrom($productId);
        $this->assertEquals('15', $result['ticket_start_p_date']);
        $this->assertEquals('06', $result['ticket_start_p_month']);
        $this->assertEquals('2026', $result['ticket_start_p_year']);
        $this->assertEquals('10', $result['ticket_start_p_hour']);
        $this->assertEquals('30', $result['ticket_start_p_min']);
    }

    // ── ermittelCodePosition ─────────────────────────────────────

    public function test_ermittelCodePosition_first_element(): void {
        $ticket = $this->main->getTicketHandler();
        $codes = ['AAA', 'BBB', 'CCC'];
        $this->assertEquals(1, $ticket->ermittelCodePosition('AAA', $codes));
    }

    public function test_ermittelCodePosition_middle_element(): void {
        $ticket = $this->main->getTicketHandler();
        $codes = ['AAA', 'BBB', 'CCC'];
        $this->assertEquals(2, $ticket->ermittelCodePosition('BBB', $codes));
    }

    public function test_ermittelCodePosition_last_element(): void {
        $ticket = $this->main->getTicketHandler();
        $codes = ['AAA', 'BBB', 'CCC'];
        $this->assertEquals(3, $ticket->ermittelCodePosition('CCC', $codes));
    }

    public function test_ermittelCodePosition_not_found_returns_1(): void {
        $ticket = $this->main->getTicketHandler();
        $codes = ['AAA', 'BBB', 'CCC'];
        $this->assertEquals(1, $ticket->ermittelCodePosition('ZZZ', $codes));
    }

    public function test_ermittelCodePosition_empty_array_returns_1(): void {
        $ticket = $this->main->getTicketHandler();
        $this->assertEquals(1, $ticket->ermittelCodePosition('AAA', []));
    }

    // ── isOrderPaid ──────────────────────────────────────────────

    public function test_isOrderPaid_completed_order(): void {
        $order = wc_create_order();
        $order->set_status('completed');
        $order->save();

        $this->assertTrue(SASO_EVENTTICKETS::isOrderPaid($order));
    }

    public function test_isOrderPaid_processing_order(): void {
        $order = wc_create_order();
        $order->set_status('processing');
        $order->save();

        $this->assertTrue(SASO_EVENTTICKETS::isOrderPaid($order));
    }

    public function test_isOrderPaid_pending_order(): void {
        $order = wc_create_order();
        $order->set_status('pending');
        $order->save();

        $this->assertFalse(SASO_EVENTTICKETS::isOrderPaid($order));
    }

    public function test_isOrderPaid_cancelled_order(): void {
        $order = wc_create_order();
        $order->set_status('cancelled');
        $order->save();

        $this->assertFalse(SASO_EVENTTICKETS::isOrderPaid($order));
    }

    public function test_isOrderPaid_null_returns_false(): void {
        $this->assertFalse(SASO_EVENTTICKETS::isOrderPaid(null));
    }

    public function test_isOrderPaid_non_order_returns_false(): void {
        $this->assertFalse(SASO_EVENTTICKETS::isOrderPaid('not_an_order'));
    }
}
