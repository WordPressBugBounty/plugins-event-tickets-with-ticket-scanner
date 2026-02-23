<?php
/**
 * Tests for WC Base meta constants and shared utility methods.
 */

class WCBaseMetaConstantsTest extends WP_UnitTestCase {

    private $main;

    public function set_up(): void {
        parent::set_up();
        $this->main = sasoEventtickets::Instance();

        if (!class_exists('WC_Product_Simple')) {
            $this->markTestSkipped('WooCommerce not available');
        }
    }

    // ── Meta key constants ─────────────────────────────────────────

    public function test_META_ORDER_ITEM_CODES_defined(): void {
        $this->assertEquals(
            '_saso_eventtickets_product_code',
            sasoEventtickets_WC_Base::META_ORDER_ITEM_CODES
        );
    }

    public function test_META_ORDER_ITEM_PUBLIC_IDS_defined(): void {
        $this->assertEquals(
            '_saso_eventtickets_public_ticket_ids',
            sasoEventtickets_WC_Base::META_ORDER_ITEM_PUBLIC_IDS
        );
    }

    public function test_META_ORDER_ITEM_IS_TICKET_defined(): void {
        $this->assertEquals(
            '_saso_eventtickets_is_ticket',
            sasoEventtickets_WC_Base::META_ORDER_ITEM_IS_TICKET
        );
    }

    public function test_META_PRODUCT_IS_TICKET_defined(): void {
        $this->assertEquals(
            'saso_eventtickets_is_ticket',
            sasoEventtickets_WC_Base::META_PRODUCT_IS_TICKET
        );
    }

    public function test_META_PRODUCT_LIST_defined(): void {
        $this->assertEquals(
            'saso_eventtickets_list',
            sasoEventtickets_WC_Base::META_PRODUCT_LIST
        );
    }

    // ── WC manager accessors ───────────────────────────────────────

    public function test_getProductManager_returns_object(): void {
        $pm = $this->main->getWC()->getProductManager();
        $this->assertIsObject($pm);
        $this->assertInstanceOf(sasoEventtickets_WC_Product::class, $pm);
    }

    public function test_getOrderManager_returns_object(): void {
        $om = $this->main->getWC()->getOrderManager();
        $this->assertIsObject($om);
        $this->assertInstanceOf(sasoEventtickets_WC_Order::class, $om);
    }

    public function test_getEmailHandler_returns_object(): void {
        $eh = $this->main->getWC()->getEmailHandler();
        $this->assertIsObject($eh);
        $this->assertInstanceOf(sasoEventtickets_WC_Email::class, $eh);
    }

    public function test_getFrontendManager_returns_object(): void {
        $fm = $this->main->getWC()->getFrontendManager();
        $this->assertIsObject($fm);
        $this->assertInstanceOf(sasoEventtickets_WC_Frontend::class, $fm);
    }

    // ── Lazy loading (same instance returned) ──────────────────────

    public function test_getProductManager_same_instance(): void {
        $pm1 = $this->main->getWC()->getProductManager();
        $pm2 = $this->main->getWC()->getProductManager();
        $this->assertSame($pm1, $pm2);
    }

    public function test_getOrderManager_same_instance(): void {
        $om1 = $this->main->getWC()->getOrderManager();
        $om2 = $this->main->getWC()->getOrderManager();
        $this->assertSame($om1, $om2);
    }
}
