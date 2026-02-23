<?php
/**
 * Tests for QR Code setters and TicketDesigner: setTemplate,
 * getTemplateList, getReplacementTagsExplanation.
 */

class QRCodeAndDesignerTest extends WP_UnitTestCase {

    private $main;

    private $originalTemplate;

    public function set_up(): void {
        parent::set_up();
        $this->main = sasoEventtickets::Instance();

        // Save original designer template to restore in tear_down
        $ref = new ReflectionProperty($this->main->getTicketDesignerHandler(), 'html');
        $ref->setAccessible(true);
        $this->originalTemplate = $ref->getValue($this->main->getTicketDesignerHandler());
    }

    public function tear_down(): void {
        // Restore designer template to avoid polluting other tests
        $this->main->getTicketDesignerHandler()->setTemplate($this->originalTemplate ?? '');
        parent::tear_down();
    }

    // ── QR Code setters ──────────────────────────────────────────

    public function test_qr_setWidth_stores_value(): void {
        $qr = $this->main->getTicketQRHandler();
        $qr->setWidth(200);

        $ref = new ReflectionProperty($qr, 'size_width');
        $ref->setAccessible(true);
        $this->assertEquals(200, $ref->getValue($qr));
    }

    public function test_qr_setHeight_stores_value(): void {
        $qr = $this->main->getTicketQRHandler();
        $qr->setHeight(150);

        $ref = new ReflectionProperty($qr, 'size_height');
        $ref->setAccessible(true);
        $this->assertEquals(150, $ref->getValue($qr));
    }

    public function test_qr_setFilepath_stores_value(): void {
        $qr = $this->main->getTicketQRHandler();
        $qr->setFilepath('/tmp/qr/');

        $ref = new ReflectionProperty($qr, 'filepath');
        $ref->setAccessible(true);
        $this->assertEquals('/tmp/qr/', $ref->getValue($qr));
    }

    public function test_qr_setWidth_casts_to_int(): void {
        $qr = $this->main->getTicketQRHandler();
        $qr->setWidth('300');

        $ref = new ReflectionProperty($qr, 'size_width');
        $ref->setAccessible(true);
        $this->assertIsInt($ref->getValue($qr));
        $this->assertEquals(300, $ref->getValue($qr));
    }

    // ── TicketDesigner: setTemplate ──────────────────────────────

    public function test_designer_setTemplate_stores_html(): void {
        $designer = $this->main->getTicketDesignerHandler();
        $result = $designer->setTemplate('<div>Test Template</div>');

        // setTemplate returns $this for chaining
        $this->assertSame($designer, $result);

        $ref = new ReflectionProperty($designer, 'html');
        $ref->setAccessible(true);
        $this->assertEquals('<div>Test Template</div>', $ref->getValue($designer));
    }

    public function test_designer_setTemplate_trims(): void {
        $designer = $this->main->getTicketDesignerHandler();
        $designer->setTemplate('  <p>trimmed</p>  ');

        $ref = new ReflectionProperty($designer, 'html');
        $ref->setAccessible(true);
        $this->assertEquals('<p>trimmed</p>', $ref->getValue($designer));
    }

    // ── TicketDesigner: getTemplateList ──────────────────────────

    public function test_designer_getTemplateList_returns_array(): void {
        $designer = $this->main->getTicketDesignerHandler();
        $result = $designer->getTemplateList();
        $this->assertIsArray($result);
    }

    public function test_designer_getTemplateList_entries_have_template_key(): void {
        $designer = $this->main->getTicketDesignerHandler();
        $result = $designer->getTemplateList();

        if (!empty($result)) {
            $first = $result[0];
            $this->assertArrayHasKey('template', $first);
            $this->assertStringStartsWith('getTicketTemplate_', $first['template']);
        } else {
            $this->assertIsArray($result);
        }
    }

    // ── TicketBadge: getReplacementTagsExplanation ──────────────

    public function test_badge_getReplacementTagsExplanation_returns_string(): void {
        $badge = $this->main->getTicketBadgeHandler();
        $result = $badge->getReplacementTagsExplanation();
        $this->assertIsString($result);
        $this->assertNotEmpty($result);
    }

    public function test_badge_getReplacementTagsExplanation_contains_ticket_tags(): void {
        $badge = $this->main->getTicketBadgeHandler();
        $result = $badge->getReplacementTagsExplanation();
        $this->assertStringContainsString('TICKET', $result);
        $this->assertStringContainsString('QRCODE', $result);
    }
}
