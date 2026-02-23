<?php
/**
 * Tests for cronjob handling and scheduled tasks.
 */

class CronjobTest extends WP_UnitTestCase {

    private $main;

    public function set_up(): void {
        parent::set_up();
        $this->main = sasoEventtickets::Instance();
    }

    // ── Cronjob scheduling ───────────────────────────────────────

    public function test_cronjob_daily_activate_schedules_event(): void {
        // Clear any existing schedule
        wp_clear_scheduled_hook('sasoEventtickets_cronjob_daily');

        $this->main->cronjob_daily_activate();

        $next = wp_next_scheduled('sasoEventtickets_cronjob_daily', []);
        $this->assertNotFalse($next, 'Daily cronjob should be scheduled');
        $this->assertIsInt($next);
    }

    public function test_cronjob_daily_activate_is_idempotent(): void {
        wp_clear_scheduled_hook('sasoEventtickets_cronjob_daily');

        $this->main->cronjob_daily_activate();
        $first = wp_next_scheduled('sasoEventtickets_cronjob_daily', []);

        $this->main->cronjob_daily_activate();
        $second = wp_next_scheduled('sasoEventtickets_cronjob_daily', []);

        // Should be the same timestamp (not re-scheduled)
        $this->assertEquals($first, $second);
    }

    // ── cronJobDaily execution ───────────────────────────────────

    public function test_cronJobDaily_runs_without_error(): void {
        $ticket = $this->main->getTicketHandler();
        // Should not throw
        $ticket->cronJobDaily();
        $this->assertTrue(true);
    }

    // ── hideAllTicketProductsWithExpiredEndDate ───────────────────

    public function test_expired_product_gets_hidden(): void {
        if (!class_exists('WC_Product_Simple')) {
            $this->markTestSkipped('WooCommerce not available');
        }

        // Create a product with an expired end date
        $product = new WC_Product_Simple();
        $product->set_name('Expired Event');
        $product->set_regular_price('10.00');
        $product->set_status('publish');
        $product->save();
        $pid = $product->get_id();

        $listId = $this->main->getDB()->insert('lists', [
            'name' => 'Cron Expire ' . uniqid(),
            'aktiv' => 1,
            'meta' => '{}',
        ]);

        update_post_meta($pid, 'saso_eventtickets_is_ticket', 'yes');
        update_post_meta($pid, 'saso_eventtickets_list', $listId);
        update_post_meta($pid, 'saso_eventtickets_ticket_end_date', '2020-01-01');

        // Enable the auto-hide option (correct key: wcTicketHideTicketAfterEventEnd)
        $this->main->getOptions()->changeOption([
            'key' => 'wcTicketHideTicketAfterEventEnd',
            'value' => 1,
        ]);

        // Run the cron job
        $this->main->getTicketHandler()->cronJobDaily();

        // Product should now be set to 'private'
        $post = get_post($pid);
        $this->assertEquals('private', $post->post_status, 'Expired product should be set to private');
    }

    public function test_future_product_stays_visible(): void {
        if (!class_exists('WC_Product_Simple')) {
            $this->markTestSkipped('WooCommerce not available');
        }

        $product = new WC_Product_Simple();
        $product->set_name('Future Event');
        $product->set_regular_price('10.00');
        $product->set_status('publish');
        $product->save();
        $pid = $product->get_id();

        $listId = $this->main->getDB()->insert('lists', [
            'name' => 'Cron Future ' . uniqid(),
            'aktiv' => 1,
            'meta' => '{}',
        ]);

        update_post_meta($pid, 'saso_eventtickets_is_ticket', 'yes');
        update_post_meta($pid, 'saso_eventtickets_list', $listId);
        update_post_meta($pid, 'saso_eventtickets_ticket_end_date', '2030-12-31');

        $this->main->getOptions()->changeOption([
            'key' => 'wcTicketHideTicketAfterEventEnd',
            'value' => 1,
        ]);

        $this->main->getTicketHandler()->cronJobDaily();

        $post = get_post($pid);
        $this->assertEquals('publish', $post->post_status);
    }
}
