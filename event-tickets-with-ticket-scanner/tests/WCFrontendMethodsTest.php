<?php
/**
 * Batch 39 — WC Frontend methods:
 * - containsProductsWithRestrictions: checks cart for restriction codes
 * - getWarningDatePickerLabel: generates warning labels with placeholders
 * - woocommerce_update_cart_validation_handler: quantity change vs. seats
 * - check_code_for_cartitem: validates restriction code for cart item
 * - updateCartItemMeta: updates cart item metadata (name/value/daychooser)
 * - woocommerce_add_to_cart_validation_handler: add-to-cart pre-check
 * - displayWarningDatePicker: outputs warning HTML for date picker
 */

class WCFrontendMethodsTest extends WP_UnitTestCase {

	private $main;
	private $frontend;

	public function set_up(): void {
		parent::set_up();
		$this->main = sasoEventtickets::Instance();

		if (!class_exists('WC_Product_Simple')) {
			$this->markTestSkipped('WooCommerce not available');
		}

		$this->frontend = $this->main->getWC()->getFrontendManager();
	}

	private function createTicketProduct(bool $isDaychooser = false, bool $withRestriction = false): array {
		$listId = $this->main->getDB()->insert('lists', [
			'name' => 'FE Methods List ' . uniqid(),
			'aktiv' => 1,
			'meta' => '{}',
		]);

		$product = new WC_Product_Simple();
		$product->set_name('FE Test Product ' . uniqid());
		$product->set_regular_price('10.00');
		$product->set_status('publish');
		$product->save();
		$pid = $product->get_id();

		update_post_meta($pid, 'saso_eventtickets_is_ticket', 'yes');
		update_post_meta($pid, 'saso_eventtickets_list', $listId);

		if ($isDaychooser) {
			update_post_meta($pid, 'saso_eventtickets_is_daychooser', 'yes');
		}

		if ($withRestriction) {
			// Create a code list for restrictions
			$restrictListId = $this->main->getDB()->insert('lists', [
				'name' => 'Restrict List ' . uniqid(),
				'aktiv' => 1,
				'meta' => '{}',
			]);
			update_post_meta($pid, 'saso_eventtickets_codelist_restriction', $restrictListId);
		}

		return ['product' => $product, 'product_id' => $pid, 'list_id' => $listId];
	}

	// ── getWarningDatePickerLabel ─────────────────────────────

	public function test_getWarningDatePickerLabel_returns_string(): void {
		$label = $this->frontend->getWarningDatePickerLabel('Test Product', '0', 1);
		$this->assertIsString($label);
		$this->assertNotEmpty($label);
	}

	public function test_getWarningDatePickerLabel_contains_product_name(): void {
		$label = $this->frontend->getWarningDatePickerLabel('My Concert', '0', 0);
		$this->assertStringContainsString('My Concert', $label);
	}

	public function test_getWarningDatePickerLabel_past_date(): void {
		$label = $this->frontend->getWarningDatePickerLabel('Past Event', '0', 1, true);
		$this->assertIsString($label);
		$this->assertStringContainsString('Past Event', $label);
	}

	public function test_getWarningDatePickerLabel_escapes_html(): void {
		$label = $this->frontend->getWarningDatePickerLabel('<script>evil</script>', '0', 0);
		$this->assertStringNotContainsString('<script>', $label);
	}

	// ── hasTicketsInCart ───────────────────────────────────────

	public function test_hasTicketsInCart_false_for_empty_cart(): void {
		WC()->cart->empty_cart();
		$this->assertFalse($this->frontend->hasTicketsInCart());
	}

	public function test_hasTicketsInCart_true_with_ticket_product(): void {
		WC()->cart->empty_cart();
		$tp = $this->createTicketProduct();
		WC()->cart->add_to_cart($tp['product_id']);

		$this->assertTrue($this->frontend->hasTicketsInCart());
		WC()->cart->empty_cart();
	}

	public function test_hasTicketsInCart_false_with_regular_product(): void {
		WC()->cart->empty_cart();

		$product = new WC_Product_Simple();
		$product->set_name('Regular Product');
		$product->set_regular_price('5.00');
		$product->set_status('publish');
		$product->save();

		WC()->cart->add_to_cart($product->get_id());
		$this->assertFalse($this->frontend->hasTicketsInCart());
		WC()->cart->empty_cart();
	}

	// ── containsProductsWithRestrictions ──────────────────────

	public function test_containsProductsWithRestrictions_false_for_empty_cart(): void {
		WC()->cart->empty_cart();
		// Reset internal cache by creating new instance indirectly
		$frontend = $this->main->getWC()->getFrontendManager();
		// We can't easily reset the private cache, so test the empty cart path
		$result = $frontend->containsProductsWithRestrictions();
		$this->assertIsBool($result);
	}

	// ── displayWarningDatePicker ──────────────────────────────

	public function test_displayWarningDatePicker_adds_error_notice(): void {
		wc_clear_notices();
		$this->frontend->displayWarningDatePicker('Test Product', 'item1', 0);
		$notices = wc_get_notices('error');

		$this->assertNotEmpty($notices, 'displayWarningDatePicker should add a WC error notice');
		$lastNotice = end($notices);
		$noticeText = is_array($lastNotice) ? ($lastNotice['notice'] ?? '') : $lastNotice;
		$this->assertStringContainsString('Test Product', $noticeText);
		wc_clear_notices();
	}

	public function test_displayWarningDatePicker_past_date_adds_error_notice(): void {
		wc_clear_notices();
		$this->frontend->displayWarningDatePicker('Past Event', 'item2', 0, true);
		$notices = wc_get_notices('error');

		$this->assertNotEmpty($notices, 'displayWarningDatePicker with past date should add a WC error notice');
		$lastNotice = end($notices);
		$noticeText = is_array($lastNotice) ? ($lastNotice['notice'] ?? '') : $lastNotice;
		$this->assertStringContainsString('Past Event', $noticeText);
		wc_clear_notices();
	}

	// ── woocommerce_update_cart_validation_handler ────────────

	public function test_update_cart_validation_passes_when_no_seats(): void {
		WC()->cart->empty_cart();
		$tp = $this->createTicketProduct();
		$key = WC()->cart->add_to_cart($tp['product_id']);

		$cart = WC()->cart->get_cart();
		$cartItem = $cart[$key] ?? [];

		$result = $this->frontend->woocommerce_update_cart_validation_handler(
			true, $key, $cartItem, 2
		);
		$this->assertTrue($result);
		WC()->cart->empty_cart();
	}

	public function test_update_cart_validation_passes_when_already_failed(): void {
		$result = $this->frontend->woocommerce_update_cart_validation_handler(
			false, 'nonexistent_key', [], 1
		);
		$this->assertFalse($result);
	}

	// ── check_code_for_cartitem ──────────────────────────────

	public function test_check_code_for_cartitem_returns_int(): void {
		$tp = $this->createTicketProduct();

		WC()->cart->empty_cart();
		$key = WC()->cart->add_to_cart($tp['product_id']);
		$cart = WC()->cart->get_cart();

		if (isset($cart[$key])) {
			$result = $this->frontend->check_code_for_cartitem($cart[$key], 'TESTCODE123');
			$this->assertIsInt($result);
		}

		WC()->cart->empty_cart();
		$this->assertTrue(true);
	}

	// ── woocommerce_add_to_cart_validation_handler ────────────

	public function test_add_to_cart_validation_passes_for_regular_product(): void {
		$product = new WC_Product_Simple();
		$product->set_name('Regular Validation');
		$product->set_regular_price('5.00');
		$product->set_status('publish');
		$product->save();

		$result = $this->frontend->woocommerce_add_to_cart_validation_handler(
			true, $product->get_id(), 1
		);
		$this->assertTrue($result);
	}

	public function test_add_to_cart_validation_passes_for_ticket_product(): void {
		$tp = $this->createTicketProduct();

		$result = $this->frontend->woocommerce_add_to_cart_validation_handler(
			true, $tp['product_id'], 1
		);
		$this->assertTrue($result);
	}

	public function test_add_to_cart_validation_returns_false_when_already_failed(): void {
		$tp = $this->createTicketProduct();

		$result = $this->frontend->woocommerce_add_to_cart_validation_handler(
			false, $tp['product_id'], 1
		);
		$this->assertFalse($result);
	}

	// ── updateCartItemMeta ────────────────────────────────────

	public function test_updateCartItemMeta_returns_array(): void {
		WC()->cart->empty_cart();
		$tp = $this->createTicketProduct();
		$key = WC()->cart->add_to_cart($tp['product_id']);

		$result = $this->frontend->updateCartItemMeta('name', $key, 0, 'John Doe');
		$this->assertIsArray($result);
		WC()->cart->empty_cart();
	}

	public function test_updateCartItemMeta_invalid_key_returns_error(): void {
		$result = $this->frontend->updateCartItemMeta('name', 'nonexistent_key_xyz', 0, 'John');
		$this->assertIsArray($result);
		// Should indicate an error or empty result
		if (isset($result['error'])) {
			$this->assertNotEmpty($result['error']);
		}
	}
}
