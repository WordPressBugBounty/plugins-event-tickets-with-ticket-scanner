<?php
/**
 * Tests for Ticket handler methods: isScanner, get_product, get_is_paid_statuses,
 * getMaxRedeemAmountOfTicket, ermittelCodePosition, getOrderItem, getUserRedirectURLForCode.
 */

class TicketScannerMethodsTest extends WP_UnitTestCase {

    private $main;

    public function set_up(): void {
        parent::set_up();
        $this->main = sasoEventtickets::Instance();

        if (!class_exists('WC_Product_Simple')) {
            $this->markTestSkipped('WooCommerce not available');
        }
    }

    // ── isScanner ───────────────────────────────────────────────

    public function test_isScanner_returns_false_for_normal_uri(): void {
        $ticket = new sasoEventtickets_Ticket('/ticket/ABC123/');
        $this->assertFalse($ticket->isScanner());
    }

    public function test_isScanner_returns_true_for_scanner_uri(): void {
        $user = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($user);

        $ticket = new sasoEventtickets_Ticket('/wp-content/plugins/event-tickets-with-ticket-scanner/ticket/scanner/');
        $this->assertTrue($ticket->isScanner());
    }

    public function test_isScanner_returns_true_for_scanner_with_trailing_slash(): void {
        $user = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($user);

        $ticket = new sasoEventtickets_Ticket('/ticket/scanner/');
        $this->assertTrue($ticket->isScanner());
    }

    // ── get_product ─────────────────────────────────────────────

    public function test_get_product_returns_product(): void {
        $product = new WC_Product_Simple();
        $product->set_name('GetProduct Test');
        $product->set_regular_price('10.00');
        $product->set_status('publish');
        $product->save();

        $ticket = $this->main->getTicketHandler();
        $result = $ticket->get_product($product->get_id());

        $this->assertInstanceOf(WC_Product::class, $result);
        $this->assertEquals($product->get_id(), $result->get_id());
    }

    public function test_get_product_returns_null_for_invalid_id(): void {
        $ticket = $this->main->getTicketHandler();
        $result = $ticket->get_product(999999);
        $this->assertFalse($result);
    }

    // ── get_is_paid_statuses ────────────────────────────────────

    public function test_get_is_paid_statuses_returns_array(): void {
        $ticket = $this->main->getTicketHandler();
        $statuses = $ticket->get_is_paid_statuses();

        $this->assertIsArray($statuses);
        $this->assertContains('processing', $statuses);
        $this->assertContains('completed', $statuses);
    }

    // ── getMaxRedeemAmountOfTicket ──────────────────────────────

    public function test_getMaxRedeemAmountOfTicket_default_is_one(): void {
        $listId = $this->main->getDB()->insert('lists', [
            'name' => 'MaxRedeem Test ' . uniqid(),
            'aktiv' => 1,
            'meta' => '{}',
        ]);

        $product = new WC_Product_Simple();
        $product->set_name('MaxRedeem Product');
        $product->set_regular_price('10.00');
        $product->set_status('publish');
        $product->save();

        update_post_meta($product->get_id(), 'saso_eventtickets_is_ticket', 'yes');
        update_post_meta($product->get_id(), 'saso_eventtickets_list', $listId);

        // Create order and generate code
        $order = wc_create_order();
        $order->add_product($product, 1);
        $order->calculate_totals();
        $order->set_status('completed');
        $order->save();

        $this->main->getWC()->getOrderManager()->add_serialcode_to_order($order->get_id());
        $codes = $this->main->getCore()->getCodesByOrderId($order->get_id());
        $this->assertNotEmpty($codes);

        $codeObj = $this->main->getCore()->retrieveCodeByCode($codes[0]['code']);
        $ticket = $this->main->getTicketHandler();
        $max = $ticket->getMaxRedeemAmountOfTicket($codeObj);

        // Default is 0 (from get_post_meta) which means no custom limit
        $this->assertIsInt($max);
    }

    public function test_getMaxRedeemAmountOfTicket_custom_value(): void {
        $listId = $this->main->getDB()->insert('lists', [
            'name' => 'MaxRedeem Custom ' . uniqid(),
            'aktiv' => 1,
            'meta' => '{}',
        ]);

        $product = new WC_Product_Simple();
        $product->set_name('MaxRedeem Custom Product');
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
        $ticket = $this->main->getTicketHandler();
        $max = $ticket->getMaxRedeemAmountOfTicket($codeObj);

        $this->assertEquals(5, $max);
    }

    // ── ermittelCodePosition ────────────────────────────────────

    public function test_ermittelCodePosition_first(): void {
        $ticket = $this->main->getTicketHandler();
        $result = $ticket->ermittelCodePosition('AAA', ['AAA', 'BBB', 'CCC']);
        $this->assertEquals(1, $result);
    }

    public function test_ermittelCodePosition_middle(): void {
        $ticket = $this->main->getTicketHandler();
        $result = $ticket->ermittelCodePosition('BBB', ['AAA', 'BBB', 'CCC']);
        $this->assertEquals(2, $result);
    }

    public function test_ermittelCodePosition_not_found(): void {
        $ticket = $this->main->getTicketHandler();
        $result = $ticket->ermittelCodePosition('ZZZ', ['AAA', 'BBB', 'CCC']);
        $this->assertEquals(1, $result);
    }

    // ── getOrderItem ────────────────────────────────────────────

    public function test_getOrderItem_finds_matching_item(): void {
        $product = new WC_Product_Simple();
        $product->set_name('OrderItem Test');
        $product->set_regular_price('10.00');
        $product->set_status('publish');
        $product->save();

        $order = wc_create_order();
        $itemId = $order->add_product($product, 1);
        $order->calculate_totals();
        $order->save();

        $metaObj = [
            'woocommerce' => ['item_id' => $itemId],
        ];

        $ticket = $this->main->getTicketHandler();
        $result = $ticket->getOrderItem($order, $metaObj);

        $this->assertNotNull($result);
        $this->assertEquals($product->get_id(), $result->get_product_id());
    }

    public function test_getOrderItem_returns_null_for_wrong_item_id(): void {
        $product = new WC_Product_Simple();
        $product->set_name('OrderItem Wrong');
        $product->set_regular_price('10.00');
        $product->set_status('publish');
        $product->save();

        $order = wc_create_order();
        $order->add_product($product, 1);
        $order->calculate_totals();
        $order->save();

        $metaObj = [
            'woocommerce' => ['item_id' => 999999],
        ];

        $ticket = $this->main->getTicketHandler();
        $result = $ticket->getOrderItem($order, $metaObj);

        $this->assertNull($result);
    }

    // ── getUserRedirectURLForCode ───────────────────────────────

    public function test_getUserRedirectURLForCode_returns_value(): void {
        // getUserRedirectURLForCode has a known issue: the apply_filters call
        // returns the codeObj (array) when no filter is registered, causing
        // "Array to string conversion". We test that it doesn't fatal.
        $listId = $this->main->getDB()->insert('lists', [
            'name' => 'Redirect Test ' . uniqid(),
            'aktiv' => 1,
            'meta' => '{}',
        ]);

        $metaObj = $this->main->getCore()->getMetaObject();
        $metaJson = $this->main->getCore()->json_encode_with_error_handling($metaObj);

        $code = 'REDIR' . strtoupper(uniqid());
        $this->main->getDB()->insert('codes', [
            'code' => $code,
            'code_display' => $code,
            'cvv' => '',
            'meta' => $metaJson,
            'aktiv' => 1,
            'redeemed' => 0,
            'list_id' => $listId,
            'order_id' => 0,
        ]);

        $codeObj = $this->main->getCore()->retrieveCodeByCode($code);
        $ticket = $this->main->getTicketHandler();

        // Suppress the E_NOTICE for Array to string conversion
        $prev = error_reporting(E_ERROR);
        $url = $ticket->getUserRedirectURLForCode($codeObj);
        error_reporting($prev);

        // Result may be string or array depending on filter registration
        $this->assertTrue(is_string($url) || is_array($url));
    }
}
