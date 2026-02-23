<?php
/**
 * Tests for WC Product methods: isTicketByProductId, wc_get_lists.
 */

class WCProductTicketTest extends WP_UnitTestCase {

    private $main;

    public function set_up(): void {
        parent::set_up();
        $this->main = sasoEventtickets::Instance();

        if (!class_exists('WC_Product_Simple')) {
            $this->markTestSkipped('WooCommerce not available');
        }
    }

    // ── isTicketByProductId ───────────────────────────────────────

    public function test_isTicketByProductId_true_for_ticket(): void {
        $product = new WC_Product_Simple();
        $product->set_name('Ticket Product ' . uniqid());
        $product->set_regular_price('10.00');
        $product->save();

        update_post_meta($product->get_id(), 'saso_eventtickets_is_ticket', 'yes');

        $result = $this->main->getWC()->getProductManager()->isTicketByProductId($product->get_id());
        $this->assertTrue($result);
    }

    public function test_isTicketByProductId_false_for_non_ticket(): void {
        $product = new WC_Product_Simple();
        $product->set_name('Normal Product ' . uniqid());
        $product->set_regular_price('5.00');
        $product->save();

        $result = $this->main->getWC()->getProductManager()->isTicketByProductId($product->get_id());
        $this->assertFalse($result);
    }

    public function test_isTicketByProductId_false_for_zero(): void {
        $result = $this->main->getWC()->getProductManager()->isTicketByProductId(0);
        $this->assertFalse($result);
    }

    public function test_isTicketByProductId_false_for_negative(): void {
        $result = $this->main->getWC()->getProductManager()->isTicketByProductId(-1);
        $this->assertFalse($result);
    }

    public function test_isTicketByProductId_false_for_nonexistent(): void {
        $result = $this->main->getWC()->getProductManager()->isTicketByProductId(999999);
        $this->assertFalse($result);
    }

    // ── wc_get_lists ─────────────────────────────────────────────

    public function test_wc_get_lists_returns_array(): void {
        $result = $this->main->getWC()->getProductManager()->wc_get_lists();
        $this->assertIsArray($result);
    }

    public function test_wc_get_lists_has_empty_key_for_deactivate(): void {
        $result = $this->main->getWC()->getProductManager()->wc_get_lists();
        $this->assertArrayHasKey('', $result);
    }

    public function test_wc_get_lists_contains_created_list(): void {
        $listName = 'WC Lists Test ' . uniqid();
        $listId = $this->main->getDB()->insert('lists', [
            'name' => $listName,
            'aktiv' => 1,
            'meta' => '{}',
        ]);

        $result = $this->main->getWC()->getProductManager()->wc_get_lists();
        $this->assertArrayHasKey($listId, $result);
        $this->assertEquals($listName, $result[$listId]);
    }
}
