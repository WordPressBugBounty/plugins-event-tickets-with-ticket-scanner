<?php
/**
 * Batch 56 — Shortcodes and code list display:
 * - replacingShortcodeTicketScanner: scanner HTML boilerplate
 * - replacingShortcodeTicketDetail: ticket detail page via shortcode
 * - replacingShortcodeFeatureList: option features list
 * - replacingShortcodeEventViews: event calendar/list view
 * - getCodesTextAsShortList: code display table
 * - getMyCodeText: user profile code display
 */

class ShortcodesAndCodeListTest extends WP_UnitTestCase {

	private $main;

	public function set_up(): void {
		parent::set_up();
		$this->main = sasoEventtickets::Instance();
	}

	// ── replacingShortcodeTicketScanner ──────────────────────────

	public function test_shortcode_ticket_scanner_returns_html(): void {
		$result = $this->main->replacingShortcodeTicketScanner();
		$this->assertIsString($result);
		$this->assertNotEmpty($result);
	}

	public function test_shortcode_ticket_scanner_contains_div(): void {
		$result = $this->main->replacingShortcodeTicketScanner();
		$this->assertStringContainsString('<div', $result);
	}

	public function test_shortcode_ticket_scanner_with_attrs(): void {
		$result = $this->main->replacingShortcodeTicketScanner(['foo' => 'bar']);
		$this->assertIsString($result);
	}

	// ── replacingShortcodeTicketDetail ───────────────────────────

	public function test_shortcode_ticket_detail_no_code_returns_message(): void {
		$result = $this->main->replacingShortcodeTicketDetail();
		$this->assertIsString($result);
		$this->assertStringContainsString('<p>', $result);
		$this->assertStringContainsString('ticket', strtolower($result));
	}

	public function test_shortcode_ticket_detail_with_attr_code(): void {
		if (!class_exists('WC_Product_Simple')) {
			$this->markTestSkipped('WooCommerce not available');
		}

		// Create a ticket code to test with
		$product = new WC_Product_Simple();
		$product->set_name('ShortcodeDetail Test ' . uniqid());
		$product->set_regular_price('10.00');
		$product->set_status('publish');
		$product->save();
		$pid = $product->get_id();

		update_post_meta($pid, 'saso_eventtickets_is_ticket', 'yes');
		update_post_meta($pid, 'saso_eventtickets_ticket_start_date', '2026-12-25');

		$listId = $this->main->getDB()->insert('lists', [
			'name' => 'Detail Test ' . uniqid(),
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
		$metaObj['wc_ticket']['idcode'] = 'SCDETAIL';
		$metaJson = $this->main->getCore()->json_encode_with_error_handling($metaObj);

		$codeStr = 'SCDETAIL' . strtoupper(uniqid());
		$this->main->getDB()->insert('codes', [
			'list_id' => $listId,
			'code' => $codeStr,
			'aktiv' => 1,
			'cvv' => '',
			'order_id' => $order->get_id(),
			'user_id' => 0,
			'meta' => $metaJson,
		]);

		$codeObj = $this->main->getCore()->retrieveCodeByCode($codeStr);
		$metaObj2 = $this->main->getCore()->encodeMetaValuesAndFillObject($codeObj['meta'], $codeObj);
		$ticketId = $this->main->getCore()->getTicketId($codeObj, $metaObj2);

		// Call with the ticket id as code attribute
		$result = $this->main->replacingShortcodeTicketDetail(['code' => $ticketId]);
		$this->assertIsString($result);
	}

	// ── replacingShortcodeFeatureList ────────────────────────────

	public function test_shortcode_feature_list_returns_html(): void {
		$result = $this->main->replacingShortcodeFeatureList();
		$this->assertIsString($result);
		$this->assertNotEmpty($result);
	}

	public function test_shortcode_feature_list_contains_headings(): void {
		$result = $this->main->replacingShortcodeFeatureList();
		$this->assertStringContainsString('<h3>', $result);
	}

	public function test_shortcode_feature_list_contains_options(): void {
		$result = $this->main->replacingShortcodeFeatureList();
		$this->assertStringContainsString('<li>', $result);
	}

	// ── replacingShortcodeEventViews ────────────────────────────

	public function test_shortcode_event_views_returns_string(): void {
		if (!class_exists('WC_Product_Simple')) {
			$this->markTestSkipped('WooCommerce not available');
		}
		$result = $this->main->replacingShortcodeEventViews();
		$this->assertIsString($result);
	}

	public function test_shortcode_event_views_with_list_view(): void {
		if (!class_exists('WC_Product_Simple')) {
			$this->markTestSkipped('WooCommerce not available');
		}
		$result = $this->main->replacingShortcodeEventViews(['view' => 'list']);
		$this->assertIsString($result);
	}

	public function test_shortcode_event_views_with_calendar_view(): void {
		if (!class_exists('WC_Product_Simple')) {
			$this->markTestSkipped('WooCommerce not available');
		}
		$result = $this->main->replacingShortcodeEventViews(['view' => 'calendar']);
		$this->assertIsString($result);
	}

	public function test_shortcode_event_views_with_months(): void {
		if (!class_exists('WC_Product_Simple')) {
			$this->markTestSkipped('WooCommerce not available');
		}
		$result = $this->main->replacingShortcodeEventViews(['months_to_show' => '6']);
		$this->assertIsString($result);
	}

	public function test_shortcode_event_views_returns_container_div(): void {
		if (!class_exists('WC_Product_Simple')) {
			$this->markTestSkipped('WooCommerce not available');
		}

		$result = $this->main->replacingShortcodeEventViews(['view' => 'list', 'months_to_show' => '3']);
		$this->assertIsString($result);
		// Shortcode returns a container div that loads content via AJAX
		$this->assertStringContainsString('sasoEventTicketsValidator_eventsview', $result);
	}

	// ── getCodesTextAsShortList ──────────────────────────────────

	public function test_getCodesTextAsShortList_empty_array(): void {
		$result = $this->main->getCodesTextAsShortList([]);
		$this->assertIsString($result);
		$this->assertEmpty($result);
	}

	public function test_getCodesTextAsShortList_with_code(): void {
		if (!class_exists('WC_Product_Simple')) {
			$this->markTestSkipped('WooCommerce not available');
		}

		$pid = $this->createTicketProduct();
		$data = $this->createCodeForProduct($pid);

		$result = $this->main->getCodesTextAsShortList([$data['codeObj']]);
		$this->assertIsString($result);
		$this->assertStringContainsString('<table>', $result);
		$this->assertStringContainsString('</table>', $result);
	}

	public function test_getCodesTextAsShortList_inactive_code_shows_label(): void {
		if (!class_exists('WC_Product_Simple')) {
			$this->markTestSkipped('WooCommerce not available');
		}

		$pid = $this->createTicketProduct();
		$data = $this->createCodeForProduct($pid);

		// Set code to inactive
		$this->main->getDB()->update('codes', ['aktiv' => 0], ['id' => $data['codeObj']['id']]);
		$codeObj = $this->main->getCore()->retrieveCodeByCode($data['code']);

		$result = $this->main->getCodesTextAsShortList([$codeObj]);
		$this->assertStringContainsString('<table>', $result);
	}

	public function test_getCodesTextAsShortList_fires_filter(): void {
		$filtered = false;
		add_filter($this->main->_add_filter_prefix . 'main_getCodesTextAsShortList', function ($ret) use (&$filtered) {
			$filtered = true;
			return $ret;
		});

		$this->main->getCodesTextAsShortList([]);
		$this->assertTrue($filtered);
	}

	// ── getMyCodeText ───────────────────────────────────────────

	public function test_getMyCodeText_no_user_returns_empty(): void {
		$result = $this->main->getMyCodeText(0);
		$this->assertIsString($result);
	}

	public function test_getMyCodeText_fires_filter(): void {
		$filtered = false;
		add_filter($this->main->_add_filter_prefix . 'main_getMyCodeText', function ($ret) use (&$filtered) {
			$filtered = true;
			return $ret;
		});

		$this->main->getMyCodeText(0);
		$this->assertTrue($filtered);
	}

	public function test_getMyCodeText_with_codes(): void {
		if (!class_exists('WC_Product_Simple')) {
			$this->markTestSkipped('WooCommerce not available');
		}

		$pid = $this->createTicketProduct();
		$data = $this->createCodeForProduct($pid);

		$result = $this->main->getMyCodeText(0, [], null, '', [$data['codeObj']]);
		$this->assertIsString($result);
		$this->assertStringContainsString('<table>', $result);
	}

	// ── helpers ─────────────────────────────────────────────────

	private function createTicketProduct(): int {
		$product = new WC_Product_Simple();
		$product->set_name('SC Test ' . uniqid());
		$product->set_regular_price('10.00');
		$product->set_status('publish');
		$product->save();
		$pid = $product->get_id();

		update_post_meta($pid, 'saso_eventtickets_is_ticket', 'yes');
		update_post_meta($pid, 'saso_eventtickets_ticket_start_date', '2026-12-25');

		return $pid;
	}

	private function createCodeForProduct(int $pid): array {
		$listId = $this->main->getDB()->insert('lists', [
			'name' => 'SC List ' . uniqid(),
			'aktiv' => 1,
			'meta' => '{}',
		]);

		$metaObj = $this->main->getCore()->getMetaObject();
		$metaObj['woocommerce']['product_id'] = $pid;
		$metaJson = $this->main->getCore()->json_encode_with_error_handling($metaObj);

		$codeStr = 'SCTEST' . strtoupper(uniqid());
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
		return ['code' => $codeStr, 'codeObj' => $codeObj, 'list_id' => $listId];
	}
}
