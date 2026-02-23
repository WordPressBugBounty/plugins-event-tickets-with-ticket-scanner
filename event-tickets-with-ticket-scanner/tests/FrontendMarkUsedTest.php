<?php
/**
 * Tests for Frontend methods: countConfirmedStatus, markAsUsed (with force),
 * removeUsedInformationFromCode, removeUserRegistrationFromCode.
 */

class FrontendMarkUsedTest extends WP_UnitTestCase {

    private $main;

    public function set_up(): void {
        parent::set_up();
        $this->main = sasoEventtickets::Instance();
    }

    /**
     * Helper: create a code in a list.
     */
    private function createCode(): array {
        $listId = $this->main->getDB()->insert('lists', [
            'name' => 'FMU List ' . uniqid(),
            'aktiv' => 1,
            'meta' => '{}',
        ]);

        $metaObj = $this->main->getCore()->getMetaObject();
        $metaJson = $this->main->getCore()->json_encode_with_error_handling($metaObj);

        $code = 'FMU' . strtoupper(uniqid());
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

        return ['code' => $code, 'list_id' => $listId];
    }

    // ── countConfirmedStatus ────────────────────────────────────

    public function test_countConfirmedStatus_increments_count(): void {
        $data = $this->createCode();
        $codeObj = $this->main->getCore()->retrieveCodeByCode($data['code']);
        $codeObj['_valid'] = 1;

        $result = $this->main->getFrontend()->countConfirmedStatus($codeObj);

        // Re-read from DB
        $updated = $this->main->getCore()->retrieveCodeByCode($data['code']);
        $metaObj = $this->main->getCore()->encodeMetaValuesAndFillObject($updated['meta'], $updated);

        $this->assertEquals(1, intval($metaObj['confirmedCount']));
        $this->assertNotEmpty($metaObj['validation']['first_success']);
        $this->assertNotEmpty($metaObj['validation']['last_success']);
    }

    public function test_countConfirmedStatus_increments_twice(): void {
        $data = $this->createCode();

        // First
        $codeObj = $this->main->getCore()->retrieveCodeByCode($data['code']);
        $codeObj['_valid'] = 1;
        $this->main->getFrontend()->countConfirmedStatus($codeObj);

        // Second
        $codeObj = $this->main->getCore()->retrieveCodeByCode($data['code']);
        $codeObj['_valid'] = 1;
        $this->main->getFrontend()->countConfirmedStatus($codeObj);

        $updated = $this->main->getCore()->retrieveCodeByCode($data['code']);
        $metaObj = $this->main->getCore()->encodeMetaValuesAndFillObject($updated['meta'], $updated);

        $this->assertEquals(2, intval($metaObj['confirmedCount']));
    }

    public function test_countConfirmedStatus_skips_invalid_code(): void {
        $data = $this->createCode();
        $codeObj = $this->main->getCore()->retrieveCodeByCode($data['code']);
        $codeObj['_valid'] = 0; // not valid

        $this->main->getFrontend()->countConfirmedStatus($codeObj);

        $updated = $this->main->getCore()->retrieveCodeByCode($data['code']);
        $metaObj = $this->main->getCore()->encodeMetaValuesAndFillObject($updated['meta'], $updated);

        $this->assertEquals(0, intval($metaObj['confirmedCount']));
    }

    public function test_countConfirmedStatus_force_ignores_valid_flag(): void {
        $data = $this->createCode();
        $codeObj = $this->main->getCore()->retrieveCodeByCode($data['code']);
        $codeObj['_valid'] = 0; // not valid, but force=true

        $this->main->getFrontend()->countConfirmedStatus($codeObj, true);

        $updated = $this->main->getCore()->retrieveCodeByCode($data['code']);
        $metaObj = $this->main->getCore()->encodeMetaValuesAndFillObject($updated['meta'], $updated);

        $this->assertEquals(1, intval($metaObj['confirmedCount']));
    }

    public function test_countConfirmedStatus_skips_inactive_code(): void {
        $data = $this->createCode();
        $codeObj = $this->main->getCore()->retrieveCodeByCode($data['code']);
        $codeObj['aktiv'] = 0; // deactivated
        $codeObj['_valid'] = 1;

        $this->main->getFrontend()->countConfirmedStatus($codeObj);

        $updated = $this->main->getCore()->retrieveCodeByCode($data['code']);
        $metaObj = $this->main->getCore()->encodeMetaValuesAndFillObject($updated['meta'], $updated);

        $this->assertEquals(0, intval($metaObj['confirmedCount']));
    }

    // ── markAsUsed with force ───────────────────────────────────

    public function test_markAsUsed_force_marks_code(): void {
        $data = $this->createCode();
        $codeObj = $this->main->getCore()->retrieveCodeByCode($data['code']);

        // Pre-set confirmedCount to 0 — force should mark after 1 call
        $this->main->getFrontend()->markAsUsed($codeObj, true);

        $updated = $this->main->getCore()->retrieveCodeByCode($data['code']);
        $metaObj = $this->main->getCore()->encodeMetaValuesAndFillObject($updated['meta'], $updated);

        $this->assertNotEmpty($metaObj['used']['reg_request']);
    }

    public function test_markAsUsed_without_force_and_option_does_nothing(): void {
        $data = $this->createCode();
        $codeObj = $this->main->getCore()->retrieveCodeByCode($data['code']);

        // Option is not active in free version
        $this->main->getFrontend()->markAsUsed($codeObj, false);

        $updated = $this->main->getCore()->retrieveCodeByCode($data['code']);
        $metaObj = $this->main->getCore()->encodeMetaValuesAndFillObject($updated['meta'], $updated);

        $this->assertEmpty($metaObj['used']['reg_request']);
    }

    public function test_markAsUsed_force_already_used_sets_valid_5(): void {
        $data = $this->createCode();

        // First mark
        $codeObj = $this->main->getCore()->retrieveCodeByCode($data['code']);
        $this->main->getFrontend()->markAsUsed($codeObj, true);

        // Second mark — should set _valid=5
        $codeObj = $this->main->getCore()->retrieveCodeByCode($data['code']);
        $result = $this->main->getFrontend()->markAsUsed($codeObj, true);

        $this->assertEquals(5, $result['_valid']);
    }

    // ── removeUsedInformationFromCode ───────────────────────────

    public function test_removeUsedInformation_clears_used_data(): void {
        $data = $this->createCode();
        $codeObj = $this->main->getCore()->retrieveCodeByCode($data['code']);

        // Mark as used first
        $this->main->getFrontend()->markAsUsed($codeObj, true);

        // Verify it's used
        $marked = $this->main->getCore()->retrieveCodeByCode($data['code']);
        $this->assertTrue($this->main->getFrontend()->isUsed($marked));

        // Remove used info
        $this->main->getAdmin()->removeUsedInformationFromCode(['code' => $data['code']]);

        // Should no longer be used
        $cleared = $this->main->getCore()->retrieveCodeByCode($data['code']);
        $this->assertFalse($this->main->getFrontend()->isUsed($cleared));

        $metaObj = $this->main->getCore()->encodeMetaValuesAndFillObject($cleared['meta'], $cleared);
        $this->assertEmpty($metaObj['used']['reg_request']);
        $this->assertEquals(0, intval($metaObj['confirmedCount']));
    }

    public function test_removeUsedInformation_missing_code_throws(): void {
        $this->expectException(Exception::class);
        $this->main->getAdmin()->removeUsedInformationFromCode([]);
    }

    // ── markAsUsed inactive code does nothing ──────────────────

    public function test_markAsUsed_force_inactive_code_does_nothing(): void {
        $data = $this->createCode();
        $codeObj = $this->main->getCore()->retrieveCodeByCode($data['code']);
        $codeObj['aktiv'] = 0;

        $this->main->getFrontend()->markAsUsed($codeObj, true);

        $updated = $this->main->getCore()->retrieveCodeByCode($data['code']);
        $metaObj = $this->main->getCore()->encodeMetaValuesAndFillObject($updated['meta'], $updated);

        $this->assertEmpty($metaObj['used']['reg_request']);
    }
}
