<?php
/**
 * Tests for "Max redeems per day" feature (Issue #227).
 * - getMaxRedeemPerDayOfTicket: reads per-day limit from product meta
 * - redeemWoocommerceTicketForCode: daily limit check in redeem chain
 */

class MaxRedeemPerDayTest extends WP_UnitTestCase {

	private $main;
	private sasoEventtickets_Ticket $ticket;

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
		$product->set_name('PerDay Test ' . uniqid());
		$product->set_regular_price('10.00');
		$product->set_status('publish');
		$product->save();
		$pid = $product->get_id();

		$listId = $this->main->getDB()->insert('lists', [
			'name' => 'PerDay List ' . uniqid(),
			'aktiv' => 1,
			'meta' => '{}',
		]);
		update_post_meta($pid, 'saso_eventtickets_is_ticket', 'yes');
		update_post_meta($pid, 'saso_eventtickets_list', $listId);

		foreach ($meta as $key => $value) {
			update_post_meta($pid, $key, $value);
		}

		return $pid;
	}

	private function createCodeWithProduct(int $productId, array $statsRedeemed = []): array {
		$listId = intval(get_post_meta($productId, 'saso_eventtickets_list', true));

		$metaObj = $this->main->getCore()->getMetaObject();
		$metaObj['woocommerce']['product_id'] = $productId;
		$metaObj['wc_ticket']['stats_redeemed'] = $statsRedeemed;
		if (!empty($statsRedeemed)) {
			$metaObj['wc_ticket']['redeemed_date'] = end($statsRedeemed)['redeemed_date'];
		}
		$metaJson = $this->main->getCore()->json_encode_with_error_handling($metaObj);

		$code = 'PERDAY' . strtoupper(uniqid());
		$redeemed = !empty($statsRedeemed) ? 1 : 0;
		$this->main->getDB()->insert('codes', [
			'list_id' => $listId,
			'code' => $code,
			'code_display' => $code,
			'aktiv' => 1,
			'redeemed' => $redeemed,
			'cvv' => '',
			'order_id' => 0,
			'user_id' => 0,
			'meta' => $metaJson,
		]);

		$codeObj = $this->main->getCore()->retrieveCodeByCode($code);
		return ['codeObj' => $codeObj, 'code' => $code];
	}

	private function createFullOrderTicket(int $productId): array {
		$product = wc_get_product($productId);
		$order = wc_create_order();
		$order->add_product($product, 1);
		$order->calculate_totals();
		$order->set_status('completed');
		$order->save();

		$this->main->getWC()->getOrderManager()->add_serialcode_to_order($order->get_id());
		$codes = $this->main->getCore()->getCodesByOrderId($order->get_id());

		return [
			'order' => $order,
			'codeObj' => $codes[0],
			'code' => $codes[0]['code'],
		];
	}

	// ── getMaxRedeemPerDayOfTicket ─────────────────────────────

	public function test_getMaxRedeemPerDayOfTicket_returns_0_when_not_set(): void {
		$pid = $this->createTicketProduct();
		$data = $this->createCodeWithProduct($pid);
		$result = $this->ticket->getMaxRedeemPerDayOfTicket($data['codeObj']);
		$this->assertSame(0, $result);
	}

	public function test_getMaxRedeemPerDayOfTicket_returns_value_from_product_meta(): void {
		$pid = $this->createTicketProduct([
			'saso_eventtickets_ticket_max_redeem_per_day' => 3,
		]);
		$data = $this->createCodeWithProduct($pid);
		$result = $this->ticket->getMaxRedeemPerDayOfTicket($data['codeObj']);
		$this->assertSame(3, $result);
	}

	public function test_getMaxRedeemPerDayOfTicket_uses_parent_for_variation(): void {
		$parent = new WC_Product_Variable();
		$parent->set_name('Variable PerDay ' . uniqid());
		$parent->set_status('publish');
		$parent->save();

		$listId = $this->main->getDB()->insert('lists', [
			'name' => 'Var PerDay List ' . uniqid(),
			'aktiv' => 1,
			'meta' => '{}',
		]);
		update_post_meta($parent->get_id(), 'saso_eventtickets_is_ticket', 'yes');
		update_post_meta($parent->get_id(), 'saso_eventtickets_list', $listId);
		update_post_meta($parent->get_id(), 'saso_eventtickets_ticket_max_redeem_per_day', 5);

		$variation = new WC_Product_Variation();
		$variation->set_parent_id($parent->get_id());
		$variation->set_regular_price('15.00');
		$variation->set_status('publish');
		$variation->save();

		$metaObj = $this->main->getCore()->getMetaObject();
		$metaObj['woocommerce']['product_id'] = $variation->get_id();
		$metaJson = $this->main->getCore()->json_encode_with_error_handling($metaObj);

		$code = 'VARDPD' . strtoupper(uniqid());
		$this->main->getDB()->insert('codes', [
			'list_id' => $listId,
			'code' => $code,
			'code_display' => $code,
			'aktiv' => 1,
			'redeemed' => 0,
			'cvv' => '',
			'order_id' => 0,
			'user_id' => 0,
			'meta' => $metaJson,
		]);

		$codeObj = $this->main->getCore()->retrieveCodeByCode($code);
		$result = $this->ticket->getMaxRedeemPerDayOfTicket($codeObj);
		$this->assertSame(5, $result);
	}

	// ── Redeem logic with per-day limit ─────────────────────────

	public function test_redeem_succeeds_when_per_day_is_0(): void {
		$pid = $this->createTicketProduct([
			'saso_eventtickets_ticket_max_redeem_amount' => 5,
			// max_redeem_per_day NOT set → 0 → no daily limit
		]);
		$data = $this->createFullOrderTicket($pid);

		$result = $this->main->getAdmin()->executeJSON(
			'redeemWoocommerceTicketForCode',
			['code' => $data['code']],
			true, true
		);
		$this->assertIsArray($result);
	}

	public function test_redeem_succeeds_under_daily_limit(): void {
		$pid = $this->createTicketProduct([
			'saso_eventtickets_ticket_max_redeem_amount' => 5,
			'saso_eventtickets_ticket_max_redeem_per_day' => 2,
		]);
		$today = wp_date('Y-m-d H:i:s');
		$data = $this->createFullOrderTicket($pid);

		// Manually add 1 redeem for today
		$codeObj = $this->main->getCore()->retrieveCodeByCode($data['code']);
		$metaObj = $this->main->getCore()->encodeMetaValuesAndFillObject($codeObj['meta'], $codeObj);
		$metaObj['wc_ticket']['redeemed_date'] = $today;
		$metaObj['wc_ticket']['stats_redeemed'] = [
			['redeemed_date' => $today, 'ip' => '127.0.0.1'],
		];
		$metaJson = $this->main->getCore()->json_encode_with_error_handling($metaObj);
		$this->main->getDB()->update('codes', ['meta' => $metaJson, 'redeemed' => 1], ['id' => $codeObj['id']]);

		// Second redeem today — limit=2, used=1, should succeed
		$result = $this->main->getAdmin()->executeJSON(
			'redeemWoocommerceTicketForCode',
			['code' => $data['code']],
			true, true
		);
		$this->assertIsArray($result);
	}

	public function test_redeem_blocked_when_daily_limit_reached(): void {
		$pid = $this->createTicketProduct([
			'saso_eventtickets_ticket_max_redeem_amount' => 5,
			'saso_eventtickets_ticket_max_redeem_per_day' => 2,
		]);
		$today = wp_date('Y-m-d H:i:s');
		$data = $this->createFullOrderTicket($pid);

		// Add 2 redeems for today
		$codeObj = $this->main->getCore()->retrieveCodeByCode($data['code']);
		$metaObj = $this->main->getCore()->encodeMetaValuesAndFillObject($codeObj['meta'], $codeObj);
		$metaObj['wc_ticket']['redeemed_date'] = $today;
		$metaObj['wc_ticket']['stats_redeemed'] = [
			['redeemed_date' => $today, 'ip' => '127.0.0.1'],
			['redeemed_date' => $today, 'ip' => '127.0.0.2'],
		];
		$metaJson = $this->main->getCore()->json_encode_with_error_handling($metaObj);
		$this->main->getDB()->update('codes', ['meta' => $metaJson, 'redeemed' => 1], ['id' => $codeObj['id']]);

		$this->expectException(Exception::class);
		$this->expectExceptionMessage('Daily redeem limit reached');

		$this->main->getAdmin()->executeJSON(
			'redeemWoocommerceTicketForCode',
			['code' => $data['code']],
			true, true
		);
	}

	public function test_redeem_allowed_next_day(): void {
		$pid = $this->createTicketProduct([
			'saso_eventtickets_ticket_max_redeem_amount' => 5,
			'saso_eventtickets_ticket_max_redeem_per_day' => 1,
		]);
		$yesterday = wp_date('Y-m-d H:i:s', strtotime('-1 day'));
		$data = $this->createFullOrderTicket($pid);

		// Add 1 redeem for yesterday only
		$codeObj = $this->main->getCore()->retrieveCodeByCode($data['code']);
		$metaObj = $this->main->getCore()->encodeMetaValuesAndFillObject($codeObj['meta'], $codeObj);
		$metaObj['wc_ticket']['redeemed_date'] = $yesterday;
		$metaObj['wc_ticket']['stats_redeemed'] = [
			['redeemed_date' => $yesterday, 'ip' => '127.0.0.1'],
		];
		$metaJson = $this->main->getCore()->json_encode_with_error_handling($metaObj);
		$this->main->getDB()->update('codes', ['meta' => $metaJson, 'redeemed' => 1], ['id' => $codeObj['id']]);

		// Today: 0 redeems → should succeed even though per_day=1
		$result = $this->main->getAdmin()->executeJSON(
			'redeemWoocommerceTicketForCode',
			['code' => $data['code']],
			true, true
		);
		$this->assertIsArray($result);
	}

	public function test_total_limit_blocks_before_daily(): void {
		$pid = $this->createTicketProduct([
			'saso_eventtickets_ticket_max_redeem_amount' => 2,
			'saso_eventtickets_ticket_max_redeem_per_day' => 3,
		]);
		$yesterday = wp_date('Y-m-d H:i:s', strtotime('-1 day'));
		$data = $this->createFullOrderTicket($pid);

		// Add 2 redeems (total max=2) from yesterday — total exhausted, daily today=0
		$codeObj = $this->main->getCore()->retrieveCodeByCode($data['code']);
		$metaObj = $this->main->getCore()->encodeMetaValuesAndFillObject($codeObj['meta'], $codeObj);
		$metaObj['wc_ticket']['redeemed_date'] = $yesterday;
		$metaObj['wc_ticket']['stats_redeemed'] = [
			['redeemed_date' => $yesterday, 'ip' => '127.0.0.1'],
			['redeemed_date' => $yesterday, 'ip' => '127.0.0.2'],
		];
		$metaJson = $this->main->getCore()->json_encode_with_error_handling($metaObj);
		$this->main->getDB()->update('codes', ['meta' => $metaJson, 'redeemed' => 1], ['id' => $codeObj['id']]);

		$this->expectException(Exception::class);
		// Total limit message, NOT daily limit message
		$this->expectExceptionMessage('All redeem operations are used up');

		$this->main->getAdmin()->executeJSON(
			'redeemWoocommerceTicketForCode',
			['code' => $data['code']],
			true, true
		);
	}

	public function test_daily_limit_1_blocks_second_redeem_same_day(): void {
		$pid = $this->createTicketProduct([
			'saso_eventtickets_ticket_max_redeem_amount' => 10,
			'saso_eventtickets_ticket_max_redeem_per_day' => 1,
		]);
		$today = wp_date('Y-m-d H:i:s');
		$data = $this->createFullOrderTicket($pid);

		// Add 1 redeem for today
		$codeObj = $this->main->getCore()->retrieveCodeByCode($data['code']);
		$metaObj = $this->main->getCore()->encodeMetaValuesAndFillObject($codeObj['meta'], $codeObj);
		$metaObj['wc_ticket']['redeemed_date'] = $today;
		$metaObj['wc_ticket']['stats_redeemed'] = [
			['redeemed_date' => $today, 'ip' => '127.0.0.1'],
		];
		$metaJson = $this->main->getCore()->json_encode_with_error_handling($metaObj);
		$this->main->getDB()->update('codes', ['meta' => $metaJson, 'redeemed' => 1], ['id' => $codeObj['id']]);

		$this->expectException(Exception::class);
		$this->expectExceptionMessage('Daily redeem limit reached');

		$this->main->getAdmin()->executeJSON(
			'redeemWoocommerceTicketForCode',
			['code' => $data['code']],
			true, true
		);
	}
}
