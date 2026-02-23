<?php
/**
 * Tests for sasoEventtickets_TicketDesigner template methods:
 * getTemplate, getDefaultTemplate, getTemplateList, setTemplate, getVariables.
 */

class TicketDesignerTemplateTest extends WP_UnitTestCase {

    private $main;

    public function set_up(): void {
        parent::set_up();
        $this->main = sasoEventtickets::Instance();
    }

    private function getDesigner(): sasoEventtickets_TicketDesigner {
        $pluginDir = dirname(__DIR__);
        if (!class_exists('sasoEventtickets_TicketDesigner')) {
            require_once $pluginDir . '/sasoEventtickets_TicketDesigner.php';
        }
        return new sasoEventtickets_TicketDesigner($this->main);
    }

    // ── getDefaultTemplate ─────────────────────────────────────────

    public function test_getDefaultTemplate_returns_string(): void {
        $designer = $this->getDesigner();
        $result = $designer->getDefaultTemplate();
        $this->assertIsString($result);
        $this->assertNotEmpty($result);
    }

    // ── getTemplate ────────────────────────────────────────────────

    public function test_getTemplate_returns_default_when_empty(): void {
        $designer = $this->getDesigner();
        $template = $designer->getTemplate();
        $default = $designer->getDefaultTemplate();
        $this->assertEquals($default, $template);
    }

    public function test_getTemplate_returns_custom_after_set(): void {
        $designer = $this->getDesigner();
        $designer->setTemplate('<h1>Custom</h1>');
        $this->assertEquals('<h1>Custom</h1>', $designer->getTemplate());
    }

    // ── setTemplate ────────────────────────────────────────────────

    public function test_setTemplate_trims_whitespace(): void {
        $designer = $this->getDesigner();
        $designer->setTemplate('  <p>Test</p>  ');
        $this->assertEquals('<p>Test</p>', $designer->getTemplate());
    }

    public function test_setTemplate_returns_self(): void {
        $designer = $this->getDesigner();
        $result = $designer->setTemplate('<p>Test</p>');
        $this->assertSame($designer, $result);
    }

    // ── getTemplateList ────────────────────────────────────────────

    public function test_getTemplateList_returns_array(): void {
        $designer = $this->getDesigner();
        $result = $designer->getTemplateList();
        $this->assertIsArray($result);
        $this->assertNotEmpty($result);
    }

    public function test_getTemplateList_entries_have_template_key(): void {
        $designer = $this->getDesigner();
        $list = $designer->getTemplateList();
        foreach ($list as $entry) {
            $this->assertArrayHasKey('template', $entry);
        }
    }

    // ── getVariables ───────────────────────────────────────────────

    public function test_getVariables_returns_null_initially(): void {
        $designer = $this->getDesigner();
        $result = $designer->getVariables();
        // Variables are null until renderHTML populates them
        $this->assertNull($result);
    }
}
