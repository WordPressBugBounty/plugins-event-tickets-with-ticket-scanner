<?php
/**
 * Tests for Admin methods: getLists, getCodesByProductId.
 */

class AdminListQueryTest extends WP_UnitTestCase {

    private $main;

    public function set_up(): void {
        parent::set_up();
        $this->main = sasoEventtickets::Instance();
    }

    // ── getLists ──────────────────────────────────────────────────

    public function test_getLists_returns_array(): void {
        $result = $this->main->getAdmin()->getLists();
        $this->assertIsArray($result);
    }

    public function test_getLists_contains_created_list(): void {
        $listName = 'GetLists Test ' . uniqid();
        $listId = $this->main->getDB()->insert('lists', [
            'name' => $listName,
            'aktiv' => 1,
            'meta' => '{}',
        ]);

        $lists = $this->main->getAdmin()->getLists();
        $found = false;
        foreach ($lists as $list) {
            if (intval($list['id']) === $listId) {
                $found = true;
                $this->assertEquals($listName, $list['name']);
                break;
            }
        }
        $this->assertTrue($found, "Created list not found in getLists()");
    }

    public function test_getLists_entries_have_expected_keys(): void {
        $this->main->getDB()->insert('lists', [
            'name' => 'Keys Test ' . uniqid(),
            'aktiv' => 1,
            'meta' => '{}',
        ]);

        $lists = $this->main->getAdmin()->getLists();
        $this->assertNotEmpty($lists);

        $first = $lists[0];
        $this->assertArrayHasKey('id', $first);
        $this->assertArrayHasKey('name', $first);
    }

    // ── getCodesByProductId ──────────────────────────────────────

    public function test_getCodesByProductId_returns_array(): void {
        if (!class_exists('WC_Product_Simple')) {
            $this->markTestSkipped('WooCommerce not available');
        }

        $result = $this->main->getAdmin()->getCodesByProductId(999999);
        $this->assertIsArray($result);
    }

    public function test_getCodesByProductId_empty_for_nonexistent(): void {
        if (!class_exists('WC_Product_Simple')) {
            $this->markTestSkipped('WooCommerce not available');
        }

        $result = $this->main->getAdmin()->getCodesByProductId(999999);
        $this->assertEmpty($result);
    }

    public function test_getCodesByProductId_finds_codes_for_product(): void {
        if (!class_exists('WC_Product_Simple')) {
            $this->markTestSkipped('WooCommerce not available');
        }

        $listId = $this->main->getDB()->insert('lists', [
            'name' => 'ProdCode Test ' . uniqid(),
            'aktiv' => 1,
            'meta' => '{}',
        ]);

        $product = new WC_Product_Simple();
        $product->set_name('CodesByProduct Test ' . uniqid());
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

        $codes = $this->main->getAdmin()->getCodesByProductId($product->get_id());
        $this->assertNotEmpty($codes);
        $this->assertArrayHasKey('_customer_name', $codes[0]);
    }
}
