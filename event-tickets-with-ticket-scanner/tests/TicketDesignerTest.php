<?php
/**
 * Tests for TicketDesigner methods: getDefaultTemplate, getTemplate, getVariables.
 */

class TicketDesignerTest extends WP_UnitTestCase {

    private $main;

    public function set_up(): void {
        parent::set_up();
        $this->main = sasoEventtickets::Instance();
    }

    // ── getDefaultTemplate ───────────────────────────────────────

    public function test_getDefaultTemplate_returns_twig_template(): void {
        $designer = $this->main->getTicketDesignerHandler();
        $template = $designer->getDefaultTemplate();
        $this->assertIsString($template);
        $this->assertNotEmpty($template);
    }

    public function test_getDefaultTemplate_contains_twig_syntax(): void {
        $designer = $this->main->getTicketDesignerHandler();
        $template = $designer->getDefaultTemplate();
        $this->assertStringContainsString('{%', $template);
        $this->assertStringContainsString('PRODUCT', $template);
    }

    // ── getTemplate ──────────────────────────────────────────────

    public function test_getTemplate_returns_string(): void {
        $designer = $this->main->getTicketDesignerHandler();
        $template = $designer->getTemplate();
        $this->assertIsString($template);
    }

    public function test_getTemplate_not_empty(): void {
        $designer = $this->main->getTicketDesignerHandler();
        $template = $designer->getTemplate();
        $this->assertNotEmpty($template);
    }

    public function test_getTemplate_matches_default_when_no_custom(): void {
        $designer = $this->main->getTicketDesignerHandler();
        $template = $designer->getTemplate();
        $default = $designer->getDefaultTemplate();
        $this->assertEquals($default, $template);
    }
}
