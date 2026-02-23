<?php
/**
 * Tests for AdminSettings operations: generateFirstCodeList, removeWoocommerceOrderInfoFromCode,
 * downloadTicketInfosOfProduct, getAuthtokens, editAuthtoken, transformMetaObjectToExportColumn.
 */

class AdminListAndCodeOpsTest extends WP_UnitTestCase {

    private $main;

    public function set_up(): void {
        parent::set_up();
        $this->main = sasoEventtickets::Instance();
    }

    // ── generateFirstCodeList ───────────────────────────────────

    public function test_generateFirstCodeList_creates_list_when_none_exist(): void {
        // Delete all lists first
        global $wpdb;
        $table = $wpdb->prefix . 'saso_eventtickets_lists';
        $wpdb->query("DELETE FROM $table");

        $this->main->getAdmin()->generateFirstCodeList();

        $lists = $this->main->getAdmin()->getLists();
        $this->assertNotEmpty($lists, 'generateFirstCodeList should create a list');
        $this->assertEquals('Ticket list', $lists[0]['name']);
    }

    public function test_generateFirstCodeList_does_nothing_when_lists_exist(): void {
        // Ensure at least one list exists
        $this->main->getDB()->insert('lists', [
            'name' => 'Existing List ' . uniqid(),
            'aktiv' => 1,
            'meta' => '{}',
        ]);

        $countBefore = count($this->main->getAdmin()->getLists());
        $this->main->getAdmin()->generateFirstCodeList();
        $countAfter = count($this->main->getAdmin()->getLists());

        $this->assertEquals($countBefore, $countAfter);
    }

    // ── getAuthtokens / editAuthtoken ───────────────────────────

    public function test_getAuthtokens_returns_array(): void {
        $tokens = $this->main->getAdmin()->getAuthtokens();
        $this->assertIsArray($tokens);
    }

    public function test_editAuthtoken_missing_id_throws(): void {
        $this->expectException(Exception::class);
        $this->main->getAdmin()->editAuthtoken([]);
    }

    // ── removeWoocommerceOrderInfoFromCode ───────────────────────

    public function test_removeWoocommerceOrderInfoFromCode_missing_code_throws(): void {
        if (!class_exists('WC_Product_Simple')) {
            $this->markTestSkipped('WooCommerce not available');
        }
        $this->expectException(Exception::class);
        $this->main->getAdmin()->removeWoocommerceOrderInfoFromCode([]);
    }

    public function test_removeWoocommerceOrderInfoFromCode_clears_order_link(): void {
        if (!class_exists('WC_Product_Simple')) {
            $this->markTestSkipped('WooCommerce not available');
        }

        $listId = $this->main->getDB()->insert('lists', [
            'name' => 'RemoveWC Test ' . uniqid(),
            'aktiv' => 1,
            'meta' => '{}',
        ]);

        $product = new WC_Product_Simple();
        $product->set_name('RemoveWC Product');
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
        $this->assertNotEmpty($codes);
        $code = $codes[0]['code'];

        // Remove the WC order info
        $result = $this->main->getAdmin()->removeWoocommerceOrderInfoFromCode(['code' => $code]);

        // After removal, the meta woocommerce section should be cleared
        $updated = $this->main->getCore()->retrieveCodeByCode($code);
        $metaObj = $this->main->getCore()->encodeMetaValuesAndFillObject($updated['meta'], $updated);

        $this->assertEquals(0, intval($metaObj['woocommerce']['order_id']));
    }

    // ── downloadTicketInfosOfProduct (on WC_Product manager) ────

    public function test_downloadTicketInfosOfProduct_returns_codes(): void {
        if (!class_exists('WC_Product_Simple')) {
            $this->markTestSkipped('WooCommerce not available');
        }

        $listId = $this->main->getDB()->insert('lists', [
            'name' => 'Download Info ' . uniqid(),
            'aktiv' => 1,
            'meta' => '{}',
        ]);

        $product = new WC_Product_Simple();
        $product->set_name('Download Info Product');
        $product->set_regular_price('10.00');
        $product->set_status('publish');
        $product->save();
        $pid = $product->get_id();

        update_post_meta($pid, 'saso_eventtickets_is_ticket', 'yes');
        update_post_meta($pid, 'saso_eventtickets_list', $listId);

        $order = wc_create_order();
        $order->add_product($product, 2);
        $order->calculate_totals();
        $order->set_status('completed');
        $order->save();

        $this->main->getWC()->getOrderManager()->add_serialcode_to_order($order->get_id());

        $result = $this->main->getWC()->getProductManager()->downloadTicketInfosOfProduct(['product_id' => $pid]);
        $this->assertIsArray($result);
        $this->assertArrayHasKey('ticket_infos', $result);
        $this->assertCount(2, $result['ticket_infos']);
    }

    public function test_downloadTicketInfosOfProduct_empty_for_unknown(): void {
        if (!class_exists('WC_Product_Simple')) {
            $this->markTestSkipped('WooCommerce not available');
        }

        $result = $this->main->getWC()->getProductManager()->downloadTicketInfosOfProduct(['product_id' => 999999]);
        $this->assertIsArray($result);
        $this->assertArrayHasKey('ticket_infos', $result);
        $this->assertEmpty($result['ticket_infos']);
    }

    // ── transformMetaObjectToExportColumn ────────────────────────

    public function test_transformMetaObjectToExportColumn_returns_array(): void {
        $listId = $this->main->getDB()->insert('lists', [
            'name' => 'Export Test ' . uniqid(),
            'aktiv' => 1,
            'meta' => '{}',
        ]);

        $metaObj = $this->main->getCore()->getMetaObject();
        $metaJson = $this->main->getCore()->json_encode_with_error_handling($metaObj);

        $code = 'EXP' . strtoupper(uniqid());
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

        $row = $this->main->getCore()->retrieveCodeByCode($code);
        $result = $this->main->getAdmin()->transformMetaObjectToExportColumn($row);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('meta_confirmedCount', $result);
    }
}
