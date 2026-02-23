<?php
/**
 * Tests for sasoEventtickets_TicketQR setters:
 * setWidth, setHeight, setFilepath.
 */

class TicketQRSettersTest extends WP_UnitTestCase {

    private $main;

    public function set_up(): void {
        parent::set_up();
        $this->main = sasoEventtickets::Instance();
    }

    private function getQR(): sasoEventtickets_TicketQR {
        $pluginDir = dirname(__DIR__);
        if (!class_exists('sasoEventtickets_TicketQR')) {
            require_once $pluginDir . '/sasoEventtickets_TicketQR.php';
        }
        return new sasoEventtickets_TicketQR();
    }

    // ── setWidth ───────────────────────────────────────────────────

    public function test_setWidth_does_not_throw(): void {
        $qr = $this->getQR();
        $qr->setWidth(100);
        $this->assertTrue(true);
    }

    public function test_setWidth_zero(): void {
        $qr = $this->getQR();
        $qr->setWidth(0);
        $this->assertTrue(true);
    }

    // ── setHeight ──────────────────────────────────────────────────

    public function test_setHeight_does_not_throw(): void {
        $qr = $this->getQR();
        $qr->setHeight(100);
        $this->assertTrue(true);
    }

    public function test_setHeight_zero(): void {
        $qr = $this->getQR();
        $qr->setHeight(0);
        $this->assertTrue(true);
    }

    // ── setFilepath ────────────────────────────────────────────────

    public function test_setFilepath_does_not_throw(): void {
        $qr = $this->getQR();
        $qr->setFilepath('/tmp/test_qr');
        $this->assertTrue(true);
    }

    public function test_setFilepath_trims(): void {
        $qr = $this->getQR();
        $qr->setFilepath('  /tmp/test_qr  ');
        $this->assertTrue(true);
    }
}
