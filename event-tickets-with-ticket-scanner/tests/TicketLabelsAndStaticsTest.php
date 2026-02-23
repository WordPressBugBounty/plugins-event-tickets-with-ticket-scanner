<?php
/**
 * Tests for Ticket label methods: get_expiration, getLabelNamePerTicket,
 * getLabelValuePerTicket, getLabelDaychooserPerTicket, getRedeemAmountText.
 * And static helpers: getMediaData, issetRPara.
 */

class TicketLabelsAndStaticsTest extends WP_UnitTestCase {

    private $main;

    public function set_up(): void {
        parent::set_up();
        $this->main = sasoEventtickets::Instance();
    }

    // ── get_expiration ───────────────────────────────────────────

    public function test_get_expiration_returns_array(): void {
        $result = $this->main->getTicketHandler()->get_expiration();
        $this->assertIsArray($result);
    }

    public function test_get_expiration_has_expected_keys(): void {
        $result = $this->main->getTicketHandler()->get_expiration();
        $this->assertArrayHasKey('subscription_type', $result);
        $this->assertArrayHasKey('grace_period_days', $result);
        $this->assertArrayHasKey('expiration_date', $result);
    }

    public function test_get_expiration_default_subscription_type(): void {
        $result = $this->main->getTicketHandler()->get_expiration();
        $this->assertEquals('abo', $result['subscription_type']);
    }

    // ── getLabelNamePerTicket ─────────────────────────────────────

    public function test_getLabelNamePerTicket_returns_string(): void {
        if (!class_exists('WC_Product_Simple')) {
            $this->markTestSkipped('WooCommerce not available');
        }

        $product = new WC_Product_Simple();
        $product->set_name('Label Test');
        $product->set_regular_price('10.00');
        $product->set_status('publish');
        $product->save();

        $label = $this->main->getTicketHandler()->getLabelNamePerTicket($product->get_id());
        $this->assertIsString($label);
        $this->assertNotEmpty($label);
    }

    public function test_getLabelNamePerTicket_custom_label(): void {
        if (!class_exists('WC_Product_Simple')) {
            $this->markTestSkipped('WooCommerce not available');
        }

        $product = new WC_Product_Simple();
        $product->set_name('Custom Label Test');
        $product->set_regular_price('10.00');
        $product->set_status('publish');
        $product->save();

        update_post_meta($product->get_id(), 'saso_eventtickets_request_name_per_ticket_label', 'Custom Name:');
        $label = $this->main->getTicketHandler()->getLabelNamePerTicket($product->get_id());
        $this->assertEquals('Custom Name:', $label);
    }

    // ── getLabelValuePerTicket ────────────────────────────────────

    public function test_getLabelValuePerTicket_returns_string(): void {
        if (!class_exists('WC_Product_Simple')) {
            $this->markTestSkipped('WooCommerce not available');
        }

        $product = new WC_Product_Simple();
        $product->set_name('Value Label Test');
        $product->set_regular_price('10.00');
        $product->set_status('publish');
        $product->save();

        $label = $this->main->getTicketHandler()->getLabelValuePerTicket($product->get_id());
        $this->assertIsString($label);
        $this->assertNotEmpty($label);
    }

    // ── getLabelDaychooserPerTicket ───────────────────────────────

    public function test_getLabelDaychooserPerTicket_returns_string(): void {
        if (!class_exists('WC_Product_Simple')) {
            $this->markTestSkipped('WooCommerce not available');
        }

        $product = new WC_Product_Simple();
        $product->set_name('Day Label Test');
        $product->set_regular_price('10.00');
        $product->set_status('publish');
        $product->save();

        $label = $this->main->getTicketHandler()->getLabelDaychooserPerTicket($product->get_id());
        $this->assertIsString($label);
        $this->assertNotEmpty($label);
    }

    // ── getRedeemAmountText ──────────────────────────────────────

    public function test_getRedeemAmountText_empty_for_single_redeem(): void {
        if (!class_exists('WC_Product_Simple')) {
            $this->markTestSkipped('WooCommerce not available');
        }

        $listId = $this->main->getDB()->insert('lists', [
            'name' => 'Redeem Text Test ' . uniqid(),
            'aktiv' => 1,
            'meta' => '{}',
        ]);

        $product = new WC_Product_Simple();
        $product->set_name('Redeem Text Product');
        $product->set_regular_price('10.00');
        $product->set_status('publish');
        $product->save();

        update_post_meta($product->get_id(), 'saso_eventtickets_is_ticket', 'yes');
        update_post_meta($product->get_id(), 'saso_eventtickets_list', $listId);
        // Default max redeem is 1 or unset

        $order = wc_create_order();
        $order->add_product($product, 1);
        $order->calculate_totals();
        $order->set_status('completed');
        $order->save();

        $this->main->getWC()->getOrderManager()->add_serialcode_to_order($order->get_id());
        $codes = $this->main->getCore()->getCodesByOrderId($order->get_id());
        $codeObj = $this->main->getCore()->retrieveCodeByCode($codes[0]['code']);
        $metaObj = $this->main->getCore()->encodeMetaValuesAndFillObject($codeObj['meta'], $codeObj);

        $text = $this->main->getTicketHandler()->getRedeemAmountText($codeObj, $metaObj);
        // Max redeem = 1 (default) → empty string
        $this->assertEquals('', $text);
    }

    public function test_getRedeemAmountText_shows_text_for_multi_redeem(): void {
        if (!class_exists('WC_Product_Simple')) {
            $this->markTestSkipped('WooCommerce not available');
        }

        $listId = $this->main->getDB()->insert('lists', [
            'name' => 'Multi Redeem Text ' . uniqid(),
            'aktiv' => 1,
            'meta' => '{}',
        ]);

        $product = new WC_Product_Simple();
        $product->set_name('Multi Redeem Product');
        $product->set_regular_price('10.00');
        $product->set_status('publish');
        $product->save();

        update_post_meta($product->get_id(), 'saso_eventtickets_is_ticket', 'yes');
        update_post_meta($product->get_id(), 'saso_eventtickets_list', $listId);
        update_post_meta($product->get_id(), 'saso_eventtickets_ticket_max_redeem_amount', 5);

        $order = wc_create_order();
        $order->add_product($product, 1);
        $order->calculate_totals();
        $order->set_status('completed');
        $order->save();

        $this->main->getWC()->getOrderManager()->add_serialcode_to_order($order->get_id());
        $codes = $this->main->getCore()->getCodesByOrderId($order->get_id());
        $codeObj = $this->main->getCore()->retrieveCodeByCode($codes[0]['code']);
        $metaObj = $this->main->getCore()->encodeMetaValuesAndFillObject($codeObj['meta'], $codeObj);

        $text = $this->main->getTicketHandler()->getRedeemAmountText($codeObj, $metaObj);
        $this->assertIsString($text);
        // Max redeem = 5, so text should include "5"
        $this->assertStringContainsString('5', $text);
    }

    // ── getMediaData ─────────────────────────────────────────────

    public function test_getMediaData_returns_array(): void {
        $result = SASO_EVENTTICKETS::getMediaData(0);
        $this->assertIsArray($result);
        $this->assertArrayHasKey('title', $result);
        $this->assertArrayHasKey('url', $result);
        $this->assertArrayHasKey('suffix', $result);
    }

    public function test_getMediaData_nonexistent_returns_empty_values(): void {
        $result = SASO_EVENTTICKETS::getMediaData(999999);
        $this->assertIsArray($result);
        $this->assertEmpty($result['title']);
    }

    // ── issetRPara ───────────────────────────────────────────────

    public function test_issetRPara_returns_false_for_nonexistent(): void {
        $result = SASO_EVENTTICKETS::issetRPara('nonexistent_param_' . uniqid());
        $this->assertFalse($result);
    }

    public function test_issetRPara_returns_true_for_get_param(): void {
        $_GET['test_isset_param'] = 'value';
        $_SERVER['REQUEST_METHOD'] = 'GET';

        $result = SASO_EVENTTICKETS::issetRPara('test_isset_param');
        $this->assertTrue($result);

        unset($_GET['test_isset_param']);
    }
}
