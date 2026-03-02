<?php
/**
 * Tests for WC Order item meta display methods:
 * - woocommerce_order_item_meta_start: renders ticket codes in emails/order pages
 * - wpo_wcpdf_after_item_meta: renders ticket codes for PDF invoices plugin
 * - hasTicketsInOrderWithTicketnumber: checks if order has tickets with codes
 * - woocommerce_thankyou: renders checkout thank-you page ticket links
 */

class WCOrderItemMetaDisplayTest extends WP_UnitTestCase {

	private $main;

	public function set_up(): void {
		parent::set_up();
		$this->main = sasoEventtickets::Instance();

		if (!class_exists('WC_Product_Simple')) {
			$this->markTestSkipped('WooCommerce not available');
		}
	}

	private function createTicketProduct(array $extraMeta = []): array {
		$listId = $this->main->getDB()->insert('lists', [
			'name' => 'MetaDisplay List ' . uniqid(),
			'aktiv' => 1,
			'meta' => '{}',
		]);

		$product = new WC_Product_Simple();
		$product->set_name('MetaDisplay Ticket ' . uniqid());
		$product->set_regular_price('12.00');
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

	private function createCompletedOrderWithCodes(WC_Product $product, int $quantity = 1): WC_Order {
		$order = wc_create_order();
		$order->add_product($product, $quantity);
		$order->set_billing_first_name('Meta');
		$order->set_billing_last_name('Display');
		$order->set_billing_email('meta@display.test');
		$order->calculate_totals();
		$order->set_status('completed');
		$order->save();
		return wc_get_order($order->get_id());
	}

	private function getFirstOrderItem(WC_Order $order): array {
		$items = $order->get_items();
		$item_id = key($items);
		$item = current($items);
		return ['item_id' => $item_id, 'item' => $item];
	}

	// ── hasTicketsInOrderWithTicketnumber ────────────────────────

	public function test_hasTicketsInOrderWithTicketnumber_true_for_completed_ticket_order(): void {
		$tp = $this->createTicketProduct();
		$order = $this->createCompletedOrderWithCodes($tp['product']);
		$first = $this->getFirstOrderItem($order);

		$codes = wc_get_order_item_meta($first['item_id'], '_saso_eventtickets_product_code', true);
		$this->assertNotEmpty($codes, 'Order should have ticket codes');

		$result = $this->main->getWC()->getOrderManager()->hasTicketsInOrderWithTicketnumber($order);
		$this->assertTrue($result);
	}

	public function test_hasTicketsInOrderWithTicketnumber_false_for_non_ticket_order(): void {
		$product = new WC_Product_Simple();
		$product->set_name('Regular Product');
		$product->set_regular_price('5.00');
		$product->set_status('publish');
		$product->save();

		$order = wc_create_order();
		$order->add_product($product, 1);
		$order->set_status('completed');
		$order->save();
		$order = wc_get_order($order->get_id());

		$result = $this->main->getWC()->getOrderManager()->hasTicketsInOrderWithTicketnumber($order);
		$this->assertFalse($result);
	}

	// ── woocommerce_order_item_meta_start ────────────────────────

	public function test_order_item_meta_start_outputs_ticket_code_html(): void {
		$tp = $this->createTicketProduct();
		$order = $this->createCompletedOrderWithCodes($tp['product']);
		$first = $this->getFirstOrderItem($order);

		ob_start();
		$this->main->getWC()->getOrderManager()->woocommerce_order_item_meta_start(
			$first['item_id'], $first['item'], $order, false
		);
		$output = ob_get_clean();

		// Should contain the product-serial-code div
		$this->assertStringContainsString('product-serial-code', $output);
	}

	public function test_order_item_meta_start_plain_text_mode(): void {
		$tp = $this->createTicketProduct();
		$order = $this->createCompletedOrderWithCodes($tp['product']);
		$first = $this->getFirstOrderItem($order);

		ob_start();
		$this->main->getWC()->getOrderManager()->woocommerce_order_item_meta_start(
			$first['item_id'], $first['item'], $order, true
		);
		$output = ob_get_clean();

		// Plain text mode should NOT contain HTML divs
		$this->assertStringNotContainsString('<div', $output);
		// Should contain ticket code text
		$this->assertNotEmpty(trim($output));
	}

	public function test_order_item_meta_start_skips_unpaid_order(): void {
		$tp = $this->createTicketProduct();
		$order = wc_create_order();
		$order->add_product($tp['product'], 1);
		$order->set_status('pending');
		$order->save();
		$order = wc_get_order($order->get_id());
		$first = $this->getFirstOrderItem($order);

		ob_start();
		$this->main->getWC()->getOrderManager()->woocommerce_order_item_meta_start(
			$first['item_id'], $first['item'], $order, false
		);
		$output = ob_get_clean();

		$this->assertEmpty(trim($output));
	}

	public function test_order_item_meta_start_suppressed_when_option_active(): void {
		$tp = $this->createTicketProduct();
		$order = $this->createCompletedOrderWithCodes($tp['product']);
		$first = $this->getFirstOrderItem($order);

		// Enable "do not put on email" option
		update_option('sasoEventticketswcassignmentDoNotPutOnEmail', '1');
		$this->main->getOptions()->initOptions();

		ob_start();
		$this->main->getWC()->getOrderManager()->woocommerce_order_item_meta_start(
			$first['item_id'], $first['item'], $order, false
		);
		$output = ob_get_clean();

		// Should NOT contain ticket codes when option is active
		$this->assertStringNotContainsString('product-serial-code', $output);

		// Reset
		update_option('sasoEventticketswcassignmentDoNotPutOnEmail', '0');
		$this->main->getOptions()->initOptions();
	}

	public function test_order_item_meta_start_fires_action(): void {
		$tp = $this->createTicketProduct();
		$order = $this->createCompletedOrderWithCodes($tp['product']);
		$first = $this->getFirstOrderItem($order);

		$fired = false;
		$callback = function () use (&$fired) {
			$fired = true;
		};
		add_action('saso_eventtickets_woocommerce-hooks_woocommerce_order_item_meta_start', $callback);

		ob_start();
		$this->main->getWC()->getOrderManager()->woocommerce_order_item_meta_start(
			$first['item_id'], $first['item'], $order, false
		);
		ob_end_clean();

		$this->assertTrue($fired);

		remove_action('saso_eventtickets_woocommerce-hooks_woocommerce_order_item_meta_start', $callback);
	}

	// ── wpo_wcpdf_after_item_meta ────────────────────────────────

	public function test_wpo_wcpdf_skips_unpaid_order(): void {
		$tp = $this->createTicketProduct();
		$order = wc_create_order();
		$order->add_product($tp['product'], 1);
		$order->set_status('pending');
		$order->save();
		$order = wc_get_order($order->get_id());
		$first = $this->getFirstOrderItem($order);

		$itemArray = [
			'item_id' => $first['item_id'],
			'product_id' => $tp['product_id'],
		];

		ob_start();
		$this->main->getWC()->getOrderManager()->wpo_wcpdf_after_item_meta('invoice', $itemArray, $order);
		$output = ob_get_clean();

		$this->assertEmpty(trim($output));
	}

	public function test_wpo_wcpdf_outputs_ticket_codes_for_paid_order(): void {
		$tp = $this->createTicketProduct();
		$order = $this->createCompletedOrderWithCodes($tp['product']);
		$first = $this->getFirstOrderItem($order);

		$itemArray = [
			'item_id' => $first['item_id'],
			'product_id' => $tp['product_id'],
		];

		ob_start();
		$this->main->getWC()->getOrderManager()->wpo_wcpdf_after_item_meta('invoice', $itemArray, $order);
		$output = ob_get_clean();

		// Ticket codes are rendered with <b> tags and ticket number prefix
		$this->assertNotEmpty(trim($output));
		$this->assertStringContainsString('<b>', $output);
	}

	public function test_wpo_wcpdf_suppressed_when_option_active(): void {
		$tp = $this->createTicketProduct();
		$order = $this->createCompletedOrderWithCodes($tp['product']);
		$first = $this->getFirstOrderItem($order);

		update_option('sasoEventticketswcassignmentDoNotPutOnPDF', '1');
		$this->main->getOptions()->initOptions();

		$itemArray = [
			'item_id' => $first['item_id'],
			'product_id' => $tp['product_id'],
		];

		ob_start();
		$this->main->getWC()->getOrderManager()->wpo_wcpdf_after_item_meta('invoice', $itemArray, $order);
		$output = ob_get_clean();

		// When suppressed, no ticket codes should be rendered
		$this->assertEmpty(trim($output));

		update_option('sasoEventticketswcassignmentDoNotPutOnPDF', '0');
		$this->main->getOptions()->initOptions();
	}

	// ── woocommerce_thankyou ─────────────────────────────────────

	public function test_thankyou_returns_early_for_zero_order_id(): void {
		ob_start();
		$this->main->getWC()->getFrontendManager()->woocommerce_thankyou(0);
		$output = ob_get_clean();

		$this->assertEmpty(trim($output));
	}

	public function test_thankyou_returns_early_for_non_ticket_order(): void {
		$product = new WC_Product_Simple();
		$product->set_name('Regular Product');
		$product->set_regular_price('5.00');
		$product->set_status('publish');
		$product->save();

		$order = wc_create_order();
		$order->add_product($product, 1);
		$order->set_status('completed');
		$order->save();

		ob_start();
		$this->main->getWC()->getFrontendManager()->woocommerce_thankyou($order->get_id());
		$output = ob_get_clean();

		$this->assertEmpty(trim($output));
	}

	public function test_thankyou_shows_pdf_download_when_option_active(): void {
		$tp = $this->createTicketProduct();
		$order = $this->createCompletedOrderWithCodes($tp['product']);

		update_option('sasoEventticketswcTicketDisplayDownloadAllTicketsPDFButtonOnCheckout', '1');
		$this->main->getOptions()->initOptions();

		ob_start();
		$this->main->getWC()->getFrontendManager()->woocommerce_thankyou($order->get_id());
		$output = ob_get_clean();

		$this->assertStringContainsString('<a target="_blank"', $output);

		update_option('sasoEventticketswcTicketDisplayDownloadAllTicketsPDFButtonOnCheckout', '0');
		$this->main->getOptions()->initOptions();
	}

	public function test_thankyou_shows_nothing_when_both_options_disabled(): void {
		$tp = $this->createTicketProduct();
		$order = $this->createCompletedOrderWithCodes($tp['product']);

		update_option('sasoEventticketswcTicketDisplayDownloadAllTicketsPDFButtonOnCheckout', '0');
		update_option('sasoEventticketswcTicketDisplayOrderTicketsViewLinkOnCheckout', '0');
		$this->main->getOptions()->initOptions();

		ob_start();
		$this->main->getWC()->getFrontendManager()->woocommerce_thankyou($order->get_id());
		$output = ob_get_clean();

		$this->assertEmpty(trim($output));

		$this->main->getOptions()->initOptions();
	}
}
