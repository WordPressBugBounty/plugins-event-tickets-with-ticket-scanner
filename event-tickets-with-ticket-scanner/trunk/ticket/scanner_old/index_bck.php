<?php
require_once('../../../../../wp-load.php');
get_current_user_id();
wp_create_nonce( 'wp_rest' );
?>
<!DOCTYPE html>
<html>
    <head>
        <title>Event Ticket Scanner</title>
        <meta http-equiv="Cache-control" content="no-cache, must-revalidate">
        <meta http-equiv="Pragma" content="no-cache">
        <meta http-equiv="Expires" content="Sat, 26 Jul 1997 05:00:00 GMT">
        <link rel='stylesheet' id='wp-block-library-css'  href='../../../../../wp-includes/css/jquery-ui-dialog.min.css' media='all' />
        <style>
            body {font-family: Helvetica, Arial, sans-serif;}
            h3,h4,h5 {padding-bottom:0.5em;margin-bottom:0;}
            p {padding:0;margin:0;margin-bottom:1em;}
			div.ticket_content p {font-size:initial !important;margin-bottom:1em !important;}
            button {padding:10px;font-size: 1.5em;}
            .lds-dual-ring {display:inline-block;width:64px;height:64px;}
            .lds-dual-ring:after {content:" ";display:block;width:46px;height:46px;margin:1px;border-radius:50%;border:5px solid #fff;border-color:#2e74b5 transparent #2e74b5 transparent;animation:lds-dual-ring 0.6s linear infinite;}
            @keyframes lds-dual-ring {0% {transform: rotate(0deg);} 100% {transform: rotate(360deg);}}
		</style>
        <!-- umbauen, so dass später beim Update dieser Teil als eigenes JS geladen wird und dynamisch mit den richtigen URLs ausgestattet wird -->
        <script src='../../../../../wp-includes/js/jquery/jquery.min.js?ver=3.6.0' id='jquery-core-js'></script>
        <script src='../../../../../wp-includes/js/jquery/jquery-migrate.min.js?ver=3.3.2' id='jquery-migrate-js'></script>
        <script src='../../../../../wp-includes/js/jquery/ui/core.min.js?ver=1.13.1' id='jquery-ui-core-js'></script>
        <script src='../../../../../wp-includes/js/jquery/ui/mouse.min.js?ver=1.13.1' id='jquery-ui-mouse-js'></script>
        <script src='../../../../../wp-includes/js/jquery/ui/resizable.min.js?ver=1.13.1' id='jquery-ui-resizable-js'></script>
        <script src='../../../../../wp-includes/js/jquery/ui/draggable.min.js?ver=1.13.1' id='jquery-ui-draggable-js'></script>
        <script src='../../../../../wp-includes/js/jquery/ui/controlgroup.min.js?ver=1.13.1' id='jquery-ui-controlgroup-js'></script>
        <script src='../../../../../wp-includes/js/jquery/ui/checkboxradio.min.js?ver=1.13.1' id='jquery-ui-checkboxradio-js'></script>
        <script src='../../../../../wp-includes/js/jquery/ui/button.min.js?ver=1.13.1' id='jquery-ui-button-js'></script>
        <script src='../../../../../wp-includes/js/jquery/ui/dialog.min.js?ver=1.13.1' id='jquery-ui-dialog-js'></script>
        <script src='../../3rd/jquery.qrcode.min.js?_v=1.0.12&ver=6.0.2' id='ajax_script-js'></script>
        <script src='../../3rd/html5-qrcode.min.js?_v=1.0.12&ver=6.0.2' id='html5-qrcode-js'></script>
        <script type="text/javascript">
            var NONCE = '<?php echo wp_create_nonce( 'wp_rest' ) ?>';
            var IS_PRETTY_PERMALINK_ACTIVATED = <?php if(get_option('permalink_structure')) echo 'true'; else echo 'false';  ?>;
        </script>
        <script type="text/javascript" src="../../ticket_scanner.js"></script>
    </head>
    <body>
        <center>
        <h1>Ticket Scanner</h1>
        <div style="width:90%;max-width:800px;">
            <div style="width: 100%; justify-content: center;align-items: center;position: relative;">
                <div class="ticket_content" style="background-color:white;color:black;padding:15px;display:block;position: relative;left: 0;right: 0;margin: auto;text-align:left;border:1px solid black;">
                    <div id="ticket_scanner_info_area"></div>
                    <div id="ticket_info_retrieved" style="padding-top:20px;padding-bottom:20px;"></div>
                    <div id="ticket_info"></div>
                    <div id="ticket_add_info"></div>
                    <div id="ticket_info_btns" style="padding-top:20px;padding-bottom:20px;"></div>
                    <div id="reader_output"></div>
                    <div id="reader" style="width:100%"></div>
                </div>
            </div>
        </div>
        </center>
    </body>
</html>