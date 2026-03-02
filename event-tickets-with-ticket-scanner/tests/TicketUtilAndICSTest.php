<?php
/**
 * Batch 49 — Ticket utility methods and ICS generation:
 * - ermittelCodePosition: find code position in array
 * - getRedeemAmountText: redeem amount display text
 * - countRedeemsToday: count today's redeems
 * - get_product: product lookup with caching
 * - generateICSFile: ICS calendar file generation
 * - setCodeObj / getOrder / setOrder
 */

class TicketUtilAndICSTest extends WP_UnitTestCase {

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
		$product->set_name('Util Test ' . uniqid());
		$product->set_regular_price('15.00');
		$product->set_status('publish');
		$product->save();
		$pid = $product->get_id();

		foreach ($meta as $key => $value) {
			update_post_meta($pid, $key, $value);
		}

		return $pid;
	}

	private function createCodeWithProduct(int $productId, int $orderId = 0): array {
		$listId = $this->main->getDB()->insert('lists', [
			'name' => 'Util List ' . uniqid(),
			'aktiv' => 1,
			'meta' => '{}',
		]);

		$metaObj = $this->main->getCore()->getMetaObject();
		$metaObj['woocommerce']['product_id'] = $productId;
		$metaJson = $this->main->getCore()->json_encode_with_error_handling($metaObj);

		$codeStr = 'UTIL' . strtoupper(uniqid());
		$this->main->getDB()->insert('codes', [
			'list_id' => $listId,
			'code' => $codeStr,
			'aktiv' => 1,
			'cvv' => '',
			'order_id' => $orderId,
			'user_id' => 0,
			'meta' => $metaJson,
		]);

		$codeObj = $this->main->getCore()->retrieveCodeByCode($codeStr);
		return ['list_id' => $listId, 'code' => $codeStr, 'codeObj' => $codeObj];
	}

	// ── ermittelCodePosition ────────────────────────────────────

	public function test_ermittelCodePosition_first_code(): void {
		$result = $this->ticket->ermittelCodePosition('AAA', ['AAA', 'BBB', 'CCC']);
		$this->assertEquals(1, $result);
	}

	public function test_ermittelCodePosition_second_code(): void {
		$result = $this->ticket->ermittelCodePosition('BBB', ['AAA', 'BBB', 'CCC']);
		$this->assertEquals(2, $result);
	}

	public function test_ermittelCodePosition_last_code(): void {
		$result = $this->ticket->ermittelCodePosition('CCC', ['AAA', 'BBB', 'CCC']);
		$this->assertEquals(3, $result);
	}

	public function test_ermittelCodePosition_not_found_returns_one(): void {
		$result = $this->ticket->ermittelCodePosition('ZZZ', ['AAA', 'BBB']);
		$this->assertEquals(1, $result);
	}

	public function test_ermittelCodePosition_single_code(): void {
		$result = $this->ticket->ermittelCodePosition('AAA', ['AAA']);
		$this->assertEquals(1, $result);
	}

	public function test_ermittelCodePosition_empty_array(): void {
		$result = $this->ticket->ermittelCodePosition('AAA', []);
		$this->assertEquals(1, $result);
	}

	// ── countRedeemsToday ───────────────────────────────────────

	public function test_countRedeemsToday_empty_array(): void {
		$result = $this->ticket->countRedeemsToday([]);
		$this->assertEquals(0, $result);
	}

	public function test_countRedeemsToday_entries_today(): void {
		$today = wp_date('Y-m-d');
		$entries = [
			['redeemed_date' => $today . ' 10:00:00'],
			['redeemed_date' => $today . ' 14:30:00'],
		];
		$result = $this->ticket->countRedeemsToday($entries);
		$this->assertEquals(2, $result);
	}

	public function test_countRedeemsToday_entries_other_day(): void {
		$entries = [
			['redeemed_date' => '2025-01-01 10:00:00'],
			['redeemed_date' => '2025-06-15 14:30:00'],
		];
		$result = $this->ticket->countRedeemsToday($entries);
		$this->assertEquals(0, $result);
	}

	public function test_countRedeemsToday_mixed_entries(): void {
		$today = wp_date('Y-m-d');
		$entries = [
			['redeemed_date' => '2025-01-01 10:00:00'],
			['redeemed_date' => $today . ' 08:00:00'],
			['redeemed_date' => '2024-12-31 23:59:59'],
			['redeemed_date' => $today . ' 23:59:59'],
		];
		$result = $this->ticket->countRedeemsToday($entries);
		$this->assertEquals(2, $result);
	}

	public function test_countRedeemsToday_empty_redeemed_date(): void {
		$entries = [
			['redeemed_date' => ''],
			['other_key' => 'value'],
		];
		$result = $this->ticket->countRedeemsToday($entries);
		$this->assertEquals(0, $result);
	}

	// ── getRedeemAmountText ─────────────────────────────────────

	public function test_getRedeemAmountText_empty_for_max_one(): void {
		$pid = $this->createTicketProduct();
		$data = $this->createCodeWithProduct($pid);
		$codeObj = $data['codeObj'];
		$metaObj = $this->main->getCore()->encodeMetaValuesAndFillObject($codeObj['meta'], $codeObj);
		$result = $this->ticket->getRedeemAmountText($codeObj, $metaObj);
		$this->assertEquals('', $result);
	}

	public function test_getRedeemAmountText_with_max_greater_one_pdf(): void {
		$pid = $this->createTicketProduct([
			'saso_eventtickets_ticket_max_redeem_amount' => 5,
		]);
		$data = $this->createCodeWithProduct($pid);
		$codeObj = $data['codeObj'];
		$metaObj = $this->main->getCore()->encodeMetaValuesAndFillObject($codeObj['meta'], $codeObj);
		$result = $this->ticket->getRedeemAmountText($codeObj, $metaObj, true);
		$this->assertNotEmpty($result);
		$this->assertStringContainsString('5', $result);
	}

	public function test_getRedeemAmountText_with_max_greater_one_screen(): void {
		$pid = $this->createTicketProduct([
			'saso_eventtickets_ticket_max_redeem_amount' => 3,
		]);
		$data = $this->createCodeWithProduct($pid);
		$codeObj = $data['codeObj'];
		$metaObj = $this->main->getCore()->encodeMetaValuesAndFillObject($codeObj['meta'], $codeObj);
		$result = $this->ticket->getRedeemAmountText($codeObj, $metaObj, false);
		$this->assertNotEmpty($result);
		$this->assertStringContainsString('3', $result);
	}

	// ── get_product ─────────────────────────────────────────────

	public function test_get_product_returns_product(): void {
		$pid = $this->createTicketProduct();
		$product = $this->ticket->get_product($pid);
		$this->assertInstanceOf(WC_Product::class, $product);
		$this->assertEquals($pid, $product->get_id());
	}

	public function test_get_product_caches_result(): void {
		$pid = $this->createTicketProduct();
		$product1 = $this->ticket->get_product($pid);
		$product2 = $this->ticket->get_product($pid);
		// Both calls return equivalent objects
		$this->assertEquals($product1->get_id(), $product2->get_id());
	}

	// ── setCodeObj / setOrder / getOrder ─────────────────────────

	public function test_setOrder_stores_order(): void {
		$order = wc_create_order();
		$order->save();
		$this->ticket->setOrder($order);

		// getOrder is private, use Reflection
		$ref = new ReflectionProperty($this->ticket, 'order');
		$ref->setAccessible(true);
		$retrieved = $ref->getValue($this->ticket);
		$this->assertNotNull($retrieved);
		$this->assertEquals($order->get_id(), $retrieved->get_id());
	}

	public function test_setCodeObj_clears_order(): void {
		$pid = $this->createTicketProduct();
		$data = $this->createCodeWithProduct($pid);

		$order = wc_create_order();
		$order->save();
		$this->ticket->setOrder($order);

		// setCodeObj should reset order to null
		$this->ticket->setCodeObj($data['codeObj']);

		$ref = new ReflectionProperty($this->ticket, 'order');
		$ref->setAccessible(true);
		$this->assertNull($ref->getValue($this->ticket));
	}

	// ── generateICSFile ─────────────────────────────────────────

	public function test_generateICSFile_with_start_date(): void {
		$pid = $this->createTicketProduct([
			'saso_eventtickets_ticket_start_date' => '2026-12-25',
			'saso_eventtickets_ticket_start_time' => '19:00',
		]);
		$product = wc_get_product($pid);
		$ics = $this->ticket->generateICSFile($product);

		$this->assertStringContainsString('BEGIN:VCALENDAR', $ics);
		$this->assertStringContainsString('BEGIN:VEVENT', $ics);
		$this->assertStringContainsString('END:VEVENT', $ics);
		$this->assertStringContainsString('END:VCALENDAR', $ics);
		$this->assertStringContainsString('DTSTART', $ics);
		$this->assertStringContainsString('SUMMARY:Util Test', $ics);
	}

	public function test_generateICSFile_contains_version(): void {
		$pid = $this->createTicketProduct([
			'saso_eventtickets_ticket_start_date' => '2026-06-15',
		]);
		$product = wc_get_product($pid);
		$ics = $this->ticket->generateICSFile($product);

		$this->assertStringContainsString('VERSION:2.0', $ics);
		$this->assertStringContainsString('PRODID:', $ics);
	}

	public function test_generateICSFile_with_start_and_end(): void {
		$pid = $this->createTicketProduct([
			'saso_eventtickets_ticket_start_date' => '2026-12-25',
			'saso_eventtickets_ticket_start_time' => '19:00',
			'saso_eventtickets_ticket_end_date' => '2026-12-26',
			'saso_eventtickets_ticket_end_time' => '02:00',
		]);
		$product = wc_get_product($pid);
		$ics = $this->ticket->generateICSFile($product);

		$this->assertStringContainsString('DTSTART', $ics);
		$this->assertStringContainsString('DTEND', $ics);
	}

	public function test_generateICSFile_with_location(): void {
		$pid = $this->createTicketProduct([
			'saso_eventtickets_ticket_start_date' => '2026-12-25',
			'saso_eventtickets_event_location' => 'Berlin Arena',
		]);
		$product = wc_get_product($pid);
		$ics = $this->ticket->generateICSFile($product);

		$this->assertStringContainsString('LOCATION:', $ics);
		$this->assertStringContainsString('Berlin Arena', $ics);
	}

	public function test_generateICSFile_date_only_no_time(): void {
		$pid = $this->createTicketProduct([
			'saso_eventtickets_ticket_start_date' => '2026-12-25',
		]);
		$product = wc_get_product($pid);
		$ics = $this->ticket->generateICSFile($product);

		// Date-only uses VALUE=DATE format
		$this->assertStringContainsString('VALUE=DATE', $ics);
	}

	public function test_generateICSFile_with_codeObj(): void {
		$pid = $this->createTicketProduct([
			'saso_eventtickets_ticket_start_date' => '2026-12-25',
			'saso_eventtickets_ticket_start_time' => '18:00',
		]);
		$data = $this->createCodeWithProduct($pid);
		$product = wc_get_product($pid);
		$ics = $this->ticket->generateICSFile($product, $data['codeObj']);

		$this->assertStringContainsString('BEGIN:VCALENDAR', $ics);
		$this->assertStringContainsString('DTSTART', $ics);
	}

	public function test_generateICSFile_contains_uid(): void {
		$pid = $this->createTicketProduct([
			'saso_eventtickets_ticket_start_date' => '2026-12-25',
		]);
		$product = wc_get_product($pid);
		$ics = $this->ticket->generateICSFile($product);

		$this->assertStringContainsString('UID:', $ics);
		$this->assertStringContainsString('DTSTAMP:', $ics);
	}

	public function test_generateICSFile_contains_url(): void {
		$pid = $this->createTicketProduct([
			'saso_eventtickets_ticket_start_date' => '2026-12-25',
		]);
		$product = wc_get_product($pid);
		$ics = $this->ticket->generateICSFile($product);

		$this->assertStringContainsString('URL:', $ics);
	}

	// ── isScanner ───────────────────────────────────────────────

	public function test_isScanner_returns_bool(): void {
		$result = $this->ticket->isScanner();
		$this->assertIsBool($result);
	}

	// ── get_expiration ──────────────────────────────────────────

	public function test_get_expiration_returns_array(): void {
		$result = $this->ticket->get_expiration();
		$this->assertIsArray($result);
		$this->assertArrayHasKey('last_run', $result);
		$this->assertArrayHasKey('timestamp', $result);
		$this->assertArrayHasKey('expiration_date', $result);
		$this->assertArrayHasKey('subscription_type', $result);
		$this->assertArrayHasKey('grace_period_days', $result);
	}

	public function test_get_expiration_default_subscription_type(): void {
		$result = $this->ticket->get_expiration();
		$this->assertEquals('abo', $result['subscription_type']);
	}

	public function test_get_expiration_default_grace_period(): void {
		$result = $this->ticket->get_expiration();
		$this->assertEquals(7, $result['grace_period_days']);
	}
}
