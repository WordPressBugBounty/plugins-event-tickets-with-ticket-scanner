<?php
/**
 * Tests for Frontend code checking: checkCode logic via
 * various code states (valid, invalid, inactive, used).
 */

class FrontendCheckCodeTest extends WP_UnitTestCase {

    private $main;

    public function set_up(): void {
        parent::set_up();
        $this->main = sasoEventtickets::Instance();

        if (!class_exists('WC_Product_Simple')) {
            $this->markTestSkipped('WooCommerce not available');
        }
    }

    private function createCodeInList(): array {
        $listId = $this->main->getDB()->insert('lists', [
            'name' => 'CheckCode List ' . uniqid(),
            'aktiv' => 1,
            'meta' => '{}',
        ]);

        $product = new WC_Product_Simple();
        $product->set_name('CheckCode Product ' . uniqid());
        $product->set_regular_price('10.00');
        $product->set_status('publish');
        $product->save();

        update_post_meta($product->get_id(), 'saso_eventtickets_is_ticket', 'yes');
        update_post_meta($product->get_id(), 'saso_eventtickets_list', $listId);

        $order = wc_create_order();
        $order->add_product($product, 1);
        $order->calculate_totals();
        $order->set_status('completed');
        $order->save();

        $this->main->getWC()->getOrderManager()->add_serialcode_to_order($order->get_id());
        $codes = $this->main->getCore()->getCodesByOrderId($order->get_id());

        return [
            'code' => $codes[0]['code'],
            'list_id' => $listId,
            'order_id' => $order->get_id(),
            'product_id' => $product->get_id(),
        ];
    }

    // ── isUsed with fresh code ───────────────────────────────────

    public function test_fresh_code_is_not_used(): void {
        $data = $this->createCodeInList();
        $codeObj = $this->main->getCore()->retrieveCodeByCode($data['code']);
        $this->assertFalse($this->main->getFrontend()->isUsed($codeObj));
    }

    // ── isUsed with marked code ──────────────────────────────────

    public function test_marked_code_is_used(): void {
        $data = $this->createCodeInList();
        $codeObj = $this->main->getCore()->retrieveCodeByCode($data['code']);

        // Set used meta
        $metaObj = $this->main->getCore()->encodeMetaValuesAndFillObject($codeObj['meta'], $codeObj);
        $metaObj['used']['reg_request'] = wp_date('Y-m-d H:i:s');
        $metaJson = $this->main->getCore()->json_encode_with_error_handling($metaObj);
        $this->main->getDB()->update('codes', ['meta' => $metaJson], ['id' => $codeObj['id']]);

        $codeObj = $this->main->getCore()->retrieveCodeByCode($data['code']);
        $this->assertTrue($this->main->getFrontend()->isUsed($codeObj));
    }

    // ── markAsUsed ───────────────────────────────────────────────

    public function test_markAsUsed_returns_codeObj(): void {
        $data = $this->createCodeInList();
        $codeObj = $this->main->getCore()->retrieveCodeByCode($data['code']);
        $result = $this->main->getFrontend()->markAsUsed($codeObj);
        $this->assertIsArray($result);
        $this->assertArrayHasKey('code', $result);
    }

    public function test_markAsUsed_force_marks_code(): void {
        $data = $this->createCodeInList();
        $codeObj = $this->main->getCore()->retrieveCodeByCode($data['code']);

        // Force mark as used
        $result = $this->main->getFrontend()->markAsUsed($codeObj, true);

        // Re-fetch and check
        $updatedObj = $this->main->getCore()->retrieveCodeByCode($data['code']);
        $metaObj = $this->main->getCore()->encodeMetaValuesAndFillObject($updatedObj['meta'], $updatedObj);
        $this->assertNotEmpty($metaObj['used']['reg_request']);
    }

    // ── Code states ──────────────────────────────────────────────

    public function test_active_code_has_aktiv_1(): void {
        $data = $this->createCodeInList();
        $codeObj = $this->main->getCore()->retrieveCodeByCode($data['code']);
        $this->assertEquals(1, intval($codeObj['aktiv']));
    }

    public function test_inactive_code_has_aktiv_0(): void {
        $data = $this->createCodeInList();
        $codeObj = $this->main->getCore()->retrieveCodeByCode($data['code']);

        // Deactivate
        $this->main->getDB()->update('codes', ['aktiv' => 0], ['id' => $codeObj['id']]);

        $updatedObj = $this->main->getCore()->retrieveCodeByCode($data['code']);
        $this->assertEquals(0, intval($updatedObj['aktiv']));
    }

    // ── Retrieve code verification ───────────────────────────────

    public function test_retrieveCodeByCode_throws_for_nonexistent(): void {
        $this->expectException(Exception::class);
        $this->main->getCore()->retrieveCodeByCode('NONEXISTENT-CODE-' . uniqid());
    }

    public function test_retrieveCodeByCode_returns_correct_code(): void {
        $data = $this->createCodeInList();
        $codeObj = $this->main->getCore()->retrieveCodeByCode($data['code']);
        $this->assertEquals($data['code'], $codeObj['code']);
    }
}
