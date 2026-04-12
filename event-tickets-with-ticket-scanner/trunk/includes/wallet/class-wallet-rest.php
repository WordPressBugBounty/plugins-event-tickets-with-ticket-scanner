<?php
/**
 * Vollstart Wallet REST API
 *
 * Provides a public REST endpoint for the Vollstart Wallet PWA (wallet.vollstart.com)
 * to fetch ticket data from any shop running this plugin.
 *
 * @since 3.0.2
 */

if (!defined('ABSPATH')) exit;

class sasoEventtickets_Wallet_REST {

	private const WALLET_ORIGINS = [
		'https://wallet.vollstart.com',
	];
	private const API_VERSION = '1.0.0';

	protected sasoEventtickets $MAIN;

	public function __construct(sasoEventtickets $MAIN) {
		$this->MAIN = $MAIN;
	}

	/**
	 * Register REST routes for the wallet API.
	 */
	public function register_routes(): void {
		register_rest_route('saso-eventtickets/v1', '/wallet/ticket/(?P<public_id>[a-zA-Z0-9_\-]+)', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [$this, 'rest_get_wallet_ticket'],
			'permission_callback' => '__return_true',
			'args'                => [
				'public_id' => [
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				],
			],
		]);
	}

	/**
	 * Register CORS headers for wallet routes.
	 */
	public function register_cors(): void {
		$allowed = self::WALLET_ORIGINS;

		add_action('rest_pre_serve_request', function ($served, $result, $request) use ($allowed) {
			$route = $request->get_route();
			if (strpos($route, '/saso-eventtickets/v1/wallet/') === 0) {
				$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
				if (in_array($origin, $allowed, true)) {
					header('Access-Control-Allow-Origin: ' . $origin);
					header('Access-Control-Allow-Methods: GET, OPTIONS');
					header('Access-Control-Allow-Headers: Content-Type');
				}
			}
			return $served;
		}, 10, 3);

		// Handle OPTIONS preflight
		add_filter('rest_pre_dispatch', function ($result, $server, $request) use ($allowed) {
			if ($request->get_method() === 'OPTIONS') {
				$route = $request->get_route();
				if (strpos($route, '/saso-eventtickets/v1/wallet/') === 0) {
					$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
					if (in_array($origin, $allowed, true)) {
						header('Access-Control-Allow-Origin: ' . $origin);
						header('Access-Control-Allow-Methods: GET, OPTIONS');
						header('Access-Control-Allow-Headers: Content-Type');
						return new \WP_REST_Response(null, 204);
					}
				}
			}
			return $result;
		}, 10, 3);
	}

	/**
	 * Handle GET request for a single wallet ticket.
	 */
	public function rest_get_wallet_ticket(\WP_REST_Request $request): \WP_REST_Response {
		$public_id = $request->get_param('public_id');

		try {
			[$codeObj, $metaObj] = $this->resolve_public_id($public_id);
		} catch (\Exception $e) {
			$code = $e->getCode();
			if ($code === 400) {
				return new \WP_REST_Response(['error' => 'invalid_ticket_id', 'message' => $e->getMessage()], 400);
			}
			return new \WP_REST_Response(['error' => 'ticket_not_found', 'message' => $e->getMessage()], 404);
		}

		$response = $this->build_response($codeObj, $metaObj);
		return new \WP_REST_Response($response, 200);
	}

	/**
	 * Resolve a public ticket ID to code object and meta object.
	 *
	 * Public ID format: {idcode}-{order_id}-{code}
	 *
	 * @throws \Exception on invalid/not-found ticket
	 * @return array{0: array, 1: array} [$codeObj, $metaObj]
	 */
	private function resolve_public_id(string $public_id): array {
		$parts = explode('-', $public_id, 3);
		if (count($parts) !== 3 || empty($parts[0]) || empty($parts[1]) || empty($parts[2])) {
			throw new \Exception('Invalid ticket ID format', 400);
		}

		[$idcode, $order_id, $code] = $parts;

		try {
			$codeObj = $this->MAIN->getCore()->retrieveCodeByCode($code);
		} catch (\Exception $e) {
			throw new \Exception('Ticket not found', 404);
		}

		// Validate order_id matches
		if (intval($codeObj['order_id']) !== intval($order_id)) {
			throw new \Exception('Ticket not found', 404);
		}

		// Parse meta and validate idcode
		$codeObj = $this->MAIN->getCore()->setMetaObj($codeObj);
		$metaObj = $codeObj['metaObj'];

		if (empty($metaObj['wc_ticket']['idcode']) || strval($metaObj['wc_ticket']['idcode']) !== strval($idcode)) {
			throw new \Exception('Ticket not found', 404);
		}

		// Must be a ticket (not just a code)
		if (intval($metaObj['wc_ticket']['is_ticket'] ?? 0) !== 1) {
			throw new \Exception('Ticket not found', 404);
		}

		// Verify order is paid
		$order = wc_get_order($codeObj['order_id']);
		if (!$order || !$order->is_paid()) {
			throw new \Exception('Ticket not found', 404);
		}

		return [$codeObj, $metaObj];
	}

	/**
	 * Determine the ticket status.
	 */
	private function get_ticket_status(array $codeObj, array $metaObj): string {
		if (intval($codeObj['aktiv'] ?? 1) !== 1) {
			return 'cancelled';
		}
		if (!empty($metaObj['wc_ticket']['redeemed_date'])) {
			return 'redeemed';
		}
		if ($this->MAIN->getCore()->checkCodeExpired($codeObj)) {
			return 'expired';
		}
		return 'valid';
	}

	/**
	 * Build the JSON response for a wallet ticket.
	 */
	private function build_response(array $codeObj, array $metaObj): array {
		$core = $this->MAIN->getCore();
		$ticketHandler = $this->MAIN->getTicketHandler();
		$public_id = $core->getTicketId($codeObj, $metaObj);

		// Get product info
		$product_id = intval($metaObj['woocommerce']['product_id'] ?? 0);
		$product = $product_id > 0 ? wc_get_product($product_id) : null;
		$parent_product = null;
		if ($product && $product->get_parent_id() > 0) {
			$parent_product = wc_get_product($product->get_parent_id());
		}
		$display_product = $parent_product ?: $product;

		// Get event dates via existing method
		$event = [
			'name'       => $display_product ? $display_product->get_name() : '',
			'start_date' => '',
			'start_time' => '',
			'end_date'   => '',
			'end_time'   => '',
			'location'   => '',
		];

		if ($product_id > 0) {
			try {
				$dates = $ticketHandler->calcDateStringAllowedRedeemFrom($product_id, $codeObj);
				if (!empty($dates['is_date_set']) && $dates['is_date_set'] === true) {
					$event['start_date'] = $dates['ticket_start_date'] ?? '';
					$event['start_time'] = $dates['ticket_start_time'] ?? '';
					$event['end_date']   = $dates['ticket_end_date'] ?? '';
					$event['end_time']   = $dates['ticket_end_time'] ?? '';
				}
			} catch (\Exception $e) {
				// dates not available
			}

			$location_product_id = $display_product ? $display_product->get_id() : $product_id;
			$location_product_id = $ticketHandler->getWPMLProductId($location_product_id);
			$event['location'] = trim(get_post_meta($location_product_id, 'saso_eventtickets_event_location', true));
		}

		// Seat info
		$seat = [
			'label'    => $metaObj['wc_ticket']['seat_label'] ?? '',
			'category' => $metaObj['wc_ticket']['seat_category'] ?? '',
		];

		// QR data
		$qr_data = $core->getQRCodeContent($codeObj, $metaObj);

		// Downloads (empty in free, extensible via filter for premium)
		$downloads = apply_filters('saso_eventtickets_wallet_downloads', [], $codeObj, $metaObj);

		return [
			'ticket_id'      => $public_id,
			'code'           => $codeObj['code'],
			'status'         => $this->get_ticket_status($codeObj, $metaObj),
			'event'          => $event,
			'seat'           => $seat,
			'qr_data'        => $qr_data,
			'shop'           => [
				'name' => get_bloginfo('name'),
				'url'  => site_url(),
			],
			'downloads'      => $downloads,
			'redeemed_at'    => !empty($metaObj['wc_ticket']['redeemed_date']) ? $metaObj['wc_ticket']['redeemed_date'] : null,
			'wallet_version' => self::API_VERSION,
		];
	}
}
