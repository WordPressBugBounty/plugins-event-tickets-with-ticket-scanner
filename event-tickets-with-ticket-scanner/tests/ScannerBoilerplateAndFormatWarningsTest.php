<?php
/**
 * Batch 58 — Scanner boilerplate, format warnings, error logging, ticket detail shortcode:
 * - getTicketScannerHTMLBoilerplate: scanner HTML container
 * - renderTicketDetailForShortcode: ticket detail via shortcode
 * - logErrorToDB: error logging to database
 * - getFormatWarning / clearFormatWarning: format warning management
 * - plugin_uninstall: static uninstall handler
 */

class ScannerBoilerplateAndFormatWarningsTest extends WP_UnitTestCase {

	private $main;

	public function set_up(): void {
		parent::set_up();
		$this->main = sasoEventtickets::Instance();
	}

	// ── getTicketScannerHTMLBoilerplate ──────────────────────────

	public function test_boilerplate_returns_html_string(): void {
		$result = $this->main->getTicketHandler()->getTicketScannerHTMLBoilerplate();
		$this->assertIsString($result);
		$this->assertNotEmpty($result);
	}

	public function test_boilerplate_contains_scanner_div(): void {
		$result = $this->main->getTicketHandler()->getTicketScannerHTMLBoilerplate();
		$this->assertStringContainsString('ticket_content', $result);
		$this->assertStringContainsString('reader', $result);
	}

	public function test_boilerplate_contains_info_areas(): void {
		$result = $this->main->getTicketHandler()->getTicketScannerHTMLBoilerplate();
		$this->assertStringContainsString('ticket_scanner_info_area', $result);
		$this->assertStringContainsString('ticket_info_retrieved', $result);
		$this->assertStringContainsString('reader_output', $result);
		$this->assertStringContainsString('order_info', $result);
		$this->assertStringContainsString('ticket_info', $result);
	}

	public function test_boilerplate_fires_filter(): void {
		$filtered = false;
		add_filter($this->main->_add_filter_prefix . 'ticket_getTicketScannerHTMLBoilerplate', function ($t) use (&$filtered) {
			$filtered = true;
			return $t;
		});

		$this->main->getTicketHandler()->getTicketScannerHTMLBoilerplate();
		$this->assertTrue($filtered);
	}

	// ── renderTicketDetailForShortcode ───────────────────────────

	public function test_renderTicketDetailForShortcode_returns_html(): void {
		if (!class_exists('WC_Product_Simple')) {
			$this->markTestSkipped('WooCommerce not available');
		}

		// Create a valid ticket setup
		$setup = $this->createFullTicketSetup();
		$codeObj = $setup['codeObj'];
		$metaObj = $this->main->getCore()->encodeMetaValuesAndFillObject($codeObj['meta'], $codeObj);
		$ticketId = $this->main->getCore()->getTicketId($codeObj, $metaObj);

		$ticketPath = $this->main->getCore()->getTicketURLPath(true);
		$fakeUri = $ticketPath . $ticketId;

		// sasoEventtickets_Ticket is already loaded by the plugin
		$ticketInstance = sasoEventtickets_Ticket::Instance($fakeUri);

		$result = $ticketInstance->renderTicketDetailForShortcode();
		$this->assertIsString($result);
		$this->assertStringContainsString('ticket_content', $result);
	}

	// ── logErrorToDB ────────────────────────────────────────────

	public function test_logErrorToDB_stores_exception(): void {
		$admin = $this->main->getAdmin();
		$e = new Exception('Test error #9999');

		$admin->logErrorToDB($e, 'TestClass', 'test context');

		// Verify it was stored in the errorlogs table
		$logs = $this->main->getDB()->_db_datenholen(
			"SELECT * FROM " . $this->main->getDB()->getTabelle('errorlogs') .
			" WHERE exception_msg LIKE '%Test error #9999%' ORDER BY id DESC LIMIT 1"
		);

		$this->assertNotEmpty($logs);
		$this->assertStringContainsString('Test error #9999', $logs[0]['exception_msg']);
	}

	public function test_logErrorToDB_stores_caller_name(): void {
		$admin = $this->main->getAdmin();
		$e = new Exception('CallerTest ' . uniqid());

		$admin->logErrorToDB($e, 'MyCallerFunction');

		$logs = $this->main->getDB()->_db_datenholen(
			"SELECT * FROM " . $this->main->getDB()->getTabelle('errorlogs') .
			" WHERE exception_msg LIKE '%CallerTest%' ORDER BY id DESC LIMIT 1"
		);

		$this->assertNotEmpty($logs);
		$this->assertStringContainsString('MyCallerFunction', $logs[0]['caller_name']);
	}

	public function test_logErrorToDB_stores_custom_msg(): void {
		$admin = $this->main->getAdmin();
		$uid = uniqid();
		$e = new Exception('MsgTest ' . $uid);

		$admin->logErrorToDB($e, '', 'custom context info ' . $uid);

		$logs = $this->main->getDB()->_db_datenholen(
			"SELECT * FROM " . $this->main->getDB()->getTabelle('errorlogs') .
			" WHERE exception_msg LIKE '%MsgTest " . $uid . "%' ORDER BY id DESC LIMIT 1"
		);

		$this->assertNotEmpty($logs);
		$this->assertStringContainsString('custom context info', $logs[0]['msg']);
	}

	// ── getFormatWarning / clearFormatWarning ────────────────────

	public function test_getFormatWarning_returns_null_for_clean_list(): void {
		$listId = $this->main->getDB()->insert('lists', [
			'name' => 'Clean List ' . uniqid(),
			'aktiv' => 1,
			'meta' => '{}',
		]);

		$result = $this->main->getAdmin()->getFormatWarning($listId);
		$this->assertNull($result);
	}

	public function test_getFormatWarning_returns_null_for_nonexistent(): void {
		$result = $this->main->getAdmin()->getFormatWarning(999999);
		$this->assertNull($result);
	}

	public function test_getFormatWarning_returns_critical_warning(): void {
		$listName = 'Warning List ' . uniqid();
		$listId = $this->main->getDB()->insert('lists', [
			'name' => $listName,
			'aktiv' => 1,
			'meta' => '{}',
		]);

		// Set critical format warning directly in DB meta (editList clears warnings)
		$listObj = $this->main->getAdmin()->getList(['id' => $listId]);
		$metaObj = $this->main->getCore()->encodeMetaValuesAndFillObjectList($listObj['meta']);
		$metaObj['messages']['format_end_warning']['last_email'] = wp_date('Y-m-d H:i:s');
		$metaObj['messages']['format_end_warning']['attempts'] = 500;
		$metaJson = $this->main->getCore()->json_encode_with_error_handling($metaObj);
		$this->main->getDB()->update('lists', ['meta' => $metaJson], ['id' => $listId]);

		$result = $this->main->getAdmin()->getFormatWarning($listId);
		$this->assertIsArray($result);
		$this->assertEquals('critical', $result['type']);
		$this->assertEquals(500, $result['attempts']);
	}

	public function test_getFormatWarning_returns_threshold_warning(): void {
		$listName = 'Threshold List ' . uniqid();
		$listId = $this->main->getDB()->insert('lists', [
			'name' => $listName,
			'aktiv' => 1,
			'meta' => '{}',
		]);

		$listObj = $this->main->getAdmin()->getList(['id' => $listId]);
		$metaObj = $this->main->getCore()->encodeMetaValuesAndFillObjectList($listObj['meta']);
		$metaObj['messages']['format_limit_threshold_warning']['last_email'] = wp_date('Y-m-d H:i:s');
		$metaObj['messages']['format_limit_threshold_warning']['attempts'] = 80;
		$metaJson = $this->main->getCore()->json_encode_with_error_handling($metaObj);
		$this->main->getDB()->update('lists', ['meta' => $metaJson], ['id' => $listId]);

		$result = $this->main->getAdmin()->getFormatWarning($listId);
		$this->assertIsArray($result);
		$this->assertEquals('warning', $result['type']);
		$this->assertEquals(80, $result['attempts']);
	}

	public function test_clearFormatWarning_clears_warning(): void {
		$listName = 'ClearTest List ' . uniqid();
		$listId = $this->main->getDB()->insert('lists', [
			'name' => $listName,
			'aktiv' => 1,
			'meta' => '{}',
		]);

		// Set a warning directly in DB
		$listObj = $this->main->getAdmin()->getList(['id' => $listId]);
		$metaObj = $this->main->getCore()->encodeMetaValuesAndFillObjectList($listObj['meta']);
		$metaObj['messages']['format_end_warning']['last_email'] = wp_date('Y-m-d H:i:s');
		$metaObj['messages']['format_end_warning']['attempts'] = 100;
		$metaJson = $this->main->getCore()->json_encode_with_error_handling($metaObj);
		$this->main->getDB()->update('lists', ['meta' => $metaJson], ['id' => $listId]);

		// Verify warning exists
		$this->assertNotNull($this->main->getAdmin()->getFormatWarning($listId));

		// Clear it
		$this->main->getAdmin()->clearFormatWarning($listId);

		// Verify it's gone
		$this->assertNull($this->main->getAdmin()->getFormatWarning($listId));
	}

	// ── plugin_uninstall ────────────────────────────────────────

	public function test_plugin_uninstall_is_callable(): void {
		$this->assertTrue(method_exists('sasoEventtickets_AdminSettings', 'plugin_uninstall'));
		$this->assertTrue(is_callable(['sasoEventtickets_AdminSettings', 'plugin_uninstall']));
	}

	// ── helpers ─────────────────────────────────────────────────

	private function createFullTicketSetup(): array {
		$product = new WC_Product_Simple();
		$product->set_name('ScannerTest ' . uniqid());
		$product->set_regular_price('10.00');
		$product->set_status('publish');
		$product->save();
		$pid = $product->get_id();

		update_post_meta($pid, 'saso_eventtickets_is_ticket', 'yes');
		update_post_meta($pid, 'saso_eventtickets_ticket_start_date', '2026-12-25');

		$listId = $this->main->getDB()->insert('lists', [
			'name' => 'Scanner List ' . uniqid(),
			'aktiv' => 1,
			'meta' => '{}',
		]);

		$order = wc_create_order();
		$order->add_product($product, 1);
		$order->set_status('completed');
		$order->save();

		$items = $order->get_items();
		$firstItemId = array_key_first($items);

		$metaObj = $this->main->getCore()->getMetaObject();
		$metaObj['woocommerce']['product_id'] = $pid;
		$metaObj['woocommerce']['item_id'] = $firstItemId;
		$metaObj['woocommerce']['order_id'] = $order->get_id();
		$metaObj['wc_ticket']['idcode'] = 'SCAN';
		$metaJson = $this->main->getCore()->json_encode_with_error_handling($metaObj);

		$codeStr = 'SCAN' . strtoupper(uniqid());
		$this->main->getDB()->insert('codes', [
			'list_id' => $listId,
			'code' => $codeStr,
			'aktiv' => 1,
			'cvv' => '',
			'order_id' => $order->get_id(),
			'user_id' => 0,
			'meta' => $metaJson,
		]);

		$orderItem = $items[$firstItemId];
		$orderItem->update_meta_data('_saso_eventtickets_product_code', $codeStr);
		$orderItem->update_meta_data('_saso_eventtickets_is_ticket', 'yes');
		$orderItem->save();

		$codeObj = $this->main->getCore()->retrieveCodeByCode($codeStr);

		return [
			'product_id' => $pid,
			'list_id' => $listId,
			'order' => $order,
			'code' => $codeStr,
			'codeObj' => $codeObj,
		];
	}
}
