<?php
/**
 * Tests for the Setup Wizard (Phase 2 of #187).
 *
 * Tests:
 * - getWizardPresetDefaults() returns correct preset values
 * - getWizardPresetPremiumDefaults() returns premium-only values
 * - applyWizardPreset() sets correct options for each use-case
 * - applyWizardPreset() with overrides changes specific values
 * - applyWizardPreset() sets wizardCompleted to plugin version
 * - applyWizardPreset() with invalid preset throws exception
 * - All preset option keys exist in the options system
 */

class SetupWizardTest extends WP_UnitTestCase {

	private sasoEventtickets_AdminSettings $admin;

	public function set_up(): void {
		parent::set_up();
		$user_id = self::factory()->user->create(['role' => 'administrator']);
		wp_set_current_user($user_id);
		$this->admin = sasoEventtickets::Instance()->getAdmin();
	}

	/**
	 * Call applyWizardPreset via executeJSON (same path as real AJAX).
	 */
	private function callApplyPreset(string $preset, array $overrides = []): array {
		$data = ['preset' => $preset];
		if (!empty($overrides)) {
			$data['overrides'] = json_encode($overrides);
		}
		return $this->admin->executeJSON('applyWizardPreset', $data, true, true);
	}

	// ── getWizardPresetDefaults ─────────────────────────────────────

	public function test_getWizardPresetDefaults_event_returns_array(): void {
		$defaults = sasoEventtickets_AdminSettings::getWizardPresetDefaults('event');
		$this->assertIsArray($defaults);
		$this->assertNotEmpty($defaults);
	}

	public function test_getWizardPresetDefaults_daypass_returns_array(): void {
		$defaults = sasoEventtickets_AdminSettings::getWizardPresetDefaults('daypass');
		$this->assertIsArray($defaults);
		$this->assertNotEmpty($defaults);
	}

	public function test_getWizardPresetDefaults_membership_returns_array(): void {
		$defaults = sasoEventtickets_AdminSettings::getWizardPresetDefaults('membership');
		$this->assertIsArray($defaults);
		$this->assertNotEmpty($defaults);
	}

	public function test_getWizardPresetDefaults_voucher_returns_array(): void {
		$defaults = sasoEventtickets_AdminSettings::getWizardPresetDefaults('voucher');
		$this->assertIsArray($defaults);
		$this->assertNotEmpty($defaults);
	}

	public function test_getWizardPresetDefaults_invalid_returns_empty(): void {
		$defaults = sasoEventtickets_AdminSettings::getWizardPresetDefaults('nonexistent');
		$this->assertEmpty($defaults);
	}

	public function test_all_presets_have_same_keys(): void {
		$presets = ['event', 'daypass', 'membership', 'voucher'];
		$keys = null;
		foreach ($presets as $preset) {
			$defaults = sasoEventtickets_AdminSettings::getWizardPresetDefaults($preset);
			$currentKeys = array_keys($defaults);
			sort($currentKeys);
			if ($keys === null) {
				$keys = $currentKeys;
			} else {
				$this->assertSame($keys, $currentKeys, "Preset '$preset' has different keys than the first preset.");
			}
		}
	}

	public function test_all_preset_keys_exist_in_options_system(): void {
		$defaults = sasoEventtickets_AdminSettings::getWizardPresetDefaults('event');
		$optionsKeys = sasoEventtickets::Instance()->getOptions()->getOptionsKeys();
		foreach (array_keys($defaults) as $key) {
			$this->assertContains($key, $optionsKeys, "Preset key '$key' does not exist in the options system.");
		}
	}

	// ── Event preset values ─────────────────────────────────────────

	public function test_event_preset_locks_before_start(): void {
		$defaults = sasoEventtickets_AdminSettings::getWizardPresetDefaults('event');
		$this->assertSame(1, $defaults['wcTicketDontAllowRedeemTicketBeforeStart']);
	}

	public function test_event_preset_auto_redeems(): void {
		$defaults = sasoEventtickets_AdminSettings::getWizardPresetDefaults('event');
		$this->assertSame(1, $defaults['ticketScannerScanAndRedeemImmediately']);
	}

	public function test_event_preset_no_after_end(): void {
		$defaults = sasoEventtickets_AdminSettings::getWizardPresetDefaults('event');
		$this->assertSame(0, $defaults['wcTicketAllowRedeemTicketAfterEnd']);
	}

	public function test_event_preset_enables_ics_and_date(): void {
		$defaults = sasoEventtickets_AdminSettings::getWizardPresetDefaults('event');
		$this->assertSame(1, $defaults['wcTicketAttachICSToMail']);
		$this->assertSame(1, $defaults['wcTicketDisplayDateOnMail']);
	}

	public function test_event_preset_auto_completes_order(): void {
		$defaults = sasoEventtickets_AdminSettings::getWizardPresetDefaults('event');
		$this->assertSame(1, $defaults['wcTicketSetOrderToCompleteIfAllOrderItemsAreTickets']);
	}

	// ── Day pass preset values ──────────────────────────────────────

	public function test_daypass_preset_no_lock_before_start(): void {
		$defaults = sasoEventtickets_AdminSettings::getWizardPresetDefaults('daypass');
		$this->assertSame(0, $defaults['wcTicketDontAllowRedeemTicketBeforeStart']);
	}

	public function test_daypass_preset_allows_after_end(): void {
		$defaults = sasoEventtickets_AdminSettings::getWizardPresetDefaults('daypass');
		$this->assertSame(1, $defaults['wcTicketAllowRedeemTicketAfterEnd']);
	}

	public function test_daypass_preset_no_ics_no_date(): void {
		$defaults = sasoEventtickets_AdminSettings::getWizardPresetDefaults('daypass');
		$this->assertSame(0, $defaults['wcTicketAttachICSToMail']);
		$this->assertSame(0, $defaults['wcTicketDisplayDateOnMail']);
	}

	public function test_daypass_preset_enables_order_view(): void {
		$defaults = sasoEventtickets_AdminSettings::getWizardPresetDefaults('daypass');
		$this->assertSame(1, $defaults['wcTicketDisplayOrderTicketsViewLinkOnMail']);
		$this->assertSame(1, $defaults['wcTicketDisplayOrderTicketsViewLinkOnCheckout']);
	}

	// ── Membership preset values ─────────────────────────────────────

	public function test_membership_preset_shows_redeem_counter(): void {
		$defaults = sasoEventtickets_AdminSettings::getWizardPresetDefaults('membership');
		$this->assertSame(1, $defaults['wcTicketUserProfileDisplayRedeemAmount']);
	}

	public function test_membership_preset_shows_redeem_btn(): void {
		$defaults = sasoEventtickets_AdminSettings::getWizardPresetDefaults('membership');
		$this->assertSame(1, $defaults['wcTicketShowRedeemBtnOnTicket']);
	}

	public function test_membership_preset_no_auto_redeem(): void {
		$defaults = sasoEventtickets_AdminSettings::getWizardPresetDefaults('membership');
		$this->assertSame(0, $defaults['ticketScannerScanAndRedeemImmediately']);
	}

	public function test_membership_preset_no_order_view(): void {
		$defaults = sasoEventtickets_AdminSettings::getWizardPresetDefaults('membership');
		$this->assertSame(0, $defaults['wcTicketDisplayOrderTicketsViewLinkOnMail']);
		$this->assertSame(0, $defaults['wcTicketDisplayOrderTicketsViewLinkOnCheckout']);
	}

	// ── Voucher preset values ───────────────────────────────────────

	public function test_voucher_preset_self_redeem(): void {
		$defaults = sasoEventtickets_AdminSettings::getWizardPresetDefaults('voucher');
		$this->assertSame(1, $defaults['wcTicketShowRedeemBtnOnTicket']);
	}

	public function test_voucher_preset_no_order_view(): void {
		$defaults = sasoEventtickets_AdminSettings::getWizardPresetDefaults('voucher');
		$this->assertSame(0, $defaults['wcTicketDisplayOrderTicketsViewLinkOnMail']);
	}

	// ── All presets auto-complete ────────────────────────────────────

	public function test_all_presets_auto_complete_order(): void {
		foreach (['event', 'daypass', 'membership', 'voucher'] as $preset) {
			$defaults = sasoEventtickets_AdminSettings::getWizardPresetDefaults($preset);
			$this->assertSame(1, $defaults['wcTicketSetOrderToCompleteIfAllOrderItemsAreTickets'],
				"Preset '$preset' should auto-complete orders.");
		}
	}

	// ── Premium preset defaults ─────────────────────────────────────

	public function test_premium_presets_exist_for_all_usecases(): void {
		foreach (['event', 'daypass', 'membership', 'voucher'] as $preset) {
			$premiumDefaults = sasoEventtickets_AdminSettings::getWizardPresetPremiumDefaults($preset);
			$this->assertIsArray($premiumDefaults);
			$this->assertNotEmpty($premiumDefaults, "Premium preset for '$preset' should not be empty.");
		}
	}

	public function test_premium_presets_invalid_returns_empty(): void {
		$this->assertEmpty(sasoEventtickets_AdminSettings::getWizardPresetPremiumDefaults('nonexistent'));
	}

	public function test_event_premium_preset_attaches_as_one_pdf(): void {
		$premiumDefaults = sasoEventtickets_AdminSettings::getWizardPresetPremiumDefaults('event');
		$this->assertSame(1, $premiumDefaults['wcTicketAttachTicketToMail']);
		$this->assertSame(1, $premiumDefaults['wcTicketAttachTicketToMailAsOnePDF']);
	}

	public function test_membership_premium_preset_no_merge(): void {
		$premiumDefaults = sasoEventtickets_AdminSettings::getWizardPresetPremiumDefaults('membership');
		$this->assertSame(1, $premiumDefaults['wcTicketAttachTicketToMail']);
		$this->assertSame(0, $premiumDefaults['wcTicketAttachTicketToMailAsOnePDF']);
	}

	public function test_all_premium_presets_have_same_keys(): void {
		$keys = null;
		foreach (['event', 'daypass', 'membership', 'voucher'] as $preset) {
			$defaults = sasoEventtickets_AdminSettings::getWizardPresetPremiumDefaults($preset);
			$currentKeys = array_keys($defaults);
			sort($currentKeys);
			if ($keys === null) {
				$keys = $currentKeys;
			} else {
				$this->assertSame($keys, $currentKeys, "Premium preset '$preset' has different keys.");
			}
		}
	}

	// ── applyWizardPreset via executeJSON ─────────────────────────────

	public function test_applyWizardPreset_event_returns_correct_count(): void {
		$result = $this->callApplyPreset('event');
		$this->assertIsArray($result);
		$this->assertArrayHasKey('applied', $result);
		$this->assertArrayHasKey('preset', $result);
		$this->assertSame('event', $result['preset']);
		// 13 base options (free, no premium in test env)
		$this->assertSame(13, $result['applied']);
	}

	public function test_applyWizardPreset_event_sets_options_correctly(): void {
		$this->callApplyPreset('event');
		$options = sasoEventtickets::Instance()->getOptions();
		$this->assertTrue($options->isOptionCheckboxActive('wcTicketDontAllowRedeemTicketBeforeStart'));
		$this->assertTrue($options->isOptionCheckboxActive('ticketScannerScanAndRedeemImmediately'));
		$this->assertFalse($options->isOptionCheckboxActive('wcTicketAllowRedeemTicketAfterEnd'));
		$this->assertFalse($options->isOptionCheckboxActive('wcTicketUserProfileDisplayRedeemAmount'));
		$this->assertTrue($options->isOptionCheckboxActive('wcTicketSetOrderToCompleteIfAllOrderItemsAreTickets'));
		$this->assertTrue($options->isOptionCheckboxActive('wcTicketAttachICSToMail'));
	}

	public function test_applyWizardPreset_daypass_sets_options_correctly(): void {
		$this->callApplyPreset('daypass');
		$options = sasoEventtickets::Instance()->getOptions();
		$this->assertFalse($options->isOptionCheckboxActive('wcTicketDontAllowRedeemTicketBeforeStart'));
		$this->assertTrue($options->isOptionCheckboxActive('wcTicketAllowRedeemTicketAfterEnd'));
		$this->assertTrue($options->isOptionCheckboxActive('ticketScannerScanAndRedeemImmediately'));
		$this->assertTrue($options->isOptionCheckboxActive('wcTicketDisplayOrderTicketsViewLinkOnCheckout'));
		$this->assertFalse($options->isOptionCheckboxActive('wcTicketAttachICSToMail'));
	}

	public function test_applyWizardPreset_membership_sets_options_correctly(): void {
		$this->callApplyPreset('membership');
		$options = sasoEventtickets::Instance()->getOptions();
		$this->assertTrue($options->isOptionCheckboxActive('wcTicketUserProfileDisplayRedeemAmount'));
		$this->assertTrue($options->isOptionCheckboxActive('wcTicketShowRedeemBtnOnTicket'));
		$this->assertFalse($options->isOptionCheckboxActive('ticketScannerScanAndRedeemImmediately'));
		$this->assertFalse($options->isOptionCheckboxActive('wcTicketDisplayOrderTicketsViewLinkOnMail'));
	}

	public function test_applyWizardPreset_voucher_sets_options_correctly(): void {
		$this->callApplyPreset('voucher');
		$options = sasoEventtickets::Instance()->getOptions();
		$this->assertTrue($options->isOptionCheckboxActive('ticketScannerScanAndRedeemImmediately'));
		$this->assertTrue($options->isOptionCheckboxActive('wcTicketShowRedeemBtnOnTicket'));
		$this->assertFalse($options->isOptionCheckboxActive('wcTicketDisplayOrderTicketsViewLinkOnMail'));
		$this->assertFalse($options->isOptionCheckboxActive('wcTicketDisplayOrderTicketsViewLinkOnCheckout'));
	}

	// ── wizardCompleted ─────────────────────────────────────────────

	public function test_applyWizardPreset_sets_wizardCompleted(): void {
		$this->callApplyPreset('event');
		$val = sasoEventtickets::Instance()->getOptions()->getOptionValue('wizardCompleted');
		$this->assertSame(sasoEventtickets::Instance()->getPluginVersion(), $val);
	}

	public function test_wizardCompleted_option_exists(): void {
		$optionsKeys = sasoEventtickets::Instance()->getOptions()->getOptionsKeys();
		$this->assertContains('wizardCompleted', $optionsKeys);
	}

	// ── Overrides ───────────────────────────────────────────────────

	public function test_applyWizardPreset_with_overrides(): void {
		$this->callApplyPreset('event', ['wcTicketDontAllowRedeemTicketBeforeStart' => 0]);
		$options = sasoEventtickets::Instance()->getOptions();
		$this->assertFalse($options->isOptionCheckboxActive('wcTicketDontAllowRedeemTicketBeforeStart'));
		$this->assertTrue($options->isOptionCheckboxActive('ticketScannerScanAndRedeemImmediately'));
	}

	public function test_applyWizardPreset_ignores_invalid_override_keys(): void {
		$result = $this->callApplyPreset('event', ['nonexistentKey' => 1]);
		$this->assertSame(13, $result['applied']);
	}

	// ── Error handling ──────────────────────────────────────────────

	public function test_applyWizardPreset_invalid_preset_throws(): void {
		$this->expectException(\Exception::class);
		$this->callApplyPreset('nonexistent');
	}

	public function test_applyWizardPreset_empty_preset_throws(): void {
		$this->expectException(\Exception::class);
		$this->callApplyPreset('');
	}

	// ── applyWizardPreset in sensitive_actions ───────────────────────

	public function test_applyWizardPreset_requires_admin(): void {
		$sub_id = self::factory()->user->create(['role' => 'subscriber']);
		wp_set_current_user($sub_id);
		$this->expectException(\Exception::class);
		$this->callApplyPreset('event');
	}

	// ── Premium Wizard (#233) ──────────────────────────────────────

	public function test_premiumWizardCompleted_option_exists(): void {
		$optionsKeys = sasoEventtickets::Instance()->getOptions()->getOptionsKeys();
		$this->assertContains('premiumWizardCompleted', $optionsKeys);
	}

	public function test_applyPremiumDefaults_throws_without_premium(): void {
		// Test env has no premium → should throw
		$this->expectException(\Exception::class);
		$this->admin->executeJSON('applyPremiumDefaults', [], true, true);
	}

	public function test_applyPremiumDefaults_requires_admin(): void {
		$sub_id = self::factory()->user->create(['role' => 'subscriber']);
		wp_set_current_user($sub_id);
		$this->expectException(\Exception::class);
		$this->admin->executeJSON('applyPremiumDefaults', [], true, true);
	}

	// ── checkPremiumUpdate ─────────────────────────────────────────

	public function test_checkPremiumUpdate_without_old_premium_returns_no_update(): void {
		$result = $this->admin->executeJSON('checkPremiumUpdate', [], true, true);
		$this->assertIsArray($result);
		$this->assertArrayHasKey('hasUpdate', $result);
		$this->assertFalse($result['hasUpdate']);
	}

	public function test_checkPremiumUpdate_requires_admin(): void {
		$sub_id = self::factory()->user->create(['role' => 'subscriber']);
		wp_set_current_user($sub_id);
		$this->expectException(\Exception::class);
		$this->admin->executeJSON('checkPremiumUpdate', [], true, true);
	}

	public function test_checkPremiumUpdate_is_in_sensitive_actions(): void {
		// Verify it's protected by checking that a subscriber cannot call it
		$sub_id = self::factory()->user->create(['role' => 'subscriber']);
		wp_set_current_user($sub_id);
		$threw = false;
		try {
			$this->admin->executeJSON('checkPremiumUpdate', [], true, true);
		} catch (\Exception $e) {
			$threw = true;
		}
		$this->assertTrue($threw, 'checkPremiumUpdate should be restricted to admins.');
	}

	// ── isOldPremiumDetected in getOptions ─────────────────────────

	public function test_getOptions_contains_isOldPremiumDetected(): void {
		set_current_screen('dashboard');
		$result = $this->admin->executeJSON('getOptions', [], true, true);
		$this->assertIsArray($result);
		$this->assertArrayHasKey('versions', $result);
		$this->assertArrayHasKey('isOldPremiumDetected', $result['versions']);
	}

	// ── recheckLicense ────────────────────────────────────────────

	public function test_recheckLicense_returns_expected_keys(): void {
		$result = $this->admin->executeJSON('recheckLicense', [], true, true);
		$this->assertIsArray($result);
		$this->assertArrayHasKey('active', $result);
		$this->assertArrayHasKey('last_success', $result);
		$this->assertArrayHasKey('consecutive_failures', $result);
		$this->assertArrayHasKey('expiration_date', $result);
		$this->assertArrayHasKey('timestamp', $result);
		$this->assertArrayHasKey('subscription_type', $result);
		$this->assertArrayHasKey('notvalid', $result);
	}

	public function test_recheckLicense_active_is_bool(): void {
		$result = $this->admin->executeJSON('recheckLicense', [], true, true);
		$this->assertIsBool($result['active']);
	}

	public function test_recheckLicense_numeric_fields_are_int(): void {
		$result = $this->admin->executeJSON('recheckLicense', [], true, true);
		$this->assertIsInt($result['last_success']);
		$this->assertIsInt($result['consecutive_failures']);
		$this->assertIsInt($result['timestamp']);
		$this->assertIsInt($result['notvalid']);
	}

	public function test_recheckLicense_requires_admin(): void {
		$sub_id = self::factory()->user->create(['role' => 'subscriber']);
		wp_set_current_user($sub_id);
		$this->expectException(\Exception::class);
		$this->admin->executeJSON('recheckLicense', [], true, true);
	}

	public function test_recheckLicense_is_in_sensitive_actions(): void {
		$sub_id = self::factory()->user->create(['role' => 'subscriber']);
		wp_set_current_user($sub_id);
		$threw = false;
		try {
			$this->admin->executeJSON('recheckLicense', [], true, true);
		} catch (\Exception $e) {
			$threw = true;
		}
		$this->assertTrue($threw, 'recheckLicense should be restricted to admins.');
	}

	// ── expose_desctables ────────────────────────────────────────

	public function test_expose_desctables_returns_tables_key(): void {
		$result = $this->admin->executeJSON('expose_desctables', [], true, true);
		$this->assertIsArray($result);
		$this->assertArrayHasKey('tables', $result);
	}

	public function test_expose_desctables_contains_all_plugin_tables(): void {
		$result = $this->admin->executeJSON('expose_desctables', [], true, true);
		$tables = $result['tables'];
		$expected = ['lists', 'codes', 'ips', 'authtokens', 'errorlogs', 'seatingplans', 'seats', 'seat_blocks'];
		foreach ($expected as $table) {
			$this->assertArrayHasKey($table, $tables, "Table '$table' missing from expose output");
		}
	}

	public function test_expose_desctables_each_table_has_required_fields(): void {
		$result = $this->admin->executeJSON('expose_desctables', [], true, true);
		foreach ($result['tables'] as $name => $info) {
			$this->assertArrayHasKey('full_name', $info, "Table '$name' missing full_name");
			$this->assertArrayHasKey('row_count', $info, "Table '$name' missing row_count");
			$this->assertArrayHasKey('columns', $info, "Table '$name' missing columns");
			$this->assertIsInt($info['row_count'], "Table '$name' row_count should be int");
			$this->assertIsArray($info['columns'], "Table '$name' columns should be array");
		}
	}

	public function test_expose_desctables_full_name_contains_prefix(): void {
		$result = $this->admin->executeJSON('expose_desctables', [], true, true);
		foreach ($result['tables'] as $name => $info) {
			$this->assertStringContainsString('saso_eventtickets_', $info['full_name'], "Table '$name' full_name should contain plugin prefix");
			$this->assertStringContainsString($name, $info['full_name'], "Table '$name' full_name should contain table key");
		}
	}

	public function test_expose_desctables_row_count_is_non_negative(): void {
		$result = $this->admin->executeJSON('expose_desctables', [], true, true);
		foreach ($result['tables'] as $name => $info) {
			$this->assertGreaterThanOrEqual(0, $info['row_count'], "Table '$name' row_count should be >= 0");
		}
	}

	public function test_expose_desctables_requires_admin(): void {
		$sub_id = self::factory()->user->create(['role' => 'subscriber']);
		wp_set_current_user($sub_id);
		$threw = false;
		try {
			$this->admin->executeJSON('expose_desctables', [], true, true);
		} catch (\Exception $e) {
			$threw = true;
		}
		$this->assertTrue($threw, 'expose_desctables should be restricted to admins.');
	}
}
