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
        // Free version limit is 32 — very high count should exceed (return false)
        $result = $this->main->getBase()->_isMaxReachedForTickets(999999);
        $this->assertFalse($result, 'Very high ticket count should exceed free limit');
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
