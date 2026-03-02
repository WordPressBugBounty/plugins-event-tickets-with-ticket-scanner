<?php
/**
 * Batch 36 — Seating coordinator methods on sasoEventtickets_Seating:
 * - isSeatingRequired: checks product meta for seating requirement
 * - getSeatInfo: wrapper for seat manager getById
 * - blockSeatForCart / releaseSeatFromCart: cart-level wrappers
 * - getSeatsWithStatus: wrapper for block manager
 * - getFieldSeatSelection: returns HTML field name
 * - getMetaVariationSeatingplan: returns meta key string
 * - getMetaOrderItemSeat / getMetaOrderItemSeatBlockId: order item meta keys
 * - getMetaCartItemSeat: cart item meta key
 */

class SeatingCoordinatorTest extends WP_UnitTestCase {

	private $main;
	private $seating;

	public function set_up(): void {
		parent::set_up();
		$this->main = sasoEventtickets::Instance();
		$this->seating = $this->main->getSeating();

		if (!class_exists('WC_Product_Simple')) {
			$this->markTestSkipped('WooCommerce not available');
		}

		// Clean seating tables
		global $wpdb;
		$wpdb->query("DELETE FROM {$wpdb->prefix}saso_eventtickets_seat_blocks");
		$wpdb->query("DELETE FROM {$wpdb->prefix}saso_eventtickets_seats");
		$wpdb->query("DELETE FROM {$wpdb->prefix}saso_eventtickets_seatingplans");
	}

	private function createTicketProductWithSeating(): array {
		$planId = $this->seating->getPlanManager()->create(['name' => 'CoordTest ' . uniqid()]);
		$seatIds = [];
		for ($i = 1; $i <= 3; $i++) {
			$seatIds[] = $this->seating->getSeatManager()->create($planId, ['seat_identifier' => "R{$i}"]);
		}

		$product = new WC_Product_Simple();
		$product->set_name('Seating Coord Test ' . uniqid());
		$product->set_regular_price('20.00');
		$product->set_status('publish');
		$product->save();
		$pid = $product->get_id();

		update_post_meta($pid, 'saso_eventtickets_is_ticket', 'yes');
		update_post_meta($pid, $this->seating->getMetaProductSeatingplan(), $planId);
		update_post_meta($pid, $this->seating->getMetaProductSeatingRequired(), 'yes');

		return ['plan_id' => $planId, 'seat_ids' => $seatIds, 'product_id' => $pid, 'product' => $product];
	}

	// ── isSeatingRequired ─────────────────────────────────────

	public function test_isSeatingRequired_true_when_set(): void {
		$setup = $this->createTicketProductWithSeating();
		$this->assertTrue($this->seating->isSeatingRequired($setup['product_id']));
	}

	public function test_isSeatingRequired_false_when_not_set(): void {
		$product = new WC_Product_Simple();
		$product->set_name('No Seating ' . uniqid());
		$product->set_regular_price('10.00');
		$product->set_status('publish');
		$product->save();

		$this->assertFalse($this->seating->isSeatingRequired($product->get_id()));
	}

	public function test_isSeatingRequired_false_for_zero(): void {
		$this->assertFalse($this->seating->isSeatingRequired(0));
	}

	public function test_isSeatingRequired_false_for_negative(): void {
		$this->assertFalse($this->seating->isSeatingRequired(-1));
	}

	// ── getSeatInfo ───────────────────────────────────────────

	public function test_getSeatInfo_returns_seat_data(): void {
		$setup = $this->createTicketProductWithSeating();
		$seat = $this->seating->getSeatInfo($setup['seat_ids'][0]);

		$this->assertIsArray($seat);
		$this->assertEquals($setup['seat_ids'][0], $seat['id']);
		$this->assertEquals('R1', $seat['seat_identifier']);
	}

	public function test_getSeatInfo_returns_null_for_nonexistent(): void {
		$result = $this->seating->getSeatInfo(999999);
		$this->assertNull($result);
	}

	// ── getFieldSeatSelection ─────────────────────────────────

	public function test_getFieldSeatSelection_returns_string(): void {
		$field = $this->seating->getFieldSeatSelection();
		$this->assertIsString($field);
		$this->assertNotEmpty($field);
	}

	public function test_getFieldSeatSelection_contains_seating(): void {
		$field = $this->seating->getFieldSeatSelection();
		$this->assertStringContainsString('seat', strtolower($field));
	}

	// ── Meta key getters ──────────────────────────────────────

	public function test_getMetaVariationSeatingplan_returns_string(): void {
		$key = $this->seating->getMetaVariationSeatingplan();
		$this->assertIsString($key);
		$this->assertStringContainsString('seatingplan', $key);
	}

	public function test_getMetaOrderItemSeat_returns_string(): void {
		$key = $this->seating->getMetaOrderItemSeat();
		$this->assertIsString($key);
		$this->assertStringContainsString('seat', strtolower($key));
	}

	public function test_getMetaOrderItemSeatBlockId_returns_string(): void {
		$key = $this->seating->getMetaOrderItemSeatBlockId();
		$this->assertIsString($key);
		$this->assertStringContainsString('block', strtolower($key));
	}

	public function test_getMetaCartItemSeat_returns_string(): void {
		$key = $this->seating->getMetaCartItemSeat();
		$this->assertIsString($key);
		$this->assertStringContainsString('seat', strtolower($key));
	}

	// ── getSeatsWithStatus ────────────────────────────────────

	public function test_getSeatsWithStatus_returns_all_seats(): void {
		$setup = $this->createTicketProductWithSeating();

		$seats = $this->seating->getSeatsWithStatus($setup['plan_id'], $setup['product_id']);
		$this->assertIsArray($seats);
		$this->assertCount(3, $seats);
	}

	public function test_getSeatsWithStatus_shows_blocked_seat(): void {
		$setup = $this->createTicketProductWithSeating();
		$sessionId = 'coord_status_' . uniqid();

		$this->seating->getBlockManager()->blockSeat(
			$setup['seat_ids'][0],
			$setup['product_id'],
			$sessionId
		);

		$seats = $this->seating->getSeatsWithStatus($setup['plan_id'], $setup['product_id']);
		$blockedCount = 0;
		foreach ($seats as $s) {
			if (isset($s['availability']) && $s['availability'] === 'blocked') {
				$blockedCount++;
			}
		}
		$this->assertGreaterThanOrEqual(1, $blockedCount);
	}

	public function test_getSeatsWithStatus_with_event_date(): void {
		$setup = $this->createTicketProductWithSeating();
		$seats = $this->seating->getSeatsWithStatus($setup['plan_id'], $setup['product_id'], '2026-06-15');
		$this->assertIsArray($seats);
		$this->assertCount(3, $seats);
	}

	// ── getStats ──────────────────────────────────────────────

	public function test_getStats_returns_complete_stats(): void {
		$setup = $this->createTicketProductWithSeating();

		$stats = $this->seating->getStats($setup['plan_id']);
		$this->assertIsArray($stats);
		$this->assertEquals(3, $stats['total_seats']);
		$this->assertEquals(0, $stats['blocked']);
		$this->assertEquals(0, $stats['confirmed']);
		$this->assertEquals(3, $stats['available']);
	}

	public function test_getStats_reflects_blocked_seat(): void {
		$setup = $this->createTicketProductWithSeating();
		$sessionId = 'coord_stats_' . uniqid();

		$this->seating->getBlockManager()->blockSeat(
			$setup['seat_ids'][0],
			$setup['product_id'],
			$sessionId
		);

		$stats = $this->seating->getStats($setup['plan_id']);
		$this->assertEquals(3, $stats['total_seats']);
		$this->assertGreaterThanOrEqual(1, $stats['blocked']);
		$this->assertEquals($stats['total_seats'] - $stats['blocked'] - $stats['confirmed'], $stats['available']);
	}

	public function test_getStats_with_event_date(): void {
		$setup = $this->createTicketProductWithSeating();
		$sessionId = 'date_stats_' . uniqid();

		$this->seating->getBlockManager()->blockSeat(
			$setup['seat_ids'][0],
			$setup['product_id'],
			$sessionId,
			'2026-12-25'
		);

		$statsXmas = $this->seating->getStats($setup['plan_id'], '2026-12-25');
		$this->assertGreaterThanOrEqual(1, $statsXmas['blocked']);

		$statsOther = $this->seating->getStats($setup['plan_id'], '2026-12-26');
		$this->assertEquals(0, $statsOther['blocked']);
	}

	// ── Lazy loading managers ─────────────────────────────────

	public function test_getPlanManager_returns_instance(): void {
		$pm = $this->seating->getPlanManager();
		$this->assertInstanceOf(sasoEventtickets_Seating_Plan::class, $pm);
	}

	public function test_getSeatManager_returns_instance(): void {
		$sm = $this->seating->getSeatManager();
		$this->assertInstanceOf(sasoEventtickets_Seating_Seat::class, $sm);
	}

	public function test_getBlockManager_returns_instance(): void {
		$bm = $this->seating->getBlockManager();
		$this->assertInstanceOf(sasoEventtickets_Seating_Block::class, $bm);
	}

	public function test_getAdminHandler_returns_instance(): void {
		$ah = $this->seating->getAdminHandler();
		$this->assertNotNull($ah);
	}
}
