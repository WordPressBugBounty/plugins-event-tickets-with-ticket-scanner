<?php
/**
 * Tests for WC Order meta key/value display transformation and restriction codes:
 * - woocommerce_order_item_display_meta_key: transforms internal meta keys to labels
 * - woocommerce_order_item_display_meta_value: transforms meta values for admin display
 * - addRetrictionCodeToOrder: links restriction code to order
 * - _editList format-warning-reset branch
 * - changeOption: admin wrapper for option changes
 */

class OrderMetaKeyValueDisplayTest extends WP_UnitTestCase {

	private $main;

	public function set_up(): void {
		parent::set_up();
		$this->main = sasoEventtickets::Instance();

		if (!class_exists('WC_Product_Simple')) {
			$this->markTestSkipped('WooCommerce not available');
		}
	}

	public function tear_down(): void {
		// Reset admin screen context to prevent leaking into other tests
		set_current_screen('front');
		parent::tear_down();
	}

	private function createOrderWithTicketItem(): array {
		$listId = $this->main->getDB()->insert('lists', [
			'name' => 'KeyVal List ' . uniqid(),
			'aktiv' => 1,
			'meta' => '{}',
		]);

		$product = new WC_Product_Simple();
		$product->set_name('KeyVal Ticket ' . uniqid());
		$product->set_regular_price('10.00');
		$product->set_status('publish');
		$product->save();
		$pid = $product->get_id();

		update_post_meta($pid, 'saso_eventtickets_is_ticket', 'yes');
		update_post_meta($pid, 'saso_eventtickets_list', $listId);

		$order = wc_create_order();
		$order->add_product($product, 1);
		$order->set_billing_first_name('KeyVal');
		$order->set_billing_last_name('Test');
		$order->set_billing_email('keyval@test.com');
		$order->calculate_totals();
		$order->set_status('completed');
		$order->save();
		$order = wc_get_order($order->get_id());

		$items = $order->get_items();
		$item_id = key($items);
		$item = current($items);

		return [
			'order' => $order,
			'item_id' => $item_id,
			'item' => $item,
			'product_id' => $pid,
			'list_id' => $listId,
		];
	}

	private function makeMeta(string $key, string $value = ''): object {
		$meta = new stdClass();
		$meta->key = $key;
		$meta->value = $value;
		return $meta;
	}

	// ── woocommerce_order_item_display_meta_key ──────────────────

	public function test_display_meta_key_transforms_ticket_codes(): void {
		set_current_screen('edit-shop_order');

		$data = $this->createOrderWithTicketItem();
		// Ensure is_ticket meta exists
		wc_update_order_item_meta($data['item_id'], '_saso_eventtickets_is_ticket', 1);

		// Refresh order/item
		$order = wc_get_order($data['order']->get_id());
		$items = $order->get_items();
		$item = current($items);

		$meta = $this->makeMeta('_saso_eventtickets_product_code');

		$result = $this->main->getWC()->getOrderManager()->woocommerce_order_item_display_meta_key(
			'_saso_eventtickets_product_code', $meta, $item
		);

		$this->assertEquals('Ticket number(s)', $result);
	}

	public function test_display_meta_key_transforms_public_ids(): void {
		set_current_screen('edit-shop_order');
		$data = $this->createOrderWithTicketItem();
		$meta = $this->makeMeta('_saso_eventtickets_public_ticket_ids');

		$result = $this->main->getWC()->getOrderManager()->woocommerce_order_item_display_meta_key(
			'_saso_eventtickets_public_ticket_ids', $meta, $data['item']
		);

		$this->assertEquals('Public Ticket Id(s)', $result);
	}

	public function test_display_meta_key_transforms_is_ticket(): void {
		set_current_screen('edit-shop_order');
		$data = $this->createOrderWithTicketItem();
		$meta = $this->makeMeta('_saso_eventtickets_is_ticket');

		$result = $this->main->getWC()->getOrderManager()->woocommerce_order_item_display_meta_key(
			'_saso_eventtickets_is_ticket', $meta, $data['item']
		);

		$this->assertEquals('Is Ticket', $result);
	}

	public function test_display_meta_key_transforms_list_id(): void {
		set_current_screen('edit-shop_order');
		$data = $this->createOrderWithTicketItem();
		$meta = $this->makeMeta('_saso_eventticket_code_list');

		$result = $this->main->getWC()->getOrderManager()->woocommerce_order_item_display_meta_key(
			'_saso_eventticket_code_list', $meta, $data['item']
		);

		$this->assertEquals('List ID', $result);
	}

	public function test_display_meta_key_transforms_daychooser(): void {
		set_current_screen('edit-shop_order');
		$data = $this->createOrderWithTicketItem();
		$meta = $this->makeMeta('_saso_eventtickets_daychooser');

		$result = $this->main->getWC()->getOrderManager()->woocommerce_order_item_display_meta_key(
			'_saso_eventtickets_daychooser', $meta, $data['item']
		);

		$this->assertEquals('Day(s) per ticket', $result);
	}

	public function test_display_meta_key_leaves_unknown_unchanged(): void {
		set_current_screen('edit-shop_order');
		$data = $this->createOrderWithTicketItem();
		$meta = $this->makeMeta('some_random_meta');

		$result = $this->main->getWC()->getOrderManager()->woocommerce_order_item_display_meta_key(
			'some_random_meta', $meta, $data['item']
		);

		$this->assertEquals('some_random_meta', $result);
	}

	public function test_display_meta_key_is_filterable(): void {
		set_current_screen('edit-shop_order');
		$data = $this->createOrderWithTicketItem();
		$meta = $this->makeMeta('_saso_eventtickets_is_ticket');

		$filter = function ($key) { return 'Custom Label'; };
		add_filter('saso_eventtickets_woocommerce-hooks_woocommerce_order_item_display_meta_key', $filter);

		$result = $this->main->getWC()->getOrderManager()->woocommerce_order_item_display_meta_key(
			'_saso_eventtickets_is_ticket', $meta, $data['item']
		);
		$this->assertEquals('Custom Label', $result);

		remove_filter('saso_eventtickets_woocommerce-hooks_woocommerce_order_item_display_meta_key', $filter);
	}

	// ── woocommerce_order_item_display_meta_value ────────────────

	public function test_display_meta_value_wraps_codes_in_links(): void {
		set_current_screen('edit-shop_order');
		$data = $this->createOrderWithTicketItem();
		$meta = $this->makeMeta('_saso_eventtickets_product_code', 'ABC123,DEF456');

		$result = $this->main->getWC()->getOrderManager()->woocommerce_order_item_display_meta_value(
			'ABC123,DEF456', $meta, $data['item']
		);

		$this->assertStringContainsString('<a target="_blank"', $result);
		$this->assertStringContainsString('ABC123', $result);
		$this->assertStringContainsString('DEF456', $result);
	}

	public function test_display_meta_value_formats_is_ticket_yes(): void {
		set_current_screen('edit-shop_order');
		$data = $this->createOrderWithTicketItem();
		$meta = $this->makeMeta('_saso_eventtickets_is_ticket', '1');

		$result = $this->main->getWC()->getOrderManager()->woocommerce_order_item_display_meta_value(
			'1', $meta, $data['item']
		);
		$this->assertEquals('Yes', $result);
	}

	public function test_display_meta_value_formats_is_ticket_no(): void {
		set_current_screen('edit-shop_order');
		$data = $this->createOrderWithTicketItem();
		$meta = $this->makeMeta('_saso_eventtickets_is_ticket', '0');

		$result = $this->main->getWC()->getOrderManager()->woocommerce_order_item_display_meta_value(
			'0', $meta, $data['item']
		);
		$this->assertEquals('No', $result);
	}

	public function test_display_meta_value_formats_empty_public_ids_as_dash(): void {
		set_current_screen('edit-shop_order');
		$data = $this->createOrderWithTicketItem();
		$meta = $this->makeMeta('_saso_eventtickets_public_ticket_ids', '');

		$result = $this->main->getWC()->getOrderManager()->woocommerce_order_item_display_meta_value(
			'', $meta, $data['item']
		);
		$this->assertEquals('-', $result);
	}

	public function test_display_meta_value_formats_daychooser_dates(): void {
		set_current_screen('edit-shop_order');
		$data = $this->createOrderWithTicketItem();
		$meta = $this->makeMeta('_saso_eventtickets_daychooser', '2026-08-15, 2026-08-16');

		$result = $this->main->getWC()->getOrderManager()->woocommerce_order_item_display_meta_value(
			'2026-08-15, 2026-08-16', $meta, $data['item']
		);

		$this->assertStringContainsString('2026-08-15', $result);
		$this->assertStringContainsString('2026-08-16', $result);
	}

	public function test_display_meta_value_formats_seat_labels(): void {
		set_current_screen('edit-shop_order');
		$data = $this->createOrderWithTicketItem();
		$meta = $this->makeMeta('_saso_eventtickets_seat_labels', '["A1","A2","B3"]');

		$result = $this->main->getWC()->getOrderManager()->woocommerce_order_item_display_meta_value(
			'["A1","A2","B3"]', $meta, $data['item']
		);

		$this->assertStringContainsString('A1', $result);
		$this->assertStringContainsString('B3', $result);
	}

	public function test_display_meta_value_is_filterable(): void {
		set_current_screen('edit-shop_order');
		$data = $this->createOrderWithTicketItem();
		$meta = $this->makeMeta('_saso_eventtickets_is_ticket', '1');

		$filter = function ($val) { return 'Overridden'; };
		add_filter('saso_eventtickets_woocommerce-hooks_woocommerce_order_item_display_meta_value', $filter);

		$result = $this->main->getWC()->getOrderManager()->woocommerce_order_item_display_meta_value(
			'1', $meta, $data['item']
		);
		$this->assertEquals('Overridden', $result);

		remove_filter('saso_eventtickets_woocommerce-hooks_woocommerce_order_item_display_meta_value', $filter);
	}

	// ── addRetrictionCodeToOrder ──────────────────────────────────

	public function test_addRetrictionCodeToOrder_writes_wc_rp_meta(): void {
		$data = $this->createOrderWithTicketItem();

		// Create a code in the list
		$code = 'RESTRICT_' . uniqid();
		$metaObj = $this->main->getCore()->getMetaObject();
		$codeId = $this->main->getDB()->insert('codes', [
			'code' => $code,
			'list_id' => $data['list_id'],
			'redeemed' => 0,
			'aktiv' => 1,
			'meta' => json_encode($metaObj),
		]);

		$this->main->getAdmin()->addRetrictionCodeToOrder(
			$code, $data['list_id'], $data['order']->get_id(), $data['product_id'], $data['item_id']
		);

		// Read back code and check meta
		$codeObj = $this->main->getCore()->retrieveCodeByCode($code);
		$meta = json_decode($codeObj['meta'], true);

		$this->assertArrayHasKey('wc_rp', $meta);
		$this->assertEquals($data['order']->get_id(), $meta['wc_rp']['order_id']);
		$this->assertEquals($data['product_id'], $meta['wc_rp']['product_id']);
		$this->assertEquals($data['item_id'], $meta['wc_rp']['item_id']);
	}

	public function test_addRetrictionCodeToOrder_skips_empty_code(): void {
		$data = $this->createOrderWithTicketItem();

		// Empty code should return without error
		$result = $this->main->getAdmin()->addRetrictionCodeToOrder(
			'', $data['list_id'], $data['order']->get_id()
		);
		$this->assertNull($result);
	}

	// ── _editList format-warning-reset ───────────────────────────

	public function test_editList_clears_format_warnings_when_last_email_set(): void {
		// Create list with format warnings
		$warningMeta = json_encode([
			'desc' => '',
			'redirect' => ['url' => ''],
			'formatter' => ['active' => 1, 'format' => ''],
			'webhooks' => ['webhookURLaddwcticketsold' => ''],
			'messages' => [
				'format_limit_threshold_warning' => ['attempts' => 5, 'last_email' => '2026-02-20 10:00:00'],
				'format_end_warning' => ['attempts' => 3, 'last_email' => '2026-02-21 12:00:00'],
			],
		]);
		$listId = $this->main->getDB()->insert('lists', [
			'name' => 'Warning Reset Test ' . uniqid(),
			'aktiv' => 1,
			'meta' => $warningMeta,
		]);

		// Edit the list (this should clear warnings)
		$listObj = $this->main->getAdmin()->getList(['id' => $listId]);
		$this->main->getAdmin()->_editList([
			'id' => $listId,
			'name' => $listObj['name'],
		]);

		// Read back and check warnings were cleared
		$updatedList = $this->main->getAdmin()->getList(['id' => $listId]);
		$meta = json_decode($updatedList['meta'], true);

		$this->assertEquals(0, $meta['messages']['format_limit_threshold_warning']['attempts']);
		$this->assertEquals('', $meta['messages']['format_limit_threshold_warning']['last_email']);
		$this->assertEquals(0, $meta['messages']['format_end_warning']['attempts']);
		$this->assertEquals('', $meta['messages']['format_end_warning']['last_email']);
	}

	public function test_editList_preserves_clean_warnings(): void {
		// Create list WITHOUT format warnings
		$cleanMeta = json_encode([
			'desc' => '',
			'redirect' => ['url' => ''],
			'formatter' => ['active' => 1, 'format' => ''],
			'webhooks' => ['webhookURLaddwcticketsold' => ''],
			'messages' => [
				'format_limit_threshold_warning' => ['attempts' => 0, 'last_email' => ''],
				'format_end_warning' => ['attempts' => 0, 'last_email' => ''],
			],
		]);
		$listId = $this->main->getDB()->insert('lists', [
			'name' => 'No Warning Test ' . uniqid(),
			'aktiv' => 1,
			'meta' => $cleanMeta,
		]);

		$listObj = $this->main->getAdmin()->getList(['id' => $listId]);
		$this->main->getAdmin()->_editList([
			'id' => $listId,
			'name' => $listObj['name'],
		]);

		// Warnings should still be at 0
		$updatedList = $this->main->getAdmin()->getList(['id' => $listId]);
		$meta = json_decode($updatedList['meta'], true);

		$this->assertEquals(0, $meta['messages']['format_limit_threshold_warning']['attempts']);
		$this->assertEquals(0, $meta['messages']['format_end_warning']['attempts']);
	}

	// ── changeOption ─────────────────────────────────────────────

	public function test_changeOption_sets_checkbox_value(): void {
		$this->main->getAdmin()->changeOption([
			'key' => 'wcTicketDontAllowRedeemTicketBeforeStart',
			'value' => '1',
		]);
		$this->main->getOptions()->initOptions();

		$this->assertTrue(
			$this->main->getOptions()->isOptionCheckboxActive('wcTicketDontAllowRedeemTicketBeforeStart')
		);

		// Reset
		$this->main->getAdmin()->changeOption([
			'key' => 'wcTicketDontAllowRedeemTicketBeforeStart',
			'value' => '0',
		]);
		$this->main->getOptions()->initOptions();
	}

	public function test_changeOption_sets_text_value(): void {
		$this->main->getAdmin()->changeOption([
			'key' => 'displayDateFormat',
			'value' => 'd.m.Y',
		]);
		$this->main->getOptions()->initOptions();

		$this->assertEquals('d.m.Y', $this->main->getOptions()->getOptionValue('displayDateFormat'));

		// Reset
		$this->main->getAdmin()->changeOption([
			'key' => 'displayDateFormat',
			'value' => 'Y/m/d',
		]);
		$this->main->getOptions()->initOptions();
	}

	public function test_changeOption_ignores_unknown_key(): void {
		// Should not crash for unknown key
		$this->main->getAdmin()->changeOption([
			'key' => 'nonexistent_option_xyz',
			'value' => 'test',
		]);
		$this->assertTrue(true); // No exception = pass
	}
}
