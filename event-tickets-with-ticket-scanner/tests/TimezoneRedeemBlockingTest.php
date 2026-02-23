<?php
/**
 * Tests that ticket timestamps respect the WordPress timezone setting.
 *
 * Bug: strtotime() interprets local dates as UTC (because WordPress sets
 * date_default_timezone_set('UTC')). The fix uses DateTime with wp_timezone()
 * to correctly interpret admin-entered dates in the WordPress timezone.
 */

class TimezoneRedeemBlockingTest extends WP_UnitTestCase {

	private $main;
	private $original_timezone;

	public function set_up(): void {
		parent::set_up();
		$this->main = sasoEventtickets::Instance();

		if (!class_exists('WC_Product_Simple')) {
			$this->markTestSkipped('WooCommerce not available');
		}

		// Save original timezone
		$this->original_timezone = get_option('timezone_string');
	}

	public function tear_down(): void {
		// Restore original timezone
		if (!empty($this->original_timezone)) {
			update_option('timezone_string', $this->original_timezone);
		} else {
			delete_option('timezone_string');
		}
		parent::tear_down();
	}

	private function createProductWithDates(
		string $startDate,
		string $startTime = '',
		string $endDate = '',
		string $endTime = ''
	): int {
		$product = new WC_Product_Simple();
		$product->set_name('TZ Test Product ' . uniqid());
		$product->set_regular_price('10.00');
		$product->set_status('publish');
		$product->save();
		$productId = $product->get_id();

		update_post_meta($productId, 'saso_eventtickets_is_ticket', 'yes');
		update_post_meta($productId, 'saso_eventtickets_ticket_start_date', $startDate);
		if (!empty($startTime)) {
			update_post_meta($productId, 'saso_eventtickets_ticket_start_time', $startTime);
		}
		if (!empty($endDate)) {
			update_post_meta($productId, 'saso_eventtickets_ticket_end_date', $endDate);
		}
		if (!empty($endTime)) {
			update_post_meta($productId, 'saso_eventtickets_ticket_end_time', $endTime);
		}

		return $productId;
	}

	// ── Timestamp correctness with non-UTC timezone ─────────────

	public function test_start_timestamp_respects_positive_timezone(): void {
		// Berlin = UTC+1 (winter) / UTC+2 (summer)
		// 2026-01-15 is winter → UTC+1
		update_option('timezone_string', 'Europe/Berlin');

		$productId = $this->createProductWithDates('2026-01-15', '19:00:00');
		$ticket = $this->main->getTicketHandler();
		$result = $ticket->calcDateStringAllowedRedeemFrom($productId);

		// 19:00 Berlin (UTC+1) = 18:00 UTC
		$expected = gmmktime(18, 0, 0, 1, 15, 2026);
		$this->assertEquals($expected, $result['ticket_start_date_timestamp'],
			'19:00 Europe/Berlin should be 18:00 UTC');
	}

	public function test_start_timestamp_respects_negative_timezone(): void {
		// São Paulo = UTC-3
		update_option('timezone_string', 'America/Sao_Paulo');

		$productId = $this->createProductWithDates('2026-01-15', '19:00:00');
		$ticket = $this->main->getTicketHandler();
		$result = $ticket->calcDateStringAllowedRedeemFrom($productId);

		// 19:00 São Paulo (UTC-3) = 22:00 UTC
		$expected = gmmktime(22, 0, 0, 1, 15, 2026);
		$this->assertEquals($expected, $result['ticket_start_date_timestamp'],
			'19:00 America/Sao_Paulo should be 22:00 UTC');
	}

	public function test_end_timestamp_respects_timezone(): void {
		update_option('timezone_string', 'America/Sao_Paulo');

		$productId = $this->createProductWithDates('2026-01-15', '19:00:00', '2026-01-15', '23:00:00');
		$ticket = $this->main->getTicketHandler();
		$result = $ticket->calcDateStringAllowedRedeemFrom($productId);

		// 23:00 São Paulo (UTC-3) = 02:00 UTC next day
		$expected = gmmktime(2, 0, 0, 1, 16, 2026);
		$this->assertEquals($expected, $result['ticket_end_date_timestamp'],
			'23:00 America/Sao_Paulo should be 02:00 UTC next day');
	}

	public function test_utc_timezone_unchanged(): void {
		update_option('timezone_string', 'UTC');

		$productId = $this->createProductWithDates('2026-06-15', '10:00:00');
		$ticket = $this->main->getTicketHandler();
		$result = $ticket->calcDateStringAllowedRedeemFrom($productId);

		$expected = gmmktime(10, 0, 0, 6, 15, 2026);
		$this->assertEquals($expected, $result['ticket_start_date_timestamp'],
			'UTC timezone should produce unchanged timestamp');
	}

	// ── Parsed components still match local time ────────────────

	public function test_parsed_hour_shows_local_time(): void {
		update_option('timezone_string', 'America/Sao_Paulo');

		$productId = $this->createProductWithDates('2026-01-15', '19:30:00');
		$ticket = $this->main->getTicketHandler();
		$result = $ticket->calcDateStringAllowedRedeemFrom($productId);

		// wp_date() formats in local timezone, so parsed components should show 19:30
		$this->assertEquals('19', $result['ticket_start_p_hour']);
		$this->assertEquals('30', $result['ticket_start_p_min']);
	}

	public function test_parsed_date_shows_local_date(): void {
		update_option('timezone_string', 'America/Sao_Paulo');

		$productId = $this->createProductWithDates('2026-01-15', '19:00:00');
		$ticket = $this->main->getTicketHandler();
		$result = $ticket->calcDateStringAllowedRedeemFrom($productId);

		$this->assertEquals('15', $result['ticket_start_p_date']);
		$this->assertEquals('01', $result['ticket_start_p_month']);
		$this->assertEquals('2026', $result['ticket_start_p_year']);
	}

	// ── DST handling ────────────────────────────────────────────

	public function test_summer_time_offset_differs_from_winter(): void {
		update_option('timezone_string', 'Europe/Berlin');

		// Winter: 2026-01-15 → UTC+1
		$winterProd = $this->createProductWithDates('2026-01-15', '12:00:00');
		// Summer: 2026-07-15 → UTC+2
		$summerProd = $this->createProductWithDates('2026-07-15', '12:00:00');

		$ticket = $this->main->getTicketHandler();
		$winterResult = $ticket->calcDateStringAllowedRedeemFrom($winterProd);
		$summerResult = $ticket->calcDateStringAllowedRedeemFrom($summerProd);

		// Winter 12:00 Berlin = 11:00 UTC
		$expectedWinter = gmmktime(11, 0, 0, 1, 15, 2026);
		// Summer 12:00 Berlin = 10:00 UTC
		$expectedSummer = gmmktime(10, 0, 0, 7, 15, 2026);

		$this->assertEquals($expectedWinter, $winterResult['ticket_start_date_timestamp'],
			'Winter: 12:00 Berlin should be 11:00 UTC');
		$this->assertEquals($expectedSummer, $summerResult['ticket_start_date_timestamp'],
			'Summer: 12:00 Berlin should be 10:00 UTC');
	}

	// ── Redeem blocking uses correct timestamp ──────────────────

	public function test_future_event_not_blocked_in_negative_tz(): void {
		update_option('timezone_string', 'America/Sao_Paulo');

		// Event far in the future — should never be "too late"
		$productId = $this->createProductWithDates('2030-06-15', '19:00:00', '2030-06-15', '23:00:00');
		$ticket = $this->main->getTicketHandler();
		$result = $ticket->calcDateStringAllowedRedeemFrom($productId);

		$this->assertFalse($result['redeem_allowed_too_late']);
	}

	public function test_past_event_blocked_in_negative_tz(): void {
		update_option('timezone_string', 'America/Sao_Paulo');

		// Event in the past
		$productId = $this->createProductWithDates('2020-01-01', '10:00:00', '2020-01-01', '22:00:00');
		$ticket = $this->main->getTicketHandler();
		$result = $ticket->calcDateStringAllowedRedeemFrom($productId);

		$this->assertTrue($result['redeem_allowed_too_late']);
	}
}
