<?php
/**
 * Tests for the REST API endpoints used by the ticket scanner:
 * - rest_retrieve_ticket: Load ticket info by code
 * - rest_redeem_ticket: Redeem a ticket by code
 * - rest_permission_callback: Authentication checks
 */

class RestApiTicketTest extends WP_UnitTestCase {

	private $main;

	public function set_up(): void {
		parent::set_up();
		$this->main = sasoEventtickets::Instance();

		if (!class_exists('WC_Product_Simple')) {
			$this->markTestSkipped('WooCommerce not available');
		}

		// Clean state between tests
		$this->resetTicketHandler();
		unset($_GET['code'], $_GET['redeem'], $_REQUEST['code'], $_REQUEST['redeem']);
	}

	public function tear_down(): void {
		unset($_GET['code'], $_GET['redeem'], $_REQUEST['code'], $_REQUEST['redeem']);
		$this->resetTicketHandler();
		parent::tear_down();
	}

	// ── Helpers ─────────────────────────────────────────────────

	private function createTicketProduct(array $extraMeta = []): array {
		$listId = $this->main->getDB()->insert('lists', [
			'name' => 'REST Test List ' . uniqid(),
			'aktiv' => 1,
			'meta' => '{}',
		]);

		$product = new WC_Product_Simple();
		$product->set_name('REST Ticket ' . uniqid());
		$product->set_regular_price('20.00');
		$product->set_status('publish');
		$product->save();
		$pid = $product->get_id();

		update_post_meta($pid, 'saso_eventtickets_is_ticket', 'yes');
		update_post_meta($pid, 'saso_eventtickets_list', $listId);

		foreach ($extraMeta as $key => $value) {
			update_post_meta($pid, $key, $value);
		}

		return ['product' => $product, 'product_id' => $pid, 'list_id' => $listId];
	}

	private function createOrderWithCodes(WC_Product $product, int $quantity = 1): WC_Order {
		$order = wc_create_order();
		$order->add_product($product, $quantity);
		$order->set_billing_first_name('Scanner');
		$order->set_billing_last_name('Tester');
		$order->set_billing_email('scanner@example.com');
		$order->calculate_totals();
		$order->set_status('completed');
		$order->save();

		$this->main->getWC()->getOrderManager()->add_serialcode_to_order($order->get_id());

		return wc_get_order($order->get_id());
	}

	/**
	 * Build the ticket ID in scanner format: idcode-order_id-code
	 */
	private function getFirstTicketIdFromOrder(WC_Order $order): string {
		$codes = $this->main->getCore()->getCodesByOrderId($order->get_id());
		$this->assertNotEmpty($codes, 'Order should have at least one code');
		$codeObj = $codes[0];
		$metaObj = $this->main->getCore()->encodeMetaValuesAndFillObject($codeObj['meta'], $codeObj);
		$ticketId = $this->main->getCore()->getTicketId($codeObj, $metaObj);
		$this->assertNotEmpty($ticketId, 'Ticket ID should not be empty');
		return $ticketId;
	}

	/**
	 * Get all ticket IDs from an order
	 */
	private function getAllTicketIdsFromOrder(WC_Order $order): array {
		$codes = $this->main->getCore()->getCodesByOrderId($order->get_id());
		$ticketIds = [];
		foreach ($codes as $codeObj) {
			$metaObj = $this->main->getCore()->encodeMetaValuesAndFillObject($codeObj['meta'], $codeObj);
			$ticketIds[] = $this->main->getCore()->getTicketId($codeObj, $metaObj);
		}
		return $ticketIds;
	}

	/**
	 * Get the raw DB code string for direct DB lookups
	 */
	private function getFirstRawCodeFromOrder(WC_Order $order): string {
		$codes = $this->main->getCore()->getCodesByOrderId($order->get_id());
		$this->assertNotEmpty($codes, 'Order should have at least one code');
		return $codes[0]['code'];
	}

	/**
	 * Reset ticket handler state between REST calls in the same test.
	 * Must also reset the static REQUEST_DATA cache in SASO_EVENTTICKETS.
	 */
	private function resetTicketHandler(): void {
		$ticket = $this->main->getTicketHandler();
		$ticket->setCodeObj(null);
		// Reset private $parts cache
		$ref = new ReflectionProperty($ticket, 'parts');
		$ref->setAccessible(true);
		$ref->setValue($ticket, null);
		// Reset static REQUEST_DATA cache so getRequestPara() reads fresh $_GET/$_POST
		$ref2 = new ReflectionProperty('SASO_EVENTTICKETS', 'REQUEST_DATA');
		$ref2->setAccessible(true);
		$ref2->setValue(null, null);
	}

	// ── rest_retrieve_ticket ────────────────────────────────────

	public function test_retrieve_ticket_returns_ticket_data(): void {
		$tp = $this->createTicketProduct([
			'saso_eventtickets_ticket_start_date' => '2030-06-15',
			'saso_eventtickets_ticket_start_time' => '10:00:00',
			'saso_eventtickets_ticket_end_date' => '2030-06-15',
			'saso_eventtickets_ticket_end_time' => '22:00:00',
		]);
		$order = $this->createOrderWithCodes($tp['product']);
		$ticketId = $this->getFirstTicketIdFromOrder($order);

		$_GET['code'] = $ticketId;
		$_REQUEST['code'] = $ticketId;

		$ticket = $this->main->getTicketHandler();
		$result = $ticket->rest_retrieve_ticket(new WP_REST_Request());

		$this->assertIsArray($result);
		$this->assertArrayHasKey('_ret', $result);
		$ret = $result['_ret'];
		$this->assertArrayHasKey('ticket_title', $ret);
		$this->assertArrayHasKey('ticket_start_date', $ret);
		$this->assertArrayHasKey('is_paid', $ret);
		$this->assertArrayHasKey('order_status', $ret);
	}

	public function test_retrieve_ticket_contains_product_info(): void {
		$tp = $this->createTicketProduct([
			'saso_eventtickets_ticket_start_date' => '2030-06-15',
		]);
		$order = $this->createOrderWithCodes($tp['product']);
		$ticketId = $this->getFirstTicketIdFromOrder($order);

		$_GET['code'] = $ticketId;
		$_REQUEST['code'] = $ticketId;

		$ticket = $this->main->getTicketHandler();
		$result = $ticket->rest_retrieve_ticket(new WP_REST_Request());
		$ret = $result['_ret'];

		$this->assertArrayHasKey('product', $ret);
		$this->assertEquals($tp['product_id'], $ret['product']['id']);
		$this->assertNotEmpty($ret['product']['name']);
	}

	public function test_retrieve_ticket_contains_date_info(): void {
		$tp = $this->createTicketProduct([
			'saso_eventtickets_ticket_start_date' => '2030-06-15',
			'saso_eventtickets_ticket_start_time' => '19:00:00',
			'saso_eventtickets_ticket_end_date' => '2030-06-15',
			'saso_eventtickets_ticket_end_time' => '23:00:00',
		]);
		$order = $this->createOrderWithCodes($tp['product']);
		$ticketId = $this->getFirstTicketIdFromOrder($order);

		$_GET['code'] = $ticketId;
		$_REQUEST['code'] = $ticketId;

		$ticket = $this->main->getTicketHandler();
		$result = $ticket->rest_retrieve_ticket(new WP_REST_Request());
		$ret = $result['_ret'];

		$this->assertEquals('2030-06-15', $ret['ticket_start_date']);
		$this->assertEquals('19:00:00', $ret['ticket_start_time']);
		$this->assertEquals('2030-06-15', $ret['ticket_end_date']);
		$this->assertEquals('23:00:00', $ret['ticket_end_time']);
	}

	public function test_retrieve_ticket_contains_options(): void {
		$tp = $this->createTicketProduct([
			'saso_eventtickets_ticket_start_date' => '2030-06-15',
		]);
		$order = $this->createOrderWithCodes($tp['product']);
		$ticketId = $this->getFirstTicketIdFromOrder($order);

		$_GET['code'] = $ticketId;
		$_REQUEST['code'] = $ticketId;

		$ticket = $this->main->getTicketHandler();
		$result = $ticket->rest_retrieve_ticket(new WP_REST_Request());
		$ret = $result['_ret'];

		$this->assertArrayHasKey('_options', $ret);
		$this->assertArrayHasKey('isRedeemOperationTooEarly', $ret['_options']);
		$this->assertArrayHasKey('isRedeemOperationTooLate', $ret['_options']);
		$this->assertArrayHasKey('isRedeemOperationTooLateEventEnded', $ret['_options']);
	}

	public function test_retrieve_ticket_shows_paid_status(): void {
		$tp = $this->createTicketProduct([
			'saso_eventtickets_ticket_start_date' => '2030-06-15',
		]);
		$order = $this->createOrderWithCodes($tp['product']);
		$ticketId = $this->getFirstTicketIdFromOrder($order);

		$_GET['code'] = $ticketId;
		$_REQUEST['code'] = $ticketId;

		$ticket = $this->main->getTicketHandler();
		$result = $ticket->rest_retrieve_ticket(new WP_REST_Request());
		$ret = $result['_ret'];

		$this->assertTrue($ret['is_paid']);
		$this->assertEquals('completed', $ret['order_status']);
	}

	public function test_retrieve_ticket_not_redeemed_initially(): void {
		$tp = $this->createTicketProduct([
			'saso_eventtickets_ticket_start_date' => '2030-06-15',
		]);
		$order = $this->createOrderWithCodes($tp['product']);
		$ticketId = $this->getFirstTicketIdFromOrder($order);

		$_GET['code'] = $ticketId;
		$_REQUEST['code'] = $ticketId;

		$ticket = $this->main->getTicketHandler();
		$result = $ticket->rest_retrieve_ticket(new WP_REST_Request());

		$this->assertEquals(0, intval($result['redeemed']));
	}

	public function test_retrieve_ticket_contains_server_times(): void {
		$tp = $this->createTicketProduct([
			'saso_eventtickets_ticket_start_date' => '2030-06-15',
		]);
		$order = $this->createOrderWithCodes($tp['product']);
		$ticketId = $this->getFirstTicketIdFromOrder($order);

		$_GET['code'] = $ticketId;
		$_REQUEST['code'] = $ticketId;

		$ticket = $this->main->getTicketHandler();
		$result = $ticket->rest_retrieve_ticket(new WP_REST_Request());
		$ret = $result['_ret'];

		$this->assertArrayHasKey('_server', $ret);
		$this->assertArrayHasKey('time', $ret['_server']);
		$this->assertArrayHasKey('timestamp', $ret['_server']);
	}

	public function test_retrieve_ticket_with_billing_info(): void {
		$tp = $this->createTicketProduct([
			'saso_eventtickets_ticket_start_date' => '2030-06-15',
		]);
		$order = $this->createOrderWithCodes($tp['product']);
		$ticketId = $this->getFirstTicketIdFromOrder($order);

		$_GET['code'] = $ticketId;
		$_REQUEST['code'] = $ticketId;

		$ticket = $this->main->getTicketHandler();
		$result = $ticket->rest_retrieve_ticket(new WP_REST_Request());
		$ret = $result['_ret'];

		// Customer info should be available (option not disabled by default)
		$this->assertArrayHasKey('cst_billing_address', $ret);
	}

	public function test_retrieve_ticket_price_info(): void {
		$tp = $this->createTicketProduct([
			'saso_eventtickets_ticket_start_date' => '2030-06-15',
		]);
		$order = $this->createOrderWithCodes($tp['product']);
		$ticketId = $this->getFirstTicketIdFromOrder($order);

		$_GET['code'] = $ticketId;
		$_REQUEST['code'] = $ticketId;

		$ticket = $this->main->getTicketHandler();
		$result = $ticket->rest_retrieve_ticket(new WP_REST_Request());
		$ret = $result['_ret'];

		$this->assertArrayHasKey('paid_price', $ret);
		$this->assertEquals(20.00, $ret['paid_price']);
	}

	public function test_retrieve_ticket_multiple_tickets_per_order(): void {
		$tp = $this->createTicketProduct([
			'saso_eventtickets_ticket_start_date' => '2030-06-15',
		]);
		$order = $this->createOrderWithCodes($tp['product'], 3);
		$ticketIds = $this->getAllTicketIdsFromOrder($order);
		$this->assertCount(3, $ticketIds);

		// Retrieve first ticket
		$_GET['code'] = $ticketIds[0];
		$_REQUEST['code'] = $ticketIds[0];

		$ticket = $this->main->getTicketHandler();
		$result1 = $ticket->rest_retrieve_ticket(new WP_REST_Request());

		// Reset handler state before second call
		$this->resetTicketHandler();

		// Retrieve second ticket
		$_GET['code'] = $ticketIds[1];
		$_REQUEST['code'] = $ticketIds[1];

		$result2 = $ticket->rest_retrieve_ticket(new WP_REST_Request());

		// Both should return valid data but for different codes
		$this->assertIsArray($result1);
		$this->assertIsArray($result2);
		$this->assertNotEquals($result1['code'], $result2['code']);
	}

	public function test_retrieve_ticket_expired_event(): void {
		$tp = $this->createTicketProduct([
			'saso_eventtickets_ticket_start_date' => '2020-01-01',
			'saso_eventtickets_ticket_start_time' => '10:00:00',
			'saso_eventtickets_ticket_end_date' => '2020-01-01',
			'saso_eventtickets_ticket_end_time' => '22:00:00',
		]);
		$order = $this->createOrderWithCodes($tp['product']);
		$ticketId = $this->getFirstTicketIdFromOrder($order);

		$_GET['code'] = $ticketId;
		$_REQUEST['code'] = $ticketId;

		$ticket = $this->main->getTicketHandler();
		$result = $ticket->rest_retrieve_ticket(new WP_REST_Request());
		$ret = $result['_ret'];

		// Past event should show too late flags
		$this->assertTrue($ret['redeem_allowed_too_late']);
	}

	// ── rest_redeem_ticket ──────────────────────────────────────

	public function test_redeem_ticket_successfully(): void {
		$tp = $this->createTicketProduct([
			'saso_eventtickets_ticket_start_date' => '2030-06-15',
		]);
		$order = $this->createOrderWithCodes($tp['product']);
		$ticketId = $this->getFirstTicketIdFromOrder($order);

		$_GET['code'] = $ticketId;
		$_REQUEST['code'] = $ticketId;

		$request = new WP_REST_Request();
		$request->set_param('code', $ticketId);

		$ticket = $this->main->getTicketHandler();
		$result = $ticket->rest_redeem_ticket($request);

		$this->assertIsArray($result);
		$this->assertTrue($result['redeem_successfully']);
		$this->assertArrayHasKey('ticket_id', $result);
	}

	public function test_redeem_ticket_marks_as_redeemed(): void {
		$tp = $this->createTicketProduct([
			'saso_eventtickets_ticket_start_date' => '2030-06-15',
		]);
		$order = $this->createOrderWithCodes($tp['product']);
		$ticketId = $this->getFirstTicketIdFromOrder($order);
		$rawCode = $this->getFirstRawCodeFromOrder($order);

		$_GET['code'] = $ticketId;
		$_REQUEST['code'] = $ticketId;

		$request = new WP_REST_Request();
		$request->set_param('code', $ticketId);

		$ticket = $this->main->getTicketHandler();
		$ticket->rest_redeem_ticket($request);

		// Verify redeemed in DB using raw code
		$codeObj = $this->main->getCore()->retrieveCodeByCode($rawCode);
		$this->assertEquals(1, intval($codeObj['redeemed']));
	}

	public function test_redeem_ticket_double_redeem_fails(): void {
		$tp = $this->createTicketProduct([
			'saso_eventtickets_ticket_start_date' => '2030-06-15',
		]);
		$order = $this->createOrderWithCodes($tp['product']);
		$ticketId = $this->getFirstTicketIdFromOrder($order);

		$_GET['code'] = $ticketId;
		$_REQUEST['code'] = $ticketId;

		$request = new WP_REST_Request();
		$request->set_param('code', $ticketId);

		$ticket = $this->main->getTicketHandler();

		// First redeem: success
		$result1 = $ticket->rest_redeem_ticket($request);
		$this->assertTrue($result1['redeem_successfully']);

		// Reset handler state before second call
		$this->resetTicketHandler();

		// Second redeem: should NOT succeed (already redeemed, max_redeem_amount=0)
		$result2 = $ticket->rest_redeem_ticket($request);
		$this->assertFalse($result2['redeem_successfully']);
	}

	public function test_redeem_ticket_updates_redeemed_count(): void {
		$tp = $this->createTicketProduct([
			'saso_eventtickets_ticket_start_date' => '2030-06-15',
			'saso_eventtickets_ticket_max_redeem_amount' => 3,
		]);
		$order = $this->createOrderWithCodes($tp['product']);
		$ticketId = $this->getFirstTicketIdFromOrder($order);

		$_GET['code'] = $ticketId;
		$_REQUEST['code'] = $ticketId;

		$request = new WP_REST_Request();
		$request->set_param('code', $ticketId);

		$ticket = $this->main->getTicketHandler();

		// Redeem once
		$result = $ticket->rest_redeem_ticket($request);
		$this->assertTrue($result['redeem_successfully']);

		// Reset handler state, then check redeemed count via retrieve
		$this->resetTicketHandler();
		$retrieveResult = $ticket->rest_retrieve_ticket(new WP_REST_Request());
		$this->assertEquals(1, intval($retrieveResult['redeemed']));
	}

	public function test_redeem_returns_helper_data(): void {
		$tp = $this->createTicketProduct([
			'saso_eventtickets_ticket_start_date' => '2030-06-15',
		]);
		$order = $this->createOrderWithCodes($tp['product']);
		$ticketId = $this->getFirstTicketIdFromOrder($order);

		$_GET['code'] = $ticketId;
		$_REQUEST['code'] = $ticketId;

		$request = new WP_REST_Request();
		$request->set_param('code', $ticketId);

		$ticket = $this->main->getTicketHandler();
		$result = $ticket->rest_redeem_ticket($request);

		$this->assertArrayHasKey('_ret', $result);
		$this->assertArrayHasKey('tickets_redeemed', $result['_ret']);
	}

	// ── rest_retrieve_ticket with immediate redeem ──────────────

	public function test_retrieve_with_immediate_redeem(): void {
		$tp = $this->createTicketProduct([
			'saso_eventtickets_ticket_start_date' => '2030-06-15',
		]);
		$order = $this->createOrderWithCodes($tp['product']);
		$ticketId = $this->getFirstTicketIdFromOrder($order);

		$_GET['code'] = $ticketId;
		$_GET['redeem'] = '1';
		$_REQUEST['code'] = $ticketId;
		$_REQUEST['redeem'] = '1';

		$ticket = $this->main->getTicketHandler();
		$result = $ticket->rest_retrieve_ticket(new WP_REST_Request());
		$ret = $result['_ret'];

		// Should contain redeem operation result
		$this->assertArrayHasKey('redeem_operation', $ret);
		$this->assertTrue($ret['redeem_operation']['redeem_successfully']);

		// Ticket should be redeemed now
		$this->assertEquals(1, intval($result['redeemed']));

		unset($_GET['redeem'], $_REQUEST['redeem']);
	}

	// ── rest_permission_callback ────────────────────────────────

	public function test_permission_callback_admin_has_access(): void {
		$admin = self::factory()->user->create(['role' => 'administrator']);
		wp_set_current_user($admin);

		$ticket = $this->main->getTicketHandler();
		$request = new WP_REST_Request();

		$this->assertTrue($ticket->rest_permission_callback($request));
	}

	public function test_permission_callback_anonymous_allowed_when_no_role_set(): void {
		// Default: wcTicketScannerAllowedRoles="-" and wcTicketOnlyLoggedInScannerAllowed=false
		// → scanner is open to everyone
		wp_set_current_user(0);

		$ticket = $this->main->getTicketHandler();
		$request = new WP_REST_Request();

		$this->assertTrue($ticket->rest_permission_callback($request));
	}

	public function test_permission_callback_anonymous_denied_when_login_required(): void {
		$ticket = $this->main->getTicketHandler();

		// Force the "only logged in" restriction via cached property
		$ref = new ReflectionProperty($ticket, 'onlyLoggedInScannerAllowed');
		$ref->setAccessible(true);
		$ref->setValue($ticket, true);

		wp_set_current_user(0);

		$request = new WP_REST_Request();
		$this->assertFalse($ticket->rest_permission_callback($request));

		// Cleanup
		$ref->setValue($ticket, null);
	}

	// ── getTimes helper ─────────────────────────────────────────

	public function test_getTimes_returns_server_info(): void {
		$ticket = $this->main->getTicketHandler();
		$times = $ticket->getTimes();

		$this->assertArrayHasKey('time', $times);
		$this->assertArrayHasKey('timestamp', $times);
		$this->assertArrayHasKey('UTC_time', $times);
		$this->assertArrayHasKey('timezone', $times);
		$this->assertIsInt($times['timestamp']);
	}
}
