<?php
/**
 * Tests for AdminSettings customer/company name methods:
 * getCustomerName, getCompanyName.
 */

class AdminCustomerNameTest extends WP_UnitTestCase {

    private $main;

    public function set_up(): void {
        parent::set_up();
        $this->main = sasoEventtickets::Instance();

        if (!class_exists('WC_Product_Simple')) {
            $this->markTestSkipped('WooCommerce not available');
        }
    }

    // ── getCustomerName ────────────────────────────────────────────

    public function test_getCustomerName_returns_empty_for_zero(): void {
        $result = $this->main->getAdmin()->getCustomerName(0);
        $this->assertEquals('', $result);
    }

    public function test_getCustomerName_returns_string_for_valid_order(): void {
        $order = wc_create_order();
        $order->set_billing_first_name('John');
        $order->set_billing_last_name('Doe');
        $order->save();

        $result = $this->main->getAdmin()->getCustomerName($order->get_id());
        $this->assertIsString($result);
        $this->assertStringContainsString('John', $result);
        $this->assertStringContainsString('Doe', $result);
    }

    public function test_getCustomerName_caches_result(): void {
        $order = wc_create_order();
        $order->set_billing_first_name('Jane');
        $order->set_billing_last_name('Smith');
        $order->save();

        $result1 = $this->main->getAdmin()->getCustomerName($order->get_id());
        $result2 = $this->main->getAdmin()->getCustomerName($order->get_id());
        $this->assertEquals($result1, $result2);
    }

    public function test_getCustomerName_returns_not_found_for_invalid(): void {
        $result = $this->main->getAdmin()->getCustomerName(999999);
        $this->assertIsString($result);
        // Should return 'Order not found' or similar
    }

    // ── getCompanyName ─────────────────────────────────────────────

    public function test_getCompanyName_returns_empty_for_zero(): void {
        $result = $this->main->getAdmin()->getCompanyName(0);
        $this->assertEquals('', $result);
    }

    public function test_getCompanyName_returns_company(): void {
        $order = wc_create_order();
        $order->set_billing_company('Acme Corp');
        $order->save();

        $result = $this->main->getAdmin()->getCompanyName($order->get_id());
        $this->assertEquals('Acme Corp', $result);
    }

    public function test_getCompanyName_returns_empty_for_no_company(): void {
        $order = wc_create_order();
        $order->set_billing_first_name('John');
        $order->save();

        $result = $this->main->getAdmin()->getCompanyName($order->get_id());
        $this->assertEquals('', $result);
    }
}
