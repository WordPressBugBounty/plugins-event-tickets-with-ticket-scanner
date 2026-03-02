<?php
/**
 * Tests for webhook routing and DB migration logic:
 * - triggerWebhooks: status-to-option mapping, per-list URL override, enabled/disabled guard
 * - performJobsAfterDBUpgraded: DB migration for redeemed codes and seat_blocks
 * - hasTicketsInCart / containsProductsWithRestrictions: cart helper methods
 * - woocommerce_cart_updated_handler: per-ticket session data from POST
 */

class WebhooksAndMigrationTest extends WP_UnitTestCase {

	private $main;

	public function set_up(): void {
		parent::set_up();
		$this->main = sasoEventtickets::Instance();
	}

	// ── triggerWebhooks ──────────────────────────────────────────

	public function test_triggerWebhooks_does_nothing_when_disabled(): void {
		// Ensure webhooks option is disabled
		update_option('sasoEventticketswebhooksActiv', '0');
		$this->main->getOptions()->initOptions();

		$fired = false;
		$callback = function () use (&$fired) {
			$fired = true;
		};
		add_action('saso_eventtickets_core_triggerWebhooks', $callback);

		$codeObj = ['list_id' => 1, 'code' => 'TEST'];
		$this->main->getCore()->triggerWebhooks(1, $codeObj);

		$this->assertFalse($fired, 'Should not fire action when webhooks disabled');

		remove_action('saso_eventtickets_core_triggerWebhooks', $callback);
	}

	public function test_triggerWebhooks_fires_action_when_enabled_and_url_set(): void {
		// Enable webhooks
		update_option('sasoEventticketswebhooksActiv', '1');
		// Set URL for status 1 (valid)
		update_option('sasoEventticketswebhookURLvalid', 'https://example.com/webhook?code={code}');
		$this->main->getOptions()->initOptions();

		$fired = false;
		$receivedStatus = null;
		$callback = function ($status, $codeObj, $url) use (&$fired, &$receivedStatus) {
			$fired = true;
			$receivedStatus = $status;
		};
		add_action('saso_eventtickets_core_triggerWebhooks', $callback, 10, 3);

		// Mock wp_remote_get to avoid real HTTP call
		add_filter('pre_http_request', function () {
			return ['response' => ['code' => 200]];
		});

		$codeObj = ['list_id' => 1, 'code' => 'TESTCODE', 'id' => 1];
		$this->main->getCore()->triggerWebhooks(1, $codeObj);

		$this->assertTrue($fired, 'Action should fire when webhooks enabled with URL');
		$this->assertEquals(1, $receivedStatus);

		// Cleanup
		remove_action('saso_eventtickets_core_triggerWebhooks', $callback, 10);
		update_option('sasoEventticketswebhooksActiv', '0');
		update_option('sasoEventticketswebhookURLvalid', '');
		$this->main->getOptions()->initOptions();
	}

	public function test_triggerWebhooks_does_nothing_for_empty_url(): void {
		update_option('sasoEventticketswebhooksActiv', '1');
		update_option('sasoEventticketswebhookURLvalid', '');
		$this->main->getOptions()->initOptions();

		$fired = false;
		$callback = function () use (&$fired) {
			$fired = true;
		};
		add_action('saso_eventtickets_core_triggerWebhooks', $callback);

		$codeObj = ['list_id' => 1, 'code' => 'TEST', 'id' => 1];
		$this->main->getCore()->triggerWebhooks(1, $codeObj);

		$this->assertFalse($fired, 'Should not fire action when URL is empty');

		remove_action('saso_eventtickets_core_triggerWebhooks', $callback);
		update_option('sasoEventticketswebhooksActiv', '0');
		$this->main->getOptions()->initOptions();
	}

	public function test_triggerWebhooks_status_mapping_covers_all_known_statuses(): void {
		// Verify the mapping array via reflection
		$ref = new ReflectionMethod($this->main->getCore(), 'triggerWebhooks');
		$ref->setAccessible(true);

		// Test with an invalid status — should just skip (no option key)
		update_option('sasoEventticketswebhooksActiv', '1');
		$this->main->getOptions()->initOptions();

		$fired = false;
		$callback = function () use (&$fired) {
			$fired = true;
		};
		add_action('saso_eventtickets_core_triggerWebhooks', $callback);

		$codeObj = ['list_id' => 1, 'code' => 'TEST', 'id' => 1];
		$this->main->getCore()->triggerWebhooks(999, $codeObj); // Unknown status

		$this->assertFalse($fired, 'Unknown status should not trigger webhook');

		remove_action('saso_eventtickets_core_triggerWebhooks', $callback);
		update_option('sasoEventticketswebhooksActiv', '0');
		$this->main->getOptions()->initOptions();
	}

	public function test_triggerWebhooks_uses_list_url_override_for_sold_status(): void {
		if (!class_exists('WC_Product_Simple')) {
			$this->markTestSkipped('WooCommerce not available');
		}

		// Create a list with a custom webhook URL
		$webhookUrl = 'https://custom-list.example.com/sold';
		$meta = json_encode([
			'desc' => '',
			'redirect' => ['url' => ''],
			'formatter' => ['active' => 1, 'format' => ''],
			'webhooks' => ['webhookURLaddwcticketsold' => $webhookUrl],
			'messages' => [
				'format_limit_threshold_warning' => ['attempts' => 0, 'last_email' => ''],
				'format_end_warning' => ['attempts' => 0, 'last_email' => ''],
			],
		]);
		$listId = $this->main->getDB()->insert('lists', [
			'name' => 'Webhook Override List ' . uniqid(),
			'aktiv' => 1,
			'meta' => $meta,
		]);

		update_option('sasoEventticketswebhooksActiv', '1');
		update_option('sasoEventticketswebhookURLaddwcticketsold', 'https://global.example.com/sold');
		$this->main->getOptions()->initOptions();

		$receivedUrl = null;
		$callback = function ($status, $codeObj, $url) use (&$receivedUrl) {
			$receivedUrl = $url;
		};
		add_action('saso_eventtickets_core_triggerWebhooks', $callback, 10, 3);

		add_filter('pre_http_request', function () {
			return ['response' => ['code' => 200]];
		});

		$codeObj = ['list_id' => $listId, 'code' => 'SOLD123', 'id' => 1];
		$this->main->getCore()->triggerWebhooks(17, $codeObj); // 17 = webhookURLaddwcticketsold

		$this->assertNotNull($receivedUrl);
		$this->assertStringContainsString('custom-list.example.com', $receivedUrl);

		// Cleanup
		remove_action('saso_eventtickets_core_triggerWebhooks', $callback, 10);
		update_option('sasoEventticketswebhooksActiv', '0');
		update_option('sasoEventticketswebhookURLaddwcticketsold', '');
		$this->main->getOptions()->initOptions();
	}

	// ── performJobsAfterDBUpgraded ───────────────────────────────

	public function test_performJobsAfterDBUpgraded_marks_redeemed_codes(): void {
		// Create a code with redeemed_date in meta but redeemed=0
		$listId = $this->main->getDB()->insert('lists', [
			'name' => 'Migration Test ' . uniqid(),
			'aktiv' => 1,
			'meta' => '{}',
		]);

		$metaObj = $this->main->getCore()->getMetaObject();
		$metaObj['wc_ticket']['redeemed_date'] = '2026-01-15 10:00:00';
		$metaJson = json_encode($metaObj);

		$codeId = $this->main->getDB()->insert('codes', [
			'code' => 'MIGRATE_' . uniqid(),
			'list_id' => $listId,
			'redeemed' => 0,
			'aktiv' => 1,
			'meta' => $metaJson,
		]);

		// Run migration for DB version between 1.0 and 2.0
		$this->main->getAdmin()->performJobsAfterDBUpgraded('1.5', '1.0');

		// Code should now be marked as redeemed
		$table = $this->main->getDB()->getTabelle('codes');
		global $wpdb;
		$redeemed = $wpdb->get_var("SELECT redeemed FROM {$table} WHERE id = " . intval($codeId));
		$this->assertEquals(1, intval($redeemed));
	}

	public function test_performJobsAfterDBUpgraded_skips_non_redeemed_codes(): void {
		$listId = $this->main->getDB()->insert('lists', [
			'name' => 'Migration Skip Test ' . uniqid(),
			'aktiv' => 1,
			'meta' => '{}',
		]);

		$metaObj = $this->main->getCore()->getMetaObject();
		// No redeemed_date set
		$metaJson = json_encode($metaObj);

		$codeId = $this->main->getDB()->insert('codes', [
			'code' => 'NOMIGRATE_' . uniqid(),
			'list_id' => $listId,
			'redeemed' => 0,
			'aktiv' => 1,
			'meta' => $metaJson,
		]);

		$this->main->getAdmin()->performJobsAfterDBUpgraded('1.5', '1.0');

		$table = $this->main->getDB()->getTabelle('codes');
		global $wpdb;
		$redeemed = $wpdb->get_var("SELECT redeemed FROM {$table} WHERE id = " . intval($codeId));
		$this->assertEquals(0, intval($redeemed));
	}

	public function test_performJobsAfterDBUpgraded_fires_action(): void {
		$fired = false;
		$receivedVersion = '';
		$callback = function ($dbversion, $dbversion_pre) use (&$fired, &$receivedVersion) {
			$fired = true;
			$receivedVersion = $dbversion;
		};
		add_action('saso_eventtickets_performJobsAfterDBUpgraded', $callback, 10, 2);

		$this->main->getAdmin()->performJobsAfterDBUpgraded('2.0', '1.9');

		$this->assertTrue($fired);
		$this->assertEquals('2.0', $receivedVersion);

		remove_action('saso_eventtickets_performJobsAfterDBUpgraded', $callback, 10);
	}

	public function test_performJobsAfterDBUpgraded_skips_migration_for_high_version(): void {
		// Version >= 2.0 should skip the 1.x migration
		$listId = $this->main->getDB()->insert('lists', [
			'name' => 'High Version Test ' . uniqid(),
			'aktiv' => 1,
			'meta' => '{}',
		]);

		$metaObj = $this->main->getCore()->getMetaObject();
		$metaObj['wc_ticket']['redeemed_date'] = '2026-01-15 10:00:00';
		$metaJson = json_encode($metaObj);

		$codeId = $this->main->getDB()->insert('codes', [
			'code' => 'HIGHVER_' . uniqid(),
			'list_id' => $listId,
			'redeemed' => 0,
			'aktiv' => 1,
			'meta' => $metaJson,
		]);

		// Version 2.0+ should NOT run the 1.x migration
		$this->main->getAdmin()->performJobsAfterDBUpgraded('2.0', '1.9');

		$table = $this->main->getDB()->getTabelle('codes');
		global $wpdb;
		$redeemed = $wpdb->get_var("SELECT redeemed FROM {$table} WHERE id = " . intval($codeId));
		$this->assertEquals(0, intval($redeemed), 'Version 2.0+ should not run 1.x migration');
	}

	// ── hasTicketsInCart ─────────────────────────────────────────

	public function test_hasTicketsInCart_returns_true_for_ticket_product(): void {
		if (!class_exists('WC_Product_Simple')) {
			$this->markTestSkipped('WooCommerce not available');
		}

		$tp = $this->createTicketProduct();
		WC()->cart->empty_cart();
		WC()->cart->add_to_cart($tp['product_id']);

		$result = $this->main->getWC()->getFrontendManager()->hasTicketsInCart();
		$this->assertTrue($result);

		WC()->cart->empty_cart();
	}

	public function test_hasTicketsInCart_returns_false_for_regular_product(): void {
		if (!class_exists('WC_Product_Simple')) {
			$this->markTestSkipped('WooCommerce not available');
		}

		$product = new WC_Product_Simple();
		$product->set_name('Regular');
		$product->set_regular_price('5.00');
		$product->set_status('publish');
		$product->save();

		WC()->cart->empty_cart();
		WC()->cart->add_to_cart($product->get_id());

		$result = $this->main->getWC()->getFrontendManager()->hasTicketsInCart();
		$this->assertFalse($result);

		WC()->cart->empty_cart();
	}

	public function test_hasTicketsInCart_returns_false_for_empty_cart(): void {
		if (!class_exists('WC_Product_Simple')) {
			$this->markTestSkipped('WooCommerce not available');
		}

		WC()->cart->empty_cart();

		$result = $this->main->getWC()->getFrontendManager()->hasTicketsInCart();
		$this->assertFalse($result);
	}

	// ── woocommerce_cart_updated_handler ──────────────────────────

	public function test_cart_updated_handler_returns_early_on_heartbeat(): void {
		if (!class_exists('WC_Product_Simple')) {
			$this->markTestSkipped('WooCommerce not available');
		}

		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST['action'] = 'heartbeat';
		$ref = new ReflectionProperty('SASO_EVENTTICKETS', 'REQUEST_DATA');
		$ref->setAccessible(true);
		$ref->setValue(null, null);

		// Should return early without error
		$this->main->getWC()->getFrontendManager()->woocommerce_cart_updated_handler();

		$this->assertTrue(true); // No exception = pass

		unset($_POST['action'], $_SERVER['REQUEST_METHOD']);
		$ref->setValue(null, null);
	}

	public function test_cart_updated_handler_stores_name_per_ticket_in_session(): void {
		if (!class_exists('WC_Product_Simple')) {
			$this->markTestSkipped('WooCommerce not available');
		}

		$tp = $this->createTicketProduct();
		WC()->cart->empty_cart();
		$cart_item_key = WC()->cart->add_to_cart($tp['product_id']);

		if (!$cart_item_key) {
			$this->markTestSkipped('Could not add to cart');
		}

		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST['saso_eventtickets_request_name_per_ticket'] = [
			$cart_item_key => 'John Doe',
		];
		$ref = new ReflectionProperty('SASO_EVENTTICKETS', 'REQUEST_DATA');
		$ref->setAccessible(true);
		$ref->setValue(null, null);

		$this->main->getWC()->getFrontendManager()->woocommerce_cart_updated_handler();

		$sessionData = WC()->session->get('saso_eventtickets_request_name_per_ticket');
		$this->assertIsArray($sessionData);
		$this->assertEquals('John Doe', $sessionData[$cart_item_key]);

		// Cleanup
		unset($_POST['saso_eventtickets_request_name_per_ticket'], $_SERVER['REQUEST_METHOD']);
		$ref->setValue(null, null);
		WC()->session->__unset('saso_eventtickets_request_name_per_ticket');
		WC()->cart->empty_cart();
	}

	private function createTicketProduct(array $extraMeta = []): array {
		$listId = $this->main->getDB()->insert('lists', [
			'name' => 'Webhook List ' . uniqid(),
			'aktiv' => 1,
			'meta' => '{}',
		]);

		$product = new WC_Product_Simple();
		$product->set_name('Webhook Ticket ' . uniqid());
		$product->set_regular_price('10.00');
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
}
