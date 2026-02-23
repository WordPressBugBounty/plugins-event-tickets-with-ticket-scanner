<?php
/**
 * Tests for SASO_EVENTTICKETS static utility methods:
 * PasswortGenerieren, time, date, is_assoc_array,
 * sanitize_date_from_datepicker, getRequestPara.
 */

class StaticUtilMethodsTest extends WP_UnitTestCase {

    // ── PasswortGenerieren ─────────────────────────────────────────

    public function test_PasswortGenerieren_default_length(): void {
        $pw = SASO_EVENTTICKETS::PasswortGenerieren();
        $this->assertEquals(8, strlen($pw));
    }

    public function test_PasswortGenerieren_custom_length(): void {
        $pw = SASO_EVENTTICKETS::PasswortGenerieren(16);
        $this->assertEquals(16, strlen($pw));
    }

    public function test_PasswortGenerieren_unique(): void {
        $pw1 = SASO_EVENTTICKETS::PasswortGenerieren(20);
        $pw2 = SASO_EVENTTICKETS::PasswortGenerieren(20);
        $this->assertNotEquals($pw1, $pw2);
    }

    public function test_PasswortGenerieren_alphanumeric(): void {
        $pw = SASO_EVENTTICKETS::PasswortGenerieren(100);
        // Should only contain chars from the allowed set (digits 2-9, lowercase letters excluding confusable ones)
        $this->assertMatchesRegularExpression('/^[2-9a-hjkmnp-twxyz]+$/', $pw);
    }

    // ── time ───────────────────────────────────────────────────────

    public function test_time_returns_integer(): void {
        $result = SASO_EVENTTICKETS::time();
        $this->assertIsInt($result);
    }

    public function test_time_returns_reasonable_timestamp(): void {
        $result = SASO_EVENTTICKETS::time();
        // Should be after 2020 and before 2030
        $this->assertGreaterThan(strtotime('2020-01-01'), $result);
        $this->assertLessThan(strtotime('2030-01-01'), $result);
    }

    // ── date ───────────────────────────────────────────────────────

    public function test_date_returns_string(): void {
        $result = SASO_EVENTTICKETS::date('Y-m-d');
        $this->assertIsString($result);
    }

    public function test_date_format_Y_m_d(): void {
        $result = SASO_EVENTTICKETS::date('Y-m-d');
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $result);
    }

    public function test_date_with_timestamp(): void {
        $timestamp = mktime(12, 0, 0, 6, 15, 2025);
        $result = SASO_EVENTTICKETS::date('Y-m-d', $timestamp);
        $this->assertEquals('2025-06-15', $result);
    }

    // ── is_assoc_array ─────────────────────────────────────────────

    public function test_is_assoc_array_true_for_assoc(): void {
        $this->assertTrue(SASO_EVENTTICKETS::is_assoc_array(['key' => 'value']));
    }

    public function test_is_assoc_array_false_for_sequential(): void {
        $this->assertFalse(SASO_EVENTTICKETS::is_assoc_array(['a', 'b', 'c']));
    }

    public function test_is_assoc_array_false_for_non_array(): void {
        $this->assertFalse(SASO_EVENTTICKETS::is_assoc_array('not an array'));
    }

    public function test_is_assoc_array_true_for_empty(): void {
        // Implementation treats empty array as associative
        $this->assertTrue(SASO_EVENTTICKETS::is_assoc_array([]));
    }

    // ── sanitize_date_from_datepicker ──────────────────────────────

    public function test_sanitize_date_valid(): void {
        $result = SASO_EVENTTICKETS::sanitize_date_from_datepicker('2026-12-25');
        $this->assertEquals('2026-12-25', $result);
    }

    public function test_sanitize_date_invalid_format(): void {
        $result = SASO_EVENTTICKETS::sanitize_date_from_datepicker('25/12/2026');
        $this->assertEquals('', $result);
    }

    public function test_sanitize_date_empty(): void {
        $result = SASO_EVENTTICKETS::sanitize_date_from_datepicker('');
        $this->assertEquals('', $result);
    }

    public function test_sanitize_date_truncates_extra(): void {
        $result = SASO_EVENTTICKETS::sanitize_date_from_datepicker('2026-12-25T10:00:00');
        $this->assertEquals('2026-12-25', $result);
    }

    public function test_sanitize_date_script_injection(): void {
        $result = SASO_EVENTTICKETS::sanitize_date_from_datepicker('<script>alert(1)</script>');
        $this->assertEquals('', $result);
    }

    // ── getRequestPara ─────────────────────────────────────────────

    public function test_getRequestPara_returns_default_for_missing(): void {
        $ref = new ReflectionProperty('SASO_EVENTTICKETS', 'REQUEST_DATA');
        $ref->setAccessible(true);
        $ref->setValue(null, null);

        $result = SASO_EVENTTICKETS::getRequestPara('nonexistent_key_' . uniqid(), 'default_val');
        $this->assertEquals('default_val', $result);

        $ref->setValue(null, null);
    }
}
