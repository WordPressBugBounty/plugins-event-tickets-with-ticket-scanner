<?php
/**
 * Tests for Ticket methods: setRequestURI, cronJobDaily,
 * initFilterAndActions, initFilterAndActionsTicketScanner.
 */

class TicketRoutingTest extends WP_UnitTestCase {

    private $main;

    public function set_up(): void {
        parent::set_up();
        $this->main = sasoEventtickets::Instance();
    }

    // ── setRequestURI ────────────────────────────────────────────

    public function test_setRequestURI_stores_value(): void {
        $handler = $this->main->getTicketHandler();
        $handler->setRequestURI('/test/ticket/path');

        // Use the same handler instance for reflection
        $ref = new ReflectionProperty($handler, 'request_uri');
        $ref->setAccessible(true);
        $result = $ref->getValue($handler);

        $this->assertEquals('/test/ticket/path', $result);
    }

    public function test_setRequestURI_trims_whitespace(): void {
        $handler = $this->main->getTicketHandler();
        $handler->setRequestURI('  /trimmed/path  ');

        $ref = new ReflectionProperty($handler, 'request_uri');
        $ref->setAccessible(true);
        $result = $ref->getValue($handler);

        $this->assertEquals('/trimmed/path', $result);
    }

    // ── cronJobDaily ─────────────────────────────────────────────

    public function test_cronJobDaily_runs_without_error(): void {
        // cronJobDaily should execute without throwing
        $this->main->getTicketHandler()->cronJobDaily();
        $this->assertTrue(true); // If we get here, no exception was thrown
    }

    // ── initFilterAndActions ──────────────────────────────────────

    public function test_initFilterAndActions_registers_hooks(): void {
        // Save current filter state
        $this->main->getTicketHandler()->initFilterAndActions();

        // Check that query_vars filter was added
        $this->assertGreaterThan(0, has_filter('query_vars'));
    }

    public function test_initFilterAndActions_registers_title_filter(): void {
        $this->main->getTicketHandler()->initFilterAndActions();

        $this->assertGreaterThan(0, has_filter('pre_get_document_title'));
    }

    public function test_initFilterAndActions_registers_template_redirect(): void {
        $this->main->getTicketHandler()->initFilterAndActions();

        $this->assertGreaterThan(0, has_action('template_redirect'));
    }

    // ── initFilterAndActionsTicketScanner ──────────────────────────

    public function test_initFilterAndActionsTicketScanner_registers_hooks(): void {
        $this->main->getTicketHandler()->initFilterAndActionsTicketScanner();

        // Scanner should also register template_redirect
        $this->assertGreaterThan(0, has_action('template_redirect'));
    }

    // ── getCalcDateStringAllowedRedeemFromCorrectProduct ──────────

    public function test_getCalcDateStringAllowedRedeemFromCorrectProduct_returns_array(): void {
        if (!class_exists('WC_Product_Simple')) {
            $this->markTestSkipped('WooCommerce not available');
        }

        $product = new WC_Product_Simple();
        $product->set_name('DateCalc Test ' . uniqid());
        $product->set_regular_price('10.00');
        $product->save();

        $result = $this->main->getTicketHandler()->getCalcDateStringAllowedRedeemFromCorrectProduct($product->get_id());
        $this->assertIsArray($result);
        $this->assertArrayHasKey('ticket_start_date', $result);
        $this->assertArrayHasKey('ticket_end_date', $result);
        $this->assertArrayHasKey('redeem_allowed_from', $result);
        $this->assertArrayHasKey('redeem_allowed_until', $result);
    }

    public function test_getCalcDateStringAllowedRedeemFromCorrectProduct_has_timestamps(): void {
        if (!class_exists('WC_Product_Simple')) {
            $this->markTestSkipped('WooCommerce not available');
        }

        $product = new WC_Product_Simple();
        $product->set_name('TimestampCalc Test ' . uniqid());
        $product->set_regular_price('10.00');
        $product->save();

        $result = $this->main->getTicketHandler()->getCalcDateStringAllowedRedeemFromCorrectProduct($product->get_id());
        $this->assertArrayHasKey('ticket_start_date_timestamp', $result);
        $this->assertArrayHasKey('ticket_end_date_timestamp', $result);
        $this->assertArrayHasKey('server_time_timestamp', $result);
        $this->assertIsInt($result['server_time_timestamp']);
    }
}
