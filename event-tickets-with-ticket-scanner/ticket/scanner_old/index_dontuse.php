<?php

    require_once('../../../../../wp-load.php');
    //get_current_user_id();
    //wp_create_nonce( 'wp_rest' );
    include_once "../../sasoEventtickets_Ticket.php";

    $vollstart_Ticket = sasoEventtickets_Ticket::Instance($_SERVER["REQUEST_URI"]);
	//$vollstart_Ticket->initFilterAndActionsTicketScanner();
	//$vollstart_Ticket->renderPage();
    wp_head();
    $vollstart_Ticket->outputTicketScannerStandalone();
    wp_footer();
?>