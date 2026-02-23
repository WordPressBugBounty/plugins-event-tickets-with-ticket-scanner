<?php
/**
 * Tests for sasoEventtickets_Ticket::countRedeemsToday()
 */

class CountRedeemsTodayTest extends WP_UnitTestCase {

    private sasoEventtickets_Ticket $ticket;

    public function set_up(): void {
        parent::set_up();
        $this->ticket = sasoEventtickets::Instance()->getTicketHandler();
    }

    public function test_no_redeems_returns_zero(): void {
        $result = $this->ticket->countRedeemsToday([]);
        $this->assertSame(0, $result);
    }

    public function test_redeems_today_counted_correctly(): void {
        $today = wp_date('Y-m-d');
        $statsRedeemed = [
            ['redeemed_date' => $today . ' 08:00:00'],
            ['redeemed_date' => $today . ' 14:30:00'],
        ];
        $result = $this->ticket->countRedeemsToday($statsRedeemed);
        $this->assertSame(2, $result);
    }

    public function test_redeems_from_yesterday_not_counted(): void {
        $yesterday = wp_date('Y-m-d', strtotime('-1 day'));
        $statsRedeemed = [
            ['redeemed_date' => $yesterday . ' 23:59:59'],
        ];
        $result = $this->ticket->countRedeemsToday($statsRedeemed);
        $this->assertSame(0, $result);
    }

    public function test_mixed_dates(): void {
        $today = wp_date('Y-m-d');
        $yesterday = wp_date('Y-m-d', strtotime('-1 day'));
        $statsRedeemed = [
            ['redeemed_date' => $today . ' 09:00:00'],
            ['redeemed_date' => $yesterday . ' 18:00:00'],
            ['redeemed_date' => $today . ' 17:30:00'],
        ];
        $result = $this->ticket->countRedeemsToday($statsRedeemed);
        $this->assertSame(2, $result);
    }

    public function test_empty_redeemed_date_skipped(): void {
        $statsRedeemed = [
            ['redeemed_date' => ''],
            ['redeemed_date' => ''],
        ];
        $result = $this->ticket->countRedeemsToday($statsRedeemed);
        $this->assertSame(0, $result);
    }
}
