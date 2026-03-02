<?php
/**
 * Tests for WC Email handler:
 * - woocommerce_email_order_meta: PDF download + order view links in emails
 * - woocommerce_email_attachments: early return for non-WC_Order, filter hooks
 * - getTempDirectory: returns writable temp path
 * - delete_specific_attachments: cleans up temp files safely
 * - hasTicketsInOrderWithTicketnumber: true/false for ticket/regular orders
 */

class EmailOrderMetaAndAttachmentsTest extends WP_UnitTestCase {

	private $main;

	public function set_up(): void {
		parent::set_up();
		$this->main = sasoEventtickets::Instance();

		if (!class_exists('WC_Product_Simple')) {
			$this->markTestSkipped('WooCommerce not available');
		}
	}

	public function tear_down(): void {
		parent::tear_down();
	}

	// ── woocommerce_email_order_meta — returns early for disallowed email ─

	public function test_email_order_meta_returns_empty_for_disallowed_email(): void {
		$order = $this->createSimpleOrder();

		$email = $this->createMockEmail('customer_refunded_order');

		ob_start();
		$this->main->getWC()->getEmailHandler()->woocommerce_email_order_meta($order, false, false, $email);
		$output = ob_get_clean();

		$this->assertEmpty($output);
	}

	// ── woocommerce_email_order_meta — no output when options disabled ────

	public function test_email_order_meta_no_output_when_both_options_disabled(): void {
		update_option('sasoEventticketswcTicketDisplayDownloadAllTicketsPDFButtonOnMail', '0');
		update_option('sasoEventticketswcTicketDisplayOrderTicketsViewLinkOnMail', '0');
		$this->main->getOptions()->initOptions();

		$tp = $this->createTicketProduct();
		$order = $this->createCompletedOrderWithCodes($tp['product']);

		$email = $this->createMockEmail('customer_completed_order');

		ob_start();
		$this->main->getWC()->getEmailHandler()->woocommerce_email_order_meta($order, false, false, $email);
		$output = ob_get_clean();

		$this->assertEmpty($output);
	}

	// ── woocommerce_email_order_meta — shows PDF download link ────────────

	public function test_email_order_meta_shows_pdf_link_when_enabled(): void {
		update_option('sasoEventticketswcTicketDisplayDownloadAllTicketsPDFButtonOnMail', '1');
		update_option('sasoEventticketswcTicketDisplayOrderTicketsViewLinkOnMail', '0');
		$this->main->getOptions()->initOptions();

		$tp = $this->createTicketProduct();
		$order = $this->createCompletedOrderWithCodes($tp['product']);

		$email = $this->createMockEmail('customer_completed_order');

		ob_start();
		$this->main->getWC()->getEmailHandler()->woocommerce_email_order_meta($order, false, false, $email);
		$output = ob_get_clean();

		$this->assertStringContainsString('<a ', $output);
		$this->assertStringContainsString('href=', $output);
	}

	// ── woocommerce_email_order_meta — shows order view link ─────────────

	public function test_email_order_meta_shows_order_view_link_when_enabled(): void {
		update_option('sasoEventticketswcTicketDisplayDownloadAllTicketsPDFButtonOnMail', '0');
		update_option('sasoEventticketswcTicketDisplayOrderTicketsViewLinkOnMail', '1');
		$this->main->getOptions()->initOptions();

		$tp = $this->createTicketProduct();
		$order = $this->createCompletedOrderWithCodes($tp['product']);

		$email = $this->createMockEmail('customer_completed_order');

		ob_start();
		$this->main->getWC()->getEmailHandler()->woocommerce_email_order_meta($order, false, false, $email);
		$output = ob_get_clean();

		$this->assertStringContainsString('<a ', $output);
		$this->assertStringContainsString('href=', $output);
	}

	// ── woocommerce_email_order_meta — heading dedup ─────────────────────

	public function test_email_order_meta_heading_shown_once_when_both_links_enabled(): void {
		update_option('sasoEventticketswcTicketDisplayDownloadAllTicketsPDFButtonOnMail', '1');
		update_option('sasoEventticketswcTicketDisplayOrderTicketsViewLinkOnMail', '1');
		update_option('sasoEventticketswcTicketLabelPDFDownloadHeading', 'Your Tickets');
		$this->main->getOptions()->initOptions();

		$tp = $this->createTicketProduct();
		$order = $this->createCompletedOrderWithCodes($tp['product']);

		$email = $this->createMockEmail('customer_completed_order');

		ob_start();
		$this->main->getWC()->getEmailHandler()->woocommerce_email_order_meta($order, false, false, $email);
		$output = ob_get_clean();

		// Heading should appear exactly once due to $isHeaderAdded guard
		$this->assertEquals(1, substr_count($output, '<h2>Your Tickets</h2>'));
	}

	// ── woocommerce_email_order_meta — fires action ──────────────────────

	public function test_email_order_meta_fires_action(): void {
		update_option('sasoEventticketswcTicketDisplayDownloadAllTicketsPDFButtonOnMail', '1');
		$this->main->getOptions()->initOptions();

		$tp = $this->createTicketProduct();
		$order = $this->createCompletedOrderWithCodes($tp['product']);

		$email = $this->createMockEmail('customer_completed_order');

		$fired = false;
		$callback = function () use (&$fired) {
			$fired = true;
		};
		add_action($this->main->_do_action_prefix . 'woocommerce-hooks_woocommerce_email_order_meta', $callback);

		ob_start();
		$this->main->getWC()->getEmailHandler()->woocommerce_email_order_meta($order, false, false, $email);
		ob_end_clean();

		$this->assertTrue($fired);

		remove_action($this->main->_do_action_prefix . 'woocommerce-hooks_woocommerce_email_order_meta', $callback);
	}

	// ── woocommerce_email_order_meta — no tickets → no output ────────────

	public function test_email_order_meta_no_output_for_order_without_tickets(): void {
		update_option('sasoEventticketswcTicketDisplayDownloadAllTicketsPDFButtonOnMail', '1');
		update_option('sasoEventticketswcTicketDisplayOrderTicketsViewLinkOnMail', '1');
		$this->main->getOptions()->initOptions();

		$order = $this->createSimpleOrder();

		$email = $this->createMockEmail('customer_completed_order');

		ob_start();
		$this->main->getWC()->getEmailHandler()->woocommerce_email_order_meta($order, false, false, $email);
		$output = ob_get_clean();

		// No tickets → no PDF link, no view link (but action still fires)
		$this->assertStringNotContainsString('<a ', $output);
	}

	// ── woocommerce_email_attachments — early return for non-order ───────

	public function test_email_attachments_returns_unchanged_for_non_order(): void {
		$emailManager = $this->main->getWC()->getEmailHandler();

		$attachments = ['existing_file.pdf'];
		$result = $emailManager->woocommerce_email_attachments($attachments, 'customer_completed_order', 'not_an_order');

		$this->assertEquals($attachments, $result);
	}

	public function test_email_attachments_returns_unchanged_for_null_email_id(): void {
		$emailManager = $this->main->getWC()->getEmailHandler();
		$order = $this->createSimpleOrder();

		$attachments = [];
		// email_id is set but order without tickets → no attachments added (options disabled by default)
		$result = $emailManager->woocommerce_email_attachments($attachments, 'customer_completed_order', $order);

		$this->assertIsArray($result);
	}

	// ── hasTicketsInOrderWithTicketnumber ─────────────────────────────────

	public function test_hasTicketsInOrder_false_for_regular_order(): void {
		$order = $this->createSimpleOrder();

		$result = $this->main->getWC()->getOrderManager()->hasTicketsInOrderWithTicketnumber($order);

		$this->assertFalse($result);
	}

	public function test_hasTicketsInOrder_true_for_ticket_order(): void {
		$tp = $this->createTicketProduct();
		$order = $this->createCompletedOrderWithCodes($tp['product']);

		$result = $this->main->getWC()->getOrderManager()->hasTicketsInOrderWithTicketnumber($order);

		$this->assertTrue($result);
	}

	// ── getTicketsFromOrder ──────────────────────────────────────────────

	public function test_getTicketsFromOrder_empty_for_regular_order(): void {
		$order = $this->createSimpleOrder();

		$result = $this->main->getWC()->getOrderManager()->getTicketsFromOrder($order);

		$this->assertEmpty($result);
	}

	public function test_getTicketsFromOrder_returns_product_data_for_ticket_order(): void {
		$tp = $this->createTicketProduct();
		$order = $this->createCompletedOrderWithCodes($tp['product']);

		$result = $this->main->getWC()->getOrderManager()->getTicketsFromOrder($order);

		$this->assertNotEmpty($result);
		$ticket = reset($result);
		$this->assertArrayHasKey('product_id', $ticket);
		$this->assertArrayHasKey('codes', $ticket);
		$this->assertArrayHasKey('quantity', $ticket);
		$this->assertEquals($tp['product_id'], $ticket['product_id']);
	}

	// ── getTempDirectory ────────────────────────────────────────────────

	public function test_getTempDirectory_returns_string_path(): void {
		$ref = new ReflectionMethod($this->main->getWC()->getEmailHandler(), 'getTempDirectory');
		$ref->setAccessible(true);

		$result = $ref->invoke($this->main->getWC()->getEmailHandler());

		$this->assertIsString($result);
		$this->assertStringContainsString($this->main->getPrefix(), $result);
	}

	public function test_getTempDirectory_creates_directory(): void {
		$ref = new ReflectionMethod($this->main->getWC()->getEmailHandler(), 'getTempDirectory');
		$ref->setAccessible(true);

		$result = $ref->invoke($this->main->getWC()->getEmailHandler());

		$this->assertTrue(is_dir($result));
	}

	// ── delete_specific_attachments ─────────────────────────────────────

	public function test_delete_specific_attachments_removes_files_in_temp_dir(): void {
		// Create a temp file in the plugin's temp directory
		$refDir = new ReflectionMethod($this->main->getWC()->getEmailHandler(), 'getTempDirectory');
		$refDir->setAccessible(true);
		$dirname = $refDir->invoke($this->main->getWC()->getEmailHandler());

		$tempFile = $dirname . 'test_cleanup_' . uniqid() . '.pdf';
		file_put_contents($tempFile, 'test content');
		$this->assertTrue(file_exists($tempFile));

		$ref = new ReflectionMethod($this->main->getWC()->getEmailHandler(), 'delete_specific_attachments');
		$ref->setAccessible(true);
		$ref->invoke($this->main->getWC()->getEmailHandler(), [$tempFile]);

		$this->assertFalse(file_exists($tempFile));
	}

	public function test_delete_specific_attachments_ignores_files_outside_temp_dir(): void {
		$tempFile = get_temp_dir() . 'test_outside_' . uniqid() . '.pdf';
		file_put_contents($tempFile, 'test content');
		$this->assertTrue(file_exists($tempFile));

		$ref = new ReflectionMethod($this->main->getWC()->getEmailHandler(), 'delete_specific_attachments');
		$ref->setAccessible(true);
		$ref->invoke($this->main->getWC()->getEmailHandler(), [$tempFile]);

		// Should NOT be deleted — it's outside the plugin's temp directory
		$this->assertTrue(file_exists($tempFile));

		// Clean up manually
		@unlink($tempFile);
	}

	// ── Helper methods ─────────────────────────────────────────────────

	private function createSimpleOrder(): WC_Order {
		$product = new WC_Product_Simple();
		$product->set_name('Regular Email Test');
		$product->set_regular_price('5.00');
		$product->set_status('publish');
		$product->save();

		$order = wc_create_order();
		$order->add_product($product, 1);
		$order->set_billing_email('emailtest@test.local');
		$order->calculate_totals();
		$order->set_status('completed');
		$order->save();
		return wc_get_order($order->get_id());
	}

	private function createTicketProduct(): array {
		$listId = $this->main->getDB()->insert('lists', [
			'name'  => 'EmailTest List ' . uniqid(),
			'aktiv' => 1,
			'meta'  => '{}',
		]);

		$product = new WC_Product_Simple();
		$product->set_name('EmailTest Ticket ' . uniqid());
		$product->set_regular_price('10.00');
		$product->set_status('publish');
		$product->save();
		$pid = $product->get_id();

		update_post_meta($pid, 'saso_eventtickets_is_ticket', 'yes');
		update_post_meta($pid, 'saso_eventtickets_list', $listId);

		return ['product' => $product, 'product_id' => $pid, 'list_id' => $listId];
	}

	private function createCompletedOrderWithCodes(WC_Product $product, int $quantity = 1): WC_Order {
		$order = wc_create_order();
		$order->add_product($product, $quantity);
		$order->set_billing_first_name('EmailTest');
		$order->set_billing_last_name('User');
		$order->set_billing_email('emailtest@test.local');
		$order->calculate_totals();
		$order->set_status('completed');
		$order->save();
		return wc_get_order($order->get_id());
	}

	private function createMockEmail(string $emailId): object {
		return (object) ['id' => $emailId];
	}
}
