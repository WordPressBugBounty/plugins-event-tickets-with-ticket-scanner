<?php
/**
 * Integration tests for seating plan and seat CRUD operations.
 */

class SeatingPlanTest extends WP_UnitTestCase {

    private $main;
    private $planManager;
    private $seatManager;

    public function set_up(): void {
        parent::set_up();
        $this->main = sasoEventtickets::Instance();
        $this->planManager = $this->main->getSeating()->getPlanManager();
        $this->seatManager = $this->main->getSeating()->getSeatManager();

        // Clean seating tables to avoid free version limit (1 plan)
        global $wpdb;
        $wpdb->query("DELETE FROM {$wpdb->prefix}saso_eventtickets_seat_blocks");
        $wpdb->query("DELETE FROM {$wpdb->prefix}saso_eventtickets_seats");
        $wpdb->query("DELETE FROM {$wpdb->prefix}saso_eventtickets_seatingplans");
    }

    // ── Plan Meta Object ─────────────────────────────────────────

    public function test_plan_getMetaObject_returns_array(): void {
        $meta = $this->planManager->getMetaObject();
        $this->assertIsArray($meta);
    }

    public function test_seat_getMetaObject_returns_array(): void {
        $meta = $this->seatManager->getMetaObject();
        $this->assertIsArray($meta);
    }

    // ── Plan CRUD ────────────────────────────────────────────────

    public function test_create_plan(): void {
        $id = $this->planManager->create([
            'name' => 'Test Plan ' . uniqid(),
        ]);
        $this->assertIsInt($id);
        $this->assertGreaterThan(0, $id);
    }

    public function test_getById_returns_plan(): void {
        $name = 'GetById Plan ' . uniqid();
        $id = $this->planManager->create(['name' => $name]);

        $plan = $this->planManager->getById($id);
        $this->assertIsArray($plan);
        $this->assertEquals($id, $plan['id']);
        $this->assertEquals($name, $plan['name']);
    }

    public function test_getById_nonexistent_returns_null(): void {
        $result = $this->planManager->getById(999999);
        $this->assertNull($result);
    }

    public function test_update_plan_name(): void {
        $id = $this->planManager->create(['name' => 'Original ' . uniqid()]);
        $newName = 'Updated ' . uniqid();

        $this->planManager->update($id, ['name' => $newName]);
        $plan = $this->planManager->getById($id);
        $this->assertEquals($newName, $plan['name']);
    }

    public function test_delete_plan(): void {
        $id = $this->planManager->create(['name' => 'Delete Me ' . uniqid()]);
        $this->planManager->delete($id, true);

        $result = $this->planManager->getById($id);
        $this->assertNull($result);
    }

    public function test_getAll_returns_array(): void {
        $this->planManager->create(['name' => 'All Test ' . uniqid()]);
        $all = $this->planManager->getAll();
        $this->assertIsArray($all);
        $this->assertNotEmpty($all);
    }

    public function test_getCount_increases_after_create(): void {
        $countBefore = $this->planManager->getCount();
        $this->planManager->create(['name' => 'Count Test ' . uniqid()]);
        $countAfter = $this->planManager->getCount();
        $this->assertEquals($countBefore + 1, $countAfter);
    }

    // ── nameExists ───────────────────────────────────────────────

    public function test_nameExists_returns_true_for_existing(): void {
        $name = 'Unique Name ' . uniqid();
        $this->planManager->create(['name' => $name]);
        $this->assertTrue($this->planManager->nameExists($name));
    }

    public function test_nameExists_returns_false_for_nonexistent(): void {
        $this->assertFalse($this->planManager->nameExists('NonExistent_' . uniqid()));
    }

    public function test_nameExists_excludes_own_id(): void {
        $name = 'Exclude Self ' . uniqid();
        $id = $this->planManager->create(['name' => $name]);
        // Should not find itself when excluded
        $this->assertFalse($this->planManager->nameExists($name, $id));
    }

    // ── clonePlan ────────────────────────────────────────────────

    public function test_clonePlan_hits_limit_in_free_version(): void {
        // Free version limit is 1 plan. Clone tries to create a 2nd plan.
        $sourceId = $this->planManager->create(['name' => 'Clone Source ' . uniqid()]);
        $this->seatManager->create($sourceId, ['seat_identifier' => 'A1']);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Limit reached');
        $this->planManager->clonePlan($sourceId);
    }

    // ── Seat CRUD ────────────────────────────────────────────────

    public function test_create_seat(): void {
        $planId = $this->planManager->create(['name' => 'Seat Plan ' . uniqid()]);
        $seatId = $this->seatManager->create($planId, ['seat_identifier' => 'A1']);

        $this->assertIsInt($seatId);
        $this->assertGreaterThan(0, $seatId);
    }

    public function test_getById_seat(): void {
        $planId = $this->planManager->create(['name' => 'Seat GetById ' . uniqid()]);
        $seatId = $this->seatManager->create($planId, ['seat_identifier' => 'B2']);

        $seat = $this->seatManager->getById($seatId);
        $this->assertIsArray($seat);
        $this->assertEquals($seatId, $seat['id']);
        $this->assertEquals('B2', $seat['seat_identifier']);
    }

    public function test_getByPlanId_returns_seats(): void {
        $planId = $this->planManager->create(['name' => 'Seats ByPlan ' . uniqid()]);
        $this->seatManager->create($planId, ['seat_identifier' => 'C1']);
        $this->seatManager->create($planId, ['seat_identifier' => 'C2']);
        $this->seatManager->create($planId, ['seat_identifier' => 'C3']);

        $seats = $this->seatManager->getByPlanId($planId);
        $this->assertCount(3, $seats);
    }

    public function test_createBulk_seats(): void {
        $planId = $this->planManager->create(['name' => 'Bulk Seats ' . uniqid()]);
        // createBulk supports string (legacy) and object format with 'identifier' key
        $result = $this->seatManager->createBulk($planId, ['D1', 'D2', 'D3', 'D4']);

        $this->assertIsArray($result);
        $seats = $this->seatManager->getByPlanId($planId);
        $this->assertCount(4, $seats);
    }

    public function test_delete_seat(): void {
        $planId = $this->planManager->create(['name' => 'Del Seat ' . uniqid()]);
        $seatId = $this->seatManager->create($planId, ['seat_identifier' => 'E1']);

        $this->seatManager->delete($seatId, true);
        $seats = $this->seatManager->getByPlanId($planId);
        $this->assertCount(0, $seats);
    }

    public function test_deleteByPlanId_removes_all_seats(): void {
        $planId = $this->planManager->create(['name' => 'Del All Seats ' . uniqid()]);
        $this->seatManager->create($planId, ['seat_identifier' => 'F1']);
        $this->seatManager->create($planId, ['seat_identifier' => 'F2']);

        $this->seatManager->deleteByPlanId($planId);
        $seats = $this->seatManager->getByPlanId($planId);
        $this->assertCount(0, $seats);
    }

    // ── identifierExistsInPlan ───────────────────────────────────

    public function test_identifierExistsInPlan_returns_true(): void {
        $planId = $this->planManager->create(['name' => 'Ident Exists ' . uniqid()]);
        $this->seatManager->create($planId, ['seat_identifier' => 'G1']);

        $this->assertTrue($this->seatManager->identifierExistsInPlan($planId, 'G1'));
    }

    public function test_identifierExistsInPlan_returns_false(): void {
        $planId = $this->planManager->create(['name' => 'Ident NotExist ' . uniqid()]);
        $this->assertFalse($this->seatManager->identifierExistsInPlan($planId, 'ZZZ'));
    }

    public function test_identifierExistsInPlan_excludes_own_id(): void {
        $planId = $this->planManager->create(['name' => 'Ident Exclude ' . uniqid()]);
        $seatId = $this->seatManager->create($planId, ['seat_identifier' => 'H1']);

        // Should return false when excluding own ID
        $this->assertFalse($this->seatManager->identifierExistsInPlan($planId, 'H1', $seatId));
    }

    // ── getByIdentifier ──────────────────────────────────────────

    public function test_getByIdentifier_returns_seat(): void {
        $planId = $this->planManager->create(['name' => 'ByIdent ' . uniqid()]);
        $seatId = $this->seatManager->create($planId, ['seat_identifier' => 'J1']);

        $seat = $this->seatManager->getByIdentifier($planId, 'J1');
        $this->assertIsArray($seat);
        $this->assertEquals($seatId, $seat['id']);
    }

    // ── getCountForPlan ──────────────────────────────────────────

    public function test_getCountForPlan(): void {
        $planId = $this->planManager->create(['name' => 'Count Seats ' . uniqid()]);
        $this->seatManager->create($planId, ['seat_identifier' => 'K1']);
        $this->seatManager->create($planId, ['seat_identifier' => 'K2']);

        $count = $this->seatManager->getCountForPlan($planId);
        $this->assertEquals(2, $count);
    }

    // ── softDelete and restore ───────────────────────────────────

    public function test_softDelete_and_restore(): void {
        $planId = $this->planManager->create(['name' => 'SoftDel ' . uniqid()]);
        $seatId = $this->seatManager->create($planId, ['seat_identifier' => 'L1']);

        // Soft delete
        $this->seatManager->softDelete($seatId);

        // Active-only query should not find it
        $seats = $this->seatManager->getByPlanId($planId, true);
        $this->assertCount(0, $seats);

        // Restore
        $this->seatManager->restore($seatId);
        $seats = $this->seatManager->getByPlanId($planId, true);
        $this->assertCount(1, $seats);
    }

    // ── getSeatingPlanIdForSeatId ────────────────────────────────

    public function test_getSeatingPlanIdForSeatId(): void {
        $planId = $this->planManager->create(['name' => 'PlanForSeat ' . uniqid()]);
        $seatId = $this->seatManager->create($planId, ['seat_identifier' => 'M1']);

        $foundPlanId = $this->seatManager->getSeatingPlanIdForSeatId($seatId);
        $this->assertEquals($planId, $foundPlanId);
    }
}
