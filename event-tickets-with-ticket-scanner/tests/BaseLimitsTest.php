<?php
/**
 * Tests for Base methods: getMaxValue, getMaxValues, getOverallTicketCounterValue,
 * increaseGlobalTicketCounter, _isMaxReachedForList, _isMaxReachedForTickets,
 * _isMaxReachedForAuthtokens.
 */

class BaseLimitsTest extends WP_UnitTestCase {

    private $main;

    public function set_up(): void {
        parent::set_up();
        $this->main = sasoEventtickets::Instance();
    }

    // ── getMaxValues ─────────────────────────────────────────────

    public function test_getMaxValues_returns_array(): void {
        $values = $this->main->getBase()->getMaxValues();
        $this->assertIsArray($values);
    }

    // ── getMaxValue ──────────────────────────────────────────────

    public function test_getMaxValue_returns_int_for_known_key(): void {
        $val = $this->main->getBase()->getMaxValue('codes_total');
        $this->assertIsInt($val);
    }

    public function test_getMaxValue_returns_default_for_unknown_key(): void {
        $val = $this->main->getBase()->getMaxValue('nonexistent_key_xyz', 99);
        $this->assertEquals(99, $val);
    }

    // ── getOverallTicketCounterValue ─────────────────────────────

    public function test_getOverallTicketCounterValue_returns_int(): void {
        $val = $this->main->getBase()->getOverallTicketCounterValue();
        $this->assertIsInt($val);
        $this->assertGreaterThanOrEqual(0, $val);
    }

    // ── increaseGlobalTicketCounter ──────────────────────────────

    public function test_increaseGlobalTicketCounter_increments(): void {
        $before = $this->main->getBase()->getOverallTicketCounterValue();
        $this->main->getBase()->increaseGlobalTicketCounter(1);
        $after = $this->main->getBase()->getOverallTicketCounterValue();

        $this->assertEquals($before + 1, $after);
    }

    public function test_increaseGlobalTicketCounter_by_custom_amount(): void {
        $before = $this->main->getBase()->getOverallTicketCounterValue();
        $this->main->getBase()->increaseGlobalTicketCounter(5);
        $after = $this->main->getBase()->getOverallTicketCounterValue();

        $this->assertEquals($before + 5, $after);
    }

    // ── _isMaxReachedForList ─────────────────────────────────────

    public function test_isMaxReachedForList_returns_bool(): void {
        $result = $this->main->getBase()->_isMaxReachedForList(0);
        $this->assertIsBool($result);
    }

    public function test_isMaxReachedForList_low_count_not_exceeded(): void {
        // With 0 lists, should not be exceeded
        $result = $this->main->getBase()->_isMaxReachedForList(0);
        $this->assertTrue($result, 'Zero lists should not exceed limit');
    }

    // ── _isMaxReachedForTickets ──────────────────────────────────

    public function test_isMaxReachedForTickets_returns_bool(): void {
        $result = $this->main->getBase()->_isMaxReachedForTickets(0);
        $this->assertIsBool($result);
    }

    public function test_isMaxReachedForTickets_low_count_not_exceeded(): void {
        $result = $this->main->getBase()->_isMaxReachedForTickets(0);
        $this->assertTrue($result, 'Zero tickets should not exceed limit');
    }

    public function test_isMaxReachedForTickets_very_high_count(): void {
        // Free version limit is 50 — very high count should exceed (return false)
        $result = $this->main->getBase()->_isMaxReachedForTickets(999999);
        $this->assertFalse($result, 'Very high ticket count should exceed free limit');
    }

    // ── Counter brake (delete+recreate prevention) ───────────────

    public function test_counter_brake_allows_when_counter_is_zero(): void {
        update_option($this->main->getPrefix() . 'mvct', 0);
        $result = $this->main->getBase()->_isMaxReachedForTickets(5);
        $this->assertTrue($result, 'Counter=0 should not block');
    }

    public function test_counter_brake_allows_within_grace(): void {
        // Counter 200, current total 100 → difference 100 < 150 grace → allowed
        update_option($this->main->getPrefix() . 'mvct', 200);
        $result = $this->main->getBase()->_isMaxReachedForTickets(100);
        // Note: total 100 > codes_total(50) → already blocked by first check
        // Test with total within limit
        update_option($this->main->getPrefix() . 'mvct', 60);
        $result = $this->main->getBase()->_isMaxReachedForTickets(10);
        $this->assertTrue($result, 'Counter within grace period should allow');
    }

    public function test_counter_brake_blocks_when_exceeding_grace(): void {
        // Counter 300, current total 10 → 300 > (10+150)=160 → blocked
        update_option($this->main->getPrefix() . 'mvct', 300);
        $result = $this->main->getBase()->_isMaxReachedForTickets(10);
        $this->assertFalse($result, 'Counter far exceeding total+grace should block');
    }

    public function test_counter_brake_does_not_block_premium(): void {
        // Premium has codes_total=0 → first check returns true (unlimited), counter never reached
        // Simulate by passing total=0 with codes_total=0 logic
        $maxVal = $this->main->getBase()->getMaxValue('codes_total');
        if ($maxVal === 0) {
            // Premium: should always return true regardless of counter
            update_option($this->main->getPrefix() . 'mvct', 999999);
            $result = $this->main->getBase()->_isMaxReachedForTickets(0);
            $this->assertTrue($result);
        } else {
            // Free version in test env: codes_total > 0, skip this test
            $this->markTestSkipped('Only relevant for premium (codes_total=0)');
        }
    }

    public function test_counter_brake_boundary_at_grace_limit(): void {
        // Counter exactly at total+150 → NOT exceeded (needs to be greater than)
        update_option($this->main->getPrefix() . 'mvct', 160);
        $result = $this->main->getBase()->_isMaxReachedForTickets(10);
        $this->assertTrue($result, 'Counter exactly at grace boundary should still allow');

        // Counter one above → blocked
        update_option($this->main->getPrefix() . 'mvct', 161);
        $result = $this->main->getBase()->_isMaxReachedForTickets(10);
        $this->assertFalse($result, 'Counter one above grace boundary should block');
    }

    // ── _isMaxReachedForAuthtokens ───────────────────────────────

    public function test_isMaxReachedForAuthtokens_returns_bool(): void {
        $result = $this->main->getBase()->_isMaxReachedForAuthtokens(0);
        $this->assertIsBool($result);
    }

    public function test_isMaxReachedForAuthtokens_low_count(): void {
        $result = $this->main->getBase()->_isMaxReachedForAuthtokens(0);
        $this->assertTrue($result, 'Zero authtokens should not exceed limit');
    }
}
