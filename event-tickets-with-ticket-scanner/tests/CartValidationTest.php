<?php
/**
 * Tests for WooCommerce cart validation and code checking.
 */

class CartValidationTest extends WP_UnitTestCase {

    private $main;

    public function set_up(): void {
        parent::set_up();
        $this->main = sasoEventtickets::Instance();

        if (!class_exists('WC_Product_Simple')) {
            $this->markTestSkipped('WooCommerce not available');
        }
    }

    /**
     * Helper: create a code list with codes.
     */
    private function createListWithCode(string $codeStr, bool $active = true, bool $used = false): array {
        $listId = $this->main->getDB()->insert('lists', [
            'name' => 'Cart Test List ' . uniqid(),
            'aktiv' => 1,
            'meta' => '{}',
        ]);

        $metaObj = $this->main->getCore()->getMetaObject();
        $metaJson = $this->main->getCore()->json_encode_with_error_handling($metaObj);

        $codeId = $this->main->getDB()->insert('codes', [
            'code' => $codeStr,
            'code_display' => $codeStr,
            'cvv' => '',
            'meta' => $metaJson,
            'aktiv' => $active ? 1 : 0,
            'redeemed' => $used ? 1 : 0,
            'list_id' => $listId,
            'order_id' => 0,
        ]);

        return ['list_id' => $listId, 'code_id' => $codeId, 'code' => $codeStr];
    }

    /**
     * Helper: create a product with code list restriction.
     */
    private function createProductWithRestriction(int $listId): WC_Product_Simple {
        $product = new WC_Product_Simple();
        $product->set_name('Restricted Product');
        $product->set_regular_price('10.00');
        $product->set_status('publish');
        $product->save();

        update_post_meta($product->get_id(), 'saso_eventtickets_list_sale_restriction', $listId);

        return $product;
    }

    // ── check_code_for_cartitem ──────────────────────────────────
    // Return values: 0=empty, 1=valid, 2=used, 3=not valid, 4=no code list

    public function test_check_code_for_cartitem_empty_code_returns_0(): void {
        $listData = $this->createListWithCode('EMPTY' . uniqid());
        $product = $this->createProductWithRestriction($listData['list_id']);

        $cartItem = ['product_id' => $product->get_id()];
        $frontend = $this->main->getWC()->getFrontendManager();
        $result = $frontend->check_code_for_cartitem($cartItem, '');
        $this->assertEquals(0, $result);
    }

    public function test_check_code_for_cartitem_valid_code_returns_1(): void {
        $code = 'VALID' . strtoupper(uniqid());
        $listData = $this->createListWithCode($code);
        $product = $this->createProductWithRestriction($listData['list_id']);

        $cartItem = ['product_id' => $product->get_id()];
        $frontend = $this->main->getWC()->getFrontendManager();
        $result = $frontend->check_code_for_cartitem($cartItem, $code);
        $this->assertEquals(1, $result);
    }

    public function test_check_code_for_cartitem_used_code_returns_2(): void {
        $code = 'USED' . strtoupper(uniqid());
        $listData = $this->createListWithCode($code, true, false);

        // Mark as "used" via meta (isUsed checks meta['used']['reg_request'])
        $codeObj = $this->main->getCore()->retrieveCodeByCode($code);
        $metaObj = $this->main->getCore()->encodeMetaValuesAndFillObject($codeObj['meta'], $codeObj);
        $metaObj['used']['reg_request'] = wp_date('Y-m-d H:i:s');
        $metaJson = $this->main->getCore()->json_encode_with_error_handling($metaObj);
        $this->main->getDB()->update('codes', ['meta' => $metaJson], ['id' => $codeObj['id']]);

        $product = $this->createProductWithRestriction($listData['list_id']);

        $cartItem = ['product_id' => $product->get_id()];
        $frontend = $this->main->getWC()->getFrontendManager();
        $result = $frontend->check_code_for_cartitem($cartItem, $code);
        $this->assertEquals(2, $result);
    }

    public function test_check_code_for_cartitem_inactive_code_returns_3(): void {
        $code = 'INACT' . strtoupper(uniqid());
        $listData = $this->createListWithCode($code, false);
        $product = $this->createProductWithRestriction($listData['list_id']);

        $cartItem = ['product_id' => $product->get_id()];
        $frontend = $this->main->getWC()->getFrontendManager();
        $result = $frontend->check_code_for_cartitem($cartItem, $code);
        $this->assertEquals(3, $result);
    }

    public function test_check_code_for_cartitem_nonexistent_code_returns_3(): void {
        $listData = $this->createListWithCode('EXISTS' . uniqid());
        $product = $this->createProductWithRestriction($listData['list_id']);

        $cartItem = ['product_id' => $product->get_id()];
        $frontend = $this->main->getWC()->getFrontendManager();
        $result = $frontend->check_code_for_cartitem($cartItem, 'DOESNOTEXIST' . uniqid());
        $this->assertEquals(3, $result);
    }

    public function test_check_code_for_cartitem_no_restriction_returns_4(): void {
        // Product without code list restriction
        $product = new WC_Product_Simple();
        $product->set_name('No Restriction');
        $product->set_regular_price('5.00');
        $product->set_status('publish');
        $product->save();

        $cartItem = ['product_id' => $product->get_id()];
        $frontend = $this->main->getWC()->getFrontendManager();
        $result = $frontend->check_code_for_cartitem($cartItem, 'ANYCODE');
        $this->assertEquals(4, $result);
    }

    public function test_check_code_for_cartitem_wrong_list_returns_3(): void {
        $code = 'WRONGLIST' . strtoupper(uniqid());
        $listData = $this->createListWithCode($code);

        // Create a product restricted to a DIFFERENT list
        $otherListId = $this->main->getDB()->insert('lists', [
            'name' => 'Other List ' . uniqid(),
            'aktiv' => 1,
            'meta' => '{}',
        ]);
        $product = $this->createProductWithRestriction($otherListId);

        $cartItem = ['product_id' => $product->get_id()];
        $frontend = $this->main->getWC()->getFrontendManager();
        $result = $frontend->check_code_for_cartitem($cartItem, $code);
        $this->assertEquals(3, $result);
    }

    // ── check_code_for_cartitem with list_id = "0" (any list) ────

    public function test_check_code_for_cartitem_any_list_accepts_code(): void {
        $code = 'ANYLIST' . strtoupper(uniqid());
        $listData = $this->createListWithCode($code);

        // Restriction with list_id = "0" means any list is allowed
        $product = $this->createProductWithRestriction(0);
        // Override: meta key needs a truthy value for the restriction to be "set"
        // The code checks !empty(), and "0" is empty in PHP, so we need a non-zero list ID
        // Actually with list_id = "0", the condition `$saso_eventtickets_list_id != "0"` is false,
        // so any code from any list would be valid
        update_post_meta($product->get_id(), 'saso_eventtickets_list_sale_restriction', '0');

        // But "0" is empty in PHP's !empty(), so it returns 4 (no code list)
        // This is actually the behavior: restriction "0" = no restriction
        $cartItem = ['product_id' => $product->get_id()];
        $frontend = $this->main->getWC()->getFrontendManager();
        $result = $frontend->check_code_for_cartitem($cartItem, $code);
        $this->assertEquals(4, $result);
    }

    // ── deleteCodesEntryOnOrderItem ──────────────────────────────

    public function test_deleteCodesEntryOnOrderItem_clears_meta(): void {
        // Create an order with ticket items
        $listId = $this->main->getDB()->insert('lists', [
            'name' => 'Delete Meta Test ' . uniqid(),
            'aktiv' => 1,
            'meta' => '{}',
        ]);

        $product = new WC_Product_Simple();
        $product->set_name('Meta Delete Product');
        $product->set_regular_price('10.00');
        $product->set_status('publish');
        $product->save();

        update_post_meta($product->get_id(), 'saso_eventtickets_is_ticket', 'yes');
        update_post_meta($product->get_id(), 'saso_eventtickets_list', $listId);

        $order = wc_create_order();
        $order->add_product($product, 1);
        $order->set_status('completed');
        $order->save();

        // Generate tickets
        $wcOrder = $this->main->getWC()->getOrderManager();
        $wcOrder->add_serialcode_to_order($order->get_id());

        // Get the order item ID
        $items = $order->get_items();
        $itemId = array_key_first($items);

        // Verify meta exists
        $codes = wc_get_order_item_meta($itemId, '_saso_eventtickets_product_code', true);
        $this->assertNotEmpty($codes, 'Ticket codes should exist on order item');

        // Delete codes entry
        $wcOrder->deleteCodesEntryOnOrderItem($itemId);

        // Verify meta is cleared
        $codesAfter = wc_get_order_item_meta($itemId, '_saso_eventtickets_product_code', true);
        $this->assertEmpty($codesAfter, 'Ticket codes should be cleared after deleteCodesEntryOnOrderItem');
    }
}
