<?php
/**
 * Tests for WooCommerce product ticket settings (save/load meta).
 */

class ProductSettingsTest extends WP_UnitTestCase {

    private $main;

    public function set_up(): void {
        parent::set_up();
        $this->main = sasoEventtickets::Instance();

        if (!class_exists('WC_Product_Simple')) {
            $this->markTestSkipped('WooCommerce not available');
        }
    }

    /**
     * Helper: create a simple product with ticket settings.
     */
    private function createTicketProduct(array $meta = []): WC_Product_Simple {
        $product = new WC_Product_Simple();
        $product->set_name('Settings Test Product');
        $product->set_regular_price('10.00');
        $product->set_status('publish');
        $product->save();
        $productId = $product->get_id();

        foreach ($meta as $key => $value) {
            update_post_meta($productId, $key, $value);
        }

        return $product;
    }

    // ── isTicketByProductId ──────────────────────────────────────

    public function test_isTicketByProductId_true(): void {
        $product = $this->createTicketProduct(['saso_eventtickets_is_ticket' => 'yes']);
        $productManager = $this->main->getWC()->getProductManager();
        $this->assertTrue($productManager->isTicketByProductId($product->get_id()));
    }

    public function test_isTicketByProductId_false(): void {
        $product = $this->createTicketProduct();
        $productManager = $this->main->getWC()->getProductManager();
        $this->assertFalse($productManager->isTicketByProductId($product->get_id()));
    }

    public function test_isTicketByProductId_invalid_id(): void {
        $productManager = $this->main->getWC()->getProductManager();
        $this->assertFalse($productManager->isTicketByProductId(0));
        $this->assertFalse($productManager->isTicketByProductId(-1));
    }

    // ── Product meta persistence ─────────────────────────────────

    public function test_ticket_list_meta_persists(): void {
        $listId = $this->main->getDB()->insert('lists', [
            'name' => 'Meta Persist Test ' . uniqid(),
            'aktiv' => 1,
            'meta' => '{}',
        ]);

        $product = $this->createTicketProduct([
            'saso_eventtickets_is_ticket' => 'yes',
            'saso_eventtickets_list' => $listId,
        ]);

        $storedList = get_post_meta($product->get_id(), 'saso_eventtickets_list', true);
        $this->assertEquals($listId, $storedList);
    }

    public function test_ticket_dates_meta_persists(): void {
        $product = $this->createTicketProduct([
            'saso_eventtickets_is_ticket' => 'yes',
            'saso_eventtickets_ticket_start_date' => '2026-07-01',
            'saso_eventtickets_ticket_start_time' => '18:00:00',
            'saso_eventtickets_ticket_end_date' => '2026-07-01',
            'saso_eventtickets_ticket_end_time' => '23:00:00',
        ]);
        $pid = $product->get_id();

        $this->assertEquals('2026-07-01', get_post_meta($pid, 'saso_eventtickets_ticket_start_date', true));
        $this->assertEquals('18:00:00', get_post_meta($pid, 'saso_eventtickets_ticket_start_time', true));
        $this->assertEquals('2026-07-01', get_post_meta($pid, 'saso_eventtickets_ticket_end_date', true));
        $this->assertEquals('23:00:00', get_post_meta($pid, 'saso_eventtickets_ticket_end_time', true));
    }

    public function test_ticket_amount_per_item_meta(): void {
        $product = $this->createTicketProduct([
            'saso_eventtickets_is_ticket' => 'yes',
            'saso_eventtickets_ticket_amount_per_item' => 3,
        ]);

        $amount = get_post_meta($product->get_id(), 'saso_eventtickets_ticket_amount_per_item', true);
        $this->assertEquals(3, intval($amount));
    }

    public function test_daychooser_meta_persists(): void {
        $product = $this->createTicketProduct([
            'saso_eventtickets_is_ticket' => 'yes',
            'saso_eventtickets_is_daychooser' => 'yes',
            'saso_eventtickets_daychooser_offset_start' => 2,
            'saso_eventtickets_daychooser_offset_end' => 30,
        ]);
        $pid = $product->get_id();

        $this->assertEquals('yes', get_post_meta($pid, 'saso_eventtickets_is_daychooser', true));
        $this->assertEquals(2, intval(get_post_meta($pid, 'saso_eventtickets_daychooser_offset_start', true)));
        $this->assertEquals(30, intval(get_post_meta($pid, 'saso_eventtickets_daychooser_offset_end', true)));
    }

    // ── wc_get_lists ─────────────────────────────────────────────

    public function test_wc_get_lists_returns_array(): void {
        $productManager = $this->main->getWC()->getProductManager();
        $lists = $productManager->wc_get_lists();
        $this->assertIsArray($lists);
        // Should have at least the "deactivate" empty option
        $this->assertArrayHasKey('', $lists);
    }

    public function test_wc_get_lists_includes_created_list(): void {
        $listId = $this->main->getDB()->insert('lists', [
            'name' => 'Dropdown Test ' . uniqid(),
            'aktiv' => 1,
            'meta' => '{}',
        ]);

        $productManager = $this->main->getWC()->getProductManager();
        $lists = $productManager->wc_get_lists();
        $this->assertArrayHasKey($listId, $lists);
    }

    // ── Variation meta ───────────────────────────────────────────

    public function test_variation_not_ticket_meta(): void {
        // Create a variable product with variation
        $product = new WC_Product_Variable();
        $product->set_name('Variable Test');
        $product->set_status('publish');
        $product->save();

        update_post_meta($product->get_id(), 'saso_eventtickets_is_ticket', 'yes');

        // Create attribute
        $attribute = new WC_Product_Attribute();
        $attribute->set_name('Size');
        $attribute->set_options(['S', 'M']);
        $attribute->set_visible(true);
        $attribute->set_variation(true);
        $product->set_attributes([$attribute]);
        $product->save();

        // Create variation
        $variation = new WC_Product_Variation();
        $variation->set_parent_id($product->get_id());
        $variation->set_attributes(['size' => 'S']);
        $variation->set_regular_price('10.00');
        $variation->save();

        // Mark this variation as NOT a ticket
        update_post_meta($variation->get_id(), '_saso_eventtickets_is_not_ticket', 'yes');

        $notTicket = get_post_meta($variation->get_id(), '_saso_eventtickets_is_not_ticket', true);
        $this->assertEquals('yes', $notTicket);
    }

    public function test_variation_date_override(): void {
        $product = new WC_Product_Variable();
        $product->set_name('Var Date Test');
        $product->set_status('publish');
        $product->save();

        $attribute = new WC_Product_Attribute();
        $attribute->set_name('Date');
        $attribute->set_options(['June', 'July']);
        $attribute->set_visible(true);
        $attribute->set_variation(true);
        $product->set_attributes([$attribute]);
        $product->save();

        $variation = new WC_Product_Variation();
        $variation->set_parent_id($product->get_id());
        $variation->set_attributes(['date' => 'June']);
        $variation->set_regular_price('10.00');
        $variation->save();

        // Set variation-specific dates
        update_post_meta($variation->get_id(), 'saso_eventtickets_ticket_start_date', '2026-06-01');
        update_post_meta($variation->get_id(), 'saso_eventtickets_ticket_end_date', '2026-06-30');

        $this->assertEquals('2026-06-01', get_post_meta($variation->get_id(), 'saso_eventtickets_ticket_start_date', true));
        $this->assertEquals('2026-06-30', get_post_meta($variation->get_id(), 'saso_eventtickets_ticket_end_date', true));
    }

    // ── Ticket amount per item in ticket generation ──────────────

    public function test_ticket_amount_per_item_generates_multiple_codes(): void {
        $listId = $this->main->getDB()->insert('lists', [
            'name' => 'Amount Test ' . uniqid(),
            'aktiv' => 1,
            'meta' => '{}',
        ]);

        $product = $this->createTicketProduct([
            'saso_eventtickets_is_ticket' => 'yes',
            'saso_eventtickets_list' => $listId,
            'saso_eventtickets_ticket_amount_per_item' => 2,
        ]);

        // Order 3 items × 2 tickets per item = 6 codes expected
        $order = wc_create_order();
        $order->add_product($product, 3);
        $order->calculate_totals();
        $order->set_status('completed');
        $order->save();

        $wcOrder = $this->main->getWC()->getOrderManager();
        $wcOrder->add_serialcode_to_order($order->get_id());

        $codes = $this->main->getCore()->getCodesByOrderId($order->get_id());
        $this->assertCount(6, $codes, '3 items × 2 tickets/item = 6 codes');
    }

    public function test_ticket_amount_per_item_default_is_1(): void {
        $listId = $this->main->getDB()->insert('lists', [
            'name' => 'Default Amount ' . uniqid(),
            'aktiv' => 1,
            'meta' => '{}',
        ]);

        $product = $this->createTicketProduct([
            'saso_eventtickets_is_ticket' => 'yes',
            'saso_eventtickets_list' => $listId,
            // No ticket_amount_per_item set — should default to 1
        ]);

        $order = wc_create_order();
        $order->add_product($product, 2);
        $order->calculate_totals();
        $order->set_status('completed');
        $order->save();

        $wcOrder = $this->main->getWC()->getOrderManager();
        $wcOrder->add_serialcode_to_order($order->get_id());

        $codes = $this->main->getCore()->getCodesByOrderId($order->get_id());
        $this->assertCount(2, $codes, '2 items × 1 ticket/item = 2 codes');
    }

    // ── woocommerce_product_data_tabs ────────────────────────────

    public function test_product_data_tabs_adds_ticket_tab(): void {
        $productManager = $this->main->getWC()->getProductManager();
        $tabs = $productManager->woocommerce_product_data_tabs([]);
        $this->assertArrayHasKey('saso_eventtickets_code_woo', $tabs);
        $this->assertArrayHasKey('label', $tabs['saso_eventtickets_code_woo']);
        $this->assertArrayHasKey('target', $tabs['saso_eventtickets_code_woo']);
    }

    // ── manage_edit_product_columns ──────────────────────────────

    public function test_product_columns_adds_ticket_list_column(): void {
        $productManager = $this->main->getWC()->getProductManager();
        $columns = $productManager->manage_edit_product_columns([]);
        $this->assertArrayHasKey('SASO_EVENTTICKETS_LIST_COLUMN', $columns);
    }
}
