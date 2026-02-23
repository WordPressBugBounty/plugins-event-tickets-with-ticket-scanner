<?php
/**
 * Tests for WC Product utility methods:
 * isTicketByProductId, wc_get_lists.
 */

class WCProductIsTicketAndListsTest extends WP_UnitTestCase {

    private $main;

    public function set_up(): void {
        parent::set_up();
        $this->main = sasoEventtickets::Instance();

        if (!class_exists('WC_Product_Simple')) {
            $this->markTestSkipped('WooCommerce not available');
        }
    }

    // ── isTicketByProductId ────────────────────────────────────────

    public function test_isTicketByProductId_false_for_non_ticket(): void {
        $product = new WC_Product_Simple();
        $product->set_name('NonTicket ' . uniqid());
        $product->set_regular_price('10.00');
        $product->set_status('publish');
        $product->save();

        $pm = $this->main->getWC()->getProductManager();
        $this->assertFalse($pm->isTicketByProductId($product->get_id()));
    }

    public function test_isTicketByProductId_true_for_ticket(): void {
        $product = new WC_Product_Simple();
        $product->set_name('Ticket ' . uniqid());
        $product->set_regular_price('10.00');
        $product->set_status('publish');
        $product->save();

        update_post_meta($product->get_id(), 'saso_eventtickets_is_ticket', 'yes');

        $pm = $this->main->getWC()->getProductManager();
        $this->assertTrue($pm->isTicketByProductId($product->get_id()));
    }

    public function test_isTicketByProductId_false_for_zero(): void {
        $pm = $this->main->getWC()->getProductManager();
        $this->assertFalse($pm->isTicketByProductId(0));
    }

    public function test_isTicketByProductId_false_for_negative(): void {
        $pm = $this->main->getWC()->getProductManager();
        $this->assertFalse($pm->isTicketByProductId(-1));
    }

    // ── wc_get_lists ───────────────────────────────────────────────

    public function test_wc_get_lists_returns_array(): void {
        $pm = $this->main->getWC()->getProductManager();
        $result = $pm->wc_get_lists();
        $this->assertIsArray($result);
    }

    public function test_wc_get_lists_has_empty_key_for_deactivate(): void {
        $pm = $this->main->getWC()->getProductManager();
        $result = $pm->wc_get_lists();
        $this->assertArrayHasKey('', $result);
    }

    public function test_wc_get_lists_includes_created_list(): void {
        $listName = 'WCGetLists Test ' . uniqid();
        $listId = $this->main->getDB()->insert('lists', [
            'name' => $listName,
            'aktiv' => 1,
            'meta' => '{}',
        ]);

        $pm = $this->main->getWC()->getProductManager();
        $result = $pm->wc_get_lists();
        $this->assertArrayHasKey($listId, $result);
        $this->assertEquals($listName, $result[$listId]);
    }
}
