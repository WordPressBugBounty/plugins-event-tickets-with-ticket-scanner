<?php
/**
 * Tests for Admin methods: getFormatWarning, getList, addCodeFromListForOrder.
 */

class AdminFormatWarningTest extends WP_UnitTestCase {

    private $main;

    public function set_up(): void {
        parent::set_up();
        $this->main = sasoEventtickets::Instance();
    }

    // ── getList ──────────────────────────────────────────────────

    public function test_getList_returns_array(): void {
        $listId = $this->main->getDB()->insert('lists', [
            'name' => 'GetList Test ' . uniqid(),
            'aktiv' => 1,
            'meta' => '{}',
        ]);

        $result = $this->main->getAdmin()->getList(['id' => $listId]);
        $this->assertIsArray($result);
        $this->assertEquals($listId, intval($result['id']));
    }

    public function test_getList_throws_for_nonexistent(): void {
        $this->expectException(Exception::class);
        $this->main->getAdmin()->getList(['id' => 999999]);
    }

    public function test_getList_throws_without_id(): void {
        $this->expectException(Exception::class);
        $this->main->getAdmin()->getList([]);
    }

    // ── getFormatWarning ─────────────────────────────────────────

    public function test_getFormatWarning_null_for_clean_list(): void {
        $listId = $this->main->getDB()->insert('lists', [
            'name' => 'Warning Test ' . uniqid(),
            'aktiv' => 1,
            'meta' => '{}',
        ]);

        $result = $this->main->getAdmin()->getFormatWarning($listId);
        $this->assertNull($result);
    }

    public function test_getFormatWarning_null_for_nonexistent(): void {
        $result = $this->main->getAdmin()->getFormatWarning(999999);
        $this->assertNull($result);
    }

    public function test_getFormatWarning_returns_warning_type(): void {
        $metaObj = $this->main->getCore()->encodeMetaValuesAndFillObjectList('{}');
        $metaObj['messages']['format_limit_threshold_warning']['last_email'] = '2026-01-15';
        $metaObj['messages']['format_limit_threshold_warning']['attempts'] = 5;
        $metaJson = $this->main->getCore()->json_encode_with_error_handling($metaObj);

        $listId = $this->main->getDB()->insert('lists', [
            'name' => 'Threshold Warn ' . uniqid(),
            'aktiv' => 1,
            'meta' => $metaJson,
        ]);

        $result = $this->main->getAdmin()->getFormatWarning($listId);
        $this->assertIsArray($result);
        $this->assertEquals('warning', $result['type']);
        $this->assertEquals(5, $result['attempts']);
    }

    public function test_getFormatWarning_returns_critical_type(): void {
        $metaObj = $this->main->getCore()->encodeMetaValuesAndFillObjectList('{}');
        $metaObj['messages']['format_end_warning']['last_email'] = '2026-01-15';
        $metaObj['messages']['format_end_warning']['attempts'] = 10;
        $metaJson = $this->main->getCore()->json_encode_with_error_handling($metaObj);

        $listId = $this->main->getDB()->insert('lists', [
            'name' => 'Critical Warn ' . uniqid(),
            'aktiv' => 1,
            'meta' => $metaJson,
        ]);

        $result = $this->main->getAdmin()->getFormatWarning($listId);
        $this->assertIsArray($result);
        $this->assertEquals('critical', $result['type']);
        $this->assertEquals(10, $result['attempts']);
    }

    // ── addCodeFromListForOrder ───────────────────────────────────

    public function test_addCodeFromListForOrder_throws_for_zero_list(): void {
        $this->expectException(Exception::class);
        $this->main->getAdmin()->addCodeFromListForOrder(0, 0);
    }

    public function test_addCodeFromListForOrder_creates_code_with_order(): void {
        if (!class_exists('WC_Product_Simple')) {
            $this->markTestSkipped('WooCommerce not available');
        }

        $listId = $this->main->getDB()->insert('lists', [
            'name' => 'AddCode Test ' . uniqid(),
            'aktiv' => 1,
            'meta' => '{}',
        ]);

        $order = wc_create_order();
        $order->save();

        $result = $this->main->getAdmin()->addCodeFromListForOrder($listId, $order->get_id());
        // Returns the code string (e.g. "37338-52A59-08834-69F17")
        $this->assertIsString($result);
        $this->assertNotEmpty($result);
    }

    public function test_addCodeFromListForOrder_code_retrievable(): void {
        if (!class_exists('WC_Product_Simple')) {
            $this->markTestSkipped('WooCommerce not available');
        }

        $listId = $this->main->getDB()->insert('lists', [
            'name' => 'CodeList Test ' . uniqid(),
            'aktiv' => 1,
            'meta' => '{}',
        ]);

        $order = wc_create_order();
        $order->save();

        $code = $this->main->getAdmin()->addCodeFromListForOrder($listId, $order->get_id());
        $codeObj = $this->main->getCore()->retrieveCodeByCode($code);
        $this->assertEquals($listId, intval($codeObj['list_id']));
        $this->assertEquals($order->get_id(), intval($codeObj['order_id']));
    }
}
