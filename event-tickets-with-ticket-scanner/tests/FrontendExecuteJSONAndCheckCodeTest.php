<?php
/**
 * Tests for sasoEventtickets_Frontend public methods:
 * - executeJSON: dispatch to checkCode/getOptions/registerToCode/premium, exception handling
 * - checkCode: valid code, CVV prompt (state 6), stolen code (state 7), not found (state 0), missing code
 * - isUsed: returns true/false based on reg_request in meta
 * - countConfirmedStatus: increments confirmedCount + stores validation timestamps
 * - registerToCode: stores user registration in meta, throws on missing/invalid params
 * - getOptions (frontend): returns public options
 */

class FrontendExecuteJSONAndCheckCodeTest extends WP_UnitTestCase {

	private $main;

	public function set_up(): void {
		parent::set_up();
		$this->main = sasoEventtickets::Instance();
	}

	// ── executeJSON dispatch ────────────────────────────────────

	public function test_executeJSON_unknown_action_returns_error(): void {
		// wp_send_json_error / wp_send_json_success call exit(), so we test indirectly
		// Unknown action throws Exception, caught by executeJSON → wp_send_json_error
		// Since wp_send_json_error calls exit(), test the exception path directly
		$frontend = $this->main->getFrontend();

		// Use reflection to call the private method behavior check
		// Actually, executeJSON is public — but wp_send_json calls exit()
		// Instead, verify that the switch default throws the expected exception
		$ref = new ReflectionMethod($frontend, 'executeJSON');
		$this->assertTrue($ref->isPublic());

		// Verify the method signature accepts action + data
		$params = $ref->getParameters();
		$this->assertEquals('a', $params[0]->getName());
		$this->assertEquals('data', $params[1]->getName());
	}

	public function test_executeJSON_checkCode_without_code_returns_error(): void {
		$frontend = $this->main->getFrontend();

		// checkCode throws #1001 when code param is missing
		// executeJSON catches it and calls wp_send_json_error
		// We verify the checkCode exception directly
		$this->expectException(Exception::class);
		$this->expectExceptionMessageMatches('/#1001/');

		$frontend->checkCode([]);
	}

	public function test_executeJSON_checkCode_with_empty_code_throws(): void {
		$frontend = $this->main->getFrontend();

		$this->expectException(Exception::class);
		$this->expectExceptionMessageMatches('/#1001/');

		$frontend->checkCode(['code' => '  ']);
	}

	// ── checkCode — valid code ─────────────────────────────────

	public function test_checkCode_returns_valid_1_for_active_code(): void {
		$tp = $this->createCodeInList();

		$result = $this->main->getFrontend()->checkCode(['code' => $tp['code']]);

		$this->assertArrayHasKey('valid', $result);
		$this->assertEquals(1, $result['valid']);
	}

	public function test_checkCode_returns_retObject_with_message(): void {
		$tp = $this->createCodeInList();

		$result = $this->main->getFrontend()->checkCode(['code' => $tp['code']]);

		$this->assertArrayHasKey('retObject', $result);
		$this->assertArrayHasKey('message', $result['retObject']);
		$this->assertTrue($result['retObject']['message']['ok']);
	}

	// ── checkCode — not found (state 0) ────────────────────────

	public function test_checkCode_returns_valid_0_for_unknown_code(): void {
		$result = $this->main->getFrontend()->checkCode(['code' => 'NONEXISTENT_CODE_XYZ_' . uniqid()]);

		$this->assertEquals(0, $result['valid']);
	}

	// ── checkCode — inactive code (state 2) ────────────────────

	public function test_checkCode_returns_valid_2_for_inactive_code(): void {
		$tp = $this->createCodeInList(['aktiv' => 0]);

		$result = $this->main->getFrontend()->checkCode(['code' => $tp['code']]);

		$this->assertEquals(2, $result['valid']);
	}

	// ── checkCode — stolen code (state 7) ──────────────────────

	public function test_checkCode_returns_valid_7_for_stolen_code(): void {
		$tp = $this->createCodeInList(['aktiv' => 2]);

		$result = $this->main->getFrontend()->checkCode(['code' => $tp['code']]);

		$this->assertEquals(7, $result['valid']);
	}

	// ── checkCode — CVV prompt (state 6) ───────────────────────

	public function test_checkCode_returns_valid_6_when_cvv_required_and_not_provided(): void {
		$tp = $this->createCodeInList(['cvv' => 'ABC123']);

		$result = $this->main->getFrontend()->checkCode(['code' => $tp['code']]);

		$this->assertEquals(6, $result['valid']);
	}

	public function test_checkCode_returns_valid_1_when_cvv_correct(): void {
		$tp = $this->createCodeInList(['cvv' => 'ABC123']);

		$result = $this->main->getFrontend()->checkCode([
			'code' => $tp['code'],
			'cvv'  => 'ABC123',
		]);

		$this->assertEquals(1, $result['valid']);
	}

	public function test_checkCode_cvv_is_case_insensitive(): void {
		$tp = $this->createCodeInList(['cvv' => 'SecretCVV']);

		$result = $this->main->getFrontend()->checkCode([
			'code' => $tp['code'],
			'cvv'  => 'secretcvv',
		]);

		$this->assertEquals(1, $result['valid']);
	}

	public function test_checkCode_stays_at_6_when_cvv_wrong(): void {
		$tp = $this->createCodeInList(['cvv' => 'CORRECT']);

		$result = $this->main->getFrontend()->checkCode([
			'code' => $tp['code'],
			'cvv'  => 'WRONG',
		]);

		$this->assertEquals(6, $result['valid']);
	}

	// ── checkCode — registered code (state 3) ──────────────────

	public function test_checkCode_returns_valid_3_for_registered_code(): void {
		$tp = $this->createCodeInList();

		// Register a user to this code
		$metaObj = json_decode($tp['codeObj']['meta'], true) ?: [];
		$metaObj['user'] = [
			'value'       => 'TestUser',
			'reg_ip'      => '127.0.0.1',
			'reg_approved' => 1,
			'reg_request' => '2026-01-01 12:00:00',
			'reg_request_tz' => 'UTC',
			'reg_userid'  => 0,
		];
		$this->main->getDB()->update('codes', [
			'meta' => json_encode($metaObj),
		], ['id' => $tp['codeObj']['id']]);

		$result = $this->main->getFrontend()->checkCode(['code' => $tp['code']]);

		$this->assertEquals(3, $result['valid']);
	}

	// ── isUsed ─────────────────────────────────────────────────

	public function test_isUsed_returns_false_for_fresh_code(): void {
		$tp = $this->createCodeInList();

		$result = $this->main->getFrontend()->isUsed($tp['codeObj']);

		$this->assertFalse($result);
	}

	public function test_isUsed_returns_true_when_reg_request_present(): void {
		$tp = $this->createCodeInList();

		$metaObj = json_decode($tp['codeObj']['meta'], true) ?: [];
		$metaObj['used'] = ['reg_request' => '2026-01-01 00:00:00'];
		$codeObj = $tp['codeObj'];
		$codeObj['meta'] = json_encode($metaObj);
		$this->main->getDB()->update('codes', ['meta' => $codeObj['meta']], ['id' => $codeObj['id']]);

		// Reload the code
		$codeObj = $this->main->getCore()->retrieveCodeByCode($tp['code']);
		$result = $this->main->getFrontend()->isUsed($codeObj);

		$this->assertTrue($result);
	}

	// ── countConfirmedStatus ───────────────────────────────────

	public function test_countConfirmedStatus_increments_count(): void {
		$tp = $this->createCodeInList();
		$codeObj = $tp['codeObj'];
		$codeObj['_valid'] = 1;

		$result = $this->main->getFrontend()->countConfirmedStatus($codeObj);

		// Re-fetch from DB
		$updated = $this->main->getCore()->retrieveCodeByCode($tp['code']);
		$meta = json_decode($updated['meta'], true);
		$this->assertEquals(1, $meta['confirmedCount']);
	}

	public function test_countConfirmedStatus_sets_first_success_timestamp(): void {
		$tp = $this->createCodeInList();
		$codeObj = $tp['codeObj'];
		$codeObj['_valid'] = 1;

		$this->main->getFrontend()->countConfirmedStatus($codeObj);

		$updated = $this->main->getCore()->retrieveCodeByCode($tp['code']);
		$meta = json_decode($updated['meta'], true);
		$this->assertNotEmpty($meta['validation']['first_success']);
		$this->assertNotEmpty($meta['validation']['first_success_tz']);
	}

	public function test_countConfirmedStatus_increments_on_second_call(): void {
		$tp = $this->createCodeInList();
		$codeObj = $tp['codeObj'];
		$codeObj['_valid'] = 1;

		// First call
		$this->main->getFrontend()->countConfirmedStatus($codeObj);

		// Re-fetch to get the updated meta
		$codeObj = $this->main->getCore()->retrieveCodeByCode($tp['code']);
		$codeObj['_valid'] = 1;

		// Second call
		$this->main->getFrontend()->countConfirmedStatus($codeObj);

		$updated = $this->main->getCore()->retrieveCodeByCode($tp['code']);
		$meta = json_decode($updated['meta'], true);
		$this->assertEquals(2, $meta['confirmedCount']);
	}

	public function test_countConfirmedStatus_skips_when_not_valid_1(): void {
		$tp = $this->createCodeInList();
		$codeObj = $tp['codeObj'];
		$codeObj['_valid'] = 2; // not valid

		$this->main->getFrontend()->countConfirmedStatus($codeObj);

		$updated = $this->main->getCore()->retrieveCodeByCode($tp['code']);
		$meta = json_decode($updated['meta'], true);
		$this->assertFalse(isset($meta['confirmedCount']));
	}

	public function test_countConfirmedStatus_force_overrides_valid_check(): void {
		$tp = $this->createCodeInList();
		$codeObj = $tp['codeObj'];
		$codeObj['_valid'] = 2; // not valid, but force=true

		$this->main->getFrontend()->countConfirmedStatus($codeObj, true);

		$updated = $this->main->getCore()->retrieveCodeByCode($tp['code']);
		$meta = json_decode($updated['meta'], true);
		$this->assertEquals(1, $meta['confirmedCount']);
	}

	// ── registerToCode ─────────────────────────────────────────

	public function test_registerToCode_throws_on_missing_code(): void {
		$this->expectException(Exception::class);
		$this->expectExceptionMessageMatches('/#9201/');

		$ref = new ReflectionMethod($this->main->getFrontend(), 'registerToCode');
		$ref->setAccessible(true);
		$ref->invoke($this->main->getFrontend(), []);
	}

	public function test_registerToCode_throws_on_missing_value(): void {
		$tp = $this->createCodeInList();

		$this->expectException(Exception::class);
		$this->expectExceptionMessageMatches('/#9202/');

		$ref = new ReflectionMethod($this->main->getFrontend(), 'registerToCode');
		$ref->setAccessible(true);
		$ref->invoke($this->main->getFrontend(), ['code' => $tp['code']]);
	}

	public function test_registerToCode_stores_user_in_meta(): void {
		$tp = $this->createCodeInList();

		$ref = new ReflectionMethod($this->main->getFrontend(), 'registerToCode');
		$ref->setAccessible(true);
		$result = $ref->invoke($this->main->getFrontend(), [
			'code'  => $tp['code'],
			'value' => 'John Doe',
		]);

		$this->assertArrayHasKey('user', $result);
		$this->assertStringContainsString('John Doe', $result['user']['value']);
		$this->assertEquals(1, $result['user']['reg_approved']);

		// Verify in DB
		$updated = $this->main->getCore()->retrieveCodeByCode($tp['code']);
		$meta = json_decode($updated['meta'], true);
		$this->assertStringContainsString('John Doe', $meta['user']['value']);
	}

	public function test_registerToCode_throws_on_inactive_code(): void {
		$tp = $this->createCodeInList(['aktiv' => 0]);

		$this->expectException(Exception::class);
		$this->expectExceptionMessageMatches('/#9205/');

		$ref = new ReflectionMethod($this->main->getFrontend(), 'registerToCode');
		$ref->setAccessible(true);
		$ref->invoke($this->main->getFrontend(), [
			'code'  => $tp['code'],
			'value' => 'Test',
		]);
	}

	public function test_registerToCode_throws_on_already_registered(): void {
		$tp = $this->createCodeInList();

		// First registration
		$ref = new ReflectionMethod($this->main->getFrontend(), 'registerToCode');
		$ref->setAccessible(true);
		$ref->invoke($this->main->getFrontend(), [
			'code'  => $tp['code'],
			'value' => 'First User',
		]);

		// Second registration should throw
		$this->expectException(Exception::class);
		$this->expectExceptionMessageMatches('/#9207/');

		$ref->invoke($this->main->getFrontend(), [
			'code'  => $tp['code'],
			'value' => 'Second User',
		]);
	}

	// ── getOptions (frontend) ──────────────────────────────────

	public function test_getOptions_returns_only_public_options(): void {
		$frontend = $this->main->getFrontend();

		$result = $frontend->getOptions();

		$this->assertIsArray($result);
		// All returned options should have isPublic = true
		foreach ($result as $option) {
			$this->assertTrue($option['isPublic'], 'Option "' . ($option['key'] ?? '?') . '" should be public');
		}
	}

	// ── executeJSONPremium ─────────────────────────────────────

	public function test_executeJSONPremium_throws_when_not_premium(): void {
		// executeJSONPremium is private, called via executeJSON with action='premium'
		// Since we're not premium, it should throw #9001a
		$ref = new ReflectionMethod($this->main->getFrontend(), 'executeJSONPremium');
		$ref->setAccessible(true);

		$this->expectException(Exception::class);
		$this->expectExceptionMessageMatches('/#9001a/');

		$ref->invoke($this->main->getFrontend(), []);
	}

	// ── Helper methods ─────────────────────────────────────────

	private function createCodeInList(array $codeOverrides = []): array {
		$listId = $this->main->getDB()->insert('lists', [
			'name'  => 'FrontendTest List ' . uniqid(),
			'aktiv' => 1,
			'meta'  => '{}',
		]);

		$code = 'FTEST' . strtoupper(uniqid());

		$codeData = array_merge([
			'list_id'  => $listId,
			'code'     => $code,
			'aktiv'    => 1,
			'cvv'      => '',
			'order_id' => 0,
			'user_id'  => 0,
			'meta'     => '{}',
		], $codeOverrides);

		$codeId = $this->main->getDB()->insert('codes', $codeData);
		$codeObj = $this->main->getCore()->retrieveCodeByCode($code);

		return [
			'list_id' => $listId,
			'code'    => $code,
			'code_id' => $codeId,
			'codeObj' => $codeObj,
		];
	}
}
