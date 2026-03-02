<?php
/**
 * Batch 55 — WC Frontend handlers:
 * - addJSFileAndHandler: script enqueue
 * - containsProductsWithRestrictions: restriction detection
 * - getWarningDatePickerLabel: label generation
 * - addDatepickerHTML: datepicker output
 * - woocommerce_add_cart_item_data_handler: cart item data
 * - woocommerce_add_to_cart_validation_handler: validation
 * - woocommerce_after_shop_loop_item_handler: shop loop
 * - woocommerce_before_add_to_cart_button_handler: product page
 */

class WCFrontendHandlersTest extends WP_UnitTestCase {

	private $main;

	public function set_up(): void {
		parent::set_up();
		$this->main = sasoEventtickets::Instance();

		if (!class_exists('WC_Product_Simple')) {
			$this->markTestSkipped('WooCommerce not available');
		}
	}

	private function createTicketProduct(array $meta = []): int {
		$product = new WC_Product_Simple();
		$product->set_name('WCFrontend Test ' . uniqid());
		$product->set_regular_price('20.00');
		$product->set_status('publish');
		$product->save();
		$pid = $product->get_id();

		update_post_meta($pid, 'saso_eventtickets_is_ticket', 'yes');
		update_post_meta($pid, 'saso_eventtickets_ticket_start_date', '2026-12-25');

		foreach ($meta as $key => $value) {
			update_post_meta($pid, $key, $value);
		}

		return $pid;
	}

	// ── addJSFileAndHandler ─────────────────────────────────────

	public function test_addJSFileAndHandler_enqueues_script(): void {
		$frontend = $this->main->getWC()->getFrontendManager();
		$frontend->addJSFileAndHandler();

		$this->assertTrue(
			wp_script_is('SasoEventticketsValidator_WC_frontend', 'enqueued')
		);
	}

	public function test_addJSFileAndHandler_with_additional_values(): void {
		$frontend = $this->main->getWC()->getFrontendManager();
		$frontend->addJSFileAndHandler(['custom_key' => 'custom_value']);

		$this->assertTrue(
			wp_script_is('SasoEventticketsValidator_WC_frontend', 'enqueued')
		);
	}

	// ── containsProductsWithRestrictions ─────────────────────────

	public function test_containsProductsWithRestrictions_empty_cart(): void {
		// Ensure cart is empty
		WC()->cart->empty_cart();

		$frontend = $this->main->getWC()->getFrontendManager();
		// Reset cached value via reflection
		$ref = new ReflectionProperty($frontend, '_containsProductsWithRestrictions');
		$ref->setAccessible(true);
		$ref->setValue($frontend, null);

		$this->assertFalse($frontend->containsProductsWithRestrictions());
	}

	public function test_containsProductsWithRestrictions_with_restriction(): void {
		// Create product with restriction
		$listId = $this->main->getDB()->insert('lists', [
			'name' => 'Restriction List ' . uniqid(),
			'aktiv' => 1,
			'meta' => '{}',
		]);

		$pid = $this->createTicketProduct([
			'saso_eventtickets_list_sale_restriction' => $listId,
		]);

		WC()->cart->empty_cart();
		WC()->cart->add_to_cart($pid);

		$frontend = $this->main->getWC()->getFrontendManager();
		$ref = new ReflectionProperty($frontend, '_containsProductsWithRestrictions');
		$ref->setAccessible(true);
		$ref->setValue($frontend, null);

		$this->assertTrue($frontend->containsProductsWithRestrictions());

		WC()->cart->empty_cart();
	}

	// ── getWarningDatePickerLabel ────────────────────────────────

	public function test_getWarningDatePickerLabel_returns_string(): void {
		$frontend = $this->main->getWC()->getFrontendManager();
		$result = $frontend->getWarningDatePickerLabel('Test Product', '0', 1);
		$this->assertIsString($result);
	}

	public function test_getWarningDatePickerLabel_contains_product_name(): void {
		$frontend = $this->main->getWC()->getFrontendManager();
		$result = $frontend->getWarningDatePickerLabel('My Concert', '0', 1);
		$this->assertStringContainsString('My Concert', $result);
	}

	public function test_getWarningDatePickerLabel_in_the_past(): void {
		$frontend = $this->main->getWC()->getFrontendManager();
		$result = $frontend->getWarningDatePickerLabel('Old Event', '0', 1, true);
		$this->assertIsString($result);
		$this->assertNotEmpty($result);
	}

	public function test_getWarningDatePickerLabel_different_index(): void {
		$frontend = $this->main->getWC()->getFrontendManager();
		$result0 = $frontend->getWarningDatePickerLabel('Test', '0', 0);
		$result1 = $frontend->getWarningDatePickerLabel('Test', '0', 4);
		// Both should produce valid strings; label format depends on option
		$this->assertIsString($result0);
		$this->assertIsString($result1);
		$this->assertNotEmpty($result0);
	}

	// ── addDatepickerHTML ───────────────────────────────────────

	public function test_addDatepickerHTML_outputs_html(): void {
		$pid = $this->createTicketProduct([
			'saso_eventtickets_is_daychooser' => 'yes',
			'saso_eventtickets_ticket_start_date' => '2026-06-01',
			'saso_eventtickets_ticket_end_date' => '2026-06-30',
		]);

		$frontend = $this->main->getWC()->getFrontendManager();

		ob_start();
		$frontend->addDatepickerHTML('test_key', 0, $pid, '', null, null, null, 'field_name');
		$output = ob_get_clean();

		$this->assertStringContainsString('data-input-type', $output);
		$this->assertStringContainsString('daychooser', $output);
	}

	public function test_addDatepickerHTML_contains_product_id(): void {
		$pid = $this->createTicketProduct([
			'saso_eventtickets_is_daychooser' => 'yes',
			'saso_eventtickets_ticket_start_date' => '2026-06-01',
			'saso_eventtickets_ticket_end_date' => '2026-06-30',
		]);

		$frontend = $this->main->getWC()->getFrontendManager();

		ob_start();
		$frontend->addDatepickerHTML('test_key', 0, $pid, '', null, null, null, 'field_name');
		$output = ob_get_clean();

		$this->assertStringContainsString((string) $pid, $output);
	}

	public function test_addDatepickerHTML_with_custom_attributes(): void {
		$pid = $this->createTicketProduct([
			'saso_eventtickets_is_daychooser' => 'yes',
			'saso_eventtickets_ticket_start_date' => '2026-06-01',
			'saso_eventtickets_ticket_end_date' => '2026-06-30',
		]);

		$frontend = $this->main->getWC()->getFrontendManager();

		ob_start();
		$frontend->addDatepickerHTML('test_key', 0, $pid, '', null, null, null, 'field_name', ['data-custom' => 'val']);
		$output = ob_get_clean();

		$this->assertStringContainsString('data-custom', $output);
	}

	// ── woocommerce_add_cart_item_data_handler ───────────────────

	public function test_add_cart_item_data_handler_returns_array(): void {
		$frontend = $this->main->getWC()->getFrontendManager();
		$result = $frontend->woocommerce_add_cart_item_data_handler([], 1);
		$this->assertIsArray($result);
	}

	public function test_add_cart_item_data_handler_preserves_existing_data(): void {
		$frontend = $this->main->getWC()->getFrontendManager();
		$result = $frontend->woocommerce_add_cart_item_data_handler(
			['existing_key' => 'existing_value'],
			1
		);
		$this->assertArrayHasKey('existing_key', $result);
		$this->assertEquals('existing_value', $result['existing_key']);
	}

	// ── woocommerce_add_to_cart_validation_handler ───────────────

	public function test_add_to_cart_validation_handler_passes_by_default(): void {
		$pid = $this->createTicketProduct();

		$frontend = $this->main->getWC()->getFrontendManager();
		$result = $frontend->woocommerce_add_to_cart_validation_handler(true, $pid, 1);
		$this->assertTrue($result);
	}

	public function test_add_to_cart_validation_handler_respects_false(): void {
		$pid = $this->createTicketProduct();

		$frontend = $this->main->getWC()->getFrontendManager();
		$result = $frontend->woocommerce_add_to_cart_validation_handler(false, $pid, 1);
		$this->assertFalse($result);
	}

	// ── woocommerce_after_shop_loop_item_handler ────────────────

	public function test_after_shop_loop_item_handler_returns_for_non_shop(): void {
		// Not on a shop page, should return early
		$frontend = $this->main->getWC()->getFrontendManager();

		ob_start();
		$frontend->woocommerce_after_shop_loop_item_handler();
		$output = ob_get_clean();

		// Should produce no output (returns early)
		$this->assertEmpty($output);
	}

	// ── woocommerce_before_add_to_cart_button_handler ────────────

	public function test_before_add_to_cart_button_handler_returns_for_non_product(): void {
		// Not on a product page, should return early
		$frontend = $this->main->getWC()->getFrontendManager();

		ob_start();
		$frontend->woocommerce_before_add_to_cart_button_handler();
		$output = ob_get_clean();

		$this->assertEmpty($output);
	}
}
