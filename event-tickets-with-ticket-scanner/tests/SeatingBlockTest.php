<?php
/**
 * Integration tests for the seating block manager (seat reservations).
 *
 * blockSeat() returns array: ['success' => bool, 'block_id' => int, 'error' => string, ...]
 * releaseBlock() returns bool
 * confirmBlock() returns bool
 */

class SeatingBlockTest extends WP_UnitTestCase {

    private $main;
    private $planManager;
    private $seatManager;
    private $blockManager;

    public function set_up(): void {
        parent::set_up();
        $this->main = sasoEventtickets::Instance();
        $this->planManager = $this->main->getSeating()->getPlanManager();
        $this->seatManager = $this->main->getSeating()->getSeatManager();
        $this->blockManager = $this->main->getSeating()->getBlockManager();

        if (!class_exists('WC_Product_Simple')) {
            $this->markTestSkipped('WooCommerce not available');
        }

        // Clean seating tables to avoid free version limit (1 plan)
        global $wpdb;
        $wpdb->query("DELETE FROM {$wpdb->prefix}saso_eventtickets_seat_blocks");
        $wpdb->query("DELETE FROM {$wpdb->prefix}saso_eventtickets_seats");
        $wpdb->query("DELETE FROM {$wpdb->prefix}saso_eventtickets_seatingplans");
    }

    /**
     * Helper: create a plan with seats and a ticket product.
     */
    private function createPlanWithSeats(int $seatCount = 3): array {
        $planId = $this->planManager->create(['name' => 'Block Test ' . uniqid()]);

        $seatIds = [];
        for ($i = 1; $i <= $seatCount; $i++) {
            $seatIds[] = $this->seatManager->create($planId, ['seat_identifier' => "S{$i}"]);
        }

        // Create a WC product linked to this plan
        $product = new WC_Product_Simple();
        $product->set_name('Seating Product');
        $product->set_regular_price('25.00');
        $product->set_status('publish');
        $product->save();
        $productId = $product->get_id();

        update_post_meta($productId, 'saso_eventtickets_is_ticket', 'yes');
        update_post_meta($productId, 'saso_eventtickets_seatingplan', $planId);

        return [
            'plan_id' => $planId,
            'seat_ids' => $seatIds,
            'product_id' => $productId,
            'product' => $product,
        ];
    }

    // ── getMetaObject ────────────────────────────────────────────

    public function test_block_getMetaObject_returns_array(): void {
        $meta = $this->blockManager->getMetaObject();
        $this->assertIsArray($meta);
    }

    // ── blockSeat ────────────────────────────────────────────────

    public function test_blockSeat_returns_success(): void {
        $setup = $this->createPlanWithSeats();
        $sessionId = 'test_session_' . uniqid();

        $result = $this->blockManager->blockSeat(
            $setup['seat_ids'][0],
            $setup['product_id'],
            $sessionId
        );

        $this->assertIsArray($result);
        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('block_id', $result);
        $this->assertGreaterThan(0, $result['block_id']);
    }

    public function test_blockSeat_makes_seat_unavailable(): void {
        $setup = $this->createPlanWithSeats();
        $sessionId = 'test_session_' . uniqid();

        $this->blockManager->blockSeat(
            $setup['seat_ids'][0],
            $setup['product_id'],
            $sessionId
        );

        $available = $this->blockManager->isSeatAvailable(
            $setup['seat_ids'][0],
            $setup['product_id']
        );
        $this->assertFalse($available);
    }

    public function test_blockSeat_same_seat_twice_fails(): void {
        $setup = $this->createPlanWithSeats();
        $session1 = 'session1_' . uniqid();
        $session2 = 'session2_' . uniqid();

        $result1 = $this->blockManager->blockSeat(
            $setup['seat_ids'][0],
            $setup['product_id'],
            $session1
        );
        $this->assertTrue($result1['success']);

        $result2 = $this->blockManager->blockSeat(
            $setup['seat_ids'][0],
            $setup['product_id'],
            $session2
        );
        $this->assertFalse($result2['success']);
        $this->assertEquals('seat_unavailable', $result2['error']);
    }

    // ── isSeatAvailable ──────────────────────────────────────────

    public function test_isSeatAvailable_returns_true_for_free_seat(): void {
        $setup = $this->createPlanWithSeats();

        $available = $this->blockManager->isSeatAvailable(
            $setup['seat_ids'][0],
            $setup['product_id']
        );
        $this->assertTrue($available);
    }

    public function test_isSeatAvailable_excludes_own_session(): void {
        $setup = $this->createPlanWithSeats();
        $sessionId = 'own_session_' . uniqid();

        $this->blockManager->blockSeat(
            $setup['seat_ids'][0],
            $setup['product_id'],
            $sessionId
        );

        // Should be available when excluding own session
        $available = $this->blockManager->isSeatAvailable(
            $setup['seat_ids'][0],
            $setup['product_id'],
            null,
            $sessionId
        );
        $this->assertTrue($available);
    }

    // ── releaseBlock ─────────────────────────────────────────────

    public function test_releaseBlock_frees_seat(): void {
        $setup = $this->createPlanWithSeats();
        $sessionId = 'release_session_' . uniqid();

        $result = $this->blockManager->blockSeat(
            $setup['seat_ids'][0],
            $setup['product_id'],
            $sessionId
        );
        $blockId = $result['block_id'];

        $released = $this->blockManager->releaseBlock($blockId, $sessionId);
        $this->assertTrue($released);

        $available = $this->blockManager->isSeatAvailable(
            $setup['seat_ids'][0],
            $setup['product_id']
        );
        $this->assertTrue($available);
    }

    public function test_releaseBlock_wrong_session_returns_false(): void {
        $setup = $this->createPlanWithSeats();
        $sessionId = 'correct_session_' . uniqid();

        $result = $this->blockManager->blockSeat(
            $setup['seat_ids'][0],
            $setup['product_id'],
            $sessionId
        );

        $released = $this->blockManager->releaseBlock($result['block_id'], 'wrong_session');
        $this->assertFalse($released);
    }

    // ── releaseAllForSession ─────────────────────────────────────

    public function test_releaseAllForSession(): void {
        $setup = $this->createPlanWithSeats(3);
        $sessionId = 'releaseall_' . uniqid();

        $this->blockManager->blockSeat($setup['seat_ids'][0], $setup['product_id'], $sessionId);
        $this->blockManager->blockSeat($setup['seat_ids'][1], $setup['product_id'], $sessionId);

        $count = $this->blockManager->releaseAllForSession($sessionId);
        $this->assertGreaterThanOrEqual(2, $count);

        $this->assertTrue($this->blockManager->isSeatAvailable($setup['seat_ids'][0], $setup['product_id']));
        $this->assertTrue($this->blockManager->isSeatAvailable($setup['seat_ids'][1], $setup['product_id']));
    }

    // ── getSessionBlocks ─────────────────────────────────────────

    public function test_getSessionBlocks_returns_blocks(): void {
        $setup = $this->createPlanWithSeats(3);
        $sessionId = 'getblocks_' . uniqid();

        $this->blockManager->blockSeat($setup['seat_ids'][0], $setup['product_id'], $sessionId);
        $this->blockManager->blockSeat($setup['seat_ids'][1], $setup['product_id'], $sessionId);

        $blocks = $this->blockManager->getSessionBlocks($sessionId);
        $this->assertCount(2, $blocks);
    }

    public function test_getSessionBlocks_filtered_by_product(): void {
        $setup = $this->createPlanWithSeats(2);
        $sessionId = 'prodfilter_' . uniqid();

        $this->blockManager->blockSeat($setup['seat_ids'][0], $setup['product_id'], $sessionId);

        $blocks = $this->blockManager->getSessionBlocks($sessionId, $setup['product_id']);
        $this->assertCount(1, $blocks);

        $blocks = $this->blockManager->getSessionBlocks($sessionId, 999999);
        $this->assertCount(0, $blocks);
    }

    // ── confirmBlock ─────────────────────────────────────────────

    public function test_confirmBlock_marks_as_confirmed(): void {
        $setup = $this->createPlanWithSeats();
        $sessionId = 'confirm_' . uniqid();

        $result = $this->blockManager->blockSeat(
            $setup['seat_ids'][0],
            $setup['product_id'],
            $sessionId
        );
        $blockId = $result['block_id'];

        $confirmed = $this->blockManager->confirmBlock($blockId, 1, 1, 1);
        $this->assertTrue($confirmed);

        // Seat should still be unavailable after confirmation
        $available = $this->blockManager->isSeatAvailable(
            $setup['seat_ids'][0],
            $setup['product_id']
        );
        $this->assertFalse($available);
    }

    // ── getAvailableSeatIds / getBlockedSeatIds ──────────────────

    public function test_getAvailableSeatIds(): void {
        $setup = $this->createPlanWithSeats(3);
        $sessionId = 'avail_' . uniqid();

        $this->blockManager->blockSeat($setup['seat_ids'][0], $setup['product_id'], $sessionId);

        $available = $this->blockManager->getAvailableSeatIds(
            $setup['plan_id'],
            $setup['product_id']
        );
        $this->assertCount(2, $available);
    }

    public function test_getBlockedSeatIds(): void {
        $setup = $this->createPlanWithSeats(3);
        $sessionId = 'blocked_' . uniqid();

        $this->blockManager->blockSeat($setup['seat_ids'][0], $setup['product_id'], $sessionId);

        $blocked = $this->blockManager->getBlockedSeatIds(
            $setup['plan_id'],
            $setup['product_id']
        );
        $this->assertCount(1, $blocked);
    }

    // ── Counts ───────────────────────────────────────────────────

    public function test_getBlockedCount(): void {
        $setup = $this->createPlanWithSeats(3);
        $sessionId = 'count_' . uniqid();

        $this->blockManager->blockSeat($setup['seat_ids'][0], $setup['product_id'], $sessionId);

        $count = $this->blockManager->getBlockedCount($setup['plan_id']);
        $this->assertGreaterThanOrEqual(1, $count);
    }

    // ── cleanExpiredBlocks ───────────────────────────────────────

    public function test_cleanExpiredBlocks_returns_count(): void {
        $count = $this->blockManager->cleanExpiredBlocks(100);
        $this->assertIsInt($count);
    }

    // ── deleteByPlanId / deleteBySeatId ──────────────────────────

    public function test_deleteByPlanId_removes_blocks(): void {
        $setup = $this->createPlanWithSeats(2);
        $sessionId = 'delplan_' . uniqid();

        $this->blockManager->blockSeat($setup['seat_ids'][0], $setup['product_id'], $sessionId);
        $this->blockManager->deleteByPlanId($setup['plan_id']);

        $blocks = $this->blockManager->getSessionBlocks($sessionId);
        $this->assertCount(0, $blocks);
    }

    public function test_deleteBySeatId_removes_blocks(): void {
        $setup = $this->createPlanWithSeats(2);
        $sessionId = 'delseat_' . uniqid();

        $this->blockManager->blockSeat($setup['seat_ids'][0], $setup['product_id'], $sessionId);
        $this->blockManager->deleteBySeatId($setup['seat_ids'][0]);

        $blocks = $this->blockManager->getSessionBlocks($sessionId);
        $this->assertCount(0, $blocks);
    }

    // ── getSeatsWithStatus ───────────────────────────────────────

    public function test_getSeatsWithStatus_returns_all_seats(): void {
        $setup = $this->createPlanWithSeats(3);
        $sessionId = 'status_' . uniqid();

        $this->blockManager->blockSeat($setup['seat_ids'][0], $setup['product_id'], $sessionId);

        $seats = $this->blockManager->getSeatsWithStatus(
            $setup['plan_id'],
            $setup['product_id']
        );
        $this->assertIsArray($seats);
        $this->assertCount(3, $seats);
    }

    // ── Event date support ───────────────────────────────────────

    public function test_blockSeat_with_event_date(): void {
        $setup = $this->createPlanWithSeats();
        $sessionId = 'date_' . uniqid();
        $eventDate = '2026-12-25';

        $result = $this->blockManager->blockSeat(
            $setup['seat_ids'][0],
            $setup['product_id'],
            $sessionId,
            $eventDate
        );
        $this->assertTrue($result['success']);

        // Same seat but different date should be available
        $available = $this->blockManager->isSeatAvailable(
            $setup['seat_ids'][0],
            $setup['product_id'],
            '2026-12-26'
        );
        $this->assertTrue($available);

        // Same date should not be available
        $available = $this->blockManager->isSeatAvailable(
            $setup['seat_ids'][0],
            $setup['product_id'],
            $eventDate
        );
        $this->assertFalse($available);
    }
}
