<?php
/**
 * Batch 38 — Core code operations and DB logError:
 * - saveMetaObject: writes meta JSON back to codes table
 * - retrieveCodeById: lookup by ID, with/without list join
 * - checkCodesSize / isCodeSizeExceeded: ticket limit enforcement
 * - checkCodeExpired: premium expiration check (returns false in free)
 * - isCodeIsRegistered: checks if ticket has registered user value
 * - DB::logError: new convenience method for error logging
 * - Base::increaseGlobalTicketCounter / getOverallTicketCounterValue
 * - Base::getMaxValues / getMaxValue
 */

class CoreCodeOpsAndDBLogTest extends WP_UnitTestCase {

	private $main;

	public function set_up(): void {
		parent::set_up();
		$this->main = sasoEventtickets::Instance();
	}

	private function createCodeInList(): array {
		$listId = $this->main->getDB()->insert('lists', [
			'name' => 'CodeOps List ' . uniqid(),
			'aktiv' => 1,
			'meta' => '{}',
		]);
		$metaObj = $this->main->getCore()->getMetaObject();
		$metaJson = $this->main->getCore()->json_encode_with_error_handling($metaObj);

		$code = 'CODEOPS' . strtoupper(uniqid());
		$this->main->getDB()->insert('codes', [
			'list_id' => $listId,
			'code' => $code,
			'aktiv' => 1,
			'cvv' => '',
			'order_id' => 0,
			'user_id' => 0,
			'meta' => $metaJson,
		]);

		$codeObj = $this->main->getCore()->retrieveCodeByCode($code);
		return ['list_id' => $listId, 'code' => $code, 'codeObj' => $codeObj];
	}

	// ── saveMetaObject ────────────────────────────────────────

	public function test_saveMetaObject_updates_meta(): void {
		$data = $this->createCodeInList();
		$metaObj = $this->main->getCore()->encodeMetaValuesAndFillObject($data['codeObj']['meta'], $data['codeObj']);
		$metaObj['confirmedCount'] = 42;

		$updatedCodeObj = $this->main->getCore()->saveMetaObject($data['codeObj'], $metaObj);

		// Reload from DB
		$reloaded = $this->main->getCore()->retrieveCodeByCode($data['code']);
		$reloadedMeta = $this->main->getCore()->encodeMetaValuesAndFillObject($reloaded['meta'], $reloaded);
		$this->assertEquals(42, $reloadedMeta['confirmedCount']);
	}

	public function test_saveMetaObject_returns_updated_codeObj(): void {
		$data = $this->createCodeInList();
		$metaObj = $this->main->getCore()->encodeMetaValuesAndFillObject($data['codeObj']['meta'], $data['codeObj']);

		$result = $this->main->getCore()->saveMetaObject($data['codeObj'], $metaObj);
		$this->assertIsArray($result);
		$this->assertArrayHasKey('meta', $result);
		$this->assertIsString($result['meta']); // JSON string
	}

	// ── retrieveCodeById ──────────────────────────────────────

	public function test_retrieveCodeById_returns_code(): void {
		$data = $this->createCodeInList();
		$result = $this->main->getCore()->retrieveCodeById($data['codeObj']['id']);

		$this->assertIsArray($result);
		$this->assertEquals($data['code'], $result['code']);
	}

	public function test_retrieveCodeById_with_list_join(): void {
		$data = $this->createCodeInList();
		$result = $this->main->getCore()->retrieveCodeById($data['codeObj']['id'], true);

		$this->assertIsArray($result);
		$this->assertArrayHasKey('list_name', $result);
		$this->assertNotEmpty($result['list_name']);
	}

	public function test_retrieveCodeById_throws_for_zero(): void {
		$this->expectException(Exception::class);
		$this->expectExceptionMessageMatches('/#220/');
		$this->main->getCore()->retrieveCodeById(0);
	}

	public function test_retrieveCodeById_throws_for_nonexistent(): void {
		$this->expectException(Exception::class);
		$this->expectExceptionMessageMatches('/#221/');
		$this->main->getCore()->retrieveCodeById(999999);
	}

	// ── checkCodesSize / isCodeSizeExceeded ───────────────────

	public function test_isCodeSizeExceeded_returns_bool(): void {
		$result = $this->main->getCore()->isCodeSizeExceeded();
		$this->assertIsBool($result);
	}

	public function test_checkCodesSize_does_not_throw_below_limit(): void {
		// In test environment with few codes, this should not throw
		if ($this->main->getCore()->isCodeSizeExceeded()) {
			$this->markTestSkipped('Code limit already exceeded');
		}
		$this->main->getCore()->checkCodesSize();
		$this->assertTrue(true); // No exception = pass
	}

	// ── checkCodeExpired ──────────────────────────────────────

	public function test_checkCodeExpired_returns_false_in_free_version(): void {
		$data = $this->createCodeInList();
		$result = $this->main->getCore()->checkCodeExpired($data['codeObj']);
		$this->assertFalse($result);
	}

	// ── isCodeIsRegistered ────────────────────────────────────

	public function test_isCodeIsRegistered_false_for_new_code(): void {
		$data = $this->createCodeInList();
		$result = $this->main->getCore()->isCodeIsRegistered($data['codeObj']);
		$this->assertFalse($result);
	}

	public function test_isCodeIsRegistered_true_for_registered_code(): void {
		$data = $this->createCodeInList();
		$metaObj = $this->main->getCore()->encodeMetaValuesAndFillObject($data['codeObj']['meta'], $data['codeObj']);
		$metaObj['user']['value'] = 'John Doe';
		$this->main->getCore()->saveMetaObject($data['codeObj'], $metaObj);

		$reloaded = $this->main->getCore()->retrieveCodeByCode($data['code']);
		$result = $this->main->getCore()->isCodeIsRegistered($reloaded);
		$this->assertTrue($result);
	}

	// ── DB::logError ──────────────────────────────────────────

	public function test_logError_inserts_into_errorlogs(): void {
		global $wpdb;
		$countBefore = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->prefix}saso_eventtickets_errorlogs"
		);

		$this->main->getDB()->logError('Test error from PHPUnit', 'CoreCodeOpsTest');

		$countAfter = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->prefix}saso_eventtickets_errorlogs"
		);
		$this->assertEquals($countBefore + 1, $countAfter);
	}

	public function test_logError_stores_message(): void {
		global $wpdb;
		$msg = 'Unique error ' . uniqid();
		$this->main->getDB()->logError($msg, 'TestCaller');

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}saso_eventtickets_errorlogs WHERE msg = %s",
				$msg
			),
			ARRAY_A
		);
		$this->assertNotNull($row);
		$this->assertEquals('TestCaller', $row['caller_name']);
	}

	public function test_logError_truncates_long_message(): void {
		$longMsg = str_repeat('X', 500);
		$this->main->getDB()->logError($longMsg, 'TruncTest');

		global $wpdb;
		$row = $wpdb->get_row(
			"SELECT * FROM {$wpdb->prefix}saso_eventtickets_errorlogs WHERE caller_name = 'TruncTest' ORDER BY id DESC LIMIT 1",
			ARRAY_A
		);
		$this->assertNotNull($row);
		$this->assertLessThanOrEqual(250, strlen($row['exception_msg']));
	}

	// ── Base::increaseGlobalTicketCounter ─────────────────────

	public function test_increaseGlobalTicketCounter_increases(): void {
		$before = $this->main->getBase()->getOverallTicketCounterValue();
		$this->main->getBase()->increaseGlobalTicketCounter(5);
		$after = $this->main->getBase()->getOverallTicketCounterValue();

		$this->assertEquals($before + 5, $after);
	}

	public function test_increaseGlobalTicketCounter_default_increment(): void {
		$before = $this->main->getBase()->getOverallTicketCounterValue();
		$this->main->getBase()->increaseGlobalTicketCounter();
		$after = $this->main->getBase()->getOverallTicketCounterValue();

		$this->assertEquals($before + 1, $after);
	}

	public function test_getOverallTicketCounterValue_returns_int(): void {
		$result = $this->main->getBase()->getOverallTicketCounterValue();
		$this->assertIsInt($result);
	}

	// ── Base::getMaxValues / getMaxValue ──────────────────────

	public function test_getMaxValues_returns_array(): void {
		$result = $this->main->getBase()->getMaxValues();
		$this->assertIsArray($result);
	}

	public function test_getMaxValues_has_expected_keys(): void {
		$result = $this->main->getBase()->getMaxValues();
		$this->assertArrayHasKey('lists', $result);
		$this->assertArrayHasKey('codes_total', $result);
	}

	public function test_getMaxValue_returns_known_key(): void {
		$result = $this->main->getBase()->getMaxValue('lists');
		$this->assertIsInt($result);
		$this->assertGreaterThan(0, $result);
	}

	public function test_getMaxValue_returns_default_for_unknown(): void {
		$result = $this->main->getBase()->getMaxValue('nonexistent_key', 99);
		$this->assertEquals(99, $result);
	}

	// ── Base::_isMaxReachedForList ────────────────────────────

	public function test_isMaxReachedForList_true_below_limit(): void {
		$result = $this->main->getBase()->_isMaxReachedForList(0);
		$this->assertTrue($result);
	}

	public function test_isMaxReachedForTickets_true_below_limit(): void {
		$result = $this->main->getBase()->_isMaxReachedForTickets(0);
		$this->assertTrue($result);
	}

	public function test_isMaxReachedForAuthtokens_true_below_limit(): void {
		$result = $this->main->getBase()->_isMaxReachedForAuthtokens(0);
		$this->assertTrue($result);
	}
}
