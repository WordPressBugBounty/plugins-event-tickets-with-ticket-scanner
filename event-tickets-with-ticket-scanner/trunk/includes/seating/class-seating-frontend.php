<?php
/**
 * Seating Frontend Handler
 *
 * Handles frontend seat selection UI and AJAX requests.
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
 * Seating Frontend Class
 *
 * Provides frontend UI for seat selection in cart/checkout.
 *
 * @since 2.8.0
 */
class sasoEventtickets_Seating_Frontend extends sasoEventtickets_Seating_Base {

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
	 * Get meta object structure - not used by Frontend class
	 *
	 * Required by abstract parent class but Frontend doesn't manage meta objects.
	 *
	 * @return array Empty array
	 */
	public function getMetaObject(): array {
		return [];
	}

	/**
	 * Initialize WordPress hooks
	 *
	 * Note: AJAX hooks are registered in sasoEventtickets_Seating constructor
	 * to ensure they're available before lazy loading
	 */
	private function initHooks(): void {
		// AJAX handlers are now registered in sasoEventtickets_Seating::__construct()
		// to ensure availability on AJAX requests before getFrontendManager() is called

		// WordPress Heartbeat API hooks for seat block keep-alive
		add_filter('heartbeat_received', [$this, 'heartbeatReceived'], 10, 2);
	}

	/**
	 * Handle WordPress heartbeat - update last_seen for active seat blocks
	 *
	 * @param array $response Heartbeat response data
	 * @param array $data Heartbeat request data from JS
	 * @return array Modified response
	 */
	public function heartbeatReceived(array $response, array $data): array {
		// Check if seating data was sent
		if (empty($data['saso_seating_blocks'])) {
			return $response;
		}

		$blockIds = array_map('intval', (array) $data['saso_seating_blocks']);
		if (empty($blockIds)) {
			return $response;
		}

		// Get session ID to verify ownership
		$sessionId = WC()->session ? WC()->session->get_customer_id() : session_id();

		// Update last_seen for these blocks
		$blockManager = $this->MAIN->getSeating()->getBlockManager();
		$updated = $blockManager->updateLastSeen($blockIds, $sessionId);

		// Return status of blocks
		$response['saso_seating'] = [
			'updated' => $updated,
			'timestamp' => current_time('timestamp')
		];

		return $response;
	}

	/**
	 * Enqueue frontend scripts and styles
	 */
	public function enqueueScripts(): void {
		wp_enqueue_script('jquery');
		wp_enqueue_script('jquery-ui-dialog');
		wp_enqueue_style('wp-jquery-ui-dialog');

		wp_enqueue_script(
			'saso-seating-frontend',
			plugins_url('js/seating_frontend.js', dirname(__DIR__)),
			['jquery', 'wp-i18n'],
			$this->MAIN->getPluginVersion(),
			true
		);

		wp_localize_script('saso-seating-frontend', 'sasoSeatingData', [
			'ajaxurl' => admin_url('admin-ajax.php'),
			'action' => $this->MAIN->getPrefix() . '_executeSeatingFrontend',
			'nonce' => wp_create_nonce('sasoEventtickets'),
			'fieldName' => $this->getFieldSeatSelection(),
			'hideExpirationTime' => $this->MAIN->getOptions()->isOptionCheckboxActive('seatingHideExpirationTime'),
			'lockSelectedSeats' => $this->MAIN->getOptions()->isOptionCheckboxActive('seatingLockSelectedSeats'),
			'blockOnAddToCart' => $this->MAIN->getOptions()->isOptionCheckboxActive('seatingBlockOnAddToCart'),
			'showSeatDescInChooser' => $this->MAIN->getOptions()->isOptionCheckboxActive('seatingShowDescInChooser'),
			'autoConfirmSeatSelection' => $this->MAIN->getOptions()->isOptionCheckboxActive('seatingAutoConfirmSeatSelection'),
		]);

		wp_set_script_translations('saso-seating-frontend', 'event-tickets-with-ticket-scanner', dirname(dirname(__DIR__)) . '/languages');

		wp_enqueue_style(
			'saso-seating-frontend',
			plugins_url('css/seating_frontend.css', dirname(__DIR__)),
			[],
			$this->MAIN->getPluginVersion()
		);
	}

	/**
	 * Get seating plan for a product for FRONTEND display
	 *
	 * Calls parent's getPlanForProduct() and applies frontend-specific filtering:
	 * - Only returns published plans for customers
	 * - Admins can preview drafts via ?preview_seating=1 query parameter
	 *
	 * Note: Caller is responsible for WPML normalization.
	 *
	 * @param int $productId Product ID (or variation ID)
	 * @param int|null $variationId Variation ID (optional)
	 * @return array|null Plan data or null if not available for frontend
	 */
	public function getPlanForProductFrontend(int $productId, ?int $variationId = null): ?array {
		// Validate product ID
		if ($productId <= 0) {
			$this->MAIN->getDB()->logError('getPlanForProductFrontend: Invalid product ID: ' . $productId);
			return null;
		}

		// Get the base plan (SRP: reuse inherited getPlanForProduct)
		$plan = $this->getPlanForProduct($productId, $variationId);

		if (!$plan) {
			return null;
		}

		// Frontend filter: must be active
		if (empty($plan['aktiv'])) {
			return null;
		}

		// Check published status and admin preview
		// Null-Date-Schutz (#015050): MariaDB ohne strict mode speichert "leer"
		// als '0000-00-00 00:00:00' — das ist NICHT published.
		$rawPublishedAt = $plan['published_at'] ?? null;
		$isPublished = !empty($rawPublishedAt) && $rawPublishedAt !== '0000-00-00 00:00:00';
		$allowDraftPreview = isset($_GET['preview_seating']) && $_GET['preview_seating'] === '1';
		$isAdminPreview = $allowDraftPreview && current_user_can('manage_woocommerce');

		// Not published and not admin preview - don't show
		if (!$isPublished && !$isAdminPreview) {
			return null;
		}

		// getById() returns: meta (decoded), meta_draft (JSON string), meta_published (JSON string)
		// Plan-level meta (from edit modal: image_id, description) - already decoded by getById()
		$planMeta = $plan['meta'] ?? [];

		// Get designer meta (visual elements, canvas settings) - need to decode JSON
		$planManager = $this->MAIN->getSeating()->getPlanManager();
		if ($isPublished && !$isAdminPreview) {
			// Customer sees published version
			$designerMeta = !empty($plan['meta_published'])
				? json_decode($plan['meta_published'], true)
				: [];
			$plan['_using_published'] = true;
		} else {
			// Admin preview - use draft
			$designerMeta = !empty($plan['meta_draft'])
				? json_decode($plan['meta_draft'], true)
				: [];
			$plan['_using_draft'] = true;
			$plan['_is_preview'] = true;
		}

		// #015050: element lists (decorations/lines/labels) are replaced wholesale —
		// array_replace_recursive on lists resurrects stale tail entries from the
		// legacy meta column (rect9 came back on the shop page while draft/published
		// were clean). mergePlanMeta replaces lists and merges scalars correctly.
		$plan['meta'] = $this->mergePlanMeta(
			$planManager->getMetaObject(),
			is_array($planMeta) ? $planMeta : [],
			is_array($designerMeta) ? $designerMeta : []
		);

		return $plan;
	}

	/**
	 * Render seat selector container for a product
	 *
	 * Only renders a minimal container div with data attributes.
	 * All UI rendering is handled by JavaScript (seating_frontend.js).
	 *
	 * @param int $productId Product ID
	 * @param string|null $eventDate Event date
	 * @param string|null $cartItemKey Cart item key (for cart context)
	 * @param array|null $currentSelection Current selected seat data
	 * @param int $variationId Variation ID - its own plan wins over the parent product
	 * @return string HTML output
	 */
	/**
	 * #015050: merge plan meta with list-replacement semantics. Element lists
	 * (decorations, lines, labels) replace wholesale when a later layer
	 * provides them; scalars/unknown keys merge as before.
	 */
	private function mergePlanMeta(array ...$layers): array {
		$listKeys = ['decorations', 'lines', 'labels'];
		$out = [];
		foreach ($layers as $layer) {
			foreach ($listKeys as $lk) {
				if (array_key_exists($lk, $layer)) {
					$out[$lk] = $layer[$lk];
					unset($layer[$lk]);
				}
			}
			$out = array_replace_recursive($out, $layer);
		}
		return $out;
	}

	public function renderSeatSelector(int $productId, ?string $eventDate = null, ?string $cartItemKey = null, ?array $currentSelection = null, int $variationId = 0): string {
		$jsData = $this->buildSelectorData($productId, $eventDate, $currentSelection, $variationId);

		if ($jsData === null) {
			return '';
		}

		// Unique ID for this selector instance
		$instanceId = 'saso-seating-' . $productId . '-' . uniqid();

		ob_start();
		?>
		<div class="saso-seating-selector"
			 id="<?php echo esc_attr($instanceId); ?>"
			 data-product-id="<?php echo esc_attr($productId); ?>"
			 data-variation-id="<?php echo esc_attr($variationId); ?>"
			 data-event-date="<?php echo esc_attr($eventDate ?? ''); ?>"
			 data-cart-item-key="<?php echo esc_attr($cartItemKey ?? ''); ?>">
			<input type="hidden" name="<?php echo esc_attr($this->getFieldSeatSelection()); ?>" class="saso-seat-selection-input"
				   value="<?php echo esc_attr($currentSelection ? json_encode($currentSelection) : ''); ?>">
		</div>
		<script type="application/json" id="<?php echo esc_attr($instanceId); ?>-data"><?php echo wp_json_encode($jsData); ?></script>
		<?php
		return ob_get_clean();
	}

	/**
	 * Hat wenigstens eine Variante einen eigenen Sitzplan?
	 *
	 * Auf der Produktseite steht die Variante noch nicht fest. Ohne diese Frage
	 * wuerde ein Produkt ohne eigenen Plan gar keinen Platz fuer die Karte
	 * rendern, und die Plaene seiner Varianten blieben unerreichbar.
	 *
	 * @param int $productId Parent product ID
	 * @return bool
	 */
	public function hasVariationPlans(int $productId): bool {
		if ($productId <= 0) {
			return false;
		}

		global $wpdb;
		$found = $wpdb->get_var($wpdb->prepare(
			"SELECT pm.post_id FROM {$wpdb->postmeta} pm
			 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			 WHERE p.post_parent = %d AND p.post_type = 'product_variation'
			   AND pm.meta_key = %s AND pm.meta_value > 0
			 LIMIT 1",
			$productId,
			$this->MAIN->getSeating()->getMetaVariationSeatingplan()
		));

		return !empty($found);
	}

	/**
	 * Alles, was das JS ueber den Sitzplan wissen muss.
	 *
	 * Eine Stelle baut diese Daten - der erste Seitenaufbau und der Wechsel der
	 * Variante im Browser bekommen dadurch garantiert dasselbe Format.
	 *
	 * @param int $productId Product ID
	 * @param string|null $eventDate Event date
	 * @param array|null $currentSelection Current selected seat data
	 * @param int $variationId Variation ID - its own plan wins over the parent product
	 * @return array|null Data for the JS or null when no plan applies
	 */
	public function buildSelectorData(int $productId, ?string $eventDate = null, ?array $currentSelection = null, int $variationId = 0): ?array {
		// Validate product ID
		if ($productId <= 0) {
			$this->MAIN->getDB()->logError('buildSelectorData: Invalid product ID: ' . $productId);
			return null;
		}

		$manager = $this->MAIN->getSeating();

		// Use frontend method - only returns published plans (or draft for admin preview).
		// A variation carries its own plan; without one it inherits the parent product's.
		$plan = $this->getPlanForProductFrontend($productId, $variationId > 0 ? $variationId : null);

		if (!$plan) {
			return null;
		}

		// Check for user's existing blocks (not yet in cart) - restore on page reload
		$existingBlocks = [];
		if (empty($currentSelection)) {
			$sessionId = WC()->session ? WC()->session->get_customer_id() : session_id();
			$blockManager = $manager->getBlockManager();
			$blocksData = $blockManager->getSessionBlocks($sessionId, $productId, $eventDate);

			if (!empty($blocksData)) {
				foreach ($blocksData as $block) {
					$existingBlocks[] = [
						'seat_id' => (int) $block['seat_id'],
						'seat_label' => $block['seat_label'],
						'seat_category' => $block['seat_category'],
						'seat_desc' => $block['seat_desc'] ?? '',
						'block_id' => (int) $block['id'],
						'expires_at' => $block['expires_at'],
						'event_date' => $block['event_date'],
					];
				}
			}
		}

		// Prepare data for JS
		$seats = $manager->getSeatsWithStatus((int) $plan['id'], $productId, $eventDate);
		$planImageId = $plan['meta']['image_id'] ?? 0;
		$planImage = !empty($planImageId) ? wp_get_attachment_url((int) $planImageId) : '';

		// Calculate remaining seconds for existing blocks
		$now = current_time('timestamp');
		foreach ($existingBlocks as &$block) {
			$expiresTimestamp = strtotime($block['expires_at']);
			$block['remaining_seconds'] = max(0, $expiresTimestamp - $now);
		}
		unset($block);

		// A block belongs to the product, a seat to a plan. When the customer
		// switches to a variation with a different plan, the blocks of the other
		// plan must not show up as a selection here.
		if (!empty($existingBlocks)) {
			$seatIdsOfPlan = array_map(static function ($seat) { return (int) $seat['id']; }, $seats);
			$existingBlocks = array_values(array_filter($existingBlocks, static function ($block) use ($seatIdsOfPlan) {
				return in_array((int) $block['seat_id'], $seatIdsOfPlan, true);
			}));
		}

		$jsData = [
			'planId' => (int) $plan['id'],
			'variationId' => $variationId,
			'planName' => $plan['name'] ?? '',
			'layoutType' => $plan['layout_type'] ?? self::LAYOUT_SIMPLE,
			'isPreview' => !empty($plan['_is_preview']),
			'isRequired' => $manager->isSeatingRequired($productId),
			'planImage' => $planImage,
			'seats' => $seats,
			'currentSelection' => $currentSelection,
			'existingBlocks' => $existingBlocks,
			'meta' => [
				'canvas_width' => $plan['meta']['canvas_width'] ?? 800,
				'canvas_height' => $plan['meta']['canvas_height'] ?? 600,
				'background_color' => $plan['meta']['background_color'] ?? '#ffffff',
				'background_image' => $plan['meta']['background_image'] ?? '',
				'colors' => $plan['meta']['colors'] ?? [],
				'decorations' => $plan['meta']['decorations'] ?? [],
				'lines' => $plan['meta']['lines'] ?? [],
				'labels' => $plan['meta']['labels'] ?? [],
			],
			'adminUrl' => admin_url('admin.php?page=event-tickets-with-ticket-scanner&tab=seating'),
		];

		return $jsData;
	}

	// =========================================================================
	// AJAX Handlers
	// =========================================================================

	/**
	 * Execute Seating Frontend AJAX
	 *
	 * Central switch handler for all seating frontend AJAX requests.
	 * Called via relay_executeSeatingFrontend() in index.php
	 */
	public function executeSeatingFrontend(): void {
		$nonce_mode = $this->MAIN->_js_nonce;
		if (!SASO_EVENTTICKETS::issetRPara('security') || !wp_verify_nonce(SASO_EVENTTICKETS::getRequestPara('security'), $nonce_mode)) {
			wp_send_json(['nonce_fail' => 1]);
			exit;
		}
		if (!SASO_EVENTTICKETS::issetRPara('a')) {
			wp_send_json_error("a not provided");
			return;
		}

		$ret = "";
		$a = trim(SASO_EVENTTICKETS::getRequestPara('a'));
		try {
			switch ($a) {
				case "blockSeat":
					$this->doBlockSeat();
					return; // doBlockSeat calls wp_send_json_* itself
				case "releaseSeat":
					$this->doReleaseSeat();
					return;
				case "getAvailableSeats":
					$this->doGetAvailableSeats();
					return;
				case "getSelectorData":
					$this->doGetSelectorData();
					return;
				default:
					throw new Exception("#7001 " . sprintf(
						esc_html__('function "%s" not implemented', 'event-tickets-with-ticket-scanner'),
						$a
					));
			}
		} catch (Exception $e) {
			$this->MAIN->getAdmin()->logErrorToDB($e);
			wp_send_json_error(['msg' => $e->getMessage()]);
			return;
		}
		wp_send_json_success($ret);
	}

	/**
	 * Internal: Block a seat
	 */
	private function doBlockSeat(): void {
		$seatId = isset($_POST['seat_id']) ? (int) $_POST['seat_id'] : 0;
		$productIdRaw = isset($_POST['product_id']) ? (int) $_POST['product_id'] : 0;
		$eventDate = isset($_POST['event_date']) ? sanitize_text_field($_POST['event_date']) : null;

		if (!$seatId || !$productIdRaw) {
			wp_send_json_error(['error' => 'missing_params']);
			return;
		}

		// WPML: Normalize to original product ID
		$productId = $this->MAIN->getTicketHandler()->getWPMLProductId($productIdRaw);

		$result = $this->MAIN->getSeating()->blockSeatForCart($productId, $seatId, $eventDate);

		if ($result['success']) {
			// Get seat info for response
			$seatInfo = $this->MAIN->getSeating()->getSeatInfo($seatId);

			// Calculate remaining seconds for countdown (avoids timezone issues)
			$expiresTimestamp = strtotime($result['expires_at']);
			$remainingSeconds = max(0, $expiresTimestamp - current_time('timestamp'));

			wp_send_json_success([
				'block_id' => $result['block_id'],
				'expires_at' => $result['expires_at'],
				'remaining_seconds' => $remainingSeconds,
				'extended' => $result['extended'] ?? false,
				'seat' => $seatInfo
			]);
		} else {
			wp_send_json_error($result);
		}
	}

	/**
	 * Internal: Release a seat
	 */
	private function doReleaseSeat(): void {
		$blockId = isset($_POST['block_id']) ? (int) $_POST['block_id'] : 0;

		if (!$blockId) {
			wp_send_json_error(['error' => 'missing_params']);
			return;
		}

		$success = $this->MAIN->getSeating()->releaseSeatFromCart($blockId);

		if ($success) {
			wp_send_json_success();
		} else {
			wp_send_json_error(['error' => 'release_failed']);
		}
	}

	/**
	 * Internal: Sitzplan der gewaehlten Variante nachliefern.
	 *
	 * Der Kunde waehlt die Variante erst im Browser. Welcher Plan dazu gehoert,
	 * entscheidet weiterhin der Server - das JS fragt nur nach.
	 */
	private function doGetSelectorData(): void {
		$productIdRaw = isset($_POST['product_id']) ? (int) $_POST['product_id'] : 0;
		$variationId = isset($_POST['variation_id']) ? (int) $_POST['variation_id'] : 0;
		$eventDate = isset($_POST['event_date']) && $_POST['event_date'] !== ''
			? sanitize_text_field($_POST['event_date'])
			: null;

		if (!$productIdRaw) {
			wp_send_json_error(['error' => 'missing_params']);
			return;
		}

		// WPML: Normalize to original product ID
		$productId = $this->MAIN->getTicketHandler()->getWPMLProductId($productIdRaw);
		$data = $this->buildSelectorData($productId, $eventDate, null, $variationId);

		// Kein Plan ist eine gueltige Antwort: die Variante hat schlicht keinen.
		// _server_time (#015050): sobald der Timestamp im Response vom
		// Absendezeitpunkt abweicht, kam die Antwort aus einem Cache, nicht
		// aus PHP — sofortiges Cache-vs-Live-Erkennungsmerkmal.
		wp_send_json_success(['_server_time' => wp_date('c'), 'selector' => $data]);
	}

	/**
	 * Internal: Get available seats for product/date
	 */
	private function doGetAvailableSeats(): void {
		$productIdRaw = isset($_POST['product_id']) ? (int) $_POST['product_id'] : 0;
		// #015050: '' (no date chosen) must become null — MySQL 8 strict mode
		// rejects `event_date = ''` and the seat query dies silently.
		$eventDate = sasoEventtickets_Seating::normalizeEventDate(
			isset($_POST['event_date']) ? sanitize_text_field($_POST['event_date']) : null
		);

		if (!$productIdRaw) {
			wp_send_json_error(['error' => 'missing_params']);
			return;
		}

		// WPML: Normalize to original product ID
		$productId = $this->MAIN->getTicketHandler()->getWPMLProductId($productIdRaw);

		$plan = $this->getPlanForProduct($productId);

		if (!$plan) {
			wp_send_json_error(['error' => 'no_plan']);
			return;
		}

		$seating = $this->MAIN->getSeating();
		$seats = $seating->getSeatsWithStatus((int) $plan['id'], $productId, $eventDate);
		$stats = $seating->getStats((int) $plan['id'], $eventDate);

		wp_send_json_success([
			'plan' => [
				'id' => $plan['id'],
				'name' => $plan['name'],
				'layout_type' => $plan['layout_type'] ?? self::LAYOUT_SIMPLE
			],
			'seats' => $seats,
			'stats' => $stats,
			// #015050: Cache-vs-Live-Merkmal — weicht _server_time vom
			// Absendezeitpunkt ab, kam die Antwort aus einem Cache.
			'_server_time' => wp_date('c')
		]);
	}

	/**
	 * AJAX: Block a seat (legacy - kept for compatibility)
	 * @deprecated Use executeSeatingFrontend with a=blockSeat instead
	 */
	public function ajaxBlockSeat(): void {
		check_ajax_referer('sasoEventtickets', 'security');

		$seatId = isset($_POST['seat_id']) ? (int) $_POST['seat_id'] : 0;
		$productIdRaw = isset($_POST['product_id']) ? (int) $_POST['product_id'] : 0;
		$eventDate = isset($_POST['event_date']) ? sanitize_text_field($_POST['event_date']) : null;

		if (!$seatId || !$productIdRaw) {
			wp_send_json_error(['error' => 'missing_params']);
			return;
		}

		// WPML: Normalize to original product ID
		$productId = $this->MAIN->getTicketHandler()->getWPMLProductId($productIdRaw);

		$result = $this->MAIN->getSeating()->blockSeatForCart($productId, $seatId, $eventDate);

		if ($result['success']) {
			// Get seat info for response
			$seatInfo = $this->MAIN->getSeating()->getSeatInfo($seatId);

			// Calculate remaining seconds for countdown (avoids timezone issues)
			$expiresTimestamp = strtotime($result['expires_at']);
			$remainingSeconds = max(0, $expiresTimestamp - current_time('timestamp'));

			wp_send_json_success([
				'block_id' => $result['block_id'],
				'expires_at' => $result['expires_at'],
				'remaining_seconds' => $remainingSeconds,
				'extended' => $result['extended'] ?? false,
				'seat' => $seatInfo
			]);
		} else {
			wp_send_json_error($result);
		}
	}

	/**
	 * AJAX: Release a seat
	 */
	public function ajaxReleaseSeat(): void {
		check_ajax_referer('sasoEventtickets', 'security');

		$blockId = isset($_POST['block_id']) ? (int) $_POST['block_id'] : 0;

		if (!$blockId) {
			wp_send_json_error(['error' => 'missing_params']);
			return;
		}

		$success = $this->MAIN->getSeating()->releaseSeatFromCart($blockId);

		if ($success) {
			wp_send_json_success();
		} else {
			wp_send_json_error(['error' => 'release_failed']);
		}
	}

	/**
	 * AJAX: Get available seats for product/date
	 */
	public function ajaxGetAvailableSeats(): void {
		check_ajax_referer('sasoEventtickets', 'security');

		$productIdRaw = isset($_POST['product_id']) ? (int) $_POST['product_id'] : 0;
		$eventDate = isset($_POST['event_date']) ? sanitize_text_field($_POST['event_date']) : null;

		if (!$productIdRaw) {
			wp_send_json_error(['error' => 'missing_params']);
			return;
		}

		// WPML: Normalize to original product ID
		$productId = $this->MAIN->getTicketHandler()->getWPMLProductId($productIdRaw);

		$plan = $this->getPlanForProduct($productId);

		if (!$plan) {
			wp_send_json_error(['error' => 'no_plan']);
			return;
		}

		$seating = $this->MAIN->getSeating();
		$seats = $seating->getSeatsWithStatus((int) $plan['id'], $productId, $eventDate);
		$stats = $seating->getStats((int) $plan['id'], $eventDate);

		wp_send_json_success([
			'plan' => [
				'id' => $plan['id'],
				'name' => $plan['name'],
				'layout_type' => $plan['layout_type'] ?? self::LAYOUT_SIMPLE
			],
			'seats' => $seats,
			'stats' => $stats,
			// #015050: Cache-vs-Live-Merkmal — weicht _server_time vom
			// Absendezeitpunkt ab, kam die Antwort aus einem Cache.
			'_server_time' => wp_date('c')
		]);
	}

	/**
	 * Get cart item seat selection
	 *
	 * @param array $cartItem Cart item data
	 * @return array|null Seat selection data or null
	 */
	public function getCartItemSeatSelection(array $cartItem): ?array {
		$metaKey = $this->getMetaCartItemSeat();
		if (!isset($cartItem[$metaKey])) {
			return null;
		}

		$selection = $cartItem[$metaKey];
		if (is_string($selection)) {
			$selection = json_decode($selection, true);
		}

		return is_array($selection) ? $selection : null;
	}

	/**
	 * Validate seat selection for cart item
	 *
	 * @param int $productId Product ID
	 * @param int $seatId Seat ID
	 * @param string|null $eventDate Event date
	 * @return array Validation result: ['valid' => bool, 'error' => string]
	 */
	public function validateSeatSelection(int $productId, int $seatId, ?string $eventDate = null): array {
		// Validate IDs
		if ($productId <= 0) {
			$this->MAIN->getDB()->logError('validateSeatSelection: Invalid product ID: ' . $productId);
			return ['valid' => false, 'error' => 'invalid_product'];
		}
		if ($seatId <= 0) {
			$this->MAIN->getDB()->logError('validateSeatSelection: Invalid seat ID: ' . $seatId);
			return ['valid' => false, 'error' => 'invalid_seat'];
		}

		// Check if product has seating plan
		$plan = $this->getPlanForProduct($productId);
		if (!$plan) {
			return ['valid' => false, 'error' => 'no_seating_plan'];
		}

		$seating = $this->MAIN->getSeating();

		// Check if seat exists and belongs to this plan
		$seat = $seating->getSeatManager()->getById($seatId);
		if (!$seat || (int) $seat['seatingplan_id'] !== (int) $plan['id']) {
			return ['valid' => false, 'error' => 'invalid_seat'];
		}

		// Check if seat is active
		if ((int) $seat['aktiv'] !== 1) {
			return ['valid' => false, 'error' => 'seat_inactive'];
		}

		// Check availability - exclude current session's blocks (user's own blocks are allowed)
		$sessionId = $this->getSessionId();
		if (!$seating->getBlockManager()->isSeatAvailable($seatId, $productId, $eventDate, $sessionId)) {
			return ['valid' => false, 'error' => 'seat_unavailable'];
		}

		return ['valid' => true];
	}
}
