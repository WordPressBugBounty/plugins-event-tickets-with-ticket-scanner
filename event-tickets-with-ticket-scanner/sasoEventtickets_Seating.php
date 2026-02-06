<?php
include_once(plugin_dir_path(__FILE__)."init_file.php");

class sasoEventtickets_Seating {
	private $MAIN;

	/**
	 * Plan manager instance
	 *
	 * @var sasoEventtickets_Seating_Plan|null
	 */
	private $planManager = null;

	/**
	 * Seat manager instance
	 *
	 * @var sasoEventtickets_Seating_Seat|null
	 */
	private $seatManager = null;

	/**
	 * Block manager instance
	 *
	 * @var sasoEventtickets_Seating_Block|null
	 */
	private $blockManager = null;

	/**
	 * Admin handler instance
	 *
	 * @var sasoEventtickets_Seating_Admin|null
	 */
	private $adminHandler = null;

	/**
	 * Frontend handler instance
	 *
	 * @var sasoEventtickets_Seating_Frontend|null
	 */
	private $frontendHandler = null;

	/**
	 * Singleton pattern - get instance
	 *
	 * @param sasoEventtickets|null $main Main plugin instance
	 * @return sasoEventtickets_Seating
	 */
	public static function Instance($main = null) {
		static $inst = null;
		if ($inst === null) {
			$inst = new sasoEventtickets_Seating($main);
		}
		return $inst;
	}

	public function __construct($main = null) {
		if ($main !== null) {
			$this->MAIN = $main;
		} else {
			global $sasoEventtickets;
			$this->MAIN = $sasoEventtickets;
		}
	}

	/**
	 * Load a Seating class from /includes/seating/
	 *
	 * @param string $filename The class file name (e.g., 'class-seating-plan.php')
	 * @param string $className The class name to check if already loaded
	 * @return void
	 */
	private function loadSeatingClass(string $filename, string $className): void {
		if (class_exists($className)) {
			return;
		}

		// Load base class first
		$basePath = plugin_dir_path(__FILE__) . 'includes/seating/class-seating-base.php';
		if (file_exists($basePath) && !class_exists('sasoEventtickets_Seating_Base')) {
			require_once $basePath;
		}

		// Load requested class
		$path = plugin_dir_path(__FILE__) . 'includes/seating/' . $filename;
		if (file_exists($path)) {
			require_once $path;
		}
	}

	/**
	 * Get Plan Manager instance (lazy loading)
	 *
	 * @return sasoEventtickets_Seating_Plan
	 */
	public function getPlanManager() {
		if ($this->planManager === null) {
			$this->loadSeatingClass('class-seating-plan.php', 'sasoEventtickets_Seating_Plan');
			$this->planManager = new sasoEventtickets_Seating_Plan($this->MAIN);
		}
		return $this->planManager;
	}

	/**
	 * Get Seat Manager instance (lazy loading)
	 *
	 * @return sasoEventtickets_Seating_Seat
	 */
	public function getSeatManager() {
		if ($this->seatManager === null) {
			$this->loadSeatingClass('class-seating-seat.php', 'sasoEventtickets_Seating_Seat');
			$this->seatManager = new sasoEventtickets_Seating_Seat($this->MAIN);
		}
		return $this->seatManager;
	}

	/**
	 * Get Block Manager instance (lazy loading)
	 *
	 * @return sasoEventtickets_Seating_Block
	 */
	public function getBlockManager() {
		if ($this->blockManager === null) {
			$this->loadSeatingClass('class-seating-block.php', 'sasoEventtickets_Seating_Block');
			$this->blockManager = new sasoEventtickets_Seating_Block($this->MAIN);
		}
		return $this->blockManager;
	}

	/**
	 * Get Admin Handler instance (lazy loading)
	 *
	 * @return sasoEventtickets_Seating_Admin
	 */
	public function getAdminHandler() {
		if ($this->adminHandler === null) {
			$this->loadSeatingClass('class-seating-admin.php', 'sasoEventtickets_Seating_Admin');
			$this->adminHandler = new sasoEventtickets_Seating_Admin($this->MAIN);
		}
		return $this->adminHandler;
	}

	/**
	 * Get Frontend Handler instance (lazy loading)
	 *
	 * @return sasoEventtickets_Seating_Frontend
	 */
	public function getFrontendManager() {
		if ($this->frontendHandler === null) {
			$this->loadSeatingClass('class-seating-frontend.php', 'sasoEventtickets_Seating_Frontend');
			$this->frontendHandler = new sasoEventtickets_Seating_Frontend($this->MAIN);
		}
		return $this->frontendHandler;
	}

	// =====================================================================
	// Meta Key Accessors (proxy to Base class methods using prefix)
	// =====================================================================

	/**
	 * Get meta key prefix (public keys)
	 *
	 * @return string Prefix like 'sasoEventtickets_'
	 */
	public function getMetaPrefix(): string {
		return $this->MAIN->getPrefix() . '_';
	}

	/**
	 * Get meta key prefix (private keys)
	 *
	 * @return string Prefix like '_sasoEventtickets_'
	 */
	public function getMetaPrefixPrivate(): string {
		return '_' . $this->MAIN->getPrefix() . '_';
	}

	/**
	 * Get meta key: Seatingplan ID assigned to product
	 *
	 * @return string Meta key
	 */
	public function getMetaProductSeatingplan(): string {
		return $this->getMetaPrefix() . 'seatingplan_id';
	}

	/**
	 * Get meta key: Product requires seat selection
	 *
	 * @return string Meta key
	 */
	public function getMetaProductSeatingRequired(): string {
		return $this->getMetaPrefix() . 'seating_required';
	}

	/**
	 * Get meta key: Seatingplan ID override for variation
	 *
	 * @return string Meta key
	 */
	public function getMetaVariationSeatingplan(): string {
		return $this->getMetaPrefixPrivate() . 'seatingplan_id';
	}

	/**
	 * Get meta key: Selected seat data on order item
	 *
	 * @return string Meta key
	 */
	public function getMetaOrderItemSeat(): string {
		return $this->getMetaPrefixPrivate() . 'seat';
	}

	/**
	 * Get meta key: Seat block ID reference on order item
	 *
	 * @return string Meta key
	 */
	public function getMetaOrderItemSeatBlockId(): string {
		return $this->getMetaPrefixPrivate() . 'seat_block_id';
	}

	/**
	 * Get meta key: Selected seat in cart item
	 *
	 * @return string Meta key
	 */
	public function getMetaCartItemSeat(): string {
		return $this->getMetaPrefixPrivate() . 'seat_selection';
	}

	/**
	 * Get form field name for seat selection input
	 *
	 * @return string Field name
	 */
	public function getFieldSeatSelection(): string {
		return $this->getMetaPrefix() . 'seat_selection';
	}

	/**
	 * Check if seating is required for a product
	 *
	 * Note: Caller is responsible for WPML normalization via getWPMLProductId()
	 * before calling this function.
	 *
	 * @param int $productId Product ID (should be normalized for WPML)
	 * @return bool
	 */
	public function isSeatingRequired(int $productId): bool {
		// Validate product ID
		if ($productId <= 0) {
			$this->MAIN->getDB()->logError('isSeatingRequired: Invalid product ID: ' . $productId);
			return false;
		}

		return get_post_meta($productId, $this->getMetaProductSeatingRequired(), true) === 'yes';
	}

	/**
	 * Get seat info by ID
	 *
	 * @param int $seatId Seat ID
	 * @return array|null Seat data or null
	 */
	public function getSeatInfo(int $seatId): ?array {
		return $this->getSeatManager()->getById($seatId);
	}

	/**
	 * Block a seat for cart (wrapper for Block Manager)
	 *
	 * @param int $productId Product ID
	 * @param int $seatId Seat ID
	 * @param string|null $eventDate Event date
	 * @return array Result with success/error
	 */
	public function blockSeatForCart(int $productId, int $seatId, ?string $eventDate = null): array {
		$sessionId = $this->getWCSessionId();
		if (!$sessionId) {
			return ['success' => false, 'error' => 'no_session'];
		}
		return $this->getBlockManager()->blockSeat($seatId, $productId, $sessionId, $eventDate);
	}

	/**
	 * Release a seat from cart (wrapper for Block Manager)
	 *
	 * @param int $blockId Block ID
	 * @return bool Success
	 */
	public function releaseSeatFromCart(int $blockId): bool {
		$sessionId = $this->getWCSessionId();
		if (!$sessionId) {
			return false;
		}
		return $this->getBlockManager()->releaseBlock($blockId, $sessionId);
	}

	/**
	 * Get WooCommerce session ID
	 *
	 * @return string|null Session ID or null
	 */
	private function getWCSessionId(): ?string {
		if (!function_exists('WC') || !WC()->session) {
			return null;
		}

		// Get or create session
		if (!WC()->session->has_session()) {
			WC()->session->set_customer_session_cookie(true);
		}

		$customerId = WC()->session->get_customer_id();
		return $customerId ? (string) $customerId : null;
	}

	// =========================================================================
	// JSON API Router
	// =========================================================================

	/**
	 * Execute JSON action
	 *
	 * @param string $a Action name
	 * @param array $data Request data
	 * @param bool $just_ret Return value instead of wp_send_json
	 * @return mixed
	 */
	public function executeJSON($a, $data = [], $just_ret = false) {
		$ret = "";
		try {
			switch (trim($a)) {
				// Plan operations
				case "getPlans":
					$ret = $this->handleGetPlans();
					break;
				case "getPlan":
					$ret = $this->handleGetPlan($data);
					break;
				case "createPlan":
				case "updatePlan":
					$ret = $this->handleSavePlan($data);
					break;
				case "deletePlan":
					$ret = $this->handleDeletePlan($data);
					break;

				// Seat operations
				case "getSeats":
					$ret = $this->handleGetSeats($data);
					break;
				case "createSeat":
				case "updateSeat":
					$ret = $this->handleSaveSeat($data);
					break;
				case "createSeatsBulk":
					$ret = $this->handleCreateSeatsBulk($data);
					break;
				case "deleteSeat":
					$ret = $this->handleDeleteSeat($data);
					break;

				// Statistics
				case "getStats":
					$ret = $this->handleGetStats($data);
					break;

				// Visual Designer Actions - delegate to Admin Handler
				case "getDesignerPage":
				case "getDesignerData":
				case "saveDraft":
				case "publishPlan":
				case "discardDraft":
				case "clonePlan":
					$ret = $this->getAdminHandler()->executeSeatingJSON($a, $data, true, true);
					break;

				default:
					throw new Exception("#8500 ".sprintf(esc_html__('function "%s" not implemented', 'event-tickets-with-ticket-scanner'), $a));
			}
		} catch(Exception $e) {
			$this->MAIN->getAdmin()->logErrorToDB($e);
			if ($just_ret) throw $e;
			return wp_send_json_error($e->getMessage());
		}
		if ($just_ret) return $ret;
		return wp_send_json_success($ret);
	}

	// =========================================================================
	// Plan Handlers
	// =========================================================================

	private function handleGetPlans(): array {
		$plans = $this->getPlanManager()->getAll();
		foreach ($plans as &$plan) {
			$plan['seat_count'] = $this->getSeatManager()->getCountForPlan((int) $plan['id']);
		}
		return ['plans' => $plans];
	}

	private function handleGetPlan(array $data): array {
		$planId = isset($data['plan_id']) ? (int) $data['plan_id'] : 0;
		if (!$planId) {
			throw new Exception('#8510 missing plan_id');
		}

		$plan = $this->getPlanManager()->getById($planId);
		if (!$plan) {
			throw new Exception('#8511 plan not found');
		}

		$plan['seat_count'] = $this->getSeatManager()->getCountForPlan($planId);
		return ['plan' => $plan];
	}

	private function handleSavePlan(array $data): array {
		$planId = isset($data['plan_id']) ? (int) $data['plan_id'] : 0;
		$isUpdate = $planId > 0;

		$planData = [
			'name' => sanitize_text_field($data['name'] ?? ''),
			'aktiv' => isset($data['aktiv']) ? 1 : 0,
			'layout_type' => sanitize_text_field($data['layout_type'] ?? 'simple'),
			'meta' => [
				'description' => sanitize_textarea_field($data['description'] ?? ''),
				'image_id' => isset($data['image_id']) ? absint($data['image_id']) : 0
			]
		];

		if ($isUpdate) {
			if (!$this->getPlanManager()->update($planId, $planData)) {
				throw new Exception('#8531 update plan failed');
			}
			return ['message' => __('Seating plan updated successfully', 'event-tickets-with-ticket-scanner')];
		} else {
			$planId = $this->getPlanManager()->create($planData);
			if (!$planId) {
				throw new Exception('#8520 create plan failed');
			}
			return [
				'plan_id' => $planId,
				'message' => __('Seating plan created successfully', 'event-tickets-with-ticket-scanner')
			];
		}
	}

	private function handleDeletePlan(array $data): array {
		$planId = isset($data['plan_id']) ? (int) $data['plan_id'] : 0;
		$force = isset($data['force']) && ($data['force'] === true || $data['force'] === 'true');

		if (!$planId) {
			throw new Exception('#8540 missing plan_id');
		}

		if (!$this->getPlanManager()->delete($planId, $force)) {
			throw new Exception('#8541 delete plan failed');
		}

		return ['message' => __('Seating plan deleted successfully', 'event-tickets-with-ticket-scanner')];
	}

	// =========================================================================
	// Seat Handlers
	// =========================================================================

	private function handleGetSeats(array $data): array {
		$planId = isset($data['plan_id']) ? (int) $data['plan_id'] : 0;
		if (!$planId) {
			throw new Exception('#8550 missing plan_id');
		}

		return ['seats' => $this->getSeatManager()->getByPlanId($planId)];
	}

	private function handleSaveSeat(array $data): array {
		$seatId = isset($data['seat_id']) ? (int) $data['seat_id'] : 0;
		$planId = isset($data['plan_id']) ? (int) $data['plan_id'] : 0;
		$isUpdate = $seatId > 0;

		$seatData = [
			'seat_identifier' => sanitize_text_field($data['seat_identifier'] ?? ''),
			'aktiv' => isset($data['aktiv']) ? 1 : 0,
			'meta' => [
				'seat_label' => sanitize_text_field($data['seat_label'] ?? ''),
				'seat_category' => sanitize_text_field($data['seat_category'] ?? ''),
				'seat_desc' => sanitize_text_field($data['seat_desc'] ?? '')
			]
		];

		if ($isUpdate) {
			$results = $this->getSeatManager()->update($seatData, $seatId);
			if (empty($results) || !$results[0]['success']) {
				throw new Exception('#8581 update seat failed');
			}
			return ['message' => __('Seat updated successfully', 'event-tickets-with-ticket-scanner')];
		} else {
			if (!$planId) {
				throw new Exception('#8560 missing plan_id');
			}
			$seatId = $this->getSeatManager()->create($planId, $seatData);
			if (!$seatId) {
				throw new Exception('#8561 create seat failed');
			}
			return [
				'seat_id' => $seatId,
				'message' => __('Seat created successfully', 'event-tickets-with-ticket-scanner')
			];
		}
	}

	private function handleCreateSeatsBulk(array $data): array {
		$planId = isset($data['plan_id']) ? (int) $data['plan_id'] : 0;
		if (!$planId) {
			throw new Exception('#8570 missing plan_id');
		}

		// Support both formats:
		// 1. Legacy: 'identifiers' as newline-separated string
		// 2. New: 'seats' as JSON array of {identifier, label, category}
		$seatsList = [];

		if (isset($data['seats']) && is_array($data['seats'])) {
			// New format: array of seat objects
			foreach ($data['seats'] as $seat) {
				if (is_array($seat) && !empty($seat['identifier'])) {
					$seatsList[] = [
						'identifier' => sanitize_text_field($seat['identifier']),
						'label' => isset($seat['label']) ? sanitize_text_field($seat['label']) : '',
						'category' => isset($seat['category']) ? sanitize_text_field($seat['category']) : ''
					];
				}
			}
		} elseif (isset($data['identifiers'])) {
			// Legacy format: newline-separated identifiers
			$identifiers = sanitize_textarea_field($data['identifiers']);
			$seatsList = array_filter(array_map('trim', explode("\n", $identifiers)));
		}

		if (empty($seatsList)) {
			throw new Exception('#8571 no seats provided');
		}

		$createdIds = $this->getSeatManager()->createBulk($planId, $seatsList);

		return [
			'created_count' => count($createdIds),
			'seat_ids' => $createdIds,
			'message' => sprintf(__('%d seats created successfully', 'event-tickets-with-ticket-scanner'), count($createdIds))
		];
	}

	private function handleDeleteSeat(array $data): array {
		$seatId = isset($data['seat_id']) ? (int) $data['seat_id'] : 0;
		$force = isset($data['force']) && ($data['force'] === true || $data['force'] === 'true');

		if (!$seatId) {
			throw new Exception('#8590 missing seat_id');
		}

		$results = $this->getSeatManager()->delete($seatId, $force);
		if (empty($results) || !$results[0]['success']) {
			$error = $results[0]['error'] ?? 'delete failed';
			throw new Exception('#8591 ' . $error);
		}

		return ['message' => __('Seat deleted successfully', 'event-tickets-with-ticket-scanner')];
	}

	// =========================================================================
	// Stats Handler
	// =========================================================================

	private function handleGetStats(array $data): array {
		$planId = isset($data['plan_id']) ? (int) $data['plan_id'] : 0;
		if (!$planId) {
			throw new Exception('#8595 missing plan_id');
		}

		return ['stats' => $this->getStats($planId, $data['event_date'] ?? null)];
	}

	/**
	 * Get statistics for a plan
	 *
	 * @param int $planId Plan ID
	 * @param string|null $eventDate Event date
	 * @return array Statistics
	 */
	public function getStats(int $planId, ?string $eventDate = null): array {
		$totalSeats = $this->getSeatManager()->getCountForPlan($planId);
		$blocked = $this->getBlockManager()->getBlockedCount($planId, $eventDate);
		$confirmed = $this->getBlockManager()->getConfirmedCount($planId, $eventDate);

		return [
			'total_seats' => $totalSeats,
			'blocked' => $blocked,
			'confirmed' => $confirmed,
			'available' => $totalSeats - $blocked - $confirmed
		];
	}

	/**
	 * Get seats with their current status
	 *
	 * @param int $planId Plan ID
	 * @param int $productId Product ID
	 * @param string|null $eventDate Event date
	 * @return array Seats with status
	 */
	public function getSeatsWithStatus(int $planId, int $productId, ?string $eventDate = null): array {
		return $this->getBlockManager()->getSeatsWithStatus($planId, $productId, $eventDate);
	}
}
