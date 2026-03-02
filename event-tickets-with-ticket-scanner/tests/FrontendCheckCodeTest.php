<?php
/**
 * Batch 42 — Frontend checkCode, registerToCode, and helper methods:
 * - checkCode: main validation flow (valid, invalid, inactive, CVV, registered, expired, stolen)
 * - registerToCode: user registration to ticket code
 * - isUsed / markAsUsed: one-time-use logic
 * - countConfirmedStatus: counter increment
 * - getOptions: public options retrieval
 */

class FrontendCheckCodeTest extends WP_UnitTestCase {

	private $main;
	private $frontend;

	public function set_up(): void {
		parent::set_up();
		$this->main = sasoEventtickets::Instance();
		$this->frontend = $this->main->getFrontend();
	}

	private function createActiveCode(string $code = '', string $cvv = ''): array {
		$listId = $this->main->getDB()->insert('lists', [
			'name' => 'FE Check List ' . uniqid(),
			'aktiv' => 1,
			'meta' => '{}',
		]);
		$metaObj = $this->main->getCore()->getMetaObject();
		$metaJson = $this->main->getCore()->json_encode_with_error_handling($metaObj);

		if (empty($code)) {
			$code = 'FECHECK' . strtoupper(uniqid());
		}

		$this->main->getDB()->insert('codes', [
			'list_id' => $listId,
			'code' => $code,
			'aktiv' => 1,
			'cvv' => $cvv,
			'order_id' => 0,
			'user_id' => 0,
			'meta' => $metaJson,
		]);

		$codeObj = $this->main->getCore()->retrieveCodeByCode($code);
		return ['list_id' => $listId, 'code' => $code, 'codeObj' => $codeObj];
	}

	private function createInactiveCode(): array {
		$listId = $this->main->getDB()->insert('lists', [
			'name' => 'FE Inactive List ' . uniqid(),
			'aktiv' => 1,
			'meta' => '{}',
		]);
		$metaObj = $this->main->getCore()->getMetaObject();
		$metaJson = $this->main->getCore()->json_encode_with_error_handling($metaObj);
		$code = 'FEINACT' . strtoupper(uniqid());

		$this->main->getDB()->insert('codes', [
			'list_id' => $listId,
			'code' => $code,
			'aktiv' => 0,
			'cvv' => '',
			'order_id' => 0,
			'user_id' => 0,
			'meta' => $metaJson,
		]);

		return ['list_id' => $listId, 'code' => $code];
	}

	private function createStolenCode(): array {
		$listId = $this->main->getDB()->insert('lists', [
			'name' => 'FE Stolen List ' . uniqid(),
			'aktiv' => 1,
			'meta' => '{}',
		]);
		$metaObj = $this->main->getCore()->getMetaObject();
		$metaJson = $this->main->getCore()->json_encode_with_error_handling($metaObj);
		$code = 'FESTOLEN' . strtoupper(uniqid());

		$this->main->getDB()->insert('codes', [
			'list_id' => $listId,
			'code' => $code,
			'aktiv' => 2, // stolen
			'cvv' => '',
			'order_id' => 0,
			'user_id' => 0,
			'meta' => $metaJson,
		]);

		return ['list_id' => $listId, 'code' => $code];
	}

	// ── checkCode ────────────────────────────────────────────

	public function test_checkCode_valid_code(): void {
		$data = $this->createActiveCode();
		$result = $this->frontend->checkCode(['code' => $data['code']]);
		$this->assertIsArray($result);
		$this->assertEquals(1, $result['valid']);
	}

	public function test_checkCode_nonexistent_returns_zero(): void {
		$result = $this->frontend->checkCode(['code' => 'NONEXISTENT_XYZ_' . uniqid()]);
		$this->assertIsArray($result);
		$this->assertEquals(0, $result['valid']);
	}

	public function test_checkCode_inactive_returns_two(): void {
		$data = $this->createInactiveCode();
		$result = $this->frontend->checkCode(['code' => $data['code']]);
		$this->assertIsArray($result);
		$this->assertEquals(2, $result['valid']);
	}

	public function test_checkCode_stolen_returns_seven(): void {
		$data = $this->createStolenCode();
		$result = $this->frontend->checkCode(['code' => $data['code']]);
		$this->assertIsArray($result);
		$this->assertEquals(7, $result['valid']);
	}

	public function test_checkCode_throws_for_empty_code(): void {
		$this->expectException(Exception::class);
		$this->expectExceptionMessageMatches('/#1001/');
		$this->frontend->checkCode(['code' => '']);
	}

	public function test_checkCode_throws_for_missing_code(): void {
		$this->expectException(Exception::class);
		$this->expectExceptionMessageMatches('/#1001/');
		$this->frontend->checkCode([]);
	}

	public function test_checkCode_cvv_required_returns_six(): void {
		$data = $this->createActiveCode('', 'SECRET123');
		$result = $this->frontend->checkCode(['code' => $data['code']]);
		$this->assertEquals(6, $result['valid']);
	}

	public function test_checkCode_cvv_correct_returns_valid(): void {
		$data = $this->createActiveCode('', 'SECRET123');
		$result = $this->frontend->checkCode(['code' => $data['code'], 'cvv' => 'SECRET123']);
		$this->assertEquals(1, $result['valid']);
	}

	public function test_checkCode_cvv_wrong_stays_six(): void {
		$data = $this->createActiveCode('', 'SECRET123');
		$result = $this->frontend->checkCode(['code' => $data['code'], 'cvv' => 'WRONG']);
		$this->assertEquals(6, $result['valid']);
	}

	public function test_checkCode_cvv_case_insensitive(): void {
		$data = $this->createActiveCode('', 'ABC');
		$result = $this->frontend->checkCode(['code' => $data['code'], 'cvv' => 'abc']);
		$this->assertEquals(1, $result['valid']);
	}

	public function test_checkCode_returns_retObject(): void {
		$data = $this->createActiveCode();
		$result = $this->frontend->checkCode(['code' => $data['code']]);
		$this->assertArrayHasKey('retObject', $result);
		$this->assertArrayHasKey('message', $result['retObject']);
	}

	public function test_checkCode_registered_code_returns_three(): void {
		$data = $this->createActiveCode();
		// Register a user to the code
		$metaObj = $this->main->getCore()->encodeMetaValuesAndFillObject($data['codeObj']['meta'], $data['codeObj']);
		$metaObj['user']['value'] = 'Test User';
		$this->main->getCore()->saveMetaObject($data['codeObj'], $metaObj);

		$result = $this->frontend->checkCode(['code' => $data['code']]);
		$this->assertEquals(3, $result['valid']);
	}

	// ── isUsed ───────────────────────────────────────────────

	public function test_isUsed_false_for_new_code(): void {
		$data = $this->createActiveCode();
		$this->assertFalse($this->frontend->isUsed($data['codeObj']));
	}

	public function test_isUsed_true_after_marking_used(): void {
		$data = $this->createActiveCode();
		$codeObj = $data['codeObj'];
		$metaObj = $this->main->getCore()->encodeMetaValuesAndFillObject($codeObj['meta'], $codeObj);
		$metaObj['used']['reg_request'] = wp_date("Y-m-d H:i:s");
		$codeObj['meta'] = $this->main->getCore()->json_encode_with_error_handling($metaObj);

		$this->assertTrue($this->frontend->isUsed($codeObj));
	}

	// ── countConfirmedStatus ─────────────────────────────────

	public function test_countConfirmedStatus_increments(): void {
		$data = $this->createActiveCode();
		$codeObj = $data['codeObj'];
		$codeObj['_valid'] = 1;

		$result = $this->frontend->countConfirmedStatus($codeObj);
		$this->assertIsArray($result);

		// Reload and check confirmedCount
		$reloaded = $this->main->getCore()->retrieveCodeByCode($data['code']);
		$reloadedMeta = $this->main->getCore()->encodeMetaValuesAndFillObject($reloaded['meta'], $reloaded);
		$this->assertEquals(1, (int) $reloadedMeta['confirmedCount']);
	}

	public function test_countConfirmedStatus_skips_when_not_valid(): void {
		$data = $this->createActiveCode();
		$codeObj = $data['codeObj'];
		$codeObj['_valid'] = 0; // not valid

		$this->frontend->countConfirmedStatus($codeObj);

		$reloaded = $this->main->getCore()->retrieveCodeByCode($data['code']);
		$reloadedMeta = $this->main->getCore()->encodeMetaValuesAndFillObject($reloaded['meta'], $reloaded);
		$this->assertEquals(0, (int) $reloadedMeta['confirmedCount']);
	}

	public function test_countConfirmedStatus_force_increments(): void {
		$data = $this->createActiveCode();
		$codeObj = $data['codeObj'];
		$codeObj['_valid'] = 0; // normally skipped

		$this->frontend->countConfirmedStatus($codeObj, true); // force

		$reloaded = $this->main->getCore()->retrieveCodeByCode($data['code']);
		$reloadedMeta = $this->main->getCore()->encodeMetaValuesAndFillObject($reloaded['meta'], $reloaded);
		$this->assertEquals(1, (int) $reloadedMeta['confirmedCount']);
	}

	public function test_countConfirmedStatus_sets_first_success(): void {
		$data = $this->createActiveCode();
		$codeObj = $data['codeObj'];
		$codeObj['_valid'] = 1;

		$this->frontend->countConfirmedStatus($codeObj);

		$reloaded = $this->main->getCore()->retrieveCodeByCode($data['code']);
		$reloadedMeta = $this->main->getCore()->encodeMetaValuesAndFillObject($reloaded['meta'], $reloaded);
		$this->assertNotEmpty($reloadedMeta['validation']['first_success']);
		$this->assertNotEmpty($reloadedMeta['validation']['last_success']);
	}

	public function test_countConfirmedStatus_multiple_calls(): void {
		$data = $this->createActiveCode();
		$codeObj = $data['codeObj'];
		$codeObj['_valid'] = 1;

		$codeObj = $this->frontend->countConfirmedStatus($codeObj);
		$codeObj['_valid'] = 1; // reset for second call
		$codeObj = $this->frontend->countConfirmedStatus($codeObj, true);

		$reloaded = $this->main->getCore()->retrieveCodeByCode($data['code']);
		$reloadedMeta = $this->main->getCore()->encodeMetaValuesAndFillObject($reloaded['meta'], $reloaded);
		$this->assertEquals(2, (int) $reloadedMeta['confirmedCount']);
	}

	// ── getOptions ───────────────────────────────────────────

	public function test_getOptions_returns_array(): void {
		$result = $this->frontend->getOptions();
		$this->assertIsArray($result);
	}

	// ── markAsUsed ───────────────────────────────────────────

	public function test_markAsUsed_returns_codeObj(): void {
		$data = $this->createActiveCode();
		$result = $this->frontend->markAsUsed($data['codeObj']);
		$this->assertIsArray($result);
		$this->assertArrayHasKey('id', $result);
	}

	public function test_markAsUsed_with_force(): void {
		$data = $this->createActiveCode();
		$codeObj = $data['codeObj'];

		// Force mark as used with 1 count
		$result = $this->frontend->markAsUsed($codeObj, true);
		$this->assertIsArray($result);

		// After forced mark, isUsed should be true
		$reloaded = $this->main->getCore()->retrieveCodeByCode($data['code']);
		$this->assertTrue($this->frontend->isUsed($reloaded));
	}
}
