<?php
/**
 * Tests for sasoEventtickets_Messenger type validation:
 * sendMessage and sendFile throw for invalid types.
 */

class MessengerTypeCheckTest extends WP_UnitTestCase {

    private $main;

    public function set_up(): void {
        parent::set_up();
        $this->main = sasoEventtickets::Instance();
    }

    private function getMessenger(): sasoEventtickets_Messenger {
        // Ensure class is loaded
        $pluginDir = dirname(__DIR__);
        if (!class_exists('sasoEventtickets_Messenger')) {
            require_once $pluginDir . '/sasoEventtickets_Messenger.php';
        }
        return new sasoEventtickets_Messenger();
    }

    // ── sendMessage type validation ────────────────────────────────

    public function test_sendMessage_throws_for_null_type(): void {
        $messenger = $this->getMessenger();
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('#2006');
        $messenger->sendMessage('test', '+1234567890', null);
    }

    public function test_sendMessage_throws_for_invalid_type(): void {
        $messenger = $this->getMessenger();
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('#2007');
        $messenger->sendMessage('test', '+1234567890', 'sms');
    }

    // ── sendFile type validation ───────────────────────────────────

    public function test_sendFile_throws_for_null_type(): void {
        $messenger = $this->getMessenger();
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('#2006');
        $messenger->sendFile('/tmp/test.pdf', 'msg', '+1234567890', null);
    }

    public function test_sendFile_throws_for_invalid_type(): void {
        $messenger = $this->getMessenger();
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('#2007');
        $messenger->sendFile('/tmp/test.pdf', 'msg', '+1234567890', 'email');
    }
}
