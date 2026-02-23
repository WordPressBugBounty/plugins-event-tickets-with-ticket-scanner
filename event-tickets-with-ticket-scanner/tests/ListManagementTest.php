<?php
/**
 * Tests for list management (getList, getLists, editList, _addList).
 */

class ListManagementTest extends WP_UnitTestCase {

    private $main;

    public function set_up(): void {
        parent::set_up();
        $this->main = sasoEventtickets::Instance();
    }

    // ── getList ───────────────────────────────────────────────────

    public function test_getList_returns_list_data(): void {
        $listId = $this->main->getDB()->insert('lists', [
            'name' => 'GetList Test ' . uniqid(),
            'aktiv' => 1,
            'meta' => '{}',
        ]);

        $list = $this->main->getAdmin()->getList(['id' => $listId]);
        $this->assertIsArray($list);
        $this->assertEquals($listId, intval($list['id']));
        $this->assertStringContainsString('GetList Test', $list['name']);
    }

    public function test_getList_missing_id_throws(): void {
        $this->expectException(Exception::class);
        $this->main->getAdmin()->getList([]);
    }

    public function test_getList_invalid_id_throws(): void {
        $this->expectException(Exception::class);
        $this->main->getAdmin()->getList(['id' => 999999]);
    }

    // ── getLists ──────────────────────────────────────────────────

    public function test_getLists_returns_array(): void {
        $lists = $this->main->getAdmin()->getLists();
        $this->assertIsArray($lists);
    }

    public function test_getLists_includes_created_list(): void {
        $name = 'GetLists Verify ' . uniqid();
        $this->main->getDB()->insert('lists', [
            'name' => $name,
            'aktiv' => 1,
            'meta' => '{}',
        ]);

        $lists = $this->main->getAdmin()->getLists();
        $names = array_column($lists, 'name');
        $this->assertContains($name, $names);
    }

    // ── _addList (public handler) ─────────────────────────────────

    public function test_addList_creates_list(): void {
        $name = 'AddList Test ' . uniqid();
        $id = $this->main->getAdmin()->_addList(['name' => $name]);

        $this->assertIsInt($id);
        $this->assertGreaterThan(0, $id);

        // Verify it exists
        $list = $this->main->getAdmin()->getList(['id' => $id]);
        $this->assertEquals($name, $list['name']);
        $this->assertEquals(1, intval($list['aktiv']));
    }

    public function test_addList_missing_name_throws(): void {
        $this->expectException(Exception::class);
        $this->main->getAdmin()->_addList([]);
    }

    public function test_addList_empty_name_throws(): void {
        $this->expectException(Exception::class);
        $this->main->getAdmin()->_addList(['name' => '']);
    }

    public function test_addList_strips_html_tags(): void {
        $name = '<script>alert("xss")</script>Safe Name ' . uniqid();
        $id = $this->main->getAdmin()->_addList(['name' => $name]);

        $list = $this->main->getAdmin()->getList(['id' => $id]);
        $this->assertStringNotContainsString('<script>', $list['name']);
        $this->assertStringContainsString('Safe Name', $list['name']);
    }

    public function test_addList_duplicate_name_returns_zero_or_throws(): void {
        $name = 'Duplicate List ' . uniqid();
        $this->main->getAdmin()->_addList(['name' => $name]);

        // Duplicate insert: DB has unique index on name.
        // WordPress wpdb outputs HTML error, so capture it.
        $threw = false;
        $result = null;
        ob_start();
        try {
            $result = $this->main->getAdmin()->_addList(['name' => $name]);
        } catch (Exception $e) {
            $threw = true;
        }
        ob_end_clean();

        // Either throws or returns 0 (failed insert)
        $this->assertTrue($threw || $result === 0, 'Duplicate list name should throw or return 0');
    }

    public function test_addList_stores_meta(): void {
        $name = 'MetaList ' . uniqid();
        $id = $this->main->getAdmin()->_addList(['name' => $name]);

        $list = $this->main->getAdmin()->getList(['id' => $id]);
        $this->assertNotEmpty($list['meta']);
        $decoded = json_decode($list['meta'], true);
        $this->assertIsArray($decoded);
    }

    // ── editList ──────────────────────────────────────────────────

    public function test_editList_updates_name(): void {
        $oldName = 'Old Name ' . uniqid();
        $id = $this->main->getDB()->insert('lists', [
            'name' => $oldName,
            'aktiv' => 1,
            'meta' => '{}',
        ]);

        $newName = 'New Name ' . uniqid();
        $this->main->getAdmin()->editList(['id' => $id, 'name' => $newName]);

        $list = $this->main->getAdmin()->getList(['id' => $id]);
        $this->assertEquals($newName, $list['name']);
    }

    public function test_editList_missing_name_throws(): void {
        $id = $this->main->getDB()->insert('lists', [
            'name' => 'Edit No Name ' . uniqid(),
            'aktiv' => 1,
            'meta' => '{}',
        ]);

        $this->expectException(Exception::class);
        $this->main->getAdmin()->editList(['id' => $id]);
    }

    public function test_editList_missing_id_throws(): void {
        $this->expectException(Exception::class);
        $this->main->getAdmin()->editList(['name' => 'No ID']);
    }

    public function test_editList_strips_html(): void {
        $id = $this->main->getDB()->insert('lists', [
            'name' => 'HTML Test ' . uniqid(),
            'aktiv' => 1,
            'meta' => '{}',
        ]);

        $this->main->getAdmin()->editList(['id' => $id, 'name' => '<b>Bold</b> Name']);

        $list = $this->main->getAdmin()->getList(['id' => $id]);
        $this->assertStringNotContainsString('<b>', $list['name']);
        $this->assertStringContainsString('Bold', $list['name']);
    }

    // ── getCodesByProductId ───────────────────────────────────────

    public function test_getCodesByProductId_returns_codes_for_product(): void {
        if (!class_exists('WC_Product_Simple')) {
            $this->markTestSkipped('WooCommerce not available');
        }

        $listId = $this->main->getDB()->insert('lists', [
            'name' => 'Product Codes ' . uniqid(),
            'aktiv' => 1,
            'meta' => '{}',
        ]);

        $product = new WC_Product_Simple();
        $product->set_name('Codes By Product');
        $product->set_regular_price('10.00');
        $product->set_status('publish');
        $product->save();
        $pid = $product->get_id();

        update_post_meta($pid, 'saso_eventtickets_is_ticket', 'yes');
        update_post_meta($pid, 'saso_eventtickets_list', $listId);

        // Create an order and generate codes
        $order = wc_create_order();
        $order->add_product($product, 2);
        $order->calculate_totals();
        $order->set_status('completed');
        $order->save();

        $this->main->getWC()->getOrderManager()->add_serialcode_to_order($order->get_id());

        $codes = $this->main->getAdmin()->getCodesByProductId($pid);
        $this->assertIsArray($codes);
        $this->assertCount(2, $codes);

        // Each code should have _customer_name populated
        foreach ($codes as $codeRow) {
            $this->assertArrayHasKey('_customer_name', $codeRow);
            $this->assertArrayHasKey('list_name', $codeRow);
        }
    }

    public function test_getCodesByProductId_empty_for_unknown_product(): void {
        $codes = $this->main->getAdmin()->getCodesByProductId(999999);
        $this->assertIsArray($codes);
        $this->assertEmpty($codes);
    }
}
