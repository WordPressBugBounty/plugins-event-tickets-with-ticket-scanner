<?php
/**
 * Tests for ticket display methods (displayTicketDateAsString, labels, ICS generation, redeem amount text).
 */

class TicketDisplayTest extends WP_UnitTestCase {

    private $main;

    public function set_up(): void {
        parent::set_up();
        $this->main = sasoEventtickets::Instance();

        if (!class_exists('WC_Product_Simple')) {
            $this->markTestSkipped('WooCommerce not available');
        }
    }

    /**
     * Helper: create a ticket product with dates.
     */
    private function createTicketProductWithDates(array $meta = []): WC_Product_Simple {
        $listId = $this->main->getDB()->insert('lists', [
            'name' => 'Display List ' . uniqid(),
            'aktiv' => 1,
            'meta' => '{}',
        ]);

        $product = new WC_Product_Simple();
        $product->set_name('Display Test Product');
        $product->set_regular_price('10.00');
        $product->set_status('publish');
        $product->save();
        $pid = $product->get_id();

        update_post_meta($pid, 'saso_eventtickets_is_ticket', 'yes');
        update_post_meta($pid, 'saso_eventtickets_list', $listId);

        foreach ($meta as $key => $value) {
            update_post_meta($pid, $key, $value);
        }

        return $product;
    }

    // ── displayTicketDateAsString ─────────────────────────────────

    public function test_displayTicketDateAsString_full_date_and_time(): void {
        $product = $this->createTicketProductWithDates([
            'saso_eventtickets_ticket_start_date' => '2026-07-01',
            'saso_eventtickets_ticket_start_time' => '18:00:00',
            'saso_eventtickets_ticket_end_date' => '2026-07-01',
            'saso_eventtickets_ticket_end_time' => '23:00:00',
        ]);

        $ticket = $this->main->getTicketHandler();
        $result = $ticket->displayTicketDateAsString($product->get_id());

        $this->assertStringContainsString('2026', $result);
        $this->assertStringContainsString('18:00', $result);
        $this->assertStringContainsString('23:00', $result);
        $this->assertStringContainsString(' - ', $result);
    }

    public function test_displayTicketDateAsString_only_start_date(): void {
        $product = $this->createTicketProductWithDates([
            'saso_eventtickets_ticket_start_date' => '2026-12-25',
        ]);

        $ticket = $this->main->getTicketHandler();
        $result = $ticket->displayTicketDateAsString($product->get_id());

        $this->assertStringContainsString('2026', $result);
        $this->assertStringContainsString('12', $result);
    }

    public function test_displayTicketDateAsString_no_dates_returns_string(): void {
        $product = $this->createTicketProductWithDates([]);

        $ticket = $this->main->getTicketHandler();
        $result = $ticket->displayTicketDateAsString($product->get_id());

        // May return non-empty string with default/zero dates
        $this->assertIsString($result);
    }

    public function test_displayTicketDateAsString_invalid_product_throws(): void {
        $this->expectException(Exception::class);
        $this->main->getTicketHandler()->displayTicketDateAsString(0);
    }

    public function test_displayTicketDateAsString_custom_format(): void {
        $product = $this->createTicketProductWithDates([
            'saso_eventtickets_ticket_start_date' => '2026-03-15',
            'saso_eventtickets_ticket_start_time' => '09:30:00',
        ]);

        $ticket = $this->main->getTicketHandler();
        $result = $ticket->displayTicketDateAsString($product->get_id(), 'd.m.Y', 'H:i');

        $this->assertStringContainsString('15.03.2026', $result);
        $this->assertStringContainsString('09:30', $result);
    }

    // ── displayDayChooserDateAsString ─────────────────────────────

    public function test_displayDayChooserDateAsString_null_returns_empty(): void {
        $ticket = $this->main->getTicketHandler();
        $result = $ticket->displayDayChooserDateAsString(null);
        $this->assertEmpty($result);
    }

    public function test_displayDayChooserDateAsString_non_daychooser_returns_empty(): void {
        $listId = $this->main->getDB()->insert('lists', [
            'name' => 'DC Display ' . uniqid(),
            'aktiv' => 1,
            'meta' => '{}',
        ]);

        $metaObj = $this->main->getCore()->getMetaObject();
        $metaObj['wc_ticket']['is_daychooser'] = 0;
        $metaJson = $this->main->getCore()->json_encode_with_error_handling($metaObj);

        $code = 'DCD' . strtoupper(uniqid());
        $this->main->getDB()->insert('codes', [
            'code' => $code,
            'code_display' => $code,
            'cvv' => '',
            'meta' => $metaJson,
            'aktiv' => 1,
            'redeemed' => 0,
            'list_id' => $listId,
            'order_id' => 0,
        ]);

        $codeObj = $this->main->getCore()->retrieveCodeByCode($code);
        $result = $this->main->getTicketHandler()->displayDayChooserDateAsString($codeObj);
        $this->assertEmpty($result);
    }

    public function test_displayDayChooserDateAsString_with_day_per_ticket(): void {
        $listId = $this->main->getDB()->insert('lists', [
            'name' => 'DC With Day ' . uniqid(),
            'aktiv' => 1,
            'meta' => '{}',
        ]);

        $metaObj = $this->main->getCore()->getMetaObject();
        $metaObj['wc_ticket']['is_daychooser'] = 1;
        $metaObj['wc_ticket']['day_per_ticket'] = '2026-06-15';
        $metaJson = $this->main->getCore()->json_encode_with_error_handling($metaObj);

        $code = 'DCW' . strtoupper(uniqid());
        $this->main->getDB()->insert('codes', [
            'code' => $code,
            'code_display' => $code,
            'cvv' => '',
            'meta' => $metaJson,
            'aktiv' => 1,
            'redeemed' => 0,
            'list_id' => $listId,
            'order_id' => 0,
        ]);

        $codeObj = $this->main->getCore()->retrieveCodeByCode($code);
        $result = $this->main->getTicketHandler()->displayDayChooserDateAsString($codeObj);
        $this->assertNotEmpty($result);
        $this->assertStringContainsString('2026', $result);
    }

    // ── getLabelNamePerTicket ──────────────────────────────────────

    public function test_getLabelNamePerTicket_default(): void {
        $product = $this->createTicketProductWithDates([]);

        $label = $this->main->getTicketHandler()->getLabelNamePerTicket($product->get_id());
        $this->assertStringContainsString('#{count}', $label);
    }

    public function test_getLabelNamePerTicket_custom(): void {
        $product = $this->createTicketProductWithDates([
            'saso_eventtickets_request_name_per_ticket_label' => 'Attendee name #{count}:',
        ]);

        $label = $this->main->getTicketHandler()->getLabelNamePerTicket($product->get_id());
        $this->assertEquals('Attendee name #{count}:', $label);
    }

    // ── getLabelValuePerTicket ─────────────────────────────────────

    public function test_getLabelValuePerTicket_default(): void {
        $product = $this->createTicketProductWithDates([]);

        $label = $this->main->getTicketHandler()->getLabelValuePerTicket($product->get_id());
        $this->assertStringContainsString('#{count}', $label);
    }

    // ── getLabelDaychooserPerTicket ────────────────────────────────

    public function test_getLabelDaychooserPerTicket_default(): void {
        $product = $this->createTicketProductWithDates([]);

        $label = $this->main->getTicketHandler()->getLabelDaychooserPerTicket($product->get_id());
        $this->assertStringContainsString('#{count}', $label);
    }

    public function test_getLabelDaychooserPerTicket_custom(): void {
        $product = $this->createTicketProductWithDates([
            'saso_eventtickets_request_daychooser_per_ticket_label' => 'Select day #{count}:',
        ]);

        $label = $this->main->getTicketHandler()->getLabelDaychooserPerTicket($product->get_id());
        $this->assertEquals('Select day #{count}:', $label);
    }

    // ── getRedeemAmountText ───────────────────────────────────────

    public function test_getRedeemAmountText_empty_when_max_is_1(): void {
        $product = $this->createTicketProductWithDates([
            'saso_eventtickets_ticket_max_redeem_amount' => 1,
        ]);

        $listId = intval(get_post_meta($product->get_id(), 'saso_eventtickets_list', true));
        $metaObj = $this->main->getCore()->getMetaObject();
        $metaObj['wc_ticket']['is_ticket'] = 1;
        $metaObj['woocommerce']['product_id'] = $product->get_id();
        $metaJson = $this->main->getCore()->json_encode_with_error_handling($metaObj);

        $code = 'RAT' . strtoupper(uniqid());
        $this->main->getDB()->insert('codes', [
            'code' => $code,
            'code_display' => $code,
            'cvv' => '',
            'meta' => $metaJson,
            'aktiv' => 1,
            'redeemed' => 0,
            'list_id' => $listId,
            'order_id' => 0,
        ]);

        $codeObj = $this->main->getCore()->retrieveCodeByCode($code);
        $metaObj2 = $this->main->getCore()->encodeMetaValuesAndFillObject($codeObj['meta'], $codeObj);
        $text = $this->main->getTicketHandler()->getRedeemAmountText($codeObj, $metaObj2);
        // max_redeem_amount=1 means single redeem, no text needed
        $this->assertEmpty($text);
    }

    public function test_getRedeemAmountText_shows_amount_when_max_gt_1(): void {
        $product = $this->createTicketProductWithDates([
            'saso_eventtickets_ticket_max_redeem_amount' => 5,
        ]);

        $listId = intval(get_post_meta($product->get_id(), 'saso_eventtickets_list', true));
        $metaObj = $this->main->getCore()->getMetaObject();
        $metaObj['wc_ticket']['is_ticket'] = 1;
        $metaObj['woocommerce']['product_id'] = $product->get_id();
        $metaJson = $this->main->getCore()->json_encode_with_error_handling($metaObj);

        $code = 'RA5' . strtoupper(uniqid());
        $this->main->getDB()->insert('codes', [
            'code' => $code,
            'code_display' => $code,
            'cvv' => '',
            'meta' => $metaJson,
            'aktiv' => 1,
            'redeemed' => 0,
            'list_id' => $listId,
            'order_id' => 0,
        ]);

        $codeObj = $this->main->getCore()->retrieveCodeByCode($code);
        $metaObj2 = $this->main->getCore()->encodeMetaValuesAndFillObject($codeObj['meta'], $codeObj);
        $text = $this->main->getTicketHandler()->getRedeemAmountText($codeObj, $metaObj2);
        $this->assertNotEmpty($text);
        $this->assertStringContainsString('5', $text);
    }

    // ── generateICSFile ───────────────────────────────────────────

    public function test_generateICSFile_returns_valid_ics(): void {
        $product = $this->createTicketProductWithDates([
            'saso_eventtickets_ticket_start_date' => '2026-08-01',
            'saso_eventtickets_ticket_start_time' => '10:00:00',
            'saso_eventtickets_ticket_end_date' => '2026-08-01',
            'saso_eventtickets_ticket_end_time' => '18:00:00',
        ]);

        $ticket = $this->main->getTicketHandler();
        $ics = $ticket->generateICSFile($product);

        $this->assertStringContainsString('BEGIN:VCALENDAR', $ics);
        $this->assertStringContainsString('END:VCALENDAR', $ics);
        $this->assertStringContainsString('BEGIN:VEVENT', $ics);
        $this->assertStringContainsString('DTSTART:', $ics);
        $this->assertStringContainsString('DTEND:', $ics);
        $this->assertStringContainsString('SUMMARY:Display Test Product', $ics);
    }

    public function test_generateICSFile_no_explicit_date_still_generates(): void {
        // Without explicit dates, calcDateStringAllowedRedeemFrom may provide defaults
        $product = $this->createTicketProductWithDates([]);

        $ticket = $this->main->getTicketHandler();
        try {
            $ics = $ticket->generateICSFile($product);
            // If it doesn't throw, it should still be valid ICS
            $this->assertStringContainsString('BEGIN:VCALENDAR', $ics);
        } catch (Exception $e) {
            // Also acceptable: throws when no date available
            $this->assertStringContainsString('No date available', $e->getMessage());
        }
    }

    public function test_generateICSFile_only_start_time_uses_today(): void {
        $product = $this->createTicketProductWithDates([
            'saso_eventtickets_ticket_start_time' => '14:00:00',
        ]);

        $ticket = $this->main->getTicketHandler();
        $ics = $ticket->generateICSFile($product);

        $this->assertStringContainsString('BEGIN:VCALENDAR', $ics);
        $this->assertStringContainsString('DTSTART:', $ics);
    }

    public function test_generateICSFile_with_location(): void {
        $product = $this->createTicketProductWithDates([
            'saso_eventtickets_ticket_start_date' => '2026-09-15',
            'saso_eventtickets_ticket_start_time' => '19:00:00',
            'saso_eventtickets_event_location' => 'Olympiastadion Berlin',
        ]);

        $ticket = $this->main->getTicketHandler();
        $ics = $ticket->generateICSFile($product);

        $this->assertStringContainsString('LOCATION:', $ics);
        $this->assertStringContainsString('Olympiastadion', $ics);
    }
}
