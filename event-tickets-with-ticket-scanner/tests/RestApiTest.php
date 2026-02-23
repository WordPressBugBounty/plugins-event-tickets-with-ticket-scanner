<?php
/**
 * Integration tests for the scanner REST API endpoints.
 */

class RestApiTest extends WP_UnitTestCase {

    private $main;

    public function set_up(): void {
        parent::set_up();
        $this->main = sasoEventtickets::Instance();

        // Ensure REST API routes are registered
        do_action('rest_api_init');
    }

    // ── REST Routes Registered ─────────────────────────────────

    public function test_ping_route_registered(): void {
        $routes = rest_get_server()->get_routes();
        $prefix = '/' . SASO_EVENTTICKETS::getRESTPrefixURL() . '/ticket/scanner';
        $this->assertArrayHasKey($prefix . '/ping', $routes);
    }

    public function test_retrieve_ticket_route_registered(): void {
        $routes = rest_get_server()->get_routes();
        $prefix = '/' . SASO_EVENTTICKETS::getRESTPrefixURL() . '/ticket/scanner';
        $this->assertArrayHasKey($prefix . '/retrieve_ticket', $routes);
    }

    public function test_redeem_ticket_route_registered(): void {
        $routes = rest_get_server()->get_routes();
        $prefix = '/' . SASO_EVENTTICKETS::getRESTPrefixURL() . '/ticket/scanner';
        $this->assertArrayHasKey($prefix . '/redeem_ticket', $routes);
    }

    public function test_seating_plan_route_registered(): void {
        $routes = rest_get_server()->get_routes();
        $prefix = '/' . SASO_EVENTTICKETS::getRESTPrefixURL() . '/ticket/scanner';
        $this->assertArrayHasKey($prefix . '/seating_plan', $routes);
    }

    public function test_pwa_manifest_route_registered(): void {
        $routes = rest_get_server()->get_routes();
        $prefix = '/' . SASO_EVENTTICKETS::getRESTPrefixURL() . '/ticket/scanner';
        $this->assertArrayHasKey($prefix . '/pwa-manifest', $routes);
    }

    // ── rest_ping ──────────────────────────────────────────────

    public function test_rest_ping_returns_time(): void {
        $ticket = $this->main->getTicketHandler();
        $result = $ticket->rest_ping();

        // rest_ping returns: time (unix timestamp), img_pfad, _ret._server
        $this->assertIsArray($result);
        $this->assertArrayHasKey('time', $result);
        $this->assertArrayHasKey('img_pfad', $result);
        $this->assertArrayHasKey('_ret', $result);
    }

    public function test_rest_ping_time_is_current(): void {
        $ticket = $this->main->getTicketHandler();
        $result = $ticket->rest_ping();

        // time is unix timestamp (int)
        $now = time();
        $this->assertLessThan(10, abs($now - $result['time']), 'Ping time should be close to now');
    }

    public function test_rest_ping_contains_server_times(): void {
        $ticket = $this->main->getTicketHandler();
        $result = $ticket->rest_ping();

        $this->assertArrayHasKey('_server', $result['_ret']);
        $this->assertArrayHasKey('time', $result['_ret']['_server']);
        $this->assertArrayHasKey('UTC_time', $result['_ret']['_server']);
        $this->assertArrayHasKey('timezone', $result['_ret']['_server']);
    }

    // ── Permission Callback ────────────────────────────────────

    public function test_permission_callback_without_auth_depends_on_option(): void {
        // The permission callback may allow logged-out users depending on options.
        // We test that it returns a boolean-like value.
        $request = new WP_REST_Request('GET', '/event-tickets-with-ticket-scanner/ticket/scanner/ping');

        $ticket = $this->main->getTicketHandler();
        $result = $ticket->rest_permission_callback($request);

        // Result should be bool (true if option allows non-logged-in, false otherwise)
        $this->assertIsBool($result);
    }

    public function test_permission_callback_with_admin_user(): void {
        // Create admin user and set as current
        $adminId = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($adminId);

        $request = new WP_REST_Request('GET', '/event-tickets-with-ticket-scanner/ticket/scanner/ping');

        $ticket = $this->main->getTicketHandler();
        $result = $ticket->rest_permission_callback($request);

        $this->assertTrue($result);
    }

    public function test_permission_callback_with_authtoken(): void {
        // Create an authtoken
        $auth = $this->main->getAuthtokenHandler();
        $id = $auth->addAuthtoken(['name' => 'REST Test ' . uniqid()]);

        $tokens = $auth->getAuthtokens();
        $code = null;
        foreach ($tokens as $t) {
            if ((int)$t['id'] === $id) {
                $code = $t['code'];
                break;
            }
        }

        $request = new WP_REST_Request('GET', '/event-tickets-with-ticket-scanner/ticket/scanner/ping');
        $request->set_param('authtoken', $code);

        $ticket = $this->main->getTicketHandler();
        $result = $ticket->rest_permission_callback($request);

        $this->assertTrue($result);
    }
}
