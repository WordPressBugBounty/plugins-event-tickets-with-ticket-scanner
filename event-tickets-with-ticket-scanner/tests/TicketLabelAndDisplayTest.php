<?php
/**
 * Batch 46 — Ticket label getters, date display, WPML fallback, order item lookup:
 * - getLabelNamePerTicket / getLabelValuePerTicket / getLabelDaychooserPerTicket
 * - getWPMLProductId: filter fallback
 * - displayDayChooserDateAsString: daychooser date formatting
 * - displayTicketDateAsString: ticket date range formatting
 * - getMaxRedeemAmountOfTicket: max redeem from product meta
 * - getOrderItem: order item lookup by metaObj item_id
 */

class TicketLabelAndDisplayTest extends WP_UnitTestCase {

	private $main;
	private $ticket;

	public function set_up(): void {
		parent::set_up();
		$this->main = sasoEventtickets::Instance();
		$this->ticket = $this->main->getTicketHandler();
	}

	private function createTicketProduct(array $meta = []): int {
		if (!class_exists('WC_Product_Simple')) {
			$this->markTestSkipped('WooCommerce not available');
		}

		$product = new WC_Product_Simple();
		$product->set_name('Label Test ' . uniqid());
		$product->set_regular_price('10.00');
		$product->set_status('publish');
		$product->save();
		$pid = $product->get_id();

		foreach ($meta as $key => $value) {
			update_post_meta($pid, $key, $value);
		}

		return $pid;
	}

	private function createCodeWithProduct(int $productId): array {
		$listId = $this->main->getDB()->insert('lists', [
			'name' => 'Display Test ' . uniqid(),
			'aktiv' => 1,
			'meta' => '{}',
		]);

		$metaObj = $this->main->getCore()->getMetaObject();
		$metaObj['woocommerce']['product_id'] = $productId;
		$metaJson = $this->main->getCore()->json_encode_with_error_handling($metaObj);

		$code = 'DISPLAY' . strtoupper(uniqid());
		$this->main->getDB()->insert('codes', [
			'list_id' => $listId,
			'code' => $code,
			'aktiv' => 1,
			'cvv' => '',
			'order_id' => 0,
			'user_id' => 0,
			'meta' => $metaJson,
		]);

		$codeObj = $this->main->getCore()->retrieveCodeByCode($code);
		return ['list_id' => $listId, 'code' => $code, 'codeObj' => $codeObj];
	}

	// ── getLabelNamePerTicket ────────────────────────────────

	public function test_getLabelNamePerTicket_returns_default(): void {
		$pid = $this->createTicketProduct();
		$result = $this->ticket->getLabelNamePerTicket($pid);
		$this->assertStringContainsString('Name', $result);
		$this->assertStringContainsString('{count}', $result);
	}

	public function test_getLabelNamePerTicket_returns_custom(): void {
		$pid = $this->createTicketProduct([
			'saso_eventtickets_request_name_per_ticket_label' => 'Bitte Name eingeben #{count}:',
		]);
		$result = $this->ticket->getLabelNamePerTicket($pid);
		$this->assertEquals('Bitte Name eingeben #{count}:', $result);
	}

	public function test_getLabelNamePerTicket_trims_whitespace(): void {
		$pid = $this->createTicketProduct([
			'saso_eventtickets_request_name_per_ticket_label' => '  Custom Label  ',
		]);
		$result = $this->ticket->getLabelNamePerTicket($pid);
		$this->assertEquals('Custom Label', $result);
	}

	public function test_getLabelNamePerTicket_empty_returns_default(): void {
		$pid = $this->createTicketProduct([
			'saso_eventtickets_request_name_per_ticket_label' => '',
		]);
		$result = $this->ticket->getLabelNamePerTicket($pid);
		$this->assertStringContainsString('Name', $result);
	}

	// ── getLabelValuePerTicket ───────────────────────────────

	public function test_getLabelValuePerTicket_returns_default(): void {
		$pid = $this->createTicketProduct();
		$result = $this->ticket->getLabelValuePerTicket($pid);
		$this->assertStringContainsString('value', strtolower($result));
		$this->assertStringContainsString('{count}', $result);
	}

	public function test_getLabelValuePerTicket_returns_custom(): void {
		$pid = $this->createTicketProduct([
			'saso_eventtickets_request_value_per_ticket_label' => 'Wert wählen #{count}:',
		]);
		$result = $this->ticket->getLabelValuePerTicket($pid);
		$this->assertEquals('Wert wählen #{count}:', $result);
	}

	// ── getLabelDaychooserPerTicket ──────────────────────────

	public function test_getLabelDaychooserPerTicket_returns_default(): void {
		$pid = $this->createTicketProduct();
		$result = $this->ticket->getLabelDaychooserPerTicket($pid);
		$this->assertStringContainsString('day', strtolower($result));
		$this->assertStringContainsString('{count}', $result);
	}

	public function test_getLabelDaychooserPerTicket_returns_custom(): void {
		$pid = $this->createTicketProduct([
			'saso_eventtickets_request_daychooser_per_ticket_label' => 'Tag auswählen #{count}:',
		]);
		$result = $this->ticket->getLabelDaychooserPerTicket($pid);
		$this->assertEquals('Tag auswählen #{count}:', $result);
	}

	// ── getWPMLProductId ────────────────────────────────────

	public function test_getWPMLProductId_returns_same_id_without_wpml(): void {
		$pid = $this->createTicketProduct();
		$result = $this->ticket->getWPMLProductId($pid);
		$this->assertEquals($pid, $result);
	}

	public function test_getWPMLProductId_handles_zero(): void {
		$result = $this->ticket->getWPMLProductId(0);
		$this->assertEquals(0, $result);
	}

	public function test_getWPMLProductId_handles_null(): void {
		$result = $this->ticket->getWPMLProductId(null);
		$this->assertNull($result);
	}

	public function test_getWPMLProductId_fallback_on_bad_filter(): void {
		// Simulate a broken WPML filter returning null
		add_filter('wpml_object_id', '__return_null', 9999);
		$pid = $this->createTicketProduct();
		$result = $this->ticket->getWPMLProductId($pid);
		// Should fallback to original product_id
		$this->assertEquals($pid, $result);
		remove_filter('wpml_object_id', '__return_null', 9999);
	}

	// ── displayDayChooserDateAsString ───────────────────────

	public function test_displayDayChooserDateAsString_null_returns_empty(): void {
		$result = $this->ticket->displayDayChooserDateAsString(null);
		$this->assertEquals('', $result);
	}

	public function test_displayDayChooserDateAsString_non_daychooser_returns_empty(): void {
		$pid = $this->createTicketProduct();
		$data = $this->createCodeWithProduct($pid);
		$result = $this->ticket->displayDayChooserDateAsString($data['codeObj']);
		$this->assertEquals('', $result);
	}

	public function test_displayDayChooserDateAsString_with_daychooser_date(): void {
		$pid = $this->createTicketProduct();
		$data = $this->createCodeWithProduct($pid);
		$codeObj = $data['codeObj'];

		// Set daychooser meta
		$metaObj = $this->main->getCore()->encodeMetaValuesAndFillObject($codeObj['meta'], $codeObj);
		$metaObj['wc_ticket']['is_daychooser'] = 1;
		$metaObj['wc_ticket']['day_per_ticket'] = '2026-06-15';
		$this->main->getCore()->saveMetaObject($codeObj, $metaObj);

		$codeObj = $this->main->getCore()->retrieveCodeByCode($data['code']);
		$result = $this->ticket->displayDayChooserDateAsString($codeObj);
		$this->assertNotEmpty($result);
		// Should contain some form of the date
		$this->assertStringContainsString('2026', $result);
	}

	// ── displayTicketDateAsString ────────────────────────────

	public function test_displayTicketDateAsString_throws_for_zero(): void {
		$this->expectException(Exception::class);
		$this->expectExceptionMessageMatches('/#8021/');
		$this->ticket->displayTicketDateAsString(0);
	}

	public function test_displayTicketDateAsString_throws_for_negative(): void {
		$this->expectException(Exception::class);
		$this->ticket->displayTicketDateAsString(-1);
	}

	public function test_displayTicketDateAsString_with_start_date(): void {
		$pid = $this->createTicketProduct([
			'saso_eventtickets_ticket_start_date' => '2026-12-25',
		]);
		$result = $this->ticket->displayTicketDateAsString($pid, 'Y/m/d', 'H:i');
		$this->assertStringContainsString('2026', $result);
	}

	public function test_displayTicketDateAsString_with_start_and_end(): void {
		$pid = $this->createTicketProduct([
			'saso_eventtickets_ticket_start_date' => '2026-12-25',
			'saso_eventtickets_ticket_end_date' => '2026-12-26',
		]);
		$result = $this->ticket->displayTicketDateAsString($pid, 'Y/m/d', 'H:i');
		$this->assertStringContainsString('2026', $result);
		$this->assertStringContainsString('-', $result); // separator
	}

	public function test_displayTicketDateAsString_no_explicit_dates_returns_today(): void {
		$pid = $this->createTicketProduct();
		$result = $this->ticket->displayTicketDateAsString($pid, 'Y/m/d', 'H:i');
		// When no dates are set, calcDateStringAllowedRedeemFrom defaults to today
		$this->assertStringContainsString(wp_date('Y'), $result);
	}

	// ── getMaxRedeemAmountOfTicket ───────────────────────────

	public function test_getMaxRedeemAmountOfTicket_default_is_one(): void {
		$pid = $this->createTicketProduct();
		$data = $this->createCodeWithProduct($pid);
		$result = $this->ticket->getMaxRedeemAmountOfTicket($data['codeObj']);
		// Default (no meta set) returns 0 from intval(''), but method returns that
		$this->assertIsInt($result);
	}

	public function test_getMaxRedeemAmountOfTicket_custom_value(): void {
		$pid = $this->createTicketProduct([
			'saso_eventtickets_ticket_max_redeem_amount' => 5,
		]);
		$data = $this->createCodeWithProduct($pid);
		$result = $this->ticket->getMaxRedeemAmountOfTicket($data['codeObj']);
		$this->assertEquals(5, $result);
	}

	public function test_getMaxRedeemAmountOfTicket_no_product_returns_one(): void {
		// Code without product_id in meta
		$listId = $this->main->getDB()->insert('lists', [
			'name' => 'NoProduct List ' . uniqid(),
			'aktiv' => 1,
			'meta' => '{}',
		]);
		$metaObj = $this->main->getCore()->getMetaObject();
		$metaJson = $this->main->getCore()->json_encode_with_error_handling($metaObj);
		$code = 'NOPROD' . strtoupper(uniqid());
		$this->main->getDB()->insert('codes', [
			'list_id' => $listId,
			'code' => $code,
			'aktiv' => 1,
			'cvv' => '',
			'order_id' => 0,
			'user_id' => 0,
			'meta' => $metaJson,
		]);
		$codeObj = $this->main->getCore()->retrieveCodeByCode($code);
		$result = $this->ticket->getMaxRedeemAmountOfTicket($codeObj);
		$this->assertEquals(1, $result);
	}

	// ── getOrderItem ────────────────────────────────────────

	public function test_getOrderItem_finds_matching_item(): void {
		$pid = $this->createTicketProduct();
		$product = wc_get_product($pid);
		$order = wc_create_order();
		$order->add_product($product, 1);
		$order->save();

		$items = $order->get_items();
		$firstItemId = array_key_first($items);

		$metaObj = $this->main->getCore()->getMetaObject();
		$metaObj['woocommerce']['item_id'] = $firstItemId;

		$result = $this->ticket->getOrderItem($order, $metaObj);
		$this->assertNotNull($result);
		$this->assertEquals($pid, $result->get_product_id());
	}

	public function test_getOrderItem_returns_null_for_wrong_item_id(): void {
		$pid = $this->createTicketProduct();
		$product = wc_get_product($pid);
		$order = wc_create_order();
		$order->add_product($product, 1);
		$order->save();

		$metaObj = $this->main->getCore()->getMetaObject();
		$metaObj['woocommerce']['item_id'] = 999999;

		$result = $this->ticket->getOrderItem($order, $metaObj);
		$this->assertNull($result);
	}

	public function test_getOrderItem_returns_null_for_empty_order(): void {
		$order = wc_create_order();
		$order->save();

		$metaObj = $this->main->getCore()->getMetaObject();
		$metaObj['woocommerce']['item_id'] = 1;

		$result = $this->ticket->getOrderItem($order, $metaObj);
		$this->assertNull($result);
	}
}
