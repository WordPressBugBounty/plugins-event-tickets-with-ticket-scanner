<?php
/**
 * Tests for AdminSettings WC order info methods: getCompanyName, getCustomerName,
 * removeWoocommerceRstrPurchaseInfoFromCode.
 * And Core methods: getOrderTicketId, getOrderTicketIDCode.
 */

class AdminOrderInfoTest extends WP_UnitTestCase {

    private $main;

    public function set_up(): void {
        parent::set_up();
        $this->main = sasoEventtickets::Instance();

        if (!class_exists('WC_Product_Simple')) {
            $this->markTestSkipped('WooCommerce not available');
        }
    }

    private function createOrder(): WC_Order {
        $order = wc_create_order();
        $order->set_billing_first_name('Max');
        $order->set_billing_last_name('Mustermann');
        $order->set_billing_company('TestFirma GmbH');
        $order->calculate_totals();
        $order->save();
        return $order;
    }

    private function createOrderWithTickets(): array {
        $listId = $this->main->getDB()->insert('lists', [
            'name' => 'OrderInfo Test ' . uniqid(),
            'aktiv' => 1,
            'meta' => '{}',
        ]);

        $product = new WC_Product_Simple();
        $product->set_name('OrderInfo Product ' . uniqid());
        $product->set_regular_price('15.00');
        $product->set_status('publish');
        $product->save();

        update_post_meta($product->get_id(), 'saso_eventtickets_is_ticket', 'yes');
        update_post_meta($product->get_id(), 'saso_eventtickets_list', $listId);

        $order = wc_create_order();
        $order->set_billing_first_name('Max');
        $order->set_billing_last_name('Mustermann');
        $order->set_billing_company('TestFirma GmbH');
        $order->add_product($product, 1);
        $order->calculate_totals();
        $order->set_status('completed');
        $order->save();

        $this->main->getWC()->getOrderManager()->add_serialcode_to_order($order->get_id());
        $codes = $this->main->getCore()->getCodesByOrderId($order->get_id());

        return [
            'order' => $order,
            'order_id' => $order->get_id(),
            'codes' => $codes,
            'list_id' => $listId,
            'product_id' => $product->get_id(),
        ];
    }

    // ── getCompanyName ───────────────────────────────────────────

    public function test_getCompanyName_returns_company(): void {
        $order = $this->createOrder();
        $result = $this->main->getAdmin()->getCompanyName($order->get_id());
        $this->assertEquals('TestFirma GmbH', $result);
    }

    public function test_getCompanyName_empty_for_invalid_order(): void {
        $result = $this->main->getAdmin()->getCompanyName(0);
        $this->assertEquals('', $result);
    }

    public function test_getCompanyName_empty_for_nonexistent_order(): void {
        $result = $this->main->getAdmin()->getCompanyName(999999);
        $this->assertIsString($result);
    }

    // ── getCustomerName ──────────────────────────────────────────

    public function test_getCustomerName_returns_full_name(): void {
        $order = $this->createOrder();
        $result = $this->main->getAdmin()->getCustomerName($order->get_id());
        $this->assertStringContainsString('Max', $result);
        $this->assertStringContainsString('Mustermann', $result);
    }

    public function test_getCustomerName_invalid_order_returns_string(): void {
        $result = $this->main->getAdmin()->getCustomerName(999999);
        $this->assertIsString($result);
    }

    public function test_getCustomerName_zero_returns_string(): void {
        $result = $this->main->getAdmin()->getCustomerName(0);
        $this->assertIsString($result);
    }

    // ── getOrderTicketIDCode ─────────────────────────────────────

    public function test_getOrderTicketIDCode_returns_string(): void {
        $order = $this->createOrder();
        $idcode = $this->main->getCore()->getOrderTicketIDCode($order);
        $this->assertIsString($idcode);
        $this->assertNotEmpty($idcode);
    }

    public function test_getOrderTicketIDCode_is_deterministic(): void {
        $order = $this->createOrder();
        $idcode1 = $this->main->getCore()->getOrderTicketIDCode($order);
        $idcode2 = $this->main->getCore()->getOrderTicketIDCode($order);
        $this->assertEquals($idcode1, $idcode2, 'Same order should produce same idcode');
    }

    // ── getOrderTicketId ─────────────────────────────────────────

    public function test_getOrderTicketId_contains_order_prefix(): void {
        $order = $this->createOrder();
        $ticketId = $this->main->getCore()->getOrderTicketId($order);
        $this->assertStringStartsWith('order-', $ticketId);
    }

    public function test_getOrderTicketId_contains_order_id(): void {
        $order = $this->createOrder();
        $ticketId = $this->main->getCore()->getOrderTicketId($order);
        $this->assertStringContainsString((string) $order->get_id(), $ticketId);
    }

    public function test_getOrderTicketId_custom_prefix(): void {
        $order = $this->createOrder();
        $ticketId = $this->main->getCore()->getOrderTicketId($order, 'custom-');
        $this->assertStringStartsWith('custom-', $ticketId);
    }

    // ── removeWoocommerceRstrPurchaseInfoFromCode ─────────────────

    public function test_removeWoocommerceRstrPurchaseInfoFromCode_missing_code_throws(): void {
        $this->expectException(Exception::class);
        $this->main->getAdmin()->removeWoocommerceRstrPurchaseInfoFromCode([]);
    }

    public function test_removeWoocommerceRstrPurchaseInfoFromCode_clears_rstr(): void {
        $data = $this->createOrderWithTickets();
        $code = $data['codes'][0]['code'];

        $result = $this->main->getAdmin()->removeWoocommerceRstrPurchaseInfoFromCode(['code' => $code]);
        $this->assertIsArray($result);

        // Verify the wc_rp section is reset
        $fresh = $this->main->getCore()->retrieveCodeByCode($code);
        $freshMeta = $this->main->getCore()->encodeMetaValuesAndFillObject($fresh['meta'], $fresh);
        $this->assertEquals(0, intval($freshMeta['wc_rp']['order_id']));
    }
}
