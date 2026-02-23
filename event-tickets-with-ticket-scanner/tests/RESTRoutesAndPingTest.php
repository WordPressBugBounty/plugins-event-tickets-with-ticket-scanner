<?php
/**
 * Tests for REST API routes registration and static utility methods:
 * setRestRoutesTicket, getRESTPrefixURL, isOrderPaid.
 */

class RESTRoutesAndPingTest extends WP_UnitTestCase {

    private $main;

    public function set_up(): void {
        parent::set_up();
        $this->main = sasoEventtickets::Instance();
    }

    // ── getRESTPrefixURL ─────────────────────────────────────────

    public function test_getRESTPrefixURL_returns_string(): void {
        $result = SASO_EVENTTICKETS::getRESTPrefixURL();
        $this->assertIsString($result);
        $this->assertNotEmpty($result);
    }

    public function test_getRESTPrefixURL_contains_plugin_name(): void {
        $result = SASO_EVENTTICKETS::getRESTPrefixURL();
        $this->assertStringContainsString('event-tickets', $result);
    }

    // ── setRestRoutesTicket ──────────────────────────────────────

    public function test_setRestRoutesTicket_registers_routes(): void {
        // Suppress "REST API routes must be registered on rest_api_init" notice
        $this->setExpectedIncorrectUsage('register_rest_route');

        // Register routes
        SASO_EVENTTICKETS::setRestRoutesTicket();

        // Check that REST server has routes registered
        $server = rest_get_server();
        $routes = $server->get_routes();

        $prefix = SASO_EVENTTICKETS::getRESTPrefixURL();
        $foundPing = false;
        foreach ($routes as $route => $handlers) {
            if (strpos($route, $prefix . '/ticket/scanner/ping') !== false) {
                $foundPing = true;
                break;
            }
        }
        $this->assertTrue($foundPing, 'Ping route should be registered');
    }

    public function test_setRestRoutesTicket_registers_retrieve_ticket(): void {
        $this->setExpectedIncorrectUsage('register_rest_route');
        SASO_EVENTTICKETS::setRestRoutesTicket();

        $server = rest_get_server();
        $routes = $server->get_routes();
        $prefix = SASO_EVENTTICKETS::getRESTPrefixURL();

        $foundRetrieve = false;
        foreach ($routes as $route => $handlers) {
            if (strpos($route, $prefix . '/ticket/scanner/retrieve_ticket') !== false) {
                $foundRetrieve = true;
                break;
            }
        }
        $this->assertTrue($foundRetrieve, 'Retrieve ticket route should be registered');
    }

    public function test_setRestRoutesTicket_registers_redeem_ticket(): void {
        $this->setExpectedIncorrectUsage('register_rest_route');
        SASO_EVENTTICKETS::setRestRoutesTicket();

        $server = rest_get_server();
        $routes = $server->get_routes();
        $prefix = SASO_EVENTTICKETS::getRESTPrefixURL();

        $foundRedeem = false;
        foreach ($routes as $route => $handlers) {
            if (strpos($route, $prefix . '/ticket/scanner/redeem_ticket') !== false) {
                $foundRedeem = true;
                break;
            }
        }
        $this->assertTrue($foundRedeem, 'Redeem ticket route should be registered');
    }

    // ── isOrderPaid ──────────────────────────────────────────────

    public function test_isOrderPaid_true_for_completed(): void {
        if (!class_exists('WC_Product_Simple')) {
            $this->markTestSkipped('WooCommerce not available');
        }

        $order = wc_create_order();
        $order->set_status('completed');
        $order->save();

        $result = SASO_EVENTTICKETS::isOrderPaid($order);
        $this->assertTrue($result);
    }

    public function test_isOrderPaid_true_for_processing(): void {
        if (!class_exists('WC_Product_Simple')) {
            $this->markTestSkipped('WooCommerce not available');
        }

        $order = wc_create_order();
        $order->set_status('processing');
        $order->save();

        $result = SASO_EVENTTICKETS::isOrderPaid($order);
        $this->assertTrue($result);
    }

    public function test_isOrderPaid_false_for_pending(): void {
        if (!class_exists('WC_Product_Simple')) {
            $this->markTestSkipped('WooCommerce not available');
        }

        $order = wc_create_order();
        $order->set_status('pending');
        $order->save();

        $result = SASO_EVENTTICKETS::isOrderPaid($order);
        $this->assertFalse($result);
    }

    public function test_isOrderPaid_false_for_cancelled(): void {
        if (!class_exists('WC_Product_Simple')) {
            $this->markTestSkipped('WooCommerce not available');
        }

        $order = wc_create_order();
        $order->set_status('cancelled');
        $order->save();

        $result = SASO_EVENTTICKETS::isOrderPaid($order);
        $this->assertFalse($result);
    }
}
