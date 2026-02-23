<?php
/**
 * Tests for Core methods: getOrderTicketsURL, getRealIpAddr.
 */

class CoreOrderAndIPTest extends WP_UnitTestCase {

    private $main;

    public function set_up(): void {
        parent::set_up();
        $this->main = sasoEventtickets::Instance();
    }

    // ── getRealIpAddr ────────────────────────────────────────────

    public function test_getRealIpAddr_returns_string(): void {
        $ip = $this->main->getCore()->getRealIpAddr();
        $this->assertIsString($ip);
    }

    public function test_getRealIpAddr_reads_remote_addr(): void {
        $original = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : null;
        $_SERVER['REMOTE_ADDR'] = '192.168.1.100';
        unset($_SERVER['HTTP_CLIENT_IP'], $_SERVER['HTTP_X_FORWARDED_FOR']);

        $ip = $this->main->getCore()->getRealIpAddr();
        $this->assertEquals('192.168.1.100', $ip);

        if ($original !== null) {
            $_SERVER['REMOTE_ADDR'] = $original;
        }
    }

    public function test_getRealIpAddr_prefers_client_ip(): void {
        $original_remote = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : null;
        $_SERVER['REMOTE_ADDR'] = '10.0.0.1';
        $_SERVER['HTTP_CLIENT_IP'] = '203.0.113.5';

        $ip = $this->main->getCore()->getRealIpAddr();
        $this->assertEquals('203.0.113.5', $ip);

        unset($_SERVER['HTTP_CLIENT_IP']);
        if ($original_remote !== null) {
            $_SERVER['REMOTE_ADDR'] = $original_remote;
        }
    }

    public function test_getRealIpAddr_uses_forwarded_for(): void {
        $original_remote = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : null;
        $_SERVER['REMOTE_ADDR'] = '10.0.0.1';
        unset($_SERVER['HTTP_CLIENT_IP']);
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.50';

        $ip = $this->main->getCore()->getRealIpAddr();
        $this->assertEquals('198.51.100.50', $ip);

        unset($_SERVER['HTTP_X_FORWARDED_FOR']);
        if ($original_remote !== null) {
            $_SERVER['REMOTE_ADDR'] = $original_remote;
        }
    }

    // ── getOrderTicketsURL ───────────────────────────────────────

    public function test_getOrderTicketsURL_contains_ticket_path(): void {
        if (!class_exists('WC_Product_Simple')) {
            $this->markTestSkipped('WooCommerce not available');
        }

        $order = wc_create_order();
        $order->save();

        $url = $this->main->getCore()->getOrderTicketsURL($order);
        $this->assertIsString($url);
        $this->assertStringContainsString('ticket', $url);
        $this->assertStringContainsString('order-', $url);
    }

    public function test_getOrderTicketsURL_contains_order_id(): void {
        if (!class_exists('WC_Product_Simple')) {
            $this->markTestSkipped('WooCommerce not available');
        }

        $order = wc_create_order();
        $order->save();

        $url = $this->main->getCore()->getOrderTicketsURL($order);
        $this->assertStringContainsString((string) $order->get_id(), $url);
    }

    public function test_getOrderTicketsURL_custom_prefix(): void {
        if (!class_exists('WC_Product_Simple')) {
            $this->markTestSkipped('WooCommerce not available');
        }

        $order = wc_create_order();
        $order->save();

        $url = $this->main->getCore()->getOrderTicketsURL($order, 'myprefix-');
        $this->assertStringContainsString('myprefix-', $url);
    }

    public function test_getOrderTicketsURL_null_order_throws(): void {
        $this->expectException(Exception::class);
        $this->main->getCore()->getOrderTicketsURL(null);
    }
}
