<?php
/**
 * Tests for WC Order deletion and cleanup methods:
 * deleteCodesEntryOnOrderItem, deleteRestrictionEntryOnOrderItem,
 * woocommerce_pre_delete_order_refund.
 */

class WCOrderDeleteTest extends WP_UnitTestCase {

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
            'name' => 'DeleteTest List ' . uniqid(),
            'aktiv' => 1,
            'meta' => '{}',
        ]);

        $product = new WC_Product_Simple();
        $product->set_name('DeleteTest Product ' . uniqid());
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

        $items = $order->get_items();
        $item_id = 0;
        foreach ($items as $id => $item) {
            $item_id = $id;
            break;
        }

        return [
            'order' => $order,
            'product' => $product,
            'list_id' => $listId,
            'item_id' => $item_id,
        ];
    }

    // ── deleteCodesEntryOnOrderItem ──────────────────────────────

    public function test_deleteCodesEntryOnOrderItem_removes_ticket_meta(): void {
        $data = $this->createTicketOrder();
        $om = $this->main->getWC()->getOrderManager();

        // Verify meta exists before deletion
        $isTicket = wc_get_order_item_meta($data['item_id'], '_saso_eventtickets_is_ticket', true);
        $this->assertEquals(1, intval($isTicket));

        // Delete codes entry
        $om->deleteCodesEntryOnOrderItem($data['item_id']);

        // Verify meta is removed
        $isTicketAfter = wc_get_order_item_meta($data['item_id'], '_saso_eventtickets_is_ticket', true);
        $this->assertEmpty($isTicketAfter);
    }

    public function test_deleteCodesEntryOnOrderItem_removes_codes_meta(): void {
        $data = $this->createTicketOrder();
        $om = $this->main->getWC()->getOrderManager();

        // Verify codes exist
        $codes = wc_get_order_item_meta($data['item_id'], '_saso_eventtickets_product_code', true);
        $this->assertNotEmpty($codes);

        // Delete
        $om->deleteCodesEntryOnOrderItem($data['item_id']);

        // Verify removed
        $codesAfter = wc_get_order_item_meta($data['item_id'], '_saso_eventtickets_product_code', true);
        $this->assertEmpty($codesAfter);
    }

    // ── deleteRestrictionEntryOnOrderItem ─────────────────────────

    public function test_deleteRestrictionEntryOnOrderItem_removes_restriction_meta(): void {
        $data = $this->createTicketOrder();
        $om = $this->main->getWC()->getOrderManager();

        // Add restriction meta first
        wc_add_order_item_meta($data['item_id'], '_saso_eventticket_list_sale_restriction', 'TEST-CODE-123');

        // Verify it exists
        $restriction = wc_get_order_item_meta($data['item_id'], '_saso_eventticket_list_sale_restriction', true);
        $this->assertEquals('TEST-CODE-123', $restriction);

        // Delete
        $om->deleteRestrictionEntryOnOrderItem($data['item_id']);

        // Verify removed
        $restrictionAfter = wc_get_order_item_meta($data['item_id'], '_saso_eventticket_list_sale_restriction', true);
        $this->assertEmpty($restrictionAfter);
    }

    // ── woocommerce_pre_delete_order_refund ───────────────────────

    public function test_pre_delete_order_refund_captures_parent_id(): void {
        $data = $this->createTicketOrder();
        $om = $this->main->getWC()->getOrderManager();

        // Create a refund
        $refund = wc_create_refund([
            'order_id' => $data['order']->get_id(),
            'amount' => '10.00',
            'reason' => 'Test refund',
        ]);

        // Call pre-delete hook
        $result = $om->woocommerce_pre_delete_order_refund(true, $refund, true);

        // Should pass through the return value
        $this->assertTrue($result);

        // Should have captured the parent ID (stored in refund_parent_id property)
        $ref = new ReflectionProperty($om, 'refund_parent_id');
        $ref->setAccessible(true);
        $this->assertEquals($data['order']->get_id(), $ref->getValue($om));
    }

    public function test_pre_delete_order_refund_null_refund(): void {
        $om = $this->main->getWC()->getOrderManager();
        $result = $om->woocommerce_pre_delete_order_refund('passthrough', null, false);
        $this->assertEquals('passthrough', $result);
    }
}
