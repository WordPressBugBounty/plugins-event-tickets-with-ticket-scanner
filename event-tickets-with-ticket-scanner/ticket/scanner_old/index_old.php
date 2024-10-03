<?php

    require_once('../../../../../wp-load.php');
	include_once "../../sasoEventtickets_Ticket.php";
	$vollstart_Ticket = sasoEventtickets_Ticket::Instance($_SERVER["REQUEST_URI"]);
	$vollstart_Ticket->initFilterAndActions();
	$vollstart_Ticket->renderPage();

?>