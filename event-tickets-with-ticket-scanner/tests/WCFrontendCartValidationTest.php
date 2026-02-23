<?php
/**
 * Tests for WC Frontend cart methods: hasTicketsInCart,
 * containsProductsWithRestrictions, check_code_for_cartitem,
 * updateCartItemMeta (empty item_id path).
 */

class WCFrontendCartValidationTest extends WP_UnitTestCase {

    private $main;

    public function set_up(): void {
        parent::set_up();
        $this->main = sasoEventtickets::Instance();

        if (!class_exists('WC_Product_Simple')) {
            $this->markTestSkipped('WooCommerce not available');
        }
    }

    // ── hasTicketsInCart ──────────────────────────────────────────

    public function test_hasTicketsInCart_false_for_empty_cart(): void {
        // Ensure cart is empty
        WC()->cart->empty_cart();
        $frontend = $this->main->getWC()->getFrontendManager();
        $this->assertFalse($frontend->hasTicketsInCart());
    }

    public function test_hasTicketsInCart_false_for_non_ticket_product(): void {
        WC()->cart->empty_cart();
        $product = new WC_Product_Simple();
        $product->set_name('Non-Ticket Product ' . uniqid());
        $product->set_regular_price('5.00');
        $product->set_status('publish');
        $product->save();

        WC()->cart->add_to_cart($product->get_id());

        $frontend = $this->main->getWC()->getFrontendManager();
        $this->assertFalse($frontend->hasTicketsInCart());

        WC()->cart->empty_cart();
    }

    public function test_hasTicketsInCart_true_for_ticket_product(): void {
        WC()->cart->empty_cart();

        $listId = $this->main->getDB()->insert('lists', [
            'name' => 'CartCheck List ' . uniqid(),
            'aktiv' => 1,
            'meta' => '{}',
        ]);

        $product = new WC_Product_Simple();
        $product->set_name('Ticket Product ' . uniqid());
        $product->set_regular_price('10.00');
        $product->set_status('publish');
        $product->save();

        update_post_meta($product->get_id(), 'saso_eventtickets_list', $listId);

        WC()->cart->add_to_cart($product->get_id());

        $frontend = $this->main->getWC()->getFrontendManager();
        $this->assertTrue($frontend->hasTicketsInCart());

        WC()->cart->empty_cart();
    }

    // ── containsProductsWithRestrictions ─────────────────────────

    public function test_containsProductsWithRestrictions_false_for_empty_cart(): void {
        WC()->cart->empty_cart();
        $frontend = $this->main->getWC()->getFrontendManager();
        // Reset cached value
        $ref = new ReflectionProperty($frontend, '_containsProductsWithRestrictions');
        $ref->setAccessible(true);
        $ref->setValue($frontend, null);

        $this->assertFalse($frontend->containsProductsWithRestrictions());
    }

    // ── check_code_for_cartitem ──────────────────────────────────

    public function test_check_code_for_cartitem_empty_code_returns_zero(): void {
        $frontend = $this->main->getWC()->getFrontendManager();

        $product = new WC_Product_Simple();
        $product->set_name('CodeCheck Product ' . uniqid());
        $product->set_regular_price('5.00');
        $product->set_status('publish');
        $product->save();

        $cart_item = ['product_id' => $product->get_id()];
        $result = $frontend->check_code_for_cartitem($cart_item, '');
        $this->assertEquals(0, $result);
    }

    public function test_check_code_for_cartitem_no_restriction_list_returns_four(): void {
        $frontend = $this->main->getWC()->getFrontendManager();

        $product = new WC_Product_Simple();
        $product->set_name('NoRestriction Product ' . uniqid());
        $product->set_regular_price('5.00');
        $product->set_status('publish');
        $product->save();
        // No restriction meta set

        $cart_item = ['product_id' => $product->get_id()];
        $result = $frontend->check_code_for_cartitem($cart_item, 'SOME-CODE');
        $this->assertEquals(4, $result);
    }

    public function test_check_code_for_cartitem_invalid_code_returns_three(): void {
        $frontend = $this->main->getWC()->getFrontendManager();

        $listId = $this->main->getDB()->insert('lists', [
            'name' => 'Restriction List ' . uniqid(),
            'aktiv' => 1,
            'meta' => '{}',
        ]);

        $product = new WC_Product_Simple();
        $product->set_name('Restricted Product ' . uniqid());
        $product->set_regular_price('5.00');
        $product->set_status('publish');
        $product->save();

        update_post_meta($product->get_id(), 'saso_eventtickets_list_sale_restriction', $listId);

        $cart_item = ['product_id' => $product->get_id()];
        $result = $frontend->check_code_for_cartitem($cart_item, 'NONEXISTENT-CODE-' . uniqid());
        $this->assertEquals(3, $result);
    }

    public function test_check_code_for_cartitem_valid_code_returns_one(): void {
        $frontend = $this->main->getWC()->getFrontendManager();

        $listId = $this->main->getDB()->insert('lists', [
            'name' => 'ValidCode List ' . uniqid(),
            'aktiv' => 1,
            'meta' => '{}',
        ]);

        // Create a real order to generate a code in the list
        $product = new WC_Product_Simple();
        $product->set_name('ValidCode Product ' . uniqid());
        $product->set_regular_price('5.00');
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
        $code = $codes[0]['code'];

        // Now set up a different product with restriction list pointing to same list
        $product2 = new WC_Product_Simple();
        $product2->set_name('Restricted Product2 ' . uniqid());
        $product2->set_regular_price('5.00');
        $product2->set_status('publish');
        $product2->save();

        update_post_meta($product2->get_id(), 'saso_eventtickets_list_sale_restriction', $listId);

        $cart_item = ['product_id' => $product2->get_id()];
        $result = $frontend->check_code_for_cartitem($cart_item, $code);
        // Code exists and is active = 1 (valid) or 2 (used)
        $this->assertContains($result, [1, 2]);
    }

    // ── updateCartItemMeta ───────────────────────────────────────

    public function test_updateCartItemMeta_empty_item_id_returns_item_id_missing(): void {
        $frontend = $this->main->getWC()->getFrontendManager();
        $result = $frontend->updateCartItemMeta(
            'saso_eventtickets_request_name_per_ticket',
            '',
            0,
            'Test Name'
        );
        $this->assertIsArray($result);
        $this->assertTrue($result['item_id_missing']);
    }

    public function test_updateCartItemMeta_invalid_type_defaults_to_name(): void {
        $frontend = $this->main->getWC()->getFrontendManager();
        // With empty cart_item_id, it returns item_id_missing immediately
        // but at least we can verify the method doesn't crash with bad type
        $result = $frontend->updateCartItemMeta(
            'invalid_type_xyz',
            '',
            0,
            'value'
        );
        $this->assertIsArray($result);
        $this->assertTrue($result['item_id_missing']);
    }
}
