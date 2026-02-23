<?php
/**
 * Tests for SASO_EVENTTICKETS static methods: time(), date().
 * And deprecated backward-compatibility helpers.
 */

class StaticDateAndTimeTest extends WP_UnitTestCase {

    // ── SASO_EVENTTICKETS::time() ─────────────────────────────────

    public function test_time_returns_int(): void {
        $result = SASO_EVENTTICKETS::time();
        $this->assertIsInt($result);
    }

    public function test_time_returns_reasonable_value(): void {
        $result = SASO_EVENTTICKETS::time();
        // Should be after 2020
        $this->assertGreaterThan(strtotime('2020-01-01'), $result);
    }

    public function test_time_close_to_current_time(): void {
        $result = SASO_EVENTTICKETS::time();
        $diff = abs(time() - $result);
        // Should be within 24h of actual time (accounting for timezone)
        $this->assertLessThan(86400, $diff);
    }

    // ── SASO_EVENTTICKETS::date() ─────────────────────────────────

    public function test_date_returns_string(): void {
        $result = SASO_EVENTTICKETS::date('Y-m-d');
        $this->assertIsString($result);
    }

    public function test_date_formats_year(): void {
        $result = SASO_EVENTTICKETS::date('Y');
        $this->assertMatchesRegularExpression('/^\d{4}$/', $result);
    }

    public function test_date_with_timestamp(): void {
        // 2026-01-15 00:00:00 UTC
        $ts = mktime(0, 0, 0, 1, 15, 2026);
        $result = SASO_EVENTTICKETS::date('Y-m-d', $ts, new DateTimeZone('UTC'));
        $this->assertEquals('2026-01-15', $result);
    }

    public function test_date_with_timezone(): void {
        $tz = new DateTimeZone('Europe/Berlin');
        $result = SASO_EVENTTICKETS::date('e', 0, $tz);
        $this->assertEquals('Europe/Berlin', $result);
    }

    public function test_date_full_format(): void {
        $result = SASO_EVENTTICKETS::date('Y-m-d H:i:s');
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $result);
    }

    public function test_date_day_of_week(): void {
        // Monday 2026-01-19 UTC
        $ts = mktime(12, 0, 0, 1, 19, 2026);
        $result = SASO_EVENTTICKETS::date('l', $ts, new DateTimeZone('UTC'));
        $this->assertEquals('Monday', $result);
    }

    // ── SASO_EVENTTICKETS::getDB() ────────────────────────────────

    public function test_getDB_returns_object(): void {
        $main = sasoEventtickets::Instance();
        $db = $main->getDB();
        $this->assertIsObject($db);
    }

    // ── issetRPara additional tests ──────────────────────────────

    public function test_issetRPara_true_for_post_param(): void {
        $_POST['test_isset_post_' . uniqid()] = 'value';
        $_SERVER['REQUEST_METHOD'] = 'POST';

        $ref = new ReflectionProperty('SASO_EVENTTICKETS', 'REQUEST_DATA');
        $ref->setAccessible(true);
        $ref->setValue(null, null);

        $key = array_key_last($_POST);
        $result = SASO_EVENTTICKETS::issetRPara($key);
        $this->assertTrue($result);

        unset($_POST[$key]);
        $ref->setValue(null, null);
    }
}
