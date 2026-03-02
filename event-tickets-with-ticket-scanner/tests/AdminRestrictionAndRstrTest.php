<?php
/**
 * Batch 47 — AdminSettings restriction code ops and remaining methods:
 * - addRetrictionCodeToOrder: restriction code assignment
 * - removeWoocommerceRstrPurchaseInfoFromCode: restriction purchase cleanup
 * - generateCode / encodeDateToLetters edge cases
 */

class AdminRestrictionAndRstrTest extends WP_UnitTestCase {

	private $main;

	public function set_up(): void {
		parent::set_up();
		$this->main = sasoEventtickets::Instance();

		if (!class_exists('WC_Product_Simple')) {
			$this->markTestSkipped('WooCommerce not available');
		}
	}

	private function createCodeInList(string $codeStr = ''): array {
		$listId = $this->main->getDB()->insert('lists', [
			'name' => 'Rstr Test ' . uniqid(),
			'aktiv' => 1,
			'meta' => '{}',
		]);

		$metaObj = $this->main->getCore()->getMetaObject();
		$metaJson = $this->main->getCore()->json_encode_with_error_handling($metaObj);

		if (empty($codeStr)) {
			$codeStr = 'RSTR' . strtoupper(uniqid());
		}

		$this->main->getDB()->insert('codes', [
			'list_id' => $listId,
			'code' => $codeStr,
			'aktiv' => 1,
			'cvv' => '',
			'order_id' => 0,
			'user_id' => 0,
			'meta' => $metaJson,
		]);

		return ['list_id' => $listId, 'code' => $codeStr];
	}

	// ── addRetrictionCodeToOrder ─────────────────────────────

	public function test_addRetrictionCodeToOrder_assigns_wc_rp_meta(): void {
		$data = $this->createCodeInList();
		$order = wc_create_order();
		$order->save();

		$result = $this->main->getAdmin()->addRetrictionCodeToOrder(
			$data['code'], $data['list_id'], $order->get_id()
		);

		$this->assertIsArray($result);
		$this->assertEquals($data['code'], $result['code']);

		// Verify wc_rp meta was saved
		$reloaded = $this->main->getCore()->retrieveCodeByCode($data['code']);
		$metaObj = $this->main->getCore()->encodeMetaValuesAndFillObject($reloaded['meta'], $reloaded);
		$this->assertEquals($order->get_id(), $metaObj['wc_rp']['order_id']);
	}

	public function test_addRetrictionCodeToOrder_stores_creation_date(): void {
		$data = $this->createCodeInList();
		$order = wc_create_order();
		$order->save();

		$this->main->getAdmin()->addRetrictionCodeToOrder(
			$data['code'], $data['list_id'], $order->get_id()
		);

		$reloaded = $this->main->getCore()->retrieveCodeByCode($data['code']);
		$metaObj = $this->main->getCore()->encodeMetaValuesAndFillObject($reloaded['meta'], $reloaded);
		$this->assertNotEmpty($metaObj['wc_rp']['creation_date']);
		$this->assertNotEmpty($metaObj['wc_rp']['creation_date_tz']);
	}

	public function test_addRetrictionCodeToOrder_with_product_and_item(): void {
		$data = $this->createCodeInList();
		$order = wc_create_order();
		$order->save();

		$this->main->getAdmin()->addRetrictionCodeToOrder(
			$data['code'], $data['list_id'], $order->get_id(), 42, 99
		);

		$reloaded = $this->main->getCore()->retrieveCodeByCode($data['code']);
		$metaObj = $this->main->getCore()->encodeMetaValuesAndFillObject($reloaded['meta'], $reloaded);
		$this->assertEquals(42, $metaObj['wc_rp']['product_id']);
		$this->assertEquals(99, $metaObj['wc_rp']['item_id']);
	}

	public function test_addRetrictionCodeToOrder_empty_code_returns_null(): void {
		$result = $this->main->getAdmin()->addRetrictionCodeToOrder('', 1, 1);
		$this->assertNull($result);
	}

	// ── removeWoocommerceRstrPurchaseInfoFromCode ─────────────

	public function test_removeRstrPurchaseInfo_clears_wc_rp(): void {
		$data = $this->createCodeInList();
		$order = wc_create_order();
		$order->save();

		// First assign restriction
		$this->main->getAdmin()->addRetrictionCodeToOrder(
			$data['code'], $data['list_id'], $order->get_id()
		);

		// Verify it was set
		$reloaded = $this->main->getCore()->retrieveCodeByCode($data['code']);
		$metaObj = $this->main->getCore()->encodeMetaValuesAndFillObject($reloaded['meta'], $reloaded);
		$this->assertGreaterThan(0, $metaObj['wc_rp']['order_id']);

		// Now remove it
		$result = $this->main->getAdmin()->removeWoocommerceRstrPurchaseInfoFromCode([
			'code' => $data['code'],
		]);
		$this->assertIsArray($result);

		// Verify wc_rp was cleared
		$reloaded2 = $this->main->getCore()->retrieveCodeByCode($data['code']);
		$metaObj2 = $this->main->getCore()->encodeMetaValuesAndFillObject($reloaded2['meta'], $reloaded2);
		$this->assertEquals(0, intval($metaObj2['wc_rp']['order_id']));
	}

	public function test_removeRstrPurchaseInfo_throws_without_code(): void {
		$this->expectException(Exception::class);
		$this->expectExceptionMessageMatches('/#9611/');
		$this->main->getAdmin()->removeWoocommerceRstrPurchaseInfoFromCode([]);
	}

	public function test_removeRstrPurchaseInfo_throws_for_nonexistent_code(): void {
		$this->expectException(Exception::class);
		$this->main->getAdmin()->removeWoocommerceRstrPurchaseInfoFromCode([
			'code' => 'NONEXISTENT_' . uniqid(),
		]);
	}

	// ── generateCode edge cases ──────────────────────────────

	public function test_generateCode_with_formatter_numbers_only(): void {
		$ref = new ReflectionMethod($this->main->getAdmin(), 'generateCode');
		$ref->setAccessible(true);

		$formatterJson = json_encode([
			'input_amount_letters' => 8,
			'input_letter_style' => 0,
			'input_include_numbers' => 3, // numbers only
		]);
		$code = $ref->invoke($this->main->getAdmin(), $formatterJson);
		// Should start with date prefix (letters) then hyphen then digits
		$parts = explode('-', $code, 2);
		$this->assertEquals(5, strlen($parts[0]));
		$this->assertMatchesRegularExpression('/^[A-Z]{5}$/', $parts[0]);
		// The random part should be digits only
		$randomPart = str_replace('-', '', $parts[1]);
		$this->assertMatchesRegularExpression('/^[0-9]+$/', $randomPart);
	}

	public function test_generateCode_with_formatter_and_delimiter(): void {
		$ref = new ReflectionMethod($this->main->getAdmin(), 'generateCode');
		$ref->setAccessible(true);

		$formatterJson = json_encode([
			'input_amount_letters' => 9,
			'input_letter_style' => 1, // uppercase
			'input_include_numbers' => 2, // letters + numbers
			'input_serial_delimiter' => 2, // hyphen
			'input_serial_delimiter_space' => 3, // every 3 chars
		]);
		$code = $ref->invoke($this->main->getAdmin(), $formatterJson);

		// Should have date prefix, then formatted code with delimiters
		$this->assertStringStartsWith($this->getDatePrefix() . '-', $code);
	}

	public function test_generateCode_with_exclusions(): void {
		$ref = new ReflectionMethod($this->main->getAdmin(), 'generateCode');
		$ref->setAccessible(true);

		$formatterJson = json_encode([
			'input_amount_letters' => 20,
			'input_letter_style' => 1, // uppercase
			'input_include_numbers' => 0, // letters only
			'input_letter_excl' => 2, // exclude I, L, O, P, Q
		]);
		$code = $ref->invoke($this->main->getAdmin(), $formatterJson);
		$randomPart = substr($code, 6); // skip date prefix + hyphen

		// Should not contain excluded letters
		$this->assertStringNotContainsString('I', $randomPart);
		$this->assertStringNotContainsString('L', $randomPart);
		$this->assertStringNotContainsString('O', $randomPart);
		$this->assertStringNotContainsString('P', $randomPart);
		$this->assertStringNotContainsString('Q', $randomPart);
	}

	private function getDatePrefix(): string {
		$ref = new ReflectionMethod($this->main->getAdmin(), 'encodeDateToLetters');
		$ref->setAccessible(true);
		return $ref->invoke($this->main->getAdmin());
	}
}
