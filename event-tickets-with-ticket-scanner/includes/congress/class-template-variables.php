<?php
if (!defined('ABSPATH')) exit;

/**
 * Single source of truth for the Twig placeholder variables available in
 * admin-authored templates (ticket PDFs and congress section text). Grouped
 * for a dropdown UI. Tokens are the inner Twig expression (no braces).
 */
class sasoEventtickets_TemplateVariables {

    public static function getList(): array {
        $td = 'event-tickets-with-ticket-scanner';
        return [
            ['group' => __('Ticket', $td),   'label' => __('Ticket number', $td),   'token' => 'TICKET.public_ticket_number'],
            ['group' => __('Ticket', $td),   'label' => __('Start date', $td),      'token' => 'TICKET.start_date'],
            ['group' => __('Ticket', $td),   'label' => __('Start time', $td),      'token' => 'TICKET.start_time'],
            ['group' => __('Ticket', $td),   'label' => __('Location', $td),        'token' => 'TICKET.location'],
            ['group' => __('Ticket', $td),   'label' => __('Seat label', $td),      'token' => 'TICKET.seat_label'],
            ['group' => __('Order', $td),    'label' => __('Order number', $td),    'token' => 'ORDER.get_order_number()'],
            ['group' => __('Order', $td),    'label' => __('Order date paid', $td), 'token' => 'TICKET.order_date_paid_text'],
            ['group' => __('Customer', $td), 'label' => __('First name', $td),      'token' => 'CUSTOMER.first_name'],
            ['group' => __('Customer', $td), 'label' => __('Last name', $td),       'token' => 'CUSTOMER.last_name'],
            ['group' => __('Customer', $td), 'label' => __('Email', $td),           'token' => 'CUSTOMER.user_email'],
            ['group' => __('Product', $td),  'label' => __('Product name', $td),    'token' => 'PRODUCT.get_name()'],
        ];
    }
}
