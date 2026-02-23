<?php
/**
 * Tests for Admin methods: setWoocommerceTicketInfoForCode,
 * removeRedeemWoocommerceTicketForCode, removeWoocommerceTicketForCode.
 */

class AdminTicketInfoTest extends WP_UnitTestCase {

    private $main;

    public function set_up(): void {
        parent::set_up();
        $this->main = sasoEventtickets::Instance();

        if (!class_exists('WC_Product_Simple')) {
            $this->markTestSkipped('WooCommerce not available');
        }
    }

    private function createCodeWithOrder(): array {
        $listId = $this->main->getDB()->insert('lists', [
            'name' => 'TicketInfo Test ' . uniqid(),
            'aktiv' => 1,
            'meta' => '{}',
        ]);

        $product = new WC_Product_Simple();
        $product->set_name('TicketInfo Product ' . uniqid());
        $product->set_regular_price('10.00');
        $product->set_status('publish');
        $product->save();

        update_post_meta($product->get_id(), 'saso_eventtickets_is_ticket', 'yes');
        update_post_meta($product->get_id(), 'saso_eventtickets_list', $listId);

        $order = wc_create_order();
        $order->add_product($product, 1);
        $order->calculate_totals();
        $order->set_status('completed');
        $order->save();

        $this->main->getWC()->getOrderManager()->add_serialcode_to_order($order->get_id());
        $codes = $this->main->getCore()->getCodesByOrderId($order->get_id());

        return [
            'code' => $codes[0]['code'],
            'order_id' => $order->get_id(),
            'list_id' => $listId,
            'product_id' => $product->get_id(),
        ];
    }

    // ── setWoocommerceTicketInfoForCode ───────────────────────────

    public function test_setWoocommerceTicketInfoForCode_sets_name(): void {
        $data = $this->createCodeWithOrder();

        $this->main->getAdmin()->setWoocommerceTicketInfoForCode(
            $data['code'],
            'John Doe'
        );

        $codeObj = $this->main->getCore()->retrieveCodeByCode($data['code']);
        $metaObj = $this->main->getCore()->encodeMetaValuesAndFillObject($codeObj['meta'], $codeObj);

        $this->assertEquals('John Doe', $metaObj['wc_ticket']['name_per_ticket']);
    }

    public function test_setWoocommerceTicketInfoForCode_sets_value(): void {
        $data = $this->createCodeWithOrder();

        $this->main->getAdmin()->setWoocommerceTicketInfoForCode(
            $data['code'],
            '',
            'VIP-Gold'
        );

        $codeObj = $this->main->getCore()->retrieveCodeByCode($data['code']);
        $metaObj = $this->main->getCore()->encodeMetaValuesAndFillObject($codeObj['meta'], $codeObj);

        $this->assertEquals('VIP-Gold', $metaObj['wc_ticket']['value_per_ticket']);
    }

    public function test_setWoocommerceTicketInfoForCode_sets_day(): void {
        $data = $this->createCodeWithOrder();

        $this->main->getAdmin()->setWoocommerceTicketInfoForCode(
            $data['code'],
            '',
            '',
            '2026-06-15'
        );

        $codeObj = $this->main->getCore()->retrieveCodeByCode($data['code']);
        $metaObj = $this->main->getCore()->encodeMetaValuesAndFillObject($codeObj['meta'], $codeObj);

        $this->assertEquals('2026-06-15', $metaObj['wc_ticket']['day_per_ticket']);
        $this->assertEquals(1, $metaObj['wc_ticket']['is_daychooser']);
    }

    public function test_setWoocommerceTicketInfoForCode_marks_as_ticket(): void {
        $data = $this->createCodeWithOrder();

        $this->main->getAdmin()->setWoocommerceTicketInfoForCode($data['code']);

        $codeObj = $this->main->getCore()->retrieveCodeByCode($data['code']);
        $metaObj = $this->main->getCore()->encodeMetaValuesAndFillObject($codeObj['meta'], $codeObj);

        $this->assertEquals(1, $metaObj['wc_ticket']['is_ticket']);
    }

    // ── removeRedeemWoocommerceTicketForCode ──────────────────────

    public function test_removeRedeemWoocommerceTicketForCode_throws_without_code(): void {
        $this->expectException(Exception::class);
        $this->main->getAdmin()->removeRedeemWoocommerceTicketForCode([]);
    }

    public function test_removeRedeemWoocommerceTicketForCode_clears_used_data(): void {
        $data = $this->createCodeWithOrder();

        // First set some used data
        $codeObj = $this->main->getCore()->retrieveCodeByCode($data['code']);
        $metaObj = $this->main->getCore()->encodeMetaValuesAndFillObject($codeObj['meta'], $codeObj);
        $metaObj['used']['reg_request'] = '2026-01-15 10:00:00';
        $metaObj['wc_ticket']['redeemed_date'] = '2026-01-15 10:00:00';
        $metaJson = $this->main->getCore()->json_encode_with_error_handling($metaObj);
        $this->main->getDB()->update('codes', ['meta' => $metaJson, 'redeemed' => 1], ['id' => $codeObj['id']]);

        // Now remove the redeem info
        $this->main->getAdmin()->removeRedeemWoocommerceTicketForCode(['code' => $data['code']]);

        // Verify cleared
        $updated = $this->main->getCore()->retrieveCodeByCode($data['code']);
        $updatedMeta = $this->main->getCore()->encodeMetaValuesAndFillObject($updated['meta'], $updated);
        $this->assertEmpty($updatedMeta['wc_ticket']['redeemed_date']);
        $this->assertEquals(0, intval($updated['redeemed']));
    }

    // ── removeWoocommerceTicketForCode ────────────────────────────

    public function test_removeWoocommerceTicketForCode_throws_without_code(): void {
        $this->expectException(Exception::class);
        $this->main->getAdmin()->removeWoocommerceTicketForCode([]);
    }
}
