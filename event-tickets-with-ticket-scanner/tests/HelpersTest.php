<?php
/**
 * Tests for SASO_EVENTTICKETS static helper methods.
 */

class HelpersTest extends WP_UnitTestCase {

    // ── PasswortGenerieren ─────────────────────────────────────

    public function test_password_default_length_is_8(): void {
        $pw = SASO_EVENTTICKETS::PasswortGenerieren();
        $this->assertSame(8, strlen($pw));
    }

    public function test_password_custom_length(): void {
        $pw = SASO_EVENTTICKETS::PasswortGenerieren(16);
        $this->assertSame(16, strlen($pw));
    }

    public function test_password_length_1(): void {
        $pw = SASO_EVENTTICKETS::PasswortGenerieren(1);
        $this->assertSame(1, strlen($pw));
    }

    public function test_password_contains_only_allowed_chars(): void {
        $allowed = '23456789abcdefghjkmnpqrstwxyz';
        for ($i = 0; $i < 20; $i++) {
            $pw = SASO_EVENTTICKETS::PasswortGenerieren(32);
            for ($c = 0; $c < strlen($pw); $c++) {
                $this->assertStringContainsString(
                    $pw[$c], $allowed,
                    "Character '{$pw[$c]}' not in allowed set"
                );
            }
        }
    }

    public function test_password_randomness_not_identical(): void {
        $passwords = [];
        for ($i = 0; $i < 10; $i++) {
            $passwords[] = SASO_EVENTTICKETS::PasswortGenerieren(16);
        }
        $unique = array_unique($passwords);
        $this->assertGreaterThan(1, count($unique), 'Passwords should not all be identical');
    }

    // ── sanitize_date_from_datepicker ──────────────────────────

    public function test_sanitize_valid_date(): void {
        $this->assertSame('2026-02-20', SASO_EVENTTICKETS::sanitize_date_from_datepicker('2026-02-20'));
    }

    public function test_sanitize_date_with_time_truncated(): void {
        $this->assertSame('2026-02-20', SASO_EVENTTICKETS::sanitize_date_from_datepicker('2026-02-20 14:30:00'));
    }

    public function test_sanitize_invalid_date_returns_empty(): void {
        $this->assertSame('', SASO_EVENTTICKETS::sanitize_date_from_datepicker('not-a-date'));
    }

    public function test_sanitize_empty_string_returns_empty(): void {
        $this->assertSame('', SASO_EVENTTICKETS::sanitize_date_from_datepicker(''));
    }

    public function test_sanitize_wrong_format_returns_empty(): void {
        $this->assertSame('', SASO_EVENTTICKETS::sanitize_date_from_datepicker('20/02/2026'));
    }

    public function test_sanitize_partial_date_returns_empty(): void {
        $this->assertSame('', SASO_EVENTTICKETS::sanitize_date_from_datepicker('2026-02'));
    }

    // ── is_assoc_array ─────────────────────────────────────────

    public function test_is_assoc_array_with_string_keys(): void {
        $this->assertTrue(SASO_EVENTTICKETS::is_assoc_array(['a' => 1, 'b' => 2]));
    }

    public function test_is_assoc_array_with_numeric_keys(): void {
        $this->assertFalse(SASO_EVENTTICKETS::is_assoc_array([1, 2, 3]));
    }

    public function test_is_assoc_array_empty_returns_true(): void {
        // empty arrays are considered associative by the implementation
        $this->assertTrue(SASO_EVENTTICKETS::is_assoc_array([]));
    }

    public function test_is_assoc_array_not_array_returns_false(): void {
        $this->assertFalse(SASO_EVENTTICKETS::is_assoc_array('string'));
    }

    public function test_is_assoc_array_mixed_keys(): void {
        $this->assertTrue(SASO_EVENTTICKETS::is_assoc_array([0 => 'a', 'key' => 'b']));
    }

    // ── getRESTPrefixURL ───────────────────────────────────────

    public function test_getRESTPrefixURL_returns_plugin_dirname(): void {
        $result = SASO_EVENTTICKETS::getRESTPrefixURL();
        $this->assertSame('event-tickets-with-ticket-scanner', $result);
    }

    // ── getRequestPara ─────────────────────────────────────────

    public function test_getRequestPara_returns_default_when_not_set(): void {
        $result = SASO_EVENTTICKETS::getRequestPara('nonexistent_param_xyz', 'fallback');
        $this->assertSame('fallback', $result);
    }

    public function test_getRequestPara_returns_null_default(): void {
        $result = SASO_EVENTTICKETS::getRequestPara('nonexistent_param_xyz');
        $this->assertNull($result);
    }
}
