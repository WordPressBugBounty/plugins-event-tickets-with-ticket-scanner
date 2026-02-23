<?php
/**
 * Tests for Seating metadata methods: getMetaPrefix, getMetaPrefixPrivate,
 * getMetaProductSeatingplan, getMetaProductSeatingRequired, getStats.
 */

class SeatingMetaTest extends WP_UnitTestCase {

    private $main;

    public function set_up(): void {
        parent::set_up();
        $this->main = sasoEventtickets::Instance();
    }

    // ── getMetaPrefix ────────────────────────────────────────────

    public function test_getMetaPrefix_returns_string(): void {
        $prefix = $this->main->getSeating()->getMetaPrefix();
        $this->assertIsString($prefix);
        $this->assertNotEmpty($prefix);
    }

    public function test_getMetaPrefix_contains_plugin_name(): void {
        $prefix = $this->main->getSeating()->getMetaPrefix();
        $this->assertStringContainsString('sasoEventtickets', $prefix);
    }

    public function test_getMetaPrefix_ends_with_underscore(): void {
        $prefix = $this->main->getSeating()->getMetaPrefix();
        $this->assertStringEndsWith('_', $prefix);
    }

    // ── getMetaPrefixPrivate ─────────────────────────────────────

    public function test_getMetaPrefixPrivate_starts_with_underscore(): void {
        $prefix = $this->main->getSeating()->getMetaPrefixPrivate();
        $this->assertStringStartsWith('_', $prefix);
    }

    public function test_getMetaPrefixPrivate_contains_public_prefix(): void {
        $public = $this->main->getSeating()->getMetaPrefix();
        $private = $this->main->getSeating()->getMetaPrefixPrivate();
        $this->assertStringContainsString($public, $private);
    }

    // ── getMetaProductSeatingplan ─────────────────────────────────

    public function test_getMetaProductSeatingplan_returns_string(): void {
        $key = $this->main->getSeating()->getMetaProductSeatingplan();
        $this->assertIsString($key);
        $this->assertStringContainsString('seatingplan', $key);
    }

    // ── getMetaProductSeatingRequired ─────────────────────────────

    public function test_getMetaProductSeatingRequired_returns_string(): void {
        $key = $this->main->getSeating()->getMetaProductSeatingRequired();
        $this->assertIsString($key);
        $this->assertStringContainsString('seating_required', $key);
    }

    // ── getStats ─────────────────────────────────────────────────

    public function test_getStats_returns_array_with_expected_keys(): void {
        // Create a seating plan via direct DB insert
        $planId = $this->main->getDB()->insert('seatingplans', [
            'name' => 'Stats Test Plan ' . uniqid(),
            'aktiv' => 1,
            'meta' => '{}',
        ]);

        $stats = $this->main->getSeating()->getStats($planId);
        $this->assertIsArray($stats);
        $this->assertArrayHasKey('total_seats', $stats);
        $this->assertArrayHasKey('blocked', $stats);
        $this->assertArrayHasKey('confirmed', $stats);
        $this->assertArrayHasKey('available', $stats);
    }

    public function test_getStats_empty_plan_all_zeros(): void {
        $planId = $this->main->getDB()->insert('seatingplans', [
            'name' => 'Empty Stats Plan ' . uniqid(),
            'aktiv' => 1,
            'meta' => '{}',
        ]);

        $stats = $this->main->getSeating()->getStats($planId);
        $this->assertEquals(0, $stats['total_seats']);
        $this->assertEquals(0, $stats['blocked']);
        $this->assertEquals(0, $stats['confirmed']);
        $this->assertEquals(0, $stats['available']);
    }
}
