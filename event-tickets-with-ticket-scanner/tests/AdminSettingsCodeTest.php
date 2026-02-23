<?php
/**
 * Tests for AdminSettings code management (addCode, addCodes, removeCode, getMetaOfCode, customer name).
 */

class AdminSettingsCodeTest extends WP_UnitTestCase {

    private $main;

    public function set_up(): void {
        parent::set_up();
        $this->main = sasoEventtickets::Instance();
    }

    /**
     * Helper: create a list.
     */
    private function createList(): int {
        return $this->main->getDB()->insert('lists', [
            'name' => 'Admin List ' . uniqid(),
            'aktiv' => 1,
            'meta' => '{}',
        ]);
    }

    // ── addCode ───────────────────────────────────────────────────

    public function test_addCode_creates_code(): void {
        $listId = $this->createList();
        $code = 'ADD' . strtoupper(uniqid());

        $id = $this->main->getAdmin()->addCode(['code' => $code, 'list_id' => $listId]);
        $this->assertIsInt($id);
        $this->assertGreaterThan(0, $id);

        // Verify it exists
        $codeObj = $this->main->getCore()->retrieveCodeByCode($code);
        $this->assertEquals($code, $codeObj['code']);
        $this->assertEquals($listId, intval($codeObj['list_id']));
    }

    public function test_addCode_without_list_uses_zero(): void {
        $code = 'NOLIST' . strtoupper(uniqid());

        $id = $this->main->getAdmin()->addCode(['code' => $code]);
        $this->assertGreaterThan(0, $id);

        $codeObj = $this->main->getCore()->retrieveCodeByCode($code);
        $this->assertEquals(0, intval($codeObj['list_id']));
    }

    public function test_addCode_empty_code_throws(): void {
        $this->expectException(Exception::class);
        $this->main->getAdmin()->addCode(['code' => '']);
    }

    public function test_addCode_missing_code_throws(): void {
        $this->expectException(Exception::class);
        $this->main->getAdmin()->addCode([]);
    }

    // ── addCodes ──────────────────────────────────────────────────

    public function test_addCodes_bulk_creates_codes(): void {
        $listId = $this->createList();
        $codes = [
            'BULK1_' . strtoupper(uniqid()),
            'BULK2_' . strtoupper(uniqid()),
            'BULK3_' . strtoupper(uniqid()),
        ];

        $result = $this->main->getAdmin()->addCodes(['codes' => $codes, 'list_id' => $listId]);
        $this->assertIsArray($result);
        $this->assertCount(3, $result['ok']);
        $this->assertEmpty($result['notok']);
        $this->assertArrayHasKey('total_size', $result);
    }

    public function test_addCodes_duplicate_goes_to_notok(): void {
        $listId = $this->createList();
        $code = 'DUP_' . strtoupper(uniqid());

        // Add first
        $this->main->getAdmin()->addCode(['code' => $code, 'list_id' => $listId]);

        // Try bulk with duplicate
        $result = $this->main->getAdmin()->addCodes(['codes' => [$code], 'list_id' => $listId]);
        $this->assertCount(0, $result['ok']);
        $this->assertCount(1, $result['notok']);
    }

    public function test_addCodes_missing_codes_throws(): void {
        $this->expectException(Exception::class);
        $this->main->getAdmin()->addCodes([]);
    }

    public function test_addCodes_non_array_throws(): void {
        $this->expectException(Exception::class);
        $this->main->getAdmin()->addCodes(['codes' => 'not_an_array']);
    }

    // ── removeCode ────────────────────────────────────────────────

    public function test_removeCode_deletes_code(): void {
        $listId = $this->createList();
        $code = 'REM' . strtoupper(uniqid());

        $id = $this->main->getAdmin()->addCode(['code' => $code, 'list_id' => $listId]);

        $this->main->getAdmin()->removeCode(['id' => $id]);

        // Code should no longer be retrievable
        $this->expectException(Exception::class);
        $this->main->getCore()->retrieveCodeByCode($code);
    }

    public function test_removeCode_missing_id_throws(): void {
        $this->expectException(Exception::class);
        $this->main->getAdmin()->removeCode([]);
    }

    // ── getCustomerName ───────────────────────────────────────────

    public function test_getCustomerName_with_valid_order(): void {
        if (!class_exists('WC_Product_Simple')) {
            $this->markTestSkipped('WooCommerce not available');
        }

        $order = wc_create_order();
        $order->set_billing_first_name('John');
        $order->set_billing_last_name('Doe');
        $order->save();

        $name = $this->main->getAdmin()->getCustomerName($order->get_id());
        $this->assertStringContainsString('John', $name);
        $this->assertStringContainsString('Doe', $name);
    }

    public function test_getCustomerName_caches_result(): void {
        if (!class_exists('WC_Product_Simple')) {
            $this->markTestSkipped('WooCommerce not available');
        }

        $order = wc_create_order();
        $order->set_billing_first_name('Jane');
        $order->set_billing_last_name('Smith');
        $order->save();

        $name1 = $this->main->getAdmin()->getCustomerName($order->get_id());
        $name2 = $this->main->getAdmin()->getCustomerName($order->get_id());
        $this->assertEquals($name1, $name2);
    }

    public function test_getCustomerName_zero_returns_empty(): void {
        $name = $this->main->getAdmin()->getCustomerName(0);
        $this->assertEmpty($name);
    }

    public function test_getCustomerName_invalid_order_returns_string(): void {
        $name = $this->main->getAdmin()->getCustomerName(999999);
        // Returns "Order not found" or empty
        $this->assertIsString($name);
    }

    // ── getCompanyName ────────────────────────────────────────────

    public function test_getCompanyName_with_valid_order(): void {
        if (!class_exists('WC_Product_Simple')) {
            $this->markTestSkipped('WooCommerce not available');
        }

        $order = wc_create_order();
        $order->set_billing_company('Acme Corp');
        $order->save();

        $company = $this->main->getAdmin()->getCompanyName($order->get_id());
        $this->assertEquals('Acme Corp', $company);
    }

    public function test_getCompanyName_zero_returns_empty(): void {
        $company = $this->main->getAdmin()->getCompanyName(0);
        $this->assertEmpty($company);
    }

    // ── getRedeemAmount ───────────────────────────────────────────

    public function test_getRedeemAmount_non_ticket_returns_zeros(): void {
        $listId = $this->createList();
        $code = 'RAMT' . strtoupper(uniqid());
        $this->main->getAdmin()->addCode(['code' => $code, 'list_id' => $listId]);
        $codeObj = $this->main->getCore()->retrieveCodeByCode($code);

        $result = $this->main->getAdmin()->getRedeemAmount($codeObj);
        $this->assertEquals(0, $result['_redeemed_counter']);
        $this->assertEquals(0, $result['_max_redeem_amount']);
    }

    public function test_getRedeemAmount_ticket_with_max_redeem(): void {
        if (!class_exists('WC_Product_Simple')) {
            $this->markTestSkipped('WooCommerce not available');
        }

        $listId = $this->createList();

        $product = new WC_Product_Simple();
        $product->set_name('Redeem Amount Test');
        $product->set_regular_price('10.00');
        $product->set_status('publish');
        $product->save();
        $pid = $product->get_id();

        update_post_meta($pid, 'saso_eventtickets_is_ticket', 'yes');
        update_post_meta($pid, 'saso_eventtickets_list', $listId);
        update_post_meta($pid, 'saso_eventtickets_ticket_max_redeem_amount', 5);

        // Create a code that looks like a ticket
        $metaObj = $this->main->getCore()->getMetaObject();
        $metaObj['wc_ticket']['is_ticket'] = 1;
        $metaObj['woocommerce']['product_id'] = $pid;
        $metaJson = $this->main->getCore()->json_encode_with_error_handling($metaObj);

        $code = 'RMAX' . strtoupper(uniqid());
        $this->main->getDB()->insert('codes', [
            'code' => $code,
            'code_display' => $code,
            'cvv' => '',
            'meta' => $metaJson,
            'aktiv' => 1,
            'redeemed' => 0,
            'list_id' => $listId,
            'order_id' => 0,
        ]);

        $codeObj = $this->main->getCore()->retrieveCodeByCode($code);
        $result = $this->main->getAdmin()->getRedeemAmount($codeObj);
        $this->assertEquals(0, $result['_redeemed_counter']);
        $this->assertEquals(5, $result['_max_redeem_amount']);
        $this->assertArrayHasKey('cache', $result);
    }

    // ── getMetaOfCode ─────────────────────────────────────────────

    public function test_getMetaOfCode_returns_meta_object(): void {
        $listId = $this->createList();
        $code = 'META' . strtoupper(uniqid());
        $this->main->getAdmin()->addCode(['code' => $code, 'list_id' => $listId]);

        $metaObj = $this->main->getAdmin()->getMetaOfCode(['code' => $code]);
        $this->assertIsArray($metaObj);
        $this->assertArrayHasKey('wc_ticket', $metaObj);
        $this->assertArrayHasKey('user', $metaObj);
    }

    public function test_getMetaOfCode_missing_code_throws(): void {
        $this->expectException(Exception::class);
        $this->main->getAdmin()->getMetaOfCode([]);
    }

    public function test_getMetaOfCode_includes_qr_content(): void {
        $listId = $this->createList();
        $code = 'QRC' . strtoupper(uniqid());
        $this->main->getAdmin()->addCode(['code' => $code, 'list_id' => $listId]);

        $metaObj = $this->main->getAdmin()->getMetaOfCode(['code' => $code]);
        $this->assertArrayHasKey('_qr_content', $metaObj['wc_ticket']);
    }
}
