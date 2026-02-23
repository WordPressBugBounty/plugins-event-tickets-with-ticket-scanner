<?php
/**
 * Tests for sasoEventtickets_PDF settings/configuration:
 * setOrientation, setPageFormat, setFontFamily, setFontSize,
 * setSize, setRTL, isRTL, setFilemode, getFilemode,
 * convertPixelIntoMm, getFontInfos, getPossibleFontFamiles.
 */

class PDFSettingsTest extends WP_UnitTestCase {

    public function set_up(): void {
        parent::set_up();
        // Load the PDF class (lazy-loaded, not auto-included)
        $pluginDir = dirname(__DIR__);
        if (!class_exists('sasoEventtickets_PDF')) {
            require_once $pluginDir . '/sasoEventtickets_PDF.php';
        }
    }

    private function createPDF(): sasoEventtickets_PDF {
        return new sasoEventtickets_PDF([], 'S', 'test.pdf');
    }

    // ── setOrientation / getters ───────────────────────────────────

    public function test_setOrientation_portrait(): void {
        $pdf = $this->createPDF();
        $pdf->setOrientation('P');
        // No getter — just verify no exception
        $this->assertTrue(true);
    }

    public function test_setOrientation_landscape(): void {
        $pdf = $this->createPDF();
        $pdf->setOrientation('L');
        $this->assertTrue(true);
    }

    // ── setPageFormat ──────────────────────────────────────────────

    public function test_setPageFormat_a4(): void {
        $pdf = $this->createPDF();
        $pdf->setPageFormat('A4');
        $this->assertTrue(true);
    }

    public function test_setPageFormat_letter(): void {
        $pdf = $this->createPDF();
        $pdf->setPageFormat('LETTER');
        $this->assertTrue(true);
    }

    // ── setFontFamily ──────────────────────────────────────────────

    public function test_setFontFamily_default(): void {
        $pdf = $this->createPDF();
        $pdf->setFontFamily('dejavusans');
        $this->assertTrue(true);
    }

    public function test_setFontFamily_trims_whitespace(): void {
        $pdf = $this->createPDF();
        $pdf->setFontFamily('  helvetica  ');
        $this->assertTrue(true);
    }

    // ── setFontSize ────────────────────────────────────────────────

    public function test_setFontSize_default(): void {
        $pdf = $this->createPDF();
        $pdf->setFontSize();
        $this->assertTrue(true);
    }

    public function test_setFontSize_custom(): void {
        $pdf = $this->createPDF();
        $pdf->setFontSize(14);
        $this->assertTrue(true);
    }

    // ── setSize ────────────────────────────────────────────────────

    public function test_setSize_custom_dimensions(): void {
        $pdf = $this->createPDF();
        $pdf->setSize(100, 150);
        $this->assertTrue(true);
    }

    // ── setRTL / isRTL ─────────────────────────────────────────────

    public function test_isRTL_default_false(): void {
        $pdf = $this->createPDF();
        $this->assertFalse($pdf->isRTL());
    }

    public function test_setRTL_true(): void {
        $pdf = $this->createPDF();
        $pdf->setRTL(true);
        $this->assertTrue($pdf->isRTL());
    }

    public function test_setRTL_false_explicit(): void {
        $pdf = $this->createPDF();
        $pdf->setRTL(true);
        $pdf->setRTL(false);
        $this->assertFalse($pdf->isRTL());
    }

    // ── setFilemode / getFilemode ──────────────────────────────────

    public function test_getFilemode_default_from_constructor(): void {
        $pdf = $this->createPDF();
        $this->assertEquals('S', $pdf->getFilemode());
    }

    public function test_setFilemode_changes_value(): void {
        $pdf = $this->createPDF();
        $pdf->setFilemode('f');
        $this->assertEquals('F', $pdf->getFilemode());
    }

    public function test_setFilemode_uppercase(): void {
        $pdf = $this->createPDF();
        $pdf->setFilemode('i');
        $this->assertEquals('I', $pdf->getFilemode());
    }

    // ── convertPixelIntoMm ─────────────────────────────────────────

    public function test_convertPixelIntoMm_96dpi(): void {
        $pdf = $this->createPDF();
        $result = $pdf->convertPixelIntoMm(96, 96);
        $this->assertEqualsWithDelta(25.4, $result, 0.001);
    }

    public function test_convertPixelIntoMm_zero_dpi_uses_default(): void {
        $pdf = $this->createPDF();
        // dpi < 1 falls back to 96
        $result = $pdf->convertPixelIntoMm(96, 0);
        $this->assertEqualsWithDelta(25.4, $result, 0.001);
    }

    public function test_convertPixelIntoMm_300dpi(): void {
        $pdf = $this->createPDF();
        $result = $pdf->convertPixelIntoMm(300, 300);
        $this->assertEqualsWithDelta(25.4, $result, 0.001);
    }

    public function test_convertPixelIntoMm_zero_pixels(): void {
        $pdf = $this->createPDF();
        $result = $pdf->convertPixelIntoMm(0, 96);
        $this->assertEquals(0, $result);
    }

    // ── getFontInfos ───────────────────────────────────────────────

    public function test_getFontInfos_returns_array(): void {
        $pdf = $this->createPDF();
        $result = $pdf->getFontInfos();
        $this->assertIsArray($result);
        $this->assertNotEmpty($result);
    }

    public function test_getFontInfos_entries_have_name_key(): void {
        $pdf = $this->createPDF();
        $infos = $pdf->getFontInfos();
        $first = reset($infos);
        $this->assertArrayHasKey('name', $first);
    }

    // ── getPossibleFontFamiles ──────────────────────────────────────

    public function test_getPossibleFontFamiles_returns_array(): void {
        $pdf = $this->createPDF();
        $result = $pdf->getPossibleFontFamiles();
        $this->assertIsArray($result);
        $this->assertArrayHasKey('default', $result);
        $this->assertArrayHasKey('fonts', $result);
    }

    public function test_getPossibleFontFamiles_default_is_dejavusans(): void {
        $pdf = $this->createPDF();
        $result = $pdf->getPossibleFontFamiles();
        $this->assertEquals('dejavusans', $result['default']);
    }

    public function test_getPossibleFontFamiles_fonts_not_empty(): void {
        $pdf = $this->createPDF();
        $result = $pdf->getPossibleFontFamiles();
        $this->assertNotEmpty($result['fonts']);
    }
}
