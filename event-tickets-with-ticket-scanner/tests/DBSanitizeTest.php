<?php
/**
 * Tests for DB methods: reinigen_in (input sanitization).
 */

class DBSanitizeTest extends WP_UnitTestCase {

    private $main;

    public function set_up(): void {
        parent::set_up();
        $this->main = sasoEventtickets::Instance();
    }

    // ── reinigen_in ──────────────────────────────────────────────

    public function test_reinigen_in_trims_whitespace(): void {
        $result = $this->main->getDB()->reinigen_in('  hello  ');
        $this->assertEquals('hello', stripslashes($result));
    }

    public function test_reinigen_in_truncates_to_length(): void {
        $result = $this->main->getDB()->reinigen_in('abcdefghij', 5);
        $this->assertEquals(5, strlen(stripslashes($result)));
    }

    public function test_reinigen_in_no_truncation_without_length(): void {
        $text = 'This is a longer text for testing';
        $result = $this->main->getDB()->reinigen_in($text);
        $this->assertEquals($text, stripslashes($result));
    }

    public function test_reinigen_in_addslashes_by_default(): void {
        $result = $this->main->getDB()->reinigen_in("O'Brien");
        $this->assertStringContainsString("O\\'Brien", $result);
    }

    public function test_reinigen_in_no_addslashes_when_disabled(): void {
        $result = $this->main->getDB()->reinigen_in("O'Brien", 0, 0);
        $this->assertEquals("O'Brien", $result);
    }

    public function test_reinigen_in_html_entities(): void {
        $result = $this->main->getDB()->reinigen_in('<script>alert("xss")</script>', 0, 0, 0, 1);
        $this->assertStringNotContainsString('<script>', $result);
        $this->assertStringContainsString('&lt;script&gt;', $result);
    }

    public function test_reinigen_in_empty_string(): void {
        $result = $this->main->getDB()->reinigen_in('');
        $this->assertEquals('', $result);
    }

    public function test_reinigen_in_special_characters(): void {
        $result = $this->main->getDB()->reinigen_in('Tëst Üser', 0, 0);
        $this->assertEquals('Tëst Üser', $result);
    }
}
