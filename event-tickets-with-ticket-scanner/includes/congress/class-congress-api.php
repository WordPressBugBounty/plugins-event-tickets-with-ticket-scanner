<?php
if (!defined('ABSPATH')) exit;

class sasoEventtickets_CongressApi {

    private sasoEventtickets $MAIN;

    public function __construct(sasoEventtickets $main) {
        $this->MAIN = $main;
    }

    public function registerRoutes(): void {
        // Initial load: meta + page menu + start-page sections (small, instantly visible).
        register_rest_route('saso-et/v1', '/congress/(?P<slug>[a-z0-9\-]+)', [
            'methods'             => 'GET',
            'callback'            => [$this, 'getCongress'],
            'permission_callback' => '__return_true',
            'args'                => [
                'slug' => ['sanitize_callback' => 'sanitize_title'],
                't'    => ['sanitize_callback' => 'sanitize_text_field'],
            ],
        ]);

        // Lazy per-page load: sections + content of one page (fetched on menu click).
        register_rest_route('saso-et/v1', '/congress/(?P<slug>[a-z0-9\-]+)/page/(?P<page_id>\d+)', [
            'methods'             => 'GET',
            'callback'            => [$this, 'getPage'],
            'permission_callback' => '__return_true',
            'args'                => [
                'slug' => ['sanitize_callback' => 'sanitize_title'],
                't'    => ['sanitize_callback' => 'sanitize_text_field'],
            ],
        ]);

        register_rest_route('saso-et/v1', '/congress/(?P<slug>[a-z0-9\-]+)/section/(?P<section_id>\d+)/unlock', [
            'methods'             => 'POST',
            'callback'            => [$this, 'unlockSection'],
            'permission_callback' => '__return_true',
        ]);
    }

    public function getCongress(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $slug      = $request->get_param('slug');
        $ticket_id = $request->get_param('t') ?? '';
        $repo      = $this->MAIN->getCongressRepository();

        $congress = $repo->checkAccess($slug, $ticket_id);
        if (!$congress) {
            return new WP_Error('access_denied', __('Access denied.', 'event-tickets-with-ticket-scanner'), ['status' => 403]);
        }

        $expired = !empty($congress['access_expires_at']) && strtotime($congress['access_expires_at']) < time();

        $repoMain = $repo;
        // Page menu (id, title, sort_order, + card-landing display meta).
        $pages = array_map(function($p) use ($repoMain) {
            $meta = $repoMain->getPageMeta($p);
            return [
                'id'          => (int)$p['id'],
                'title'       => $p['title'],
                'sort_order'  => (int)$p['sort_order'],
                'icon'        => $meta['icon'],
                'image'       => $meta['image_id'] ? (wp_get_attachment_image_url($meta['image_id'], 'large') ?: '') : '',
                'description' => $meta['description'],
                'color'       => $meta['color'],
            ];
        }, $repo->getPages((int)$congress['id']));

        $landingCards = $repo->getCongressMeta($congress)['landing_cards'];

        // Card landing shows the page grid first → don't auto-ship start-page sections.
        $start_page = $repo->getStartPage((int)$congress['id']);
        $start_id   = $start_page ? (int)$start_page['id'] : 0;
        $sections   = ($start_id && !$landingCards) ? $this->serializePageSections($start_id, $ticket_id) : [];

        $response = new WP_REST_Response([
            'id'                => (int)$congress['id'],
            'slug'              => $congress['slug'],
            'title'             => $congress['title'],
            'label'             => $repo->resolveLabel($congress),
            'landing_cards'     => $landingCards,
            'updated_at'        => $congress['updated_at'],
            'access_expires_at' => $congress['access_expires_at'],
            'expired'           => $expired,
            'pages'             => $pages,
            'start_page_id'     => $start_id,
            'sections'          => $sections,
        ]);
        $response->header('ETag', '"' . strtotime($congress['updated_at']) . '"');
        $response->header('Last-Modified', gmdate('D, d M Y H:i:s', strtotime($congress['updated_at'])) . ' GMT');
        return $response;
    }

    public function getPage(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $slug      = $request->get_param('slug');
        $page_id   = (int)$request->get_param('page_id');
        $ticket_id = $request->get_param('t') ?? '';
        $repo      = $this->MAIN->getCongressRepository();

        $congress = $repo->checkAccess($slug, $ticket_id);
        if (!$congress) {
            return new WP_Error('access_denied', __('Access denied.', 'event-tickets-with-ticket-scanner'), ['status' => 403]);
        }

        // The page must belong to this congress (no cross-congress reads).
        $page = $repo->getPageById($page_id);
        if (!$page || (int)$page['congress_id'] !== (int)$congress['id']) {
            return new WP_Error('not_found', '', ['status' => 404]);
        }

        $response = new WP_REST_Response([
            'page_id'  => $page_id,
            'title'    => $page['title'],
            'sections' => $this->serializePageSections($page_id, $ticket_id),
        ]);
        $response->header('ETag', '"' . strtotime($page['updated_at']) . '"');
        $response->header('Last-Modified', gmdate('D, d M Y H:i:s', strtotime($page['updated_at'])) . ' GMT');
        return $response;
    }

    /**
     * Serialize all sections of a page for the client. Password-protected sections
     * ship without content until unlocked (per-ticket transient). Video sections get
     * server-generated embed HTML so the browser only places it (data-from-PHP rule).
     */
    private function serializePageSections(int $page_id, string $ticket_id): array {
        $repo = $this->MAIN->getCongressRepository();
        return array_map(function($s) use ($ticket_id) {
            $out = [
                'id'           => (int)$s['id'],
                'type'         => $s['type'],
                'title'        => $s['title'],
                'sort_order'   => (int)$s['sort_order'],
                'has_password' => !empty($s['password_hash']),
                'content'      => null,
            ];
            $unlocked = empty($s['password_hash']);
            if (!$unlocked) {
                $unlock_key = 'congress_section_unlock_' . md5($ticket_id . '_' . $s['id']);
                $unlocked   = (bool) get_transient($unlock_key);
            }
            if ($unlocked) {
                $out['content'] = $this->prepareContent($s['type'], json_decode($s['content'], true), $ticket_id);
            }
            return $out;
        }, $repo->getSectionsForPage($page_id));
    }

    /**
     * Per-type content post-processing done server-side (so JS only places data):
     * video → add sanitized oEmbed HTML for provider URLs.
     * info/custom → render Twig placeholders against the viewer's ticket context,
     * but only when `{{` or `{%` notation is present (perf + avoids needless engine runs).
     */
    private function prepareContent(string $type, $content, string $ticket_id = '') {
        if (!is_array($content)) return $content;
        if ($type === 'video' && ($content['provider'] ?? '') === 'oembed' && !empty($content['url'])) {
            $embed = wp_oembed_get($content['url']);
            $content['embed_html'] = $embed ? $embed : '';
        }
        if (($type === 'info' || $type === 'custom') && $ticket_id !== '' && $this->needsTwig($content['html'] ?? '')) {
            $vars = $this->buildTicketVars($ticket_id);
            if ($vars !== null) {
                $content = $this->renderSectionTwig($content, $vars);
            }
        }
        return $content;
    }

    /** True if the text contains Twig output/statement notation. */
    private function needsTwig(string $text): bool {
        return strpos($text, '{{') !== false || strpos($text, '{%') !== false;
    }

    /** Build the template variable map for a ticket, or null if it can't be resolved. */
    private function buildTicketVars(string $ticket_id): ?array {
        $codeObj = $this->MAIN->getCongressRepository()->getCodeObjForTicket($ticket_id);
        if (!$codeObj) return null;
        try {
            return $this->MAIN->getTicketDesignerHandler()->buildVariables($codeObj, false);
        } catch (\Throwable $e) {
            $this->MAIN->getAdmin()->logErrorToDB($e, null, 'Congress section Twig vars failed');
            return null;
        }
    }

    /** Render the html field of an info/custom content array with Twig. */
    private function renderSectionTwig(array $content, array $vars): array {
        try {
            $tz = $vars['TICKET']['timezone_id'] ?? '';
            $content['html'] = $this->MAIN->getTicketDesignerHandler()->renderInlineString((string)($content['html'] ?? ''), $vars, $tz);
        } catch (\Throwable $e) {
            $this->MAIN->getAdmin()->logErrorToDB($e, null, 'Congress section Twig render failed');
            // On error, leave the raw text (with placeholders) rather than breaking the page.
        }
        return $content;
    }

    public function unlockSection(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $slug       = $request->get_param('slug');
        $section_id = (int)$request->get_param('section_id');
        $body       = $request->get_json_params();
        $ticket_id  = sanitize_text_field($body['ticket_id'] ?? '');
        $password   = $body['password'] ?? '';
        $repo       = $this->MAIN->getCongressRepository();

        $congress = $repo->checkAccess($slug, $ticket_id);
        if (!$congress) {
            return new WP_Error('access_denied', '', ['status' => 403]);
        }

        $section = $repo->getSectionById($section_id);
        if (!$section || (int)$section['congress_id'] !== (int)$congress['id']) {
            return new WP_Error('not_found', '', ['status' => 404]);
        }

        if (!wp_check_password($password, $section['password_hash'])) {
            return new WP_Error('wrong_password', __('Wrong password.', 'event-tickets-with-ticket-scanner'), ['status' => 403]);
        }

        $unlock_key = 'congress_section_unlock_' . md5($ticket_id . '_' . $section_id);
        set_transient($unlock_key, true, HOUR_IN_SECONDS);

        return new WP_REST_Response(['content' => $this->prepareContent($section['type'], json_decode($section['content'], true), $ticket_id)]);
    }
}
