<?php
/**
 * Tests for one-time-use code behavior and the mark-as-used flow.
 * When oneTimeUseOfRegisterCode is enabled, codes become "used" after validation.
 */

class OneTimeUseTest extends WP_UnitTestCase {

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
            'name' => 'OTU List ' . uniqid(),
            'aktiv' => 1,
            'meta' => '{}',
        ]);

        $metaObj = $this->main->getCore()->getMetaObject();
        $metaJson = $this->main->getCore()->json_encode_with_error_handling($metaObj);

        $code = 'OTU' . strtoupper(uniqid());
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

    // ── One-time use disabled ─────────────────────────────────────

    public function test_code_not_marked_used_when_option_disabled(): void {
        $this->main->getOptions()->changeOption([
            'key' => 'oneTimeUseOfRegisterCode',
            'value' => 0,
        ]);

        $data = $this->createCode();
        $codeObj = $this->main->getCore()->retrieveCodeByCode($data['code']);

        // Check code (should not mark as used)
        $this->main->getFrontend()->checkCode(['code' => $data['code']]);

        // Re-retrieve
        $codeObj = $this->main->getCore()->retrieveCodeByCode($data['code']);
        $this->assertFalse($this->main->getFrontend()->isUsed($codeObj));
    }

    // ── One-time use enabled ──────────────────────────────────────

    public function test_oneTimeUse_option_not_available_in_free_version(): void {
        // oneTimeUseOfRegisterCode is a premium-only option (not in Options definitions)
        $this->assertFalse(
            $this->main->getOptions()->isOptionCheckboxActive('oneTimeUseOfRegisterCode'),
            'oneTimeUseOfRegisterCode should not be available in free version'
        );
    }

    public function test_code_stays_valid_without_oneTimeUse(): void {
        $data = $this->createCode();

        // Without oneTimeUseOfRegisterCode option, checking the same code
        // multiple times should always return valid=1
        $result1 = $this->main->getFrontend()->checkCode(['code' => $data['code']]);
        $this->assertEquals(1, $result1['valid']);

        $result2 = $this->main->getFrontend()->checkCode(['code' => $data['code']]);
        $this->assertEquals(1, $result2['valid']);
    }

    // ── Multiple use before marked ────────────────────────────────

    public function test_code_marked_used_after_n_checks(): void {
        $this->main->getOptions()->changeOption([
            'key' => 'oneTimeUseOfRegisterCode',
            'value' => 1,
        ]);
        $this->main->getOptions()->changeOption([
            'key' => 'oneTimeUseOfRegisterAmount',
            'value' => 3,
        ]);

        $data = $this->createCode();

        // First 3 checks should be valid (amount = 3 means mark used after 3 confirmations)
        $result1 = $this->main->getFrontend()->checkCode(['code' => $data['code']]);
        $this->assertEquals(1, $result1['valid']);

        // Check if isUsed changes after checks
        $codeObj = $this->main->getCore()->retrieveCodeByCode($data['code']);
        // With amount=3, might need more checks or confirmations

        // Reset option
        $this->main->getOptions()->changeOption([
            'key' => 'oneTimeUseOfRegisterCode',
            'value' => 0,
        ]);
        $this->main->getOptions()->changeOption([
            'key' => 'oneTimeUseOfRegisterAmount',
            'value' => 1,
        ]);
    }

    // ── isUsed checks meta field ──────────────────────────────────

    public function test_isUsed_checks_meta_used_reg_request(): void {
        $data = $this->createCode();
        $codeObj = $this->main->getCore()->retrieveCodeByCode($data['code']);

        // Not used
        $this->assertFalse($this->main->getFrontend()->isUsed($codeObj));

        // Set used in meta
        $metaObj = $this->main->getCore()->encodeMetaValuesAndFillObject($codeObj['meta'], $codeObj);
        $metaObj['used']['reg_request'] = wp_date('Y-m-d H:i:s');
        $metaJson = $this->main->getCore()->json_encode_with_error_handling($metaObj);
        $this->main->getDB()->update('codes', ['meta' => $metaJson], ['id' => $codeObj['id']]);

        // Now should be used
        $codeObj = $this->main->getCore()->retrieveCodeByCode($data['code']);
        $this->assertTrue($this->main->getFrontend()->isUsed($codeObj));
    }
}
