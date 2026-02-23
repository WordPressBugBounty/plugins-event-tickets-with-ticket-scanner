<?php
/**
 * Tests for AdminSettings::getCodesByProductId method.
 */

class AdminCodesByProductTest extends WP_UnitTestCase {

    private $main;

    public function set_up(): void {
        parent::set_up();
        $this->main = sasoEventtickets::Instance();

        if (!class_exists('WC_Product_Simple')) {
            $this->markTestSkipped('WooCommerce not available');
        }
    }

    // ── getCodesByProductId ────────────────────────────────────────

    public function test_getCodesByProductId_returns_empty_for_nonexistent(): void {
        $result = $this->main->getAdmin()->getCodesByProductId(999999);
        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function test_getCodesByProductId_returns_codes_for_ticket_product(): void {
        $listId = $this->main->getDB()->insert('lists', [
            'name' => 'ByProduct List ' . uniqid(),
            'aktiv' => 1,
            'meta' => '{}',
        ]);

        $product = new WC_Product_Simple();
        $product->set_name('ByProduct Product ' . uniqid());
        $product->set_regular_price('10.00');
        $product->set_status('publish');
        $product->save();

        update_post_meta($product->get_id(), 'saso_eventtickets_is_ticket', 'yes');
        update_post_meta($product->get_id(), 'saso_eventtickets_list', $listId);

        $order = wc_create_order();
        $order->add_product($product, 2);
        $order->calculate_totals();
        $order->set_status('completed');
        $order->save();

        $result = $this->main->getAdmin()->getCodesByProductId($product->get_id());
        $this->assertIsArray($result);
        $this->assertNotEmpty($result);
    }

    public function test_getCodesByProductId_entries_have_customer_name(): void {
        $listId = $this->main->getDB()->insert('lists', [
            'name' => 'ByProductCust List ' . uniqid(),
            'aktiv' => 1,
            'meta' => '{}',
        ]);

        $product = new WC_Product_Simple();
        $product->set_name('ByProductCust Product ' . uniqid());
        $product->set_regular_price('10.00');
        $product->set_status('publish');
        $product->save();

        update_post_meta($product->get_id(), 'saso_eventtickets_is_ticket', 'yes');
        update_post_meta($product->get_id(), 'saso_eventtickets_list', $listId);

        $order = wc_create_order();
        $order->set_billing_first_name('Alice');
        $order->set_billing_last_name('Wonder');
        $order->add_product($product, 1);
        $order->calculate_totals();
        $order->set_status('completed');
        $order->save();

        $result = $this->main->getAdmin()->getCodesByProductId($product->get_id());
        $this->assertNotEmpty($result);
        $this->assertArrayHasKey('_customer_name', $result[0]);
    }

    public function test_getCodesByProductId_returns_empty_for_non_ticket(): void {
        $product = new WC_Product_Simple();
        $product->set_name('NonTicket Product ' . uniqid());
        $product->set_regular_price('10.00');
        $product->set_status('publish');
        $product->save();

        $result = $this->main->getAdmin()->getCodesByProductId($product->get_id());
        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }
}
