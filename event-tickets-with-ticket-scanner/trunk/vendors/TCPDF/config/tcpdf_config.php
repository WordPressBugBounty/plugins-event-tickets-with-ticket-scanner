<?php
//============================================================+
// File name   : tcpdf_config.php
// Begin       : 2004-06-11
// Last Update : 2014-12-11
//
// Description : Configuration file for SASOET_TCPDF.
// Author      : Nicola Asuni - Tecnick.com LTD - www.tecnick.com - info@tecnick.com
// License     : GNU-LGPL v3 (http://www.gnu.org/copyleft/lesser.html)
// -------------------------------------------------------------------
// Copyright (C) 2004-2014  Nicola Asuni - Tecnick.com LTD
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
// You should have received a copy of the GNU Lesser General Public License
// along with SASOET_TCPDF.  If not, see <http://www.gnu.org/licenses/>.
//
// See LICENSE.TXT file for more information.
//============================================================+

/**
 * Configuration file for SASOET_TCPDF.
 * @author Nicola Asuni
 * @package com.tecnick.tcpdf
 * @version 4.9.005
 * @since 2004-10-27
 */

// IMPORTANT:
// If you define the constant SASOET_K_TCPDF_EXTERNAL_CONFIG, all the following settings will be ignored.
// If you use the tcpdf_autoconfig.php, then you can overwrite some values here.


/**
 * Installation path (/var/www/tcpdf/).
 * By default it is automatically calculated but you can also set it as a fixed string to improve performances.
 */
//define ('SASOET_K_PATH_MAIN', '');

/**
 * URL path to tcpdf installation folder (http://localhost/tcpdf/).
 * By default it is automatically set but you can also set it as a fixed string to improve performances.
 */
//define ('SASOET_K_PATH_URL', '');

/**
 * Path for PDF fonts.
 * By default it is automatically set but you can also set it as a fixed string to improve performances.
 */
//define ('SASOET_K_PATH_FONTS', SASOET_K_PATH_MAIN.'fonts/');

/**
 * Default images directory.
 * By default it is automatically set but you can also set it as a fixed string to improve performances.
 */
//define ('SASOET_K_PATH_IMAGES', '');

/**
 * Deafult image logo used be the default Header() method.
 * Please set here your own logo or an empty string to disable it.
 */
//define ('SASOET_PDF_HEADER_LOGO', '');

/**
 * Header logo image width in user units.
 */
//define ('SASOET_PDF_HEADER_LOGO_WIDTH', 0);

/**
 * Cache directory for temporary files (full path).
 */
//define ('SASOET_K_PATH_CACHE', '/tmp/');

/**
 * Generic name for a blank image.
 */
define ('SASOET_K_BLANK_IMAGE', '_blank.png');

/**
 * Page format.
 */
define ('SASOET_PDF_PAGE_FORMAT', 'A4');

/**
 * Page orientation (P=portrait, L=landscape).
 */
define ('SASOET_PDF_PAGE_ORIENTATION', 'P');

/**
 * Document creator.
 */
define ('SASOET_PDF_CREATOR', 'SASOET_TCPDF');

/**
 * Document author.
 */
define ('SASOET_PDF_AUTHOR', 'SASOET_TCPDF');

/**
 * Header title.
 */
define ('SASOET_PDF_HEADER_TITLE', 'VOLLSTART');

/**
 * Header description string.
 */
define ('SASOET_PDF_HEADER_STRING', "by Saso Nikolov - vollstart.com");

/**
 * Document unit of measure [pt=point, mm=millimeter, cm=centimeter, in=inch].
 */
define ('SASOET_PDF_UNIT', 'mm');

/**
 * Header margin.
 */
define ('SASOET_PDF_MARGIN_HEADER', 5);

/**
 * Footer margin.
 */
define ('SASOET_PDF_MARGIN_FOOTER', 10);

/**
 * Top margin.
 */
define ('SASOET_PDF_MARGIN_TOP', 27);

/**
 * Bottom margin.
 */
define ('SASOET_PDF_MARGIN_BOTTOM', 25);

/**
 * Left margin.
 */
define ('SASOET_PDF_MARGIN_LEFT', 15);

/**
 * Right margin.
 */
define ('SASOET_PDF_MARGIN_RIGHT', 15);

/**
 * Default main font name.
 */
define ('SASOET_PDF_FONT_NAME_MAIN', 'helvetica');

/**
 * Default main font size.
 */
define ('SASOET_PDF_FONT_SIZE_MAIN', 10);

/**
 * Default data font name.
 */
define ('SASOET_PDF_FONT_NAME_DATA', 'helvetica');

/**
 * Default data font size.
 */
define ('SASOET_PDF_FONT_SIZE_DATA', 8);

/**
 * Default monospaced font name.
 */
define ('SASOET_PDF_FONT_MONOSPACED', 'courier');

/**
 * Ratio used to adjust the conversion of pixels to user units.
 */
define ('SASOET_PDF_IMAGE_SCALE_RATIO', 1.25);

/**
 * Magnification factor for titles.
 */
define('SASOET_HEAD_MAGNIFICATION', 1.1);

/**
 * Height of cell respect font height.
 */
define('SASOET_K_CELL_HEIGHT_RATIO', 1.25);

/**
 * Title magnification respect main font size.
 */
define('SASOET_K_TITLE_MAGNIFICATION', 1.3);

/**
 * Reduction factor for small font.
 */
define('SASOET_K_SMALL_RATIO', 2/3);

/**
 * Set to true to enable the special procedure used to avoid the overlappind of symbols on Thai language.
 */
define('SASOET_K_THAI_TOPCHARS', true);

/**
 * If true allows to call SASOET_TCPDF methods using HTML syntax
 * IMPORTANT: For security reason, disable this feature if you are printing user HTML content.
 */
define('SASOET_K_TCPDF_CALLS_IN_HTML', true);

/**
 * If true and PHP version is greater than 5, then the Error() method throw new exception instead of terminating the execution.
 */
define('SASOET_K_TCPDF_THROW_EXCEPTION_ERROR', false);

/**
 * Default timezone for datetime functions
 */
define('SASOET_K_TIMEZONE', 'UTC');

//============================================================+
// END OF FILE
//============================================================+
