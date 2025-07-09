<?php
defined('ABSPATH') or die('Direct access not allowed');
if (defined('WP_DEBUG') && WP_DEBUG && isset($_GET['VollstartValidatorDebug'])) {
	//defined('WP_DEBUG') or define( 'WP_DEBUG', true );
	ini_set('display_startup_errors', 'On');
	error_reporting(2147483647); // max future error values
	ini_set('display_errors', 'On');
}
?>