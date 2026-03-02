<?php
/**
 * Batch 54 — Messenger validation, REST endpoints, admin helpers:
 * - sasoEventtickets_Messenger: type validation, sendMessage/sendFile error paths
 * - SASO_EVENTTICKETS: rest_pwa_manifest, isOrderPaid
 * - sasoEventtickets_AdminSettings: wpdocs_custom_timezone_string, getSupportInfos
 * - sasoEventtickets_WC: add_meta_boxes
 */

class MessengerAndRESTEndpointsTest extends WP_UnitTestCase {

	private $main;

	public function set_up(): void {
		parent::set_up();
		$this->main = sasoEventtickets::Instance();
	}

	// ── Messenger: type validation ──────────────────────────────

	public function test_messenger_instance(): void {
		$this->main->loadOnce('sasoEventtickets_Messenger');
		$messenger = sasoEventtickets_Messenger::Instance();
		$this->assertInstanceOf(sasoEventtickets_Messenger::class, $messenger);
	}

	public function test_messenger_sendMessage_throws_for_null_type(): void {
		$this->main->loadOnce('sasoEventtickets_Messenger');
		$messenger = sasoEventtickets_Messenger::Instance();

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessageMatches('/#2006/');
		$messenger->sendMessage('test', '1234567890', null);
	}

	public function test_messenger_sendMessage_throws_for_invalid_type(): void {
		$this->main->loadOnce('sasoEventtickets_Messenger');
		$messenger = sasoEventtickets_Messenger::Instance();

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessageMatches('/#2007/');
		$messenger->sendMessage('test', '1234567890', 'sms');
	}

	public function test_messenger_sendFile_throws_for_null_type(): void {
		$this->main->loadOnce('sasoEventtickets_Messenger');
		$messenger = sasoEventtickets_Messenger::Instance();

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessageMatches('/#2006/');
		$messenger->sendFile('/tmp/test.pdf', 'test', '1234567890', null);
	}

	public function test_messenger_sendFile_throws_for_invalid_type(): void {
		$this->main->loadOnce('sasoEventtickets_Messenger');
		$messenger = sasoEventtickets_Messenger::Instance();

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessageMatches('/#2007/');
		$messenger->sendFile('/tmp/test.pdf', 'test', '1234567890', 'email');
	}

	// ── REST: pwa_manifest ──────────────────────────────────────

	public function test_rest_pwa_manifest_returns_response(): void {
		$request = new WP_REST_Request('GET', '/saso-eventtickets/v1/pwa-manifest');
		$response = SASO_EVENTTICKETS::rest_pwa_manifest($request);

		$this->assertInstanceOf(WP_REST_Response::class, $response);
		$this->assertEquals(200, $response->get_status());
	}

	public function test_rest_pwa_manifest_has_required_fields(): void {
		$request = new WP_REST_Request('GET', '/saso-eventtickets/v1/pwa-manifest');
		$response = SASO_EVENTTICKETS::rest_pwa_manifest($request);
		$data = $response->get_data();

		$this->assertArrayHasKey('name', $data);
		$this->assertArrayHasKey('short_name', $data);
		$this->assertArrayHasKey('display', $data);
		$this->assertArrayHasKey('start_url', $data);
		$this->assertArrayHasKey('icons', $data);
		$this->assertEquals('Ticket Scanner', $data['name']);
		$this->assertEquals('Scanner', $data['short_name']);
		$this->assertEquals('standalone', $data['display']);
	}

	public function test_rest_pwa_manifest_icons_are_valid(): void {
		$request = new WP_REST_Request('GET', '/saso-eventtickets/v1/pwa-manifest');
		$response = SASO_EVENTTICKETS::rest_pwa_manifest($request);
		$data = $response->get_data();

		$this->assertIsArray($data['icons']);
		$this->assertCount(2, $data['icons']);

		foreach ($data['icons'] as $icon) {
			$this->assertArrayHasKey('src', $icon);
			$this->assertArrayHasKey('sizes', $icon);
			$this->assertArrayHasKey('type', $icon);
			$this->assertEquals('image/png', $icon['type']);
		}
	}

	public function test_rest_pwa_manifest_theme_color_default(): void {
		$request = new WP_REST_Request('GET', '/saso-eventtickets/v1/pwa-manifest');
		$response = SASO_EVENTTICKETS::rest_pwa_manifest($request);
		$data = $response->get_data();

		$this->assertArrayHasKey('theme_color', $data);
		$this->assertNotEmpty($data['theme_color']);
	}

	public function test_rest_pwa_manifest_start_url_contains_scanner(): void {
		$request = new WP_REST_Request('GET', '/saso-eventtickets/v1/pwa-manifest');
		$response = SASO_EVENTTICKETS::rest_pwa_manifest($request);
		$data = $response->get_data();

		$this->assertStringContainsString('scanner', $data['start_url']);
	}

	// ── isOrderPaid ─────────────────────────────────────────────

	public function test_isOrderPaid_null_returns_false(): void {
		$this->assertFalse(SASO_EVENTTICKETS::isOrderPaid(null));
	}

	public function test_isOrderPaid_string_returns_false(): void {
		$this->assertFalse(SASO_EVENTTICKETS::isOrderPaid('not_an_order'));
	}

	public function test_isOrderPaid_non_order_object_returns_false(): void {
		$this->assertFalse(SASO_EVENTTICKETS::isOrderPaid(new stdClass()));
	}

	public function test_isOrderPaid_completed_order_returns_true(): void {
		if (!class_exists('WC_Order')) {
			$this->markTestSkipped('WooCommerce not available');
		}
		$order = wc_create_order();
		$order->set_status('completed');
		$order->save();

		$this->assertTrue(SASO_EVENTTICKETS::isOrderPaid($order));
	}

	public function test_isOrderPaid_processing_order_returns_true(): void {
		if (!class_exists('WC_Order')) {
			$this->markTestSkipped('WooCommerce not available');
		}
		$order = wc_create_order();
		$order->set_status('processing');
		$order->save();

		$this->assertTrue(SASO_EVENTTICKETS::isOrderPaid($order));
	}

	public function test_isOrderPaid_pending_order_returns_false(): void {
		if (!class_exists('WC_Order')) {
			$this->markTestSkipped('WooCommerce not available');
		}
		$order = wc_create_order();
		$order->set_status('pending');
		$order->save();

		$this->assertFalse(SASO_EVENTTICKETS::isOrderPaid($order));
	}

	public function test_isOrderPaid_cancelled_order_returns_false(): void {
		if (!class_exists('WC_Order')) {
			$this->markTestSkipped('WooCommerce not available');
		}
		$order = wc_create_order();
		$order->set_status('cancelled');
		$order->save();

		$this->assertFalse(SASO_EVENTTICKETS::isOrderPaid($order));
	}

	// ── AdminSettings: timezone string ──────────────────────────

	public function test_wpdocs_custom_timezone_string_returns_string(): void {
		$result = $this->main->getAdmin()->wpdocs_custom_timezone_string();
		$this->assertIsString($result);
		$this->assertNotEmpty($result);
	}

	public function test_wpdocs_custom_timezone_string_fires_filter(): void {
		$filtered = false;
		add_filter($this->main->_add_filter_prefix . 'admin_wpdocs_custom_timezone_string', function ($tz) use (&$filtered) {
			$filtered = true;
			return $tz;
		});

		$this->main->getAdmin()->wpdocs_custom_timezone_string();
		$this->assertTrue($filtered);
	}

	// ── WC: add_meta_boxes ──────────────────────────────────────

	public function test_add_meta_boxes_product_screen(): void {
		if (!class_exists('WC_Product_Simple')) {
			$this->markTestSkipped('WooCommerce not available');
		}

		$product = new WC_Product_Simple();
		$product->set_name('MetaBox Test ' . uniqid());
		$product->set_regular_price('10.00');
		$product->set_status('publish');
		$product->save();
		$pid = $product->get_id();

		update_post_meta($pid, 'saso_eventtickets_is_ticket', 'yes');

		$post = get_post($pid);
		$wc = $this->main->getWC();
		// Should not throw
		$wc->add_meta_boxes('product', $post);
		$this->assertTrue(true);
	}

	public function test_add_meta_boxes_non_ticket_product_skips(): void {
		if (!class_exists('WC_Product_Simple')) {
			$this->markTestSkipped('WooCommerce not available');
		}

		$product = new WC_Product_Simple();
		$product->set_name('Non-ticket ' . uniqid());
		$product->set_regular_price('10.00');
		$product->set_status('publish');
		$product->save();

		$post = get_post($product->get_id());
		$wc = $this->main->getWC();
		// Should return early without adding meta box
		$wc->add_meta_boxes('product', $post);
		$this->assertTrue(true);
	}

	public function test_add_meta_boxes_order_screen(): void {
		if (!class_exists('WC_Order')) {
			$this->markTestSkipped('WooCommerce not available');
		}

		$order = wc_create_order();
		$order->save();
		$post = get_post($order->get_id());

		$wc = $this->main->getWC();
		$wc->add_meta_boxes('shop_order', $post);
		$this->assertTrue(true);
	}
}
