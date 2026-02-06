<?php
/**
 * Seating Admin Handler
 *
 * Handles admin UI for seating plan management.
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
 * Seating Admin Class
 *
 * Provides admin UI for managing seating plans and seats.
 *
 * @since 2.8.0
 */
class sasoEventtickets_Seating_Admin extends sasoEventtickets_Seating_Base {

	/**
	 * Constructor
	 *
	 * @param sasoEventtickets $main Main plugin instance
	 */
	public function __construct($main) {
		parent::__construct($main);
		$this->initHooks();
	}

	/**
	 * Initialize WordPress hooks
	 *
	 * Note: AJAX handler is registered in index.php via executeSeatingAdmin_a()
	 * This follows the same pattern as executeAdminSettings for consistency
	 */
	private function initHooks(): void {
		// No direct AJAX registration here - handled via index.php dispatcher
	}

	/**
	 * Get meta object structure - Admin class has no meta fields
	 *
	 * @return array Empty array as Admin is UI-only
	 */
	public function getMetaObject(): array {
		return [];
	}

	/**
	 * Sanitize meta input from request data
	 *
	 * Only sanitizes user-provided values - does NOT set defaults.
	 * Defaults are handled by PlanManager::create() via getMetaObject().
	 *
	 * @param array $data Request data
	 * @return array Sanitized meta values (only keys that were provided)
	 */
	private function sanitizeMetaInput(array $data): array {
		$meta = [];

		// Basic fields
		if (isset($data['description'])) {
			$meta['description'] = sanitize_textarea_field($data['description']);
		}
		if (isset($data['image_id'])) {
			$meta['image_id'] = absint($data['image_id']);
		}

		// Canvas settings (only if provided)
		if (isset($data['canvas_width'])) {
			$meta['canvas_width'] = absint($data['canvas_width']);
		}
		if (isset($data['canvas_height'])) {
			$meta['canvas_height'] = absint($data['canvas_height']);
		}
		if (isset($data['background_color'])) {
			$color = sanitize_hex_color($data['background_color']);
			if ($color) {
				$meta['background_color'] = $color;
			}
		}

		// Colors (only if provided)
		$colorKeys = ['color_available', 'color_reserved', 'color_booked', 'color_selected'];
		$colorMap = ['color_available' => 'available', 'color_reserved' => 'reserved', 'color_booked' => 'booked', 'color_selected' => 'selected'];
		foreach ($colorKeys as $key) {
			if (isset($data[$key])) {
				$color = sanitize_hex_color($data[$key]);
				if ($color) {
					$meta['colors'][$colorMap[$key]] = $color;
				}
			}
		}

		return $meta;
	}

	// =========================================================================
	// Main AJAX Dispatcher (Switch Pattern)
	// =========================================================================

	/**
	 * Execute seating admin JSON action
	 *
	 * Main dispatcher for all seating admin AJAX requests.
	 * Follows the same pattern as executeAdminSettings::executeJSON()
	 *
	 * @param string $a Action name
	 * @param array $data Request data (full $data array for Single Responsibility)
	 * @param bool $just_ret Return value instead of wp_send_json
	 * @param bool $skipNonceTest Skip nonce verification (default: false)
	 * @return mixed Result or wp_send_json response
	 */
	public function executeSeatingJSON(string $a, array $data = [], bool $just_ret = false, bool $skipNonceTest = false) {
		$ret = '';

		// Nonce verification
		if (!$skipNonceTest) {
			$nonce = SASO_EVENTTICKETS::getRequestPara('nonce');
			if (!wp_verify_nonce($nonce, $this->MAIN->_js_nonce)) {
				if (!wp_verify_nonce($nonce, 'wp_rest')) {
					if ($just_ret) {
						throw new Exception('Security check failed');
					}
					return wp_send_json_error('Security check failed');
				}
			}
		}

		// Permission check for admin operations
		if (!current_user_can('manage_options')) {
			if ($just_ret) {
				throw new Exception('Unauthorized');
			}
			return wp_send_json_error(['error' => 'unauthorized']);
		}

		try {
			switch (trim($a)) {
				// Plan operations
				case 'getPlans':
					$ret = $this->handleGetPlans($data);
					break;
				case 'getPlan':
					$ret = $this->handleGetPlan($data);
					break;
				case 'createPlan':
					$ret = $this->handleCreatePlan($data);
					break;
				case 'updatePlan':
					$ret = $this->handleUpdatePlan($data);
					break;
				case 'deletePlan':
					$ret = $this->handleDeletePlan($data);
					break;
				case 'clonePlan':
					$ret = $this->handleClonePlan($data);
					break;

				// Draft/Publish operations (Visual Designer)
				case 'saveDraft':
					$ret = $this->handleSaveDraft($data);
					break;
				case 'publishPlan':
					$ret = $this->handlePublishPlan($data);
					break;
				case 'discardDraft':
					$ret = $this->handleDiscardDraft($data);
					break;
				case 'getDesignerData':
					$ret = $this->handleGetDesignerData($data);
					break;
				case 'getPublishedData':
					$ret = $this->handleGetPublishedData($data);
					break;

				// Seat operations
				case 'getSeats':
					$ret = $this->handleGetSeats($data);
					break;
				case 'createSeat':
					$ret = $this->handleCreateSeat($data);
					break;
				case 'createSeatsBulk':
					$ret = $this->handleCreateSeatsBulk($data);
					break;
				case 'updateSeat':
					$ret = $this->handleUpdateSeat($data);
					break;
				case 'deleteSeat':
					$ret = $this->handleDeleteSeat($data);
					break;

				// Statistics
				case 'getStats':
					$ret = $this->handleGetStats($data);
					break;

				case 'getDesignerPage':
					$ret = $this->handleGetDesignerPage($data);
					break;

				// Premium delegation
				case 'premium':
					$ret = $this->executeSeatingJSONPremium($data);
					break;

				default:
					throw new Exception('#8501 Unknown seating action: ' . $a);
			}
		} catch (Exception $e) {
			$this->MAIN->getAdmin()->logErrorToDB($e, 'executeSeatingJSON', __FILE__ . ' on Line: ' . __LINE__);
			if ($just_ret) {
				throw $e;
			}
			return wp_send_json_error(['error' => $e->getMessage()]);
		}

		if ($just_ret) {
			return $ret;
		}
		return wp_send_json_success($ret);
	}

	/**
	 * Execute seating JSON action delegated to premium plugin
	 *
	 * @param array $data Request data with 'c' for premium action
	 * @return mixed Premium handler result
	 * @throws Exception If premium not active or action missing
	 */
	private function executeSeatingJSONPremium(array $data) {
		if (!$this->MAIN->isPremium() || !method_exists($this->MAIN->getPremiumFunctions(), 'executeSeatingJSON')) {
			throw new Exception('#8502 premium is not active or method not available');
		}
		if (!isset($data['c'])) {
			throw new Exception('#8503 premium action parameter is missing');
		}
		return $this->MAIN->getPremiumFunctions()->executeSeatingJSON($data['c'], $data);
	}

	// =========================================================================
	// Handler Methods (receive full $data for Single Responsibility)
	// =========================================================================

	/**
	 * Handle: Get all seating plans
	 *
	 * @param array $data Request data
	 * @return array Plans with seat counts and limits
	 */
	private function handleGetPlans(array $data): array {
		$plans = $this->MAIN->getSeating()->getPlanManager()->getAll();

		// Add seat count to each plan
		foreach ($plans as &$plan) {
			$plan['seat_count'] = $this->MAIN->getSeating()->getSeatManager()->getCountForPlan((int) $plan['id']);
		}

		$result = ['plans' => $plans];

		// Premium enrichment hook
		if ($this->MAIN->isPremium() && method_exists($this->MAIN->getPremiumFunctions(), 'handleGetPlans')) {
			$result = $this->MAIN->getPremiumFunctions()->handleGetPlans($result, $data);
		}

		return $result;
	}

	/**
	 * Handle: Get single seating plan
	 *
	 * @param array $data Request data with plan_id
	 * @return array Plan data
	 * @throws Exception If plan_id missing or not found
	 */
	private function handleGetPlan(array $data): array {
		$planId = isset($data['plan_id']) ? (int) $data['plan_id'] : 0;
		if (!$planId) {
			throw new Exception('#8510 missing plan_id');
		}

		$plan = $this->MAIN->getSeating()->getPlanManager()->getById($planId);
		if (!$plan) {
			throw new Exception('#8511 plan not found');
		}

		$plan['seat_count'] = $this->MAIN->getSeating()->getSeatManager()->getCountForPlan($planId);

		if ($this->MAIN->isPremium() && method_exists($this->MAIN->getPremiumFunctions(), 'handleGetPlan')) {
			$plan = $this->MAIN->getPremiumFunctions()->handleGetPlan($plan, $data);
		}

		return ['plan' => $plan];
	}

	private function sanitizePlanData($data) {
		// Only pass sanitized user input - update() merges with getMetaObject() defaults
		$planData = [
			'name' => sanitize_text_field($data['name'] ?? ''),
			'aktiv' => (!empty($data['aktiv']) && $data['aktiv'] != '0') ? 1 : 0,
			'layout_type' => sanitize_text_field($data['layout_type'] ?? ''),
			'meta' => $this->sanitizeMetaInput($data)
		];
		return $planData;
	}

	/**
	 * Sanitize seat data from admin form or designer
	 *
	 * Handles both key formats:
	 * - Admin: seat_identifier, seat_label, seat_category
	 * - Designer: identifier, label, category, pos_x, pos_y, shape_type, etc.
	 *
	 * @param array $data Raw seat data
	 * @return array Sanitized seat data ready for create/update
	 */
	private function sanitizeSeatData(array $data): array {
		// Handle both key formats (admin form vs designer)
		$identifier = $data['seat_identifier'] ?? $data['identifier'] ?? '';
		$label = $data['seat_label'] ?? $data['label'] ?? '';
		$category = $data['seat_category'] ?? $data['category'] ?? '';

		$seatData = [
			'seat_identifier' => sanitize_text_field($identifier),
			'aktiv' => isset($data['aktiv']) ? (int) $data['aktiv'] : 1,
			'meta' => [
				'seat_label' => sanitize_text_field($label),
				'seat_category' => sanitize_text_field($category),
				'seat_desc' => sanitize_textarea_field($data['seat_desc'] ?? '')
			]
		];

		// Designer-specific fields (position, shape, color)
		if (isset($data['pos_x'])) {
			$seatData['meta']['pos_x'] = floatval($data['pos_x']);
		}
		if (isset($data['pos_y'])) {
			$seatData['meta']['pos_y'] = floatval($data['pos_y']);
		}
		if (isset($data['rotation'])) {
			$seatData['meta']['rotation'] = intval($data['rotation']) % 360;
		}
		if (isset($data['shape_type'])) {
			$seatData['meta']['shape_type'] = sanitize_text_field($data['shape_type']);
		}
		if (isset($data['shape_config'])) {
			$seatData['meta']['shape_config'] = $data['shape_config'];
		}
		if (isset($data['color'])) {
			$seatData['meta']['color'] = sanitize_hex_color($data['color']) ?: '#4CAF50';
		}
		if (isset($data['description'])) {
			$seatData['meta']['description'] = sanitize_textarea_field($data['description']);
		}

		return $seatData;
	}
	/**
	 * Handle: Create seating plan
	 *
	 * @param array $data Request data with name, aktiv, description, layout_type, visual settings
	 * @return array Created plan info
	 * @throws Exception On create failure
	 */
	private function handleCreatePlan(array $data): array {
		// Only pass sanitized user input - create() merges with getMetaObject() defaults
		$planData = $this->sanitizePlanData($data);

		$planId = $this->MAIN->getSeating()->getPlanManager()->create($planData);
		if (!$planId) {
			throw new Exception('#8520 create plan failed');
		}

		return [
			'plan_id' => $planId,
			'message' => __('Seating plan created successfully', 'event-tickets-with-ticket-scanner')
		];
	}

	/**
	 * Handle: Update seating plan
	 *
	 * @param array $data Request data with plan_id, name, aktiv, visual settings, etc.
	 * @return array Success message
	 * @throws Exception On update failure
	 */
	private function handleUpdatePlan(array $data): array {
		$planId = isset($data['plan_id']) ? (int) $data['plan_id'] : 0;
		if (!$planId) {
			throw new Exception('#8530 missing plan_id');
		}

		// Only pass sanitized user input - update() merges with getMetaObject() defaults
		$planData = $this->sanitizePlanData($data);

		$success = $this->MAIN->getSeating()->getPlanManager()->update($planId, $planData);
		if (!$success) {
			throw new Exception('#8531 update plan failed');
		}

		return ['message' => __('Seating plan updated successfully', 'event-tickets-with-ticket-scanner')];
	}

	/**
	 * Handle: Delete seating plan
	 *
	 * @param array $data Request data with plan_id, optional force
	 * @return array Success message
	 * @throws Exception On delete failure
	 */
	private function handleDeletePlan(array $data): array {
		$planId = isset($data['plan_id']) ? (int) $data['plan_id'] : 0;
		$force = isset($data['force']) && ($data['force'] === true || $data['force'] === 'true');

		if (!$planId) {
			throw new Exception('#8540 missing plan_id');
		}

		$success = $this->MAIN->getSeating()->getPlanManager()->delete($planId, $force);
		if (!$success) {
			throw new Exception('#8541 delete plan failed');
		}

		return ['message' => __('Seating plan deleted successfully', 'event-tickets-with-ticket-scanner')];
	}

	/**
	 * Handle: Clone/duplicate a seating plan
	 *
	 * @param array $data Request data with plan_id and optional new_name
	 * @return array Success info with new plan ID
	 * @throws Exception If plan_id missing or clone fails
	 * @since 2.8.2
	 */
	private function handleClonePlan(array $data): array {
		$planId = isset($data['plan_id']) ? (int) $data['plan_id'] : 0;
		$newName = isset($data['new_name']) ? sanitize_text_field($data['new_name']) : null;

		if (!$planId) {
			throw new Exception('#8545 missing plan_id');
		}

		$newPlanId = $this->MAIN->getSeating()->getPlanManager()->clonePlan($planId, $newName);

		// Get the new plan to return full info
		$newPlan = $this->MAIN->getSeating()->getPlanManager()->getById($newPlanId);

		return [
			'message' => __('Seating plan cloned successfully', 'event-tickets-with-ticket-scanner'),
			'plan_id' => $newPlanId,
			'plan' => $newPlan
		];
	}

	/**
	 * Handle: Get seats for a plan
	 *
	 * @param array $data Request data with plan_id
	 * @return array Seats and limits
	 * @throws Exception If plan_id missing
	 */
	private function handleGetSeats(array $data): array {
		$planId = isset($data['plan_id']) ? (int) $data['plan_id'] : 0;
		if (!$planId) {
			throw new Exception('#8550 missing plan_id');
		}

		$seats = $this->MAIN->getSeating()->getSeatManager()->getByPlanId($planId);

		$result = ['seats' => $seats];

		// Premium enrichment hook
		if ($this->MAIN->isPremium() && method_exists($this->MAIN->getPremiumFunctions(), 'enrichSeatingSeats')) {
			$result = $this->MAIN->getPremiumFunctions()->enrichSeatingSeats($result, $data);
		}

		return $result;
	}

	/**
	 * Handle: Create single seat
	 *
	 * @param array $data Request data with plan_id, seat_identifier, etc.
	 * @return array Created seat info
	 * @throws Exception On failure
	 */
	private function handleCreateSeat(array $data): array {
		$planId = isset($data['plan_id']) ? (int) $data['plan_id'] : 0;
		if (!$planId) {
			throw new Exception('#8560 missing plan_id');
		}

		$seatData = $this->sanitizeSeatData($data);
		$seatId = $this->MAIN->getSeating()->getSeatManager()->create($planId, $seatData);
		if (!$seatId) {
			throw new Exception('#8561 create seat failed');
		}

		return [
			'seat_id' => $seatId,
			'message' => __('Seat created successfully', 'event-tickets-with-ticket-scanner')
		];
	}

	/**
	 * Handle: Create multiple seats
	 *
	 * @param array $data Request data with plan_id, identifiers (newline-separated)
	 * @return array Created seat count and IDs
	 * @throws Exception On failure
	 */
	private function handleCreateSeatsBulk(array $data): array {
		$planId = isset($data['plan_id']) ? (int) $data['plan_id'] : 0;
		if (!$planId || empty($data['identifiers'])) {
			throw new Exception('#8570 missing plan_id or identifiers');
		}

		$identifiers = sanitize_textarea_field($data['identifiers']);
		$identifierList = array_filter(array_map('trim', explode("\n", $identifiers)));
		$createdIds = $this->MAIN->getSeating()->getSeatManager()->createBulk($planId, $identifierList);

		return [
			'created_count' => count($createdIds),
			'seat_ids' => $createdIds,
			'message' => sprintf(
				__('%d seats created successfully', 'event-tickets-with-ticket-scanner'),
				count($createdIds)
			)
		];
	}

	/**
	 * Handle: Update one or multiple seats
	 *
	 * Accepts:
	 * - Single: {seat_id: 1, ...fields}
	 * - Multiple: {seat_ids: [1, 2, 3], ...fields}
	 *
	 * Same fields applied to all IDs
	 *
	 * @param array $data Request data with fields and seat_id or seat_ids
	 * @return array Results
	 * @throws Exception On failure
	 */
	private function handleUpdateSeat(array $data): array {
		$seatManager = $this->MAIN->getSeating()->getSeatManager();

		// Get IDs (single or multiple)
		$ids = [];
		if (!empty($data['seat_ids'])) {
			$ids = $data['seat_ids'];
			if (is_string($ids)) {
				$ids = json_decode(wp_unslash($ids), true);
			}
		} elseif (!empty($data['seat_id'])) {
			$ids = [(int) $data['seat_id']];
		}

		if (empty($ids) || !is_array($ids)) {
			throw new Exception('#8580 missing seat_id or seat_ids');
		}

		// Sanitize update data
		$updateData = $this->sanitizeSeatData($data);

		// Handle simple field updates (aktiv, sort_order)
		if (isset($data['aktiv'])) {
			$updateData['aktiv'] = (int) $data['aktiv'];
		}
		if (isset($data['sort_order'])) {
			$updateData['sort_order'] = (int) $data['sort_order'];
		}

		$results = $seatManager->update($updateData, $ids);
		$successCount = count(array_filter($results, fn($r) => $r['success']));

		if (count($ids) === 1) {
			// Single seat response
			if (empty($results) || !$results[0]['success']) {
				$error = $results[0]['error'] ?? 'update failed';
				throw new Exception('#8581 ' . $error);
			}
			return ['message' => __('Seat updated successfully', 'event-tickets-with-ticket-scanner')];
		}

		// Multiple seats response
		return [
			'results' => $results,
			'success_count' => $successCount,
			'fail_count' => count($results) - $successCount,
			'message' => sprintf(__('%d seats updated successfully', 'event-tickets-with-ticket-scanner'), $successCount)
		];
	}

	/**
	 * Handle: Delete one or multiple seats
	 *
	 * Accepts:
	 * - Single: {seat_id: 1}
	 * - Multiple: {seat_ids: [1, 2, 3]}
	 *
	 * @param array $data Request data
	 * @return array Results
	 * @throws Exception On failure
	 */
	private function handleDeleteSeat(array $data): array {
		$seatManager = $this->MAIN->getSeating()->getSeatManager();
		$force = isset($data['force']) && ($data['force'] === true || $data['force'] === 'true');

		// Get IDs (single or multiple)
		$ids = [];
		if (!empty($data['seat_ids'])) {
			$ids = $data['seat_ids'];
			if (is_string($ids)) {
				$ids = json_decode(wp_unslash($ids), true);
			}
		} elseif (!empty($data['seat_id'])) {
			$ids = [(int) $data['seat_id']];
		}

		if (empty($ids) || !is_array($ids)) {
			throw new Exception('#8590 missing seat_id or seat_ids');
		}

		$results = $seatManager->delete($ids, $force);
		$successCount = count(array_filter($results, fn($r) => $r['success']));

		if (count($ids) === 1) {
			// Single seat response
			if (empty($results) || !$results[0]['success']) {
				$error = $results[0]['error'] ?? 'delete failed';
				throw new Exception('#8591 ' . $error);
			}
			return ['message' => __('Seat deleted successfully', 'event-tickets-with-ticket-scanner')];
		}

		// Multiple seats response
		return [
			'results' => $results,
			'success_count' => $successCount,
			'fail_count' => count($results) - $successCount,
			'message' => sprintf(__('%d seats deleted successfully', 'event-tickets-with-ticket-scanner'), $successCount)
		];
	}

	/**
	 * Handle: Get seating statistics
	 *
	 * @param array $data Request data with plan_id, optional product_id, event_date
	 * @return array Statistics
	 * @throws Exception If plan_id missing
	 */
	private function handleGetStats(array $data): array {
		$planId = isset($data['plan_id']) ? (int) $data['plan_id'] : 0;

		if (!$planId) {
			throw new Exception('#8595 missing plan_id');
		}

		$eventDate = isset($data['event_date']) ? sanitize_text_field($data['event_date']) : null;
		$stats = $this->MAIN->getSeating()->getStats($planId, $eventDate);
		$productId = isset($data['product_id']) ? (int) $data['product_id'] : 0;

		if ($productId) {
			$seats = $this->MAIN->getSeating()->getSeatsWithStatus($planId, $productId, $eventDate);
			$stats['seats'] = $seats;
		}

		$result = ['stats' => $stats];

		// Premium enrichment hook (e.g., for live monitor data)
		if ($this->MAIN->isPremium() && method_exists($this->MAIN->getPremiumFunctions(), 'enrichSeatingStats')) {
			$result = $this->MAIN->getPremiumFunctions()->enrichSeatingStats($result, $data);
		}

		return $result;
	}

	// =========================================================================
	// Draft/Publish Handler Methods (Visual Designer)
	// =========================================================================

	/**
	 * Handle: Save draft for visual designer
	 *
	 * @param array $data Request data with plan_id and draft content
	 * @return array Success message
	 * @throws Exception On failure
	 */
	private function handleSaveDraft(array $data): array {
		$planId = isset($data['plan_id']) ? (int) $data['plan_id'] : 0;
		if (!$planId) {
			throw new Exception('#8600 missing plan_id');
		}

		// Parse JSON strings from JS - use wp_unslash to remove WordPress magic quotes escaping
		$decorations = [];
		if (!empty($data['decorations'])) {
			$jsonStr = is_string($data['decorations']) ? wp_unslash($data['decorations']) : $data['decorations'];
			$decorations = is_string($jsonStr) ? json_decode($jsonStr, true) : $jsonStr;
			$decorations = is_array($decorations) ? $decorations : [];
		}

		$lines = [];
		if (!empty($data['lines'])) {
			$jsonStr = is_string($data['lines']) ? wp_unslash($data['lines']) : $data['lines'];
			$lines = is_string($jsonStr) ? json_decode($jsonStr, true) : $jsonStr;
			$lines = is_array($lines) ? $lines : [];
		}

		$labels = [];
		if (!empty($data['labels'])) {
			$jsonStr = is_string($data['labels']) ? wp_unslash($data['labels']) : $data['labels'];
			$labels = is_string($jsonStr) ? json_decode($jsonStr, true) : $jsonStr;
			$labels = is_array($labels) ? $labels : [];
		}

		$colors = null;
		if (!empty($data['colors'])) {
			$jsonStr = is_string($data['colors']) ? wp_unslash($data['colors']) : $data['colors'];
			$colors = is_string($jsonStr) ? json_decode($jsonStr, true) : $jsonStr;
		}

		$draftData = [
			'decorations' => $decorations,
			'lines' => $lines,
			'labels' => $labels,
			'canvas_width' => isset($data['canvas_width']) ? absint($data['canvas_width']) : null,
			'canvas_height' => isset($data['canvas_height']) ? absint($data['canvas_height']) : null,
			'background_color' => isset($data['background_color']) ? sanitize_hex_color($data['background_color']) : null,
			'background_image' => isset($data['background_image']) ? esc_url_raw($data['background_image']) : null,
			'background_image_id' => isset($data['background_image_id']) ? absint($data['background_image_id']) : null,
			'background_image_fit' => isset($data['background_image_fit']) ? sanitize_text_field($data['background_image_fit']) : null,
			'background_image_align' => isset($data['background_image_align']) ? sanitize_text_field($data['background_image_align']) : null
		];

		// Remove null values (but keep empty arrays)
		$draftData = array_filter($draftData, fn($v) => $v !== null);

		// Update colors if provided
		if (is_array($colors)) {
			$draftData['colors'] = [
				'available' => sanitize_hex_color($colors['available'] ?? '#4CAF50') ?: '#4CAF50',
				'reserved' => sanitize_hex_color($colors['reserved'] ?? '#FFC107') ?: '#FFC107',
				'booked' => sanitize_hex_color($colors['booked'] ?? '#F44336') ?: '#F44336',
				'selected' => sanitize_hex_color($colors['selected'] ?? '#2196F3') ?: '#2196F3'
			];
		}

		$success = $this->MAIN->getSeating()->getPlanManager()->saveDraft($planId, $draftData);
		if (!$success) {
			throw new Exception('#8601 save draft failed');
		}

		// Handle seats if provided - use wp_unslash for WordPress magic quotes
		if (!empty($data['seats'])) {
			$jsonStr = is_string($data['seats']) ? wp_unslash($data['seats']) : $data['seats'];
			$seats = is_string($jsonStr) ? json_decode($jsonStr, true) : $jsonStr;
			if (is_array($seats)) {
				$this->syncSeatsFromDesigner($planId, $seats);
			}
		}

		// Get updated info for badges
		$planManager = $this->MAIN->getSeating()->getPlanManager();
		$auditInfo = $planManager->getAuditInfo($planId);
		$publishInfo = $planManager->getPublishInfo($planId);

		return [
			'message' => __('Draft saved successfully', 'event-tickets-with-ticket-scanner'),
			'has_unpublished_changes' => true,
			'audit_info' => $auditInfo,
			'publish_info' => $publishInfo
		];
	}

	/**
	 * Sync seats from designer data
	 *
	 * Creates new seats and updates existing ones.
	 * Does NOT delete seats (that happens on publish).
	 *
	 * @param int $planId Plan ID
	 * @param array $seatsData Seats data from designer
	 */
	private function syncSeatsFromDesigner(int $planId, array $seatsData): void {
		$seatManager = $this->MAIN->getSeating()->getSeatManager();

		foreach ($seatsData as $rawSeatData) {
			$seatId = isset($rawSeatData['id']) ? (int) $rawSeatData['id'] : 0;
			$seatData = $this->sanitizeSeatData($rawSeatData);

			// Ensure identifier for new seats
			if (empty($seatData['seat_identifier'])) {
				$seatData['seat_identifier'] = 'SEAT-' . uniqid();
			}

			if ($seatId > 0) {
				$seatManager->update($seatData, $seatId);
			} else {
				$seatManager->create($planId, $seatData);
			}
		}
	}

	/**
	 * Handle: Publish plan (copy draft to published)
	 *
	 * @param array $data Request data with plan_id
	 * @return array Result with success status and any conflicts
	 * @throws Exception On failure
	 */
	private function handlePublishPlan(array $data): array {
		$planId = isset($data['plan_id']) ? (int) $data['plan_id'] : 0;
		if (!$planId) {
			throw new Exception('#8610 missing plan_id');
		}

		$planManager = $this->MAIN->getSeating()->getPlanManager();
		$result = $planManager->publish($planId);

		if (!$result['success']) {
			return [
				'success' => false,
				'conflicts' => $result['conflicts'] ?? [],
				'message' => $result['message']
			];
		}

		// Get updated info for badges
		$auditInfo = $planManager->getAuditInfo($planId);
		$publishInfo = $planManager->getPublishInfo($planId);

		return [
			'success' => true,
			'published_at' => $result['published_at'],
			'has_unpublished_changes' => false,
			'audit_info' => $auditInfo,
			'publish_info' => $publishInfo,
			'message' => __('Seating plan published successfully', 'event-tickets-with-ticket-scanner')
		];
	}

	/**
	 * Handle: Discard draft changes
	 *
	 * @param array $data Request data with plan_id
	 * @return array Success message
	 * @throws Exception On failure
	 */
	private function handleDiscardDraft(array $data): array {
		$planId = isset($data['plan_id']) ? (int) $data['plan_id'] : 0;
		if (!$planId) {
			throw new Exception('#8620 missing plan_id');
		}

		$success = $this->MAIN->getSeating()->getPlanManager()->discardDraft($planId);
		if (!$success) {
			throw new Exception('#8621 discard draft failed');
		}

		return [
			'message' => __('Draft changes discarded', 'event-tickets-with-ticket-scanner'),
			'has_unpublished_changes' => false
		];
	}

	/**
	 * Handle: Get designer data (plan settings, seats, draft)
	 *
	 * @param array $data Request data with plan_id
	 * @return array Designer data
	 * @throws Exception On failure
	 */
	private function handleGetDesignerData(array $data): array {
		$planId = isset($data['plan_id']) ? (int) $data['plan_id'] : 0;
		if (!$planId) {
			throw new Exception('#8630 missing plan_id');
		}

		$planManager = $this->MAIN->getSeating()->getPlanManager();
		$seatManager = $this->MAIN->getSeating()->getSeatManager();

		$plan = $planManager->getById($planId);
		if (!$plan) {
			throw new Exception('#8631 plan not found');
		}

		$draftMeta = $planManager->getDraftMeta($planId);
		$publishedMeta = $planManager->getPublishedMeta($planId);
		$seats = $seatManager->getByPlanId($planId);
		$hasUnpublishedChanges = $planManager->hasUnpublishedChanges($planId);
		$publishInfo = $planManager->getPublishInfo($planId);
		$auditInfo = $planManager->getAuditInfo($planId);
		$activeSales = $planManager->getActiveSalesInfo($planId);

		return [
			'plan' => [
				'id' => $plan['id'],
				'name' => $plan['name'],
				'layout_type' => $plan['layout_type'] ?? 'simple',
				'aktiv' => $plan['aktiv']
			],
			'draft' => $draftMeta,
			'published' => $publishedMeta,
			'seats' => $seats,
			'has_unpublished_changes' => $hasUnpublishedChanges,
			'publish_info' => $publishInfo,
			'audit_info' => $auditInfo,
			'active_sales' => $activeSales
		];
	}

	/**
	 * Handle: Get published data only (for version toggle)
	 *
	 * @param array $data Request data with plan_id
	 * @return array Published meta data
	 * @throws Exception On failure
	 */
	private function handleGetPublishedData(array $data): array {
		$planId = isset($data['plan_id']) ? (int) $data['plan_id'] : 0;
		if (!$planId) {
			throw new Exception('#8635 missing plan_id');
		}

		$planManager = $this->MAIN->getSeating()->getPlanManager();
		$publishedMeta = $planManager->getPublishedMeta($planId);

		return [
			'published' => $publishedMeta
		];
	}

	/**
	 * Handle: Get designer page data (JSON only, HTML generated in JS)
	 *
	 * @param array $data Request data with plan_id
	 * @return array Config and data for designer
	 * @throws Exception On failure
	 */
	private function handleGetDesignerPage(array $data): array {
		$planId = isset($data['plan_id']) ? (int) $data['plan_id'] : 0;
		if (!$planId) {
			throw new Exception('#8640 missing plan_id');
		}

		// Load complete plan object
		$plan = $this->MAIN->getSeating()->getPlanManager()->getFullPlan($planId);
		if (!$plan) {
			throw new Exception('#8641 plan not found');
		}

		// Return plan + UI config
		return [
			'plan' => $plan,
			'config' => [
				'container' => '#saso-designer-container',
				'planId' => $planId,
				'ajaxUrl' => admin_url('admin-ajax.php'),
				'ajaxAction' => $this->MAIN->getPrefix() . '_executeSeatingAdmin',
				'nonce' => wp_create_nonce($this->MAIN->_js_nonce)
			]
		];
	}

	/**
	 * Enqueue admin scripts and styles
	 */
	public function enqueueScripts(): void {
		wp_enqueue_script('jquery');
		wp_enqueue_script('jquery-ui-sortable');
		wp_enqueue_script('jquery-ui-dialog');
		wp_enqueue_style('wp-jquery-ui-dialog');

		// DataTables for seat list
		wp_enqueue_script(
			'datatables',
			'https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js',
			['jquery'],
			'1.13.7',
			true
		);
		wp_enqueue_style(
			'datatables',
			'https://cdn.datatables.net/1.13.7/css/jquery.dataTables.min.css',
			[],
			'1.13.7'
		);

		// Plugin root path for assets (from /includes/seating/ go up 2 levels)
		$pluginRoot = dirname(dirname(__DIR__));

		wp_enqueue_script(
			'saso-seating-admin',
			plugins_url('js/seating_admin.js', $pluginRoot . '/index.php'),
			['jquery', 'jquery-ui-sortable', 'jquery-ui-dialog', 'wp-i18n'],
			$this->MAIN->getPluginVersion(),
			true
		);

		wp_localize_script('saso-seating-admin', 'sasoSeatingAdmin', [
			'ajaxurl' => admin_url('admin-ajax.php'),
			'action' => $this->MAIN->getPrefix() . '_executeSeatingAdmin',
			'nonce' => wp_create_nonce($this->MAIN->_js_nonce),
			'i18n' => [
				'confirmDelete' => __('Are you sure you want to delete this?', 'event-tickets-with-ticket-scanner'),
				'confirmDeleteWithSeats' => __('This plan has seats. Delete plan and all seats?', 'event-tickets-with-ticket-scanner'),
				'planCreated' => __('Seating plan created successfully', 'event-tickets-with-ticket-scanner'),
				'planUpdated' => __('Seating plan updated successfully', 'event-tickets-with-ticket-scanner'),
				'planDeleted' => __('Seating plan deleted successfully', 'event-tickets-with-ticket-scanner'),
				'seatCreated' => __('Seat created successfully', 'event-tickets-with-ticket-scanner'),
				'seatsCreated' => __('Seats created successfully', 'event-tickets-with-ticket-scanner'),
				'seatUpdated' => __('Seat updated successfully', 'event-tickets-with-ticket-scanner'),
				'seatDeleted' => __('Seat deleted successfully', 'event-tickets-with-ticket-scanner'),
				'limitReached' => __('Limit reached. Upgrade to Premium for unlimited access.', 'event-tickets-with-ticket-scanner'),
				'error' => __('An error occurred. Please try again.', 'event-tickets-with-ticket-scanner'),
				'loading' => __('Loading...', 'event-tickets-with-ticket-scanner'),
				'noPlans' => __('No seating plans found. Create your first plan!', 'event-tickets-with-ticket-scanner'),
				'noSeats' => __('No seats in this plan. Add seats below.', 'event-tickets-with-ticket-scanner'),
				'layoutSimpleTitle' => __('Simple Layout (Dropdown)', 'event-tickets-with-ticket-scanner'),
				'layoutSimpleDesc' => __('Customers will see a dropdown menu to select their seat. Available seats are shown in a list sorted by identifier. This is ideal for smaller venues or when visual seat selection is not needed.', 'event-tickets-with-ticket-scanner'),
				'layoutVisualTitle' => __('Visual Layout (Seat Map)', 'event-tickets-with-ticket-scanner'),
				'layoutVisualDesc' => __('Customers will see an interactive seat map where they can click on available seats. Occupied seats are shown in a different color. This provides a visual overview of the venue. (Premium feature)', 'event-tickets-with-ticket-scanner'),
				// Visual Designer i18n
				'draftSaved' => __('Draft saved successfully', 'event-tickets-with-ticket-scanner'),
				'draftDiscarded' => __('Draft changes discarded', 'event-tickets-with-ticket-scanner'),
				'planPublished' => __('Seating plan published successfully', 'event-tickets-with-ticket-scanner'),
				'unpublishedChanges' => __('You have unpublished changes', 'event-tickets-with-ticket-scanner'),
				'lastPublished' => __('Last published:', 'event-tickets-with-ticket-scanner'),
				'neverPublished' => __('Never published', 'event-tickets-with-ticket-scanner'),
				'confirmDiscard' => __('Discard all unsaved changes and revert to published version?', 'event-tickets-with-ticket-scanner'),
				'confirmPublish' => __('Publish changes? This will make them visible to customers.', 'event-tickets-with-ticket-scanner'),
				'publishConflicts' => __('Cannot publish: The following seats have sold tickets and cannot be deleted:', 'event-tickets-with-ticket-scanner'),
				'activeSalesWarning' => __('Warning: This seating plan has active ticket sales. Changes will only be visible after publishing.', 'event-tickets-with-ticket-scanner'),
				'ticketsSold' => __('%d tickets sold', 'event-tickets-with-ticket-scanner')
			]
		]);

		wp_set_script_translations('saso-seating-admin', 'event-tickets-with-ticket-scanner', $pluginRoot . '/languages');

		wp_enqueue_style(
			'saso-seating-admin',
			plugins_url('css/seating_admin.css', $pluginRoot . '/index.php'),
			[],
			$this->MAIN->getPluginVersion()
		);

		// Visual Designer scripts and styles
		wp_enqueue_script(
			'saso-seating-designer',
			plugins_url('js/seating_designer.js', $pluginRoot . '/index.php'),
			['jquery', 'wp-i18n', 'saso-seating-admin'],
			$this->MAIN->getPluginVersion(),
			true
		);

		wp_enqueue_style(
			'saso-seating-designer',
			plugins_url('css/seating_designer.css', $pluginRoot . '/index.php'),
			['saso-seating-admin'],
			$this->MAIN->getPluginVersion()
		);
	}

	// NOTE: renderAdminPage() removed - HTML is now generated in JavaScript (seating_admin.js)
	// See renderAdminHTML(), renderPlanModal(), renderSeatModal() in seating_admin.js

}
