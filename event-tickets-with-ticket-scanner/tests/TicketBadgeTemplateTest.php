<?php
/**
 * Tests for sasoEventtickets_TicketBadge template methods:
 * getTemplate, getDefaultTemplate, getReplacementTagsExplanation.
 */

class TicketBadgeTemplateTest extends WP_UnitTestCase {

    private $main;

    public function set_up(): void {
        parent::set_up();
        $this->main = sasoEventtickets::Instance();
        // Ensure TicketBadge class is loaded (lazy-loaded)
        $this->main->getTicketBadgeHandler();
    }

    // ── getDefaultTemplate ─────────────────────────────────────────

    public function test_getDefaultTemplate_returns_string(): void {
        $badge = sasoEventtickets_TicketBadge::Instance();
        $result = $badge->getDefaultTemplate();
        $this->assertIsString($result);
        $this->assertNotEmpty($result);
    }

    public function test_getDefaultTemplate_contains_qrcode_tag(): void {
        $badge = sasoEventtickets_TicketBadge::Instance();
        $result = $badge->getDefaultTemplate();
        $this->assertStringContainsString('{QRCODE_INLINE}', $result);
    }

    public function test_getDefaultTemplate_contains_product_name(): void {
        $badge = sasoEventtickets_TicketBadge::Instance();
        $result = $badge->getDefaultTemplate();
        $this->assertStringContainsString('{PRODUCT.name}', $result);
    }

    public function test_getDefaultTemplate_contains_ticket_code(): void {
        $badge = sasoEventtickets_TicketBadge::Instance();
        $result = $badge->getDefaultTemplate();
        $this->assertStringContainsString('{TICKET.code_display}', $result);
    }

    // ── getTemplate ────────────────────────────────────────────────

    public function test_getTemplate_returns_default_when_no_html_set(): void {
        $badge = sasoEventtickets_TicketBadge::Instance();
        $template = $badge->getTemplate();
        $defaultTemplate = $badge->getDefaultTemplate();
        $this->assertEquals(trim($defaultTemplate), trim($template));
    }

    // ── getReplacementTagsExplanation ──────────────────────────────

    public function test_getReplacementTagsExplanation_returns_string(): void {
        $badge = sasoEventtickets_TicketBadge::Instance();
        $result = $badge->getReplacementTagsExplanation();
        $this->assertIsString($result);
        $this->assertNotEmpty($result);
    }

    public function test_getReplacementTagsExplanation_contains_ticket_tags(): void {
        $badge = sasoEventtickets_TicketBadge::Instance();
        $result = $badge->getReplacementTagsExplanation();
        $this->assertStringContainsString('TICKET.id', $result);
        $this->assertStringContainsString('TICKET.code', $result);
    }

    public function test_getReplacementTagsExplanation_contains_qrcode_tag(): void {
        $badge = sasoEventtickets_TicketBadge::Instance();
        $result = $badge->getReplacementTagsExplanation();
        $this->assertStringContainsString('QRCODE_INLINE', $result);
    }

    public function test_getReplacementTagsExplanation_contains_html(): void {
        $badge = sasoEventtickets_TicketBadge::Instance();
        $result = $badge->getReplacementTagsExplanation();
        // Should contain HTML markup like <li>, <ul>, <b>
        $this->assertStringContainsString('<li>', $result);
    }
}
