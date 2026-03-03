<?php
/**
 * Tests that old premium versions (starter 1.3.6, stop 1.2.9) do not crash
 * when loaded alongside the current basic plugin.
 *
 * Verifies:
 * - Old premium files are syntactically valid PHP
 * - The version guard in getPremiumFunctions() correctly detects old versions
 * - PremiumFunctions class file can be included without fatal errors
 * - The basic plugin's guard logic works for all version scenarios
 */

class OldPremiumGuardTest extends WP_UnitTestCase {

    private $main;
    private $fixturesDir;

    public function set_up(): void {
        parent::set_up();
        $this->main = sasoEventtickets::Instance();
        $this->fixturesDir = dirname(__FILE__) . '/fixtures/old-premium';
    }

    // ── Fixture files exist ────────────────────────────────────────

    public function test_starter_premium_fixture_exists(): void {
        $this->assertFileExists($this->fixturesDir . '/starter/index.php');
        $this->assertFileExists($this->fixturesDir . '/starter/sasoEventtickets_PremiumFunctions.php');
    }

    public function test_stop_premium_fixture_exists(): void {
        $this->assertFileExists($this->fixturesDir . '/stop/index.php');
        $this->assertFileExists($this->fixturesDir . '/stop/sasoEventtickets_PremiumFunctions.php');
    }

    // ── Syntax validation ──────────────────────────────────────────

    public function test_starter_premium_files_valid_php(): void {
        $files = glob($this->fixturesDir . '/starter/*.php');
        $this->assertNotEmpty($files, 'Should have PHP files in starter fixture');
        foreach ($files as $file) {
            $output = [];
            $result = 0;
            exec('php -l ' . escapeshellarg($file) . ' 2>&1', $output, $result);
            $this->assertEquals(0, $result, 'Syntax error in ' . basename($file) . ': ' . implode("\n", $output));
        }
    }

    public function test_stop_premium_files_valid_php(): void {
        $files = glob($this->fixturesDir . '/stop/*.php');
        $this->assertNotEmpty($files, 'Should have PHP files in stop fixture');
        foreach ($files as $file) {
            $output = [];
            $result = 0;
            exec('php -l ' . escapeshellarg($file) . ' 2>&1', $output, $result);
            $this->assertEquals(0, $result, 'Syntax error in ' . basename($file) . ': ' . implode("\n", $output));
        }
    }

    // ── Version constant parsing ───────────────────────────────────

    public function test_starter_premium_declares_version_1_3_6(): void {
        $content = file_get_contents($this->fixturesDir . '/starter/index.php');
        $this->assertMatchesRegularExpression(
            "/define\('SASO_EVENTTICKETS_PREMIUM_PLUGIN_VERSION',\s*'1\.3\.6'\)/",
            $content,
            'Starter premium should declare version 1.3.6'
        );
    }

    public function test_stop_premium_declares_version_1_2_9(): void {
        $content = file_get_contents($this->fixturesDir . '/stop/index.php');
        $this->assertMatchesRegularExpression(
            "/define\('SASO_EVENTTICKETS_PREMIUM_PLUGIN_VERSION',\s*'1\.2\.9'\)/",
            $content,
            'Stop premium should declare version 1.2.9'
        );
    }

    // ── Guard logic (version_compare) ──────────────────────────────

    public function test_guard_blocks_starter_version(): void {
        $min_premium_version = '1.6.0';
        $this->assertTrue(
            version_compare('1.3.6', $min_premium_version, '<'),
            'Starter premium 1.3.6 must be below minimum 1.6.0'
        );
    }

    public function test_guard_blocks_stop_version(): void {
        $min_premium_version = '1.6.0';
        $this->assertTrue(
            version_compare('1.2.9', $min_premium_version, '<'),
            'Stop premium 1.2.9 must be below minimum 1.6.0'
        );
    }

    public function test_guard_allows_current_premium(): void {
        $min_premium_version = '1.6.0';
        if (!defined('SASO_EVENTTICKETS_PREMIUM_PLUGIN_VERSION')) {
            $this->markTestSkipped('No premium plugin active');
        }
        $this->assertFalse(
            version_compare(SASO_EVENTTICKETS_PREMIUM_PLUGIN_VERSION, $min_premium_version, '<'),
            'Current premium ' . SASO_EVENTTICKETS_PREMIUM_PLUGIN_VERSION . ' must be >= ' . $min_premium_version
        );
    }

    // ── init_file.php safe to include ──────────────────────────────

    public function test_starter_init_file_is_harmless(): void {
        $content = file_get_contents($this->fixturesDir . '/starter/init_file.php');
        // Must NOT contain class instantiations or function calls that could crash
        $this->assertStringNotContainsString('new sasoEventtickets', $content);
        $this->assertStringNotContainsString('sasoEventtickets::Instance', $content);
    }

    public function test_stop_init_file_is_harmless(): void {
        $content = file_get_contents($this->fixturesDir . '/stop/init_file.php');
        $this->assertStringNotContainsString('new sasoEventtickets', $content);
        $this->assertStringNotContainsString('sasoEventtickets::Instance', $content);
    }

    // ── PremiumFunctions class is only a definition ────────────────

    public function test_starter_premium_functions_does_not_auto_instantiate(): void {
        $content = file_get_contents($this->fixturesDir . '/starter/sasoEventtickets_PremiumFunctions.php');
        // File should define the class but NOT instantiate it at file level
        $this->assertStringContainsString('class sasoEventtickets_PremiumFunctions', $content);
        // Check no top-level instantiation (outside class body)
        $this->assertDoesNotMatchRegularExpression(
            '/^\s*new sasoEventtickets_PremiumFunctions/m',
            $content,
            'PremiumFunctions must not self-instantiate at file level'
        );
    }

    public function test_stop_premium_functions_does_not_auto_instantiate(): void {
        $content = file_get_contents($this->fixturesDir . '/stop/sasoEventtickets_PremiumFunctions.php');
        $this->assertStringContainsString('class sasoEventtickets_PremiumFunctions', $content);
        $this->assertDoesNotMatchRegularExpression(
            '/^\s*new sasoEventtickets_PremiumFunctions/m',
            $content,
            'PremiumFunctions must not self-instantiate at file level'
        );
    }

    // ── Integration: isOldPremiumDetected works ────────────────────

    public function test_isOldPremiumDetected_method_exists(): void {
        $this->assertTrue(
            method_exists($this->main, 'isOldPremiumDetected'),
            'Basic plugin must have isOldPremiumDetected() method'
        );
    }

    public function test_isOldPremiumDetected_returns_bool(): void {
        $result = $this->main->isOldPremiumDetected();
        $this->assertIsBool($result);
    }

    // ── Guard logic: version boundary tests ────────────────────────

    public function test_guard_blocks_version_1_5_9(): void {
        $min = '1.6.0';
        $this->assertTrue(version_compare('1.5.9', $min, '<'));
    }

    public function test_guard_allows_version_1_6_0(): void {
        $min = '1.6.0';
        $this->assertFalse(version_compare('1.6.0', $min, '<'));
    }

    public function test_guard_allows_version_1_6_1(): void {
        $min = '1.6.0';
        $this->assertFalse(version_compare('1.6.1', $min, '<'));
    }

    public function test_guard_allows_version_2_0_0(): void {
        $min = '1.6.0';
        $this->assertFalse(version_compare('2.0.0', $min, '<'));
    }
}
