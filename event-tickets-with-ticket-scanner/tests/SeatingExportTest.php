<?php
/**
 * Tests for seating plan CSV export (#209).
 */

class SeatingExportTest extends WP_UnitTestCase {

	private sasoEventtickets $main;
	private sasoEventtickets_Seating $seating;
	private int $userId;
	private int $planId = 0;

	public function set_up(): void {
		parent::set_up();
		$this->userId = self::factory()->user->create(['role' => 'administrator']);
		wp_set_current_user($this->userId);

		$this->main = sasoEventtickets::Instance();
		$this->seating = $this->main->getSeating();
	}

	public function tear_down(): void {
		// Clean up created seats and plan
		if ($this->planId > 0) {
			global $wpdb;
			$seatsTable = $this->main->getDB()->getTabelle('seats');
			$plansTable = $this->main->getDB()->getTabelle('seatingplans');
			$wpdb->delete($seatsTable, ['seatingplan_id' => $this->planId]);
			$wpdb->delete($plansTable, ['id' => $this->planId]);
		}
		parent::tear_down();
	}

	private function createTestPlan(string $name = 'Test Plan'): int {
		global $wpdb;
		$table = $this->main->getDB()->getTabelle('seatingplans');
		$wpdb->insert($table, [
			'name' => $name,
			'aktiv' => 1,
			'time' => wp_date('Y-m-d H:i:s'),
			'timezone' => wp_timezone_string(),
			'layout_type' => 'simple',
			'meta' => '{}',
			'meta_draft' => '{}',
			'meta_published' => '{}',
			'created_at' => wp_date('Y-m-d H:i:s'),
			'updated_at' => wp_date('Y-m-d H:i:s'),
			'created_by' => $this->userId,
			'updated_by' => $this->userId,
		]);
		$this->planId = (int) $wpdb->insert_id;
		return $this->planId;
	}

	private function createTestSeat(int $planId, string $identifier, array $metaOverrides = []): int {
		global $wpdb;
		$table = $this->main->getDB()->getTabelle('seats');
		$meta = array_merge([
			'seat_label' => '',
			'seat_category' => '',
			'description' => '',
			'capacity' => 1,
			'price_modifier' => 0,
		], $metaOverrides);
		$wpdb->insert($table, [
			'seatingplan_id' => $planId,
			'seat_identifier' => $identifier,
			'aktiv' => 1,
			'sort_order' => 0,
			'time' => wp_date('Y-m-d H:i:s'),
			'timezone' => wp_timezone_string(),
			'meta' => wp_json_encode($meta),
			'is_deleted' => 0,
			'created_at' => wp_date('Y-m-d H:i:s'),
			'updated_at' => wp_date('Y-m-d H:i:s'),
			'created_by' => $this->userId,
			'updated_by' => $this->userId,
		]);
		return (int) $wpdb->insert_id;
	}

	// ── prepareSeatsForExport ──────────────────────────────

	public function test_prepareSeatsForExport_returns_correct_columns(): void {
		$planId = $this->createTestPlan();
		$this->createTestSeat($planId, 'A-1');

		$result = $this->seating->prepareSeatsForExport($planId);

		$this->assertCount(1, $result);
		$row = $result[0];
		$this->assertArrayHasKey('identifier', $row);
		$this->assertArrayHasKey('label', $row);
		$this->assertArrayHasKey('category', $row);
		$this->assertArrayHasKey('active', $row);
		$this->assertArrayHasKey('sort_order', $row);
		$this->assertArrayHasKey('description', $row);
		$this->assertArrayHasKey('capacity', $row);
		$this->assertArrayHasKey('price_modifier', $row);
	}

	public function test_prepareSeatsForExport_includes_meta_fields(): void {
		$planId = $this->createTestPlan();
		$this->createTestSeat($planId, 'VIP-3', [
			'seat_label' => 'VIP Seat 3',
			'seat_category' => 'VIP',
			'description' => 'Front row center',
			'capacity' => 2,
			'price_modifier' => 25.50,
		]);

		$result = $this->seating->prepareSeatsForExport($planId);

		$this->assertCount(1, $result);
		$row = $result[0];
		$this->assertEquals('VIP-3', $row['identifier']);
		$this->assertEquals('VIP Seat 3', $row['label']);
		$this->assertEquals('VIP', $row['category']);
		$this->assertEquals('Front row center', $row['description']);
		$this->assertEquals(2, $row['capacity']);
		$this->assertEquals(25.50, $row['price_modifier']);
	}

	public function test_prepareSeatsForExport_handles_empty_plan(): void {
		$planId = $this->createTestPlan();

		$result = $this->seating->prepareSeatsForExport($planId);

		$this->assertIsArray($result);
		$this->assertEmpty($result);
	}

	public function test_prepareSeatsForExport_requires_valid_plan(): void {
		$this->expectException(\Exception::class);
		$this->seating->prepareSeatsForExport(999999);
	}

	public function test_prepareSeatsForExport_formats_active_flag(): void {
		$planId = $this->createTestPlan();
		$this->createTestSeat($planId, 'A-1');

		// Create an inactive seat directly
		global $wpdb;
		$table = $this->main->getDB()->getTabelle('seats');
		$wpdb->insert($table, [
			'seatingplan_id' => $planId,
			'seat_identifier' => 'A-2',
			'aktiv' => 0,
			'sort_order' => 1,
			'time' => wp_date('Y-m-d H:i:s'),
			'timezone' => wp_timezone_string(),
			'meta' => '{}',
			'is_deleted' => 0,
			'created_at' => wp_date('Y-m-d H:i:s'),
			'updated_at' => wp_date('Y-m-d H:i:s'),
			'created_by' => $this->userId,
			'updated_by' => $this->userId,
		]);

		$result = $this->seating->prepareSeatsForExport($planId);

		$activeStates = array_column($result, 'active', 'identifier');
		$this->assertEquals('yes', $activeStates['A-1']);
		$this->assertEquals('no', $activeStates['A-2']);
	}

	public function test_prepareSeatsForExport_handles_multiple_seats(): void {
		$planId = $this->createTestPlan();
		$this->createTestSeat($planId, 'A-1', ['seat_category' => 'Standard']);
		$this->createTestSeat($planId, 'A-2', ['seat_category' => 'Standard']);
		$this->createTestSeat($planId, 'B-1', ['seat_category' => 'VIP']);

		$result = $this->seating->prepareSeatsForExport($planId);

		$this->assertCount(3, $result);
		$identifiers = array_column($result, 'identifier');
		$this->assertContains('A-1', $identifiers);
		$this->assertContains('A-2', $identifiers);
		$this->assertContains('B-1', $identifiers);
	}

	// ── importSeatsCSV ─────────────────────────────────────

	public function test_importSeatsCSV_creates_new_seats(): void {
		$planId = $this->createTestPlan();

		$result = $this->seating->importSeatsCSV([
			'plan_id' => $planId,
			'rows' => [
				['identifier' => 'A-1', 'label' => 'Seat A1', 'category' => 'Standard'],
				['identifier' => 'A-2', 'label' => 'Seat A2', 'category' => 'VIP'],
			],
		]);

		$this->assertEquals(2, $result['created']);
		$this->assertEquals(0, $result['updated']);
		$this->assertEquals(0, $result['skipped']);
	}

	public function test_importSeatsCSV_updates_existing_seats(): void {
		$planId = $this->createTestPlan();
		$this->createTestSeat($planId, 'A-1', ['seat_category' => 'Standard']);

		$result = $this->seating->importSeatsCSV([
			'plan_id' => $planId,
			'rows' => [
				['identifier' => 'A-1', 'label' => 'Updated Label', 'category' => 'VIP'],
			],
		]);

		$this->assertEquals(0, $result['created']);
		$this->assertEquals(1, $result['updated']);

		// Verify the update took effect
		$seat = $this->seating->getSeatManager()->getByIdentifier($planId, 'A-1');
		$this->assertEquals('VIP', $seat['meta']['seat_category']);
		$this->assertEquals('Updated Label', $seat['meta']['seat_label']);
	}

	public function test_importSeatsCSV_mixed_create_and_update(): void {
		$planId = $this->createTestPlan();
		$this->createTestSeat($planId, 'A-1', ['seat_category' => 'Standard']);

		$result = $this->seating->importSeatsCSV([
			'plan_id' => $planId,
			'rows' => [
				['identifier' => 'A-1', 'label' => 'Updated', 'category' => 'VIP'],
				['identifier' => 'B-1', 'label' => 'New Seat', 'category' => 'Standard'],
			],
		]);

		$this->assertEquals(1, $result['created']);
		$this->assertEquals(1, $result['updated']);
	}

	public function test_importSeatsCSV_skips_empty_identifiers(): void {
		$planId = $this->createTestPlan();

		$result = $this->seating->importSeatsCSV([
			'plan_id' => $planId,
			'rows' => [
				['identifier' => 'A-1', 'label' => 'Good'],
				['identifier' => '', 'label' => 'No ID'],
				['identifier' => '  ', 'label' => 'Whitespace'],
			],
		]);

		$this->assertEquals(1, $result['created']);
		$this->assertEquals(2, $result['skipped']);
	}

	public function test_importSeatsCSV_requires_valid_plan(): void {
		$this->expectException(\Exception::class);
		$this->seating->importSeatsCSV([
			'plan_id' => 999999,
			'rows' => [['identifier' => 'A-1']],
		]);
	}

	public function test_importSeatsCSV_requires_rows(): void {
		$planId = $this->createTestPlan();
		$this->expectException(\Exception::class);
		$this->seating->importSeatsCSV([
			'plan_id' => $planId,
			'rows' => [],
		]);
	}

	public function test_importSeatsCSV_roundtrip_export_import(): void {
		$planId = $this->createTestPlan();
		$this->createTestSeat($planId, 'A-1', [
			'seat_label' => 'Seat A1',
			'seat_category' => 'VIP',
			'description' => 'Front row',
			'capacity' => 2,
			'price_modifier' => 15.00,
		]);
		$this->createTestSeat($planId, 'A-2', [
			'seat_label' => 'Seat A2',
			'seat_category' => 'Standard',
		]);

		// Export
		$exported = $this->seating->prepareSeatsForExport($planId);
		$this->assertCount(2, $exported);

		// Create a new plan and import into it
		$newPlanId = $this->createTestPlan('Import Target');

		$result = $this->seating->importSeatsCSV([
			'plan_id' => $newPlanId,
			'rows' => $exported,
		]);

		$this->assertEquals(2, $result['created']);

		// Verify imported data matches
		$importedSeats = $this->seating->prepareSeatsForExport($newPlanId);
		$this->assertCount(2, $importedSeats);

		$importedMap = array_column($importedSeats, null, 'identifier');
		$this->assertEquals('Seat A1', $importedMap['A-1']['label']);
		$this->assertEquals('VIP', $importedMap['A-1']['category']);
		$this->assertEquals('Front row', $importedMap['A-1']['description']);
		$this->assertEquals(2, $importedMap['A-1']['capacity']);
		$this->assertEquals(15.00, $importedMap['A-1']['price_modifier']);
	}

	// ── Forward/Backward Compatibility ────────────────────

	public function test_importSeatsCSV_reports_unknown_columns(): void {
		$planId = $this->createTestPlan();

		$result = $this->seating->importSeatsCSV([
			'plan_id' => $planId,
			'rows' => [
				['identifier' => 'A-1', 'label' => 'Seat A1', 'future_field' => 'value', 'another_new' => '42'],
			],
		]);

		$this->assertEquals(1, $result['created']);
		$this->assertArrayHasKey('ignored_columns', $result);
		$this->assertContains('future_field', $result['ignored_columns']);
		$this->assertContains('another_new', $result['ignored_columns']);
	}

	public function test_importSeatsCSV_partial_update_preserves_existing_meta(): void {
		$planId = $this->createTestPlan();
		$this->createTestSeat($planId, 'A-1', [
			'seat_label' => 'Original Label',
			'seat_category' => 'VIP',
			'description' => 'Front row center',
			'capacity' => 3,
			'price_modifier' => 20.00,
		]);

		// Import with only identifier and category — other fields should be preserved
		$result = $this->seating->importSeatsCSV([
			'plan_id' => $planId,
			'rows' => [
				['identifier' => 'A-1', 'category' => 'Standard'],
			],
		]);

		$this->assertEquals(0, $result['created']);
		$this->assertEquals(1, $result['updated']);

		$seat = $this->seating->getSeatManager()->getByIdentifier($planId, 'A-1');
		$meta = is_string($seat['meta']) ? json_decode($seat['meta'], true) : $seat['meta'];

		// Updated field
		$this->assertEquals('Standard', $meta['seat_category']);
		// Preserved fields
		$this->assertEquals('Original Label', $meta['seat_label']);
		$this->assertEquals('Front row center', $meta['description']);
		$this->assertEquals(3, $meta['capacity']);
		$this->assertEquals(20.00, $meta['price_modifier']);
	}

	public function test_importSeatsCSV_old_csv_without_new_fields_keeps_defaults(): void {
		$planId = $this->createTestPlan();

		// Minimal CSV — only identifier, simulating an old export format
		$result = $this->seating->importSeatsCSV([
			'plan_id' => $planId,
			'rows' => [
				['identifier' => 'B-1'],
			],
		]);

		$this->assertEquals(1, $result['created']);

		$seat = $this->seating->getSeatManager()->getByIdentifier($planId, 'B-1');
		$meta = is_string($seat['meta']) ? json_decode($seat['meta'], true) : $seat['meta'];

		// Defaults applied for missing fields
		// seat_label defaults to identifier when empty (SeatManager::create behavior)
		$this->assertEquals('B-1', $meta['seat_label']);
		$this->assertEquals('', $meta['seat_category']);
		$this->assertEquals(1, $meta['capacity']);
		$this->assertEquals(0, $meta['price_modifier']);
	}
}
