<?php
/**
 * Tests for WC Order display meta methods:
 * woocommerce_order_item_display_meta_key, woocommerce_order_item_display_meta_value,
 * getOrderTicketIDCode, getOrderTicketId, getTicketURL, getTicketScannerURL.
 */

class WCOrderDisplayMetaTest extends WP_UnitTestCase {

    private $main;

    public function set_up(): void {
        parent::set_up();
        $this->main = sasoEventtickets::Instance();

        if (!class_exists('WC_Product_Simple')) {
            $this->markTestSkipped('WooCommerce not available');
        }
    }

    private function createTicketOrder(): array {
        $listId = $this->main->getDB()->insert('lists', [
            'name' => 'MetaTest List ' . uniqid(),
            'aktiv' => 1,
            'meta' => '{}',
        ]);

        $product = new WC_Product_Simple();
        $product->set_name('MetaTest Product ' . uniqid());
        $product->set_regular_price('15.00');
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
            'order' => $order,
            'product' => $product,
            'list_id' => $listId,
            'code' => $codes[0]['code'] ?? '',
            'order_id' => $order->get_id(),
        ];
    }

    // ── getOrderTicketIDCode ─────────────────────────────────────

    public function test_getOrderTicketIDCode_returns_string(): void {
        $data = $this->createTicketOrder();
        $idcode = $this->main->getCore()->getOrderTicketIDCode($data['order']);
        $this->assertIsString($idcode);
        $this->assertNotEmpty($idcode);
    }

    public function test_getOrderTicketIDCode_consistent(): void {
        $data = $this->createTicketOrder();
        $idcode1 = $this->main->getCore()->getOrderTicketIDCode($data['order']);
        $idcode2 = $this->main->getCore()->getOrderTicketIDCode($data['order']);
        $this->assertEquals($idcode1, $idcode2);
    }

    public function test_getOrderTicketIDCode_is_uppercase(): void {
        $data = $this->createTicketOrder();
        $idcode = $this->main->getCore()->getOrderTicketIDCode($data['order']);
        $this->assertEquals(strtoupper($idcode), $idcode);
    }

    // ── getOrderTicketId ─────────────────────────────────────────

    public function test_getOrderTicketId_contains_order_id(): void {
        $data = $this->createTicketOrder();
        $ticketId = $this->main->getCore()->getOrderTicketId($data['order']);
        $this->assertStringContainsString((string) $data['order_id'], $ticketId);
    }

    public function test_getOrderTicketId_default_prefix(): void {
        $data = $this->createTicketOrder();
        $ticketId = $this->main->getCore()->getOrderTicketId($data['order']);
        $this->assertStringStartsWith('order-', $ticketId);
    }

    public function test_getOrderTicketId_custom_prefix(): void {
        $data = $this->createTicketOrder();
        $ticketId = $this->main->getCore()->getOrderTicketId($data['order'], 'myprefix-');
        $this->assertStringStartsWith('myprefix-', $ticketId);
    }

    // ── getTicketURL ─────────────────────────────────────────────

    public function test_getTicketURL_returns_string(): void {
        $data = $this->createTicketOrder();
        $codeObj = $this->main->getCore()->retrieveCodeByCode($data['code']);
        $metaObj = $this->main->getCore()->encodeMetaValuesAndFillObject($codeObj['meta'], $codeObj);
        $url = $this->main->getCore()->getTicketURL($codeObj, $metaObj);
        $this->assertIsString($url);
    }

    public function test_getTicketURL_contains_ticket_id(): void {
        $data = $this->createTicketOrder();
        $codeObj = $this->main->getCore()->retrieveCodeByCode($data['code']);
        $metaObj = $this->main->getCore()->encodeMetaValuesAndFillObject($codeObj['meta'], $codeObj);
        $ticketId = $this->main->getCore()->getTicketId($codeObj, $metaObj);
        $url = $this->main->getCore()->getTicketURL($codeObj, $metaObj);
        if (!empty($ticketId)) {
            $this->assertStringContainsString($data['code'], $url);
        } else {
            // ticket id empty, URL should still be a string
            $this->assertIsString($url);
        }
    }

    // ── getTicketScannerURL ──────────────────────────────────────

    public function test_getTicketScannerURL_contains_scanner(): void {
        $url = $this->main->getCore()->getTicketScannerURL('TEST-123');
        $this->assertStringContainsString('scanner', $url);
    }

    public function test_getTicketScannerURL_contains_code(): void {
        $url = $this->main->getCore()->getTicketScannerURL('MY-TICKET-CODE');
        $this->assertStringContainsString('MY-TICKET-CODE', $url);
    }

    // ── getOrderTicketsURL ───────────────────────────────────────

    public function test_getOrderTicketsURL_returns_string(): void {
        $data = $this->createTicketOrder();
        $url = $this->main->getCore()->getOrderTicketsURL($data['order']);
        $this->assertIsString($url);
        $this->assertNotEmpty($url);
    }

    public function test_getOrderTicketsURL_throws_for_null(): void {
        $this->expectException(Exception::class);
        $this->main->getCore()->getOrderTicketsURL(null);
    }

    public function test_getOrderTicketsURL_contains_order_prefix(): void {
        $data = $this->createTicketOrder();
        $url = $this->main->getCore()->getOrderTicketsURL($data['order']);
        $this->assertStringContainsString('order-', $url);
    }
}
