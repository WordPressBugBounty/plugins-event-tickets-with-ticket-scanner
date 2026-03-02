<?php
/**
 * Batch 35a — WC Product configuration and product columns:
 * - manage_edit_product_columns: adds ticket list column
 * - manage_edit_product_sortable_columns: makes column sortable
 * - manage_product_posts_custom_column: renders list name
 * - downloadTicketInfosOfProduct: returns ticket data for product
 * - woocommerce_order_item_display_meta_key: transforms meta keys to labels
 * - woocommerce_order_item_display_meta_value: transforms meta values for display
 * - wc_get_lists: returns lists for dropdown
 * - wc_product_display_side_box: renders action buttons
 */

class WCProductConfigAndColumnsTest extends WP_UnitTestCase {

	private $main;

	public function set_up(): void {
		parent::set_up();
		$this->main = sasoEventtickets::Instance();

		if (!class_exists('WC_Product_Simple')) {
			$this->markTestSkipped('WooCommerce not available');
		}
	}

	// ── manage_edit_product_columns ────────────────────────────

	public function test_manage_edit_product_columns_adds_ticket_list_column(): void {
		$columns = ['name' => 'Name', 'price' => 'Price'];

		$result = $this->main->getWC()->getProductManager()->manage_edit_product_columns($columns);

		$this->assertArrayHasKey('SASO_EVENTTICKETS_LIST_COLUMN', $result);
		$this->assertArrayHasKey('name', $result);
		$this->assertArrayHasKey('price', $result);
	}

	public function test_manage_edit_product_columns_handles_empty_array(): void {
		$result = $this->main->getWC()->getProductManager()->manage_edit_product_columns([]);

		$this->assertArrayHasKey('SASO_EVENTTICKETS_LIST_COLUMN', $result);
	}

	// ── manage_edit_product_sortable_columns ───────────────────

	public function test_sortable_columns_adds_ticket_list(): void {
		$columns = ['name' => 'name', 'date' => 'date'];

		$result = $this->main->getWC()->getProductManager()->manage_edit_product_sortable_columns($columns);

		$this->assertArrayHasKey('SASO_EVENTTICKETS_LIST_COLUMN', $result);
		$this->assertEquals('saso_eventtickets_list', $result['SASO_EVENTTICKETS_LIST_COLUMN']);
	}

	// ── manage_product_posts_custom_column ─────────────────────

	public function test_custom_column_outputs_list_name_for_ticket_product(): void {
		$tp = $this->createTicketProduct();

		// Set global post for the column renderer
		global $post;
		$post = get_post($tp['product_id']);

		ob_start();
		$this->main->getWC()->getProductManager()->manage_product_posts_custom_column('SASO_EVENTTICKETS_LIST_COLUMN');
		$output = ob_get_clean();

		$this->assertNotEmpty($output);
		$this->assertStringNotContainsString('-', $output); // Should show list name, not "-"
	}

	public function test_custom_column_outputs_dash_for_non_ticket_product(): void {
		$product = new WC_Product_Simple();
		$product->set_name('NonTicket Column Test');
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
		$product->set_name('Other Column Test');
		$product->set_regular_price('5.00');
		$product->set_status('publish');
		$product->save();
		$post = get_post($product->get_id());

		ob_start();
		$this->main->getWC()->getProductManager()->manage_product_posts_custom_column('some_other_column');
		$output = ob_get_clean();

		$this->assertEmpty($output);
	}

	// ── downloadTicketInfosOfProduct ──────────────────────────

	public function test_downloadTicketInfos_returns_array_with_keys(): void {
		$tp = $this->createTicketProduct();

		$result = $this->main->getWC()->getProductManager()->downloadTicketInfosOfProduct([
			'product_id' => $tp['product_id'],
		]);

		$this->assertIsArray($result);
		$this->assertArrayHasKey('ticket_infos', $result);
		$this->assertArrayHasKey('product', $result);
	}

	public function test_downloadTicketInfos_zero_id_returns_empty(): void {
		$result = $this->main->getWC()->getProductManager()->downloadTicketInfosOfProduct([
			'product_id' => 0,
		]);

		$this->assertEmpty($result['ticket_infos']);
		$this->assertEmpty($result['product']);
	}

	public function test_downloadTicketInfos_returns_product_name(): void {
		$tp = $this->createTicketProduct();

		$result = $this->main->getWC()->getProductManager()->downloadTicketInfosOfProduct([
			'product_id' => $tp['product_id'],
		]);

		$this->assertNotEmpty($result['product']['name']);
	}

	// ── wc_product_display_side_box ───────────────────────────

	public function test_side_box_renders_buttons(): void {
		ob_start();
		$this->main->getWC()->getProductManager()->wc_product_display_side_box();
		$output = ob_get_clean();

		$this->assertStringContainsString('button', $output);
		$this->assertStringContainsString('Download Event Flyer', $output);
		$this->assertStringContainsString('Download ICS File', $output);
	}

	public function test_side_box_fires_action(): void {
		$fired = false;
		$callback = function () use (&$fired) {
			$fired = true;
		};
		add_action($this->main->_do_action_prefix . 'wc_product_display_side_box', $callback);

		ob_start();
		$this->main->getWC()->getProductManager()->wc_product_display_side_box();
		ob_end_clean();

		$this->assertTrue($fired);
		remove_action($this->main->_do_action_prefix . 'wc_product_display_side_box', $callback);
	}

	// ── woocommerce_order_item_display_meta_key ───────────────

	public function test_display_meta_key_transforms_codes_key(): void {
		$tp = $this->createTicketProduct();
		$order = $this->createCompletedOrder($tp['product']);
		$this->main->getWC()->getOrderManager()->add_serialcode_to_order($order->get_id());

		$order = wc_get_order($order->get_id());
		foreach ($order->get_items() as $item_id => $item) {
			// Create a mock meta object
			$meta = (object) ['key' => '_saso_eventtickets_product_code'];

			// Must be in admin context
			set_current_screen('edit-post');

			$result = $this->main->getWC()->getOrderManager()->woocommerce_order_item_display_meta_key(
				'_saso_eventtickets_product_code', $meta, $item
			);

			$isTicket = $item->get_meta('_saso_eventtickets_is_ticket');
			if ($isTicket == 1) {
				$this->assertStringContainsString('Ticket', $result);
			}
			break;
		}
	}

	public function test_display_meta_key_transforms_public_ids(): void {
		$tp = $this->createTicketProduct();
		$order = $this->createCompletedOrder($tp['product']);
		$this->main->getWC()->getOrderManager()->add_serialcode_to_order($order->get_id());

		$order = wc_get_order($order->get_id());
		foreach ($order->get_items() as $item_id => $item) {
			$meta = (object) ['key' => '_saso_eventtickets_public_ticket_ids'];
			set_current_screen('edit-post');

			$result = $this->main->getWC()->getOrderManager()->woocommerce_order_item_display_meta_key(
				'_saso_eventtickets_public_ticket_ids', $meta, $item
			);

			$this->assertStringContainsString('Public', $result);
			break;
		}
	}

	public function test_display_meta_key_fires_filter(): void {
		$tp = $this->createTicketProduct();
		$order = $this->createCompletedOrder($tp['product']);

		$order = wc_get_order($order->get_id());
		foreach ($order->get_items() as $item_id => $item) {
			$filtered = false;
			$callback = function ($key) use (&$filtered) {
				$filtered = true;
				return $key;
			};
			add_filter($this->main->_add_filter_prefix . 'woocommerce-hooks_woocommerce_order_item_display_meta_key', $callback);

			$meta = (object) ['key' => '_saso_eventtickets_product_code'];
			set_current_screen('edit-post');

			$this->main->getWC()->getOrderManager()->woocommerce_order_item_display_meta_key(
				'_saso_eventtickets_product_code', $meta, $item
			);

			$this->assertTrue($filtered);
			remove_filter($this->main->_add_filter_prefix . 'woocommerce-hooks_woocommerce_order_item_display_meta_key', $callback);
			break;
		}
	}

	// ── woocommerce_order_item_display_meta_value ─────────────

	public function test_display_meta_value_transforms_codes_to_links(): void {
		$tp = $this->createTicketProduct();
		$order = $this->createCompletedOrder($tp['product']);
		$this->main->getWC()->getOrderManager()->add_serialcode_to_order($order->get_id());

		$order = wc_get_order($order->get_id());
		$tested = false;
		foreach ($order->get_items() as $item_id => $item) {
			$codes = wc_get_order_item_meta($item_id, '_saso_eventtickets_product_code', true);
			if (!empty($codes)) {
				$meta = (object) ['key' => '_saso_eventtickets_product_code'];
				set_current_screen('edit-post');

				$result = $this->main->getWC()->getOrderManager()->woocommerce_order_item_display_meta_value(
					$codes, $meta, $item
				);

				$this->assertStringContainsString('<a ', $result);
				$this->assertStringContainsString('href=', $result);
				$tested = true;
			}
			break;
		}

		if (!$tested) {
			$this->markTestSkipped('No codes found in order item');
		}
	}

	public function test_display_meta_value_transforms_is_ticket_to_yes_no(): void {
		$tp = $this->createTicketProduct();
		$order = $this->createCompletedOrder($tp['product']);
		$this->main->getWC()->getOrderManager()->add_serialcode_to_order($order->get_id());

		$order = wc_get_order($order->get_id());
		foreach ($order->get_items() as $item_id => $item) {
			$meta = (object) ['key' => '_saso_eventtickets_is_ticket'];
			set_current_screen('edit-post');

			$result = $this->main->getWC()->getOrderManager()->woocommerce_order_item_display_meta_value(
				'1', $meta, $item
			);

			$this->assertEquals('Yes', $result);
			break;
		}
	}

	// ── Helper methods ─────────────────────────────────────────

	private function createTicketProduct(): array {
		$listId = $this->main->getDB()->insert('lists', [
			'name' => 'ProductConfig List ' . uniqid(),
			'aktiv' => 1,
			'meta' => '{}',
		]);

		$product = new WC_Product_Simple();
		$product->set_name('ProductConfig Ticket ' . uniqid());
		$product->set_regular_price('10.00');
		$product->set_status('publish');
		$product->save();
		$pid = $product->get_id();

		update_post_meta($pid, 'saso_eventtickets_is_ticket', 'yes');
		update_post_meta($pid, 'saso_eventtickets_list', $listId);

		return ['product' => $product, 'product_id' => $pid, 'list_id' => $listId];
	}

	private function createCompletedOrder(WC_Product $product, int $quantity = 1): WC_Order {
		$order = wc_create_order();
		$order->add_product($product, $quantity);
		$order->calculate_totals();
		$order->set_status('completed');
		$order->save();
		return wc_get_order($order->get_id());
	}
}
