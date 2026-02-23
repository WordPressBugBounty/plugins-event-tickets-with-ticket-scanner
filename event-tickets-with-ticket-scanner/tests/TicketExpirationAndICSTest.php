<?php
/**
 * Tests for Ticket expiration and ICS generation:
 * get_expiration, generateICSFile, getRedeemAmountText.
 */

class TicketExpirationAndICSTest extends WP_UnitTestCase {

    private $main;

    public function set_up(): void {
        parent::set_up();
        $this->main = sasoEventtickets::Instance();
    }

    // ── get_expiration ───────────────────────────────────────────

    public function test_get_expiration_returns_array(): void {
        $ticket = $this->main->getTicketHandler();
        $result = $ticket->get_expiration();
        $this->assertIsArray($result);
    }

    public function test_get_expiration_has_required_keys(): void {
        $ticket = $this->main->getTicketHandler();
        $result = $ticket->get_expiration();
        $this->assertArrayHasKey('last_run', $result);
        $this->assertArrayHasKey('timestamp', $result);
        $this->assertArrayHasKey('expiration_date', $result);
        $this->assertArrayHasKey('subscription_type', $result);
        $this->assertArrayHasKey('grace_period_days', $result);
        $this->assertArrayHasKey('consecutive_failures', $result);
    }

    public function test_get_expiration_default_subscription_type(): void {
        $ticket = $this->main->getTicketHandler();
        $result = $ticket->get_expiration();
        // Default should be 'abo' unless changed
        $this->assertContains($result['subscription_type'], ['abo', 'lifetime']);
    }

    // ── generateICSFile ──────────────────────────────────────────

    public function test_generateICSFile_returns_string(): void {
        if (!class_exists('WC_Product_Simple')) {
            $this->markTestSkipped('WooCommerce not available');
        }

        $product = new WC_Product_Simple();
        $product->set_name('ICS Test Event ' . uniqid());
        $product->set_regular_price('25.00');
        $product->set_status('publish');
        $product->save();

        // Set event date so ICS can generate
        update_post_meta($product->get_id(), 'saso_eventtickets_ticket_start_date', '2026-12-25');
        update_post_meta($product->get_id(), 'saso_eventtickets_ticket_start_time', '10:00:00');

        $ticket = $this->main->getTicketHandler();
        $result = $ticket->generateICSFile($product);
        $this->assertIsString($result);
        $this->assertStringContainsString('BEGIN:VCALENDAR', $result);
    }

    public function test_generateICSFile_contains_event_name(): void {
        if (!class_exists('WC_Product_Simple')) {
            $this->markTestSkipped('WooCommerce not available');
        }

        $eventName = 'ICS Name Test ' . uniqid();
        $product = new WC_Product_Simple();
        $product->set_name($eventName);
        $product->set_regular_price('25.00');
        $product->set_status('publish');
        $product->save();

        update_post_meta($product->get_id(), 'saso_eventtickets_ticket_start_date', '2026-12-25');

        $ticket = $this->main->getTicketHandler();
        $result = $ticket->generateICSFile($product);
        $this->assertStringContainsString($eventName, $result);
    }

    public function test_generateICSFile_contains_vevent(): void {
        if (!class_exists('WC_Product_Simple')) {
            $this->markTestSkipped('WooCommerce not available');
        }

        $product = new WC_Product_Simple();
        $product->set_name('ICS VEvent Test ' . uniqid());
        $product->set_regular_price('25.00');
        $product->set_status('publish');
        $product->save();

        update_post_meta($product->get_id(), 'saso_eventtickets_ticket_start_date', '2026-06-15');
        update_post_meta($product->get_id(), 'saso_eventtickets_ticket_start_time', '14:00:00');

        $ticket = $this->main->getTicketHandler();
        $result = $ticket->generateICSFile($product);
        $this->assertStringContainsString('BEGIN:VEVENT', $result);
        $this->assertStringContainsString('END:VEVENT', $result);
    }

    // ── getRedeemAmountText ──────────────────────────────────────

    public function test_getRedeemAmountText_empty_for_single_redeem(): void {
        if (!class_exists('WC_Product_Simple')) {
            $this->markTestSkipped('WooCommerce not available');
        }

        $listId = $this->main->getDB()->insert('lists', [
            'name' => 'RedeemText List ' . uniqid(),
            'aktiv' => 1,
            'meta' => '{}',
        ]);

        $product = new WC_Product_Simple();
        $product->set_name('RedeemText Product ' . uniqid());
        $product->set_regular_price('10.00');
        $product->set_status('publish');
        $product->save();

        update_post_meta($product->get_id(), 'saso_eventtickets_is_ticket', 'yes');
        update_post_meta($product->get_id(), 'saso_eventtickets_list', $listId);
        // max_redeem_amount defaults to 1

        $order = wc_create_order();
        $order->add_product($product, 1);
        $order->calculate_totals();
        $order->set_status('completed');
        $order->save();

        $this->main->getWC()->getOrderManager()->add_serialcode_to_order($order->get_id());
        $codes = $this->main->getCore()->getCodesByOrderId($order->get_id());
        $codeObj = $this->main->getCore()->retrieveCodeByCode($codes[0]['code']);
        $metaObj = $this->main->getCore()->encodeMetaValuesAndFillObject($codeObj['meta'], $codeObj);

        $ticket = $this->main->getTicketHandler();
        $result = $ticket->getRedeemAmountText($codeObj, $metaObj);
        // For single-redeem tickets, text should be empty
        $this->assertEmpty($result);
    }
}
