<?php
/**
 * Tests for PWA manifest, session helpers, isOrderPaid, and plugin version info:
 * - rest_pwa_manifest: returns WP_REST_Response with correct PWA manifest structure
 * - session_set_value / session_get_value: WC session round-trip storage
 * - isOrderPaid: static helper for paid order status check
 * - getPluginVersions: returns version array with basic/premium/debug
 */

class PWAManifestAndSessionTest extends WP_UnitTestCase {

	private $main;

	public function set_up(): void {
		parent::set_up();
		$this->main = sasoEventtickets::Instance();
	}

	// ── rest_pwa_manifest ───────────────────────────────────────

	public function test_pwa_manifest_returns_wp_rest_response(): void {
		$request = new WP_REST_Request('GET', '/saso-eventtickets/v1/pwa-manifest');
		$response = SASO_EVENTTICKETS::rest_pwa_manifest($request);

		$this->assertInstanceOf(WP_REST_Response::class, $response);
		$this->assertEquals(200, $response->get_status());
	}

	public function test_pwa_manifest_contains_required_keys(): void {
		$request = new WP_REST_Request('GET', '/saso-eventtickets/v1/pwa-manifest');
		$response = SASO_EVENTTICKETS::rest_pwa_manifest($request);
		$data = $response->get_data();

		$this->assertArrayHasKey('name', $data);
		$this->assertArrayHasKey('short_name', $data);
		$this->assertArrayHasKey('display', $data);
		$this->assertArrayHasKey('orientation', $data);
		$this->assertArrayHasKey('theme_color', $data);
		$this->assertArrayHasKey('background_color', $data);
		$this->assertArrayHasKey('start_url', $data);
		$this->assertArrayHasKey('scope', $data);
		$this->assertArrayHasKey('icons', $data);
	}

	public function test_pwa_manifest_has_correct_static_values(): void {
		$request = new WP_REST_Request('GET', '/saso-eventtickets/v1/pwa-manifest');
		$response = SASO_EVENTTICKETS::rest_pwa_manifest($request);
		$data = $response->get_data();

		$this->assertEquals('Ticket Scanner', $data['name']);
		$this->assertEquals('Scanner', $data['short_name']);
		$this->assertEquals('standalone', $data['display']);
		$this->assertEquals('portrait', $data['orientation']);
		$this->assertEquals('#ffffff', $data['background_color']);
	}

	public function test_pwa_manifest_icons_contain_two_sizes(): void {
		$request = new WP_REST_Request('GET', '/saso-eventtickets/v1/pwa-manifest');
		$response = SASO_EVENTTICKETS::rest_pwa_manifest($request);
		$data = $response->get_data();

		$this->assertCount(2, $data['icons']);
		$this->assertEquals('192x192', $data['icons'][0]['sizes']);
		$this->assertEquals('512x512', $data['icons'][1]['sizes']);
		$this->assertEquals('image/png', $data['icons'][0]['type']);
		$this->assertEquals('image/png', $data['icons'][1]['type']);
	}

	public function test_pwa_manifest_uses_custom_theme_color(): void {
		update_option('sasoEventticketsticketScannerThemeColor', '#ff0000');
		$this->main->getOptions()->initOptions();

		$request = new WP_REST_Request('GET', '/saso-eventtickets/v1/pwa-manifest');
		$response = SASO_EVENTTICKETS::rest_pwa_manifest($request);
		$data = $response->get_data();

		$this->assertEquals('#ff0000', $data['theme_color']);

		// Reset
		update_option('sasoEventticketsticketScannerThemeColor', '');
		$this->main->getOptions()->initOptions();
	}

	public function test_pwa_manifest_uses_default_theme_color_when_empty(): void {
		update_option('sasoEventticketsticketScannerThemeColor', '');
		$this->main->getOptions()->initOptions();

		$request = new WP_REST_Request('GET', '/saso-eventtickets/v1/pwa-manifest');
		$response = SASO_EVENTTICKETS::rest_pwa_manifest($request);
		$data = $response->get_data();

		$this->assertEquals('#2e74b5', $data['theme_color']);
	}

	public function test_pwa_manifest_start_url_contains_scanner(): void {
		$request = new WP_REST_Request('GET', '/saso-eventtickets/v1/pwa-manifest');
		$response = SASO_EVENTTICKETS::rest_pwa_manifest($request);
		$data = $response->get_data();

		$this->assertStringContainsString('scanner/', $data['start_url']);
	}

	public function test_pwa_manifest_content_type_header(): void {
		$request = new WP_REST_Request('GET', '/saso-eventtickets/v1/pwa-manifest');
		$response = SASO_EVENTTICKETS::rest_pwa_manifest($request);
		$headers = $response->get_headers();

		$this->assertArrayHasKey('Content-Type', $headers);
		$this->assertEquals('application/manifest+json', $headers['Content-Type']);
	}

	// ── session_set_value / session_get_value ────────────────────

	public function test_session_round_trip_stores_and_retrieves_value(): void {
		if (!class_exists('WC_Product_Simple')) {
			$this->markTestSkipped('WooCommerce not available');
		}

		$frontend = $this->main->getWC()->getFrontendManager();

		// Use reflection to access protected methods
		$setRef = new ReflectionMethod($frontend, 'session_set_value');
		$setRef->setAccessible(true);
		$getRef = new ReflectionMethod($frontend, 'session_get_value');
		$getRef->setAccessible(true);

		$result = $setRef->invoke($frontend, 'test_key_123', 'hello_world');
		$this->assertTrue($result);

		$value = $getRef->invoke($frontend, 'test_key_123');
		$this->assertEquals('hello_world', $value);

		// Clean up
		WC()->session->__unset('sasoEventtickets_test_key_123');
	}

	public function test_session_set_value_uses_correct_prefix(): void {
		if (!class_exists('WC_Product_Simple')) {
			$this->markTestSkipped('WooCommerce not available');
		}

		$frontend = $this->main->getWC()->getFrontendManager();
		$setRef = new ReflectionMethod($frontend, 'session_set_value');
		$setRef->setAccessible(true);

		$setRef->invoke($frontend, 'prefix_test', 'value123');

		// Verify value is stored with prefix
		$stored = WC()->session->get('sasoEventtickets_prefix_test');
		$this->assertEquals('value123', $stored);

		// Clean up
		WC()->session->__unset('sasoEventtickets_prefix_test');
	}

	public function test_session_get_value_returns_null_for_missing_key(): void {
		if (!class_exists('WC_Product_Simple')) {
			$this->markTestSkipped('WooCommerce not available');
		}

		$frontend = $this->main->getWC()->getFrontendManager();
		$getRef = new ReflectionMethod($frontend, 'session_get_value');
		$getRef->setAccessible(true);

		$value = $getRef->invoke($frontend, 'nonexistent_key_' . uniqid());
		$this->assertNull($value);
	}

	public function test_session_stores_array_value(): void {
		if (!class_exists('WC_Product_Simple')) {
			$this->markTestSkipped('WooCommerce not available');
		}

		$frontend = $this->main->getWC()->getFrontendManager();
		$setRef = new ReflectionMethod($frontend, 'session_set_value');
		$setRef->setAccessible(true);
		$getRef = new ReflectionMethod($frontend, 'session_get_value');
		$getRef->setAccessible(true);

		$arrayValue = ['date' => '2026-03-01', 'seat' => 'A1'];
		$setRef->invoke($frontend, 'array_test', $arrayValue);

		$result = $getRef->invoke($frontend, 'array_test');
		$this->assertEquals($arrayValue, $result);

		// Clean up
		WC()->session->__unset('sasoEventtickets_array_test');
	}

	// ── isOrderPaid ─────────────────────────────────────────────

	public function test_isOrderPaid_true_for_completed_order(): void {
		if (!class_exists('WC_Product_Simple')) {
			$this->markTestSkipped('WooCommerce not available');
		}

		$order = wc_create_order();
		$order->set_status('completed');
		$order->save();

		$this->assertTrue(SASO_EVENTTICKETS::isOrderPaid($order));
	}

	public function test_isOrderPaid_true_for_processing_order(): void {
		if (!class_exists('WC_Product_Simple')) {
			$this->markTestSkipped('WooCommerce not available');
		}

		$order = wc_create_order();
		$order->set_status('processing');
		$order->save();

		$this->assertTrue(SASO_EVENTTICKETS::isOrderPaid($order));
	}

	public function test_isOrderPaid_false_for_pending_order(): void {
		if (!class_exists('WC_Product_Simple')) {
			$this->markTestSkipped('WooCommerce not available');
		}

		$order = wc_create_order();
		$order->set_status('pending');
		$order->save();

		$this->assertFalse(SASO_EVENTTICKETS::isOrderPaid($order));
	}

	public function test_isOrderPaid_false_for_null(): void {
		$this->assertFalse(SASO_EVENTTICKETS::isOrderPaid(null));
	}

	public function test_isOrderPaid_false_for_non_object(): void {
		$this->assertFalse(SASO_EVENTTICKETS::isOrderPaid('not_an_order'));
	}

	public function test_isOrderPaid_false_for_wrong_object_type(): void {
		$obj = new stdClass();
		$this->assertFalse(SASO_EVENTTICKETS::isOrderPaid($obj));
	}

	// ── getPluginVersions ───────────────────────────────────────

	public function test_getPluginVersions_returns_array_with_basic_key(): void {
		$versions = $this->main->getPluginVersions();

		$this->assertIsArray($versions);
		$this->assertArrayHasKey('basic', $versions);
		$this->assertEquals(SASO_EVENTTICKETS_PLUGIN_VERSION, $versions['basic']);
	}

	public function test_getPluginVersions_has_premium_key(): void {
		$versions = $this->main->getPluginVersions();

		$this->assertArrayHasKey('premium', $versions);
		// Premium may be empty if premium plugin not active
		$this->assertIsString($versions['premium']);
	}

	public function test_getPluginVersions_has_debug_key(): void {
		$versions = $this->main->getPluginVersions();

		$this->assertArrayHasKey('debug', $versions);
	}
}
