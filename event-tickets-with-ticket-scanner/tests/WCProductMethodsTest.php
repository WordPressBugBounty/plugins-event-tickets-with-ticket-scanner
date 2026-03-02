<?php
/**
 * Batch 44 — WC Product methods:
 * - isTicketByProductId: product ticket check
 * - wc_get_lists: dropdown list retrieval
 * - woocommerce_product_data_tabs: tab registration
 * - manage_edit_product_columns: column registration
 * - manage_edit_product_sortable_columns: sortable columns
 */

class WCProductMethodsTest extends WP_UnitTestCase {

	private $main;
	private $productMgr;

	public function set_up(): void {
		parent::set_up();
		$this->main = sasoEventtickets::Instance();

		if (!class_exists('WC_Product_Simple')) {
			$this->markTestSkipped('WooCommerce not available');
		}

		$this->productMgr = $this->main->getWC()->getProductManager();
	}

	private function createTicketProduct(): int {
		$listId = $this->main->getDB()->insert('lists', [
			'name' => 'WC Prod List ' . uniqid(),
			'aktiv' => 1,
			'meta' => '{}',
		]);

		$product = new WC_Product_Simple();
		$product->set_name('Prod Method Test ' . uniqid());
		$product->set_regular_price('12.00');
		$product->set_status('publish');
		$product->save();
		$pid = $product->get_id();

		update_post_meta($pid, 'saso_eventtickets_is_ticket', 'yes');
		update_post_meta($pid, 'saso_eventtickets_list', $listId);

		return $pid;
	}

	// ── isTicketByProductId ──────────────────────────────────

	public function test_isTicketByProductId_true_for_ticket(): void {
		$pid = $this->createTicketProduct();
		$this->assertTrue($this->productMgr->isTicketByProductId($pid));
	}

	public function test_isTicketByProductId_false_for_regular(): void {
		$product = new WC_Product_Simple();
		$product->set_name('Regular Prod ' . uniqid());
		$product->set_regular_price('5.00');
		$product->set_status('publish');
		$product->save();

		$this->assertFalse($this->productMgr->isTicketByProductId($product->get_id()));
	}

	public function test_isTicketByProductId_false_for_zero(): void {
		$this->assertFalse($this->productMgr->isTicketByProductId(0));
	}

	public function test_isTicketByProductId_false_for_negative(): void {
		$this->assertFalse($this->productMgr->isTicketByProductId(-1));
	}

	public function test_isTicketByProductId_false_for_nonexistent(): void {
		$this->assertFalse($this->productMgr->isTicketByProductId(999999));
	}

	// ── wc_get_lists ─────────────────────────────────────────

	public function test_wc_get_lists_returns_array(): void {
		$lists = $this->productMgr->wc_get_lists();
		$this->assertIsArray($lists);
	}

	public function test_wc_get_lists_has_empty_key_for_deactivate(): void {
		$lists = $this->productMgr->wc_get_lists();
		$this->assertArrayHasKey('', $lists);
	}

	public function test_wc_get_lists_contains_created_list(): void {
		$listId = $this->main->getDB()->insert('lists', [
			'name' => 'GetLists Test ' . uniqid(),
			'aktiv' => 1,
			'meta' => '{}',
		]);

		$lists = $this->productMgr->wc_get_lists();
		$this->assertArrayHasKey($listId, $lists);
	}

	// ── woocommerce_product_data_tabs ────────────────────────

	public function test_product_data_tabs_adds_event_tickets_tab(): void {
		$tabs = $this->productMgr->woocommerce_product_data_tabs([]);
		$this->assertArrayHasKey('saso_eventtickets_code_woo', $tabs);
		$this->assertArrayHasKey('label', $tabs['saso_eventtickets_code_woo']);
		$this->assertArrayHasKey('target', $tabs['saso_eventtickets_code_woo']);
	}

	public function test_product_data_tabs_preserves_existing(): void {
		$existing = ['general' => ['label' => 'General']];
		$tabs = $this->productMgr->woocommerce_product_data_tabs($existing);
		$this->assertArrayHasKey('general', $tabs);
		$this->assertArrayHasKey('saso_eventtickets_code_woo', $tabs);
	}

	// ── manage_edit_product_columns ──────────────────────────

	public function test_manage_edit_product_columns_adds_ticket_column(): void {
		$columns = $this->productMgr->manage_edit_product_columns([
			'name' => 'Name',
			'price' => 'Price',
		]);
		$this->assertIsArray($columns);
		// Should have added ticket-related column(s)
		$this->assertGreaterThanOrEqual(2, count($columns));
	}

	// ── manage_edit_product_sortable_columns ──────────────────

	public function test_manage_edit_product_sortable_columns(): void {
		$columns = $this->productMgr->manage_edit_product_sortable_columns([
			'name' => 'name',
		]);
		$this->assertIsArray($columns);
		$this->assertArrayHasKey('name', $columns);
	}
}
