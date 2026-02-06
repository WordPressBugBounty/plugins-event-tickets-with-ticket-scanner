<?php
/**
 * Seating Block (Semaphore) Class
 *
 * Handles seat blocking/reservation logic with database transactions.
 *
 * @package    Event_Tickets_With_Ticket_Scanner
 * @subpackage Seating
 * @since      2.8.0
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
	exit;
}

require_once __DIR__ . '/class-seating-base.php';

/**
 * Seating Block Manager
 *
 * Provides seat blocking/semaphore functionality using database transactions.
 *
 * @since 2.8.0
 */
class sasoEventtickets_Seating_Block extends sasoEventtickets_Seating_Base {

	/**
	 * Table name
	 *
	 * @var string
	 */
	private string $table = 'seat_blocks';

	/**
	 * Get meta object structure for seat blocks
	 *
	 * @return array Meta object structure with all defaults
	 */
	public function getMetaObject(): array {
		$metaObj = [
			'block_type' => self::BLOCK_TYPE_SESSION,
			'user_id' => 0,
			'order_item_id' => null,
			'confirmed_at' => null
		];

		// Premium hook - can add fields without basic plugin update
		if ($this->MAIN->isPremium() && method_exists($this->MAIN->getPremiumFunctions(), 'getSeatingBlockMetaObject')) {
			$metaObj = $this->MAIN->getPremiumFunctions()->getSeatingBlockMetaObject($metaObj);
		}

		return $metaObj;
	}

	/**
	 * Block a seat (temporary reservation)
	 *
	 * Uses database transaction with SELECT FOR UPDATE to prevent race conditions.
	 *
	 * @param int $seatId Seat ID
	 * @param int $productId WooCommerce product ID
	 * @param string $sessionId WC session ID
	 * @param string|null $eventDate Event date (Y-m-d) or null for general
	 * @return array Result: ['success' => bool, 'block_id' => int, 'error' => string, 'extended' => bool]
	 */
	public function blockSeat(int $seatId, int $productId, string $sessionId, ?string $eventDate = null): array {
		global $wpdb;

		// Validate IDs
		if ($seatId <= 0) {
			$this->MAIN->getDB()->logError('blockSeat: Invalid seat ID: ' . $seatId);
			return ['success' => false, 'error' => 'invalid_seat'];
		}
		if ($productId <= 0) {
			$this->MAIN->getDB()->logError('blockSeat: Invalid product ID: ' . $productId);
			return ['success' => false, 'error' => 'invalid_product'];
		}
		if (empty($sessionId)) {
			$this->MAIN->getDB()->logError('blockSeat: Empty session ID');
			return ['success' => false, 'error' => 'invalid_session'];
		}

		// Get seat info
		$seat = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT s.*, sp.id as plan_id FROM {$this->getTable('seats')} s
				JOIN {$this->getTable('seatingplans')} sp ON s.seatingplan_id = sp.id
				WHERE s.id = %d AND s.aktiv = 1",
				$seatId
			),
			ARRAY_A
		);

		if (!$seat) {
			return ['success' => false, 'error' => 'seat_not_found'];
		}

		$planId = (int) $seat['seatingplan_id'];
		$timeoutMinutes = $this->getBlockTimeout();
		// Use WordPress timezone (current_time) to match DB comparisons
		$expiresAt = date('Y-m-d H:i:s', current_time('timestamp') + ($timeoutMinutes * 60));

		// Start transaction
		$wpdb->query('START TRANSACTION');

		try {
			// Lock the rows we're checking (prevent race conditions)
			// Check if seat is already blocked/confirmed for this product/date
			$existingBlock = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM {$this->getTable($this->table)}
					WHERE seat_id = %d
					AND product_id = %d
					AND (event_date = %s OR event_date IS NULL)
					AND status IN (%s, %s)
					FOR UPDATE",
					$seatId,
					$productId,
					$eventDate,
					self::STATUS_BLOCKED,
					self::STATUS_CONFIRMED
				),
				ARRAY_A
			);

			// If block exists for same session
			if ($existingBlock && $existingBlock['session_id'] === $sessionId && $existingBlock['status'] === self::STATUS_BLOCKED) {
				// Check if expired - if so, delete and create new block with full time
				if (strtotime($existingBlock['expires_at']) < current_time('timestamp')) {
					$wpdb->delete(
						$this->getTable($this->table),
						['id' => $existingBlock['id']],
						['%d']
					);
					$existingBlock = null; // Clear so we fall through to create new block
				} else {
					// Not expired - return existing block (don't extend time - that would be unfair)
					$wpdb->query('COMMIT');
					return [
						'success' => true,
						'block_id' => (int) $existingBlock['id'],
						'existing' => true,
						'expires_at' => $existingBlock['expires_at']
					];
				}
			}

			// If block exists for different session or is confirmed, seat unavailable
			if ($existingBlock) {
				// Check if expired (cleanup might not have run yet)
				if ($existingBlock['status'] === self::STATUS_BLOCKED && strtotime($existingBlock['expires_at']) < time()) {
					// Expired block, delete it and continue
					$wpdb->delete(
						$this->getTable($this->table),
						['id' => $existingBlock['id']],
						['%d']
					);
				} else {
					$wpdb->query('ROLLBACK');
					return [
						'success' => false,
						'error' => 'seat_unavailable',
						'blocked_by' => $existingBlock['status'] === self::STATUS_CONFIRMED ? 'order' : 'session'
					];
				}
			}

			// Create new block
			$result = $wpdb->insert(
				$this->getTable($this->table),
				[
					'time' => current_time('mysql'),
					'timezone' => wp_timezone_string(),
					'seat_id' => $seatId,
					'seatingplan_id' => $planId,
					'product_id' => $productId,
					'event_date' => $eventDate,
					'session_id' => $sessionId,
					'order_id' => null,
					'code_id' => null,
					'expires_at' => $expiresAt,
					'last_seen' => current_time('mysql'), // Initialize last_seen on creation
					'status' => self::STATUS_BLOCKED,
					'meta' => $this->MAIN->getCore()->json_encode_with_error_handling([
						'block_type' => self::BLOCK_TYPE_SESSION,
						'user_id' => get_current_user_id()
					])
				],
				['%s', '%s', '%d', '%d', '%d', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s']
			);

			if ($result === false) {
				$wpdb->query('ROLLBACK');
				return ['success' => false, 'error' => 'db_error', 'message' => $wpdb->last_error];
			}

			$blockId = $wpdb->insert_id;
			$wpdb->query('COMMIT');

			return [
				'success' => true,
				'block_id' => $blockId,
				'extended' => false,
				'expires_at' => $expiresAt
			];

		} catch (Exception $e) {
			$wpdb->query('ROLLBACK');
			return ['success' => false, 'error' => 'exception', 'message' => $e->getMessage()];
		}
	}

	/**
	 * Release a blocked seat (cancel reservation)
	 *
	 * @param int $blockId Block ID
	 * @param string $sessionId Session ID (must match)
	 * @return bool Success
	 */
	public function releaseBlock(int $blockId, string $sessionId): bool {
		global $wpdb;

		// Only release if session matches and status is blocked
		$result = $wpdb->delete(
			$this->getTable($this->table),
			[
				'id' => $blockId,
				'session_id' => $sessionId,
				'status' => self::STATUS_BLOCKED
			],
			['%d', '%s', '%s']
		);

		return $result > 0;
	}

	/**
	 * Release all blocks for a session
	 *
	 * @param string $sessionId Session ID
	 * @return int Number of released blocks
	 */
	public function releaseAllForSession(string $sessionId): int {
		global $wpdb;

		return $wpdb->delete(
			$this->getTable($this->table),
			[
				'session_id' => $sessionId,
				'status' => self::STATUS_BLOCKED
			],
			['%s', '%s']
		);
	}

	/**
	 * Get active blocks for a session
	 *
	 * Returns all non-expired blocked seats for a session, optionally filtered by product/date.
	 * Useful for restoring user's selection on page reload.
	 *
	 * @param string $sessionId Session ID
	 * @param int|null $productId Product ID filter (optional)
	 * @param string|null $eventDate Event date filter (optional)
	 * @return array Array of block records with seat info
	 */
	public function getSessionBlocks(string $sessionId, ?int $productId = null, ?string $eventDate = null): array {
		global $wpdb;

		$blocksTable = $this->getTable($this->table);
		$seatsTable = $this->getTable('seats');
		$now = current_time('mysql');

		$sql = $wpdb->prepare(
			"SELECT b.*, s.seat_identifier, s.meta as seat_meta
			 FROM {$blocksTable} b
			 LEFT JOIN {$seatsTable} s ON b.seat_id = s.id
			 WHERE b.session_id = %s
			   AND b.status = %s
			   AND b.expires_at > %s",
			$sessionId,
			self::STATUS_BLOCKED,
			$now
		);

		if ($productId !== null) {
			$sql .= $wpdb->prepare(" AND b.product_id = %d", $productId);
		}

		if ($eventDate !== null) {
			$sql .= $wpdb->prepare(" AND b.event_date = %s", $eventDate);
		}

		$results = $wpdb->get_results($sql, ARRAY_A);

		// Parse seat meta to get label
		foreach ($results as &$row) {
			$seatMeta = !empty($row['seat_meta']) ? json_decode($row['seat_meta'], true) : [];
			$row['seat_label'] = $seatMeta['label'] ?? $row['seat_identifier'] ?? ('Seat ' . $row['seat_id']);
			$row['seat_category'] = $seatMeta['category'] ?? '';
			$row['seat_desc'] = $seatMeta['seat_desc'] ?? '';
		}

		return $results ?: [];
	}

	/**
	 * Update last_seen timestamp for seat blocks (heartbeat keep-alive)
	 *
	 * Used by WordPress heartbeat to track if user is still active.
	 * Only updates blocks that belong to the given session.
	 *
	 * @param array $blockIds Array of block IDs
	 * @param string $sessionId Session ID (must match)
	 * @return int Number of updated blocks
	 */
	public function updateLastSeen(array $blockIds, string $sessionId): int {
		global $wpdb;

		if (empty($blockIds) || empty($sessionId)) {
			return 0;
		}

		// Filter to integers
		$blockIds = array_map('intval', $blockIds);
		$blockIds = array_filter($blockIds, function($id) { return $id > 0; });

		if (empty($blockIds)) {
			return 0;
		}

		// Update last_seen for these blocks (only if session matches and still blocked)
		$placeholders = implode(',', array_fill(0, count($blockIds), '%d'));
		$values = array_merge(
			[current_time('mysql'), $sessionId, self::STATUS_BLOCKED],
			$blockIds
		);

		$affected = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$this->getTable($this->table)}
				SET last_seen = %s
				WHERE session_id = %s
				AND status = %s
				AND id IN ($placeholders)",
				...$values
			)
		);

		return (int) $affected;
	}

	/**
	 * Confirm a seat block (order completed)
	 *
	 * @param int $blockId Block ID
	 * @param int $orderId WooCommerce order ID
	 * @param int $orderItemId Order item ID
	 * @param int $codeId Ticket code ID
	 * @return bool Success
	 */
	public function confirmBlock(int $blockId, int $orderId, int $orderItemId, int $codeId): bool {
		global $wpdb;

		$result = $wpdb->update(
			$this->getTable($this->table),
			[
				'status' => self::STATUS_CONFIRMED,
				'order_id' => $orderId,
				'code_id' => $codeId,
				'expires_at' => null,
				'meta' => $this->MAIN->getCore()->json_encode_with_error_handling([
					'block_type' => self::BLOCK_TYPE_ORDER,
					'order_item_id' => $orderItemId,
					'confirmed_at' => current_time('mysql')
				])
			],
			['id' => $blockId],
			['%s', '%d', '%d', '%s', '%s'],
			['%d']
		);

		return $result !== false;
	}

	/**
	 * Confirm seat for order (by seat/product/session)
	 *
	 * @param int $seatId Seat ID
	 * @param int $productId Product ID
	 * @param string $sessionId Session ID
	 * @param int $orderId Order ID
	 * @param int $orderItemId Order item ID
	 * @param int $codeId Code ID
	 * @param string|null $eventDate Event date
	 * @return bool Success
	 */
	public function confirmSeatForOrder(int $seatId, int $productId, string $sessionId, int $orderId, int $orderItemId, int $codeId, ?string $eventDate = null): bool {
		global $wpdb;

		$block = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->getTable($this->table)}
				WHERE seat_id = %d
				AND product_id = %d
				AND session_id = %s
				AND (event_date = %s OR event_date IS NULL)
				AND status = %s",
				$seatId,
				$productId,
				$sessionId,
				$eventDate,
				self::STATUS_BLOCKED
			),
			ARRAY_A
		);

		if (!$block) {
			// Create confirmed block directly if session block doesn't exist
			$result = $wpdb->insert(
				$this->getTable($this->table),
				[
					'time' => current_time('mysql'),
					'timezone' => wp_timezone_string(),
					'seat_id' => $seatId,
					'seatingplan_id' => $this->getSeatPlanId($seatId),
					'product_id' => $productId,
					'event_date' => $eventDate,
					'session_id' => $sessionId,
					'order_id' => $orderId,
					'code_id' => $codeId,
					'expires_at' => null,
					'status' => self::STATUS_CONFIRMED,
					'meta' => $this->MAIN->getCore()->json_encode_with_error_handling([
						'block_type' => self::BLOCK_TYPE_ORDER,
						'order_item_id' => $orderItemId,
						'confirmed_at' => current_time('mysql')
					])
				],
				['%s', '%s', '%d', '%d', '%d', '%s', '%s', '%d', '%d', '%s', '%s', '%s']
			);
			return $result !== false;
		}

		return $this->confirmBlock((int) $block['id'], $orderId, $orderItemId, $codeId);
	}

	/**
	 * Release seat by code ID (for refunds)
	 *
	 * @param int $codeId Ticket code ID
	 * @return bool Success
	 */
	public function releaseSeatByCodeId(int $codeId): bool {
		global $wpdb;

		$result = $wpdb->delete(
			$this->getTable($this->table),
			['code_id' => $codeId],
			['%d']
		);

		return $result !== false;
	}

	/**
	 * Release seat by order ID (for order cancellation)
	 *
	 * @param int $orderId Order ID
	 * @return int Number of released seats
	 */
	public function releaseSeatsByOrderId(int $orderId): int {
		global $wpdb;

		return $wpdb->delete(
			$this->getTable($this->table),
			['order_id' => $orderId],
			['%d']
		);
	}

	/**
	 * Delete all blocks for a seating plan
	 *
	 * @param int $planId Seating plan ID
	 * @return int Number of deleted blocks
	 */
	public function deleteByPlanId(int $planId): int {
		global $wpdb;

		return (int) $wpdb->delete(
			$this->getTable($this->table),
			['seatingplan_id' => $planId],
			['%d']
		);
	}

	/**
	 * Delete all blocks for a seat
	 *
	 * @param int $seatId Seat ID
	 * @return int Number of deleted blocks
	 */
	public function deleteBySeatId(int $seatId): int {
		global $wpdb;

		return (int) $wpdb->delete(
			$this->getTable($this->table),
			['seat_id' => $seatId],
			['%d']
		);
	}

	/**
	 * Check if seat is available
	 *
	 * @param int $seatId Seat ID
	 * @param int $productId Product ID
	 * @param string|null $eventDate Event date
	 * @param string|null $excludeSessionId Session ID to exclude (allow own blocks)
	 * @return bool True if available
	 */
	public function isSeatAvailable(int $seatId, int $productId, ?string $eventDate = null, ?string $excludeSessionId = null): bool {
		global $wpdb;

		// Validate IDs
		if ($seatId <= 0 || $productId <= 0) {
			$this->MAIN->getDB()->logError('isSeatAvailable: Invalid IDs - seat: ' . $seatId . ', product: ' . $productId);
			return false;
		}

		$now = current_time('mysql');
		$staleTimeout = $this->getStaleTimeout();

		// Build stale condition
		$staleCondition = '';
		if ($staleTimeout > 0) {
			$staleTime = date('Y-m-d H:i:s', current_time('timestamp') - $staleTimeout);
			$staleCondition = $wpdb->prepare(" AND (last_seen IS NULL OR last_seen > %s)", $staleTime);
		}

		// Check for active blocks: confirmed OR (blocked AND not expired AND not stale)
		// Optionally exclude blocks from the current session (user's own blocks are allowed)
		if ($excludeSessionId) {
			$count = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$this->getTable($this->table)}
					WHERE seat_id = %d
					AND product_id = %d
					AND (event_date = %s OR event_date IS NULL)
					AND session_id != %s
					AND (
						status = %s
						OR (status = %s AND expires_at > %s{$staleCondition})
					)",
					$seatId,
					$productId,
					$eventDate,
					$excludeSessionId,
					self::STATUS_CONFIRMED,
					self::STATUS_BLOCKED,
					$now
				)
			);
		} else {
			$count = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$this->getTable($this->table)}
					WHERE seat_id = %d
					AND product_id = %d
					AND (event_date = %s OR event_date IS NULL)
					AND (
						status = %s
						OR (status = %s AND expires_at > %s{$staleCondition})
					)",
					$seatId,
					$productId,
					$eventDate,
					self::STATUS_CONFIRMED,
					self::STATUS_BLOCKED,
					$now
				)
			);
		}

		return (int) $count === 0;
	}

	/**
	 * Get available seats for a plan/product/date
	 *
	 * @param int $planId Seating plan ID
	 * @param int $productId Product ID
	 * @param string|null $eventDate Event date
	 * @return array Array of available seat IDs
	 */
	public function getAvailableSeatIds(int $planId, int $productId, ?string $eventDate = null): array {
		global $wpdb;

		// Get blocked seat IDs (includes time check)
		$blockedIds = $this->getBlockedSeatIds($planId, $productId, $eventDate);

		// Get all active seats for plan, excluding blocked ones
		$sql = $wpdb->prepare(
			"SELECT id FROM {$this->getTable('seats')}
			WHERE seatingplan_id = %d AND aktiv = 1",
			$planId
		);

		if (!empty($blockedIds)) {
			$placeholders = implode(',', array_fill(0, count($blockedIds), '%d'));
			$sql .= $wpdb->prepare(" AND id NOT IN ($placeholders)", ...$blockedIds);
		}

		$sql .= " ORDER BY sort_order ASC";

		return $wpdb->get_col($sql);
	}

	/**
	 * Get blocked seat IDs for a plan/product/date
	 *
	 * @param int $planId Seating plan ID
	 * @param int $productId Product ID
	 * @param string|null $eventDate Event date
	 * @return array Array of blocked seat IDs
	 */
	public function getBlockedSeatIds(int $planId, int $productId, ?string $eventDate = null): array {
		global $wpdb;

		$now = current_time('mysql');
		$staleTimeout = $this->getStaleTimeout();

		// Build stale condition
		$staleCondition = '';
		if ($staleTimeout > 0) {
			$staleTime = date('Y-m-d H:i:s', current_time('timestamp') - $staleTimeout);
			$staleCondition = $wpdb->prepare(" AND (last_seen IS NULL OR last_seen > %s)", $staleTime);
		}

		// Get seats that are: confirmed OR (blocked AND not expired AND not stale)
		return $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT seat_id FROM {$this->getTable($this->table)}
				WHERE seatingplan_id = %d
				AND product_id = %d
				AND (event_date = %s OR event_date IS NULL)
				AND (
					status = %s
					OR (status = %s AND expires_at > %s{$staleCondition})
				)",
				$planId,
				$productId,
				$eventDate,
				self::STATUS_CONFIRMED,
				self::STATUS_BLOCKED,
				$now
			)
		);
	}

	/**
	 * Clean expired blocks
	 *
	 * @param int $limit Max rows to delete per call
	 * @return int Number of deleted blocks
	 */
	public function cleanExpiredBlocks(int $limit = 500): int {
		global $wpdb;

		$deleted = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$this->getTable($this->table)}
				WHERE status = %s
				AND expires_at IS NOT NULL
				AND expires_at < %s
				LIMIT %d",
				self::STATUS_BLOCKED,
				current_time('mysql'),
				$limit
			)
		);

		return (int) $deleted;
	}

	/**
	 * Get block timeout in minutes
	 *
	 * @return int Timeout in minutes
	 */
	private function getBlockTimeout(): int {
		$timeout = $this->MAIN->getOptions()->getOptionValue('seatingBlockTimeout');
		return !empty($timeout) ? (int) $timeout : self::DEFAULT_BLOCK_TIMEOUT_MINUTES;
	}

	/**
	 * Get heartbeat stale timeout in seconds
	 *
	 * @return int Timeout in seconds (0 = disabled)
	 */
	private function getStaleTimeout(): int {
		$timeout = $this->MAIN->getOptions()->getOptionValue('seatingHeartbeatStaleTimeout');
		return !empty($timeout) ? (int) $timeout : 60;
	}

	/**
	 * Build SQL condition for "active" blocks (not expired AND not stale)
	 *
	 * A block is active if:
	 * - status = 'confirmed', OR
	 * - status = 'blocked' AND not expired AND (no stale timeout OR last_seen is recent OR last_seen is NULL for new blocks)
	 *
	 * @param string $tableAlias Table alias (e.g., 'sb' or empty)
	 * @return string SQL condition
	 */
	private function getActiveBlockCondition(string $tableAlias = ''): string {
		$prefix = $tableAlias ? "{$tableAlias}." : '';
		$now = current_time('mysql');
		$staleTimeout = $this->getStaleTimeout();

		// Base condition: confirmed OR (blocked AND not expired)
		$condition = "({$prefix}status = '" . self::STATUS_CONFIRMED . "' OR ({$prefix}status = '" . self::STATUS_BLOCKED . "' AND {$prefix}expires_at > '{$now}'";

		// Add stale check if enabled
		if ($staleTimeout > 0) {
			$staleTime = date('Y-m-d H:i:s', current_time('timestamp') - $staleTimeout);
			// Block is NOT stale if: last_seen is NULL (new block, heartbeat not yet received) OR last_seen is recent
			$condition .= " AND ({$prefix}last_seen IS NULL OR {$prefix}last_seen > '{$staleTime}')";
		}

		$condition .= "))";

		return $condition;
	}

	/**
	 * Get seating plan ID for a seat
	 *
	 * @param int $seatId Seat ID
	 * @return int|null Plan ID or null
	 */
	private function getSeatPlanId(int $seatId): ?int {
		global $wpdb;

		$planId = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT seatingplan_id FROM {$this->getTable('seats')} WHERE id = %d",
				$seatId
			)
		);

		return $planId !== null ? (int) $planId : null;
	}

	/**
	 * Get block by ID
	 *
	 * @param int $blockId Block ID
	 * @return array|null Block data
	 */
	public function getById(int $blockId): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->getTable($this->table)} WHERE id = %d",
				$blockId
			),
			ARRAY_A
		);

		if ($row) {
			$row['meta'] = $this->decodeAndMergeMeta($row['meta']);
		}

		return $row;
	}

	/**
	 * Get confirmed count for plan/date
	 *
	 * @param int $planId Plan ID
	 * @param string|null $eventDate Event date
	 * @return int Count
	 */
	public function getConfirmedCount(int $planId, ?string $eventDate = null): int {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$this->getTable($this->table)}
				WHERE seatingplan_id = %d
				AND (event_date = %s OR event_date IS NULL)
				AND status = %s",
				$planId,
				$eventDate,
				self::STATUS_CONFIRMED
			)
		);
	}

	/**
	 * Get blocked count for plan/date
	 *
	 * @param int $planId Plan ID
	 * @param string|null $eventDate Event date
	 * @return int Count
	 */
	public function getBlockedCount(int $planId, ?string $eventDate = null): int {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$this->getTable($this->table)}
				WHERE seatingplan_id = %d
				AND (event_date = %s OR event_date IS NULL)
				AND status = %s
				AND expires_at > %s",
				$planId,
				$eventDate,
				self::STATUS_BLOCKED,
				current_time('mysql')
			)
		);
	}

	/**
	 * Get all seats with status for plan/product/date
	 *
	 * @param int $planId Plan ID
	 * @param int $productId Product ID
	 * @param string|null $eventDate Event date
	 * @return array Array of seats with status
	 */
	public function getSeatsWithStatus(int $planId, int $productId, ?string $eventDate = null): array {
		global $wpdb;

		$now = current_time('mysql');
		$staleTimeout = $this->getStaleTimeout();

		// Build stale condition for the JOIN
		$staleCondition = '';
		if ($staleTimeout > 0) {
			$staleTime = date('Y-m-d H:i:s', current_time('timestamp') - $staleTimeout);
			$staleCondition = $wpdb->prepare(" AND (sb.last_seen IS NULL OR sb.last_seen > %s)", $staleTime);
		}

		// Get all seats with active blocks (confirmed OR blocked+not expired+not stale)
		$seats = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT s.*, sb.status as block_status, sb.session_id, sb.order_id
				FROM {$this->getTable('seats')} s
				LEFT JOIN {$this->getTable($this->table)} sb
					ON s.id = sb.seat_id
					AND sb.product_id = %d
					AND (sb.event_date = %s OR sb.event_date IS NULL)
					AND (
						sb.status = %s
						OR (sb.status = %s AND sb.expires_at > %s{$staleCondition})
					)
				WHERE s.seatingplan_id = %d AND s.aktiv = 1
				ORDER BY s.sort_order ASC",
				$productId,
				$eventDate,
				self::STATUS_CONFIRMED,
				self::STATUS_BLOCKED,
				$now,
				$planId
			),
			ARRAY_A
		);

		// Get SeatManager for proper meta merging with defaults
		$seatManager = $this->MAIN->getSeating()->getSeatManager();

		foreach ($seats as &$seat) {
			// Use SeatManager's prepareSeatMeta to ensure all default fields exist
			$seat['meta'] = $seatManager->prepareSeatMeta($seat['meta'], $seat['seat_identifier'] ?? '');
			$seat['availability'] = $seat['block_status'] === null ? 'free' :
				($seat['block_status'] === self::STATUS_CONFIRMED ? 'sold' : 'blocked');
		}

		return $seats;
	}

	/**
	 * Get changes since timestamp (for Live Monitor)
	 *
	 * @param int $planId Plan ID
	 * @param string|null $eventDate Event date
	 * @param string $sinceTimestamp Timestamp to get changes since
	 * @return array Array of changes
	 */
	public function getChangesSince(int $planId, ?string $eventDate, string $sinceTimestamp): array {
		global $wpdb;

		if (empty($sinceTimestamp)) {
			return [];
		}

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT sb.*, s.seat_identifier, s.meta as seat_meta
				FROM {$this->getTable($this->table)} sb
				JOIN {$this->getTable('seats')} s ON sb.seat_id = s.id
				WHERE sb.seatingplan_id = %d
				AND (sb.event_date = %s OR sb.event_date IS NULL)
				AND sb.time > %s
				ORDER BY sb.time DESC
				LIMIT 50",
				$planId,
				$eventDate,
				$sinceTimestamp
			),
			ARRAY_A
		);
	}
}
