<?php
/**
 * Tests for Ticket date calculation methods:
 * calcDateStringAllowedRedeemFrom with various product configurations.
 */

class TicketCalcDateTest extends WP_UnitTestCase {

    private $main;

    public function set_up(): void {
        parent::set_up();
        $this->main = sasoEventtickets::Instance();

        if (!class_exists('WC_Product_Simple')) {
            $this->markTestSkipped('WooCommerce not available');
        }
    }

    private function createProductWithDates(
        string $startDate = '',
        string $startTime = '',
        string $endDate = '',
        string $endTime = ''
    ): WC_Product_Simple {
        $product = new WC_Product_Simple();
        $product->set_name('DateCalc Product ' . uniqid());
        $product->set_regular_price('10.00');
        $product->set_status('publish');
        $product->save();

        if (!empty($startDate)) {
            update_post_meta($product->get_id(), 'saso_eventtickets_ticket_start_date', $startDate);
        }
        if (!empty($startTime)) {
            update_post_meta($product->get_id(), 'saso_eventtickets_ticket_start_time', $startTime);
        }
        if (!empty($endDate)) {
            update_post_meta($product->get_id(), 'saso_eventtickets_ticket_end_date', $endDate);
        }
        if (!empty($endTime)) {
            update_post_meta($product->get_id(), 'saso_eventtickets_ticket_end_time', $endTime);
        }

        return $product;
    }

    // ── calcDateStringAllowedRedeemFrom ──────────────────────────

    public function test_calcDate_returns_array(): void {
        $product = $this->createProductWithDates('2026-12-25', '10:00:00');
        $ticket = $this->main->getTicketHandler();
        $result = $ticket->calcDateStringAllowedRedeemFrom($product->get_id());
        $this->assertIsArray($result);
    }

    public function test_calcDate_has_expected_keys(): void {
        $product = $this->createProductWithDates('2026-12-25', '10:00:00');
        $ticket = $this->main->getTicketHandler();
        $result = $ticket->calcDateStringAllowedRedeemFrom($product->get_id());

        $this->assertArrayHasKey('ticket_start_date', $result);
        $this->assertArrayHasKey('ticket_start_time', $result);
        $this->assertArrayHasKey('ticket_end_date', $result);
        $this->assertArrayHasKey('ticket_end_time', $result);
        $this->assertArrayHasKey('is_date_set', $result);
        $this->assertArrayHasKey('is_daychooser', $result);
        $this->assertArrayHasKey('ticket_start_date_timestamp', $result);
    }

    public function test_calcDate_with_start_date(): void {
        $product = $this->createProductWithDates('2026-12-25', '14:00:00');
        $ticket = $this->main->getTicketHandler();
        $result = $ticket->calcDateStringAllowedRedeemFrom($product->get_id());

        $this->assertEquals('2026-12-25', $result['ticket_start_date']);
        $this->assertEquals('14:00:00', $result['ticket_start_time']);
        $this->assertTrue($result['is_date_set']);
    }

    public function test_calcDate_without_date_uses_today(): void {
        $product = $this->createProductWithDates();
        $ticket = $this->main->getTicketHandler();
        $result = $ticket->calcDateStringAllowedRedeemFrom($product->get_id());

        // Without date, uses today
        $this->assertFalse($result['is_date_set']);
        $this->assertEquals(wp_date('Y-m-d'), $result['ticket_start_date']);
    }

    public function test_calcDate_with_end_date(): void {
        $product = $this->createProductWithDates('2026-06-01', '09:00:00', '2026-06-03', '23:00:00');
        $ticket = $this->main->getTicketHandler();
        $result = $ticket->calcDateStringAllowedRedeemFrom($product->get_id());

        $this->assertEquals('2026-06-01', $result['ticket_start_date']);
        $this->assertEquals('2026-06-03', $result['ticket_end_date']);
    }

    public function test_calcDate_without_end_date_uses_start(): void {
        $product = $this->createProductWithDates('2026-06-15', '10:00:00');
        $ticket = $this->main->getTicketHandler();
        $result = $ticket->calcDateStringAllowedRedeemFrom($product->get_id());

        // Without end date, should use start date
        $this->assertEquals('2026-06-15', $result['ticket_end_date']);
        $this->assertFalse($result['is_end_date_set']);
    }

    public function test_calcDate_daychooser_flag(): void {
        $product = $this->createProductWithDates('2026-06-01', '10:00:00');
        update_post_meta($product->get_id(), 'saso_eventtickets_is_daychooser', 'yes');

        $ticket = $this->main->getTicketHandler();
        $result = $ticket->calcDateStringAllowedRedeemFrom($product->get_id());

        $this->assertTrue($result['is_daychooser']);
    }

    public function test_calcDate_no_daychooser_by_default(): void {
        $product = $this->createProductWithDates('2026-06-15');
        $ticket = $this->main->getTicketHandler();
        $result = $ticket->calcDateStringAllowedRedeemFrom($product->get_id());

        $this->assertFalse($result['is_daychooser']);
    }

    public function test_calcDate_timestamps_are_numeric(): void {
        $product = $this->createProductWithDates('2026-12-25', '10:00:00', '2026-12-25', '22:00:00');
        $ticket = $this->main->getTicketHandler();
        $result = $ticket->calcDateStringAllowedRedeemFrom($product->get_id());

        $this->assertIsNumeric($result['ticket_start_date_timestamp']);
        $this->assertGreaterThan(0, $result['ticket_start_date_timestamp']);
    }

    public function test_calcDate_parsed_date_components(): void {
        $product = $this->createProductWithDates('2026-12-25', '14:30:00');
        $ticket = $this->main->getTicketHandler();
        $result = $ticket->calcDateStringAllowedRedeemFrom($product->get_id());

        $this->assertEquals('25', $result['ticket_start_p_date']);
        $this->assertEquals('12', $result['ticket_start_p_month']);
        $this->assertEquals('2026', $result['ticket_start_p_year']);
    }
}
