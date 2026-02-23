<?php
/**
 * Tests for WC Email attachment handler:
 * woocommerce_email_attachments, basic structure tests.
 */

class WCEmailAttachmentTest extends WP_UnitTestCase {

    private $main;

    public function set_up(): void {
        parent::set_up();
        $this->main = sasoEventtickets::Instance();

        if (!class_exists('WC_Product_Simple')) {
            $this->markTestSkipped('WooCommerce not available');
        }
    }

    // ── woocommerce_email_attachments ────────────────────────────

    public function test_email_attachments_returns_array_for_non_order(): void {
        $email = $this->main->getWC()->getEmailHandler();
        $result = $email->woocommerce_email_attachments([], 'customer_new_account', null);
        $this->assertIsArray($result);
    }

    public function test_email_attachments_returns_unchanged_for_non_order(): void {
        $email = $this->main->getWC()->getEmailHandler();
        $existing = ['/tmp/existing.pdf'];
        $result = $email->woocommerce_email_attachments($existing, 'customer_new_account', 'not_an_order');
        $this->assertIsArray($result);
        // Non-order should return early with existing attachments
        $this->assertContains('/tmp/existing.pdf', $result);
    }

    public function test_email_attachments_with_order_returns_array(): void {
        $email = $this->main->getWC()->getEmailHandler();

        $order = wc_create_order();
        $order->set_status('completed');
        $order->save();

        $result = $email->woocommerce_email_attachments([], 'customer_completed_order', $order);
        $this->assertIsArray($result);
    }

    public function test_email_attachments_with_ticket_order_returns_array(): void {
        $email = $this->main->getWC()->getEmailHandler();

        $listId = $this->main->getDB()->insert('lists', [
            'name' => 'EmailTest List ' . uniqid(),
            'aktiv' => 1,
            'meta' => '{}',
        ]);

        $product = new WC_Product_Simple();
        $product->set_name('EmailTest Product ' . uniqid());
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

        $result = $email->woocommerce_email_attachments([], 'customer_completed_order', $order);
        $this->assertIsArray($result);
    }
}
