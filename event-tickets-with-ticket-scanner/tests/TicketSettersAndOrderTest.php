<?php
/**
 * Tests for Ticket methods: setCodeObj, setOrder, getOrderItem, isScanner.
 */

class TicketSettersAndOrderTest extends WP_UnitTestCase {

    private $main;

    public function set_up(): void {
        parent::set_up();
        $this->main = sasoEventtickets::Instance();
    }

    // ── setCodeObj ───────────────────────────────────────────────

    public function test_setCodeObj_stores_value(): void {
        $handler = $this->main->getTicketHandler();
        $codeObj = ['code' => 'TEST123', 'meta' => '{}'];

        $handler->setCodeObj($codeObj);

        $ref = new ReflectionProperty($handler, 'codeObj');
        $ref->setAccessible(true);
        $result = $ref->getValue($handler);

        $this->assertEquals('TEST123', $result['code']);
    }

    public function test_setCodeObj_resets_order(): void {
        $handler = $this->main->getTicketHandler();

        // Set order first
        $ref = new ReflectionProperty($handler, 'order');
        $ref->setAccessible(true);
        $ref->setValue($handler, 'some_order');

        // setCodeObj should reset order to null
        $handler->setCodeObj(['code' => 'X', 'meta' => '{}']);
        $this->assertNull($ref->getValue($handler));
    }

    // ── setOrder ─────────────────────────────────────────────────

    public function test_setOrder_stores_value(): void {
        if (!class_exists('WC_Product_Simple')) {
            $this->markTestSkipped('WooCommerce not available');
        }

        $handler = $this->main->getTicketHandler();
        $order = wc_create_order();
        $order->save();

        $handler->setOrder($order);

        $ref = new ReflectionProperty($handler, 'order');
        $ref->setAccessible(true);
        $result = $ref->getValue($handler);

        $this->assertSame($order, $result);
    }

    // ── getOrderItem ─────────────────────────────────────────────

    public function test_getOrderItem_finds_matching_item(): void {
        if (!class_exists('WC_Product_Simple')) {
            $this->markTestSkipped('WooCommerce not available');
        }

        $product = new WC_Product_Simple();
        $product->set_name('OrderItem Test ' . uniqid());
        $product->set_regular_price('10.00');
        $product->save();

        $order = wc_create_order();
        $order->add_product($product, 1);
        $order->save();

        $items = $order->get_items();
        $firstItemId = array_key_first($items);

        $metaObj = $this->main->getCore()->getMetaObject();
        $metaObj['woocommerce']['item_id'] = $firstItemId;

        $result = $this->main->getTicketHandler()->getOrderItem($order, $metaObj);
        $this->assertNotNull($result);
        $this->assertEquals($product->get_id(), $result->get_product_id());
    }

    public function test_getOrderItem_returns_null_for_wrong_id(): void {
        if (!class_exists('WC_Product_Simple')) {
            $this->markTestSkipped('WooCommerce not available');
        }

        $order = wc_create_order();
        $order->save();

        $metaObj = $this->main->getCore()->getMetaObject();
        $metaObj['woocommerce']['item_id'] = 999999;

        $result = $this->main->getTicketHandler()->getOrderItem($order, $metaObj);
        $this->assertNull($result);
    }

    // ── isScanner ────────────────────────────────────────────────

    public function test_isScanner_false_for_normal_path(): void {
        $handler = $this->main->getTicketHandler();
        $handler->setRequestURI('/some/normal/page');

        // Reset the cached value
        $ref = new ReflectionProperty($handler, 'isScanner');
        $ref->setAccessible(true);
        $ref->setValue($handler, null);

        $result = $handler->isScanner();
        $this->assertFalse($result);
    }

    public function test_isScanner_true_for_scanner_path(): void {
        $handler = $this->main->getTicketHandler();
        $handler->setRequestURI('/wp-content/plugins/event-tickets-with-ticket-scanner/ticket/scanner/');

        // Reset the cached value
        $ref = new ReflectionProperty($handler, 'isScanner');
        $ref->setAccessible(true);
        $ref->setValue($handler, null);

        $result = $handler->isScanner();
        $this->assertTrue($result);
    }
}
