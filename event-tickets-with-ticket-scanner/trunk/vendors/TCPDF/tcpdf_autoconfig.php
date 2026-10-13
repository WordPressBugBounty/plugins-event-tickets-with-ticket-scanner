<?php
//============================================================+
// File name   : tcpdf_autoconfig.php
// Version     : 1.1.1
// Begin       : 2013-05-16
// Last Update : 2014-12-18
// Authors     : Nicola Asuni - Tecnick.com LTD - www.tecnick.com - info@tecnick.com
// License     : GNU-LGPL v3 (http://www.gnu.org/copyleft/lesser.html)
// -------------------------------------------------------------------
// Copyright (C) 2011-2014 Nicola Asuni - Tecnick.com LTD
//
// This file is part of SASOET_TCPDF software library.
//
// SASOET_TCPDF is free software: you can redistribute it and/or modify it
// under the terms of the GNU Lesser General Public License as
// published by the Free Software Foundation, either version 3 of the
// License, or (at your option) any later version.
//
// SASOET_TCPDF is distributed in the hope that it will be useful, but
// WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
// See the GNU Lesser General Public License for more details.
//
// You should have received a copy of the License
// along with SASOET_TCPDF. If not, see
// <http://www.tecnick.com/pagefiles/tcpdf/LICENSE.TXT>.
//
// See LICENSE.TXT file for more information.
// -------------------------------------------------------------------
//
// Description : Try to automatically configure some SASOET_TCPDF
//               constants if not defined.
//
//============================================================+

/**
 * @file
 * Try to automatically configure some SASOET_TCPDF constants if not defined.
 * @package com.tecnick.tcpdf
 * @version 1.1.1
 */

// DOCUMENT_ROOT fix for IIS Webserver
if ((!isset($_SERVER['DOCUMENT_ROOT'])) OR (empty($_SERVER['DOCUMENT_ROOT']))) {
	if(isset($_SERVER['SCRIPT_FILENAME'])) {
		$_SERVER['DOCUMENT_ROOT'] = str_replace( '\\', '/', substr($_SERVER['SCRIPT_FILENAME'], 0, 0-strlen($_SERVER['PHP_SELF'])));
	} elseif(isset($_SERVER['PATH_TRANSLATED'])) {
		$_SERVER['DOCUMENT_ROOT'] = str_replace( '\\', '/', substr(str_replace('\\\\', '\\', $_SERVER['PATH_TRANSLATED']), 0, 0-strlen($_SERVER['PHP_SELF'])));
	} else {
		// define here your DOCUMENT_ROOT path if the previous fails (e.g. '/var/www')
		$_SERVER['DOCUMENT_ROOT'] = '/';
	}
}
$_SERVER['DOCUMENT_ROOT'] = str_replace('//', '/', $_SERVER['DOCUMENT_ROOT']);
if (substr($_SERVER['DOCUMENT_ROOT'], -1) != '/') {
	$_SERVER['DOCUMENT_ROOT'] .= '/';
}

// Load main configuration file only if the SASOET_K_TCPDF_EXTERNAL_CONFIG constant is set to false.
if (!defined('SASOET_K_TCPDF_EXTERNAL_CONFIG') OR !SASOET_K_TCPDF_EXTERNAL_CONFIG) {
	// define a list of default config files in order of priority
	$tcpdf_config_files = array(dirname(__FILE__).'/config/tcpdf_config.php', '/etc/php-tcpdf/tcpdf_config.php', '/etc/tcpdf/tcpdf_config.php', '/etc/tcpdf_config.php');
	foreach ($tcpdf_config_files as $tcpdf_config) {
		if (@file_exists($tcpdf_config) AND is_readable($tcpdf_config)) {
			require_once($tcpdf_config);
			break;
		}
	}
}

if (!defined('SASOET_K_PATH_MAIN')) {
	define ('SASOET_K_PATH_MAIN', dirname(__FILE__).'/');
}

if (!defined('SASOET_K_PATH_FONTS')) {
	define ('SASOET_K_PATH_FONTS', SASOET_K_PATH_MAIN.'fonts/');
}

if (!defined('SASOET_K_PATH_URL')) {
	$k_path_url = SASOET_K_PATH_MAIN; // default value for console mode
	if (isset($_SERVER['HTTP_HOST']) AND (!empty($_SERVER['HTTP_HOST']))) {
		if(isset($_SERVER['HTTPS']) AND (!empty($_SERVER['HTTPS'])) AND (strtolower($_SERVER['HTTPS']) != 'off')) {
			$k_path_url = 'https://';
		} else {
			$k_path_url = 'http://';
		}
		$k_path_url .= $_SERVER['HTTP_HOST'];
		$k_path_url .= str_replace( '\\', '/', substr(SASOET_K_PATH_MAIN, (strlen($_SERVER['DOCUMENT_ROOT']) - 1)));
	}
	define ('SASOET_K_PATH_URL', $k_path_url);
}

if (!defined('SASOET_K_PATH_IMAGES')) {
	$tcpdf_images_dirs = array(SASOET_K_PATH_MAIN.'examples/images/', SASOET_K_PATH_MAIN.'images/', '/usr/share/doc/php-tcpdf/examples/images/', '/usr/share/doc/tcpdf/examples/images/', '/usr/share/doc/php/tcpdf/examples/images/', '/var/www/tcpdf/images/', '/var/www/html/tcpdf/images/', '/usr/local/apache2/htdocs/tcpdf/images/', SASOET_K_PATH_MAIN);
	foreach ($tcpdf_images_dirs as $tcpdf_images_path) {
		if (@file_exists($tcpdf_images_path)) {
			define ('SASOET_K_PATH_IMAGES', $tcpdf_images_path);
			break;
		}
	}
}

if (!defined('SASOET_PDF_HEADER_LOGO')) {
	$tcpdf_header_logo = '';
	if (@file_exists(SASOET_K_PATH_IMAGES.'tcpdf_logo.jpg')) {
		$tcpdf_header_logo = 'tcpdf_logo.jpg';
	}
	define ('SASOET_PDF_HEADER_LOGO', $tcpdf_header_logo);
}

if (!defined('SASOET_PDF_HEADER_LOGO_WIDTH')) {
	if (!empty($tcpdf_header_logo)) {
		define ('SASOET_PDF_HEADER_LOGO_WIDTH', 30);
	} else {
		define ('SASOET_PDF_HEADER_LOGO_WIDTH', 0);
	}
}

if (!defined('SASOET_K_PATH_CACHE')) {
	$SASOET_K_PATH_CACHE = ini_get('upload_tmp_dir') ? ini_get('upload_tmp_dir') : sys_get_temp_dir();
	if (substr($SASOET_K_PATH_CACHE, -1) != '/') {
		$SASOET_K_PATH_CACHE .= '/';
	}
	define ('SASOET_K_PATH_CACHE', $SASOET_K_PATH_CACHE);
}

if (!defined('SASOET_K_BLANK_IMAGE')) {
	define ('SASOET_K_BLANK_IMAGE', '_blank.png');
}

if (!defined('SASOET_PDF_PAGE_FORMAT')) {
	define ('SASOET_PDF_PAGE_FORMAT', 'A4');
}

if (!defined('SASOET_PDF_PAGE_ORIENTATION')) {
	define ('SASOET_PDF_PAGE_ORIENTATION', 'P');
}

if (!defined('SASOET_PDF_CREATOR')) {
	define ('SASOET_PDF_CREATOR', 'SASOET_TCPDF');
}

if (!defined('SASOET_PDF_AUTHOR')) {
	define ('SASOET_PDF_AUTHOR', 'SASOET_TCPDF');
}

if (!defined('SASOET_PDF_HEADER_TITLE')) {
	define ('SASOET_PDF_HEADER_TITLE', 'SASOET_TCPDF Example');
}

if (!defined('SASOET_PDF_HEADER_STRING')) {
	define ('SASOET_PDF_HEADER_STRING', "by Nicola Asuni - Tecnick.com\nwww.tcpdf.org");
}

if (!defined('SASOET_PDF_UNIT')) {
	define ('SASOET_PDF_UNIT', 'mm');
}

if (!defined('SASOET_PDF_MARGIN_HEADER')) {
	define ('SASOET_PDF_MARGIN_HEADER', 5);
}

if (!defined('SASOET_PDF_MARGIN_FOOTER')) {
	define ('SASOET_PDF_MARGIN_FOOTER', 10);
}

if (!defined('SASOET_PDF_MARGIN_TOP')) {
	define ('SASOET_PDF_MARGIN_TOP', 27);
}

if (!defined('SASOET_PDF_MARGIN_BOTTOM')) {
	define ('SASOET_PDF_MARGIN_BOTTOM', 25);
}

if (!defined('SASOET_PDF_MARGIN_LEFT')) {
	define ('SASOET_PDF_MARGIN_LEFT', 15);
}

if (!defined('SASOET_PDF_MARGIN_RIGHT')) {
	define ('SASOET_PDF_MARGIN_RIGHT', 15);
}

if (!defined('SASOET_PDF_FONT_NAME_MAIN')) {
	define ('SASOET_PDF_FONT_NAME_MAIN', 'helvetica');
}

if (!defined('SASOET_PDF_FONT_SIZE_MAIN')) {
	define ('SASOET_PDF_FONT_SIZE_MAIN', 10);
}

if (!defined('SASOET_PDF_FONT_NAME_DATA')) {
	define ('SASOET_PDF_FONT_NAME_DATA', 'helvetica');
}

if (!defined('SASOET_PDF_FONT_SIZE_DATA')) {
	define ('SASOET_PDF_FONT_SIZE_DATA', 8);
}

if (!defined('SASOET_PDF_FONT_MONOSPACED')) {
	define ('SASOET_PDF_FONT_MONOSPACED', 'courier');
}

if (!defined('SASOET_PDF_IMAGE_SCALE_RATIO')) {
	define ('SASOET_PDF_IMAGE_SCALE_RATIO', 1.25);
}

if (!defined('SASOET_HEAD_MAGNIFICATION')) {
	define('SASOET_HEAD_MAGNIFICATION', 1.1);
}

if (!defined('SASOET_K_CELL_HEIGHT_RATIO')) {
	define('SASOET_K_CELL_HEIGHT_RATIO', 1.25);
}

if (!defined('SASOET_K_TITLE_MAGNIFICATION')) {
	define('SASOET_K_TITLE_MAGNIFICATION', 1.3);
}

if (!defined('SASOET_K_SMALL_RATIO')) {
	define('SASOET_K_SMALL_RATIO', 2/3);
}

if (!defined('SASOET_K_THAI_TOPCHARS')) {
	define('SASOET_K_THAI_TOPCHARS', true);
}

if (!defined('SASOET_K_TCPDF_CALLS_IN_HTML')) {
	define('SASOET_K_TCPDF_CALLS_IN_HTML', false);
}

if (!defined('SASOET_K_TCPDF_THROW_EXCEPTION_ERROR')) {
	define('SASOET_K_TCPDF_THROW_EXCEPTION_ERROR', false);
}

if (!defined('SASOET_K_TIMEZONE')) {
	define('SASOET_K_TIMEZONE', @date_default_timezone_get());
}

//============================================================+
// END OF FILE
//============================================================+
