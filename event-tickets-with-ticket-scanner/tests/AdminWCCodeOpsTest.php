<?php
/**
 * Tests for AdminSettings WooCommerce code operations:
 * removeRedeemWoocommerceTicketForCode, removeWoocommerceTicketForCode,
 * removeWoocommerceRstrPurchaseInfoFromCode, setWoocommerceTicketInfoForCode,
 * addCodeFromListForOrder, addRetrictionCodeToOrder.
 */

class AdminWCCodeOpsTest extends WP_UnitTestCase {

    private $main;

    public function set_up(): void {
        parent::set_up();
        $this->main = sasoEventtickets::Instance();

        if (!class_exists('WC_Product_Simple')) {
            $this->markTestSkipped('WooCommerce not available');
        }
    }

    private function createTicketProduct(): array {
        $listId = $this->main->getDB()->insert('lists', [
            'name' => 'WCOps List ' . uniqid(),
            'aktiv' => 1,
            'meta' => '{}',
        ]);

        $product = new WC_Product_Simple();
        $product->set_name('WCOps Product ' . uniqid());
        $product->set_regular_price('20.00');
        $product->set_status('publish');
        $product->save();

        update_post_meta($product->get_id(), 'saso_eventtickets_is_ticket', 'yes');
        update_post_meta($product->get_id(), 'saso_eventtickets_list', $listId);

        return ['product' => $product, 'product_id' => $product->get_id(), 'list_id' => $listId];
    }

    private function createOrderWithTickets(): array {
        $tp = $this->createTicketProduct();

        $order = wc_create_order();
        $order->add_product($tp['product'], 1);
        $order->calculate_totals();
        $order->set_status('completed');
        $order->save();

        $this->main->getWC()->getOrderManager()->add_serialcode_to_order($order->get_id());

        $codes = $this->main->getCore()->getCodesByOrderId($order->get_id());

        return [
            'order' => $order,
            'order_id' => $order->get_id(),
            'codes' => $codes,
            'list_id' => $tp['list_id'],
            'product_id' => $tp['product_id'],
        ];
    }

    // ── removeRedeemWoocommerceTicketForCode ─────────────────────

    public function test_removeRedeemWoocommerceTicketForCode_clears_redeem(): void {
        $data = $this->createOrderWithTickets();
        $code = $data['codes'][0]['code'];

        // Set as redeemed first
        $codeObj = $this->main->getCore()->retrieveCodeByCode($code);
        $metaObj = $this->main->getCore()->encodeMetaValuesAndFillObject($codeObj['meta'], $codeObj);
        $metaObj['wc_ticket']['redeemed_date'] = wp_date('Y-m-d H:i:s');
        $metaJson = $this->main->getCore()->json_encode_with_error_handling($metaObj);
        $this->main->getDB()->update('codes', ['meta' => $metaJson, 'redeemed' => 1], ['id' => $codeObj['id']]);

        // Verify it's redeemed
        $codeObj = $this->main->getCore()->retrieveCodeByCode($code);
        $this->assertEquals(1, intval($codeObj['redeemed']));

        // Remove redeem
        $this->main->getAdmin()->removeRedeemWoocommerceTicketForCode(['code' => $code]);

        $updated = $this->main->getCore()->retrieveCodeByCode($code);
        $updatedMeta = $this->main->getCore()->encodeMetaValuesAndFillObject($updated['meta'], $updated);

        $this->assertEquals(0, intval($updated['redeemed']));
        $this->assertEmpty($updatedMeta['wc_ticket']['redeemed_date']);
    }

    public function test_removeRedeemWoocommerceTicketForCode_missing_code_throws(): void {
        $this->expectException(Exception::class);
        $this->main->getAdmin()->removeRedeemWoocommerceTicketForCode([]);
    }

    // ── removeWoocommerceTicketForCode ───────────────────────────

    public function test_removeWoocommerceTicketForCode_clears_ticket_info(): void {
        $data = $this->createOrderWithTickets();
        $code = $data['codes'][0]['code'];

        $this->main->getAdmin()->removeWoocommerceTicketForCode(['code' => $code]);

        $updated = $this->main->getCore()->retrieveCodeByCode($code);
        $updatedMeta = $this->main->getCore()->encodeMetaValuesAndFillObject($updated['meta'], $updated);

        $this->assertEquals(0, intval($updatedMeta['wc_ticket']['is_ticket']));
    }

    public function test_removeWoocommerceTicketForCode_missing_code_throws(): void {
        $this->expectException(Exception::class);
        $this->main->getAdmin()->removeWoocommerceTicketForCode([]);
    }

    // ── setWoocommerceTicketInfoForCode ──────────────────────────

    public function test_setWoocommerceTicketInfoForCode_sets_name_and_value(): void {
        $data = $this->createOrderWithTickets();
        $code = $data['codes'][0]['code'];

        $this->main->getAdmin()->setWoocommerceTicketInfoForCode(
            $code,
            'VIP Pass',
            'John Doe'
        );

        $updated = $this->main->getCore()->retrieveCodeByCode($code);
        $updatedMeta = $this->main->getCore()->encodeMetaValuesAndFillObject($updated['meta'], $updated);

        $this->assertEquals('VIP Pass', $updatedMeta['wc_ticket']['name_per_ticket']);
        $this->assertEquals('John Doe', $updatedMeta['wc_ticket']['value_per_ticket']);
    }

    public function test_setWoocommerceTicketInfoForCode_sets_daychooser(): void {
        $data = $this->createOrderWithTickets();
        $code = $data['codes'][0]['code'];

        $this->main->getAdmin()->setWoocommerceTicketInfoForCode(
            $code,
            '',
            '',
            '2026-06-15'
        );

        $updated = $this->main->getCore()->retrieveCodeByCode($code);
        $updatedMeta = $this->main->getCore()->encodeMetaValuesAndFillObject($updated['meta'], $updated);

        $this->assertEquals('2026-06-15', $updatedMeta['wc_ticket']['day_per_ticket']);
        $this->assertEquals(1, intval($updatedMeta['wc_ticket']['is_daychooser']));
    }

    // ── addCodeFromListForOrder ──────────────────────────────────

    public function test_addCodeFromListForOrder_assigns_code(): void {
        $listId = $this->main->getDB()->insert('lists', [
            'name' => 'FromList ' . uniqid(),
            'aktiv' => 1,
            'meta' => '{}',
        ]);

        // Pre-add a code to the list
        $metaObj = $this->main->getCore()->getMetaObject();
        $metaJson = $this->main->getCore()->json_encode_with_error_handling($metaObj);
        $code = 'FROMLIST' . strtoupper(uniqid());
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

        $product = new WC_Product_Simple();
        $product->set_name('FromList Product');
        $product->set_regular_price('10.00');
        $product->set_status('publish');
        $product->save();

        $order = wc_create_order();
        $itemId = $order->add_product($product, 1);
        $order->calculate_totals();
        $order->set_status('completed');
        $order->save();

        $result = $this->main->getAdmin()->addCodeFromListForOrder(
            $listId,
            $order->get_id(),
            $product->get_id(),
            $itemId
        );

        // Returns the code string directly
        $this->assertIsString($result);
        $this->assertNotEmpty($result);

        // The code should now be assigned to the order
        $codeObj = $this->main->getCore()->retrieveCodeByCode($result);
        $this->assertEquals($order->get_id(), intval($codeObj['order_id']));
    }

    public function test_addCodeFromListForOrder_no_available_codes_creates_new(): void {
        $listId = $this->main->getDB()->insert('lists', [
            'name' => 'EmptyList ' . uniqid(),
            'aktiv' => 1,
            'meta' => '{}',
        ]);

        $product = new WC_Product_Simple();
        $product->set_name('EmptyList Product');
        $product->set_regular_price('10.00');
        $product->set_status('publish');
        $product->save();

        $order = wc_create_order();
        $itemId = $order->add_product($product, 1);
        $order->calculate_totals();
        $order->set_status('completed');
        $order->save();

        $result = $this->main->getAdmin()->addCodeFromListForOrder(
            $listId,
            $order->get_id(),
            $product->get_id(),
            $itemId
        );

        $this->assertIsString($result);
        $this->assertNotEmpty($result);
    }
}
