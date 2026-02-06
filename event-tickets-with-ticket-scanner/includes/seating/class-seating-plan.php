<?php
/**
 * Seating Plan CRUD Class
 *
 * Handles all CRUD operations for seating plans.
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
 * Seating Plan Manager
 *
 * Provides CRUD operations for seatingplans table.
 *
 * @since 2.8.0
 */
class sasoEventtickets_Seating_Plan extends sasoEventtickets_Seating_Base {

	/**
	 * Table name
	 *
	 * @var string
	 */
	private string $table = 'seatingplans';

	/**
	 * Get meta object structure for seating plans
	 *
	 * @return array Meta object structure with all defaults
	 */
	public function getMetaObject(): array {
		$metaObj = [
			'description' => '',
			'total_capacity' => 0,
			'layout_type' => self::LAYOUT_SIMPLE,
			// Venue photo is stored in 'image_id' (set via plan edit modal)
			// Visual Designer settings
			'canvas_width' => 800,
			'canvas_height' => 600,
			'background_color' => '#ffffff',
			'background_image' => '',
			// Status colors (configurable)
			'colors' => [
				'available' => '#4CAF50',  // Green
				'reserved' => '#FFC107',   // Yellow
				'booked' => '#F44336',     // Red
				'selected' => '#2196F3'    // Blue
			],
			// Visual elements (stored in meta_draft/meta_published)
			'decorations' => [],  // Non-seat shapes
			'lines' => [],        // Room boundaries, aisles
			'labels' => []        // Text labels
		];

		// Premium hook - can add fields without basic plugin update
		if ($this->MAIN->isPremium() && method_exists($this->MAIN->getPremiumFunctions(), 'getSeatingPlanMetaObject')) {
			$metaObj = $this->MAIN->getPremiumFunctions()->getSeatingPlanMetaObject($metaObj);
		}

		return $metaObj;
	}

	/**
	 * Create a new seating plan
	 *
	 * @param array $data Plan data: name, aktiv, meta (optional)
	 * @return int|false Plan ID on success, false on failure
	 * @throws Exception If limit reached or validation fails
	 */
	public function create(array $data) {
		global $wpdb;

		// Validate required fields
		if (empty($data['name'])) {
			throw new Exception(__('Plan name is required.', 'event-tickets-with-ticket-scanner'));
		}

		// Check free version limit via existing infrastructure
		$currentCount = $this->getCount();
		$maxPlans = $this->MAIN->getBase()->getMaxValue('seatingplans', 1);

		// maxValue = 0 means unlimited (Premium)
		if ($maxPlans > 0 && $currentCount >= $maxPlans) {
			throw new Exception(
				sprintf(
					__('Limit reached (%d plans). Upgrade to Premium for unlimited seating plans.', 'event-tickets-with-ticket-scanner'),
					$maxPlans
				)
			);
		}

		// Check name uniqueness
		if ($this->nameExists($data['name'])) {
			throw new Exception(__('A plan with this name already exists.', 'event-tickets-with-ticket-scanner'));
		}

		// Merge input meta with defaults
		$meta = array_replace_recursive($this->getMetaObject(), $data['meta'] ?? []);
		$metaJson = $this->MAIN->getCore()->json_encode_with_error_handling($meta);

		// Get current user for audit trail
		$currentUserId = get_current_user_id();
		$now = current_time('mysql');

		$result = $wpdb->insert(
			$this->getTable($this->table),
			[
				'time' => $now,
				'timezone' => wp_timezone_string(),
				'name' => sanitize_text_field($data['name']),
				'aktiv' => isset($data['aktiv']) ? (int) $data['aktiv'] : 0,
				'meta' => $metaJson,
				'layout_type' => sanitize_text_field($data['layout_type'] ?? self::LAYOUT_SIMPLE),
				'meta_draft' => $metaJson,
				'meta_published' => '', // Empty until first publish
				'created_by' => $currentUserId,
				'updated_by' => $currentUserId,
				'created_at' => $now,
				'updated_at' => $now
			],
			['%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s']
		);

		if ($result === false) {
			return false;
		}

		return $wpdb->insert_id;
	}

	/**
	 * Get a seating plan by ID
	 *
	 * @param int $id Plan ID
	 * @return array|null Plan data or null if not found
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
			$row['meta'] = $this->decodeAndMergeMeta($row['meta']);
		}

		return $row;
	}

	/**
	 * Get complete seating plan object with all data
	 *
	 * Returns the full plan including draft, published, seats, and meta info.
	 * Use this for admin/designer - it loads everything in one call.
	 *
	 * @param int $id Plan ID
	 * @return array|null Complete plan object or null if not found
	 */
	public function getFullPlan(int $id): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->getTable($this->table)} WHERE id = %d",
				$id
			),
			ARRAY_A
		);

		if (!$row) {
			return null;
		}

		// Parse meta fields
		$meta = $this->decodeAndMergeMeta($row['meta'] ?? '');
		$draft = $this->decodeAndMergeMeta($row['meta_draft']);
		$published = !empty($row['meta_published']) ? $this->decodeAndMergeMeta($row['meta_published']) : [];

		// Get seats
		$seats = $this->MAIN->getSeating()->getSeatManager()->getByPlanId($id);

		// Get user names for audit info
		$createdByName = '';
		$updatedByName = '';
		$publishedByName = '';

		if (!empty($row['created_by'])) {
			$user = get_userdata($row['created_by']);
			$createdByName = $user ? $user->display_name : '';
		}
		if (!empty($row['updated_by'])) {
			$user = get_userdata($row['updated_by']);
			$updatedByName = $user ? $user->display_name : '';
		}
		if (!empty($row['published_by'])) {
			$user = get_userdata($row['published_by']);
			$publishedByName = $user ? $user->display_name : '';
		}

		// Check for unpublished changes
		$hasUnpublishedChanges = ($row['meta_draft'] !== $row['meta_published']);

		return [
			'id' => (int) $row['id'],
			'name' => $row['name'],
			'aktiv' => (bool) $row['aktiv'],
			'layout_type' => $row['layout_type'] ?? 'simple',
			'meta' => $meta,
			'draft' => $draft,
			'published' => $published,
			'seats' => $seats,
			'has_unpublished_changes' => $hasUnpublishedChanges,
			'publish_info' => [
				'published_at' => $row['published_at'],
				'published_by' => (int) $row['published_by'],
				'published_by_name' => $publishedByName
			],
			'audit_info' => [
				'created_at' => $row['created_at'],
				'created_by' => (int) $row['created_by'],
				'created_by_name' => $createdByName,
				'updated_at' => $row['updated_at'],
				'updated_by' => (int) $row['updated_by'],
				'updated_by_name' => $updatedByName
			],
			'active_sales' => $this->getActiveSalesInfo($id)
		];
	}

	/**
	 * Get a seating plan by name
	 *
	 * @param string $name Plan name
	 * @return array|null Plan data or null if not found
	 */
	public function getByName(string $name): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->getTable($this->table)} WHERE name = %s",
				$name
			),
			ARRAY_A
		);

		if ($row) {
			$row['meta'] = $this->decodeAndMergeMeta($row['meta']);
		}

		return $row;
	}

	/**
	 * Get all seating plans
	 *
	 * @param bool $activeOnly Only return active plans
	 * @return array Array of plan data
	 */
	public function getAll(bool $activeOnly = false): array {
		global $wpdb;

		$sql = "SELECT * FROM {$this->getTable($this->table)}";
		if ($activeOnly) {
			$sql .= " WHERE aktiv = 1";
		}
		$sql .= " ORDER BY name ASC";

		$rows = $wpdb->get_results($sql, ARRAY_A);

		foreach ($rows as &$row) {
			$row['meta'] = $this->decodeAndMergeMeta($row['meta']);
		}

		return $rows ?: [];
	}

	/**
	 * Update a seating plan
	 *
	 * @param int $id Plan ID
	 * @param array $data Data to update
	 * @return bool Success
	 * @throws Exception If validation fails
	 */
	public function update(int $id, array $data): bool {
		global $wpdb;

		$existing = $this->getById($id);
		if (!$existing) {
			throw new Exception(__('Seating plan not found.', 'event-tickets-with-ticket-scanner'));
		}

		$updateData = [];
		$updateFormats = [];

		// Update name if provided
		if (isset($data['name']) && $data['name'] !== $existing['name']) {
			if ($this->nameExists($data['name'], $id)) {
				throw new Exception(__('A plan with this name already exists.', 'event-tickets-with-ticket-scanner'));
			}
			$updateData['name'] = sanitize_text_field($data['name']);
			$updateFormats[] = '%s';
		}

		// Update aktiv if provided
		if (isset($data['aktiv'])) {
			$updateData['aktiv'] = (int) $data['aktiv'];
			$updateFormats[] = '%d';
		}

		// Update layout_type if provided
		if (isset($data['layout_type'])) {
			$validTypes = [self::LAYOUT_SIMPLE, self::LAYOUT_VISUAL];
			$layoutType = in_array($data['layout_type'], $validTypes, true)
				? $data['layout_type']
				: self::LAYOUT_SIMPLE;
			$updateData['layout_type'] = $layoutType;
			$updateFormats[] = '%s';
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
	 * Delete a seating plan
	 *
	 * @param int $id Plan ID
	 * @param bool $force Also delete associated seats and blocks
	 * @return bool Success
	 * @throws Exception If plan has seats and force is false
	 */
	public function delete(int $id, bool $force = false): bool {
		global $wpdb;

		// Check for associated seats
		$seatCount = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$this->getTable('seats')} WHERE seatingplan_id = %d",
				$id
			)
		);

		if ($seatCount > 0 && !$force) {
			throw new Exception(
				sprintf(
					__('Cannot delete plan with %d seats. Use force delete or remove seats first.', 'event-tickets-with-ticket-scanner'),
					$seatCount
				)
			);
		}

		// If force, delete seats and blocks first (delegated to SeatManager)
		if ($force) {
			$this->MAIN->getSeating()->getSeatManager()->deleteByPlanId($id);
		}

		$result = $wpdb->delete(
			$this->getTable($this->table),
			['id' => $id],
			['%d']
		);

		return $result !== false;
	}

	/**
	 * Get count of seating plans
	 *
	 * @return int Count
	 */
	public function getCount(): int {
		global $wpdb;

		return (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$this->getTable($this->table)}"
		);
	}

	/**
	 * Check if a plan name already exists
	 *
	 * @param string $name Plan name
	 * @param int|null $excludeId Exclude this ID from check (for updates)
	 * @return bool True if name exists
	 */
	public function nameExists(string $name, ?int $excludeId = null): bool {
		global $wpdb;

		$sql = $wpdb->prepare(
			"SELECT COUNT(*) FROM {$this->getTable($this->table)} WHERE name = %s",
			$name
		);

		if ($excludeId !== null) {
			$sql = $wpdb->prepare(
				"SELECT COUNT(*) FROM {$this->getTable($this->table)} WHERE name = %s AND id != %d",
				$name,
				$excludeId
			);
		}

		return (int) $wpdb->get_var($sql) > 0;
	}

	/**
	 * Update layout type for a plan
	 *
	 * @param int $planId Plan ID
	 * @param string $layoutType Layout type (simple|visual)
	 * @return bool Success
	 */
	public function updateLayoutType(int $planId, string $layoutType): bool {
		$validTypes = [self::LAYOUT_SIMPLE, self::LAYOUT_VISUAL];
		if (!in_array($layoutType, $validTypes, true)) {
			$layoutType = self::LAYOUT_SIMPLE;
		}

		return $this->update($planId, [
			'meta' => ['layout_type' => $layoutType]
		]);
	}

	/**
	 * Get plans as dropdown options
	 *
	 * @param bool $includeEmpty Include empty option
	 * @param bool $showLayoutType Show layout type in label
	 * @param bool $showDraftStatus Show draft/published status
	 * @return array Key-value pairs for dropdown
	 */
	public function getDropdownOptions(bool $includeEmpty = true, bool $showLayoutType = true, bool $showDraftStatus = true): array {
		$plans = $this->getAll(true);
		$options = [];

		if ($includeEmpty) {
			$options[''] = __('-- Select Seating Plan --', 'event-tickets-with-ticket-scanner');
		}

		foreach ($plans as $plan) {
			$label = $plan['name'];

			// Layout type (stored in separate DB column, not in meta JSON)
			if ($showLayoutType) {
				$layoutType = $plan['layout_type'] ?? self::LAYOUT_SIMPLE;
				$layoutLabel = $layoutType === self::LAYOUT_VISUAL
					? __('Visual', 'event-tickets-with-ticket-scanner')
					: __('Simple', 'event-tickets-with-ticket-scanner');
				$label .= ' [' . $layoutLabel . ']';
			}

			// Draft/Published status
			if ($showDraftStatus) {
				$isPublished = !empty($plan['published_at']);
				$hasChanges = !empty($plan['meta_draft']) && $plan['meta_draft'] !== ($plan['meta_published'] ?? '');

				if (!$isPublished) {
					// Never published - Draft only
					$label .= ' ⚠️ ' . __('Not published yet', 'event-tickets-with-ticket-scanner');
				} elseif ($hasChanges) {
					// Published but has unpublished changes
					$label .= ' 📝 ' . __('Unpublished changes', 'event-tickets-with-ticket-scanner');
				}
			}

			$options[$plan['id']] = $label;
		}

		return $options;
	}

	// =========================================================================
	// Draft/Publish Workflow Methods (Visual Designer)
	// =========================================================================

	/**
	 * Save draft changes for visual designer
	 *
	 * @param int $planId Plan ID
	 * @param array $draftData Draft data (decorations, lines, labels, colors, etc.)
	 * @return bool Success
	 * @throws Exception If plan not found
	 */
	public function saveDraft(int $planId, array $draftData): bool {
		global $wpdb;

		$existing = $this->getById($planId);
		if (!$existing) {
			throw new Exception(__('Seating plan not found.', 'event-tickets-with-ticket-scanner'));
		}

		// Merge with existing meta defaults
		$currentDraft = $this->getDraftMeta($planId);
		$newDraft = array_replace_recursive($currentDraft, $draftData);

		$draftJson = $this->MAIN->getCore()->json_encode_with_error_handling($newDraft);

		$result = $wpdb->update(
			$this->getTable($this->table),
			[
				'meta_draft' => $draftJson,
				// Note: meta column contains plan config (name, layout, background_image)
				// and should NOT be overwritten by draft designer data
				'updated_by' => get_current_user_id(),
				'updated_at' => current_time('mysql')
			],
			['id' => $planId],
			['%s', '%d', '%s'],
			['%d']
		);

		return $result !== false;
	}

	/**
	 * Publish draft to make it visible to customers
	 *
	 * @param int $planId Plan ID
	 * @return array Result with success status and any conflicts
	 * @throws Exception If validation fails or conflicts exist
	 */
	public function publish(int $planId): array {
		global $wpdb;

		$existing = $this->getById($planId);
		if (!$existing) {
			throw new Exception(__('Seating plan not found.', 'event-tickets-with-ticket-scanner'));
		}

		// Check for conflicts: seats with sold tickets that are being deleted
		$conflicts = $this->checkPublishConflicts($planId);
		if (!empty($conflicts)) {
			return [
				'success' => false,
				'conflicts' => $conflicts,
				'message' => __('Cannot publish: Some seats have sold tickets.', 'event-tickets-with-ticket-scanner')
			];
		}

		$currentUserId = get_current_user_id();
		$now = current_time('mysql');

		// Copy draft to published
		$result = $wpdb->update(
			$this->getTable($this->table),
			[
				'meta_published' => $existing['meta_draft'] ?? '',
				'published_at' => $now,
				'published_by' => $currentUserId,
				'updated_by' => $currentUserId,
				'updated_at' => $now
			],
			['id' => $planId],
			['%s', '%s', '%d', '%d', '%s'],
			['%d']
		);

		if ($result === false) {
			throw new Exception(__('Failed to publish seating plan.', 'event-tickets-with-ticket-scanner'));
		}

		// Sync seats table (soft-delete removed seats, create new ones)
		$this->syncSeatsOnPublish($planId);

		return [
			'success' => true,
			'published_at' => $now,
			'message' => __('Seating plan published successfully.', 'event-tickets-with-ticket-scanner')
		];
	}

	/**
	 * Discard draft changes and revert to published version
	 *
	 * @param int $planId Plan ID
	 * @return bool Success
	 * @throws Exception If plan not found
	 */
	public function discardDraft(int $planId): bool {
		global $wpdb;

		$existing = $this->getById($planId);
		if (!$existing) {
			throw new Exception(__('Seating plan not found.', 'event-tickets-with-ticket-scanner'));
		}

		// Revert draft to published version
		$result = $wpdb->update(
			$this->getTable($this->table),
			[
				'meta_draft' => $existing['meta_published'] ?? '',
				'meta' => $existing['meta_published'] ?? '',
				'updated_by' => get_current_user_id(),
				'updated_at' => current_time('mysql')
			],
			['id' => $planId],
			['%s', '%s', '%d', '%s'],
			['%d']
		);

		return $result !== false;
	}

	/**
	 * Check if plan has been published at least once
	 *
	 * @param int $planId Plan ID
	 * @return bool True if plan has published version
	 */
	public function isPublished(int $planId): bool {
		global $wpdb;

		$publishedAt = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT published_at FROM {$this->getTable($this->table)} WHERE id = %d",
				$planId
			)
		);

		return !empty($publishedAt);
	}

	/**
	 * Check if plan has unpublished changes
	 *
	 * @param int $planId Plan ID
	 * @return bool True if there are unpublished changes
	 */
	public function hasUnpublishedChanges(int $planId): bool {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT meta_draft, meta_published FROM {$this->getTable($this->table)} WHERE id = %d",
				$planId
			),
			ARRAY_A
		);

		if (!$row) {
			return false;
		}

		// If published is empty, there are definitely unpublished changes
		if (empty($row['meta_published'])) {
			return !empty($row['meta_draft']);
		}

		// Compare draft and published
		return $row['meta_draft'] !== $row['meta_published'];
	}

	/**
	 * Get draft meta data for editing
	 *
	 * @param int $planId Plan ID
	 * @return array Draft meta data merged with defaults
	 */
	public function getDraftMeta(int $planId): array {
		global $wpdb;

		$draftJson = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT meta_draft FROM {$this->getTable($this->table)} WHERE id = %d",
				$planId
			)
		);

		return $this->decodeAndMergeMeta($draftJson);
	}

	/**
	 * Get published meta data for frontend display
	 *
	 * @param int $planId Plan ID
	 * @return array Published meta data merged with defaults
	 */
	public function getPublishedMeta(int $planId): array {
		global $wpdb;

		$publishedJson = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT meta_published FROM {$this->getTable($this->table)} WHERE id = %d",
				$planId
			)
		);

		return $this->decodeAndMergeMeta($publishedJson);
	}

	/**
	 * Get publish info (when and by whom)
	 *
	 * @param int $planId Plan ID
	 * @return array|null Publish info or null if never published
	 */
	public function getPublishInfo(int $planId): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT published_at, published_by FROM {$this->getTable($this->table)} WHERE id = %d",
				$planId
			),
			ARRAY_A
		);

		if (!$row || empty($row['published_at'])) {
			return null;
		}

		$user = get_user_by('id', $row['published_by']);

		return [
			'published_at' => $row['published_at'],
			'published_by' => $row['published_by'],
			'published_by_name' => $user ? $user->display_name : __('Unknown', 'event-tickets-with-ticket-scanner')
		];
	}

	/**
	 * Get audit trail info (created/updated by whom)
	 *
	 * @param int $planId Plan ID
	 * @return array Audit info
	 */
	public function getAuditInfo(int $planId): array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT created_by, created_at, updated_by, updated_at, published_by, published_at
				FROM {$this->getTable($this->table)} WHERE id = %d",
				$planId
			),
			ARRAY_A
		);

		if (!$row) {
			return [];
		}

		$createdUser = get_user_by('id', $row['created_by']);
		$updatedUser = get_user_by('id', $row['updated_by']);
		$publishedUser = $row['published_by'] ? get_user_by('id', $row['published_by']) : null;

		return [
			'created_at' => $row['created_at'],
			'created_by' => $row['created_by'],
			'created_by_name' => $createdUser ? $createdUser->display_name : __('Unknown', 'event-tickets-with-ticket-scanner'),
			'updated_at' => $row['updated_at'],
			'updated_by' => $row['updated_by'],
			'updated_by_name' => $updatedUser ? $updatedUser->display_name : __('Unknown', 'event-tickets-with-ticket-scanner'),
			'published_at' => $row['published_at'],
			'published_by' => $row['published_by'],
			'published_by_name' => $publishedUser ? $publishedUser->display_name : null
		];
	}

	/**
	 * Check for conflicts when publishing (seats with sold tickets being deleted)
	 *
	 * @param int $planId Plan ID
	 * @return array Array of conflicts (seat identifier => ticket count)
	 */
	protected function checkPublishConflicts(int $planId): array {
		// Get seats marked for deletion in draft that have active tickets
		$seatManager = $this->MAIN->getSeating()->getSeatManager();
		$conflicts = [];

		// Get all seats for this plan
		$seats = $seatManager->getByPlanId($planId, false); // Include soft-deleted

		foreach ($seats as $seat) {
			// Check if seat is marked for deletion in the new draft
			// but has active (non-refunded) tickets
			if (!empty($seat['is_deleted']) || $this->isSeatRemovedInDraft($planId, $seat['seat_identifier'])) {
				$ticketCount = $this->countActiveTicketsForSeat($planId, $seat['id']);
				if ($ticketCount > 0) {
					$conflicts[$seat['seat_identifier']] = $ticketCount;
				}
			}
		}

		return $conflicts;
	}

	/**
	 * Check if a seat identifier was removed in the draft
	 *
	 * @param int $planId Plan ID
	 * @param string $identifier Seat identifier
	 * @return bool True if seat was removed in draft
	 */
	protected function isSeatRemovedInDraft(int $planId, string $identifier): bool {
		// This would compare draft seats with current seats
		// For now, return false - will be implemented when Visual Designer saves seats
		return false;
	}

	/**
	 * Count active (sold, non-refunded) tickets for a seat
	 *
	 * @param int $planId Plan ID
	 * @param int $seatId Seat ID
	 * @return int Number of active tickets
	 */
	protected function countActiveTicketsForSeat(int $planId, int $seatId): int {
		global $wpdb;

		// Check seat_blocks for confirmed bookings
		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$this->getTable('seat_blocks')}
				WHERE seat_id = %d AND seatingplan_id = %d AND status = 'confirmed'",
				$seatId,
				$planId
			)
		);

		return (int) $count;
	}

	/**
	 * Sync seats table after publishing
	 * Creates new seats, soft-deletes removed seats
	 *
	 * @param int $planId Plan ID
	 */
	protected function syncSeatsOnPublish(int $planId): void {
		// This will be called after publish to sync the seats table
		// with the published design. New seats get created, removed
		// seats get soft-deleted (is_deleted = 1).
		// Implementation depends on how Visual Designer stores seat data.

		// For now, this is a placeholder. The actual implementation
		// will parse the published meta and sync seats accordingly.
	}

	/**
	 * Get products using this seating plan
	 *
	 * @param int $planId Plan ID
	 * @return array Array of product data (id, name, ticket_count)
	 */
	public function getLinkedProducts(int $planId): array {
		global $wpdb;

		// Find WooCommerce products that use this seating plan
		$products = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.ID, p.post_title
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
				WHERE pm.meta_key = %s
				AND pm.meta_value = %d
				AND p.post_type IN ('product', 'product_variation')
				AND p.post_status = 'publish'",
				$this->getMetaProductSeatingplan(),
				$planId
			),
			ARRAY_A
		);

		$result = [];
		foreach ($products as $product) {
			// Count sold tickets for this product
			$ticketCount = $this->countTicketsForProduct($product['ID']);
			$result[] = [
				'id' => $product['ID'],
				'name' => $product['post_title'],
				'ticket_count' => $ticketCount
			];
		}

		return $result;
	}

	/**
	 * Count sold tickets for a product
	 *
	 * @param int $productId WooCommerce product ID
	 * @return int Number of sold tickets
	 */
	protected function countTicketsForProduct(int $productId): int {
		global $wpdb;

		// Count confirmed seat blocks for this product
		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$this->getTable('seat_blocks')}
				WHERE product_id = %d AND status = 'confirmed'",
				$productId
			)
		);

		return (int) $count;
	}

	/**
	 * Check if plan has active sales (products with sold tickets)
	 *
	 * @param int $planId Plan ID
	 * @return array Info about active sales
	 */
	public function getActiveSalesInfo(int $planId): array {
		$products = $this->getLinkedProducts($planId);
		$totalTickets = 0;
		$activeProducts = [];

		foreach ($products as $product) {
			if ($product['ticket_count'] > 0) {
				$activeProducts[] = $product;
				$totalTickets += $product['ticket_count'];
			}
		}

		return [
			'has_active_sales' => $totalTickets > 0,
			'total_tickets' => $totalTickets,
			'products' => $activeProducts
		];
	}

	/**
	 * Clone/duplicate a seating plan with all its seats
	 *
	 * Creates a copy of the plan including all seats and visual layout.
	 * The new plan will have "(Copy)" appended to the name.
	 *
	 * @param int $sourcePlanId ID of the plan to clone
	 * @param string|null $newName Optional custom name for the cloned plan
	 * @return int New plan ID
	 * @throws Exception If source plan not found or clone fails
	 * @since 2.8.2
	 */
	public function clonePlan(int $sourcePlanId, ?string $newName = null): int {
		global $wpdb;

		// Get source plan
		$sourcePlan = $this->getById($sourcePlanId);
		if (!$sourcePlan) {
			throw new Exception(__('Source seating plan not found.', 'event-tickets-with-ticket-scanner'));
		}

		// Generate unique name if not provided
		if (empty($newName)) {
			$newName = $this->generateUniqueCopyName($sourcePlan['name']);
		} else {
			// Ensure provided name is unique
			if ($this->nameExists($newName)) {
				$newName = $this->generateUniqueCopyName($newName);
			}
		}

		// Create new plan with copied data
		$newPlanId = $this->create([
			'name' => $newName,
			'aktiv' => 0, // Start as inactive
			'layout_type' => $sourcePlan['layout_type'] ?? self::LAYOUT_SIMPLE,
			'meta' => $sourcePlan['meta']
		]);

		if (!$newPlanId) {
			throw new Exception(__('Failed to create cloned seating plan.', 'event-tickets-with-ticket-scanner'));
		}

		// Copy draft and published meta if they exist
		$now = current_time('mysql');
		$currentUserId = get_current_user_id();

		$wpdb->update(
			$this->getTable($this->table),
			[
				'meta_draft' => $sourcePlan['meta_draft'] ?? '',
				'meta_published' => '', // Don't copy published state - new plan starts unpublished
				'updated_at' => $now,
				'updated_by' => $currentUserId
			],
			['id' => $newPlanId],
			['%s', '%s', '%s', '%d'],
			['%d']
		);

		// Copy all seats
		$seatManager = $this->MAIN->getSeating()->getSeatManager();
		$sourceSeats = $seatManager->getByPlanId($sourcePlanId);

		foreach ($sourceSeats as $seat) {
			$seatManager->create($newPlanId, [
				'seat_identifier' => $seat['seat_identifier'],
				'aktiv' => $seat['aktiv'],
				'sort_order' => $seat['sort_order'],
				'meta' => $seat['meta']
			]);
		}

		return $newPlanId;
	}

	/**
	 * Generate a unique copy name for a plan
	 *
	 * Appends "(Copy)", "(Copy 2)", etc. until a unique name is found.
	 *
	 * @param string $baseName Original plan name
	 * @return string Unique name
	 */
	private function generateUniqueCopyName(string $baseName): string {
		// Remove existing "(Copy X)" suffix if present
		$baseName = preg_replace('/\s*\(Copy(?:\s+\d+)?\)\s*$/', '', $baseName);

		$copyName = $baseName . ' (Copy)';
		$counter = 2;

		while ($this->nameExists($copyName)) {
			$copyName = $baseName . ' (Copy ' . $counter . ')';
			$counter++;

			// Safety limit
			if ($counter > 100) {
				$copyName = $baseName . ' (Copy ' . uniqid() . ')';
				break;
			}
		}

		return $copyName;
	}
}
