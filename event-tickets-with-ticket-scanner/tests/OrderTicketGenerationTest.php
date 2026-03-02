<?php
/**
 * Integration tests for order → ticket generation pipeline.
 *
 * These tests create real WooCommerce products/orders and verify
 * the ticket generation pipeline works end-to-end.
 */

class OrderTicketGenerationTest extends WP_UnitTestCase {

    private $main;

    public function set_up(): void {
        parent::set_up();
        $this->main = sasoEventtickets::Instance();

        // Ensure WooCommerce is loaded
        if (!class_exists('WC_Product_Simple')) {
            $this->markTestSkipped('WooCommerce not available');
        }
    }

    /**
     * Helper: create a simple ticket product with a list.
     */
    private function createTicketProduct(): array {
        // Create a ticket list
        $listId = $this->main->getDB()->insert('lists', [
            'name' => 'Order Test List ' . uniqid(),
            'aktiv' => 1,
            'meta' => '{}',
        ]);

        // Create WC product
        $product = new WC_Product_Simple();
        $product->set_name('Test Ticket Product');
        $product->set_regular_price('10.00');
        $product->set_status('publish');
        $product->save();

        $productId = $product->get_id();

        // Mark as ticket product
        update_post_meta($productId, 'saso_eventtickets_is_ticket', 'yes');
        update_post_meta($productId, 'saso_eventtickets_list', $listId);

        return ['product' => $product, 'product_id' => $productId, 'list_id' => $listId];
    }

    /**
     * Helper: create a non-ticket product.
     */
    private function createNonTicketProduct(): WC_Product_Simple {
        $product = new WC_Product_Simple();
        $product->set_name('Regular Product');
        $product->set_regular_price('5.00');
        $product->set_status('publish');
        $product->save();
        return $product;
    }

    /**
     * Helper: create an order with items.
     */
    private function createOrder(array $items, string $status = 'completed'): WC_Order {
        $order = wc_create_order();
        foreach ($items as $item) {
            $order->add_product($item['product'], $item['quantity'] ?? 1);
        }
        $order->calculate_totals();
        $order->set_status($status);
        $order->save();
        return $order;
    }

    // ── hasTicketsInOrder ──────────────────────────────────────

    public function test_hasTicketsInOrder_with_ticket_product(): void {
        $ticket = $this->createTicketProduct();
        $order = $this->createOrder([
            ['product' => $ticket['product']],
        ]);

        $wcOrder = $this->main->getWC()->getOrderManager();
        $this->assertTrue($wcOrder->hasTicketsInOrder($order));
    }

    public function test_hasTicketsInOrder_without_ticket_product(): void {
        $product = $this->createNonTicketProduct();
        $order = $this->createOrder([
            ['product' => $product],
        ]);

        $wcOrder = $this->main->getWC()->getOrderManager();
        $this->assertFalse($wcOrder->hasTicketsInOrder($order));
    }

    public function test_hasTicketsInOrder_mixed_products(): void {
        $ticket = $this->createTicketProduct();
        $regular = $this->createNonTicketProduct();
        $order = $this->createOrder([
            ['product' => $ticket['product']],
            ['product' => $regular],
        ]);

        $wcOrder = $this->main->getWC()->getOrderManager();
        $this->assertTrue($wcOrder->hasTicketsInOrder($order));
    }

    // ── Ticket Generation Pipeline ─────────────────────────────

    public function test_add_serialcode_creates_codes(): void {
        $ticket = $this->createTicketProduct();
        $order = $this->createOrder([
            ['product' => $ticket['product'], 'quantity' => 2],
        ]);

        $wcOrder = $this->main->getWC()->getOrderManager();
        $wcOrder->add_serialcode_to_order($order->get_id());

        // Check codes were created in DB for this order
        $codes = $this->main->getCore()->getCodesByOrderId($order->get_id());
        $this->assertGreaterThanOrEqual(2, count($codes), 'Should have created at least 2 codes for quantity 2');
    }

    public function test_add_serialcode_idempotent(): void {
        $ticket = $this->createTicketProduct();
        $order = $this->createOrder([
            ['product' => $ticket['product'], 'quantity' => 1],
        ]);

        $wcOrder = $this->main->getWC()->getOrderManager();

        // Run twice
        $wcOrder->add_serialcode_to_order($order->get_id());
        $countFirst = count($this->main->getCore()->getCodesByOrderId($order->get_id()));

        $wcOrder->add_serialcode_to_order($order->get_id());
        $countSecond = count($this->main->getCore()->getCodesByOrderId($order->get_id()));

        // Should not create duplicate codes
        $this->assertSame($countFirst, $countSecond, 'Running add_serialcode twice should not create duplicates');
    }

    // ── getTicketsFromOrder ────────────────────────────────────

    public function test_getTicketsFromOrder_returns_ticket_items(): void {
        $ticket = $this->createTicketProduct();
        $order = $this->createOrder([
            ['product' => $ticket['product'], 'quantity' => 3],
        ]);

        // Generate codes first
        $wcOrder = $this->main->getWC()->getOrderManager();
        $wcOrder->add_serialcode_to_order($order->get_id());

        // Refresh order
        $order = wc_get_order($order->get_id());
        $tickets = $wcOrder->getTicketsFromOrder($order);

        $this->assertNotEmpty($tickets);
        $first = reset($tickets);
        $this->assertEquals(3, $first['quantity']);
        $this->assertEquals($ticket['product_id'], $first['product_id']);
    }

    public function test_getTicketsFromOrder_excludes_non_ticket_items(): void {
        $ticket = $this->createTicketProduct();
        $regular = $this->createNonTicketProduct();
        $order = $this->createOrder([
            ['product' => $ticket['product']],
            ['product' => $regular],
        ]);

        $wcOrder = $this->main->getWC()->getOrderManager();
        $wcOrder->add_serialcode_to_order($order->get_id());

        $order = wc_get_order($order->get_id());
        $tickets = $wcOrder->getTicketsFromOrder($order);

        // Should only contain the ticket product, not the regular one
        foreach ($tickets as $t) {
            $this->assertEquals($ticket['product_id'], $t['product_id']);
        }
    }

    // ── Order Status Change Handling ───────────────────────────

    public function test_status_change_to_completed_generates_tickets(): void {
        $ticket = $this->createTicketProduct();

        // Create order in pending status
        $order = $this->createOrder([
            ['product' => $ticket['product'], 'quantity' => 1],
        ], 'pending');

        // No codes yet
        $codesBefore = $this->main->getCore()->getCodesByOrderId($order->get_id());
        $this->assertEmpty($codesBefore);

        // Actually set order to completed (so isOrderPaid returns true),
        // then call the handler as WooCommerce would.
        $order->set_status('completed');
        $order->save();

        $wcOrder = $this->main->getWC()->getOrderManager();
        $wcOrder->woocommerce_order_status_changed($order->get_id(), 'pending', 'completed');

        $codesAfter = $this->main->getCore()->getCodesByOrderId($order->get_id());
        $this->assertNotEmpty($codesAfter);
    }

    public function test_status_change_to_cancelled_does_not_generate_tickets(): void {
        $ticket = $this->createTicketProduct();
        $order = $this->createOrder([
            ['product' => $ticket['product']],
        ], 'pending');

        $wcOrder = $this->main->getWC()->getOrderManager();
        $wcOrder->woocommerce_order_status_changed($order->get_id(), 'pending', 'cancelled');

        $codes = $this->main->getCore()->getCodesByOrderId($order->get_id());
        $this->assertEmpty($codes);
    }

    // ── Code Metadata ──────────────────────────────────────────

    public function test_generated_code_has_correct_metadata(): void {
        $ticket = $this->createTicketProduct();
        $order = $this->createOrder([
            ['product' => $ticket['product'], 'quantity' => 1],
        ]);

        $wcOrder = $this->main->getWC()->getOrderManager();
        $wcOrder->add_serialcode_to_order($order->get_id());

        $codes = $this->main->getCore()->getCodesByOrderId($order->get_id());
        $this->assertNotEmpty($codes);

        $codeObj = $codes[0];
        $metaObj = $this->main->getCore()->encodeMetaValuesAndFillObject($codeObj['meta'], $codeObj);

        $this->assertEquals($order->get_id(), $metaObj['woocommerce']['order_id']);
        $this->assertEquals($ticket['product_id'], $metaObj['woocommerce']['product_id']);
        $this->assertEquals(1, $metaObj['wc_ticket']['is_ticket']);
    }

	// ── add_serialcode_to_order — edge cases ────────────────────

	public function test_add_serialcode_skips_zero_order_id(): void {
		$this->main->getWC()->getOrderManager()->add_serialcode_to_order(0);
		$this->assertTrue(true); // No exception = pass
	}

	public function test_add_serialcode_skips_nonexistent_order(): void {
		$this->main->getWC()->getOrderManager()->add_serialcode_to_order(999999);
		$this->assertTrue(true); // No exception = pass
	}

	public function test_add_serialcode_skips_pending_order(): void {
		$ticket = $this->createTicketProduct();
		$order = $this->createOrder([
			['product' => $ticket['product'], 'quantity' => 1],
		], 'pending');

		$this->main->getWC()->getOrderManager()->add_serialcode_to_order($order->get_id());

		$codes = $this->main->getCore()->getCodesByOrderId($order->get_id());
		$this->assertEmpty($codes, 'No codes should be generated for pending (unpaid) order');
	}

	// ── woocommerce_order_status_changed — refund path ──────────

	public function test_status_change_to_refunded_does_not_generate_tickets(): void {
		$ticket = $this->createTicketProduct();
		$order = $this->createOrder([
			['product' => $ticket['product']],
		], 'pending');

		$this->main->getWC()->getOrderManager()->woocommerce_order_status_changed(
			$order->get_id(), 'completed', 'refunded'
		);

		$codes = $this->main->getCore()->getCodesByOrderId($order->get_id());
		$this->assertEmpty($codes);
	}

	public function test_status_change_fires_action(): void {
		$ticket = $this->createTicketProduct();
		$order = $this->createOrder([
			['product' => $ticket['product']],
		]);

		$fired = false;
		$callback = function () use (&$fired) {
			$fired = true;
		};
		add_action($this->main->_do_action_prefix . 'woocommerce-hooks_woocommerce_order_status_changed', $callback);

		$this->main->getWC()->getOrderManager()->woocommerce_order_status_changed(
			$order->get_id(), 'pending', 'completed'
		);

		$this->assertTrue($fired);
		remove_action($this->main->_do_action_prefix . 'woocommerce-hooks_woocommerce_order_status_changed', $callback);
	}

	// ── Order item meta correctness ─────────────────────────────

	public function test_ticket_is_marked_in_order_item_meta(): void {
		$ticket = $this->createTicketProduct();
		$order = $this->createOrder([
			['product' => $ticket['product']],
		]);

		$this->main->getWC()->getOrderManager()->add_serialcode_to_order($order->get_id());

		$order = wc_get_order($order->get_id());
		foreach ($order->get_items() as $item_id => $item) {
			$isTicket = wc_get_order_item_meta($item_id, '_saso_eventtickets_is_ticket', true);
			$pid = $item->get_product_id();
			if (get_post_meta($pid, 'saso_eventtickets_is_ticket', true) == 'yes') {
				// Order item stores 1 (truthy), not 'yes'
				$this->assertNotEmpty($isTicket);
			}
		}
	}

	public function test_code_list_stored_in_order_item_meta(): void {
		$ticket = $this->createTicketProduct();
		$order = $this->createOrder([
			['product' => $ticket['product']],
		]);

		$this->main->getWC()->getOrderManager()->add_serialcode_to_order($order->get_id());

		$order = wc_get_order($order->get_id());
		foreach ($order->get_items() as $item_id => $item) {
			$codeList = wc_get_order_item_meta($item_id, '_saso_eventticket_code_list', true);
			$pid = $item->get_product_id();
			if (get_post_meta($pid, 'saso_eventtickets_is_ticket', true) == 'yes') {
				$this->assertEquals($ticket['list_id'], intval($codeList));
			}
		}
	}

	public function test_public_ticket_ids_stored_in_order_item_meta(): void {
		$ticket = $this->createTicketProduct();
		$order = $this->createOrder([
			['product' => $ticket['product'], 'quantity' => 2],
		]);

		$this->main->getWC()->getOrderManager()->add_serialcode_to_order($order->get_id());

		$order = wc_get_order($order->get_id());
		foreach ($order->get_items() as $item_id => $item) {
			$publicIds = wc_get_order_item_meta($item_id, '_saso_eventtickets_public_ticket_ids', true);
			$pid = $item->get_product_id();
			if (get_post_meta($pid, 'saso_eventtickets_is_ticket', true) == 'yes') {
				$this->assertNotEmpty($publicIds, 'Public ticket IDs should be stored');
				$idsArr = explode(',', $publicIds);
				$this->assertCount(2, $idsArr, 'Should have 2 public ticket IDs for qty 2');
			}
		}
	}

	// ── woocommerce_delete_order_item ───────────────────────────

	public function test_delete_order_item_clears_wc_meta_from_code(): void {
		update_option('sasoEventticketswcRestrictFreeCodeByOrderRefund', '1');
		$this->main->getOptions()->initOptions();

		$ticket = $this->createTicketProduct();
		$order = $this->createOrder([
			['product' => $ticket['product']],
		]);

		$this->main->getWC()->getOrderManager()->add_serialcode_to_order($order->get_id());

		$order = wc_get_order($order->get_id());
		$codeStr = '';
		$targetItemId = 0;
		foreach ($order->get_items() as $item_id => $item) {
			$codes = wc_get_order_item_meta($item_id, '_saso_eventtickets_product_code', true);
			if (!empty($codes)) {
				$codeStr = trim(explode(',', $codes)[0]);
				$targetItemId = $item_id;
			}
		}
		$this->assertNotEmpty($codeStr);

		// Before deletion, code has WC order info
		$codeObjBefore = $this->main->getCore()->retrieveCodeByCode($codeStr);
		$metaBefore = json_decode($codeObjBefore['meta'], true);
		$this->assertNotEmpty($metaBefore['woocommerce']['order_id'] ?? 0);

		$this->main->getWC()->getOrderManager()->woocommerce_delete_order_item($targetItemId);

		// After deletion, WC info should be cleared from code meta
		$codeObjAfter = $this->main->getCore()->retrieveCodeByCode($codeStr);
		$metaAfter = json_decode($codeObjAfter['meta'], true);
		$this->assertEmpty($metaAfter['woocommerce']['order_id'] ?? 0, 'WC order info should be cleared after item deletion');
	}

	public function test_delete_order_item_fires_action(): void {
		$ticket = $this->createTicketProduct();
		$order = $this->createOrder([
			['product' => $ticket['product']],
		]);

		$this->main->getWC()->getOrderManager()->add_serialcode_to_order($order->get_id());

		$order = wc_get_order($order->get_id());
		$targetItemId = 0;
		foreach ($order->get_items() as $item_id => $item) {
			$targetItemId = $item_id;
			break;
		}

		$fired = false;
		$callback = function () use (&$fired) {
			$fired = true;
		};
		add_action($this->main->_do_action_prefix . 'woocommerce-hooks_woocommerce_delete_order_item', $callback);

		$this->main->getWC()->getOrderManager()->woocommerce_delete_order_item($targetItemId);

		$this->assertTrue($fired);
		remove_action($this->main->_do_action_prefix . 'woocommerce-hooks_woocommerce_delete_order_item', $callback);
	}
}
