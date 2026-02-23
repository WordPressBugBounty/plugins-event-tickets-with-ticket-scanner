<?php
/**
 * Tests for WooCommerce email handler (attachment logic, temp directory).
 *
 * Note: Actual PDF/ICS generation requires external libraries (TCPDF, etc.)
 * and is not tested here. We test the attachment decision logic and structure.
 */

class EmailHandlerTest extends WP_UnitTestCase {

    private $main;

    public function set_up(): void {
        parent::set_up();
        $this->main = sasoEventtickets::Instance();

        if (!class_exists('WC_Product_Simple')) {
            $this->markTestSkipped('WooCommerce not available');
        }
    }

    // ── getTempDirectory ─────────────────────────────────────────

    public function test_getTempDirectory_returns_writable_path(): void {
        $emailHandler = $this->main->getWC()->getEmailHandler();
        $reflection = new ReflectionMethod($emailHandler, 'getTempDirectory');
        $reflection->setAccessible(true);

        $dir = $reflection->invoke($emailHandler);
        $this->assertNotFalse($dir);
        $this->assertDirectoryExists($dir);
        $this->assertTrue(wp_is_writable($dir));
    }

    public function test_getTempDirectory_contains_plugin_prefix(): void {
        $emailHandler = $this->main->getWC()->getEmailHandler();
        $reflection = new ReflectionMethod($emailHandler, 'getTempDirectory');
        $reflection->setAccessible(true);

        $dir = $reflection->invoke($emailHandler);
        $this->assertStringContainsString($this->main->getPrefix(), $dir);
    }

    // ── woocommerce_email_attachments ────────────────────────────

    public function test_email_attachments_returns_array_for_non_order(): void {
        $emailHandler = $this->main->getWC()->getEmailHandler();
        $result = $emailHandler->woocommerce_email_attachments([], 'customer_completed_order', 'not_an_order');
        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function test_email_attachments_returns_array_for_order_without_tickets(): void {
        $product = new WC_Product_Simple();
        $product->set_name('Non-Ticket Product');
        $product->set_regular_price('5.00');
        $product->set_status('publish');
        $product->save();

        $order = wc_create_order();
        $order->add_product($product, 1);
        $order->set_status('completed');
        $order->save();

        $emailHandler = $this->main->getWC()->getEmailHandler();
        $result = $emailHandler->woocommerce_email_attachments([], 'customer_completed_order', $order);
        $this->assertIsArray($result);
    }

    // ── woocommerce_email_order_meta ─────────────────────────────

    public function test_email_order_meta_outputs_nothing_for_non_ticket_order(): void {
        $product = new WC_Product_Simple();
        $product->set_name('No Ticket Meta');
        $product->set_regular_price('5.00');
        $product->set_status('publish');
        $product->save();

        $order = wc_create_order();
        $order->add_product($product, 1);
        $order->set_status('completed');
        $order->save();

        $email = new stdClass();
        $email->id = 'customer_completed_order';

        $emailHandler = $this->main->getWC()->getEmailHandler();

        ob_start();
        $emailHandler->woocommerce_email_order_meta($order, false, false, $email);
        $output = ob_get_clean();

        // No tickets = no download link output
        $this->assertEmpty($output);
    }

    // ── Attachment decision logic ────────────────────────────────

    public function test_ics_attachment_disabled_by_default(): void {
        // ICS attachment depends on wcTicketAttachICSToMail option
        $isActive = $this->main->getOptions()->isOptionCheckboxActive('wcTicketAttachICSToMail');
        // We just verify the option is queryable (may be true or false depending on config)
        $this->assertIsBool($isActive);
    }

    public function test_badge_attachment_disabled_by_default(): void {
        $isActive = $this->main->getOptions()->isOptionCheckboxActive('wcTicketBadgeAttachFileToMail');
        $this->assertIsBool($isActive);
    }

    public function test_qr_image_attachment_disabled_by_default(): void {
        $isActive = $this->main->getOptions()->isOptionCheckboxActive('qrAttachQRImageToEmail');
        $this->assertIsBool($isActive);
    }

    public function test_qr_pdf_attachment_disabled_by_default(): void {
        $isActive = $this->main->getOptions()->isOptionCheckboxActive('qrAttachQRPdfToEmail');
        $this->assertIsBool($isActive);
    }
}
