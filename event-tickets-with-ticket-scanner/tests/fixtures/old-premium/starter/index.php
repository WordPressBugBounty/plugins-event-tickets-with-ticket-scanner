<?php
/**
 * Plugin Name: Event Tickets with Ticket Scanner PREMIUM
 * Plugin URI: https://vollstart.com/
 * Description: Premium Addon for Event Tickets With Ticket Scanner for WooCommerce. You have unlimited tickets and lists. Track IP of code check requeste. Allow users to register them for their code. Download redeemed ticket logs. And more.
 * Version: 1.3.6
 * Author: Vollstart
 * Author URI: https://nikolov.org/wp-plugins
 * Text Domain: event-tickets-with-woocommmerce-premium
 * Requires Plugins: event-tickets-with-ticket-scanner
 */
defined('ABSPATH') or die('Direct access not allowed');
if (!defined('SASO_EVENTTICKETS_PREMIUM_PLUGIN_VERSION'))
	define('SASO_EVENTTICKETS_PREMIUM_PLUGIN_VERSION', '1.3.6');
if (!defined('SASO_EVENTTICKETS_PREMIUM_PLUGIN_DIR_PATH'))
	define('SASO_EVENTTICKETS_PREMIUM_PLUGIN_DIR_PATH', plugin_dir_path(__FILE__));

if (defined('SASO_EVENTTICKETS_PLUGIN_DIR_PATH')) {
	if (file_exists(SASO_EVENTTICKETS_PLUGIN_DIR_PATH."SASO_EVENTTICKETS.php")) {
		include_once SASO_EVENTTICKETS_PLUGIN_DIR_PATH."SASO_EVENTTICKETS.php";
	}
	if (!class_exists('SASO_EVENTTICKETS', false)) {
		include_once SASO_EVENTTICKETS_PLUGIN_DIR_PATH."SASO_EVENTTICKETS.php";
	}
}

include_once plugin_dir_path(__FILE__)."sasoEventtickets_PremiumFunctions.php";

/*
register_uninstall_hook( __FILE__, 'sasoEventTicketsPremium_plugin_uninstall' );
function sasoEventTicketsPremium_plugin_uninstall(){
	//delete_option
}
*/

// Clean up old PUC v4 transients that may cause serialization errors
function sasoEventTicketsPremium_cleanup_old_puc_transients() {
	// Only run once per request
	static $cleaned = false;
	if ($cleaned) return;
	$cleaned = true;

	// Delete old PUC v4 transients that might contain incompatible serialized data
	$old_transients = [
		'puc_update_saso-event-tickets-with-ticket-scanner-premium',
		'_transient_puc_update_saso-event-tickets-with-ticket-scanner-premium',
		'_site_transient_puc_update_saso-event-tickets-with-ticket-scanner-premium'
	];

	foreach ($old_transients as $transient) {
		delete_option($transient);
		delete_option($transient . '_timeout');
		delete_site_option($transient);
		delete_site_transient(str_replace('_site_transient_', '', $transient));
	}
}
// Run cleanup early before PUC initializes
add_action('plugins_loaded', 'sasoEventTicketsPremium_cleanup_old_puc_transients', 1);

require_once dirname(__FILE__).'/plugin-update-checker-5.6/plugin-update-checker.php';
use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

function sasoEventTicketsPremium_buildUpdateChecker() {
	global $sasoEventtickets;

	$serial = trim(get_option( "saso-event-tickets-premium_serial" ));
	if (!empty($serial)) {
		$connection_parameters = '?ver='.SASO_EVENTTICKETS_PREMIUM_PLUGIN_VERSION
								."&b_ver=".(isset($sasoEventtickets) && $sasoEventtickets != null && method_exists($sasoEventtickets, 'getPluginVersion') ? $sasoEventtickets->getPluginVersion() : 'not available')
								."&m=".get_option('admin_email')
								."&d=".parse_url( get_site_url(), PHP_URL_HOST )
								."&serial=".urlencode($serial);

		return PucFactory::buildUpdateChecker(
			'https://vollstart.com/plugins/event-tickets-with-ticket-scanner-premium/'.$connection_parameters,
			__FILE__, //Full path to the main plugin file or functions.php.
			'saso-event-tickets-with-ticket-scanner-premium'
		);
	}
}
$myUpdateChecker = sasoEventTicketsPremium_buildUpdateChecker();
