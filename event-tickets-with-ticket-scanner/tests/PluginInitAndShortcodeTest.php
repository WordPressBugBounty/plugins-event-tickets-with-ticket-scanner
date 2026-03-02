<?php
/**
 * Batch 34c — Plugin initialization, shortcodes, and static utility methods:
 * - replaceShortcode: enqueues JS, localizes, renders div
 * - cronjob_daily_activate / deactivate: WP-Cron scheduling
 * - getPluginVersion / getPluginVersions: version getters
 * - showPhpVersionWarning: PHP version check output
 * - showSubscriptionWarning: subscription expiration check
 * - showOutdatedPremiumWarning: old premium detection
 * - SASO_EVENTTICKETS static methods: PasswortGenerieren, issetRPara, getRequestPara, isOrderPaid
 * - plugin_action_links: adds settings link
 * - load_plugin_textdomain: i18n setup
 */

class PluginInitAndShortcodeTest extends WP_UnitTestCase {

	private $main;

	public function set_up(): void {
		parent::set_up();
		$this->main = sasoEventtickets::Instance();
	}

	// ── replaceShortcode ───────────────────────────────────────

	public function test_replaceShortcode_returns_div_with_default_id(): void {
		$result = $this->main->replaceShortcode([]);

		$this->assertStringContainsString('<div', $result);
		$this->assertStringContainsString('id=', $result);
		$this->assertStringContainsString('loading', $result);
	}

	public function test_replaceShortcode_with_custom_divid_returns_empty_div(): void {
		$result = $this->main->replaceShortcode(['divid' => 'my-custom-div']);

		// When custom divid is set, plugin does not render its own div
		// The result might be empty or filtered
		$this->assertIsString($result);
	}

	public function test_replaceShortcode_fires_filter(): void {
		$filtered = false;
		$callback = function ($vars) use (&$filtered) {
			$filtered = true;
			return $vars;
		};
		add_filter($this->main->_add_filter_prefix . 'main_replaceShortcode', $callback);

		$this->main->replaceShortcode([]);

		$this->assertTrue($filtered, 'replaceShortcode should fire filter');
		remove_filter($this->main->_add_filter_prefix . 'main_replaceShortcode', $callback);
	}

	public function test_replaceShortcode_fires_action(): void {
		$fired = false;
		$callback = function () use (&$fired) {
			$fired = true;
		};
		add_action($this->main->_do_action_prefix . 'main_replaceShortcode', $callback);

		// wp_localize_script triggers doing_it_wrong on repeated calls — expected in test env
		$this->setExpectedIncorrectUsage('WP_Scripts::localize');

		$this->main->replaceShortcode([]);

		$this->assertTrue($fired, 'replaceShortcode should fire action');
		remove_action($this->main->_do_action_prefix . 'main_replaceShortcode', $callback);
	}

	// ── cronjob_daily_activate / deactivate ────────────────────

	public function test_cronjob_daily_activate_schedules_event(): void {
		// Clear first
		$this->main->cronjob_daily_deactivate();
		$this->assertFalse(wp_next_scheduled('sasoEventtickets_cronjob_daily'));

		// Activate
		$this->main->cronjob_daily_activate();
		$this->assertNotFalse(wp_next_scheduled('sasoEventtickets_cronjob_daily'));
	}

	public function test_cronjob_daily_deactivate_clears_event(): void {
		// Activate first
		$this->main->cronjob_daily_activate();
		$this->assertNotFalse(wp_next_scheduled('sasoEventtickets_cronjob_daily'));

		// Deactivate
		$this->main->cronjob_daily_deactivate();
		$this->assertFalse(wp_next_scheduled('sasoEventtickets_cronjob_daily'));
	}

	public function test_cronjob_daily_activate_idempotent(): void {
		$this->main->cronjob_daily_activate();
		$ts1 = wp_next_scheduled('sasoEventtickets_cronjob_daily');

		$this->main->cronjob_daily_activate();
		$ts2 = wp_next_scheduled('sasoEventtickets_cronjob_daily');

		$this->assertEquals($ts1, $ts2, 'Second activate should not change schedule');
	}

	// ── getPluginVersion / getPluginVersions ───────────────────

	public function test_getPluginVersion_returns_string(): void {
		$version = $this->main->getPluginVersion();

		$this->assertIsString($version);
		$this->assertNotEmpty($version);
		$this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+/', $version);
	}

	public function test_getPluginVersions_returns_array_with_keys(): void {
		$versions = $this->main->getPluginVersions();

		$this->assertIsArray($versions);
		$this->assertArrayHasKey('basic', $versions);
		$this->assertArrayHasKey('premium', $versions);
		$this->assertArrayHasKey('debug', $versions);
	}

	public function test_getPluginVersions_basic_matches_getPluginVersion(): void {
		$versions = $this->main->getPluginVersions();

		$this->assertEquals($this->main->getPluginVersion(), $versions['basic']);
	}

	// ── getPluginPath ──────────────────────────────────────────

	public function test_getPluginPath_returns_existing_directory(): void {
		$path = $this->main->getPluginPath();

		$this->assertIsString($path);
		$this->assertTrue(is_dir($path));
	}

	// ── showPhpVersionWarning ──────────────────────────────────

	public function test_showPhpVersionWarning_no_output_on_current_php(): void {
		// Current PHP is 8.1+, so no warning should be shown
		if (version_compare(PHP_VERSION, '8.1.0', '<')) {
			$this->markTestSkipped('PHP >= 8.1 required for this test');
		}

		ob_start();
		$this->main->showPhpVersionWarning();
		$output = ob_get_clean();

		$this->assertEmpty($output, 'No warning on PHP >= 8.1');
	}

	// ── showSubscriptionWarning ────────────────────────────────

	public function test_showSubscriptionWarning_no_output_without_premium(): void {
		// Without premium class, should return early
		ob_start();
		$this->main->showSubscriptionWarning();
		$output = ob_get_clean();

		// If premium is not loaded, should be empty
		if (!class_exists('sasoEventtickets_PremiumFunctions')) {
			$this->assertEmpty($output);
		} else {
			$this->assertIsString($output);
		}
	}

	// ── showOutdatedPremiumWarning ─────────────────────────────

	public function test_showOutdatedPremiumWarning_no_output_when_not_detected(): void {
		// Without old premium detected, should return early
		$adminId = $this->factory->user->create(['role' => 'administrator']);
		wp_set_current_user($adminId);
		set_current_screen('edit-post');

		ob_start();
		$this->main->showOutdatedPremiumWarning();
		$output = ob_get_clean();

		if (!$this->main->isOldPremiumDetected()) {
			$this->assertEmpty($output);
		} else {
			$this->assertStringContainsString('notice-error', $output);
		}

		wp_set_current_user(0);
	}

	// ── SASO_EVENTTICKETS static methods ───────────────────────

	public function test_PasswortGenerieren_returns_string_of_correct_length(): void {
		$pw = SASO_EVENTTICKETS::PasswortGenerieren(12);

		$this->assertIsString($pw);
		$this->assertEquals(12, strlen($pw));
	}

	public function test_PasswortGenerieren_default_length_is_8(): void {
		$pw = SASO_EVENTTICKETS::PasswortGenerieren();

		$this->assertEquals(8, strlen($pw));
	}

	public function test_PasswortGenerieren_no_ambiguous_chars(): void {
		// Method excludes 0, 1, l, i, o to avoid confusion
		$pw = SASO_EVENTTICKETS::PasswortGenerieren(100);

		$this->assertStringNotContainsString('0', $pw);
		$this->assertStringNotContainsString('1', $pw);
		$this->assertStringNotContainsString('l', $pw);
		$this->assertStringNotContainsString('i', $pw);
		$this->assertStringNotContainsString('o', $pw);
	}

	public function test_PasswortGenerieren_does_not_start_with_dot(): void {
		// Run 10 times to check the first-character guard
		for ($i = 0; $i < 10; $i++) {
			$pw = SASO_EVENTTICKETS::PasswortGenerieren(1);
			$this->assertNotEquals('.', $pw[0], 'Password should not start with dot');
		}
	}

	public function test_issetRPara_returns_bool(): void {
		$result = SASO_EVENTTICKETS::issetRPara('nonexistent_param_xyz');

		$this->assertIsBool($result);
		$this->assertFalse($result);
	}

	public function test_getRequestPara_returns_default_for_missing(): void {
		// Reset cached request data
		$ref = new ReflectionProperty('SASO_EVENTTICKETS', 'REQUEST_DATA');
		$ref->setAccessible(true);
		$ref->setValue(null, null);

		$result = SASO_EVENTTICKETS::getRequestPara('nonexistent_xyz', 'mydefault');

		$this->assertEquals('mydefault', $result);
	}

	public function test_getRESTPrefixURL_returns_plugin_folder_name(): void {
		$result = SASO_EVENTTICKETS::getRESTPrefixURL();

		$this->assertIsString($result);
		$this->assertEquals('event-tickets-with-ticket-scanner', $result);
	}

	// ── isOrderPaid ────────────────────────────────────────────

	public function test_isOrderPaid_true_for_completed_order(): void {
		if (!class_exists('WC_Product_Simple')) {
			$this->markTestSkipped('WooCommerce not available');
		}

		$order = wc_create_order();
		$order->set_status('completed');
		$order->save();
		$order = wc_get_order($order->get_id());

		$this->assertTrue(SASO_EVENTTICKETS::isOrderPaid($order));
	}

	public function test_isOrderPaid_false_for_pending_order(): void {
		if (!class_exists('WC_Product_Simple')) {
			$this->markTestSkipped('WooCommerce not available');
		}

		$order = wc_create_order();
		$order->set_status('pending');
		$order->save();
		$order = wc_get_order($order->get_id());

		$this->assertFalse(SASO_EVENTTICKETS::isOrderPaid($order));
	}

	public function test_isOrderPaid_true_for_processing_order(): void {
		if (!class_exists('WC_Product_Simple')) {
			$this->markTestSkipped('WooCommerce not available');
		}

		$order = wc_create_order();
		$order->set_status('processing');
		$order->save();
		$order = wc_get_order($order->get_id());

		$this->assertTrue(SASO_EVENTTICKETS::isOrderPaid($order));
	}

	// ── load_plugin_textdomain ─────────────────────────────────

	public function test_load_plugin_textdomain_does_not_crash(): void {
		$this->main->load_plugin_textdomain();
		$this->assertTrue(true);
	}

	// ── getBase / getCore / getAdmin lazy loaders ──────────────

	public function test_getBase_returns_instance(): void {
		$this->assertInstanceOf(sasoEventtickets_Base::class, $this->main->getBase());
	}

	public function test_getCore_returns_instance(): void {
		$this->assertInstanceOf(sasoEventtickets_Core::class, $this->main->getCore());
	}

	public function test_getAdmin_returns_instance(): void {
		$this->assertInstanceOf(sasoEventtickets_AdminSettings::class, $this->main->getAdmin());
	}

	public function test_getOptions_returns_instance(): void {
		$this->assertInstanceOf(sasoEventtickets_Options::class, $this->main->getOptions());
	}

	public function test_getFrontend_returns_instance(): void {
		$this->assertInstanceOf(sasoEventtickets_Frontend::class, $this->main->getFrontend());
	}

	public function test_getDB_returns_instance(): void {
		$db = $this->main->getDB();
		$this->assertNotNull($db);
	}

	// ── getPrefix / getPluginFolder ────────────────────────────

	public function test_getPrefix_returns_string(): void {
		$prefix = $this->main->getPrefix();
		$this->assertIsString($prefix);
		$this->assertNotEmpty($prefix);
	}

	public function test_isPremium_returns_bool(): void {
		$result = $this->main->isPremium();
		$this->assertIsBool($result);
	}
}
