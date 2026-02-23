<?php
/**
 * Tests for PDF methods: setters, getters, parts management.
 */

class PDFSettersTest extends WP_UnitTestCase {

    private $main;

    public function set_up(): void {
        parent::set_up();
        $this->main = sasoEventtickets::Instance();
    }

    // ── setFilemode / getFilemode ─────────────────────────────────

    public function test_setFilemode_and_getFilemode(): void {
        $pdf = $this->main->getNewPDFObject();
        $pdf->setFilemode('d');
        $this->assertEquals('D', $pdf->getFilemode());
    }

    public function test_setFilemode_uppercase(): void {
        $pdf = $this->main->getNewPDFObject();
        $pdf->setFilemode('i');
        $this->assertEquals('I', $pdf->getFilemode());
    }

    // ── setFilename / setFilepath / getFullFilePath ───────────────

    public function test_setFilename_and_getFullFilePath(): void {
        $pdf = $this->main->getNewPDFObject();
        $pdf->setFilepath('/tmp/');
        $pdf->setFilename('test.pdf');
        $this->assertEquals('/tmp/test.pdf', $pdf->getFullFilePath());
    }

    public function test_setFilepath_trims(): void {
        $pdf = $this->main->getNewPDFObject();
        $pdf->setFilepath('  /tmp/  ');
        $pdf->setFilename('file.pdf');
        $this->assertEquals('/tmp/file.pdf', $pdf->getFullFilePath());
    }

    // ── setSize ──────────────────────────────────────────────────

    public function test_setSize_stores_dimensions(): void {
        $pdf = $this->main->getNewPDFObject();
        $pdf->setSize(100, 200);

        $ref = new ReflectionProperty($pdf, 'size_width');
        $ref->setAccessible(true);
        $this->assertEquals(100, $ref->getValue($pdf));

        $refH = new ReflectionProperty($pdf, 'size_height');
        $refH->setAccessible(true);
        $this->assertEquals(200, $refH->getValue($pdf));
    }

    // ── setOrientation ───────────────────────────────────────────

    public function test_setOrientation_stores_value(): void {
        $pdf = $this->main->getNewPDFObject();
        $pdf->setOrientation('L');

        $ref = new ReflectionProperty($pdf, 'orientation');
        $ref->setAccessible(true);
        $this->assertEquals('L', $ref->getValue($pdf));
    }

    // ── setPageFormat ────────────────────────────────────────────

    public function test_setPageFormat_stores_value(): void {
        $pdf = $this->main->getNewPDFObject();
        $pdf->setPageFormat('A5');

        $ref = new ReflectionProperty($pdf, 'page_format');
        $ref->setAccessible(true);
        $this->assertEquals('A5', $ref->getValue($pdf));
    }

    // ── setRTL / isRTL ───────────────────────────────────────────

    public function test_setRTL_and_isRTL_default(): void {
        $pdf = $this->main->getNewPDFObject();
        $this->assertFalse($pdf->isRTL());
    }

    public function test_setRTL_and_isRTL_true(): void {
        $pdf = $this->main->getNewPDFObject();
        $pdf->setRTL(true);
        $this->assertTrue($pdf->isRTL());
    }

    // ── setParts / addPart ───────────────────────────────────────

    public function test_setParts_stores_parts(): void {
        $pdf = $this->main->getNewPDFObject();
        $pdf->setParts(['<h1>Page 1</h1>', '<h1>Page 2</h1>']);

        $ref = new ReflectionProperty($pdf, 'parts');
        $ref->setAccessible(true);
        $parts = $ref->getValue($pdf);

        $this->assertCount(2, $parts);
    }

    public function test_addPart_splits_by_pagebreak(): void {
        $pdf = $this->main->getNewPDFObject();
        $pdf->setParts([]);
        $pdf->addPart('Page1{PAGEBREAK}Page2{PAGEBREAK}Page3');

        $ref = new ReflectionProperty($pdf, 'parts');
        $ref->setAccessible(true);
        $parts = $ref->getValue($pdf);

        $this->assertCount(3, $parts);
        $this->assertEquals('Page1', $parts[0]);
        $this->assertEquals('Page2', $parts[1]);
        $this->assertEquals('Page3', $parts[2]);
    }

    public function test_addPart_single_part(): void {
        $pdf = $this->main->getNewPDFObject();
        $pdf->setParts([]);
        $pdf->addPart('<div>Content</div>');

        $ref = new ReflectionProperty($pdf, 'parts');
        $ref->setAccessible(true);
        $parts = $ref->getValue($pdf);

        $this->assertCount(1, $parts);
        $this->assertEquals('<div>Content</div>', $parts[0]);
    }
}
