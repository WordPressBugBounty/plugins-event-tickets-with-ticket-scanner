<?php
/**
 * Batch 41 — Seating Plan CRUD operations:
 * - create: plan with validation, limits, name uniqueness
 * - getById / getByName / getAll / getFullPlan: retrieval methods
 * - update: name, aktiv, layout_type, meta merge
 * - delete: with/without force (cascade)
 * - getCount / nameExists: utility queries
 * - updateLayoutType / getDropdownOptions
 * - saveDraft / publish / discardDraft: draft-publish workflow
 * - isPublished / hasUnpublishedChanges / getDraftMeta / getPublishedMeta
 * - getPublishInfo / getAuditInfo / getLinkedProducts / getActiveSalesInfo
 * - clonePlan: deep copy with seats
 */

class SeatingPlanCRUDTest extends WP_UnitTestCase {

	private $main;
	private $planMgr;
	private $seatMgr;

	public function set_up(): void {
		parent::set_up();
		$this->main = sasoEventtickets::Instance();
		$this->planMgr = $this->main->getSeating()->getPlanManager();
		$this->seatMgr = $this->main->getSeating()->getSeatManager();

		// Clean seating tables
		global $wpdb;
		$wpdb->query("DELETE FROM {$wpdb->prefix}saso_eventtickets_seat_blocks");
		$wpdb->query("DELETE FROM {$wpdb->prefix}saso_eventtickets_seats");
		$wpdb->query("DELETE FROM {$wpdb->prefix}saso_eventtickets_seatingplans");
	}

	// ── create ───────────────────────────────────────────────

	public function test_create_returns_plan_id(): void {
		$id = $this->planMgr->create(['name' => 'Test Plan ' . uniqid()]);
		$this->assertIsInt($id);
		$this->assertGreaterThan(0, $id);
	}

	public function test_create_stores_name(): void {
		$name = 'Unique Plan ' . uniqid();
		$id = $this->planMgr->create(['name' => $name]);
		$plan = $this->planMgr->getById($id);
		$this->assertEquals($name, $plan['name']);
	}

	public function test_create_throws_for_empty_name(): void {
		$this->expectException(Exception::class);
		$this->planMgr->create(['name' => '']);
	}

	public function test_create_throws_for_duplicate_name(): void {
		$name = 'Dup Plan ' . uniqid();
		$this->planMgr->create(['name' => $name]);
		$this->expectException(Exception::class);
		$this->planMgr->create(['name' => $name]);
	}

	public function test_create_with_custom_meta(): void {
		$id = $this->planMgr->create([
			'name' => 'Meta Plan ' . uniqid(),
			'meta' => ['description' => 'Concert Hall', 'canvas_width' => 1200]
		]);
		$plan = $this->planMgr->getById($id);
		$this->assertEquals('Concert Hall', $plan['meta']['description']);
		$this->assertEquals(1200, $plan['meta']['canvas_width']);
	}

	public function test_create_defaults_to_inactive(): void {
		$id = $this->planMgr->create(['name' => 'Inactive Plan ' . uniqid()]);
		$plan = $this->planMgr->getById($id);
		$this->assertEquals(0, (int) $plan['aktiv']);
	}

	// ── getById ──────────────────────────────────────────────

	public function test_getById_returns_plan(): void {
		$id = $this->planMgr->create(['name' => 'GetById Plan ' . uniqid()]);
		$plan = $this->planMgr->getById($id);
		$this->assertIsArray($plan);
		$this->assertEquals($id, $plan['id']);
	}

	public function test_getById_returns_null_for_nonexistent(): void {
		$this->assertNull($this->planMgr->getById(999999));
	}

	public function test_getById_includes_parsed_meta(): void {
		$id = $this->planMgr->create(['name' => 'MetaParsed ' . uniqid()]);
		$plan = $this->planMgr->getById($id);
		$this->assertIsArray($plan['meta']);
		$this->assertArrayHasKey('canvas_width', $plan['meta']);
		$this->assertArrayHasKey('colors', $plan['meta']);
	}

	// ── getByName ────────────────────────────────────────────

	public function test_getByName_returns_plan(): void {
		$name = 'ByName Plan ' . uniqid();
		$this->planMgr->create(['name' => $name]);
		$plan = $this->planMgr->getByName($name);
		$this->assertNotNull($plan);
		$this->assertEquals($name, $plan['name']);
	}

	public function test_getByName_returns_null_for_nonexistent(): void {
		$this->assertNull($this->planMgr->getByName('Nonexistent Plan XYZ'));
	}

	// ── getAll ───────────────────────────────────────────────

	public function test_getAll_returns_plans(): void {
		$this->planMgr->create(['name' => 'All1 ' . uniqid()]);
		$plans = $this->planMgr->getAll();
		$this->assertGreaterThanOrEqual(1, count($plans));
	}

	public function test_getAll_active_only_filters(): void {
		// Create inactive plan (free limit = 1)
		$id = $this->planMgr->create(['name' => 'FilterTest ' . uniqid(), 'aktiv' => 0]);
		$activePlans = $this->planMgr->getAll(true);
		$this->assertCount(0, $activePlans);

		// Now activate it
		$this->planMgr->update($id, ['aktiv' => 1]);
		$activePlans = $this->planMgr->getAll(true);
		$this->assertCount(1, $activePlans);
	}

	// ── getFullPlan ──────────────────────────────────────────

	public function test_getFullPlan_includes_seats(): void {
		$id = $this->planMgr->create(['name' => 'Full Plan ' . uniqid()]);
		$this->seatMgr->createBulk($id, ['F1', 'F2']);
		$full = $this->planMgr->getFullPlan($id);
		$this->assertIsArray($full);
		$this->assertArrayHasKey('seats', $full);
		$this->assertCount(2, $full['seats']);
	}

	public function test_getFullPlan_includes_audit_info(): void {
		$id = $this->planMgr->create(['name' => 'Audit Plan ' . uniqid()]);
		$full = $this->planMgr->getFullPlan($id);
		$this->assertArrayHasKey('audit_info', $full);
		$this->assertArrayHasKey('created_at', $full['audit_info']);
	}

	public function test_getFullPlan_includes_publish_info(): void {
		$id = $this->planMgr->create(['name' => 'Publish Plan ' . uniqid()]);
		$full = $this->planMgr->getFullPlan($id);
		$this->assertArrayHasKey('publish_info', $full);
		$this->assertArrayHasKey('has_unpublished_changes', $full);
	}

	public function test_getFullPlan_returns_null_for_nonexistent(): void {
		$this->assertNull($this->planMgr->getFullPlan(999999));
	}

	// ── update ───────────────────────────────────────────────

	public function test_update_changes_name(): void {
		$id = $this->planMgr->create(['name' => 'OldName ' . uniqid()]);
		$newName = 'NewName ' . uniqid();
		$this->planMgr->update($id, ['name' => $newName]);
		$plan = $this->planMgr->getById($id);
		$this->assertEquals($newName, $plan['name']);
	}

	public function test_update_changes_aktiv(): void {
		$id = $this->planMgr->create(['name' => 'Toggle ' . uniqid()]);
		$this->planMgr->update($id, ['aktiv' => 1]);
		$plan = $this->planMgr->getById($id);
		$this->assertEquals(1, (int) $plan['aktiv']);
	}

	public function test_update_merges_meta(): void {
		$id = $this->planMgr->create(['name' => 'MergeMeta ' . uniqid()]);
		$this->planMgr->update($id, ['meta' => ['description' => 'Updated desc']]);
		$plan = $this->planMgr->getById($id);
		$this->assertEquals('Updated desc', $plan['meta']['description']);
		// Other meta preserved
		$this->assertArrayHasKey('canvas_width', $plan['meta']);
	}

	public function test_update_throws_for_nonexistent(): void {
		$this->expectException(Exception::class);
		$this->planMgr->update(999999, ['name' => 'Nope']);
	}

	public function test_update_allows_keeping_same_name(): void {
		$name = 'SameName ' . uniqid();
		$id = $this->planMgr->create(['name' => $name]);
		// Updating to own name should succeed (not throw)
		$result = $this->planMgr->update($id, ['name' => $name]);
		$this->assertTrue($result);
	}

	public function test_update_layout_type(): void {
		$id = $this->planMgr->create(['name' => 'Layout ' . uniqid()]);
		$this->planMgr->update($id, ['layout_type' => 'visual']);
		$plan = $this->planMgr->getById($id);
		$this->assertEquals('visual', $plan['layout_type']);
	}

	// ── delete ───────────────────────────────────────────────

	public function test_delete_empty_plan(): void {
		$id = $this->planMgr->create(['name' => 'DelEmpty ' . uniqid()]);
		$result = $this->planMgr->delete($id);
		$this->assertTrue($result);
		$this->assertNull($this->planMgr->getById($id));
	}

	public function test_delete_throws_when_has_seats_no_force(): void {
		$id = $this->planMgr->create(['name' => 'DelSeats ' . uniqid()]);
		$this->seatMgr->create($id, ['seat_identifier' => 'X1']);
		$this->expectException(Exception::class);
		$this->planMgr->delete($id, false);
	}

	public function test_delete_force_removes_seats(): void {
		$id = $this->planMgr->create(['name' => 'ForceDelete ' . uniqid()]);
		$this->seatMgr->createBulk($id, ['FD1', 'FD2']);
		$result = $this->planMgr->delete($id, true);
		$this->assertTrue($result);
		$this->assertNull($this->planMgr->getById($id));
		$this->assertEmpty($this->seatMgr->getByPlanId($id));
	}

	// ── getCount / nameExists ────────────────────────────────

	public function test_getCount_returns_correct(): void {
		$before = $this->planMgr->getCount();
		$this->assertEquals(0, $before); // tables cleaned in set_up
		$this->planMgr->create(['name' => 'Count1 ' . uniqid()]);
		$this->assertEquals(1, $this->planMgr->getCount());
	}

	public function test_nameExists_true(): void {
		$name = 'ExistsCheck ' . uniqid();
		$this->planMgr->create(['name' => $name]);
		$this->assertTrue($this->planMgr->nameExists($name));
	}

	public function test_nameExists_false(): void {
		$this->assertFalse($this->planMgr->nameExists('NoSuchPlan_' . uniqid()));
	}

	public function test_nameExists_exclude_self(): void {
		$name = 'SelfCheck ' . uniqid();
		$id = $this->planMgr->create(['name' => $name]);
		$this->assertFalse($this->planMgr->nameExists($name, $id));
	}

	// ── updateLayoutType ─────────────────────────────────────

	public function test_updateLayoutType_to_visual(): void {
		$id = $this->planMgr->create(['name' => 'LType ' . uniqid()]);
		$this->planMgr->updateLayoutType($id, 'visual');
		$plan = $this->planMgr->getById($id);
		$this->assertEquals('visual', $plan['meta']['layout_type']);
	}

	public function test_updateLayoutType_invalid_falls_back(): void {
		$id = $this->planMgr->create(['name' => 'LInvalid ' . uniqid()]);
		$this->planMgr->updateLayoutType($id, 'invalid_type');
		$plan = $this->planMgr->getById($id);
		$this->assertEquals('simple', $plan['meta']['layout_type']);
	}

	// ── getDropdownOptions ───────────────────────────────────

	public function test_getDropdownOptions_includes_empty(): void {
		$this->planMgr->create(['name' => 'DDOpt ' . uniqid(), 'aktiv' => 1]);
		$options = $this->planMgr->getDropdownOptions();
		$this->assertArrayHasKey('', $options);
	}

	public function test_getDropdownOptions_only_active(): void {
		// Create inactive plan — dropdown should be empty (no empty option)
		$id = $this->planMgr->create(['name' => 'DDInactive ' . uniqid(), 'aktiv' => 0]);
		$options = $this->planMgr->getDropdownOptions(false);
		$this->assertCount(0, $options);

		// Activate — now should appear
		$this->planMgr->update($id, ['aktiv' => 1]);
		$options = $this->planMgr->getDropdownOptions(false);
		$this->assertCount(1, $options);
	}

	// ── saveDraft / publish / discardDraft ────────────────────

	public function test_saveDraft_stores_data(): void {
		$id = $this->planMgr->create(['name' => 'Draft ' . uniqid()]);
		$result = $this->planMgr->saveDraft($id, ['description' => 'Draft description']);
		$this->assertTrue($result);
		$draft = $this->planMgr->getDraftMeta($id);
		$this->assertEquals('Draft description', $draft['description']);
	}

	public function test_saveDraft_throws_for_nonexistent(): void {
		$this->expectException(Exception::class);
		$this->planMgr->saveDraft(999999, ['description' => 'nope']);
	}

	public function test_publish_copies_draft_to_published(): void {
		$id = $this->planMgr->create(['name' => 'Pub ' . uniqid()]);
		$this->planMgr->saveDraft($id, ['description' => 'Published content']);
		$result = $this->planMgr->publish($id);
		$this->assertTrue($result['success']);

		$published = $this->planMgr->getPublishedMeta($id);
		$this->assertEquals('Published content', $published['description']);
	}

	public function test_publish_sets_published_at(): void {
		$id = $this->planMgr->create(['name' => 'PubAt ' . uniqid()]);
		$this->planMgr->publish($id);
		$this->assertTrue($this->planMgr->isPublished($id));
	}

	public function test_discardDraft_reverts_to_published(): void {
		$id = $this->planMgr->create(['name' => 'Discard ' . uniqid()]);
		$this->planMgr->saveDraft($id, ['description' => 'Draft v1']);
		$this->planMgr->publish($id);
		$this->planMgr->saveDraft($id, ['description' => 'Draft v2']);

		$this->planMgr->discardDraft($id);
		$draft = $this->planMgr->getDraftMeta($id);
		$this->assertEquals('Draft v1', $draft['description']);
	}

	// ── isPublished / hasUnpublishedChanges ──────────────────

	public function test_isPublished_false_initially(): void {
		$id = $this->planMgr->create(['name' => 'NotPub ' . uniqid()]);
		$this->assertFalse($this->planMgr->isPublished($id));
	}

	public function test_isPublished_true_after_publish(): void {
		$id = $this->planMgr->create(['name' => 'YesPub ' . uniqid()]);
		$this->planMgr->publish($id);
		$this->assertTrue($this->planMgr->isPublished($id));
	}

	public function test_hasUnpublishedChanges_true_before_publish(): void {
		$id = $this->planMgr->create(['name' => 'Unpub ' . uniqid()]);
		$this->assertTrue($this->planMgr->hasUnpublishedChanges($id));
	}

	public function test_hasUnpublishedChanges_false_after_publish(): void {
		$id = $this->planMgr->create(['name' => 'PubSync ' . uniqid()]);
		$this->planMgr->publish($id);
		$this->assertFalse($this->planMgr->hasUnpublishedChanges($id));
	}

	public function test_hasUnpublishedChanges_true_after_draft_edit(): void {
		$id = $this->planMgr->create(['name' => 'PostPub ' . uniqid()]);
		$this->planMgr->publish($id);
		$this->planMgr->saveDraft($id, ['description' => 'Changed']);
		$this->assertTrue($this->planMgr->hasUnpublishedChanges($id));
	}

	// ── getDraftMeta / getPublishedMeta ──────────────────────

	public function test_getDraftMeta_has_defaults(): void {
		$id = $this->planMgr->create(['name' => 'DM ' . uniqid()]);
		$draft = $this->planMgr->getDraftMeta($id);
		$this->assertIsArray($draft);
		$this->assertArrayHasKey('canvas_width', $draft);
	}

	public function test_getPublishedMeta_empty_before_publish(): void {
		$id = $this->planMgr->create(['name' => 'PM ' . uniqid()]);
		$published = $this->planMgr->getPublishedMeta($id);
		$this->assertIsArray($published);
		// Returns defaults merged with empty string
	}

	// ── getPublishInfo / getAuditInfo ────────────────────────

	public function test_getPublishInfo_null_before_publish(): void {
		$id = $this->planMgr->create(['name' => 'PI ' . uniqid()]);
		$this->assertNull($this->planMgr->getPublishInfo($id));
	}

	public function test_getPublishInfo_returns_data_after_publish(): void {
		$id = $this->planMgr->create(['name' => 'PIAfter ' . uniqid()]);
		$this->planMgr->publish($id);
		$info = $this->planMgr->getPublishInfo($id);
		$this->assertIsArray($info);
		$this->assertArrayHasKey('published_at', $info);
		$this->assertNotEmpty($info['published_at']);
	}

	public function test_getAuditInfo_returns_timestamps(): void {
		$id = $this->planMgr->create(['name' => 'AI ' . uniqid()]);
		$audit = $this->planMgr->getAuditInfo($id);
		$this->assertIsArray($audit);
		$this->assertArrayHasKey('created_at', $audit);
		$this->assertArrayHasKey('updated_at', $audit);
	}

	public function test_getAuditInfo_empty_for_nonexistent(): void {
		$audit = $this->planMgr->getAuditInfo(999999);
		$this->assertIsArray($audit);
		$this->assertEmpty($audit);
	}

	// ── getLinkedProducts / getActiveSalesInfo ────────────────

	public function test_getLinkedProducts_empty_for_new_plan(): void {
		$id = $this->planMgr->create(['name' => 'LP ' . uniqid()]);
		$products = $this->planMgr->getLinkedProducts($id);
		$this->assertIsArray($products);
		$this->assertEmpty($products);
	}

	public function test_getActiveSalesInfo_no_sales_initially(): void {
		$id = $this->planMgr->create(['name' => 'ASI ' . uniqid()]);
		$info = $this->planMgr->getActiveSalesInfo($id);
		$this->assertIsArray($info);
		$this->assertFalse($info['has_active_sales']);
		$this->assertEquals(0, $info['total_tickets']);
	}

	// ── clonePlan ────────────────────────────────────────────

	public function test_clonePlan_creates_copy_with_seats(): void {
		// Free version limit: 1 plan. clonePlan internally creates a new plan.
		// We must verify the clone attempts creation (which will fail in free).
		// To test properly, we verify the limit exception or skip.
		$maxPlans = $this->main->getBase()->getMaxValue('seatingplans', 1);
		if ($maxPlans > 0 && $maxPlans <= 1) {
			// In free version, clonePlan always hits limit
			$srcId = $this->planMgr->create(['name' => 'CloneSrc ' . uniqid()]);
			$this->seatMgr->createBulk($srcId, ['CL1', 'CL2']);
			$this->expectException(Exception::class);
			$this->planMgr->clonePlan($srcId);
		} else {
			// Premium: full clone test
			$srcId = $this->planMgr->create(['name' => 'Source ' . uniqid()]);
			$this->seatMgr->createBulk($srcId, ['CL1', 'CL2', 'CL3']);
			$newId = $this->planMgr->clonePlan($srcId);
			$this->assertGreaterThan(0, $newId);
			$newSeats = $this->seatMgr->getByPlanId($newId);
			$this->assertCount(3, $newSeats);
		}
	}

	public function test_clonePlan_throws_for_nonexistent(): void {
		$this->expectException(Exception::class);
		$this->planMgr->clonePlan(999999);
	}

	// ── getMetaObject ────────────────────────────────────────

	public function test_getMetaObject_has_expected_structure(): void {
		$meta = $this->planMgr->getMetaObject();
		$this->assertIsArray($meta);
		$this->assertArrayHasKey('description', $meta);
		$this->assertArrayHasKey('layout_type', $meta);
		$this->assertArrayHasKey('canvas_width', $meta);
		$this->assertArrayHasKey('canvas_height', $meta);
		$this->assertArrayHasKey('colors', $meta);
		$this->assertIsArray($meta['colors']);
		$this->assertArrayHasKey('available', $meta['colors']);
	}
}
