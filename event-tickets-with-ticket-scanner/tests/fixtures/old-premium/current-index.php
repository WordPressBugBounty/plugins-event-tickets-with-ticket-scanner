<?php
/**
 * Plugin Name: Event Tickets with Ticket Scanner PREMIUM
 * Plugin URI: https://vollstart.com/
 * Description: Premium Addon for Event Tickets With Ticket Scanner for WooCommerce. You have unlimited tickets and lists. Track IP of code check requeste. Allow users to register them for their code. Download redeemed ticket logs. And more.
 * Version: 1.6.2
 * Author: Vollstart
 * Author URI: https://nikolov.org/wp-plugins
 * Text Domain: event-tickets-with-woocommmerce-premium
 */
defined('ABSPATH') or die('Direct access not allowed');
if (!defined('SASO_EVENTTICKETS_PREMIUM_PLUGIN_VERSION'))
	define('SASO_EVENTTICKETS_PREMIUM_PLUGIN_VERSION', '1.6.2');
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

require_once dirname(__FILE__).'/plugin-update-checker-5.6/plugin-update-checker.php';
use YahnisElsts\PluginUpdateChecker\v5\PucFactory;
function sasoEventTicketsPremium_buildUpdateChecker() {
	global $sasoEventtickets;

	$serial = trim(get_option( "saso-event-tickets-premium_serial" ));
	if (!empty($serial)) {
		$connection_parameters = '?ver='.SASO_EVENTTICKETS_PREMIUM_PLUGIN_VERSION
								."&b_ver=".($sasoEventtickets != null ? $sasoEventtickets->getPluginVersion() :'not available')
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

/**
 * Admin notice for expiring premium serial
 * Shows a warning 30 days before the serial expires
 */
function sasoEventTicketsPremium_serial_expiration_notice() {
	if (!current_user_can('manage_options')) {
		return;
	}
	$serial = trim(get_option('saso-event-tickets-premium_serial', ''));
	if (empty($serial)) {
		return;
	}
	$expires = get_option('saso-event-tickets-premium_serial_expires', '');
	if (empty($expires)) {
		return;
	}
	$expires_timestamp = is_numeric($expires) ? intval($expires) : strtotime($expires);
	if ($expires_timestamp === false || $expires_timestamp <= 0) {
		return;
	}
	$now = time();
	$days_until_expiry = floor(($expires_timestamp - $now) / 86400);

	if ($days_until_expiry <= 30 && $days_until_expiry > 0) {
		$expiry_date = date_i18n(get_option('date_format'), $expires_timestamp);
		$class = $days_until_expiry <= 7 ? 'notice-error' : 'notice-warning';
		$renew_url = 'https://vollstart.com/product/event-tickets-with-ticket-scanner-premium/';
		printf(
			'<div class="notice %s is-dismissible"><p><strong>Event Tickets Premium:</strong> %s <a href="%s" target="_blank">%s</a></p></div>',
			esc_attr($class),
			sprintf(
				esc_html__('Your premium serial will expire in %1$d days (on %2$s).', 'event-tickets-with-woocommmerce-premium'),
				$days_until_expiry,
				$expiry_date
			),
			esc_url($renew_url),
			esc_html__('Renew now', 'event-tickets-with-woocommmerce-premium')
		);
	} elseif ($days_until_expiry <= 0) {
		$renew_url = 'https://vollstart.com/product/event-tickets-with-ticket-scanner-premium/';
		printf(
			'<div class="notice notice-error"><p><strong>Event Tickets Premium:</strong> %s <a href="%s" target="_blank">%s</a></p></div>',
			esc_html__('Your premium serial has expired. Updates are no longer available.', 'event-tickets-with-woocommmerce-premium'),
			esc_url($renew_url),
			esc_html__('Renew now', 'event-tickets-with-woocommmerce-premium')
		);
	}
}
add_action('admin_notices', 'sasoEventTicketsPremium_serial_expiration_notice');

/**
 * Capture expiration date from PUC response
 */
function sasoEventTicketsPremium_capture_expiration_from_puc($result) {
	if ($result !== null && is_object($result)) {
		if (isset($result->serial_expires) && !empty($result->serial_expires)) {
			update_option('saso-event-tickets-premium_serial_expires', $result->serial_expires);
		}
	}
	return $result;
}
add_filter('puc_request_info_result-saso-event-tickets-with-ticket-scanner-premium', 'sasoEventTicketsPremium_capture_expiration_from_puc');
