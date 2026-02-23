<?php
/**
 * Tests for WC Product meta saving:
 * woocommerce_process_product_meta verifies post meta
 * is correctly saved from request data.
 */

class WCProductMetaSaveTest extends WP_UnitTestCase {

    private $main;

    public function set_up(): void {
        parent::set_up();
        $this->main = sasoEventtickets::Instance();

        if (!class_exists('WC_Product_Simple')) {
            $this->markTestSkipped('WooCommerce not available');
        }
    }

    private function createProduct(): WC_Product_Simple {
        $product = new WC_Product_Simple();
        $product->set_name('MetaSave Product ' . uniqid());
        $product->set_regular_price('10.00');
        $product->set_status('publish');
        $product->save();
        return $product;
    }

    /**
     * Set minimal POST defaults so woocommerce_process_product_meta
     * doesn't hit "Undefined array key" errors for number fields.
     * Also ensures REQUEST_METHOD is POST so getRequest() reads $_POST.
     */
    private function setPostDefaults(): void {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $numberKeys = [
            'saso_eventtickets_ticket_max_redeem_amount',
            'saso_eventtickets_ticket_amount_per_item',
            'saso_eventtickets_daychooser_offset_start',
            'saso_eventtickets_daychooser_offset_end',
        ];
        foreach ($numberKeys as $key) {
            if (!isset($_POST[$key])) {
                $_POST[$key] = '';
            }
        }
    }

    private function clearPostDefaults(): void {
        $numberKeys = [
            'saso_eventtickets_ticket_max_redeem_amount',
            'saso_eventtickets_ticket_amount_per_item',
            'saso_eventtickets_daychooser_offset_start',
            'saso_eventtickets_daychooser_offset_end',
        ];
        foreach ($numberKeys as $key) {
            unset($_POST[$key]);
        }
    }

    // ── woocommerce_process_product_meta ──────────────────────────

    public function test_process_product_meta_saves_list(): void {
        $product = $this->createProduct();
        $listId = $this->main->getDB()->insert('lists', [
            'name' => 'MetaSave List ' . uniqid(),
            'aktiv' => 1,
            'meta' => '{}',
        ]);

        // Simulate POST data
        $_POST['saso_eventtickets_list'] = (string) $listId;
        $this->setPostDefaults();
        // Reset cached request data
        $ref = new ReflectionProperty('SASO_EVENTTICKETS', 'REQUEST_DATA');
        $ref->setAccessible(true);
        $ref->setValue(null, null);

        $pm = $this->main->getWC()->getProductManager();
        $pm->woocommerce_process_product_meta($product->get_id(), null);

        $savedList = get_post_meta($product->get_id(), 'saso_eventtickets_list', true);
        $this->assertEquals($listId, $savedList);

        unset($_POST['saso_eventtickets_list']);
        $this->clearPostDefaults();
        $ref->setValue(null, null);
    }

    public function test_process_product_meta_saves_ticket_checkbox(): void {
        $product = $this->createProduct();

        $_POST['saso_eventtickets_is_ticket'] = 'yes';
        $this->setPostDefaults();
        $ref = new ReflectionProperty('SASO_EVENTTICKETS', 'REQUEST_DATA');
        $ref->setAccessible(true);
        $ref->setValue(null, null);

        $pm = $this->main->getWC()->getProductManager();
        $pm->woocommerce_process_product_meta($product->get_id(), null);

        $isTicket = get_post_meta($product->get_id(), 'saso_eventtickets_is_ticket', true);
        $this->assertEquals('yes', $isTicket);

        unset($_POST['saso_eventtickets_is_ticket']);
        $this->clearPostDefaults();
        $ref->setValue(null, null);
    }

    public function test_process_product_meta_saves_start_date(): void {
        $product = $this->createProduct();

        $_POST['saso_eventtickets_ticket_start_date'] = '2026-12-25';
        $_POST['saso_eventtickets_ticket_start_time'] = '10:00:00';
        $this->setPostDefaults();
        $ref = new ReflectionProperty('SASO_EVENTTICKETS', 'REQUEST_DATA');
        $ref->setAccessible(true);
        $ref->setValue(null, null);

        $pm = $this->main->getWC()->getProductManager();
        $pm->woocommerce_process_product_meta($product->get_id(), null);

        $startDate = get_post_meta($product->get_id(), 'saso_eventtickets_ticket_start_date', true);
        $startTime = get_post_meta($product->get_id(), 'saso_eventtickets_ticket_start_time', true);
        $this->assertEquals('2026-12-25', $startDate);
        $this->assertEquals('10:00:00', $startTime);

        unset($_POST['saso_eventtickets_ticket_start_date']);
        unset($_POST['saso_eventtickets_ticket_start_time']);
        $this->clearPostDefaults();
        $ref->setValue(null, null);
    }

    public function test_process_product_meta_saves_max_redeem_amount(): void {
        $product = $this->createProduct();

        $_POST['saso_eventtickets_ticket_max_redeem_amount'] = '5';
        $this->setPostDefaults();
        $ref = new ReflectionProperty('SASO_EVENTTICKETS', 'REQUEST_DATA');
        $ref->setAccessible(true);
        $ref->setValue(null, null);

        $pm = $this->main->getWC()->getProductManager();
        $pm->woocommerce_process_product_meta($product->get_id(), null);

        $maxRedeem = get_post_meta($product->get_id(), 'saso_eventtickets_ticket_max_redeem_amount', true);
        $this->assertEquals(5, intval($maxRedeem));

        unset($_POST['saso_eventtickets_ticket_max_redeem_amount']);
        $this->clearPostDefaults();
        $ref->setValue(null, null);
    }

    public function test_process_product_meta_deletes_empty_list(): void {
        $product = $this->createProduct();

        // First set a list
        update_post_meta($product->get_id(), 'saso_eventtickets_list', '99');

        // Then clear it via empty POST
        // Don't set saso_eventtickets_list in POST
        $this->setPostDefaults();
        $ref = new ReflectionProperty('SASO_EVENTTICKETS', 'REQUEST_DATA');
        $ref->setAccessible(true);
        $ref->setValue(null, null);

        $pm = $this->main->getWC()->getProductManager();
        $pm->woocommerce_process_product_meta($product->get_id(), null);

        $savedList = get_post_meta($product->get_id(), 'saso_eventtickets_list', true);
        $this->assertEmpty($savedList);

        $this->clearPostDefaults();
        $ref->setValue(null, null);
    }

    public function test_process_product_meta_saves_event_location(): void {
        $product = $this->createProduct();

        $_POST['saso_eventtickets_event_location'] = 'Berlin Arena';
        $this->setPostDefaults();
        $ref = new ReflectionProperty('SASO_EVENTTICKETS', 'REQUEST_DATA');
        $ref->setAccessible(true);
        $ref->setValue(null, null);

        $pm = $this->main->getWC()->getProductManager();
        $pm->woocommerce_process_product_meta($product->get_id(), null);

        $location = get_post_meta($product->get_id(), 'saso_eventtickets_event_location', true);
        $this->assertEquals('Berlin Arena', $location);

        unset($_POST['saso_eventtickets_event_location']);
        $this->clearPostDefaults();
        $ref->setValue(null, null);
    }
}
