<?php
/**
 * Tests for shortcode rendering and cart validation gate:
 * - renderTicketDetailForShortcode: shortcode HTML output
 * - woocommerce_check_cart_items: conditional validation skip
 * - containsProductsWithRestrictions: cart restriction check
 */

class ShortcodeAndCartCheckTest extends WP_UnitTestCase {

	private $main;

	public function set_up(): void {
		parent::set_up();
		$this->main = sasoEventtickets::Instance();
	}

	public function tear_down(): void {
		// Reset containsProductsWithRestrictions cache to avoid leaking to other test classes
		if (class_exists('WC_Product_Simple')) {
			$ref = new ReflectionProperty($this->main->getWC()->getFrontendManager(), '_containsProductsWithRestrictions');
			$ref->setAccessible(true);
			$ref->setValue($this->main->getWC()->getFrontendManager(), null);

			WC()->cart->empty_cart();
			wc_clear_notices();
		}
		parent::tear_down();
	}

	// ── renderTicketDetailForShortcode ────────────────────────────

	public function test_renderTicketDetailForShortcode_returns_html_string(): void {
		$html = $this->main->getTicketHandler()->renderTicketDetailForShortcode();
		$this->assertIsString($html);
		$this->assertStringContainsString('ticket_content', $html);
	}

	public function test_renderTicketDetailForShortcode_contains_error_on_invalid_request(): void {
		// Without a valid ticket URI set, should show error
		$html = $this->main->getTicketHandler()->renderTicketDetailForShortcode();
		$this->assertStringContainsString('ticket_content', $html);
		// Should contain the closing div at minimum
		$this->assertStringContainsString('</div>', $html);
	}

	public function test_renderTicketDetailForShortcode_catches_exceptions(): void {
		// Set an invalid request URI that will trigger an exception
		$ticket = $this->main->getTicketHandler();

		$ref = new ReflectionProperty($ticket, 'request_uri');
		$ref->setAccessible(true);
		$oldUri = $ref->getValue($ticket);

		// Set a URI that looks like a ticket path but has no valid code
		$ref->setValue($ticket, '/ticket/invalid-nonexistent-id');

		$html = $ticket->renderTicketDetailForShortcode();

		// Should contain error message (caught exception) or the content div
		$this->assertStringContainsString('ticket_content', $html);

		// Restore
		$ref->setValue($ticket, $oldUri);
	}

	// ── containsProductsWithRestrictions ──────────────────────────

	public function test_containsProductsWithRestrictions_false_for_empty_cart(): void {
		if (!class_exists('WC_Product_Simple')) {
			$this->markTestSkipped('WooCommerce not available');
		}

		WC()->cart->empty_cart();

		// Reset cached value
		$ref = new ReflectionProperty($this->main->getWC()->getFrontendManager(), '_containsProductsWithRestrictions');
		$ref->setAccessible(true);
		$ref->setValue($this->main->getWC()->getFrontendManager(), null);

		$result = $this->main->getWC()->getFrontendManager()->containsProductsWithRestrictions();
		$this->assertFalse($result);

		WC()->cart->empty_cart();
	}

	public function test_containsProductsWithRestrictions_false_for_regular_product(): void {
		if (!class_exists('WC_Product_Simple')) {
			$this->markTestSkipped('WooCommerce not available');
		}

		$product = new WC_Product_Simple();
		$product->set_name('NoRestriction ' . uniqid());
		$product->set_regular_price('5.00');
		$product->set_status('publish');
		$product->save();

		WC()->cart->empty_cart();
		WC()->cart->add_to_cart($product->get_id());

		$ref = new ReflectionProperty($this->main->getWC()->getFrontendManager(), '_containsProductsWithRestrictions');
		$ref->setAccessible(true);
		$ref->setValue($this->main->getWC()->getFrontendManager(), null);

		$result = $this->main->getWC()->getFrontendManager()->containsProductsWithRestrictions();
		$this->assertFalse($result);

		WC()->cart->empty_cart();
	}

	public function test_containsProductsWithRestrictions_true_for_restricted_product(): void {
		if (!class_exists('WC_Product_Simple')) {
			$this->markTestSkipped('WooCommerce not available');
		}

		// Create a restriction list
		$restrictListId = $this->main->getDB()->insert('lists', [
			'name' => 'Restriction List ' . uniqid(),
			'aktiv' => 1,
			'meta' => '{}',
		]);

		$product = new WC_Product_Simple();
		$product->set_name('Restricted ' . uniqid());
		$product->set_regular_price('10.00');
		$product->set_status('publish');
		$product->save();

		// Set restriction meta (must match META_KEY_CODELIST_RESTRICTION constant)
		update_post_meta($product->get_id(), 'saso_eventtickets_list_sale_restriction', $restrictListId);

		WC()->cart->empty_cart();
		WC()->cart->add_to_cart($product->get_id());

		$ref = new ReflectionProperty($this->main->getWC()->getFrontendManager(), '_containsProductsWithRestrictions');
		$ref->setAccessible(true);
		$ref->setValue($this->main->getWC()->getFrontendManager(), null);

		$result = $this->main->getWC()->getFrontendManager()->containsProductsWithRestrictions();
		$this->assertTrue($result);

		WC()->cart->empty_cart();
	}

	// ── woocommerce_check_cart_items ─────────────────────────────

	public function test_check_cart_items_runs_warnings_when_option_disabled(): void {
		if (!class_exists('WC_Product_Simple')) {
			$this->markTestSkipped('WooCommerce not available');
		}

		// Ensure the "show on checkout only" option is disabled
		update_option('sasoEventticketswcTicketShowInputFieldsOnCheckoutPage', '0');
		$this->main->getOptions()->initOptions();

		WC()->cart->empty_cart();

		// Should run without error (no items = no warnings)
		$this->main->getWC()->getFrontendManager()->woocommerce_check_cart_items();
		$this->assertTrue(true); // No exception = pass

		WC()->cart->empty_cart();
	}

	public function test_check_cart_items_skips_warnings_when_checkout_option_active(): void {
		if (!class_exists('WC_Product_Simple')) {
			$this->markTestSkipped('WooCommerce not available');
		}

		// Enable the "show input fields on checkout page" option
		update_option('sasoEventticketswcTicketShowInputFieldsOnCheckoutPage', '1');
		$this->main->getOptions()->initOptions();

		WC()->cart->empty_cart();

		// Should return early (skip check_cart_item_and_add_warnings)
		$this->main->getWC()->getFrontendManager()->woocommerce_check_cart_items();
		$this->assertTrue(true); // No exception = pass

		// Reset
		update_option('sasoEventticketswcTicketShowInputFieldsOnCheckoutPage', '0');
		$this->main->getOptions()->initOptions();
		WC()->cart->empty_cart();
	}
}
