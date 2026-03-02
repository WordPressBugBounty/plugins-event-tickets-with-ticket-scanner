<?php
/**
 * Batch 51 — Date calculation and correct product resolution:
 * - calcDateStringAllowedRedeemFrom: full date calculation with daychooser, start/end dates
 * - getCalcDateStringAllowedRedeemFromCorrectProduct: variation → parent resolution
 * - getDefaultMetaValueOfSubs: default meta sub-values
 * - cronJobDaily: cron job execution (fires action)
 */

class DateCalcAndCorrectProductTest extends WP_UnitTestCase {

	private $main;
	private $ticket;

	public function set_up(): void {
		parent::set_up();
		$this->main = sasoEventtickets::Instance();
		$this->ticket = $this->main->getTicketHandler();

		if (!class_exists('WC_Product_Simple')) {
			$this->markTestSkipped('WooCommerce not available');
		}
	}

	private function createTicketProduct(array $meta = []): int {
		$product = new WC_Product_Simple();
		$product->set_name('DateCalc Test ' . uniqid());
		$product->set_regular_price('20.00');
		$product->set_status('publish');
		$product->save();
		$pid = $product->get_id();

		foreach ($meta as $key => $value) {
			update_post_meta($pid, $key, $value);
		}

		return $pid;
	}

	// ── calcDateStringAllowedRedeemFrom ─────────────────────────

	public function test_calcDateString_returns_array_with_required_keys(): void {
		$pid = $this->createTicketProduct([
			'saso_eventtickets_ticket_start_date' => '2026-12-25',
		]);

		$result = $this->ticket->calcDateStringAllowedRedeemFrom($pid);

		$this->assertIsArray($result);
		$this->assertArrayHasKey('is_daychooser', $result);
		$this->assertArrayHasKey('is_date_set', $result);
		$this->assertArrayHasKey('ticket_start_date', $result);
		$this->assertArrayHasKey('ticket_start_time', $result);
		$this->assertArrayHasKey('ticket_end_date', $result);
		$this->assertArrayHasKey('ticket_end_time', $result);
		$this->assertArrayHasKey('ticket_start_date_timestamp', $result);
		$this->assertArrayHasKey('ticket_end_date_timestamp', $result);
		$this->assertArrayHasKey('redeem_allowed_from', $result);
		$this->assertArrayHasKey('redeem_allowed_from_timestamp', $result);
		$this->assertArrayHasKey('redeem_allowed_until', $result);
		$this->assertArrayHasKey('redeem_allowed_until_timestamp', $result);
		$this->assertArrayHasKey('server_time_timestamp', $result);
	}

	public function test_calcDateString_start_date_only(): void {
		$pid = $this->createTicketProduct([
			'saso_eventtickets_ticket_start_date' => '2026-12-25',
		]);

		$result = $this->ticket->calcDateStringAllowedRedeemFrom($pid);

		$this->assertEquals('2026-12-25', $result['ticket_start_date']);
		$this->assertTrue($result['is_date_set']);
		$this->assertFalse($result['is_daychooser']);
	}

	public function test_calcDateString_start_and_end(): void {
		$pid = $this->createTicketProduct([
			'saso_eventtickets_ticket_start_date' => '2026-12-25',
			'saso_eventtickets_ticket_start_time' => '19:00',
			'saso_eventtickets_ticket_end_date' => '2026-12-26',
			'saso_eventtickets_ticket_end_time' => '02:00',
		]);

		$result = $this->ticket->calcDateStringAllowedRedeemFrom($pid);

		$this->assertEquals('2026-12-25', $result['ticket_start_date']);
		$this->assertEquals('19:00', $result['ticket_start_time']);
		$this->assertEquals('2026-12-26', $result['ticket_end_date']);
		$this->assertEquals('02:00', $result['ticket_end_time']);
		$this->assertTrue($result['is_start_time_set']);
	}

	public function test_calcDateString_no_dates_defaults_to_today(): void {
		$pid = $this->createTicketProduct();

		$result = $this->ticket->calcDateStringAllowedRedeemFrom($pid);

		// When no date is set, start_date defaults to today
		$this->assertStringContainsString(wp_date('Y'), $result['ticket_start_date']);
	}

	public function test_calcDateString_is_daychooser_flag(): void {
		$pid = $this->createTicketProduct([
			'saso_eventtickets_is_daychooser' => 'yes',
			'saso_eventtickets_ticket_start_date' => '2026-06-01',
			'saso_eventtickets_ticket_end_date' => '2026-06-30',
		]);

		$result = $this->ticket->calcDateStringAllowedRedeemFrom($pid);
		$this->assertTrue($result['is_daychooser']);
	}

	public function test_calcDateString_server_time_is_current(): void {
		$pid = $this->createTicketProduct([
			'saso_eventtickets_ticket_start_date' => '2026-12-25',
		]);

		$before = time();
		$result = $this->ticket->calcDateStringAllowedRedeemFrom($pid);
		$after = time();

		$this->assertGreaterThanOrEqual($before, $result['server_time_timestamp']);
		$this->assertLessThanOrEqual($after, $result['server_time_timestamp']);
	}

	public function test_calcDateString_start_before_end_timestamp(): void {
		$pid = $this->createTicketProduct([
			'saso_eventtickets_ticket_start_date' => '2026-12-25',
			'saso_eventtickets_ticket_start_time' => '10:00',
			'saso_eventtickets_ticket_end_date' => '2026-12-25',
			'saso_eventtickets_ticket_end_time' => '22:00',
		]);

		$result = $this->ticket->calcDateStringAllowedRedeemFrom($pid);

		$this->assertLessThan(
			$result['ticket_end_date_timestamp'],
			$result['ticket_start_date_timestamp']
		);
	}

	public function test_calcDateString_redeem_allowed_from_before_end(): void {
		$pid = $this->createTicketProduct([
			'saso_eventtickets_ticket_start_date' => '2026-12-25',
			'saso_eventtickets_ticket_start_time' => '10:00',
			'saso_eventtickets_ticket_end_date' => '2026-12-25',
			'saso_eventtickets_ticket_end_time' => '22:00',
		]);

		$result = $this->ticket->calcDateStringAllowedRedeemFrom($pid);

		$this->assertLessThanOrEqual(
			$result['redeem_allowed_until_timestamp'],
			$result['redeem_allowed_from_timestamp']
		);
	}

	public function test_calcDateString_with_daychooser_code(): void {
		$pid = $this->createTicketProduct([
			'saso_eventtickets_is_daychooser' => 'yes',
			'saso_eventtickets_ticket_start_date' => '2026-06-01',
			'saso_eventtickets_ticket_end_date' => '2026-06-30',
		]);

		// Create code with daychooser date set
		$listId = $this->main->getDB()->insert('lists', [
			'name' => 'Daychooser Test ' . uniqid(),
			'aktiv' => 1,
			'meta' => '{}',
		]);

		$metaObj = $this->main->getCore()->getMetaObject();
		$metaObj['woocommerce']['product_id'] = $pid;
		$metaObj['wc_ticket']['is_daychooser'] = 1;
		$metaObj['wc_ticket']['day_per_ticket'] = '2026-06-15';
		$metaJson = $this->main->getCore()->json_encode_with_error_handling($metaObj);

		$codeStr = 'DCALC' . strtoupper(uniqid());
		$this->main->getDB()->insert('codes', [
			'list_id' => $listId,
			'code' => $codeStr,
			'aktiv' => 1,
			'cvv' => '',
			'order_id' => 0,
			'user_id' => 0,
			'meta' => $metaJson,
		]);

		$codeObj = $this->main->getCore()->retrieveCodeByCode($codeStr);
		$result = $this->ticket->calcDateStringAllowedRedeemFrom($pid, $codeObj);

		$this->assertTrue($result['is_daychooser']);
		$this->assertTrue($result['is_daychooser_value_set']);
		$this->assertEquals('2026-06-15', $result['ticket_start_date']);
	}

	// ── getCalcDateStringAllowedRedeemFromCorrectProduct ────────

	public function test_getCalcCorrectProduct_simple_product(): void {
		$pid = $this->createTicketProduct([
			'saso_eventtickets_ticket_start_date' => '2026-12-25',
		]);

		$result = $this->ticket->getCalcDateStringAllowedRedeemFromCorrectProduct($pid);

		$this->assertIsArray($result);
		$this->assertEquals('2026-12-25', $result['ticket_start_date']);
	}

	public function test_getCalcCorrectProduct_with_variation(): void {
		// Create variable product with date on parent
		$parent = new WC_Product_Variable();
		$parent->set_name('Variable DateCalc ' . uniqid());
		$parent->set_status('publish');
		$parent->save();
		$parentId = $parent->get_id();

		update_post_meta($parentId, 'saso_eventtickets_ticket_start_date', '2026-11-01');
		update_post_meta($parentId, 'saso_eventtickets_is_date_for_all_variants', 'yes');

		// Create variation
		$variation = new WC_Product_Variation();
		$variation->set_parent_id($parentId);
		$variation->set_regular_price('30.00');
		$variation->set_status('publish');
		$variation->save();
		$variationId = $variation->get_id();

		$result = $this->ticket->getCalcDateStringAllowedRedeemFromCorrectProduct($variationId);

		$this->assertIsArray($result);
		// Should use parent's date since is_date_for_all_variants = yes
		$this->assertEquals('2026-11-01', $result['ticket_start_date']);
	}

	// ── getDefaultMetaValueOfSubs ───────────────────────────────

	public function test_getDefaultMetaValueOfSubs_returns_array(): void {
		$result = $this->main->getCore()->getDefaultMetaValueOfSubs();
		$this->assertIsArray($result);
	}

	// ── cronJobDaily ────────────────────────────────────────────

	public function test_cronJobDaily_fires_action(): void {
		$fired = false;
		add_action($this->main->_do_action_prefix . 'ticket_cronJobDaily', function () use (&$fired) {
			$fired = true;
		});

		$this->ticket->cronJobDaily();

		$this->assertTrue($fired);
	}

	// ── daychooser offset fields ────────────────────────────────

	public function test_calcDateString_daychooser_offset_fields(): void {
		$pid = $this->createTicketProduct([
			'saso_eventtickets_is_daychooser' => 'yes',
			'saso_eventtickets_ticket_start_date' => '2026-06-01',
			'saso_eventtickets_ticket_end_date' => '2026-06-30',
			'saso_eventtickets_daychooser_offset_start' => 2,
			'saso_eventtickets_daychooser_offset_end' => 5,
		]);

		$result = $this->ticket->calcDateStringAllowedRedeemFrom($pid);

		$this->assertEquals(2, $result['daychooser_offset_start']);
		$this->assertEquals(5, $result['daychooser_offset_end']);
	}

	public function test_calcDateString_daychooser_exclude_wdays(): void {
		$pid = $this->createTicketProduct([
			'saso_eventtickets_is_daychooser' => 'yes',
			'saso_eventtickets_ticket_start_date' => '2026-06-01',
			'saso_eventtickets_ticket_end_date' => '2026-06-30',
			'saso_eventtickets_daychooser_exclude_wdays' => [0, 6], // Sun, Sat
		]);

		$result = $this->ticket->calcDateStringAllowedRedeemFrom($pid);

		$this->assertIsArray($result['daychooser_exclude_wdays']);
		$this->assertContains(0, $result['daychooser_exclude_wdays']);
		$this->assertContains(6, $result['daychooser_exclude_wdays']);
	}

	public function test_calcDateString_is_end_date_set_flag(): void {
		$pid = $this->createTicketProduct([
			'saso_eventtickets_ticket_start_date' => '2026-12-25',
			'saso_eventtickets_ticket_end_date' => '2026-12-26',
		]);

		$result = $this->ticket->calcDateStringAllowedRedeemFrom($pid);

		$this->assertTrue($result['is_end_date_set']);
	}

	public function test_calcDateString_is_date_for_all_variants(): void {
		$pid = $this->createTicketProduct([
			'saso_eventtickets_ticket_start_date' => '2026-12-25',
			'saso_eventtickets_is_date_for_all_variants' => 'yes',
		]);

		$result = $this->ticket->calcDateStringAllowedRedeemFrom($pid);

		$this->assertTrue($result['is_date_for_all_variants']);
	}
}
