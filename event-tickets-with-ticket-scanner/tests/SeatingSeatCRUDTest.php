<?php
/**
 * Batch 40 — Seating Seat CRUD operations:
 * - create: single seat with validation, limits, uniqueness
 * - createBulk: multiple seats (string + object format)
 * - getById / getByPlanId / getByIdentifier: retrieval methods
 * - update: single + multi-seat update with meta merge
 * - softDelete / restore: soft-delete lifecycle
 * - delete / deleteByPlanId: hard-delete with block cleanup
 * - getCountForPlan / identifierExistsInPlan: utility queries
 * - getDropdownOptions / updatePosition / upgradeToVisual
 * - getAuditInfo / getSeatingPlanIdForSeatId / prepareSeatMeta
 */

class SeatingSeatCRUDTest extends WP_UnitTestCase {

	private $main;
	private $seatMgr;
	private $planMgr;

	public function set_up(): void {
		parent::set_up();
		$this->main = sasoEventtickets::Instance();
		$this->seatMgr = $this->main->getSeating()->getSeatManager();
		$this->planMgr = $this->main->getSeating()->getPlanManager();

		// Clean seating tables
		global $wpdb;
		$wpdb->query("DELETE FROM {$wpdb->prefix}saso_eventtickets_seat_blocks");
		$wpdb->query("DELETE FROM {$wpdb->prefix}saso_eventtickets_seats");
		$wpdb->query("DELETE FROM {$wpdb->prefix}saso_eventtickets_seatingplans");
	}

	private function createPlan(string $suffix = ''): int {
		return $this->planMgr->create(['name' => 'SeatCRUD Plan ' . uniqid() . $suffix]);
	}

	// ── create ───────────────────────────────────────────────

	public function test_create_returns_seat_id(): void {
		$planId = $this->createPlan();
		$seatId = $this->seatMgr->create($planId, ['seat_identifier' => 'A1']);
		$this->assertIsInt($seatId);
		$this->assertGreaterThan(0, $seatId);
	}

	public function test_create_stores_identifier(): void {
		$planId = $this->createPlan();
		$seatId = $this->seatMgr->create($planId, ['seat_identifier' => 'B2']);
		$seat = $this->seatMgr->getById($seatId);
		$this->assertEquals('B2', $seat['seat_identifier']);
	}

	public function test_create_sets_default_meta(): void {
		$planId = $this->createPlan();
		$seatId = $this->seatMgr->create($planId, ['seat_identifier' => 'C3']);
		$seat = $this->seatMgr->getById($seatId);
		$this->assertIsArray($seat['meta']);
		$this->assertArrayHasKey('seat_label', $seat['meta']);
		$this->assertEquals('C3', $seat['meta']['seat_label']); // fallback to identifier
	}

	public function test_create_with_custom_meta(): void {
		$planId = $this->createPlan();
		$seatId = $this->seatMgr->create($planId, [
			'seat_identifier' => 'D4',
			'meta' => ['seat_label' => 'VIP Seat', 'seat_category' => 'VIP']
		]);
		$seat = $this->seatMgr->getById($seatId);
		$this->assertEquals('VIP Seat', $seat['meta']['seat_label']);
		$this->assertEquals('VIP', $seat['meta']['seat_category']);
	}

	public function test_create_throws_for_empty_identifier(): void {
		$planId = $this->createPlan();
		$this->expectException(Exception::class);
		$this->seatMgr->create($planId, ['seat_identifier' => '']);
	}

	public function test_create_throws_for_nonexistent_plan(): void {
		$this->expectException(Exception::class);
		$this->seatMgr->create(999999, ['seat_identifier' => 'X1']);
	}

	public function test_create_throws_for_duplicate_identifier(): void {
		$planId = $this->createPlan();
		$this->seatMgr->create($planId, ['seat_identifier' => 'DUP1']);
		$this->expectException(Exception::class);
		$this->seatMgr->create($planId, ['seat_identifier' => 'DUP1']);
	}

	public function test_create_allows_same_identifier_in_different_plans(): void {
		// Free version allows only 1 seating plan — create first, delete, create second
		$plan1 = $this->createPlan('_1');
		$id1 = $this->seatMgr->create($plan1, ['seat_identifier' => 'SAME']);
		$this->assertGreaterThan(0, $id1);

		// Delete plan1 to free the slot, create plan2
		$this->planMgr->delete($plan1, true);
		$plan2 = $this->createPlan('_2');
		$id2 = $this->seatMgr->create($plan2, ['seat_identifier' => 'SAME']);
		$this->assertGreaterThan(0, $id2);
		$this->assertNotEquals($id1, $id2);
	}

	// ── createBulk ───────────────────────────────────────────

	public function test_createBulk_with_string_identifiers(): void {
		$planId = $this->createPlan();
		$ids = $this->seatMgr->createBulk($planId, ['R1', 'R2', 'R3']);
		$this->assertCount(3, $ids);
		foreach ($ids as $id) {
			$this->assertGreaterThan(0, $id);
		}
	}

	public function test_createBulk_with_object_format(): void {
		$planId = $this->createPlan();
		$ids = $this->seatMgr->createBulk($planId, [
			['identifier' => 'S1', 'label' => 'Seat One', 'category' => 'Standard'],
			['identifier' => 'S2', 'label' => 'Seat Two', 'category' => 'VIP'],
		]);
		$this->assertCount(2, $ids);

		$seat = $this->seatMgr->getById($ids[0]);
		$this->assertEquals('S1', $seat['seat_identifier']);
		$this->assertEquals('Seat One', $seat['meta']['seat_label']);
	}

	public function test_createBulk_skips_duplicates(): void {
		$planId = $this->createPlan();
		$this->seatMgr->create($planId, ['seat_identifier' => 'EXIST']);
		$ids = $this->seatMgr->createBulk($planId, ['EXIST', 'NEW1', 'NEW2']);
		$this->assertCount(2, $ids); // EXIST skipped
	}

	public function test_createBulk_skips_empty_identifiers(): void {
		$planId = $this->createPlan();
		$ids = $this->seatMgr->createBulk($planId, ['V1', '', 'V2']);
		$this->assertCount(2, $ids);
	}

	// ── getById ──────────────────────────────────────────────

	public function test_getById_returns_seat_data(): void {
		$planId = $this->createPlan();
		$seatId = $this->seatMgr->create($planId, ['seat_identifier' => 'GET1']);
		$seat = $this->seatMgr->getById($seatId);
		$this->assertIsArray($seat);
		$this->assertEquals($seatId, $seat['id']);
		$this->assertEquals('GET1', $seat['seat_identifier']);
	}

	public function test_getById_returns_null_for_nonexistent(): void {
		$this->assertNull($this->seatMgr->getById(999999));
	}

	public function test_getById_includes_parsed_meta(): void {
		$planId = $this->createPlan();
		$seatId = $this->seatMgr->create($planId, ['seat_identifier' => 'META1']);
		$seat = $this->seatMgr->getById($seatId);
		$this->assertIsArray($seat['meta']);
		$this->assertArrayHasKey('color', $seat['meta']);
		$this->assertArrayHasKey('capacity', $seat['meta']);
	}

	// ── getByPlanId ──────────────────────────────────────────

	public function test_getByPlanId_returns_all_seats(): void {
		$planId = $this->createPlan();
		$this->seatMgr->createBulk($planId, ['P1', 'P2', 'P3']);
		$seats = $this->seatMgr->getByPlanId($planId);
		$this->assertCount(3, $seats);
	}

	public function test_getByPlanId_excludes_soft_deleted(): void {
		$planId = $this->createPlan();
		$ids = $this->seatMgr->createBulk($planId, ['D1', 'D2', 'D3']);
		$this->seatMgr->softDelete($ids[0]);
		$seats = $this->seatMgr->getByPlanId($planId);
		$this->assertCount(2, $seats);
	}

	public function test_getByPlanId_includes_soft_deleted_when_requested(): void {
		$planId = $this->createPlan();
		$ids = $this->seatMgr->createBulk($planId, ['I1', 'I2', 'I3']);
		$this->seatMgr->softDelete($ids[0]);
		$seats = $this->seatMgr->getByPlanId($planId, false, true);
		$this->assertCount(3, $seats);
	}

	public function test_getByPlanId_active_only(): void {
		$planId = $this->createPlan();
		$this->seatMgr->create($planId, ['seat_identifier' => 'ACT1', 'aktiv' => 1]);
		$this->seatMgr->create($planId, ['seat_identifier' => 'ACT2', 'aktiv' => 0]);
		$activeSeats = $this->seatMgr->getByPlanId($planId, true);
		$this->assertCount(1, $activeSeats);
	}

	public function test_getByPlanId_returns_empty_for_nonexistent_plan(): void {
		$seats = $this->seatMgr->getByPlanId(999999);
		$this->assertIsArray($seats);
		$this->assertEmpty($seats);
	}

	// ── getByIdentifier ──────────────────────────────────────

	public function test_getByIdentifier_returns_seat(): void {
		$planId = $this->createPlan();
		$this->seatMgr->create($planId, ['seat_identifier' => 'FIND_ME']);
		$seat = $this->seatMgr->getByIdentifier($planId, 'FIND_ME');
		$this->assertNotNull($seat);
		$this->assertEquals('FIND_ME', $seat['seat_identifier']);
	}

	public function test_getByIdentifier_returns_null_for_nonexistent(): void {
		$planId = $this->createPlan();
		$this->assertNull($this->seatMgr->getByIdentifier($planId, 'NOPE'));
	}

	// ── update ───────────────────────────────────────────────

	public function test_update_changes_identifier(): void {
		$planId = $this->createPlan();
		$seatId = $this->seatMgr->create($planId, ['seat_identifier' => 'OLD']);
		$results = $this->seatMgr->update(['seat_identifier' => 'NEW'], $seatId);
		$this->assertTrue($results[0]['success']);
		$seat = $this->seatMgr->getById($seatId);
		$this->assertEquals('NEW', $seat['seat_identifier']);
	}

	public function test_update_merges_meta(): void {
		$planId = $this->createPlan();
		$seatId = $this->seatMgr->create($planId, ['seat_identifier' => 'UPD']);
		$this->seatMgr->update(['meta' => ['seat_category' => 'Premium']], $seatId);
		$seat = $this->seatMgr->getById($seatId);
		$this->assertEquals('Premium', $seat['meta']['seat_category']);
		// Other meta fields should be preserved
		$this->assertArrayHasKey('color', $seat['meta']);
	}

	public function test_update_multiple_seats(): void {
		$planId = $this->createPlan();
		$ids = $this->seatMgr->createBulk($planId, ['M1', 'M2', 'M3']);
		$results = $this->seatMgr->update(['aktiv' => 0], $ids);
		$this->assertCount(3, $results);
		foreach ($results as $r) {
			$this->assertTrue($r['success']);
		}
	}

	public function test_update_rejects_duplicate_identifier(): void {
		$planId = $this->createPlan();
		$this->seatMgr->create($planId, ['seat_identifier' => 'TAKEN']);
		$seatId = $this->seatMgr->create($planId, ['seat_identifier' => 'FREE']);
		$results = $this->seatMgr->update(['seat_identifier' => 'TAKEN'], $seatId);
		$this->assertFalse($results[0]['success']);
	}

	public function test_update_invalid_id_returns_error(): void {
		$results = $this->seatMgr->update(['aktiv' => 1], 0);
		$this->assertFalse($results[0]['success']);
	}

	// ── softDelete / restore ─────────────────────────────────

	public function test_softDelete_marks_as_deleted(): void {
		$planId = $this->createPlan();
		$seatId = $this->seatMgr->create($planId, ['seat_identifier' => 'SD1']);
		$results = $this->seatMgr->softDelete($seatId);
		$this->assertTrue($results[0]['success']);

		// Should not appear in normal query
		$seats = $this->seatMgr->getByPlanId($planId);
		$this->assertCount(0, $seats);
	}

	public function test_restore_recovers_soft_deleted(): void {
		$planId = $this->createPlan();
		$seatId = $this->seatMgr->create($planId, ['seat_identifier' => 'RES1']);
		$this->seatMgr->softDelete($seatId);
		$this->seatMgr->restore($seatId);

		$seats = $this->seatMgr->getByPlanId($planId);
		$this->assertCount(1, $seats);
	}

	public function test_softDelete_multiple(): void {
		$planId = $this->createPlan();
		$ids = $this->seatMgr->createBulk($planId, ['SD_A', 'SD_B']);
		$results = $this->seatMgr->softDelete($ids);
		$this->assertCount(2, $results);
		foreach ($results as $r) {
			$this->assertTrue($r['success']);
		}
	}

	// ── delete (hard) ────────────────────────────────────────

	public function test_delete_removes_seat(): void {
		$planId = $this->createPlan();
		$seatId = $this->seatMgr->create($planId, ['seat_identifier' => 'DEL1']);
		$results = $this->seatMgr->delete($seatId, true);
		$this->assertTrue($results[0]['success']);
		$this->assertNull($this->seatMgr->getById($seatId));
	}

	public function test_delete_invalid_id(): void {
		$results = $this->seatMgr->delete(0);
		$this->assertFalse($results[0]['success']);
	}

	public function test_deleteByPlanId_removes_all_seats(): void {
		$planId = $this->createPlan();
		$this->seatMgr->createBulk($planId, ['DP1', 'DP2', 'DP3']);
		$count = $this->seatMgr->deleteByPlanId($planId);
		$this->assertEquals(3, $count);
		$this->assertEmpty($this->seatMgr->getByPlanId($planId));
	}

	// ── getCountForPlan ──────────────────────────────────────

	public function test_getCountForPlan_returns_correct_count(): void {
		$planId = $this->createPlan();
		$this->seatMgr->createBulk($planId, ['C1', 'C2', 'C3', 'C4']);
		$this->assertEquals(4, $this->seatMgr->getCountForPlan($planId));
	}

	public function test_getCountForPlan_zero_for_empty(): void {
		$planId = $this->createPlan();
		$this->assertEquals(0, $this->seatMgr->getCountForPlan($planId));
	}

	// ── identifierExistsInPlan ───────────────────────────────

	public function test_identifierExistsInPlan_true(): void {
		$planId = $this->createPlan();
		$this->seatMgr->create($planId, ['seat_identifier' => 'EXISTS']);
		$this->assertTrue($this->seatMgr->identifierExistsInPlan($planId, 'EXISTS'));
	}

	public function test_identifierExistsInPlan_false(): void {
		$planId = $this->createPlan();
		$this->assertFalse($this->seatMgr->identifierExistsInPlan($planId, 'NOPE'));
	}

	public function test_identifierExistsInPlan_exclude_self(): void {
		$planId = $this->createPlan();
		$seatId = $this->seatMgr->create($planId, ['seat_identifier' => 'SELF']);
		$this->assertFalse($this->seatMgr->identifierExistsInPlan($planId, 'SELF', $seatId));
	}

	// ── getSeatingPlanIdForSeatId ────────────────────────────

	public function test_getSeatingPlanIdForSeatId_returns_plan(): void {
		$planId = $this->createPlan();
		$seatId = $this->seatMgr->create($planId, ['seat_identifier' => 'PID1']);
		$result = $this->seatMgr->getSeatingPlanIdForSeatId($seatId);
		$this->assertEquals($planId, $result);
	}

	public function test_getSeatingPlanIdForSeatId_null_for_zero(): void {
		$this->assertNull($this->seatMgr->getSeatingPlanIdForSeatId(0));
	}

	public function test_getSeatingPlanIdForSeatId_null_for_negative(): void {
		$this->assertNull($this->seatMgr->getSeatingPlanIdForSeatId(-1));
	}

	// ── getDropdownOptions ───────────────────────────────────

	public function test_getDropdownOptions_includes_empty(): void {
		$planId = $this->createPlan();
		$this->seatMgr->create($planId, ['seat_identifier' => 'DD1']);
		$options = $this->seatMgr->getDropdownOptions($planId);
		$this->assertArrayHasKey('', $options); // empty option
		$this->assertGreaterThan(1, count($options));
	}

	public function test_getDropdownOptions_without_empty(): void {
		$planId = $this->createPlan();
		$this->seatMgr->create($planId, ['seat_identifier' => 'DD2']);
		$options = $this->seatMgr->getDropdownOptions($planId, false);
		$this->assertArrayNotHasKey('', $options);
		$this->assertCount(1, $options);
	}

	public function test_getDropdownOptions_shows_category(): void {
		$planId = $this->createPlan();
		$this->seatMgr->create($planId, [
			'seat_identifier' => 'CAT1',
			'meta' => ['seat_label' => 'Seat Cat', 'seat_category' => 'VIP']
		]);
		$options = $this->seatMgr->getDropdownOptions($planId, false);
		$label = reset($options);
		$this->assertStringContainsString('VIP', $label);
	}

	// ── updatePosition ───────────────────────────────────────

	public function test_updatePosition_sets_coordinates(): void {
		$planId = $this->createPlan();
		$seatId = $this->seatMgr->create($planId, ['seat_identifier' => 'POS1']);
		$this->seatMgr->updatePosition($seatId, 100.5, 200.3);
		$seat = $this->seatMgr->getById($seatId);
		$this->assertEquals(100.5, $seat['meta']['pos_x']);
		$this->assertEquals(200.3, $seat['meta']['pos_y']);
	}

	// ── upgradeToVisual ──────────────────────────────────────

	public function test_upgradeToVisual_sets_grid_positions(): void {
		$planId = $this->createPlan();
		$this->seatMgr->createBulk($planId, ['G1', 'G2', 'G3']);
		$result = $this->seatMgr->upgradeToVisual($planId, 2, 10, 10, 50);
		$this->assertTrue($result);

		$seats = $this->seatMgr->getByPlanId($planId);
		// First row: G1 at (10,10), G2 at (60,10)
		// Second row: G3 at (10,60)
		$positions = [];
		foreach ($seats as $s) {
			$positions[$s['seat_identifier']] = ['x' => $s['meta']['pos_x'], 'y' => $s['meta']['pos_y']];
		}
		$this->assertEquals(10, $positions['G1']['x']);
		$this->assertEquals(10, $positions['G1']['y']);
		$this->assertEquals(60, $positions['G2']['x']);
		$this->assertEquals(10, $positions['G2']['y']);
		$this->assertEquals(10, $positions['G3']['x']);
		$this->assertEquals(60, $positions['G3']['y']);
	}

	// ── getAuditInfo ─────────────────────────────────────────

	public function test_getAuditInfo_returns_timestamps(): void {
		$planId = $this->createPlan();
		$seatId = $this->seatMgr->create($planId, ['seat_identifier' => 'AUD1']);
		$audit = $this->seatMgr->getAuditInfo($seatId);
		$this->assertIsArray($audit);
		$this->assertArrayHasKey('created_at', $audit);
		$this->assertArrayHasKey('updated_at', $audit);
		$this->assertNotEmpty($audit['created_at']);
	}

	public function test_getAuditInfo_empty_for_nonexistent(): void {
		$audit = $this->seatMgr->getAuditInfo(999999);
		$this->assertIsArray($audit);
		$this->assertEmpty($audit);
	}

	public function test_getAuditInfo_tracks_deletion(): void {
		$planId = $this->createPlan();
		$seatId = $this->seatMgr->create($planId, ['seat_identifier' => 'AUD_DEL']);
		$this->seatMgr->softDelete($seatId);
		$audit = $this->seatMgr->getAuditInfo($seatId);
		$this->assertTrue($audit['is_deleted']);
		$this->assertNotEmpty($audit['deleted_at']);
	}

	// ── prepareSeatMeta ──────────────────────────────────────

	public function test_prepareSeatMeta_fills_defaults(): void {
		$meta = $this->seatMgr->prepareSeatMeta(null);
		$this->assertIsArray($meta);
		$this->assertArrayHasKey('color', $meta);
		$this->assertArrayHasKey('capacity', $meta);
	}

	public function test_prepareSeatMeta_uses_identifier_as_label_fallback(): void {
		$meta = $this->seatMgr->prepareSeatMeta('{}', 'Row-A');
		$this->assertEquals('Row-A', $meta['seat_label']);
	}

	public function test_prepareSeatMeta_preserves_existing_label(): void {
		$json = json_encode(['seat_label' => 'Custom Label']);
		$meta = $this->seatMgr->prepareSeatMeta($json, 'Fallback');
		$this->assertEquals('Custom Label', $meta['seat_label']);
	}

	// ── getMetaObject ────────────────────────────────────────

	public function test_getMetaObject_has_expected_keys(): void {
		$meta = $this->seatMgr->getMetaObject();
		$this->assertIsArray($meta);
		$this->assertArrayHasKey('seat_label', $meta);
		$this->assertArrayHasKey('seat_category', $meta);
		$this->assertArrayHasKey('capacity', $meta);
		$this->assertArrayHasKey('pos_x', $meta);
		$this->assertArrayHasKey('pos_y', $meta);
		$this->assertArrayHasKey('color', $meta);
		$this->assertArrayHasKey('shape_type', $meta);
	}
}
