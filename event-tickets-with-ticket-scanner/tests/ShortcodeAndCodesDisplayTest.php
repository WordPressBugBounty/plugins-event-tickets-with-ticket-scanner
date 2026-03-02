<?php
/**
 * Tests for shortcodes and code display methods:
 * - replacingShortcodeTicketDetail: empty code, attr code, renders output
 * - replacingShortcodeMyCode: dispatches to text/formatted, order_id security
 * - getMyCodeText: shows prefix, download PDF button, empty for no codes
 * - getMyCodeFormatted: JSON output with display filters
 * - getCodesTextAsShortList: HTML table rendering with status labels
 * - isUserAllowedToAccessAdminArea: permission checks
 * - canUserAccessOrder: admin, owner, order key checks
 */

class ShortcodeAndCodesDisplayTest extends WP_UnitTestCase {

	private $main;

	public function set_up(): void {
		parent::set_up();
		$this->main = sasoEventtickets::Instance();
	}

	public function tear_down(): void {
		wp_set_current_user(0);
		parent::tear_down();
	}

	// ── replacingShortcodeTicketDetail ──────────────────────────

	public function test_ticket_detail_shortcode_empty_code_returns_message(): void {
		$result = $this->main->replacingShortcodeTicketDetail([]);

		$this->assertStringContainsString('ticket', strtolower($result));
	}

	public function test_ticket_detail_shortcode_with_attr_code(): void {
		// Create a code in DB
		$listId = $this->main->getDB()->insert('lists', [
			'name' => 'SC Detail ' . uniqid(),
			'aktiv' => 1,
			'meta' => '{}',
		]);
		$code = 'SCDETAIL' . strtoupper(uniqid());
		$this->main->getDB()->insert('codes', [
			'list_id' => $listId,
			'code' => $code,
			'aktiv' => 1,
			'cvv' => '',
			'order_id' => 0,
			'user_id' => 0,
			'meta' => '{}',
		]);

		$result = $this->main->replacingShortcodeTicketDetail(['code' => $code]);

		// Should return some HTML (ticket detail or error, but not the "no code" message)
		$this->assertIsString($result);
	}

	// ── replacingShortcodeMyCode ────────────────────────────────

	public function test_my_code_shortcode_returns_string_for_guest(): void {
		wp_set_current_user(0);

		$result = $this->main->replacingShortcodeMyCode([]);

		$this->assertIsString($result);
	}

	public function test_my_code_shortcode_with_format_returns_json(): void {
		// Create a user with registered codes
		$userId = $this->factory->user->create(['role' => 'subscriber']);
		wp_set_current_user($userId);

		$result = $this->main->replacingShortcodeMyCode(['format' => 'json']);

		$this->assertIsString($result);
		// Should be valid JSON
		$decoded = json_decode($result, true);
		$this->assertIsArray($decoded);
	}

	public function test_my_code_shortcode_with_order_id_denies_unauthorized(): void {
		if (!class_exists('WC_Product_Simple')) {
			$this->markTestSkipped('WooCommerce not available');
		}

		// Create an order belonging to user 1
		$user1 = $this->factory->user->create(['role' => 'subscriber']);
		$user2 = $this->factory->user->create(['role' => 'subscriber']);

		$order = wc_create_order();
		$order->set_customer_id($user1);
		$order->save();

		// Login as user 2 → should be denied
		wp_set_current_user($user2);

		$result = $this->main->replacingShortcodeMyCode(['order_id' => $order->get_id()]);

		$this->assertStringContainsString('permission', strtolower($result));
	}

	// ── getMyCodeText ──────────────────────────────────────────

	public function test_getMyCodeText_empty_for_user_without_codes(): void {
		$userId = $this->factory->user->create(['role' => 'subscriber']);

		$result = $this->main->getMyCodeText($userId);

		$this->assertEmpty($result);
	}

	public function test_getMyCodeText_shows_prefix_when_always_option(): void {
		update_option('sasoEventticketsuserDisplayCodePrefix', 'Your Tickets:');
		update_option('sasoEventticketsuserDisplayCodePrefixAlways', '1');
		$this->main->getOptions()->initOptions();

		$userId = $this->factory->user->create(['role' => 'subscriber']);

		$result = $this->main->getMyCodeText($userId);

		$this->assertStringContainsString('Your Tickets:', $result);
	}

	public function test_getMyCodeText_shows_table_with_codes(): void {
		$userId = $this->factory->user->create(['role' => 'subscriber']);
		$codes = $this->createCodesForUser($userId, 2);

		$result = $this->main->getMyCodeText($userId, [], null, '', $codes);

		$this->assertStringContainsString('<table>', $result);
		$this->assertStringContainsString('</table>', $result);
	}

	public function test_getMyCodeText_download_pdf_button(): void {
		$userId = $this->factory->user->create(['role' => 'subscriber']);
		wp_set_current_user($userId);
		$codes = $this->createCodesForUser($userId, 2);

		$result = $this->main->getMyCodeText($userId, [
			'download_all_pdf' => 'true',
			'download_all_pdf_label' => 'Download All',
		], null, '', $codes);

		$this->assertStringContainsString('Download All', $result);
		$this->assertStringContainsString('(2)', $result);
	}

	public function test_getMyCodeText_download_pdf_too_many_shows_warning(): void {
		$userId = $this->factory->user->create(['role' => 'subscriber']);
		wp_set_current_user($userId);
		$codes = $this->createCodesForUser($userId, 5);

		$result = $this->main->getMyCodeText($userId, [
			'download_all_pdf' => 'true',
			'download_all_pdf_max' => '3',
		], null, '', $codes);

		$this->assertStringContainsString('Too many', $result);
	}

	// ── getMyCodeFormatted ─────────────────────────────────────

	public function test_getMyCodeFormatted_returns_valid_json(): void {
		$userId = $this->factory->user->create(['role' => 'subscriber']);
		$codes = $this->createCodesForUser($userId, 1);

		$result = $this->main->getMyCodeFormatted($userId, ['format' => 'json'], null, '', $codes);

		$decoded = json_decode($result, true);
		$this->assertIsArray($decoded);
		$this->assertArrayHasKey('codes', $decoded);
	}

	public function test_getMyCodeFormatted_respects_display_filter(): void {
		$userId = $this->factory->user->create(['role' => 'subscriber']);
		$codes = $this->createCodesForUser($userId, 1);

		$result = $this->main->getMyCodeFormatted($userId, [
			'format' => 'json',
			'display' => 'codes,confirmedCount',
		], null, '', $codes);

		$decoded = json_decode($result, true);
		$this->assertArrayHasKey('codes', $decoded);
		$this->assertArrayHasKey('confirmedCount', $decoded);
	}

	public function test_getMyCodeFormatted_strips_meta_from_codes(): void {
		$userId = $this->factory->user->create(['role' => 'subscriber']);
		$codes = $this->createCodesForUser($userId, 1);

		$result = $this->main->getMyCodeFormatted($userId, ['format' => 'json'], null, '', $codes);

		$decoded = json_decode($result, true);
		foreach ($decoded['codes'] as $code) {
			$this->assertArrayNotHasKey('meta', $code, 'meta should be stripped from output');
		}
	}

	// ── getCodesTextAsShortList ─────────────────────────────────

	public function test_getCodesTextAsShortList_empty_for_no_codes(): void {
		$result = $this->main->getCodesTextAsShortList([]);

		$this->assertEmpty($result);
	}

	public function test_getCodesTextAsShortList_shows_expired_label(): void {
		$listId = $this->main->getDB()->insert('lists', [
			'name' => 'Expired List ' . uniqid(),
			'aktiv' => 1,
			'meta' => '{}',
		]);
		$code = 'EXPIRED' . strtoupper(uniqid());
		$this->main->getDB()->insert('codes', [
			'list_id' => $listId,
			'code' => $code,
			'aktiv' => 1,
			'cvv' => '',
			'order_id' => 0,
			'user_id' => 0,
			'meta' => json_encode(['expiration' => ['date' => '2020-01-01']]),
		]);
		$codeObj = $this->main->getCore()->retrieveCodeByCode($code);
		$codeObj['code_display'] = $code;

		$result = $this->main->getCodesTextAsShortList([$codeObj]);

		$this->assertStringContainsString('<table>', $result);
		$this->assertStringContainsString('EXPIRED', $result);
	}

	public function test_getCodesTextAsShortList_shows_stolen_label(): void {
		$listId = $this->main->getDB()->insert('lists', [
			'name' => 'Stolen List ' . uniqid(),
			'aktiv' => 1,
			'meta' => '{}',
		]);
		$code = 'STOLEN' . strtoupper(uniqid());
		$this->main->getDB()->insert('codes', [
			'list_id' => $listId,
			'code' => $code,
			'aktiv' => 2,
			'cvv' => '',
			'order_id' => 0,
			'user_id' => 0,
			'meta' => '{}',
		]);
		$codeObj = $this->main->getCore()->retrieveCodeByCode($code);
		$codeObj['code_display'] = $code;

		$result = $this->main->getCodesTextAsShortList([$codeObj]);

		$this->assertStringContainsString('STOLEN', $result);
	}

	public function test_getCodesTextAsShortList_shows_disabled_label(): void {
		$listId = $this->main->getDB()->insert('lists', [
			'name' => 'Disabled List ' . uniqid(),
			'aktiv' => 1,
			'meta' => '{}',
		]);
		$code = 'DISABLED' . strtoupper(uniqid());
		$this->main->getDB()->insert('codes', [
			'list_id' => $listId,
			'code' => $code,
			'aktiv' => 0,
			'cvv' => '',
			'order_id' => 0,
			'user_id' => 0,
			'meta' => '{}',
		]);
		$codeObj = $this->main->getCore()->retrieveCodeByCode($code);
		$codeObj['code_display'] = $code;

		$result = $this->main->getCodesTextAsShortList([$codeObj]);

		$this->assertStringContainsString('DISABLED', $result);
	}

	public function test_getCodesTextAsShortList_fires_filter(): void {
		$filtered = false;
		$callback = function ($ret) use (&$filtered) {
			$filtered = true;
			return $ret;
		};
		add_filter($this->main->_add_filter_prefix . 'main_getCodesTextAsShortList', $callback);

		$this->main->getCodesTextAsShortList([]);

		$this->assertTrue($filtered);
		remove_filter($this->main->_add_filter_prefix . 'main_getCodesTextAsShortList', $callback);
	}

	// ── isUserAllowedToAccessAdminArea ──────────────────────────

	public function test_admin_user_is_allowed(): void {
		$adminId = $this->factory->user->create(['role' => 'administrator']);
		wp_set_current_user($adminId);

		// Reset cached value
		$ref = new ReflectionProperty($this->main, 'isAllowedAccess');
		$ref->setAccessible(true);
		$ref->setValue($this->main, null);

		$this->assertTrue($this->main->isUserAllowedToAccessAdminArea());
	}

	public function test_subscriber_is_not_allowed(): void {
		$subId = $this->factory->user->create(['role' => 'subscriber']);
		wp_set_current_user($subId);

		$ref = new ReflectionProperty($this->main, 'isAllowedAccess');
		$ref->setAccessible(true);
		$ref->setValue($this->main, null);

		$result = $this->main->isUserAllowedToAccessAdminArea();
		$this->assertEmpty($result, 'Subscriber should not have admin access');
	}

	public function test_access_can_be_filtered(): void {
		$subId = $this->factory->user->create(['role' => 'subscriber']);
		wp_set_current_user($subId);

		$ref = new ReflectionProperty($this->main, 'isAllowedAccess');
		$ref->setAccessible(true);
		$ref->setValue($this->main, null);

		// Override via filter
		$callback = function () {
			return true;
		};
		add_filter($this->main->_add_filter_prefix . 'main_isUserAllowedToAccessAdminArea', $callback);

		$this->assertTrue($this->main->isUserAllowedToAccessAdminArea());

		remove_filter($this->main->_add_filter_prefix . 'main_isUserAllowedToAccessAdminArea', $callback);
	}

	// ── canUserAccessOrder ─────────────────────────────────────

	public function test_canUserAccessOrder_admin_can_access(): void {
		if (!class_exists('WC_Product_Simple')) {
			$this->markTestSkipped('WooCommerce not available');
		}

		$adminId = $this->factory->user->create(['role' => 'administrator']);
		wp_set_current_user($adminId);
		// Grant manage_woocommerce capability
		$user = new WP_User($adminId);
		$user->add_cap('manage_woocommerce');

		$order = wc_create_order();
		$order->save();

		$ref = new ReflectionMethod($this->main, 'canUserAccessOrder');
		$ref->setAccessible(true);

		$this->assertTrue($ref->invoke($this->main, $order->get_id()));
	}

	public function test_canUserAccessOrder_owner_can_access(): void {
		if (!class_exists('WC_Product_Simple')) {
			$this->markTestSkipped('WooCommerce not available');
		}

		$userId = $this->factory->user->create(['role' => 'subscriber']);
		wp_set_current_user($userId);

		$order = wc_create_order();
		$order->set_customer_id($userId);
		$order->save();

		$ref = new ReflectionMethod($this->main, 'canUserAccessOrder');
		$ref->setAccessible(true);

		$this->assertTrue($ref->invoke($this->main, $order->get_id()));
	}

	public function test_canUserAccessOrder_other_user_denied(): void {
		if (!class_exists('WC_Product_Simple')) {
			$this->markTestSkipped('WooCommerce not available');
		}

		$user1 = $this->factory->user->create(['role' => 'subscriber']);
		$user2 = $this->factory->user->create(['role' => 'subscriber']);
		wp_set_current_user($user2);

		$order = wc_create_order();
		$order->set_customer_id($user1);
		$order->save();

		$ref = new ReflectionMethod($this->main, 'canUserAccessOrder');
		$ref->setAccessible(true);

		$this->assertFalse($ref->invoke($this->main, $order->get_id()));
	}

	public function test_canUserAccessOrder_nonexistent_order_denied(): void {
		$ref = new ReflectionMethod($this->main, 'canUserAccessOrder');
		$ref->setAccessible(true);

		$this->assertFalse($ref->invoke($this->main, 999999));
	}

	// ── Helper methods ─────────────────────────────────────────

	private function createCodesForUser(int $userId, int $count): array {
		$listId = $this->main->getDB()->insert('lists', [
			'name' => 'UserCodes List ' . uniqid(),
			'aktiv' => 1,
			'meta' => '{}',
		]);

		$codes = [];
		for ($i = 0; $i < $count; $i++) {
			$code = 'UCODE' . strtoupper(uniqid());
			$metaObj = [
				'user' => [
					'value' => 'TestUser',
					'reg_ip' => '127.0.0.1',
					'reg_approved' => 1,
					'reg_request' => '2026-01-01 12:00:00',
					'reg_request_tz' => 'UTC',
					'reg_userid' => $userId,
				],
				'confirmedCount' => 0,
			];
			$this->main->getDB()->insert('codes', [
				'list_id' => $listId,
				'code' => $code,
				'aktiv' => 1,
				'cvv' => '',
				'order_id' => 0,
				'user_id' => $userId,
				'meta' => json_encode($metaObj),
			]);
			$codeObj = $this->main->getCore()->retrieveCodeByCode($code);
			$codeObj['code_display'] = $code;
			$codes[] = $codeObj;
		}
		return $codes;
	}
}
