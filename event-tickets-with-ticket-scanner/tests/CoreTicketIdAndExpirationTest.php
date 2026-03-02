<?php
/**
 * Batch 59 — Core ticket identification and expiration:
 * - checkCodeExpired: premium expiration check
 * - isCodeIsRegistered: user registration check
 * - getTicketId: ticket ID composition
 * - getTicketURL: ticket URL generation
 * - getTicketURLBase: base URL for tickets
 * - getOrderTicketIDCode: order-level ID code
 * - isPremium: premium status check
 */

class CoreTicketIdAndExpirationTest extends WP_UnitTestCase {

	private $main;

	public function set_up(): void {
		parent::set_up();
		$this->main = sasoEventtickets::Instance();
	}

	// ── checkCodeExpired ────────────────────────────────────────

	public function test_checkCodeExpired_returns_false_without_premium(): void {
		$codeObj = $this->createCodeObj();
		$result = $this->main->getCore()->checkCodeExpired($codeObj);
		$this->assertFalse($result);
	}

	public function test_checkCodeExpired_returns_false_for_active_code(): void {
		$codeObj = $this->createCodeObj();
		$codeObj['aktiv'] = 1;
		$result = $this->main->getCore()->checkCodeExpired($codeObj);
		$this->assertFalse($result);
	}

	// ── isCodeIsRegistered ──────────────────────────────────────

	public function test_isCodeIsRegistered_returns_false_for_empty_meta(): void {
		$codeObj = $this->createCodeObj();
		$result = $this->main->getCore()->isCodeIsRegistered($codeObj);
		$this->assertFalse($result);
	}

	public function test_isCodeIsRegistered_returns_false_for_no_user(): void {
		$metaObj = $this->main->getCore()->getMetaObject();
		$metaJson = $this->main->getCore()->json_encode_with_error_handling($metaObj);

		$codeObj = $this->createCodeObj(['meta' => $metaJson]);
		$result = $this->main->getCore()->isCodeIsRegistered($codeObj);
		$this->assertFalse($result);
	}

	public function test_isCodeIsRegistered_returns_true_for_registered_user(): void {
		$metaObj = $this->main->getCore()->getMetaObject();
		$metaObj['user']['value'] = 'John Doe';
		$metaJson = $this->main->getCore()->json_encode_with_error_handling($metaObj);

		$codeObj = $this->createCodeObj(['meta' => $metaJson]);
		$result = $this->main->getCore()->isCodeIsRegistered($codeObj);
		$this->assertTrue($result);
	}

	public function test_isCodeIsRegistered_returns_false_for_empty_user_value(): void {
		$metaObj = $this->main->getCore()->getMetaObject();
		$metaObj['user']['value'] = '';
		$metaJson = $this->main->getCore()->json_encode_with_error_handling($metaObj);

		$codeObj = $this->createCodeObj(['meta' => $metaJson]);
		$result = $this->main->getCore()->isCodeIsRegistered($codeObj);
		$this->assertFalse($result);
	}

	// ── getTicketId ─────────────────────────────────────────────

	public function test_getTicketId_returns_formatted_string(): void {
		$codeObj = ['code' => 'ABC123', 'order_id' => 42];
		$metaObj = $this->main->getCore()->getMetaObject();
		$metaObj['wc_ticket']['idcode'] = 'TESTID';

		$result = $this->main->getCore()->getTicketId($codeObj, $metaObj);
		$this->assertEquals('TESTID-42-ABC123', $result);
	}

	public function test_getTicketId_returns_empty_without_code(): void {
		$codeObj = ['order_id' => 42];
		$metaObj = $this->main->getCore()->getMetaObject();
		$metaObj['wc_ticket']['idcode'] = 'TESTID';

		$result = $this->main->getCore()->getTicketId($codeObj, $metaObj);
		$this->assertEmpty($result);
	}

	public function test_getTicketId_fires_filter(): void {
		$filtered = false;
		add_filter($this->main->_add_filter_prefix . 'core_getTicketId', function ($ret) use (&$filtered) {
			$filtered = true;
			return $ret;
		});

		$codeObj = ['code' => 'XYZ', 'order_id' => 1];
		$metaObj = $this->main->getCore()->getMetaObject();
		$metaObj['wc_ticket']['idcode'] = 'ID';
		$this->main->getCore()->getTicketId($codeObj, $metaObj);
		$this->assertTrue($filtered);
	}

	// ── getTicketURLBase ────────────────────────────────────────

	public function test_getTicketURLBase_returns_string(): void {
		$result = $this->main->getCore()->getTicketURLBase();
		$this->assertIsString($result);
		$this->assertNotEmpty($result);
	}

	public function test_getTicketURLBase_ends_with_slash(): void {
		$result = $this->main->getCore()->getTicketURLBase();
		$this->assertStringEndsWith('/', $result);
	}

	public function test_getTicketURLBase_default_path(): void {
		$result = $this->main->getCore()->getTicketURLBase(true);
		$this->assertIsString($result);
		$this->assertStringContainsString('ticket', $result);
	}

	public function test_getTicketURLBase_fires_filter(): void {
		$filtered = false;
		add_filter($this->main->_add_filter_prefix . 'core_getTicketURLBase', function ($ret) use (&$filtered) {
			$filtered = true;
			return $ret;
		});

		$this->main->getCore()->getTicketURLBase();
		$this->assertTrue($filtered);
	}

	// ── getTicketURL ────────────────────────────────────────────

	public function test_getTicketURL_contains_ticket_id(): void {
		$codeObj = ['code' => 'URLTEST123', 'order_id' => 77];
		$metaObj = $this->main->getCore()->getMetaObject();
		$metaObj['wc_ticket']['idcode'] = 'URLID';

		$result = $this->main->getCore()->getTicketURL($codeObj, $metaObj);
		$this->assertIsString($result);
		$this->assertStringContainsString('URLID-77-URLTEST123', $result);
	}

	public function test_getTicketURL_fires_filter(): void {
		$filtered = false;
		add_filter($this->main->_add_filter_prefix . 'core_getTicketURL', function ($ret) use (&$filtered) {
			$filtered = true;
			return $ret;
		});

		$codeObj = ['code' => 'FLT', 'order_id' => 1];
		$metaObj = $this->main->getCore()->getMetaObject();
		$metaObj['wc_ticket']['idcode'] = 'F';
		$this->main->getCore()->getTicketURL($codeObj, $metaObj);
		$this->assertTrue($filtered);
	}

	// ── getOrderTicketIDCode ────────────────────────────────────

	public function test_getOrderTicketIDCode_returns_string(): void {
		if (!class_exists('WC_Order')) {
			$this->markTestSkipped('WooCommerce not available');
		}

		$order = wc_create_order();
		$order->save();

		$result = $this->main->getCore()->getOrderTicketIDCode($order);
		$this->assertIsString($result);
		$this->assertNotEmpty($result);
	}

	public function test_getOrderTicketIDCode_is_idempotent(): void {
		if (!class_exists('WC_Order')) {
			$this->markTestSkipped('WooCommerce not available');
		}

		$order = wc_create_order();
		$order->save();

		$first = $this->main->getCore()->getOrderTicketIDCode($order);
		$second = $this->main->getCore()->getOrderTicketIDCode($order);
		$this->assertEquals($first, $second);
	}

	public function test_getOrderTicketIDCode_differs_per_order(): void {
		if (!class_exists('WC_Order')) {
			$this->markTestSkipped('WooCommerce not available');
		}

		$order1 = wc_create_order();
		$order1->save();
		$order2 = wc_create_order();
		$order2->save();

		$id1 = $this->main->getCore()->getOrderTicketIDCode($order1);
		$id2 = $this->main->getCore()->getOrderTicketIDCode($order2);
		$this->assertNotEquals($id1, $id2);
	}

	// ── isPremium ───────────────────────────────────────────────

	public function test_isPremium_returns_bool(): void {
		$result = $this->main->isPremium();
		$this->assertIsBool($result);
	}

	// ── helpers ─────────────────────────────────────────────────

	private function createCodeObj(array $overrides = []): array {
		$defaults = [
			'id' => 1,
			'code' => 'TEST' . strtoupper(uniqid()),
			'list_id' => 1,
			'aktiv' => 1,
			'cvv' => '',
			'order_id' => 0,
			'user_id' => 0,
			'meta' => '{}',
		];
		return array_merge($defaults, $overrides);
	}
}
