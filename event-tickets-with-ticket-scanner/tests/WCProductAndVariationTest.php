<?php
/**
 * Tests for WC Product integration:
 * - woocommerce_save_product_variation: saves variation meta from POST
 * - manage_edit_product_columns: adds custom column
 * - manage_edit_product_sortable_columns: makes column sortable
 * - manage_product_posts_custom_column: displays list name in column
 */

class WCProductAndVariationTest extends WP_UnitTestCase {

	private $main;

	public function set_up(): void {
		parent::set_up();
		$this->main = sasoEventtickets::Instance();

		if (!class_exists('WC_Product_Variable')) {
			$this->markTestSkipped('WooCommerce not available');
		}
	}

	private function createTicketProduct(array $extraMeta = []): array {
		$listId = $this->main->getDB()->insert('lists', [
			'name' => 'Product Test List ' . uniqid(),
			'aktiv' => 1,
			'meta' => '{}',
		]);

		$product = new WC_Product_Simple();
		$product->set_name('Product Test ' . uniqid());
		$product->set_regular_price('10.00');
		$product->set_status('publish');
		$product->save();
		$pid = $product->get_id();

		update_post_meta($pid, 'saso_eventtickets_is_ticket', 'yes');
		update_post_meta($pid, 'saso_eventtickets_list', $listId);

		foreach ($extraMeta as $key => $value) {
			update_post_meta($pid, $key, $value);
		}

		return ['product' => $product, 'product_id' => $pid, 'list_id' => $listId];
	}

	// ── woocommerce_save_product_variation ───────────────────────

	private function simulatePost(array $data): void {
		$_SERVER['REQUEST_METHOD'] = 'POST';
		foreach ($data as $key => $value) {
			$_POST[$key] = $value;
		}
		// Reset static REQUEST_DATA cache so getRequest() picks up new $_POST
		$ref = new ReflectionProperty('SASO_EVENTTICKETS', 'REQUEST_DATA');
		$ref->setAccessible(true);
		$ref->setValue(null, null);
	}

	private function cleanupPost(array $keys): void {
		foreach ($keys as $key) {
			unset($_POST[$key]);
		}
		$ref = new ReflectionProperty('SASO_EVENTTICKETS', 'REQUEST_DATA');
		$ref->setAccessible(true);
		$ref->setValue(null, null);
		unset($_SERVER['REQUEST_METHOD']);
	}

	public function test_save_variation_stores_dates(): void {
		$variation = new WC_Product_Variation();
		$variation->set_parent_id(1);
		$variation->save();
		$vid = $variation->get_id();

		$postData = [
			'saso_eventtickets_ticket_start_date' => [0 => '2026-07-01'],
			'saso_eventtickets_ticket_start_time' => [0 => '19:00'],
			'saso_eventtickets_ticket_end_date' => [0 => '2026-07-01'],
			'saso_eventtickets_ticket_end_time' => [0 => '23:00'],
		];
		$this->simulatePost($postData);

		$this->main->getWC()->getProductManager()->woocommerce_save_product_variation($vid, 0);

		$this->assertEquals('2026-07-01', get_post_meta($vid, 'saso_eventtickets_ticket_start_date', true));
		$this->assertEquals('19:00', get_post_meta($vid, 'saso_eventtickets_ticket_start_time', true));
		$this->assertEquals('2026-07-01', get_post_meta($vid, 'saso_eventtickets_ticket_end_date', true));
		$this->assertEquals('23:00', get_post_meta($vid, 'saso_eventtickets_ticket_end_time', true));

		$this->cleanupPost(array_keys($postData));
	}

	public function test_save_variation_stores_not_ticket_flag(): void {
		$variation = new WC_Product_Variation();
		$variation->set_parent_id(1);
		$variation->save();
		$vid = $variation->get_id();

		$key = '_saso_eventtickets_is_not_ticket';
		$this->simulatePost([$key => [0 => 'yes']]);

		$this->main->getWC()->getProductManager()->woocommerce_save_product_variation($vid, 0);

		$this->assertEquals('yes', get_post_meta($vid, $key, true));

		$this->cleanupPost([$key]);
	}

	public function test_save_variation_deletes_not_ticket_when_unchecked(): void {
		$variation = new WC_Product_Variation();
		$variation->set_parent_id(1);
		$variation->save();
		$vid = $variation->get_id();

		$key = '_saso_eventtickets_is_not_ticket';
		update_post_meta($vid, $key, 'yes');
		$this->assertEquals('yes', get_post_meta($vid, $key, true));

		// POST without the key = unchecked
		$this->simulatePost([]);

		$this->main->getWC()->getProductManager()->woocommerce_save_product_variation($vid, 0);

		$this->assertEmpty(get_post_meta($vid, $key, true));

		$this->cleanupPost([]);
	}

	public function test_save_variation_stores_tickets_per_item(): void {
		$variation = new WC_Product_Variation();
		$variation->set_parent_id(1);
		$variation->save();
		$vid = $variation->get_id();

		$this->simulatePost(['saso_eventtickets_ticket_amount_per_item' => [0 => '3']]);

		$this->main->getWC()->getProductManager()->woocommerce_save_product_variation($vid, 0);

		$this->assertEquals(3, intval(get_post_meta($vid, 'saso_eventtickets_ticket_amount_per_item', true)));

		$this->cleanupPost(['saso_eventtickets_ticket_amount_per_item']);
	}

	public function test_save_variation_enforces_minimum_1_ticket_per_item(): void {
		$variation = new WC_Product_Variation();
		$variation->set_parent_id(1);
		$variation->save();
		$vid = $variation->get_id();

		$this->simulatePost(['saso_eventtickets_ticket_amount_per_item' => [0 => '0']]);

		$this->main->getWC()->getProductManager()->woocommerce_save_product_variation($vid, 0);

		$this->assertEquals(1, intval(get_post_meta($vid, 'saso_eventtickets_ticket_amount_per_item', true)));

		$this->cleanupPost(['saso_eventtickets_ticket_amount_per_item']);
	}

	// ── manage_edit_product_columns ──────────────────────────────

	public function test_manage_edit_product_columns_adds_ticket_list_column(): void {
		$columns = ['cb' => '<input type="checkbox" />', 'name' => 'Name'];
		$result = $this->main->getWC()->getProductManager()->manage_edit_product_columns($columns);

		$this->assertArrayHasKey('SASO_EVENTTICKETS_LIST_COLUMN', $result);
		$this->assertArrayHasKey('cb', $result);
		$this->assertArrayHasKey('name', $result);
	}

	// ── manage_edit_product_sortable_columns ─────────────────────

	public function test_manage_edit_product_sortable_columns(): void {
		$columns = ['name' => 'name', 'date' => 'date'];
		$result = $this->main->getWC()->getProductManager()->manage_edit_product_sortable_columns($columns);

		$this->assertArrayHasKey('SASO_EVENTTICKETS_LIST_COLUMN', $result);
		$this->assertEquals('saso_eventtickets_list', $result['SASO_EVENTTICKETS_LIST_COLUMN']);
	}

	// ── manage_product_posts_custom_column ───────────────────────

	public function test_custom_column_displays_list_name(): void {
		$tp = $this->createTicketProduct();
		global $post;
		$post = get_post($tp['product_id']);

		ob_start();
		$this->main->getWC()->getProductManager()->manage_product_posts_custom_column('SASO_EVENTTICKETS_LIST_COLUMN');
		$output = ob_get_clean();

		$this->assertNotEquals('-', trim($output));
		$this->assertNotEmpty(trim($output));
	}

	public function test_custom_column_displays_dash_for_non_ticket(): void {
		$product = new WC_Product_Simple();
		$product->set_name('Regular Product');
		$product->set_regular_price('5.00');
		$product->set_status('publish');
		$product->save();

		global $post;
		$post = get_post($product->get_id());

		ob_start();
		$this->main->getWC()->getProductManager()->manage_product_posts_custom_column('SASO_EVENTTICKETS_LIST_COLUMN');
		$output = ob_get_clean();

		$this->assertEquals('-', trim($output));
	}

	public function test_custom_column_ignores_other_columns(): void {
		global $post;
		$product = new WC_Product_Simple();
		$product->set_name('Any Product');
		$product->set_regular_price('5.00');
		$product->set_status('publish');
		$product->save();
		$post = get_post($product->get_id());

		ob_start();
		$this->main->getWC()->getProductManager()->manage_product_posts_custom_column('some_other_column');
		$output = ob_get_clean();

		$this->assertEmpty($output);
	}
}
