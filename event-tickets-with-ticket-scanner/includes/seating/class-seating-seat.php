<?php
/**
 * Seating Seat CRUD Class
 *
 * Handles all CRUD operations for individual seats.
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
 * Seating Seat Manager
 *
 * Provides CRUD operations for seats table.
 *
 * @since 2.8.0
 */
class sasoEventtickets_Seating_Seat extends sasoEventtickets_Seating_Base {

	/**
	 * Table name
	 *
	 * @var string
	 */
	private string $table = 'seats';

	/**
	 * Get meta object structure for seats
	 *
	 * @return array Meta object structure with all defaults
	 */
	public function getMetaObject(): array {
		$metaObj = [
			'seat_label' => '',
			'seat_category' => '',
			'description' => '',
			'price_modifier' => 0.00,
			'capacity' => 1,
			'pos_x' => 0,
			'pos_y' => 0,
			'rotation' => 0,
			'shape_type' => 'rect',
			'shape_config' => ['width' => 30, 'height' => 30],
			'color' => '#4CAF50'
		];

		// Premium hook - can add fields without basic plugin update
		if ($this->MAIN->isPremium() && method_exists($this->MAIN->getPremiumFunctions(), 'getSeatingSeatMetaObject')) {
			$metaObj = $this->MAIN->getPremiumFunctions()->getSeatingSeatMetaObject($metaObj);
		}

		return $metaObj;
	}

	/**
	 * Prepare seat meta from raw DB data
	 *
	 * Public method for use by other seating classes (e.g. Block).
	 * Merges stored meta JSON with defaults and sets seat_label fallback.
	 *
	 * @param string|null $metaJson JSON meta string from DB
	 * @param string $seatIdentifier Seat identifier for label fallback
	 * @return array Prepared meta array
	 */
	public function prepareSeatMeta(?string $metaJson, string $seatIdentifier = ''): array {
		$meta = $this->decodeAndMergeMeta($metaJson);

		// Use seat_identifier as label fallback if seat_label is empty
		if (empty($meta['seat_label']) && !empty($seatIdentifier)) {
			$meta['seat_label'] = $seatIdentifier;
		}

		return $meta;
	}

	/**
	 * Create a new seat
	 *
	 * @param int $planId Seating plan ID
	 * @param array $data Seat data: seat_identifier, aktiv, sort_order, meta (optional)
	 * @return int|false Seat ID on success, false on failure
	 * @throws Exception If limit reached or validation fails
	 */
	public function create(int $planId, array $data) {
		global $wpdb;

		// Validate required fields
		if (empty($data['seat_identifier'])) {
			throw new Exception(__('Seat identifier is required.', 'event-tickets-with-ticket-scanner'));
		}

		// Check if plan exists
		$planExists = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$this->getTable('seatingplans')} WHERE id = %d",
				$planId
			)
		);

		if (!$planExists) {
			throw new Exception(__('Seating plan not found.', 'event-tickets-with-ticket-scanner'));
		}

		// Check free version limit (seats per plan) via existing infrastructure
		$currentCount = $this->getCountForPlan($planId);
		$maxSeats = $this->MAIN->getBase()->getMaxValue('seats_per_plan', 20);

		// maxValue = 0 means unlimited (Premium)
		if ($maxSeats > 0 && $currentCount >= $maxSeats) {
			throw new Exception(
				sprintf(
					__('Limit reached (%d seats per plan). Upgrade to Premium for unlimited seats.', 'event-tickets-with-ticket-scanner'),
					$maxSeats
				)
			);
		}

		// Check identifier uniqueness within plan
		if ($this->identifierExistsInPlan($planId, $data['seat_identifier'])) {
			throw new Exception(__('A seat with this identifier already exists in this plan.', 'event-tickets-with-ticket-scanner'));
		}

		// Merge input meta with defaults
		$meta = array_replace_recursive(
			$this->getMetaObject(),
			$data['meta'] ?? []
		);

		// Set seat_label to identifier if not explicitly provided
		if (empty($meta['seat_label'])) {
			$meta['seat_label'] = $data['seat_identifier'];
		}

		// Get next sort order if not provided
		$sortOrder = $data['sort_order'] ?? $this->getNextSortOrder($planId);

		// Audit trail
		$currentUserId = get_current_user_id();
		$now = current_time('mysql');

		$result = $wpdb->insert(
			$this->getTable($this->table),
			[
				'time' => $now,
				'timezone' => wp_timezone_string(),
				'seatingplan_id' => $planId,
				'seat_identifier' => sanitize_text_field($data['seat_identifier']),
				'aktiv' => isset($data['aktiv']) ? (int) $data['aktiv'] : 1,
				'sort_order' => (int) $sortOrder,
				'meta' => $this->MAIN->getCore()->json_encode_with_error_handling($meta),
				'is_deleted' => 0,
				'created_by' => $currentUserId,
				'updated_by' => $currentUserId,
				'created_at' => $now,
				'updated_at' => $now
			],
			['%s', '%s', '%d', '%s', '%d', '%d', '%s', '%d', '%d', '%d', '%s', '%s']
		);

		if ($result === false) {
			return false;
		}

		return $wpdb->insert_id;
	}

	/**
	 * Create multiple seats at once (bulk import)
	 *
	 * Accepts either:
	 * - Array of strings (identifiers only, legacy)
	 * - Array of seat objects with identifier, label, category
	 *
	 * @param int $planId Seating plan ID
	 * @param array $seats Array of seat identifiers (strings) or seat objects
	 * @return array Array of created seat IDs
	 * @throws Exception If limit would be exceeded
	 */
	public function createBulk(int $planId, array $seats): array {
		$currentCount = $this->getCountForPlan($planId);
		$newCount = count($seats);

		// Check if total would exceed limit via existing infrastructure
		$maxSeats = $this->MAIN->getBase()->getMaxValue('seats_per_plan', 20);

		// maxValue = 0 means unlimited (Premium)
		if ($maxSeats > 0 && ($currentCount + $newCount) > $maxSeats) {
			throw new Exception(
				sprintf(
					__('Cannot add %d seats. Limit is %d seats per plan. Currently have %d.', 'event-tickets-with-ticket-scanner'),
					$newCount,
					$maxSeats,
					$currentCount
				)
			);
		}

		$createdIds = [];
		$sortOrder = $this->getNextSortOrder($planId);

		foreach ($seats as $seat) {
			// Support both string (legacy) and object format
			if (is_string($seat)) {
				$identifier = trim($seat);
				$seatData = [
					'seat_identifier' => $identifier,
					'sort_order' => $sortOrder++
				];
			} else {
				// Object format: {identifier, label, category}
				$identifier = isset($seat['identifier']) ? trim($seat['identifier']) : '';
				$seatData = [
					'seat_identifier' => $identifier,
					'sort_order' => $sortOrder++,
					'meta' => [
						'seat_label' => isset($seat['label']) ? sanitize_text_field($seat['label']) : '',
						'seat_category' => isset($seat['category']) ? sanitize_text_field($seat['category']) : ''
					]
				];
			}

			if (empty($identifier)) {
				continue;
			}

			// Skip duplicates
			if ($this->identifierExistsInPlan($planId, $identifier)) {
				continue;
			}

			try {
				$id = $this->create($planId, $seatData);
				if ($id) {
					$createdIds[] = $id;
				}
			} catch (Exception $e) {
				// Skip failed ones, continue with others
				continue;
			}
		}

		return $createdIds;
	}

	/**
	 * Get a seat by ID
	 *
	 * @param int $id Seat ID
	 * @return array|null Seat data or null if not found
	 */
	public function getById(int $id): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->getTable($this->table)} WHERE id = %d",
				$id
			),
			ARRAY_A
		);

		if ($row) {
			$row['meta'] = $this->prepareSeatMeta($row['meta'], $row['seat_identifier'] ?? '');
		}

		return $row;
	}

	/**
	 * Get seating plan ID for a seat
	 *
	 * @param int $seatId Seat ID
	 * @return int|null Seating plan ID or null if not found
	 */
	public function getSeatingPlanIdForSeatId(int $seatId): ?int {
		$seatId = intval($seatId);
		if ($seatId <= 0) {
			return null;
		}

		global $wpdb;

		$planId = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT seatingplan_id FROM {$this->getTable($this->table)} WHERE id = %d",
				$seatId
			)
		);

		return $planId;
	}

	/**
	 * Get all seats for a plan
	 *
	 * @param int $planId Seating plan ID
	 * @param bool $activeOnly Only return active seats (also excludes soft-deleted)
	 * @param bool $includeDeleted Include soft-deleted seats
	 * @return array Array of seat data
	 */
	public function getByPlanId(int $planId, bool $activeOnly = false, bool $includeDeleted = false): array {
		global $wpdb;

		$sql = $wpdb->prepare(
			"SELECT * FROM {$this->getTable($this->table)} WHERE seatingplan_id = %d",
			$planId
		);

		// Exclude soft-deleted by default
		if (!$includeDeleted) {
			$sql .= " AND is_deleted = 0";
		}

		if ($activeOnly) {
			$sql .= " AND aktiv = 1";
		}

		$sql .= " ORDER BY sort_order ASC, seat_identifier ASC";

		$rows = $wpdb->get_results($sql, ARRAY_A);

		foreach ($rows as &$row) {
			$row['meta'] = $this->prepareSeatMeta($row['meta'], $row['seat_identifier'] ?? '');
		}

		return $rows ?: [];
	}

	/**
	 * Update one or multiple seats with the same data
	 *
	 * @param array $data Data to update (same for all seats)
	 * @param array|int $ids Single ID or array of seat IDs
	 * @return array Results array with success/error for each seat
	 */
	public function update(array $data, $ids): array {
		// Normalize IDs to array
		if (!is_array($ids)) {
			$ids = [$ids];
		}

		$results = [];
		foreach ($ids as $id) {
			$id = (int) $id;
			if ($id <= 0) {
				$results[] = [
					'id' => $id,
					'success' => false,
					'error' => __('Invalid seat ID', 'event-tickets-with-ticket-scanner')
				];
				continue;
			}

			try {
				$success = $this->updateSingle($id, $data);
				$results[] = ['id' => $id, 'success' => $success];
			} catch (Exception $e) {
				$results[] = ['id' => $id, 'success' => false, 'error' => $e->getMessage()];
			}
		}

		return $results;
	}

	/**
	 * Update a single seat (internal method)
	 *
	 * @param int $id Seat ID
	 * @param array $data Data to update
	 * @return bool Success
	 * @throws Exception If validation fails
	 */
	private function updateSingle(int $id, array $data): bool {
		global $wpdb;

		$existing = $this->getById($id);
		if (!$existing) {
			throw new Exception(__('Seat not found.', 'event-tickets-with-ticket-scanner'));
		}

		$updateData = [];
		$updateFormats = [];

		// Update seat_identifier if provided
		if (isset($data['seat_identifier']) && $data['seat_identifier'] !== $existing['seat_identifier']) {
			if ($this->identifierExistsInPlan($existing['seatingplan_id'], $data['seat_identifier'], $id)) {
				throw new Exception(__('A seat with this identifier already exists in this plan.', 'event-tickets-with-ticket-scanner'));
			}
			$updateData['seat_identifier'] = sanitize_text_field($data['seat_identifier']);
			$updateFormats[] = '%s';
		}

		// Update aktiv if provided
		if (isset($data['aktiv'])) {
			$updateData['aktiv'] = (int) $data['aktiv'];
			$updateFormats[] = '%d';
		}

		// Update sort_order if provided
		if (isset($data['sort_order'])) {
			$updateData['sort_order'] = (int) $data['sort_order'];
			$updateFormats[] = '%d';
		}

		// Update meta if provided - merge recursively to preserve nested structures
		if (isset($data['meta'])) {
			$newMeta = array_replace_recursive($existing['meta'], $data['meta']);
			$updateData['meta'] = $this->MAIN->getCore()->json_encode_with_error_handling($newMeta);
			$updateFormats[] = '%s';
		}

		if (empty($updateData)) {
			return true; // Nothing to update
		}

		// Always add audit trail
		$updateData['updated_by'] = get_current_user_id();
		$updateFormats[] = '%d';
		$updateData['updated_at'] = current_time('mysql');
		$updateFormats[] = '%s';

		$result = $wpdb->update(
			$this->getTable($this->table),
			$updateData,
			['id' => $id],
			$updateFormats,
			['%d']
		);

		return $result !== false;
	}

	/**
	 * Soft-delete one or multiple seats (mark as deleted but keep in database)
	 *
	 * Used by Visual Designer when seats are removed during edit.
	 * Soft-deleted seats can be restored if edit is discarded.
	 *
	 * @param array|int $ids Single ID or array of seat IDs
	 * @return array Results with success/error for each seat
	 */
	public function softDelete($ids): array {
		// Normalize to array
		if (!is_array($ids)) {
			$ids = [$ids];
		}

		$results = [];
		foreach ($ids as $id) {
			$id = (int) $id;
			if ($id <= 0) {
				$results[] = ['id' => $id, 'success' => false, 'error' => __('Invalid seat ID', 'event-tickets-with-ticket-scanner')];
				continue;
			}

			try {
				$success = $this->softDeleteSingle($id);
				$results[] = ['id' => $id, 'success' => $success];
			} catch (Exception $e) {
				$results[] = ['id' => $id, 'success' => false, 'error' => $e->getMessage()];
			}
		}

		return $results;
	}

	/**
	 * Soft-delete a single seat (internal)
	 *
	 * @param int $id Seat ID
	 * @return bool Success
	 * @throws Exception If seat not found
	 */
	private function softDeleteSingle(int $id): bool {
		global $wpdb;

		$existing = $this->getById($id);
		if (!$existing) {
			throw new Exception(__('Seat not found.', 'event-tickets-with-ticket-scanner'));
		}

		$currentUserId = get_current_user_id();
		$now = current_time('mysql');

		$result = $wpdb->update(
			$this->getTable($this->table),
			[
				'is_deleted' => 1,
				'deleted_at' => $now,
				'deleted_by' => $currentUserId,
				'updated_by' => $currentUserId,
				'updated_at' => $now
			],
			['id' => $id],
			['%d', '%s', '%d', '%d', '%s'],
			['%d']
		);

		return $result !== false;
	}

	/**
	 * Restore a soft-deleted seat
	 *
	 * @param int $id Seat ID
	 * @return bool Success
	 * @throws Exception If seat not found
	 */
	public function restore(int $id): bool {
		global $wpdb;

		$result = $wpdb->update(
			$this->getTable($this->table),
			[
				'is_deleted' => 0,
				'deleted_at' => null,
				'deleted_by' => null,
				'updated_by' => get_current_user_id(),
				'updated_at' => current_time('mysql')
			],
			['id' => $id],
			['%d', '%s', '%d', '%d', '%s'],
			['%d']
		);

		return $result !== false;
	}

	/**
	 * Get audit trail info for a seat
	 *
	 * @param int $id Seat ID
	 * @return array Audit info
	 */
	public function getAuditInfo(int $id): array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT created_by, created_at, updated_by, updated_at, deleted_by, deleted_at, is_deleted
				FROM {$this->getTable($this->table)} WHERE id = %d",
				$id
			),
			ARRAY_A
		);

		if (!$row) {
			return [];
		}

		$createdUser = $row['created_by'] ? get_user_by('id', $row['created_by']) : null;
		$updatedUser = $row['updated_by'] ? get_user_by('id', $row['updated_by']) : null;
		$deletedUser = $row['deleted_by'] ? get_user_by('id', $row['deleted_by']) : null;

		return [
			'created_at' => $row['created_at'],
			'created_by' => $row['created_by'],
			'created_by_name' => $createdUser ? $createdUser->display_name : null,
			'updated_at' => $row['updated_at'],
			'updated_by' => $row['updated_by'],
			'updated_by_name' => $updatedUser ? $updatedUser->display_name : null,
			'is_deleted' => (bool) $row['is_deleted'],
			'deleted_at' => $row['deleted_at'],
			'deleted_by' => $row['deleted_by'],
			'deleted_by_name' => $deletedUser ? $deletedUser->display_name : null
		];
	}

	/**
	 * Delete one or multiple seats
	 *
	 * @param array|int $ids Single ID or array of seat IDs
	 * @param bool $force Also delete associated blocks
	 * @return array Results with success/error for each seat
	 */
	public function delete($ids, bool $force = false): array {
		// Normalize to array
		if (!is_array($ids)) {
			$ids = [$ids];
		}

		$results = [];
		foreach ($ids as $id) {
			$id = (int) $id;
			if ($id <= 0) {
				$results[] = ['id' => $id, 'success' => false, 'error' => __('Invalid seat ID', 'event-tickets-with-ticket-scanner')];
				continue;
			}

			try {
				$success = $this->deleteSingle($id, $force);
				$results[] = ['id' => $id, 'success' => $success];
			} catch (Exception $e) {
				$results[] = ['id' => $id, 'success' => false, 'error' => $e->getMessage()];
			}
		}

		return $results;
	}

	/**
	 * Delete a single seat (internal)
	 *
	 * @param int $id Seat ID
	 * @param bool $force Also delete associated blocks
	 * @return bool Success
	 * @throws Exception If seat has confirmed blocks and force is false
	 */
	private function deleteSingle(int $id, bool $force = false): bool {
		global $wpdb;

		// Check for confirmed blocks
		$confirmedBlocks = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$this->getTable('seat_blocks')}
				WHERE seat_id = %d AND status = %s",
				$id,
				self::STATUS_CONFIRMED
			)
		);

		if ($confirmedBlocks > 0 && !$force) {
			throw new Exception(
				__('Cannot delete seat with confirmed bookings. Cancel orders first or use force delete.', 'event-tickets-with-ticket-scanner')
			);
		}

		// Delete blocks first (delegated to BlockManager)
		$this->MAIN->getSeating()->getBlockManager()->deleteBySeatId($id);

		$result = $wpdb->delete(
			$this->getTable($this->table),
			['id' => $id],
			['%d']
		);

		return $result !== false;
	}

	/**
	 * Delete all seats for a plan (with their blocks)
	 *
	 * Used by Plan::delete($force=true).
	 *
	 * @param int $planId Seating plan ID
	 * @return int Number of deleted seats
	 */
	public function deleteByPlanId(int $planId): int {
		global $wpdb;

		// Delete blocks first (delegated to BlockManager)
		$this->MAIN->getSeating()->getBlockManager()->deleteByPlanId($planId);

		// Delete all seats
		return (int) $wpdb->delete(
			$this->getTable($this->table),
			['seatingplan_id' => $planId],
			['%d']
		);
	}

	/**
	 * Get count of seats for a plan
	 *
	 * @param int $planId Seating plan ID
	 * @return int Count
	 */
	public function getCountForPlan(int $planId): int {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$this->getTable($this->table)} WHERE seatingplan_id = %d",
				$planId
			)
		);
	}

	/**
	 * Check if seat identifier exists in plan
	 *
	 * @param int $planId Seating plan ID
	 * @param string $identifier Seat identifier
	 * @param int|null $excludeId Exclude this ID from check (for updates)
	 * @return bool True if identifier exists
	 */
	public function identifierExistsInPlan(int $planId, string $identifier, ?int $excludeId = null): bool {
		global $wpdb;

		$sql = $wpdb->prepare(
			"SELECT COUNT(*) FROM {$this->getTable($this->table)}
			WHERE seatingplan_id = %d AND seat_identifier = %s",
			$planId,
			$identifier
		);

		if ($excludeId !== null) {
			$sql = $wpdb->prepare(
				"SELECT COUNT(*) FROM {$this->getTable($this->table)}
				WHERE seatingplan_id = %d AND seat_identifier = %s AND id != %d",
				$planId,
				$identifier,
				$excludeId
			);
		}

		return (int) $wpdb->get_var($sql) > 0;
	}

	/**
	 * Get next sort order for a plan
	 *
	 * @param int $planId Seating plan ID
	 * @return int Next sort order value
	 */
	private function getNextSortOrder(int $planId): int {
		global $wpdb;

		$maxOrder = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT MAX(sort_order) FROM {$this->getTable($this->table)} WHERE seatingplan_id = %d",
				$planId
			)
		);

		return ($maxOrder !== null) ? ((int) $maxOrder + 1) : 0;
	}

	/**
	 * Get seat by identifier within a plan
	 *
	 * @param int $planId Seating plan ID
	 * @param string $identifier Seat identifier
	 * @return array|null Seat data or null if not found
	 */
	public function getByIdentifier(int $planId, string $identifier): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->getTable($this->table)}
				WHERE seatingplan_id = %d AND seat_identifier = %s",
				$planId,
				$identifier
			),
			ARRAY_A
		);

		if ($row) {
			$row['meta'] = $this->prepareSeatMeta($row['meta'], $row['seat_identifier'] ?? '');
		}

		return $row;
	}

	/**
	 * Get seats as dropdown options
	 *
	 * @param int $planId Seating plan ID
	 * @param bool $includeEmpty Include empty option
	 * @return array Key-value pairs for dropdown
	 */
	public function getDropdownOptions(int $planId, bool $includeEmpty = true): array {
		$seats = $this->getByPlanId($planId, true);
		$options = [];

		if ($includeEmpty) {
			$options[''] = __('-- Select Seat --', 'event-tickets-with-ticket-scanner');
		}

		foreach ($seats as $seat) {
			$label = $seat['meta']['seat_label'] ?? $seat['seat_identifier'];
			if (!empty($seat['meta']['seat_category'])) {
				$label .= ' (' . $seat['meta']['seat_category'] . ')';
			}
			$options[$seat['id']] = $label;
		}

		return $options;
	}

	/**
	 * Update visual position for a seat
	 *
	 * @param int $seatId Seat ID
	 * @param float $posX X position
	 * @param float $posY Y position
	 * @return array Results
	 */
	public function updatePosition(int $seatId, float $posX, float $posY): array {
		return $this->update([
			'meta' => [
				'pos_x' => $posX,
				'pos_y' => $posY
			]
		], $seatId);
	}

	/**
	 * Upgrade simple seats to visual layout
	 *
	 * Generates grid positions for existing seats
	 *
	 * @param int $planId Seating plan ID
	 * @param int $seatsPerRow Seats per row in grid
	 * @param int $startX Starting X position
	 * @param int $startY Starting Y position
	 * @param int $spacing Spacing between seats
	 * @return bool Success
	 */
	public function upgradeToVisual(int $planId, int $seatsPerRow = 10, int $startX = 50, int $startY = 50, int $spacing = 40): bool {
		$seats = $this->getByPlanId($planId);
		$col = 0;
		$row = 0;

		foreach ($seats as $seat) {
			$posX = $startX + ($col * $spacing);
			$posY = $startY + ($row * $spacing);

			$this->update([
				'meta' => [
					'pos_x' => $posX,
					'pos_y' => $posY,
					'shape_type' => 'rect',
					'shape_config' => ['width' => 30, 'height' => 30]
				]
			], $seat['id']);

			$col++;
			if ($col >= $seatsPerRow) {
				$col = 0;
				$row++;
			}
		}

		return true;
	}
}
