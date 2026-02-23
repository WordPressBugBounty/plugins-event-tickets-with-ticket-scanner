<?php
/**
 * Tests for WC Product admin methods: sortable columns,
 * downloadTicketInfosOfProduct, product meta saving.
 */

class WCProductColumnsTest extends WP_UnitTestCase {

    private $main;

    public function set_up(): void {
        parent::set_up();
        $this->main = sasoEventtickets::Instance();

        if (!class_exists('WC_Product_Simple')) {
            $this->markTestSkipped('WooCommerce not available');
        }
    }

    // ── manage_edit_product_sortable_columns ─────────────────────

    public function test_sortable_columns_returns_array(): void {
        $pm = $this->main->getWC()->getProductManager();
        $result = $pm->manage_edit_product_sortable_columns([]);
        $this->assertIsArray($result);
    }

    public function test_sortable_columns_adds_event_tickets_column(): void {
        $pm = $this->main->getWC()->getProductManager();
        $result = $pm->manage_edit_product_sortable_columns([]);
        $this->assertArrayHasKey('SASO_EVENTTICKETS_LIST_COLUMN', $result);
        $this->assertEquals('saso_eventtickets_list', $result['SASO_EVENTTICKETS_LIST_COLUMN']);
    }

    public function test_sortable_columns_preserves_existing(): void {
        $pm = $this->main->getWC()->getProductManager();
        $existing = ['name' => 'name', 'date' => 'date'];
        $result = $pm->manage_edit_product_sortable_columns($existing);
        $this->assertArrayHasKey('name', $result);
        $this->assertArrayHasKey('date', $result);
        $this->assertArrayHasKey('SASO_EVENTTICKETS_LIST_COLUMN', $result);
    }

    // ── downloadTicketInfosOfProduct ─────────────────────────────

    public function test_downloadTicketInfosOfProduct_returns_array(): void {
        $pm = $this->main->getWC()->getProductManager();
        $product = new WC_Product_Simple();
        $product->set_name('Info Test Product ' . uniqid());
        $product->set_regular_price('5.00');
        $product->set_status('publish');
        $product->save();

        $result = $pm->downloadTicketInfosOfProduct(['product_id' => $product->get_id()]);
        $this->assertIsArray($result);
    }

    public function test_downloadTicketInfosOfProduct_contains_product_info(): void {
        $pm = $this->main->getWC()->getProductManager();
        $product = new WC_Product_Simple();
        $product->set_name('InfoCheck Product ' . uniqid());
        $product->set_regular_price('12.00');
        $product->set_status('publish');
        $product->save();

        $result = $pm->downloadTicketInfosOfProduct(['product_id' => $product->get_id()]);
        $this->assertArrayHasKey('product', $result);
        $this->assertArrayHasKey('name', $result['product']);
    }

    public function test_downloadTicketInfosOfProduct_zero_id_returns_empty_infos(): void {
        $pm = $this->main->getWC()->getProductManager();
        $result = $pm->downloadTicketInfosOfProduct(['product_id' => 0]);
        $this->assertIsArray($result);
        $this->assertArrayHasKey('ticket_infos', $result);
        $this->assertEmpty($result['ticket_infos']);
    }

    // ── woocommerce_product_data_tabs ────────────────────────────

    public function test_product_data_tabs_returns_array(): void {
        $pm = $this->main->getWC()->getProductManager();
        $result = $pm->woocommerce_product_data_tabs([]);
        $this->assertIsArray($result);
    }

    public function test_product_data_tabs_adds_event_tickets_tab(): void {
        $pm = $this->main->getWC()->getProductManager();
        $result = $pm->woocommerce_product_data_tabs([]);
        $this->assertArrayHasKey('saso_eventtickets_code_woo', $result);
        $this->assertArrayHasKey('label', $result['saso_eventtickets_code_woo']);
    }

    public function test_product_data_tabs_preserves_existing_tabs(): void {
        $pm = $this->main->getWC()->getProductManager();
        $existing = ['general' => ['label' => 'General', 'target' => 'general_product_data']];
        $result = $pm->woocommerce_product_data_tabs($existing);
        $this->assertArrayHasKey('general', $result);
        $this->assertArrayHasKey('saso_eventtickets_code_woo', $result);
    }
}
