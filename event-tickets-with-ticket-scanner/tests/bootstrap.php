<?php
/**
 * PHPUnit bootstrap file for Event Tickets with Ticket Scanner plugin tests.
 */

// Load Composer autoloader (provides PHPUnit Polyfills).
require_once __DIR__ . '/vendor/autoload.php';

$_tests_dir = getenv('WP_TESTS_DIR');

if (!$_tests_dir) {
    $_tests_dir = rtrim(sys_get_temp_dir(), '/\\') . '/wordpress-tests-lib';
}

if (!file_exists("{$_tests_dir}/includes/functions.php")) {
    echo "Could not find {$_tests_dir}/includes/functions.php, have you run bin/install-wp-tests.sh?" . PHP_EOL;
    exit(1);
}

// Give access to tests_add_filter() function.
require_once "{$_tests_dir}/includes/functions.php";

/**
 * Manually load WooCommerce and the plugin being tested.
 */
function _manually_load_plugin(): void {
    // Load WooCommerce first (required dependency).
    $wc_path = dirname(dirname(__DIR__)) . '/woocommerce/woocommerce.php';
    if (file_exists($wc_path)) {
        require $wc_path;
    }

    require dirname(__DIR__) . '/index.php';
}

tests_add_filter('muplugins_loaded', '_manually_load_plugin');

// Start up the WP testing environment.
require "{$_tests_dir}/includes/bootstrap.php";

// Create plugin tables in the test database.
sasoEventtickets::Instance()->getDB()->installiereTabellen(true);

// Reset options migration flag AFTER table creation so existing tests run in
// legacy wp_options mode. The DB upgrade (v1.11) sets this flag during migration,
// so we must clear it afterwards. OptionsMigrationTest manages the flag itself.
delete_option('saso_eventtickets_options_migrated');
sasoEventtickets::Instance()->getOptions()->resetMigrationCache();

// Create WooCommerce tables in the test database.
if (class_exists('WooCommerce')) {
    WC_Install::install();
}
