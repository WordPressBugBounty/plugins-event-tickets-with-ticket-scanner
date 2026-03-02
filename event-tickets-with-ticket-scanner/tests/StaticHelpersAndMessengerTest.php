<?php
/**
 * Batch 37 — Static helpers (SASO_EVENTTICKETS) and Messenger type validation:
 * - SASO_EVENTTICKETS::time(): deprecated but backward-compatible timestamp
 * - SASO_EVENTTICKETS::date(): deprecated but backward-compatible date formatting
 * - SASO_EVENTTICKETS::is_assoc_array(): detect associative arrays
 * - SASO_EVENTTICKETS::sanitize_date_from_datepicker(): safe date sanitization
 * - SASO_EVENTTICKETS::isOrderPaid(): order payment status check
 * - SASO_EVENTTICKETS::getMediaData(): WP media data retrieval
 * - SASO_EVENTTICKETS::getRequestPara(): request parameter retrieval
 * - SASO_EVENTTICKETS::PasswortGenerieren(): random password generation
 * - Messenger::sendMessage / sendFile type validation
 * - Options::resetAllOptionValuesToDefault (isolated)
 * - Options::deleteAllOptionValues (isolated)
 */

class StaticHelpersAndMessengerTest extends WP_UnitTestCase {

	private $main;

	public function set_up(): void {
		parent::set_up();
		$this->main = sasoEventtickets::Instance();
	}

	// ── SASO_EVENTTICKETS::time() ─────────────────────────────

	public function test_time_returns_positive_integer(): void {
		$t = SASO_EVENTTICKETS::time();
		$this->assertIsInt($t);
		$this->assertGreaterThan(0, $t);
	}

	public function test_time_close_to_current_time(): void {
		$t = SASO_EVENTTICKETS::time();
		$this->assertEqualsWithDelta(time(), $t, 86400); // within 24h (timezone)
	}

	// ── SASO_EVENTTICKETS::date() ─────────────────────────────

	public function test_date_returns_formatted_string(): void {
		$result = SASO_EVENTTICKETS::date('Y-m-d');
		$this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $result);
	}

	public function test_date_with_timestamp(): void {
		$ts = mktime(12, 0, 0, 6, 15, 2025);
		$result = SASO_EVENTTICKETS::date('Y-m-d', $ts);
		$this->assertEquals('2025-06-15', $result);
	}

	public function test_date_with_custom_timezone(): void {
		$tz = new DateTimeZone('UTC');
		$result = SASO_EVENTTICKETS::date('Y', 0, $tz);
		$this->assertIsString($result);
	}

	// ── SASO_EVENTTICKETS::is_assoc_array() ───────────────────

	public function test_is_assoc_array_true_for_associative(): void {
		$this->assertTrue(SASO_EVENTTICKETS::is_assoc_array(['key' => 'value']));
	}

	public function test_is_assoc_array_false_for_sequential(): void {
		$this->assertFalse(SASO_EVENTTICKETS::is_assoc_array(['a', 'b', 'c']));
	}

	public function test_is_assoc_array_true_for_empty(): void {
		$this->assertTrue(SASO_EVENTTICKETS::is_assoc_array([]));
	}

	public function test_is_assoc_array_false_for_non_array(): void {
		$this->assertFalse(SASO_EVENTTICKETS::is_assoc_array('not an array'));
	}

	public function test_is_assoc_array_true_for_mixed_keys(): void {
		$this->assertTrue(SASO_EVENTTICKETS::is_assoc_array([0 => 'a', 'key' => 'b']));
	}

	// ── SASO_EVENTTICKETS::sanitize_date_from_datepicker() ────

	public function test_sanitize_date_valid(): void {
		$this->assertEquals('2026-03-15', SASO_EVENTTICKETS::sanitize_date_from_datepicker('2026-03-15'));
	}

	public function test_sanitize_date_strips_extra_chars(): void {
		$this->assertEquals('2026-03-15', SASO_EVENTTICKETS::sanitize_date_from_datepicker('2026-03-15T12:00:00'));
	}

	public function test_sanitize_date_invalid_returns_empty(): void {
		$this->assertEquals('', SASO_EVENTTICKETS::sanitize_date_from_datepicker('not-a-date'));
	}

	public function test_sanitize_date_empty_returns_empty(): void {
		$this->assertEquals('', SASO_EVENTTICKETS::sanitize_date_from_datepicker(''));
	}

	public function test_sanitize_date_partial_returns_empty(): void {
		$this->assertEquals('', SASO_EVENTTICKETS::sanitize_date_from_datepicker('2026-03'));
	}

	public function test_sanitize_date_sql_injection_blocked(): void {
		$this->assertEquals('', SASO_EVENTTICKETS::sanitize_date_from_datepicker("'; DROP TABLE--"));
	}

	// ── SASO_EVENTTICKETS::isOrderPaid() ──────────────────────

	public function test_isOrderPaid_true_for_completed(): void {
		if (!class_exists('WC_Order')) {
			$this->markTestSkipped('WooCommerce not available');
		}
		$order = wc_create_order();
		$order->set_status('completed');
		$order->save();

		$this->assertTrue(SASO_EVENTTICKETS::isOrderPaid($order));
	}

	public function test_isOrderPaid_true_for_processing(): void {
		if (!class_exists('WC_Order')) {
			$this->markTestSkipped('WooCommerce not available');
		}
		$order = wc_create_order();
		$order->set_status('processing');
		$order->save();

		$this->assertTrue(SASO_EVENTTICKETS::isOrderPaid($order));
	}

	public function test_isOrderPaid_false_for_pending(): void {
		if (!class_exists('WC_Order')) {
			$this->markTestSkipped('WooCommerce not available');
		}
		$order = wc_create_order();
		$order->set_status('pending');
		$order->save();

		$this->assertFalse(SASO_EVENTTICKETS::isOrderPaid($order));
	}

	public function test_isOrderPaid_false_for_refunded(): void {
		if (!class_exists('WC_Order')) {
			$this->markTestSkipped('WooCommerce not available');
		}
		$order = wc_create_order();
		$order->set_status('refunded');
		$order->save();

		$this->assertFalse(SASO_EVENTTICKETS::isOrderPaid($order));
	}

	// ── SASO_EVENTTICKETS::PasswortGenerieren() ───────────────

	public function test_PasswortGenerieren_default_length(): void {
		$pw = SASO_EVENTTICKETS::PasswortGenerieren();
		$this->assertEquals(8, strlen($pw));
	}

	public function test_PasswortGenerieren_custom_length(): void {
		$pw = SASO_EVENTTICKETS::PasswortGenerieren(16);
		$this->assertEquals(16, strlen($pw));
	}

	public function test_PasswortGenerieren_unique(): void {
		$pw1 = SASO_EVENTTICKETS::PasswortGenerieren(20);
		$pw2 = SASO_EVENTTICKETS::PasswortGenerieren(20);
		$this->assertNotEquals($pw1, $pw2);
	}

	// ── SASO_EVENTTICKETS::getMediaData() ─────────────────────

	public function test_getMediaData_returns_array_for_zero(): void {
		$result = SASO_EVENTTICKETS::getMediaData(0);
		$this->assertIsArray($result);
		$this->assertArrayHasKey('title', $result);
		$this->assertArrayHasKey('url', $result);
		$this->assertArrayHasKey('location', $result);
	}

	public function test_getMediaData_returns_array_for_nonexistent(): void {
		$result = SASO_EVENTTICKETS::getMediaData(999999);
		$this->assertIsArray($result);
		$this->assertEmpty($result['title']);
	}

	// ── SASO_EVENTTICKETS::getRequestPara() ───────────────────

	public function test_getRequestPara_returns_default(): void {
		$result = SASO_EVENTTICKETS::getRequestPara('nonexistent_param_xyz', 'mydefault');
		$this->assertEquals('mydefault', $result);
	}

	public function test_getRequestPara_returns_null_default(): void {
		$result = SASO_EVENTTICKETS::getRequestPara('nonexistent_param_abc');
		$this->assertNull($result);
	}

	// ── SASO_EVENTTICKETS::getRESTPrefixURL() ─────────────────

	public function test_getRESTPrefixURL_returns_string(): void {
		$url = SASO_EVENTTICKETS::getRESTPrefixURL();
		$this->assertIsString($url);
		$this->assertNotEmpty($url);
	}

	public function test_getRESTPrefixURL_contains_plugin_slug(): void {
		$url = SASO_EVENTTICKETS::getRESTPrefixURL();
		$this->assertStringContainsString('event-tickets', $url);
	}

	// ── Messenger type validation ─────────────────────────────

	private function getMessenger(): sasoEventtickets_Messenger {
		// Messenger is lazy-loaded, ensure class file is included
		$file = dirname(__DIR__) . '/sasoEventtickets_Messenger.php';
		if (!class_exists('sasoEventtickets_Messenger') && file_exists($file)) {
			require_once $file;
		}
		return sasoEventtickets_Messenger::Instance();
	}

	public function test_messenger_sendMessage_invalid_type_throws(): void {
		$messenger = $this->getMessenger();
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessageMatches('/#2007/');
		$messenger->sendMessage('test', '123', 'sms');
	}

	public function test_messenger_sendMessage_null_type_throws(): void {
		$messenger = $this->getMessenger();
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessageMatches('/#2006/');
		$messenger->sendMessage('test', '123', null);
	}

	public function test_messenger_sendFile_invalid_type_throws(): void {
		$messenger = $this->getMessenger();
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessageMatches('/#2007/');
		$messenger->sendFile('/tmp/test.pdf', 'test', '123', 'email');
	}

	public function test_messenger_sendFile_null_type_throws(): void {
		$messenger = $this->getMessenger();
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessageMatches('/#2006/');
		$messenger->sendFile('/tmp/test.pdf', 'test', '123', null);
	}

	// ── Options resetAllOptionValuesToDefault (isolated) ──────

	public function test_resetAllOptionValuesToDefault_restores_defaults(): void {
		$options = $this->main->getOptions();

		// Change a text option to a known value
		$options->changeOption(['key' => 'wcTicketHeading', 'value' => 'CHANGED_' . uniqid()]);
		$options->initOptions();

		// Verify it changed
		$changed = $options->getOptionValue('wcTicketHeading');
		$this->assertStringStartsWith('CHANGED_', $changed);

		// Reset all to defaults
		$result = $options->resetAllOptionValuesToDefault();
		$this->assertTrue($result);

		// Re-init and check that wcTicketHeading is back to default
		$options->initOptions();
		$restored = $options->getOptionValue('wcTicketHeading');
		$this->assertStringNotContainsString('CHANGED_', $restored);
	}

	// ── Options deleteAllOptionValues ─────────────────────────

	public function test_deleteAllOptionValues_removes_all(): void {
		$options = $this->main->getOptions();

		// Ensure at least one option has a value
		$options->changeOption(['key' => 'wcTicketHeading', 'value' => 'ToBeDeleted']);

		$result = $options->deleteAllOptionValues();
		$this->assertTrue($result);

		// After delete, the options array should be empty or all removed from WP
		// Re-init to reload from wp_options (which are now gone)
		$options->initOptions();
		$val = $options->getOptionValue('wcTicketHeading');
		// Should fall back to default since wp_options row was deleted
		$option = $options->getOption('wcTicketHeading');
		if ($option !== null) {
			$this->assertNotEquals('ToBeDeleted', $val);
		}
	}
}
