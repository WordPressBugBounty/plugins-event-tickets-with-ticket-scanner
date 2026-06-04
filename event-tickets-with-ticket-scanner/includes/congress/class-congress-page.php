<?php
if (!defined('ABSPATH')) exit;

class sasoEventtickets_CongressPage {

    private sasoEventtickets $MAIN;

    public function __construct(sasoEventtickets $main) {
        $this->MAIN = $main;
    }

    /**
     * Render the congress page for a ticket. Called from the Ticket handler's output()
     * when the "congress" marker is present (…/ticket/{TICKETID}?congress or ?code=…&congress)
     * — so it reuses the whole ticket routing (path-based, compatibility-mode, query fallback).
     * The congress is derived from the ticket's product; no slug in the URL → no slug collision.
     */
    public function renderForTicket(string $ticket_id): void {
        // Public congress page gated by the option (admin area stays available regardless).
        if (!$this->MAIN->getOptions()->isOptionCheckboxActive('congressModeActive')) {
            status_header(404);
            $this->renderError(__('Congress not found.', 'event-tickets-with-ticket-scanner'));
            return;
        }
        $repo = $this->MAIN->getCongressRepository();
        // getForTicket applies all access checks (order status, expiry, event window, linkage)
        $congress = $repo->getForTicket($ticket_id);
        if (!$congress) {
            status_header(403);
            $this->renderError(__('Access denied. Invalid ticket or no congress assigned to this ticket.', 'event-tickets-with-ticket-scanner'));
            return;
        }

        // Manifest request (PWA)
        if (isset($_GET['manifest'])) {
            $this->renderManifest($congress, $ticket_id);
            return;
        }

        $expired = !empty($congress['access_expires_at']) && strtotime($congress['access_expires_at']) < time();

        // ETag / Last-Modified
        $etag          = '"' . strtotime($congress['updated_at']) . '"';
        $last_modified = gmdate('D, d M Y H:i:s', strtotime($congress['updated_at'])) . ' GMT';
        if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim($_SERVER['HTTP_IF_NONE_MATCH']) === $etag) {
            status_header(304);
            return;
        }
        // Served from a plugin path that WP first resolves as 404 — force a real 200.
        status_header(200);
        header('ETag: ' . $etag);
        header('Last-Modified: ' . $last_modified);
        header('Cache-Control: private, no-cache');

        // Sections are NOT rendered here — the minimal shell loads them via the REST API in JS.
        $this->renderPage($congress, $ticket_id, $expired);
    }

    /**
     * Minimal page shell. NO UI is built in PHP — scripts/styles are enqueued via the WP
     * framework (no echoed tags), config is passed via wp_localize_script, and the sections
     * are fetched from the REST API and rendered by congress-frontend.js in the browser.
     * Keeps PHP per-request work tiny (just an access check + shell) → cacheable, light.
     */
    private function renderPage(array $congress, string $ticket_id, bool $expired): void {
        $plugin_url = plugin_dir_url(dirname(dirname(__FILE__)));
        $plugin_dir = dirname(dirname(__DIR__));
        $languages  = $plugin_dir . '/languages';
        // filemtime cache-busting so frontend asset changes are picked up without a version bump.
        $css_file   = $plugin_dir . '/css/congress-frontend.css';
        $js_file    = $plugin_dir . '/js/congress-frontend.js';
        $css_ver    = SASO_EVENTTICKETS_PLUGIN_VERSION . '.' . (@filemtime($css_file) ?: '0');
        $js_ver     = SASO_EVENTTICKETS_PLUGIN_VERSION . '.' . (@filemtime($js_file) ?: '0');
        $show_wallet = (bool) $this->MAIN->getOptions()->isOptionCheckboxActive('congressShowWalletLink');

        wp_register_style('saso-congress-frontend', $plugin_url . 'css/congress-frontend.css', [], $css_ver);
        wp_enqueue_style('saso-congress-frontend');

        wp_register_script('saso-congress-frontend', $plugin_url . 'js/congress-frontend.js', ['wp-i18n'], $js_ver, true);
        wp_set_script_translations('saso-congress-frontend', 'event-tickets-with-ticket-scanner', $languages);
        wp_localize_script('saso-congress-frontend', 'sasoEtCongressFrontend', [
            'restBase'       => rest_url('saso-et/v1/'),
            'slug'           => $congress['slug'],
            'ticket'         => $ticket_id,
            'nonce'          => wp_create_nonce('wp_rest'),
            'title'          => $congress['title'],
            'expired'        => $expired ? 1 : 0,
            'showWalletLink' => $show_wallet ? 1 : 0,
            'walletUrl'      => $show_wallet ? $this->MAIN->getCore()->getWalletImportURL($ticket_id) : '',
        ]);
        wp_enqueue_script('saso-congress-frontend');

        $manifest_url = add_query_arg('manifest', '1', $this->MAIN->getCongressRepository()->getUrl($ticket_id));
        ?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo('charset'); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo esc_html($congress['title']); ?></title>
<link rel="manifest" href="<?php echo esc_url($manifest_url); ?>">
<?php wp_print_styles('saso-congress-frontend'); ?>
</head>
<body class="congress-page">
<div id="congress-app" class="congress-app" data-loading="1">
    <div class="congress-loading"><?php esc_html_e('Loading…', 'event-tickets-with-ticket-scanner'); ?></div>
</div>
<?php wp_print_scripts('saso-congress-frontend'); ?>
</body>
</html><?php
    }

    public function renderManifest(array $congress, string $ticket_id = ''): void {
        header('Content-Type: application/manifest+json');
        $page_url = $this->MAIN->getCongressRepository()->getUrl($ticket_id);
        echo wp_json_encode([
            'name'             => $congress['title'],
            'short_name'       => mb_substr($congress['title'], 0, 12),
            'start_url'        => $page_url,
            'scope'            => $page_url,
            'display'          => 'standalone',
            'background_color' => '#ffffff',
            'theme_color'      => '#0073aa',
            'icons'            => [
                ['src' => plugin_dir_url(dirname(dirname(__FILE__))) . 'img/pwa-icon-192.png', 'sizes' => '192x192', 'type' => 'image/png'],
                ['src' => plugin_dir_url(dirname(dirname(__FILE__))) . 'img/pwa-icon-512.png', 'sizes' => '512x512', 'type' => 'image/png'],
            ],
        ]);
    }

    private function renderError(string $message): void {
        ?><!DOCTYPE html>
<html><head><meta charset="utf-8"><title>Fehler</title></head>
<body><p style="padding:40px;font-family:sans-serif"><?php echo esc_html($message); ?></p></body>
</html><?php
    }
}
