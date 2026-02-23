<?php
/**
 * Tests for Core code status methods: checkCodeExpired,
 * isCodeIsRegistered, Options reset/delete methods,
 * getOptionsKeys, getOptionsOnlyPublic, getOption.
 */

class CoreExpiredAndRegisteredTest extends WP_UnitTestCase {

    private $main;

    public function set_up(): void {
        parent::set_up();
        $this->main = sasoEventtickets::Instance();
    }

    public function tear_down(): void {
        // Re-initialize options to prevent cache corruption
        $this->main->getOptions()->initOptions();
        parent::tear_down();
    }

    // ── checkCodeExpired ─────────────────────────────────────────

    public function test_checkCodeExpired_returns_false_without_premium(): void {
        $codeObj = ['id' => 999, 'code' => 'TEST', 'meta' => '{}', 'aktiv' => 1];
        $result = $this->main->getCore()->checkCodeExpired($codeObj);
        $this->assertFalse($result);
    }

    // ── isCodeIsRegistered ───────────────────────────────────────

    public function test_isCodeIsRegistered_false_for_empty_meta(): void {
        $codeObj = ['id' => 999, 'code' => 'TEST', 'meta' => '{}'];
        $result = $this->main->getCore()->isCodeIsRegistered($codeObj);
        $this->assertFalse($result);
    }

    public function test_isCodeIsRegistered_false_for_null_meta(): void {
        $codeObj = ['id' => 999, 'code' => 'TEST', 'meta' => ''];
        $result = $this->main->getCore()->isCodeIsRegistered($codeObj);
        $this->assertFalse($result);
    }

    public function test_isCodeIsRegistered_true_for_registered_user(): void {
        $meta = json_encode(['user' => ['value' => 'john@example.com']]);
        $codeObj = ['id' => 999, 'code' => 'TEST', 'meta' => $meta, 'list_id' => 1, 'order_id' => 0];
        $result = $this->main->getCore()->isCodeIsRegistered($codeObj);
        $this->assertTrue($result);
    }

    public function test_isCodeIsRegistered_false_for_empty_user_value(): void {
        $meta = json_encode(['user' => ['value' => '']]);
        $codeObj = ['id' => 999, 'code' => 'TEST', 'meta' => $meta, 'list_id' => 1, 'order_id' => 0];
        $result = $this->main->getCore()->isCodeIsRegistered($codeObj);
        $this->assertFalse($result);
    }

    // ── getOptionsKeys ───────────────────────────────────────────

    public function test_getOptionsKeys_returns_array(): void {
        $keys = $this->main->getOptions()->getOptionsKeys();
        $this->assertIsArray($keys);
        $this->assertNotEmpty($keys);
    }

    public function test_getOptionsKeys_contains_known_key(): void {
        $keys = $this->main->getOptions()->getOptionsKeys();
        $this->assertContains('wcTicketLabelPDFDownload', $keys);
    }

    // ── getOptionsOnlyPublic ─────────────────────────────────────

    public function test_getOptionsOnlyPublic_returns_array(): void {
        $public = $this->main->getOptions()->getOptionsOnlyPublic();
        $this->assertIsArray($public);
    }

    public function test_getOptionsOnlyPublic_entries_have_isPublic(): void {
        $public = $this->main->getOptions()->getOptionsOnlyPublic();
        foreach ($public as $opt) {
            $this->assertTrue($opt['isPublic']);
        }
    }

    // ── getOption ────────────────────────────────────────────────

    public function test_getOption_returns_array_for_known_key(): void {
        $option = $this->main->getOptions()->getOption('wcTicketLabelPDFDownload');
        $this->assertIsArray($option);
        $this->assertEquals('wcTicketLabelPDFDownload', $option['key']);
    }

    public function test_getOption_returns_null_for_unknown_key(): void {
        $option = $this->main->getOptions()->getOption('nonexistent_key_xyz_' . uniqid());
        $this->assertNull($option);
    }

    public function test_getOption_returns_null_for_empty_key(): void {
        $option = $this->main->getOptions()->getOption('');
        $this->assertNull($option);
    }

    // ── loadOptionFromWP ─────────────────────────────────────────

    public function test_loadOptionFromWP_returns_default(): void {
        $result = $this->main->getOptions()->loadOptionFromWP('nonexistent_opt_' . uniqid(), 'my_default');
        $this->assertEquals('my_default', $result);
    }

    // ── resetAllOptionValuesToDefault ─────────────────────────────

    public function test_resetAllOptionValuesToDefault_returns_true(): void {
        $result = $this->main->getOptions()->resetAllOptionValuesToDefault();
        $this->assertTrue($result);
    }

    // ── deleteAllOptionValues ────────────────────────────────────

    public function test_deleteAllOptionValues_returns_true(): void {
        $result = $this->main->getOptions()->deleteAllOptionValues();
        $this->assertTrue($result);
    }

    public function test_deleteAllOptionValues_options_reloadable(): void {
        $this->main->getOptions()->deleteAllOptionValues();
        // After deleting, re-init should still work
        $this->main->getOptions()->initOptions();
        $keys = $this->main->getOptions()->getOptionsKeys();
        $this->assertIsArray($keys);
        $this->assertNotEmpty($keys);
    }
}
