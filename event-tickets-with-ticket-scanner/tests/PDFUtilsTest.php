<?php
/**
 * Tests for PDF utility methods: convertPixelIntoMm, getPossibleFontFamiles, getFontInfos.
 */

class PDFUtilsTest extends WP_UnitTestCase {

    private $main;
    private $pdf;

    public function set_up(): void {
        parent::set_up();
        $this->main = sasoEventtickets::Instance();
        $this->pdf = $this->main->getNewPDFObject();
    }

    // ── convertPixelIntoMm ───────────────────────────────────────

    public function test_convertPixelIntoMm_96dpi(): void {
        // 96 pixels at 96 DPI = 1 inch = 25.4mm
        $result = $this->pdf->convertPixelIntoMm(96, 96);
        $this->assertEqualsWithDelta(25.4, $result, 0.01);
    }

    public function test_convertPixelIntoMm_zero_pixels(): void {
        $result = $this->pdf->convertPixelIntoMm(0, 96);
        $this->assertEquals(0, $result);
    }

    public function test_convertPixelIntoMm_default_dpi(): void {
        // Default DPI is 96
        $result = $this->pdf->convertPixelIntoMm(96);
        $this->assertEqualsWithDelta(25.4, $result, 0.01);
    }

    public function test_convertPixelIntoMm_300dpi(): void {
        // 300 pixels at 300 DPI = 1 inch = 25.4mm
        $result = $this->pdf->convertPixelIntoMm(300, 300);
        $this->assertEqualsWithDelta(25.4, $result, 0.01);
    }

    // ── getPossibleFontFamiles ───────────────────────────────────

    public function test_getPossibleFontFamiles_returns_array(): void {
        $result = $this->pdf->getPossibleFontFamiles();
        $this->assertIsArray($result);
        $this->assertArrayHasKey('default', $result);
        $this->assertArrayHasKey('fonts', $result);
    }

    public function test_getPossibleFontFamiles_has_dejavusans_default(): void {
        $result = $this->pdf->getPossibleFontFamiles();
        $this->assertEquals('dejavusans', $result['default']);
    }

    public function test_getPossibleFontFamiles_fonts_not_empty(): void {
        $result = $this->pdf->getPossibleFontFamiles();
        $this->assertNotEmpty($result['fonts']);
    }

    // ── getFontInfos ─────────────────────────────────────────────

    public function test_getFontInfos_returns_array(): void {
        $result = $this->pdf->getFontInfos();
        $this->assertIsArray($result);
        $this->assertNotEmpty($result);
    }

    public function test_getFontInfos_entries_have_name(): void {
        $result = $this->pdf->getFontInfos();
        foreach ($result as $font) {
            $this->assertArrayHasKey('name', $font);
            $this->assertNotEmpty($font['name']);
            break; // Just check first entry
        }
    }
}
