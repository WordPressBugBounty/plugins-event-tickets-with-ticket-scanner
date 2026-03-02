<?php
/**
 * Batch 35b — Core parser and query methods:
 * - parser_search_loop: template loop parsing ({{LOOP...}})
 * - getCodesByRegUserId: codes by registered user
 * - getCodesByOrderId: codes by WC order ID
 * - clearCode: strips hyphens, colons, spaces, urldecodes
 * - retrieveCodeByCode: single code lookup, with/without list join
 * - getListById: list lookup by ID
 * - triggerWebhooks: webhook dispatch (without actual HTTP)
 * - encodeMetaValuesAndFillObject: meta JSON decode + fill
 */

class CoreParserAndQueryTest extends WP_UnitTestCase {

	private $main;

	public function set_up(): void {
		parent::set_up();
		$this->main = sasoEventtickets::Instance();
	}

	// ── parser_search_loop ─────────────────────────────────────

	public function test_parser_search_loop_finds_loop(): void {
		$text = '<div>{{LOOP ORDER.items AS item}}<p>{{item.name}}</p>{{LOOPEND}}</div>';

		$result = $this->main->getCore()->parser_search_loop($text);

		$this->assertIsArray($result);
		$this->assertEquals('ORDER.items', $result['collection']);
		$this->assertEquals('item', $result['item_var']);
		$this->assertStringContainsString('item.name', $result['loop_part']);
	}

	public function test_parser_search_loop_returns_false_for_no_loop(): void {
		$text = '<div>No loops here</div>';

		$result = $this->main->getCore()->parser_search_loop($text);

		$this->assertFalse($result);
	}

	public function test_parser_search_loop_returns_false_for_empty(): void {
		$result = $this->main->getCore()->parser_search_loop('');

		$this->assertFalse($result);
	}

	public function test_parser_search_loop_returns_positions(): void {
		$text = 'Before{{LOOP items AS i}}content{{LOOPEND}}After';

		$result = $this->main->getCore()->parser_search_loop($text);

		$this->assertIsArray($result);
		$this->assertArrayHasKey('pos_start', $result);
		$this->assertArrayHasKey('pos_end', $result);
		$this->assertGreaterThan(0, $result['pos_start']);
		$this->assertGreaterThan($result['pos_start'], $result['pos_end']);
	}

	public function test_parser_search_loop_with_incomplete_loop_returns_false(): void {
		// Has LOOP but no LOOPEND
		$text = '{{LOOP items AS i}}content but no end';

		$result = $this->main->getCore()->parser_search_loop($text);

		$this->assertFalse($result);
	}

	// ── clearCode ──────────────────────────────────────────────

	public function test_clearCode_removes_hyphens(): void {
		$result = $this->main->getCore()->clearCode('ABC-DEF-GHI');
		$this->assertEquals('ABCDEFGHI', $result);
	}

	public function test_clearCode_removes_colons(): void {
		$result = $this->main->getCore()->clearCode('AB:CD:EF');
		$this->assertEquals('ABCDEF', $result);
	}

	public function test_clearCode_removes_spaces(): void {
		$result = $this->main->getCore()->clearCode('AB CD EF');
		$this->assertEquals('ABCDEF', $result);
	}

	public function test_clearCode_trims_whitespace(): void {
		$result = $this->main->getCore()->clearCode('  ABCDEF  ');
		$this->assertEquals('ABCDEF', $result);
	}

	public function test_clearCode_url_decodes(): void {
		// urldecode runs AFTER space removal, so %20 decodes to space which remains
		$result = $this->main->getCore()->clearCode('ABC%20DEF');
		$this->assertEquals('ABC DEF', $result);
	}

	public function test_clearCode_strips_tags(): void {
		$result = $this->main->getCore()->clearCode('<b>ABCDEF</b>');
		$this->assertEquals('ABCDEF', $result);
	}

	public function test_clearCode_fires_filter(): void {
		$filtered = false;
		$callback = function ($code) use (&$filtered) {
			$filtered = true;
			return $code;
		};
		add_filter($this->main->_add_filter_prefix . 'core_clearCode', $callback);

		$this->main->getCore()->clearCode('TESTCODE');

		$this->assertTrue($filtered);
		remove_filter($this->main->_add_filter_prefix . 'core_clearCode', $callback);
	}

	// ── getCodesByRegUserId ────────────────────────────────────

	public function test_getCodesByRegUserId_returns_empty_for_zero(): void {
		$result = $this->main->getCore()->getCodesByRegUserId(0);
		$this->assertEmpty($result);
	}

	public function test_getCodesByRegUserId_returns_empty_for_negative(): void {
		$result = $this->main->getCore()->getCodesByRegUserId(-5);
		$this->assertEmpty($result);
	}

	public function test_getCodesByRegUserId_returns_codes_for_user(): void {
		$userId = $this->factory->user->create(['role' => 'subscriber']);

		$listId = $this->main->getDB()->insert('lists', [
			'name' => 'UserQuery List ' . uniqid(),
			'aktiv' => 1,
			'meta' => '{}',
		]);
		$code = 'USERQ' . strtoupper(uniqid());
		$this->main->getDB()->insert('codes', [
			'list_id' => $listId,
			'code' => $code,
			'aktiv' => 1,
			'cvv' => '',
			'order_id' => 0,
			'user_id' => $userId,
			'meta' => '{}',
		]);

		$result = $this->main->getCore()->getCodesByRegUserId($userId);

		$this->assertNotEmpty($result);
		$this->assertEquals($code, $result[0]['code']);
	}

	// ── getCodesByOrderId ──────────────────────────────────────

	public function test_getCodesByOrderId_returns_empty_for_zero(): void {
		$result = $this->main->getCore()->getCodesByOrderId(0);
		$this->assertEmpty($result);
	}

	public function test_getCodesByOrderId_returns_empty_for_nonexistent(): void {
		$result = $this->main->getCore()->getCodesByOrderId(999999);
		$this->assertEmpty($result);
	}

	// ── retrieveCodeByCode ─────────────────────────────────────

	public function test_retrieveCodeByCode_finds_existing_code(): void {
		$listId = $this->main->getDB()->insert('lists', [
			'name' => 'RetrieveTest ' . uniqid(),
			'aktiv' => 1,
			'meta' => '{}',
		]);
		$code = 'RTRVTEST' . strtoupper(uniqid());
		$this->main->getDB()->insert('codes', [
			'list_id' => $listId,
			'code' => $code,
			'aktiv' => 1,
			'cvv' => '',
			'order_id' => 0,
			'user_id' => 0,
			'meta' => '{}',
		]);

		$result = $this->main->getCore()->retrieveCodeByCode($code);

		$this->assertIsArray($result);
		$this->assertEquals($code, $result['code']);
	}

	public function test_retrieveCodeByCode_with_list_join(): void {
		$listName = 'JoinTest ' . uniqid();
		$listId = $this->main->getDB()->insert('lists', [
			'name' => $listName,
			'aktiv' => 1,
			'meta' => '{}',
		]);
		$code = 'JOINTEST' . strtoupper(uniqid());
		$this->main->getDB()->insert('codes', [
			'list_id' => $listId,
			'code' => $code,
			'aktiv' => 1,
			'cvv' => '',
			'order_id' => 0,
			'user_id' => 0,
			'meta' => '{}',
		]);

		$result = $this->main->getCore()->retrieveCodeByCode($code, true);

		$this->assertIsArray($result);
		$this->assertArrayHasKey('list_name', $result);
		$this->assertEquals($listName, $result['list_name']);
	}

	public function test_retrieveCodeByCode_throws_for_empty(): void {
		$this->expectException(Exception::class);
		$this->expectExceptionMessageMatches('/#203/');

		$this->main->getCore()->retrieveCodeByCode('');
	}

	public function test_retrieveCodeByCode_throws_for_not_found(): void {
		$this->expectException(Exception::class);
		$this->expectExceptionMessageMatches('/#204/');

		$this->main->getCore()->retrieveCodeByCode('NONEXISTENT' . strtoupper(uniqid()));
	}

	// ── getListById ────────────────────────────────────────────

	public function test_getListById_returns_list(): void {
		$listName = 'GetByIdTest ' . uniqid();
		$listId = $this->main->getDB()->insert('lists', [
			'name' => $listName,
			'aktiv' => 1,
			'meta' => '{}',
		]);

		$result = $this->main->getCore()->getListById($listId);

		$this->assertIsArray($result);
		$this->assertEquals($listName, $result['name']);
		$this->assertEquals($listId, $result['id']);
	}

	public function test_getListById_throws_for_nonexistent(): void {
		$this->expectException(Exception::class);
		$this->expectExceptionMessageMatches('/#9232/');

		$this->main->getCore()->getListById(999999);
	}

	// ── encodeMetaValuesAndFillObject ──────────────────────────

	public function test_encodeMetaValues_decodes_json(): void {
		$meta = json_encode(['confirmedCount' => 3, 'wc_ticket' => ['is_ticket' => 1]]);
		$codeObj = ['id' => 1, 'code' => 'TEST', 'meta' => $meta];

		$result = $this->main->getCore()->encodeMetaValuesAndFillObject($meta, $codeObj);

		$this->assertIsArray($result);
		$this->assertEquals(3, $result['confirmedCount']);
		$this->assertEquals(1, $result['wc_ticket']['is_ticket']);
	}

	public function test_encodeMetaValues_handles_empty_meta(): void {
		$codeObj = ['id' => 1, 'code' => 'TEST', 'meta' => '{}'];

		$result = $this->main->getCore()->encodeMetaValuesAndFillObject('{}', $codeObj);

		$this->assertIsArray($result);
	}

	public function test_encodeMetaValues_returns_wc_ticket_struct(): void {
		// The method decodes meta JSON and ensures wc_ticket structure exists
		$meta = json_encode([
			'wc_ticket' => ['is_ticket' => 1, '_public_ticket_id' => 'ABC123'],
			'woocommerce' => ['order_id' => 42, 'product_id' => 99],
		]);
		$codeObj = ['id' => 1, 'code' => 'TEST123', 'meta' => $meta, 'aktiv' => 1];

		$result = $this->main->getCore()->encodeMetaValuesAndFillObject($meta, $codeObj);

		$this->assertIsArray($result);
		$this->assertArrayHasKey('wc_ticket', $result);
		$this->assertEquals(1, $result['wc_ticket']['is_ticket']);
		$this->assertEquals('ABC123', $result['wc_ticket']['_public_ticket_id']);
	}
}
