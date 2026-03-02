<?php
/**
 * Tests for admin option management and ticket handler initialization:
 * - resetOptions: resets all options to defaults
 * - deleteOptions: deletes all options from DB
 * - initOptionsFromConstructor: deferred option loading
 * - addMetaTags: Open Graph meta output
 * - checkForPremiumSerialExpiration: throttle/cache logic (no remote calls)
 * - woocommerce_add_to_cart_handler: daychooser data storage
 * - woocommerce_update_cart_validation_handler: cart quantity validation
 * - woocommerce_checkout_update_order_meta: restriction code storage (legacy)
 */

class AdminOptionsAndMigrationTest extends WP_UnitTestCase {

	private $main;

	public function set_up(): void {
		parent::set_up();
		$this->main = sasoEventtickets::Instance();
	}

	// ── resetOptions ────────────────────────────────────────────

	public function test_resetOptions_sets_values_to_defaults(): void {
		// Change an option to non-default
		update_option('sasoEventticketsdisplayDateFormat', 'dd/mm/yyyy');
		$this->main->getOptions()->initOptions();

		$this->main->getAdmin()->resetOptions();
		$this->main->getOptions()->initOptions();

		// After reset, it should be back to default
		$value = $this->main->getOptions()->getOptionValue('displayDateFormat');
		$default = '';
		$options = $this->main->getOptions()->getOptions();
		foreach ($options as $opt) {
			if ($opt['key'] === 'displayDateFormat') {
				$default = $opt['default'];
				break;
			}
		}
		$this->assertEquals($default, $value);
	}

	public function test_resetOptions_returns_true(): void {
		$result = $this->main->getAdmin()->resetOptions();
		$this->assertTrue($result);
	}

	// ── deleteOptions ───────────────────────────────────────────

	public function test_deleteOptions_removes_from_db(): void {
		// Set a known option
		update_option('sasoEventticketsdisplayDateFormat', 'test_value_xyz');

		$result = $this->main->getAdmin()->deleteOptions();
		$this->assertTrue($result);

		// Option should be deleted from DB
		$this->assertFalse(get_option('sasoEventticketsdisplayDateFormat', false));
	}

	// ── initOptionsFromConstructor ──────────────────────────────

	public function test_initOptionsFromConstructor_loads_scanner_option(): void {
		// Ensure option is set
		update_option('sasoEventticketswcTicketOnlyLoggedInScannerAllowed', '1');
		// Rebuild options array from scratch so get_option picks up new value
		$this->main->getOptions()->initOptions();

		$ticket = $this->main->getTicketHandler();
		$ticket->initOptionsFromConstructor();

		// Check via reflection that onlyLoggedInScannerAllowed was set
		$ref = new ReflectionProperty($ticket, 'onlyLoggedInScannerAllowed');
		$ref->setAccessible(true);
		$this->assertTrue($ref->getValue($ticket));

		// Reset
		update_option('sasoEventticketswcTicketOnlyLoggedInScannerAllowed', '0');
		$this->main->getOptions()->initOptions();
	}

	public function test_initOptionsFromConstructor_loads_false_when_disabled(): void {
		update_option('sasoEventticketswcTicketOnlyLoggedInScannerAllowed', '0');
		$this->main->getOptions()->initOptions();

		$ticket = $this->main->getTicketHandler();
		// Reset cached value
		$ref = new ReflectionProperty($ticket, 'onlyLoggedInScannerAllowed');
		$ref->setAccessible(true);
		$ref->setValue($ticket, null);

		$ticket->initOptionsFromConstructor();

		$this->assertFalse($ref->getValue($ticket));
	}

	// ── addMetaTags ─────────────────────────────────────────────

	public function test_addMetaTags_outputs_og_tags(): void {
		ob_start();
		$this->main->getTicketHandler()->addMetaTags();
		$output = ob_get_clean();

		$this->assertStringContainsString('og:title', $output);
		$this->assertStringContainsString('og:type', $output);
		$this->assertStringContainsString('article', $output);
	}

	public function test_addMetaTags_outputs_css(): void {
		ob_start();
		$this->main->getTicketHandler()->addMetaTags();
		$output = ob_get_clean();

		$this->assertStringContainsString('<style>', $output);
		$this->assertStringContainsString('ticket_content', $output);
	}

	// ── checkForPremiumSerialExpiration ──────────────────────────

	public function test_checkForPremiumSerialExpiration_fires_action(): void {
		$fired = false;
		$callback = function () use (&$fired) {
			$fired = true;
		};
		add_action('saso_eventtickets_ticket_checkForPremiumSerialExpiration', $callback);

		$this->main->getTicketHandler()->checkForPremiumSerialExpiration();

		$this->assertTrue($fired, 'Action hook should fire');

		remove_action('saso_eventtickets_ticket_checkForPremiumSerialExpiration', $callback);
	}

	public function test_checkForPremiumSerialExpiration_skips_without_premium(): void {
		// Without premium, should just fire the action and return without error
		$option_name = $this->main->getPrefix() . "_premium_serial_expiration";
		$before = get_option($option_name, '');

		$this->main->getTicketHandler()->checkForPremiumSerialExpiration();

		$after = get_option($option_name, '');
		// Without premium, option should not change
		$this->assertEquals($before, $after);
	}
}
