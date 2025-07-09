jQuery(document).ready(function () {
	let myAjax = Ajax_sasoEventtickets;
	if (jQuery('#'+myAjax.divId)) {
		jQuery.getScript( myAjax.jsFiles, function( data, textStatus, jqxhr ){});
	}
} );