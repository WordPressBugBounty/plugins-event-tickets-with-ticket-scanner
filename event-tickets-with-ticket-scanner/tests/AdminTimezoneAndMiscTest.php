<?php
/**
 * Tests for AdminSettings methods: wpdocs_custom_timezone_string.
 * And miscellaneous static helpers from SASO_EVENTTICKETS.
 */

class AdminTimezoneAndMiscTest extends WP_UnitTestCase {

    private $main;

    public function set_up(): void {
        parent::set_up();
        $this->main = sasoEventtickets::Instance();
    }

    // ── wpdocs_custom_timezone_string ────────────────────────────

    public function test_wpdocs_custom_timezone_string_returns_string(): void {
        $result = $this->main->getAdmin()->wpdocs_custom_timezone_string();
        $this->assertIsString($result);
        $this->assertNotEmpty($result);
    }

    public function test_wpdocs_custom_timezone_string_contains_offset(): void {
        $result = $this->main->getAdmin()->wpdocs_custom_timezone_string();
        // Should contain offset format like +00:00 or -05:00
        $this->assertMatchesRegularExpression('/[+-]\d{2}:\d{2}/', $result);
    }

    // ── SASO_EVENTTICKETS static methods ─────────────────────────

    public function test_getRequest_returns_array_or_null(): void {
        $result = SASO_EVENTTICKETS::getRequest();
        $this->assertTrue(is_array($result) || is_null($result));
    }

    public function test_getRequestPara_returns_value(): void {
        $_POST['test_param_xyz'] = 'test_value';
        $_SERVER['REQUEST_METHOD'] = 'POST';

        // Reset the static cache by accessing fresh
        $ref = new ReflectionProperty('SASO_EVENTTICKETS', 'REQUEST_DATA');
        $ref->setAccessible(true);
        $ref->setValue(null, null);

        $result = SASO_EVENTTICKETS::getRequestPara('test_param_xyz');
        $this->assertEquals('test_value', $result);

        unset($_POST['test_param_xyz']);
        $ref->setValue(null, null);
    }

    public function test_getRequestPara_returns_default_for_missing(): void {
        $ref = new ReflectionProperty('SASO_EVENTTICKETS', 'REQUEST_DATA');
        $ref->setAccessible(true);
        $ref->setValue(null, null);

        $result = SASO_EVENTTICKETS::getRequestPara('nonexistent_xyz_' . uniqid(), 'default_val');
        $this->assertEquals('default_val', $result);

        $ref->setValue(null, null);
    }

    // ── PasswortGenerieren ───────────────────────────────────────

    public function test_PasswortGenerieren_returns_expected_length(): void {
        $result = SASO_EVENTTICKETS::PasswortGenerieren(16);
        $this->assertEquals(16, strlen($result));
    }

    public function test_PasswortGenerieren_unique_results(): void {
        $a = SASO_EVENTTICKETS::PasswortGenerieren(32);
        $b = SASO_EVENTTICKETS::PasswortGenerieren(32);
        $this->assertNotEquals($a, $b);
    }

    // ── sanitize_date_from_datepicker ────────────────────────────

    public function test_sanitize_date_valid(): void {
        $result = SASO_EVENTTICKETS::sanitize_date_from_datepicker('2026-06-15');
        $this->assertEquals('2026-06-15', $result);
    }

    public function test_sanitize_date_invalid(): void {
        $result = SASO_EVENTTICKETS::sanitize_date_from_datepicker('not-a-date');
        $this->assertEquals('', $result);
    }

    public function test_sanitize_date_empty(): void {
        $result = SASO_EVENTTICKETS::sanitize_date_from_datepicker('');
        $this->assertEquals('', $result);
    }

    // ── is_assoc_array ───────────────────────────────────────────

    public function test_is_assoc_array_true_for_assoc(): void {
        $result = SASO_EVENTTICKETS::is_assoc_array(['key' => 'value', 'another' => 123]);
        $this->assertTrue($result);
    }

    public function test_is_assoc_array_false_for_sequential(): void {
        $result = SASO_EVENTTICKETS::is_assoc_array(['a', 'b', 'c']);
        $this->assertFalse($result);
    }

    public function test_is_assoc_array_true_for_empty(): void {
        // Plugin treats empty array as associative
        $result = SASO_EVENTTICKETS::is_assoc_array([]);
        $this->assertTrue($result);
    }

    // ── getRESTPrefixURL ─────────────────────────────────────────

    public function test_getRESTPrefixURL_returns_string(): void {
        $result = SASO_EVENTTICKETS::getRESTPrefixURL();
        $this->assertIsString($result);
        $this->assertNotEmpty($result);
    }

    public function test_getRESTPrefixURL_contains_plugin_slug(): void {
        $result = SASO_EVENTTICKETS::getRESTPrefixURL();
        $this->assertStringContainsString('event-tickets', $result);
    }
}
