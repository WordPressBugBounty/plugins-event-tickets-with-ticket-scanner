<?php
/**
 * WooCommerce Order Manager
 *
 * Handles order lifecycle, ticket generation, and refunds for WooCommerce orders.
 *
 * @package    Event_Tickets_With_Ticket_Scanner
 * @subpackage WooCommerce
 * @since      2.9.0
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
	exit;
}

/**
 * Order Manager Class
 *
 * Manages ticket lifecycle for WooCommerce orders including:
 * - Ticket generation on order completion
 * - Order status change handling
 * - Refund processing
 * - WooCommerce Subscriptions integration
 * - Order metadata display
 *
 * **CRITICAL:** This class contains core ticket generation logic.
 * Any bugs here will break the entire plugin functionality.
 *
 * @since 2.9.0
 */
if (!class_exists('sasoEventtickets_WC_Order')) {
	class sasoEventtickets_WC_Order extends sasoEventtickets_WC_Base {

		/**
		 * Temporary storage for refund parent order ID
		 *
		 * Used to track parent order when processing refund deletions
		 *
		 * @var int|null
		 */
		private $refund_parent_id;

		/**
		 * Constructor
		 *
		 * @param sasoEventtickets $main Main plugin instance
		 */
		public function __construct($main) {
			parent::__construct($main);
		}

		/**
		 * Handle new order creation - clean up session data
		 *
		 * @param int $order_id The new order ID
		 * @return void
		 */
		public function woocommerce_new_order(int $order_id): void {
			if (WC() !== null && WC()->session !== null) {
				WC()->session->__unset('saso_eventtickets_request_name_per_ticket');
				WC()->session->__unset('saso_eventtickets_request_value_per_ticket');
				do_action($this->MAIN->_do_action_prefix . 'woocommerce-hooks_woocommerce_new_order', $order_id);
			}
		}

		/**
		 * Check if order contains ticket products
		 *
		 * @param WC_Order $order Order object
		 * @return bool True if order has ticket products
		 */
		public function hasTicketsInOrder($order): bool {
			$items = $order->get_items();
			foreach ($items as $item_id => $item) {
				$product_id = $item->get_product_id();
				$product_id_orig = $this->MAIN->getTicketHandler()->getWPMLProductId($product_id);
				if (get_post_meta($product_id_orig, self::META_PRODUCT_IS_TICKET, true) == "yes") {
					return true;
				}
			}
			return false;
		}

		/**
		 * Check if order contains tickets with assigned ticket numbers
		 *
		 * @param WC_Order $order Order object
		 * @return bool True if order has tickets with numbers assigned
		 */
		public function hasTicketsInOrderWithTicketnumber($order): bool {
			$items = $order->get_items();
			foreach ($items as $item_id => $item) {
				$product_id = $item->get_product_id();
				$product_id_orig = $this->MAIN->getTicketHandler()->getWPMLProductId($product_id);
				if (get_post_meta($product_id_orig, self::META_PRODUCT_IS_TICKET, true) == "yes") {
					$codes = wc_get_order_item_meta($item_id, self::META_ORDER_ITEM_CODES, true);
					if (!empty($codes)) {
						return true;
					}
				}
			}
			return false;
		}

		/**
		 * Get all tickets from order
		 *
		 * Returns array of ticket products with their codes and metadata.
		 *
		 * @param WC_Order $order Order object
		 * @return array Tickets array with product info and codes
		 */
		public function getTicketsFromOrder($order): array {
			$products = [];
			$items = $order->get_items();

			foreach ($items as $item_id => $item) {
				$product_id = $item->get_product_id();
				$product_id_orig = $this->MAIN->getTicketHandler()->getWPMLProductId($product_id);

				if (get_post_meta($product_id_orig, self::META_PRODUCT_IS_TICKET, true) == "yes") {
					$codes = wc_get_order_item_meta($item_id, self::META_ORDER_ITEM_CODES, true);
					$key = $product_id . "_" . $item_id;
					$products[$key] = [
						"quantity" => $item->get_quantity(),
						"codes" => $codes,
						"product_id" => $product_id,
						"product_id_orig" => $product_id_orig,
						"order_item_id" => $item_id
					];
				}
			}

			return $products;
		}

		/**
		 * Handle order status changes
		 *
		 * **CRITICAL METHOD:** This triggers ticket generation when order is completed.
		 *
		 * @param int $order_id Order ID
		 * @param string $old_status Old order status
		 * @param string $new_status New order status
		 * @return void
		 */
		public function woocommerce_order_status_changed(int $order_id, string $old_status, string $new_status): void {
			// Don't generate tickets for refunded or cancelled orders
			if ($new_status != "refunded" && $new_status != "cancelled" && $new_status != "wc-refunded" && $new_status != "wc-cancelled") {
				$this->add_serialcode_to_order($order_id); // Generate tickets - may have been manually added products
			}

			// Handle order cancellation/refund - free up codes if option is enabled
			if ($new_status == "cancelled" || $new_status == "wc-cancelled" || $new_status == "wc-refunded" || $new_status == "refunded") {
				// Always release seat blocks for cancelled/refunded orders
				try {
					$this->MAIN->getSeating()->getBlockManager()->releaseSeatsByOrderId($order_id);
				} catch (Exception $e) {
					$this->MAIN->getAdmin()->logErrorToDB($e, "", "while releasing seat blocks for cancelled order " . $order_id);
				}

				// Only free up ticket codes if option is enabled
				if ($this->MAIN->getOptions()->isOptionCheckboxActive('wcRestrictFreeCodeByOrderRefund')) {
					$order = wc_get_order($order_id);
					$items = $order->get_items();
					foreach ($items as $item_id => $item) {
						$this->woocommerce_delete_order_item($item_id);
					}
				}
			}

			do_action($this->MAIN->_do_action_prefix . 'woocommerce-hooks_woocommerce_order_status_changed', $order_id, $old_status, $new_status);
		}

		/**
		 * Main ticket generation entry point
		 *
		 * **CRITICAL METHOD:** Generates tickets for all items in an order.
		 * Iterates through all order items and generates tickets for each ticket product.
		 * Handles order status checks, day chooser preprocessing, and session cleanup.
		 *
		 * @param int $order_id Order ID
		 * @return void
		 */
		public function add_serialcode_to_order(int $order_id): void {

			if (!$order_id) return;

			// Getting an instance of the order object
			$order = wc_get_order($order_id);
			if (!$order) return;

			$create_tickets = SASO_EVENTTICKETS::isOrderPaid($order);
			$ok_order_statuses = $this->MAIN->getOptions()->getOptionValue('wcTicketAddToOrderOnlyWithOrderStatus');
			if (is_array($ok_order_statuses) && count($ok_order_statuses) > 0) {
				$order_status = $order->get_status();
				$create_tickets = in_array($order_status, $ok_order_statuses);
			}
			if ($create_tickets == false) {
				$param_data = SASO_EVENTTICKETS::getRequestPara('data');
				if (SASO_EVENTTICKETS::issetRPara('a_sngmbh') && SASO_EVENTTICKETS::getRequestPara('a_sngmbh') == "premium"
					&& $param_data != null && isset($param_data['c'])
					&& $param_data['c'] == "requestSerialsForOrder") {
					// premium add btn on order details overwrite the false
					$create_tickets = true;
				} else {
					// add the day per ticket if needed before the ticket number is added
					$items = $order->get_items();
					foreach ($items as $item_id => $item) {
						$product_id = $item->get_product_id();
						if ($product_id) {
							$product_id_orig = $this->MAIN->getTicketHandler()->getWPMLProductId($product_id);
							$isTicket = get_post_meta($product_id_orig, self::META_PRODUCT_IS_TICKET, true) == "yes";
							if ($isTicket) {
								$variation_id = $item->get_variation_id();
								if ($variation_id > 0) {
									// check ob diese variation vom ticket ausgeschlossen ist
									if (get_post_meta($variation_id, self::META_VARIATION_NOT_TICKET, true) == "yes") {
										continue;
									}
								}

								// check if it is a daychooser
								$isDaychooser = get_post_meta($product_id_orig, self::META_PRODUCT_IS_DAYCHOOSER, true) == "yes";
								if ($isDaychooser) {
									$days_per_ticket = [];
									$daychooser = wc_get_order_item_meta($item_id, self::SESSION_KEY_DAYCHOOSER, true);
									if ($daychooser != null && is_array($daychooser) && count($daychooser) > 0) {
										$quantity = $item->get_quantity();
										for ($a = 0; $a < $quantity; $a++) {
											if (isset($daychooser[$a])) {
												$days_per_ticket[] = $daychooser[$a];
											}
										}
										wc_delete_order_item_meta($item_id, self::META_ORDER_ITEM_DAYCHOOSER);
										wc_add_order_item_meta($item_id, self::META_ORDER_ITEM_DAYCHOOSER, is_array($days_per_ticket) ? implode(",", $days_per_ticket) : $days_per_ticket);
									}
								}
							}
						}
					}
				}
			}

			if ($create_tickets) {
				$items = $order->get_items();
				foreach ($items as $item_id => $item) {
					$product_id = $item->get_product_id();
					if ($product_id) {
						$product_id_orig = $this->MAIN->getTicketHandler()->getWPMLProductId($product_id);
						$isTicket = get_post_meta($product_id_orig, self::META_PRODUCT_IS_TICKET, true) == "yes";
						if ($isTicket) {
							$variation_id = $item->get_variation_id();
							if ($variation_id > 0) {
								// check ob diese variation vom ticket ausgeschlossen ist
								if (get_post_meta($variation_id, self::META_VARIATION_NOT_TICKET, true) == "yes") {
									continue;
								}
							}
							$code_list_id = get_post_meta($product_id_orig, self::META_PRODUCT_LIST, true);
							if (!empty($code_list_id)) {
								$this->add_serialcode_to_order_forItem($order_id, $order, $item_id, $item, $code_list_id);
							}
						}
					}
				} // end foreach
			}

			if (isset(WC()->session)) {
				$session_keys = ['saso_eventtickets_request_name_per_ticket', 'saso_eventtickets_request_value_per_ticket'];
				if (!WC()->session->has_session()) {
					if (method_exists(WC()->session, '__unset')) {
						foreach ($session_keys as $k) {
							WC()->session->__unset($k);
						}
					} else {
						if (method_exists(WC()->session, '__isset')) {
							foreach ($session_keys as $k) {
								if (WC()->session->__isset($k)) {
									WC()->session->set($k, []);
								}
							}
						}
					}
				}
			}

		}

		/**
		 * Generate tickets for a single order item
		 *
		 * **CRITICAL METHOD:** Core per-item ticket creation logic.
		 * Generates ticket codes, assigns them to order items, handles name/value per ticket,
		 * day chooser functionality, and subscription ticket reuse.
		 *
		 * @param int $order_id Order ID
		 * @param WC_Order $order Order object
		 * @param int $item_id Order item ID
		 * @param WC_Order_Item $item Order item
		 * @param int $saso_eventtickets_list Ticket list ID
		 * @return array Generated ticket codes
		 */
		public function add_serialcode_to_order_forItem(int $order_id, $order, int $item_id, $item, int $saso_eventtickets_list): array {
			$ret = [];
			$product_id = $item->get_product_id();
			$product_original_id = $this->MAIN->getTicketHandler()->getWPMLProductId($product_id);

			$item_variation_id = $item->get_variation_id();
			$item_variation_original_id = $this->MAIN->getTicketHandler()->getWPMLProductId($item_variation_id);

			if ($saso_eventtickets_list) {

				if ($item->get_variation_id() > 0) {
					$saso_eventtickets_ticket_amount_per_item = intval(get_post_meta($item_variation_original_id, self::META_PRODUCT_TICKETS_PER_ITEM, true));
				} else {
					$saso_eventtickets_ticket_amount_per_item = intval(get_post_meta($product_original_id, self::META_PRODUCT_TICKETS_PER_ITEM, true));
				}
				if ($saso_eventtickets_ticket_amount_per_item < 1) {
					$saso_eventtickets_ticket_amount_per_item = 1;
				}

				$item_qty_refunded = $order->get_qty_refunded_for_item($item_id);
				$quantity = $item->get_quantity() + $item_qty_refunded;
				$quantity *= $saso_eventtickets_ticket_amount_per_item;
				$quantity_needed = $quantity;

				$codes = [];
				$existingCode = wc_get_order_item_meta($item_id, self::META_ORDER_ITEM_CODES, true); // if called repeatly, do not overwrite existing codes
				if (!empty($existingCode)) {
					$codes = explode(",", $existingCode);
					$quantity_needed = $quantity - count($codes);
				}

				// check if we should extend the ticket with a subscription
				// only if there is a subscription product in the order
				$subscription_codes = $this->checkSubscriptionAndGetTicketsOfProduct($order, $product_id, $item);
				if (is_array($subscription_codes) && count($subscription_codes) > 0) {
					//$codes = array_merge($codes, $subscription_codes);
					//$quantity_needed = $quantity - count($subscription_codes);
					// better overwrite the codes, so we do not have duplicates
					$codes = $subscription_codes;
					$quantity_needed = 0;
				}

				if ($quantity_needed > 0) {
					$product_formatter_values = "";
					if (get_post_meta($product_original_id, 'saso_eventtickets_list_formatter', true) == "yes") {
						$product_formatter_values = get_post_meta($product_original_id, 'saso_eventtickets_list_formatter_values', true);
					}

					$values = [];
					$namesPerTicket = wc_get_order_item_meta($item_id, 'saso_eventtickets_request_name_per_ticket', true);
					if ($namesPerTicket != null && is_array($namesPerTicket) && count($namesPerTicket) > 0) {
						$values = $namesPerTicket;
					}
					$values2 = [];
					$valuesPerTicket = wc_get_order_item_meta($item_id, 'saso_eventtickets_request_value_per_ticket', true);
					if ($valuesPerTicket != null && is_array($valuesPerTicket) && count($valuesPerTicket) > 0) {
						$values2 = $valuesPerTicket;
					}
					$daychooser = [];
					$daysPerTicket = wc_get_order_item_meta($item_id, self::SESSION_KEY_DAYCHOOSER, true);
					if ($daysPerTicket != null && is_array($daysPerTicket) && count($daysPerTicket) > 0) {
						$daychooser = $daysPerTicket;
					}

					// Seat selection data (array of seats, one per ticket)
					$seatsPerTicket = [];
					$seatMetaKey = $this->MAIN->getSeating()->getMetaCartItemSeat();
					$seatsData = wc_get_order_item_meta($item_id, $seatMetaKey, true);
					if (!empty($seatsData) && is_array($seatsData)) {
						// Normalize to array format (could be single seat or array of seats)
						if (isset($seatsData['seat_id'])) {
							$seatsPerTicket = [$seatsData];
						} else {
							$seatsPerTicket = $seatsData;
						}
					}

					$public_ticket_ids_value = wc_get_order_item_meta($item_id, self::META_ORDER_ITEM_PUBLIC_IDS, true);
					$public_ticket_ids = !empty($public_ticket_ids_value) ? explode(",", $public_ticket_ids_value) : [];

					$new_codes = [];
					$days_per_ticket = [];
					$offset = count($codes);

					for ($a = 0; $a < $quantity_needed; $a++) {
						$namePerTicket = "";
						if (isset($values[$offset + $a])) {
							$namePerTicket = $values[$offset + $a];
						}
						$valuePerTicket = "";
						if (isset($values2[$offset + $a])) {
							$valuePerTicket = $values2[$offset + $a];
						}
						$dayPerTicket = "";
						if (isset($daychooser[$offset + $a])) {
							$dayPerTicket = $daychooser[$offset + $a];
						}
						$seatPerTicket = null;
						if (isset($seatsPerTicket[$offset + $a])) {
							$seatPerTicket = $seatsPerTicket[$offset + $a];
						}
						$newcode = "";
						try {
							$newcode = $this->MAIN->getAdmin()->addCodeFromListForOrder($saso_eventtickets_list, $order_id, $product_id, $item_id, $product_formatter_values);
						} catch (Exception $e) {
							// error handling
							$order = wc_get_order($order_id);
							$order->add_order_note(esc_html__("Free ticket numbers used up. Added placeholder", 'event-tickets-with-ticket-scanner'));
							// for now ignoring them
						}
						$codeObj = null;
						try {
							$codeObj = $this->MAIN->getAdmin()->setWoocommerceTicketInfoForCode($newcode, $namePerTicket, $valuePerTicket, $dayPerTicket, $seatPerTicket);
						} catch (Exception $e) {
							$this->MAIN->getAdmin()->logErrorToDB($e, "", "while processing the order and storing the name-value per tickets. " . $newcode . " - " . $namePerTicket . " - " . $valuePerTicket);
						}
						if ($codeObj != null) {
							$metaObj = $this->MAIN->getCore()->encodeMetaValuesAndFillObject($codeObj['meta'], $codeObj);
							$public_ticket_ids[] = $metaObj['wc_ticket']['_public_ticket_id'];

							// Confirm seat block if seat was selected
							if (!empty($seatPerTicket) && !empty($seatPerTicket['seat_id'])) {
								try {
									$blockManager = $this->MAIN->getSeating()->getBlockManager();
									$sessionId = WC()->session ? WC()->session->get_customer_id() : '';

									// Use block_id if available, otherwise find by seat/product/session
									if (!empty($seatPerTicket['block_id'])) {
										$blockManager->confirmBlock(
											(int) $seatPerTicket['block_id'],
											$order_id,
											$item_id,
											(int) $codeObj['id']
										);
									} else {
										$blockManager->confirmSeatForOrder(
											(int) $seatPerTicket['seat_id'],
											$product_id,
											$sessionId,
											$order_id,
											$item_id,
											(int) $codeObj['id'],
											$dayPerTicket ?: null
										);
									}
								} catch (Exception $e) {
									$this->MAIN->getAdmin()->logErrorToDB($e, "", "while confirming seat block for order. Seat ID: " . ($seatPerTicket['seat_id'] ?? 'unknown'));
								}
							}
						}
						$new_codes[] = $newcode;
						$days_per_ticket[] = $dayPerTicket;
					} // end for quantity_needed
					$codes = array_merge($codes, $new_codes);
					$ret = $this->addMetaToOrderItem($item_id, $codes, $saso_eventtickets_list, $days_per_ticket, $public_ticket_ids);
				}
			}
			return $ret;
		}

		/**
		 * Handle partial refunds
		 *
		 * Removes appropriate number of tickets when order is partially refunded.
		 *
		 * @param int $order_id Order ID
		 * @param int $refund_id Refund ID
		 * @return void
		 */
		public function woocommerce_order_partially_refunded(int $order_id, int $refund_id): void {
			if ($this->MAIN->getOptions()->isOptionCheckboxActive('wcassignmentOrderItemRefund')) {
				$order = wc_get_order($order_id);

				foreach ($order->get_items() as $item_id => $item) {
					$product = $item->get_product();
					if ($product == null) continue;
					$product_id = $item->get_product_id();
					$product_id_orig = $this->MAIN->getTicketHandler()->getWPMLProductId($product_id);

					$isTicket = get_post_meta($product_id_orig, self::META_PRODUCT_IS_TICKET, true) == "yes";
					if ($isTicket == false) continue;
					$variation_id = $item->get_variation_id();
					if ($variation_id > 0) {
						// check ob diese variation vom ticket ausgeschlossen ist
						if (get_post_meta($variation_id, self::META_VARIATION_NOT_TICKET, true) == "yes") {
							continue;
						}
					}

					$item_qty_refunded = $order->get_qty_refunded_for_item($item_id);
					if ($item_qty_refunded >= 0) continue;

					$existingCodes = wc_get_order_item_meta($item_id, self::META_ORDER_ITEM_CODES, true);
					if (empty($existingCodes)) continue;

					// check how many codes should be there, with the refund
					if ($item->get_variation_id() > 0) {
						$saso_eventtickets_ticket_amount_per_item = intval(get_post_meta($item->get_variation_id(), self::META_PRODUCT_TICKETS_PER_ITEM, true));
					} else {
						$saso_eventtickets_ticket_amount_per_item = intval(get_post_meta($product_id_orig, self::META_PRODUCT_TICKETS_PER_ITEM, true));
					}
					if ($saso_eventtickets_ticket_amount_per_item < 1) {
						$saso_eventtickets_ticket_amount_per_item = 1;
					}
					$new_quantity = $item->get_quantity() + $item_qty_refunded; // new quantity without the refunded
					$new_quantity *= $saso_eventtickets_ticket_amount_per_item;

					$old_codes = explode(",", $existingCodes);
					$count_codes = count($old_codes);
					if ($count_codes > $new_quantity) {
						$codes = array_slice($old_codes, 0, $new_quantity);

						$public_ticket_ids_value = wc_get_order_item_meta($item_id, self::META_ORDER_ITEM_PUBLIC_IDS, true);
						$existing_plublic_ticket_ids = explode(",", $public_ticket_ids_value);
						$public_ticket_ids = [];
						if (count($existing_plublic_ticket_ids) > $new_quantity) {
							$values = array_slice($existing_plublic_ticket_ids, 0, $new_quantity);
							foreach ($values as $public_ticket_id) {
								$public_ticket_id = trim($public_ticket_id);
								if (empty($public_ticket_id)) continue;
								$public_ticket_ids[] = $public_ticket_id;
							}
						}

						// save new values
						wc_delete_order_item_meta($item_id, self::META_ORDER_ITEM_CODES);
						wc_add_order_item_meta($item_id, self::META_ORDER_ITEM_CODES, implode(",", $codes));
						wc_delete_order_item_meta($item_id, self::META_ORDER_ITEM_PUBLIC_IDS);
						wc_add_order_item_meta($item_id, self::META_ORDER_ITEM_PUBLIC_IDS, implode(",", $public_ticket_ids));

						// delete tickets
						$codes_to_delete = array_slice($old_codes, $new_quantity);
						foreach ($codes_to_delete as $code) {
							$code = trim($code);
							if (empty($code)) continue;

							// remove used info - if it is a real ticket number and not the free max usage message
							$data = ['code' => $code];
							try {
								$this->MAIN->getAdmin()->removeUsedInformationFromCode($data);
								$this->MAIN->getAdmin()->removeWoocommerceOrderInfoFromCode($data);
								$this->MAIN->getAdmin()->removeWoocommerceRstrPurchaseInfoFromCode($data);
								$order->add_order_note(sprintf(/* translators: %s: ticket number */esc_html__('Refunded ticket(s). Ticket number %s removed for order item id: %s.', 'event-tickets-with-ticket-scanner'), esc_attr($code), esc_attr($item_id)));
							} catch (Exception $e) {
								$this->MAIN->getAdmin()->logErrorToDB($e);
							}
						}
					}
				} // endfor each
			}
		}

		/**
		 * Transform order item meta key display labels
		 *
		 * @param string $display_key Display key
		 * @param object $meta Meta object
		 * @param WC_Order_Item $item Order item
		 * @return string Modified display key
		 */
		public function woocommerce_order_item_display_meta_key(string $display_key, $meta, $item): string {
			if (is_admin() && $item->get_type() === 'line_item') {
				// Change displayed label for specific order item meta key
				if ($meta->key === self::META_ORDER_ITEM_CODES) {
					$isTicket = $item->get_meta(self::META_ORDER_ITEM_IS_TICKET) == 1 ? true : false;
					if ($isTicket) {
						$display_key = __("Ticket number(s)", 'event-tickets-with-ticket-scanner');
					} else {
						$display_key = _x("Code", "noun", 'event-tickets-with-ticket-scanner');
					}
				}
				if ($meta->key === self::META_ORDER_ITEM_PUBLIC_IDS) {
					$display_key = __("Public Ticket Id(s)", 'event-tickets-with-ticket-scanner');
				}
				if ($meta->key === self::META_ORDER_ITEM_CODE_LIST) {
					$display_key = __("List ID", 'event-tickets-with-ticket-scanner');
				}
				if ($meta->key === self::META_ORDER_ITEM_IS_TICKET) {
					$display_key = __("Is Ticket", 'event-tickets-with-ticket-scanner');
				}
				if ($meta->key === self::META_ORDER_ITEM_DAYCHOOSER) {
					$display_key = __("Day(s) per ticket", 'event-tickets-with-ticket-scanner');
				}
				// Seat selection (array - hidden by WooCommerce due to !is_scalar)
				if ($meta->key === $this->MAIN->getSeating()->getMetaCartItemSeat()) {
					$seatLabelText = esc_attr($this->MAIN->getOptions()->getOptionValue('wcTicketTransSeat'));
					if (empty($seatLabelText)) $seatLabelText = __('Seat', 'event-tickets-with-ticket-scanner');
					$display_key = $seatLabelText;
				}
				// Seat labels (string for display)
				if ($meta->key === self::META_ORDER_ITEM_SEAT_LABELS) {
					$seatLabelText = esc_attr($this->MAIN->getOptions()->getOptionValue('wcTicketTransSeat'));
					if (empty($seatLabelText)) $seatLabelText = __('Seat', 'event-tickets-with-ticket-scanner');
					$display_key = $seatLabelText;
				}
				// Seat IDs (for debugging/repair)
				if ($meta->key === self::META_ORDER_ITEM_SEAT_IDS) {
					$display_key = __('Seat ID', 'event-tickets-with-ticket-scanner');
				}
				// Seating Plan ID
				if ($meta->key === self::META_ORDER_ITEM_SEATING_PLAN_ID) {
					$display_key = __('Seating Plan', 'event-tickets-with-ticket-scanner');
				}

				// Label for purchase restriction code
				if ($meta->key === self::META_KEY_CODELIST_RESTRICTION_ORDER_ITEM) {
					$display_key = esc_attr($this->MAIN->getOptions()->getOptionValue('wcRestrictPrefixTextCode'));
				}
			}

			$display_key = apply_filters($this->MAIN->_add_filter_prefix . 'woocommerce-hooks_woocommerce_order_item_display_meta_key', $display_key, $meta, $item);

			return $display_key;
		}

		/**
		 * Transform order item meta value display
		 *
		 * @param string $meta_value Meta value
		 * @param object $meta Meta object
		 * @param WC_Order_Item $item Order item
		 * @return string Modified meta value
		 */
		public function woocommerce_order_item_display_meta_value(string $meta_value, $meta, $item): string {
			if (is_admin() && $item->get_type() === 'line_item') {
				if ($meta->key === self::META_ORDER_ITEM_CODES) {
					$codes = explode(",", $meta_value);
					$codes_ = [];
					foreach ($codes as $c) {
						$codes_[] = '<a target="_blank" href="admin.php?page=event-tickets-with-ticket-scanner&code=' . urlencode($c) . '">' . $c . '</a>';
					}
					$meta_value = implode(", ", $codes_);
				} else if ($meta->key === self::META_ORDER_ITEM_PUBLIC_IDS) {
					$codes = explode(",", $meta_value);
					$_codes = [];
					foreach ($codes as $c) {
						$c = trim($c);
						if (!empty($c)) {
							$_codes[] = $c;
						}
					}
					if (count($_codes) > 0) {
						$meta_value = implode(", ", $_codes);
					} else {
						$meta_value = '-';
					}
				} else if ($meta->key === self::META_ORDER_ITEM_IS_TICKET) {
					$meta_value = $meta_value == 1 ? "Yes" : "No";
				} else if ($meta->key === self::META_ORDER_ITEM_DAYCHOOSER) {
					$codes = explode(",", $meta_value);
					$_codes = [];
					foreach ($codes as $c) {
						$c = trim($c);
						if (!empty($c)) {
							$_codes[] = $c;
						}
					}
					if (count($_codes) > 0) {
						$meta_value = implode(", ", $_codes);
					} else {
						$meta_value = '-';
					}
				} else if ($meta->key === self::META_ORDER_ITEM_SEAT_LABELS) {
					$labels = json_decode($meta_value, true);
					if (is_array($labels) && count($labels) > 0) {
						$meta_value = implode(", ", array_map('esc_html', $labels));
					} else {
						$meta_value = '-';
					}
				} else if ($meta->key === self::META_ORDER_ITEM_SEATING_PLAN_ID) {
					$planId = intval($meta_value);
					if ($planId > 0) {
						$plan = $this->MAIN->getSeating()->getPlanManager()->getById($planId);
						if ($plan) {
							$meta_value = esc_html($plan['name']) . ' (ID: ' . $planId . ')';
						}
					}
					// Bei planId <= 0 oder null: Rohwert bleibt unverändert
				}
			}
			$meta_value = apply_filters($this->MAIN->_add_filter_prefix . 'woocommerce-hooks_woocommerce_order_item_display_meta_value', $meta_value, $meta, $item);
			return $meta_value;
		}

		/**
		 * Display ticket information in order emails and admin
		 *
		 * **CRITICAL METHOD - CUSTOMER-FACING:** Displays ticket details in WooCommerce emails and order pages.
		 * Renders ticket codes, CVV codes, download links, and custom ticket information.
		 * Handles both plain text and HTML email formats, day chooser displays, and name/value per ticket.
		 * Automatically triggers ticket generation and order status updates if needed.
		 *
		 * @param int $item_id Order item ID
		 * @param WC_Order_Item $item Order item object
		 * @param WC_Order $order Order object
		 * @param bool $plain_text Whether to render as plain text (for email) or HTML
		 * @return void
		 */
		public function woocommerce_order_item_meta_start(int $item_id, $item, $order, bool $plain_text = false): void {
			$this->add_serialcode_to_order($order->get_id()); // falls noch welche fehlen, dann vor der E-Mail noch hinzufügen

			$order = $this->set_wcTicketSetOrderToCompleteIfAllOrderItemsAreTickets($order);

			$isPaid = SASO_EVENTTICKETS::isOrderPaid($order);
			if ($isPaid) {

				$codeObjects_cache = [];

				$sale_restriction_code = wc_get_order_item_meta($item_id , self::META_KEY_CODELIST_RESTRICTION_ORDER_ITEM, true);
				if (!empty($sale_restriction_code)) {
					$preText = $this->MAIN->getOptions()->getOptionValue('wcRestrictPrefixTextCode');
					if ($plain_text) {
						echo "\n".esc_html($preText).' '. esc_attr($sale_restriction_code);
					} else {
						echo '<div class="product-restriction-serial-code">'.esc_html($preText).' '. esc_attr($sale_restriction_code).'</div>';
					}
				}

				$displaySerial = false;
				$code = "";
				$preText = "";
				if ($this->MAIN->getOptions()->isOptionCheckboxActive('wcassignmentDoNotPutOnEmail') == false) {
					$isTicket = wc_get_order_item_meta($item_id , self::META_ORDER_ITEM_IS_TICKET,true) == 1 ? true : false;
					if ($isTicket) {
						$code = wc_get_order_item_meta($item_id , self::META_ORDER_ITEM_CODES,true);
						if (!empty($code)) {
							$preText = $this->MAIN->getOptions()->getOptionValue('wcTicketPrefixTextCode');
							$displaySerial = true;
						}
					} else { // serial?
						/*
						$code = wc_get_order_item_meta($item_id , self::META_ORDER_ITEM_CODES,true);
						if (!empty($code)) {
							$preText = $this->MAIN->getOptions()->getOptionValue('wcassignmentPrefixTextCode');
							$displaySerial = true;
						}
						*/
					}
				}

				$product_id = $item->get_product_id();
				$product_id_orig = $this->MAIN->getTicketHandler()->getWPMLProductId($product_id);

				if ($displaySerial) {
					$code_ = explode(",", $code);
					array_walk($code_, "trim");
					if ($isTicket) {
						$wcassignmentDoNotPutCVVOnEmail = $this->MAIN->getOptions()->isOptionCheckboxActive('wcassignmentDoNotPutCVVOnEmail');
						$wcTicketDontDisplayDetailLinkOnMail = $this->MAIN->getOptions()->isOptionCheckboxActive('wcTicketDontDisplayDetailLinkOnMail');
						$wcTicketDontDisplayPDFButtonOnMail = $this->MAIN->getOptions()->isOptionCheckboxActive('wcTicketDontDisplayPDFButtonOnMail');
						$wcTicketBadgeAttachLinkToMail = $this->MAIN->getOptions()->isOptionCheckboxActive('wcTicketBadgeAttachLinkToMail');
						$is_daychooser = get_post_meta($product_id_orig, "saso_eventtickets_is_daychooser", true) == "yes";

						if ($this->MAIN->getOptions()->isOptionCheckboxActive('wcTicketDisplayDateOnMail')) {
							// check if the product is a day chooser
							if (!$is_daychooser) {
								$date_str = $this->MAIN->getTicketHandler()->displayTicketDateAsString($product_id_orig);
								if (!empty($date_str)) echo "<br>".$date_str."<br>";
							}
						}

						$labelNamePerTicket_label = null;
						$labelValuePerTicket_label = null;
						$labelDayChooser_label = null;

						$a = 0;
						foreach($code_ as $c) {
							$a++;
							if (!empty($c)) {
								$cvv = "";
								$url = "";
								$codeObj = null;
								$metaObj = null;
								try { // kann sein, dass keine free tickets mehr verfügbar sind
									if (isset($codeObjects_cache[$c])) {
										$codeObj = $codeObjects_cache[$c];
									} else {
										$codeObj = $this->MAIN->getCore()->retrieveCodeByCode($c);
										$codeObjects_cache[$c] = $codeObj;

									}
									$metaObj = $this->MAIN->getCore()->encodeMetaValuesAndFillObject($codeObj['meta'], $codeObj);
									$url = $metaObj['wc_ticket']['_url'];
									$cvv = $codeObj['cvv'];
									// Use public ticket ID for display (fallback to internal code)
									$displayTicketId = !empty($metaObj['wc_ticket']['_public_ticket_id'])
										? $metaObj['wc_ticket']['_public_ticket_id']
										: $c;
								} catch (Exception $e) {
									$this->MAIN->getAdmin()->logErrorToDB($e, "", "issues with the order email. Code: ".$c.". Cannot retrieve code object and/or meta object.");
									$displayTicketId = $c; // Fallback to internal code on error
								}

								$parameter_add = "?";
								if (strpos(" ".$url, "?") > 0) {
									$parameter_add = "&";
								}

								$ticket_info_text = "";
								if ($metaObj != null) {
									$name_per_ticket = $metaObj['wc_ticket']['name_per_ticket'];
									if (!empty($name_per_ticket)) {
										if ($labelNamePerTicket_label == null) {
											$labelNamePerTicket_label = esc_attr($this->MAIN->getTicketHandler()->getLabelNamePerTicket($product_id_orig));
										}
										$labelNamePerTicket = str_replace("{count}", $a, $labelNamePerTicket_label);
										$ticket_info_text = $labelNamePerTicket." ".esc_html($name_per_ticket);
									}
									$value_per_ticket = $metaObj['wc_ticket']['value_per_ticket'];
									if (!empty($value_per_ticket)) {
										if ($labelValuePerTicket_label == null) {
											$labelValuePerTicket_label = esc_attr($this->MAIN->getTicketHandler()->getLabelValuePerTicket($product_id_orig));
										}
										$labelValuePerTicket = str_replace("{count}", $a, $labelValuePerTicket_label);
										if ($ticket_info_text != "") $ticket_info_text .= "<br>";
										$ticket_info_text .= $labelValuePerTicket." ".esc_html($value_per_ticket);
									}
									$day_chooser = "";
									if ($metaObj['wc_ticket']['is_daychooser'] == 1) {
										$day_chooser = $this->MAIN->getTicketHandler()->displayDayChooserDateAsString($codeObj, true);
									}
									if (!empty($day_chooser)) {
										//if ($labelDayChooser_label == null) {
										//	$labelDayChooser_label = esc_attr($this->MAIN->getTicketHandler()->getLabelDaychooserPerTicket($product_id_orig));
										//}
										//if (!empty($ticket_info_text)) $ticket_info_text .= "<br>";
										//$labelDayChooser = str_replace("{count}", $a, $labelDayChooser_label);
										if ($ticket_info_text != "") $ticket_info_text .= "<br>";
										//$ticket_info_text .= $labelDayChooser." ".esc_html($day_chooser);
										$ticket_info_text .= esc_html($day_chooser);
									}
									// Seat info
									$seat_label = $metaObj['wc_ticket']['seat_label'] ?? '';
									if (!empty($seat_label)) {
										if ($ticket_info_text != "") $ticket_info_text .= "<br>";
										$seatLabelText = esc_attr($this->MAIN->getOptions()->getOptionValue('wcTicketTransSeat'));
										if (empty($seatLabelText)) $seatLabelText = __('Seat', 'event-tickets-with-ticket-scanner');
										$ticket_info_text .= $seatLabelText . ": " . esc_html($seat_label);
										$seat_category = $metaObj['wc_ticket']['seat_category'] ?? '';
										if (!empty($seat_category)) {
											$ticket_info_text .= ' (' . esc_html($seat_category) . ')';
										}
									}
								}

								//$is_thankyoupage = is_wc_endpoint_url( 'order-received' );

								if ($plain_text) {
									if (!empty($ticket_info_text)) {
										echo "\n".esc_html(str_replace($ticket_info_text, "<br>", "\n"));
									}
									echo "\n".esc_html($preText).' '.esc_attr($displayTicketId);
									if (!empty($cvv) && !$wcassignmentDoNotPutCVVOnEmail) {
										echo "\nCVV: ".esc_html($cvv);
									}
									if (!empty($url) && !$wcTicketDontDisplayDetailLinkOnMail) {
										echo "\n".esc_html__('Ticket Detail', 'event-tickets-with-ticket-scanner').": " . esc_url($url);
									}
									if (!empty($url) && !$wcTicketDontDisplayPDFButtonOnMail) {
										$dlnbtnlabel = $this->MAIN->getOptions()->getOptionValue('wcTicketLabelPDFDownload');
										echo "\n" . esc_html($dlnbtnlabel) . " " . esc_url($url).$parameter_add.'pdf';
									}
									if (!empty($url) && $wcTicketBadgeAttachLinkToMail ) {
										$dlnbtnlabel = $this->MAIN->getOptions()->getOptionValue('wcTicketBadgeLabelDownload');
										echo "\n" . esc_html($dlnbtnlabel) . " " . esc_url($url).$parameter_add.'badge';
									}
								} else {
									echo '<div class="product-serial-code" style="padding-bottom:15px;">';
									if (!empty($ticket_info_text)) {
										echo $ticket_info_text."<br>";
									}
									echo esc_html($preText)." ";
									if (empty($url) || $wcTicketDontDisplayDetailLinkOnMail) {
										echo esc_html($displayTicketId);
									} else {
										echo '<br><a target="_blank" data-plg="'.esc_attr($this->MAIN->getPrefix()).'" href="'.esc_url($url).'">'.esc_html($displayTicketId).'</a> ';
									}
									if (!empty($cvv) && !$wcassignmentDoNotPutCVVOnEmail) {
										echo "CVV: ".esc_html($cvv);
									}
									echo '<p>';
									if (!empty($url) && !$wcTicketDontDisplayPDFButtonOnMail) {
										$dlnbtnlabel = $this->MAIN->getOptions()->getOptionValue('wcTicketLabelPDFDownload');
										echo '<br><a target="_blank" data-plg="'.esc_attr($this->MAIN->getPrefix()).'" href="'.esc_url($url).$parameter_add.'pdf"><b>'.esc_html($dlnbtnlabel).'</b></a>';
									}
									if (!empty($url) && $wcTicketBadgeAttachLinkToMail ) {
										$dlnbtnlabel = $this->MAIN->getOptions()->getOptionValue('wcTicketBadgeLabelDownload');
										echo '<br><a target="_blank" data-plg="'.esc_attr($this->MAIN->getPrefix()).'" href="'.esc_url($url).$parameter_add.'badge"><b>'.esc_html($dlnbtnlabel).'</b></a>';
									}
									echo '</p>';
									echo '</div>';
								}
							}
						}
					} else { // serial
						/*
						$sep = $this->MAIN->getOptions()->getOptionValue('wcassignmentDisplayCodeSeperator');
						$ccodes = [];
						foreach($code_ as $c) {
							if (!$wcassignmentDoNotPutCVVOnEmail) {
								$codeObj = $this->MAIN->getCore()->retrieveCodeByCode($c);
								if (!empty($codeObj['cvv'])) {
									$ccodes[] = esc_html($c." CVV: ".$codeObj['cvv']);
								} else {
									$ccodes[] = esc_html($c);
								}
							} else {
								$ccodes[] = esc_html($c);
							}
						}
						$code_text = implode($sep, $ccodes);
						if ($plain_text) {
							echo "\n".esc_html($preText).' '.esc_attr($code_text);
						} else {
							echo '<div class="product-serial-code">'.esc_html($preText).' '.esc_html($code_text).'</div>';
						}
						*/
					}
				}

				do_action( $this->MAIN->_do_action_prefix.'woocommerce-hooks_woocommerce_order_item_meta_start', $item_id, $item, $order, $plain_text );
			} // not paid
		}

		/**
		 * Check for WooCommerce Subscriptions and get tickets from parent order
		 *
		 * This method checks if the order is a subscription renewal and attempts to reuse
		 * tickets from the parent subscription order instead of generating new ones.
		 *
		 * @param WC_Order $new_order New order object
		 * @param int $product_id Product ID
		 * @param WC_Order_Item $new_item New order item
		 * @return array Ticket codes array or empty array
		 */
		private function checkSubscriptionAndGetTicketsOfProduct($new_order, int $product_id, $new_item): array {
			$codes = [];
			if ($new_item == null) return $codes; // should not happen. No item found, so no tickets
			if (!class_exists('WooCommerce')) return $codes; // woocommerce not active, so no tickets
			if (!class_exists('WC_Subscriptions_Product')) return $codes; // woocommerce subscriptions not active, so no tickets
			if (!$this->MAIN->getOptions()->isOptionCheckboxActive('wcassignmentExtendTicketWithSubscription')) return $codes;

			$product = wc_get_product($product_id);

			if (WC_Subscriptions_Product::is_subscription($product) == false) return $codes; // not a subscription product

			$subscriptions = wcs_get_subscriptions_for_order($new_order, ['order_type' => 'any']);
			if (!is_array($subscriptions) || count($subscriptions) == 0) return $codes; // no subscriptions in the order - maybe the first order

			$parent_order_id = 0;
			$subscription = null;
			foreach ($subscriptions as $sub) {
				if ($sub != null && is_a($sub, 'WC_Subscription') && in_array($sub->get_status(), ['active', 'on-hold'])) {
					$parent_order_id = $sub->get_parent_id();
					if ($parent_order_id == 0) return $codes; // no parent order
					$subscription = $sub;
					break;
				}
			}

			if ($subscription == null) return $codes; // no active subscription in the order

			$parent_order = wc_get_order($parent_order_id);
			$products_with_codes = $this->getTicketsFromOrder($parent_order);
			if (count($products_with_codes) == 0) return $codes; // no tickets in the parent order

			$quantity = $new_item->get_quantity();

			// find the product in the parent order with the same quantity
			foreach ($products_with_codes as $product_with_codes) {
				if ($product_with_codes["product_id"] == $product_id && $product_with_codes["quantity"] == $quantity) {
					// found the product with the same quantity in the parent order - now get the tickets
					$codes = explode(",", $product_with_codes["codes"]);
					try {
						foreach ($codes as $code) {
							$codeObj = $this->MAIN->getCore()->retrieveCodeByCode($code);
							// because we are reusing the ticket, no update on the date picker, name per ticket or value per ticket needed.
							try {
								$codeObj = $this->addOrderIdToTicketSubs($codeObj, $new_order->get_id(), $new_item->get_id());
							} catch (Exception $e) {
								$msg = "While processing subscription tickets, error happened updating ticket object with new order id and item id. Ticket: " . $code;
								$this->MAIN->getAdmin()->logErrorToDB($e, "", $msg);
								$new_order->add_order_note($msg);
							}
						}
					} catch (Exception $e) {
						$msg = "While processing subscription tickets, error happened retrieving ticket object. Tickets: " . implode(", ", $codes);
						$this->MAIN->getAdmin()->logErrorToDB($e, "", $msg);
						$new_order->add_order_note($msg);
					}

					// now get the infos from the old order_item and add them to the new order item
					// remove the old values if any - happens, if the tickets were removed and added again (automatically or manually within the order details)
					$old_item_id = $product_with_codes["order_item_id"];

					// makes no sense with subscriptions - the dates are in the past :) - but then we have the code already , once we optimize it
					$days_per_ticket = [];
					$product_id_orig = $this->MAIN->getTicketHandler()->getWPMLProductId($product_id);
					$isDaychooser = get_post_meta($product_id_orig, self::META_PRODUCT_IS_DAYCHOOSER, true) == "yes";
					if ($isDaychooser) {
						$daychooser = wc_get_order_item_meta($old_item_id, self::SESSION_KEY_DAYCHOOSER, true); // somehow stored twice (self::META_ORDER_ITEM_DAYCHOOSER) to the order item meta :( i did a mistake and need to be fixed one day. for now we leave it like this to prevent issues.
						wc_delete_order_item_meta($new_item->get_id(), "saso_eventtickets_request_daychooser"); // remove old value if any
						wc_add_order_item_meta($new_item->get_id(), "saso_eventtickets_request_daychooser", $daychooser);
						$days_per_ticket = $daychooser; // take the old value
					}
					$ticket_list_id = wc_get_order_item_meta($old_item_id, self::META_ORDER_ITEM_CODE_LIST, true);
					$public_ticket_ids_value = wc_get_order_item_meta($old_item_id, self::META_ORDER_ITEM_PUBLIC_IDS, true);
					$public_ticket_ids = explode(",", $public_ticket_ids_value);

					// save the values to the new order item meta
					$this->addMetaToOrderItem($new_item->get_id(), $codes, $ticket_list_id, $days_per_ticket, $public_ticket_ids);
					break; // found the product, so break the loop
				}
			}
			return $codes;
		}

		/**
		 * Add order ID to subscription ticket
		 *
		 * Updates the ticket with the new order ID from the subscription and the new item ID of the new order.
		 * It also updates the expiration date and other subscription-related metadata.
		 *
		 * @param array $codeObj Code object
		 * @param int $new_order_id New order ID
		 * @param int $new_item_id New item ID
		 * @return array Updated code object
		 */
		private function addOrderIdToTicketSubs(array $codeObj, int $new_order_id, int $new_item_id): array {
			$old_order_id = $codeObj["order_id"];

			$metaObj = $this->MAIN->getCore()->encodeMetaValuesAndFillObject($codeObj['meta'], $codeObj);
			if (!isset($metaObj["wc_ticket"]["subs"])) {
				$metaObj["wc_ticket"]['subs'] = $this->MAIN->getCore()->getDefaultMetaValueOfSubs();
			}
			$order_info = [
				"order_id" => intval($new_order_id),
				"item_id" => $new_item_id,
				"date" => time(),
				"timezone" => wp_timezone_string()
			];
			$metaObj["wc_ticket"]['subs'][] = $order_info;

			// from the premium version - add expiration date and other stuff
			if ($this->MAIN->isPremium()) {
				$codeObj = $this->MAIN->getCore()->saveMetaObject($codeObj, $metaObj);
				// need the new order for expiration date check, but the old order id is still needed for the public ticket number
				if (method_exists($this->MAIN->getPremiumFunctions(), "executeJSON")) {
					$codeObj = $this->MAIN->getPremiumFunctions()->executeJSON('removeExpirationFromCode', ['code' => $codeObj["code"]]);
				}
				if (method_exists($this->MAIN->getPremiumFunctions(), "addCodeFromListForOrderAfter")) {
					// let the expiration date and other stuff be updated with the new order information - if needed.
					$codeObj['order_id'] = $new_order_id; // set the new order id, so the premium function can use it
					$metaObj['woocommerce']['order_id'] = $new_order_id; // set the new order id, so the premium function can use it
					$codeObj['meta'] = $this->MAIN->getCore()->json_encode_with_error_handling($metaObj);
					$codeObj = $this->MAIN->getPremiumFunctions()->addCodeFromListForOrderAfter($codeObj); // only if premium and only the premium call, otherwise the ticket information will be overwritten
					$codeObj['order_id'] = $old_order_id; // restore the old order id for the public ticket number
					$metaObj['woocommerce']['order_id'] = $old_order_id; // restore the old order id for the public ticket number
				}
			}
			// reset redeem operations
			$codeObj['meta'] = $this->MAIN->getCore()->json_encode_with_error_handling($metaObj);
			$codeObj = $this->MAIN->getAdmin()->removeRedeemWoocommerceTicketForCode(['code' => $codeObj["code"]], $codeObj);
			$codeObj = $this->MAIN->getCore()->saveMetaObject($codeObj, $metaObj);

			return $codeObj;
		}

		/**
		 * Save ticket codes and metadata to order item
		 *
		 * @param int $item_id Order item ID
		 * @param array $codes Ticket codes array
		 * @param int $list_id List ID
		 * @param mixed $days_per_ticket Days per ticket (string or array)
		 * @param array $public_ticket_ids Public ticket IDs array
		 * @return array Saved codes array
		 */
		private function addMetaToOrderItem(int $item_id, array $codes, int $list_id, $days_per_ticket, array $public_ticket_ids): array {
			$ret = [];
			if (count($codes) > 0) {
				$ret = $codes;
				wc_delete_order_item_meta($item_id, self::META_ORDER_ITEM_CODES);
				wc_add_order_item_meta($item_id, self::META_ORDER_ITEM_CODES, implode(",", $codes));
				wc_delete_order_item_meta($item_id, self::META_ORDER_ITEM_CODE_LIST);
				wc_add_order_item_meta($item_id, self::META_ORDER_ITEM_CODE_LIST, $list_id);
				wc_delete_order_item_meta($item_id, self::META_ORDER_ITEM_DAYCHOOSER);
				wc_add_order_item_meta($item_id, self::META_ORDER_ITEM_DAYCHOOSER, is_array($days_per_ticket) ? implode(",", $days_per_ticket) : $days_per_ticket);
			}
			if (count($public_ticket_ids) > 0) {
				wc_delete_order_item_meta($item_id, self::META_ORDER_ITEM_PUBLIC_IDS);
				wc_add_order_item_meta($item_id, self::META_ORDER_ITEM_PUBLIC_IDS, implode(",", $public_ticket_ids));
			}

			wc_delete_order_item_meta($item_id, self::META_ORDER_ITEM_IS_TICKET);
			wc_add_order_item_meta($item_id, self::META_ORDER_ITEM_IS_TICKET, 1, true);

			return $ret;
		}

		/**
		 * Delete/free codes and restrictions from order item
		 *
		 * Frees up codes when order items are deleted or refunded
		 *
		 * @param int $item_get_id Order item ID
		 * @return void
		 */
		public function woocommerce_delete_order_item(int $item_get_id): void {
			// Handle restriction codes
			$code = wc_get_order_item_meta($item_get_id, self::META_KEY_CODELIST_RESTRICTION_ORDER_ITEM, true);
			if (!empty($code)) {
				$data = ['code' => $code];
				// Remove used info
				try {
					$this->MAIN->getAdmin()->removeUsedInformationFromCode($data);
					$this->MAIN->getAdmin()->removeWoocommerceOrderInfoFromCode($data);
					$this->MAIN->getAdmin()->removeWoocommerceRstrPurchaseInfoFromCode($data);
					// Safety cleanup
					$this->deleteRestrictionEntryOnOrderItem($item_get_id);
				} catch (Exception $e) {
					$this->MAIN->getAdmin()->logErrorToDB($e);
					throw new Exception(esc_html__('Error while deleting restriction code from order item. ' . $e->getMessage(), 'event-tickets-with-ticket-scanner'));
				}
				// Add note to order
				$order_id = wc_get_order_id_by_order_item_id($item_get_id);
				$order = wc_get_order($order_id);
				$order->add_order_note(sprintf(/* translators: %s: restriction code number */esc_html__('Order item deleted. Free restriction code: %s for next usage.', 'event-tickets-with-ticket-scanner'), esc_attr($code)));
			}

			// Handle ticket codes
			if ($this->MAIN->getOptions()->isOptionCheckboxActive('wcRestrictFreeCodeByOrderRefund')) {
				$code_value = wc_get_order_item_meta($item_get_id, self::META_ORDER_ITEM_CODES, true);
				if (!empty($code_value)) {
					$codes = explode(",", $code_value);
					foreach ($codes as $code) {
						$code = trim($code);
						if (!empty($code)) {
							// Safety cleanup
							$this->deleteCodesEntryOnOrderItem($item_get_id);
							// Remove used info - if it is a real ticket number and not the free max usage message
							$data = ['code' => $code];
							try {
								$this->MAIN->getAdmin()->removeUsedInformationFromCode($data);
								$this->MAIN->getAdmin()->removeWoocommerceOrderInfoFromCode($data);
								$this->MAIN->getAdmin()->removeWoocommerceRstrPurchaseInfoFromCode($data);

								// Release seat block if associated with this ticket
								$codeObj = $this->MAIN->getCore()->retrieveCodeByCode($code);
								if ($codeObj && !empty($codeObj['id'])) {
									$this->MAIN->getSeating()->getBlockManager()->releaseSeatByCodeId((int) $codeObj['id']);
								}
							} catch (Exception $e) {
								$this->MAIN->getAdmin()->logErrorToDB($e);
							}
							// Add note to order
							$order_id = wc_get_order_id_by_order_item_id($item_get_id);
							$order = wc_get_order($order_id);
							$order->add_order_note(sprintf(/* translators: %s: ticket number */esc_html__('Order item deleted. Free ticket number: %s for next usage.', 'event-tickets-with-ticket-scanner'), esc_attr($code)));
						}
					}
				}
			}

			do_action($this->MAIN->_do_action_prefix . 'woocommerce-hooks_woocommerce_delete_order_item', $item_get_id);
		}

		/**
		 * Delete ticket metadata from order item
		 *
		 * @param int $item_id Order item ID
		 * @return void
		 */
		public function deleteCodesEntryOnOrderItem(int $item_id): void {
			wc_delete_order_item_meta($item_id, self::META_ORDER_ITEM_IS_TICKET);
			wc_delete_order_item_meta($item_id, self::META_ORDER_ITEM_CODES);
			wc_delete_order_item_meta($item_id, self::META_ORDER_ITEM_CODE_LIST);
			wc_delete_order_item_meta($item_id, self::META_ORDER_ITEM_PUBLIC_IDS);
			wc_delete_order_item_meta($item_id, self::META_ORDER_ITEM_DAYCHOOSER);

			// Seat meta
			$seatMetaKey = $this->MAIN->getSeating()->getMetaCartItemSeat();
			wc_delete_order_item_meta($item_id, $seatMetaKey);
			wc_delete_order_item_meta($item_id, self::META_ORDER_ITEM_SEAT_IDS);
			wc_delete_order_item_meta($item_id, self::META_ORDER_ITEM_SEAT_LABELS);
		}

		/**
		 * Delete restriction metadata from order item
		 *
		 * @param int $item_id Order item ID
		 * @return void
		 */
		public function deleteRestrictionEntryOnOrderItem(int $item_id): void {
			wc_delete_order_item_meta($item_id, self::META_KEY_CODELIST_RESTRICTION_ORDER_ITEM);
		}

		/**
		 * Automatically set order to completed if all items are tickets
		 *
		 * Changes order status from "processing" to "completed" when all order items are ticket products.
		 * Only applies if the wcTicketSetOrderToCompleteIfAllOrderItemsAreTickets option is enabled.
		 *
		 * @param WC_Order $order Order object
		 * @return WC_Order Order object (possibly with updated status)
		 */
		private function set_wcTicketSetOrderToCompleteIfAllOrderItemsAreTickets($order) {
			if ($this->MAIN->getOptions()->isOptionCheckboxActive('wcTicketSetOrderToCompleteIfAllOrderItemsAreTickets')) {
				$order_status = $order->get_status();
				//if ($order_status != "completed" && $order_status != "wc-completed") {
				if ($order_status == "processing" || $order_status == "wc-processing") {
					$items = $order->get_items();
					if (count($items) > 0) {
						$all_items_are_tickets = true;
						foreach ($items as $l_item) {
							$product_id = $l_item->get_product_id();
							$product_id_orig = $this->MAIN->getTicketHandler()->getWPMLProductId($product_id);
							if (get_post_meta($product_id_orig, self::META_PRODUCT_IS_TICKET, true) == "yes") {
							} else {
								$all_items_are_tickets = false;
								break;
							}
						}
						if ($all_items_are_tickets) {
							$order->update_status("completed");
						}
					}
				}
			}
			do_action($this->MAIN->_do_action_prefix . 'woocommerce-hooks_set_wcTicketSetOrderToCompleteIfAllOrderItemsAreTickets', $order);
			return $order;
		}

		/**
		 * Display order ticket management side box in admin
		 *
		 * Displays a meta box in the WP admin order edit page with buttons for:
		 * - Download all tickets as one PDF
		 * - Download ticket badge
		 * - Remove all tickets from order
		 * - Remove non-ticket items from order
		 *
		 * @param WP_Post|WC_Order $post_or_order_object Post or order object
		 * @return void
		 */
		public function wc_order_display_side_box($post_or_order_object): void {
			$order = ($post_or_order_object instanceof WP_Post) ? wc_get_order($post_or_order_object->ID) : $post_or_order_object;
			if ($order && $this->hasTicketsInOrder($order)) {
				$this->wc_order_addJSFileAndHandlerBackend($order);
				?>
				<p>Download All Tickets in one PDF</p>
				<button disabled data-id="<?php echo esc_attr($this->MAIN->getPrefix()."btn_download_alltickets_one_pdf"); ?>" class="button button-primary">Download Tickets</button>
				<p>Download Ticket Badge</p>
				<button disabled data-id="<?php echo esc_attr($this->MAIN->getPrefix()."btn_download_badge"); ?>" class="button button-primary">Download Ticket Badge</button>
				<p>Remove all tickets from the order</p>
				<button disabled data-id="<?php echo esc_attr($this->MAIN->getPrefix()."btn_remove_tickets"); ?>" class="button button-danger">Remove Tickets</button>
				<p>Remove all non-tickets from the order</p>
				<button disabled data-id="<?php echo esc_attr($this->MAIN->getPrefix()."btn_remove_non_tickets"); ?>" class="button button-danger">Remove Ticket Placeholders</button>

				<?php
				do_action($this->MAIN->_do_action_prefix . 'wc_order_display_side_box', []);
			} else {
				?>
				<p>No tickets in this order</p>
				<?php
			}
		}

		/**
		 * Load JavaScript and handlers for order admin backend
		 *
		 * Enqueues the WooCommerce backend JavaScript file and localizes it with
		 * order data, ticket information, and AJAX action handlers.
		 *
		 * @param WC_Order $order Order object
		 * @return void
		 */
		private function wc_order_addJSFileAndHandlerBackend($order): void {
			$tickets = $this->getTicketsFromOrder($order);
			wp_enqueue_style("wp-jquery-ui-dialog");
			wp_enqueue_media(); // damit der media chooser von wordpress geladen wird
			wp_register_script(
				$this->MAIN->getPrefix().'WC_Order_Ajax_Backend_Basic',
				trailingslashit(plugin_dir_url(dirname(dirname(__FILE__)))) . 'wc_backend.js?_v='.$this->MAIN->getPluginVersion(),
				array('jquery', 'jquery-ui-dialog', 'jquery-blockui', 'wp-i18n'),
				(current_user_can("administrator") ? time() : $this->MAIN->getPluginVersion()),
				true);
			wp_set_script_translations($this->MAIN->getPrefix().'WC_Order_Ajax_Backend_Basic', 'event-tickets-with-ticket-scanner', dirname(dirname(__DIR__)).'/languages');
			wp_localize_script(
				$this->MAIN->getPrefix().'WC_Order_Ajax_Backend_Basic',
				'Ajax_sasoEventtickets_wc', // name der js variable
				[
					'ajaxurl' => admin_url('admin-ajax.php'),
					'_plugin_home_url' => plugins_url("", dirname(dirname(__FILE__))),
					'prefix' => $this->MAIN->getPrefix(),
					'nonce' => wp_create_nonce($this->MAIN->_js_nonce),
					'action' => $this->MAIN->getPrefix().'_executeWCBackend',
					'product_id' => 0,
					'order_id' => $order != null ? $order->get_id() : 0,
					'scope' => 'order',
					'_backendJS' => trailingslashit(plugin_dir_url(dirname(dirname(__FILE__)))) . 'backend.js?_v='.$this->MAIN->getPluginVersion(),
					'tickets' => $tickets
				] // werte in der js variable
			);
			wp_enqueue_script($this->MAIN->getPrefix().'WC_Order_Ajax_Backend_Basic');
		}

		/**
		 * Remove all tickets from an order
		 *
		 * Removes all ticket metadata and frees ticket codes for all items in an order.
		 *
		 * @param array $data Request data containing order_id
		 * @return bool True on success
		 */
		public function removeAllTicketsFromOrder(array $data): bool {
			$order_id = intval($data['order_id']);
			if ($order_id > 0) {
				$this->removeTicketInfosFromOrder($order_id);
			}
			return true;
		}

		/**
		 * Remove ticket information from all order items
		 *
		 * Iterates through all order items and removes ticket codes and metadata.
		 * This is a helper method used by removeAllTicketsFromOrder().
		 *
		 * @param int $order_id Order ID
		 * @return void
		 */
		private function removeTicketInfosFromOrder(int $order_id): void {
			$order = wc_get_order($order_id);
			if ($order) {
				$items = $order->get_items();
				foreach ($items as $item_id => $item) {
					try {
						$this->woocommerce_delete_order_item($item_id);
					} catch (Exception $e) {
						// remove the meta data, even if this was maybe already done - fix issues with missing tickets.
					}
					$this->deleteCodesEntryOnOrderItem($item_id);
				}
			}
		}

		/**
		 * Remove ticket placeholders (invalid ticket codes) from order
		 *
		 * Scans all order items and removes ticket codes that don't exist in the database.
		 * Valid tickets are preserved and re-saved to the order item metadata.
		 *
		 * @param array $data Request data containing order_id
		 * @return bool True on success
		 */
		public function removeAllNonTicketsFromOrder(array $data): bool {
			$order_id = intval($data['order_id']);
			if ($order_id > 0) {
				$order = wc_get_order($order_id);
				if ($order != null) {
					$items = $order->get_items();
					foreach ($items as $item_id => $item) {
						$code_value = wc_get_order_item_meta($item_id, self::META_ORDER_ITEM_CODES, true);
						$good_codes = [];
						if (!empty($code_value)) {
							$codes = explode(",", $code_value);
							foreach ($codes as $code) {
								$code = trim($code);
								if (!empty($code)) {
									// check if ticket number exists in db, otherwise delete it
									try {
										$codeObj = $this->MAIN->getCore()->retrieveCodeByCode($code);
										$good_codes[] = $code;
									} catch (Exception $e) {
										$order->add_order_note(sprintf(
											/* translators: %s: ticket number */
											esc_html__('Ticket placeholder removed for order item id: %s.', 'event-tickets-with-ticket-scanner'),
											esc_attr($item_id)
										));
									}
								}
							}
							if (count($good_codes) != count($codes)) {
								wc_delete_order_item_meta($item_id, self::META_ORDER_ITEM_CODES);
								wc_add_order_item_meta($item_id, self::META_ORDER_ITEM_CODES, implode(",", $good_codes));
							}
						}
					}
				}
			}
			return true;
		}

		/**
		 * Handle order deletion - remove all tickets
		 *
		 * Called when an entire order is deleted. Removes all ticket codes
		 * and metadata from all order items.
		 *
		 * @param int $id Order ID
		 * @return void
		 */
		public function woocommerce_delete_order(int $id): void {
			$this->removeAllTicketsFromOrder(['order_id' => $id]);
		}

		/**
		 * Pre-delete refund handler - store parent order ID
		 *
		 * Captures the parent order ID before a refund is deleted so we can
		 * regenerate tickets for the parent order if needed.
		 *
		 * @param mixed $ret Return value (passed through)
		 * @param WC_Order_Refund $refund Refund object
		 * @param bool $force_delete Whether to force delete
		 * @return mixed Pass-through return value
		 */
		public function woocommerce_pre_delete_order_refund($ret, $refund, bool $force_delete) {
			if ($refund) {
				$this->refund_parent_id = $refund->get_parent_id();
			}
			return $ret;
		}

		/**
		 * Handle refund deletion - regenerate parent order tickets
		 *
		 * When a refund is deleted, either regenerate tickets for the parent order
		 * (if parent exists) or remove tickets from the refund order.
		 *
		 * @param int $id Refund ID
		 * @return void
		 */
		public function woocommerce_delete_order_refund(int $id): void {
			if ($this->refund_parent_id) {
				$this->add_serialcode_to_order($this->refund_parent_id); // add missing ticket numbers
			} else {
				$this->removeAllTicketsFromOrder(['order_id' => $id]);
			}
		}

		/**
		 * Display ticket information on PDF invoices (WooCommerce PDF Invoices & Packing Slips integration)
		 *
		 * Renders ticket codes, CVV, dates, and download links on PDF documents.
		 *
		 * @param string $template_type PDF template type
		 * @param array $item Order item data
		 * @param WC_Order $order Order object
		 * @return void
		 */
		public function wpo_wcpdf_after_item_meta($template_type, $item, $order): void {
			$isPaid = SASO_EVENTTICKETS::isOrderPaid($order);
			if (!$isPaid) {
				return;
			}

			// Display purchase restriction code
			$code = wc_get_order_item_meta($item['item_id'], self::META_KEY_CODELIST_RESTRICTION_ORDER_ITEM, true);
			if (!empty($code)) {
				if (!$this->MAIN->getOptions()->isOptionCheckboxActive('wcRestrictDoNotPutOnPDF')) {
					$preText = $this->MAIN->getOptions()->getOptionValue('wcRestrictPrefixTextCode');
					echo '<div class="product-serial-code">' . esc_html($preText) . ' ' . esc_attr($code) . '</div>';
				}
			}

			$codeObjects_cache = [];

			// Display ticket/serial codes
			$code = wc_get_order_item_meta($item['item_id'], self::META_ORDER_ITEM_CODES, true);
			if (empty($code)) {
				return;
			}

			if ($this->MAIN->getOptions()->isOptionCheckboxActive('wcassignmentDoNotPutOnPDF')) {
				return;
			}

			$code_ = explode(",", $code);
			array_walk($code_, "trim");

			$isTicket = wc_get_order_item_meta($item['item_id'], self::META_ORDER_ITEM_IS_TICKET, true) == 1;
			$key = $isTicket ? 'wcTicketPrefixTextCode' : 'wcassignmentPrefixTextCode';
			$preText = $this->MAIN->getOptions()->getOptionValue($key);

			$wcassignmentDoNotPutCVVOnPDF = $this->MAIN->getOptions()->isOptionCheckboxActive('wcassignmentDoNotPutCVVOnPDF');

			if ($isTicket) {
				$this->renderTicketCodesOnPDF($item, $code_, $preText, $wcassignmentDoNotPutCVVOnPDF, $codeObjects_cache);
			} else {
				$this->renderSerialCodesOnPDF($code_, $preText, $wcassignmentDoNotPutCVVOnPDF, $codeObjects_cache);
			}
		}

		/**
		 * Render ticket codes on PDF
		 *
		 * @param array $item Order item data
		 * @param array $codes Array of ticket codes
		 * @param string $preText Prefix text
		 * @param bool $hideCVV Whether to hide CVV
		 * @param array &$codeObjects_cache Cache for code objects
		 * @return void
		 */
		private function renderTicketCodesOnPDF(array $item, array $codes, string $preText, bool $hideCVV, array &$codeObjects_cache): void {
			$product_id = $item['product_id'];
			$product_id_orig = $this->MAIN->getTicketHandler()->getWPMLProductId($product_id);

			$display_date = $this->MAIN->getOptions()->isOptionCheckboxActive('wcTicketDisplayDateOnMail');
			$is_daychooser = get_post_meta($product_id_orig, "saso_eventtickets_is_daychooser", true) == "yes";

			// Display event date if not a day chooser
			if ($display_date && !$is_daychooser) {
				$date_str = $this->MAIN->getTicketHandler()->displayTicketDateAsString($product_id_orig);
				if (!empty($date_str)) {
					echo "<br>" . $date_str . "<br>";
				}
			}

			$wcTicketBadgeLabelDownload = $this->MAIN->getOptions()->getOptionValue('wcTicketBadgeLabelDownload');
			$code_size = count($codes);
			$counter = 0;
			$mod = 40;

			foreach ($codes as $c) {
				$c = trim($c);
				if (empty($c)) {
					continue;
				}

				$counter++;

				// Get or cache code object
				if (isset($codeObjects_cache[$c])) {
					$codeObj = $codeObjects_cache[$c];
				} else {
					$codeObj = $this->MAIN->getCore()->retrieveCodeByCode($c);
					$codeObjects_cache[$c] = $codeObj;
				}
				$metaObj = $this->MAIN->getCore()->encodeMetaValuesAndFillObject($codeObj['meta'], $codeObj);

				$url = $metaObj['wc_ticket']['_url'] ?? '';

				// Output ticket code
				echo '<br>' . esc_html($preText) . ' <b>' . esc_html($c) . '</b>';
				if (!empty($codeObj['cvv']) && !$hideCVV) {
					echo " CVV: <b>" . esc_html($codeObj['cvv']) . '</b>';
				}

				// Display day chooser date
				if ($display_date && ($metaObj['wc_ticket']['is_daychooser'] ?? 0) == 1) {
					$day_chooser = $this->MAIN->getTicketHandler()->displayDayChooserDateAsString($codeObj, true);
					if (!empty($day_chooser)) {
						echo "<br>" . esc_html($day_chooser) . "<br>";
					}
				}

				// Display ticket detail link
				if (!$this->MAIN->getOptions()->isOptionCheckboxActive('wcTicketDontDisplayDetailLinkOnMail')) {
					$mod = 8;
					if (!empty($url)) {
						echo '<br><b>' . esc_html__('Ticket Detail', 'event-tickets-with-ticket-scanner') . ':</b> ' . esc_url($url) . '<br>';
					}
				}

				// Display badge download link
				if (!$this->MAIN->getOptions()->isOptionCheckboxActive('wcTicketBadgeAttachLinkToMail')) {
					$mod = 8;
					if (!empty($url)) {
						echo '<br><b>' . esc_html($wcTicketBadgeLabelDownload) . ':</b> ' . esc_url($url) . '?badge<br>';
					}
				}

				// Page break for many tickets
				if ($code_size > $mod && $counter % $mod == 0) {
					echo '<div style="page-break-before: always;"></div>';
				}
			}
		}

		/**
		 * Render serial codes on PDF
		 *
		 * @param array $codes Array of serial codes
		 * @param string $preText Prefix text
		 * @param bool $hideCVV Whether to hide CVV
		 * @param array &$codeObjects_cache Cache for code objects
		 * @return void
		 */
		private function renderSerialCodesOnPDF(array $codes, string $preText, bool $hideCVV, array &$codeObjects_cache): void {
			$sep = $this->MAIN->getOptions()->getOptionValue('wcassignmentDisplayCodeSeperatorPDF');
			$ccodes = [];

			foreach ($codes as $c) {
				$c = trim($c);
				if (empty($c)) {
					continue;
				}

				if (!$hideCVV) {
					if (isset($codeObjects_cache[$c])) {
						$codeObj = $codeObjects_cache[$c];
					} else {
						$codeObj = $this->MAIN->getCore()->retrieveCodeByCode($c);
						$codeObjects_cache[$c] = $codeObj;
					}
					if (!empty($codeObj['cvv'])) {
						$ccodes[] = esc_html($c . " CVV: " . $codeObj['cvv']);
					} else {
						$ccodes[] = esc_html($c);
					}
				} else {
					$ccodes[] = esc_html($c);
				}
			}

			$code_text = implode($sep, $ccodes);
			echo '<div class="product-serial-code">' . esc_html($preText) . ' ' . esc_html($code_text) . '</div>';
		}

		/**
		 * Store ticket values from session/cart to order item meta
		 *
		 * Transfers name per ticket, value per ticket, and day chooser
		 * selections from session/cart to the order item.
		 *
		 * @param WC_Order_Item_Product $item Order item
		 * @param string $cart_item_key Cart item key
		 * @return void
		 */
		public function setTicketValuesToOrderItem($item, string $cart_item_key): void {
			if (WC() === null || WC()->session === null) {
				return;
			}

			// Transfer session-based values
			$session_keys = [
				'saso_eventtickets_request_name_per_ticket',
				'saso_eventtickets_request_value_per_ticket'
			];

			foreach ($session_keys as $k) {
				$valueArray = WC()->session->get($k);
				if ($valueArray !== null && isset($valueArray[$cart_item_key])) {
					$value = $valueArray[$cart_item_key];
					$item->update_meta_data($k, $value);
				}
			}

			// Transfer day chooser from cart item meta
			$key = self::SESSION_KEY_DAYCHOOSER;
			$cart_item = WC()->cart->get_cart_item($cart_item_key);
			$value = null;

			if (isset($cart_item[$key])) {
				$value = $cart_item[$key];
			} else {
				// Fallback to session in case the item meta is adjusted by other plugins
				$value = $this->session_get_value($key . '_' . $cart_item_key);
			}

			if ($value !== null) {
				$item->update_meta_data(self::SESSION_KEY_DAYCHOOSER, $value);
			}

			// Transfer seat selection from cart item meta
			$seatMetaKey = $this->MAIN->getSeating()->getMetaCartItemSeat();
			$seatsData = null;

			if (isset($cart_item[$seatMetaKey])) {
				$seatsData = $cart_item[$seatMetaKey];
			} else {
				// Fallback to session in case the item meta is adjusted by other plugins
				$seatsData = $this->session_get_value($seatMetaKey . '_' . $cart_item_key);
			}

			if (!empty($seatsData)) {
				// Normalize to array format
				if (isset($seatsData['seat_id'])) {
					$seatsData = [$seatsData];
				}
				$item->update_meta_data($seatMetaKey, $seatsData);

				// Store as strings for WooCommerce display (scalar values)
				$seatIds = array_column($seatsData, 'seat_id');
				$seatLabels = array_column($seatsData, 'seat_label');
				$item->update_meta_data(self::META_ORDER_ITEM_SEAT_IDS, implode(',', $seatIds));
				$item->update_meta_data(self::META_ORDER_ITEM_SEAT_LABELS, wp_json_encode($seatLabels));

				// Store seating plan ID (fetched from DB for security)
				$firstSeatId = reset($seatIds);
				if ($firstSeatId) {
					$planId = $this->MAIN->getSeating()->getSeatManager()->getSeatingPlanIdForSeatId($firstSeatId);
					//if ($planId !== null) {
						$item->update_meta_data(self::META_ORDER_ITEM_SEATING_PLAN_ID, $planId);
					//}
				}
			}
		}

		/**
		 * Store restriction codes to order on checkout
		 *
		 * Legacy method for old installations with wcRestrictPurchase option.
		 * Saves restriction code associations to order items.
		 *
		 * @param int $order_id Order ID
		 * @param array $address_data Address data from checkout
		 * @return void
		 */
		public function woocommerce_checkout_update_order_meta(int $order_id, $address_data): void {
			// Legacy option - not in use anymore but kept for old installations
			if (!$this->MAIN->getOptions()->isOptionCheckboxActive('wcRestrictPurchase')) {
				return;
			}

			if (!$this->MAIN->getWC()->getFrontendManager()->containsProductsWithRestrictions()) {
				return;
			}

			$order = wc_get_order($order_id);
			$items = $order->get_items();

			foreach ($items as $item_id => $item) {
				$code = wc_get_order_item_meta($item_id, self::META_KEY_CODELIST_RESTRICTION_ORDER_ITEM, true);

				if (empty($code)) {
					continue;
				}

				$product_id = $item->get_product_id();
				$list_id = get_post_meta($product_id, self::META_KEY_CODELIST_RESTRICTION, true);

				$this->MAIN->getAdmin()->addRetrictionCodeToOrder(
					$code,
					$list_id,
					$order->get_id(),
					$product_id,
					$item_id
				);
			}
		}

		/**
		 * Save ticket data to order line item during checkout
		 *
		 * Stores ticket values and restriction codes to order items.
		 * Also marks restriction codes as used if configured.
		 *
		 * @param WC_Order_Item_Product $item Order item
		 * @param string $cart_item_key Cart item key
		 * @param array $values Cart item values
		 * @param WC_Order $order Order object
		 * @return void
		 */
		public function woocommerce_checkout_create_order_line_item($item, string $cart_item_key, array $values, $order): void {
			// Store ticket values (name per ticket, value per ticket, day chooser, seat selection)
			$this->setTicketValuesToOrderItem($item, $cart_item_key);

			// Check for restriction code
			if (empty($values[self::META_KEY_CODELIST_RESTRICTION_ORDER_ITEM])) {
				return;
			}

			// Store purchase restriction code to order_item
			$code = $values[self::META_KEY_CODELIST_RESTRICTION_ORDER_ITEM];
			$item->add_meta_data(self::META_KEY_CODELIST_RESTRICTION_ORDER_ITEM, $code);

			$codeObj = null;
			try {
				$codeObj = $this->MAIN->getCore()->retrieveCodeByCode($code);
			} catch (\Exception $e) {
				if (isset($_GET['VollstartValidatorDebug'])) {
					var_dump($e);
				}
				$this->MAIN->getAdmin()->logErrorToDB($e);
			}

			// Mark code as used if option is active
			if ($this->MAIN->getOptions()->isOptionCheckboxActive('oneTimeUseOfRegisterCode')) {
				try {
					if ($codeObj === null) {
						$codeObj = $this->MAIN->getCore()->retrieveCodeByCode($code);
					}
					$rc_v = $this->MAIN->getOptions()->getOptionValue('wcRestrictOneTimeUsage');
					if ($rc_v == 1) {
						$codeObj = $this->MAIN->getFrontend()->markAsUsed($codeObj);
					} elseif ($rc_v == 2) {
						$codeObj = $this->MAIN->getFrontend()->markAsUsed($codeObj, true);
					}
				} catch (\Exception $e) {
					if (isset($_GET['VollstartValidatorDebug'])) {
						var_dump($e);
					}
					$this->MAIN->getAdmin()->logErrorToDB($e);
				}
			}

			$this->MAIN->getCore()->triggerWebhooks(16, $codeObj);
		}
	}
}
