<?php
if (!defined('ABSPATH')) exit;

class sasoEventtickets_CongressAdmin {

    private sasoEventtickets $MAIN;

    public function __construct(sasoEventtickets $main) {
        $this->MAIN = $main;
    }

    public function registerMenu(): void {
        // Register as hidden admin page (no sidebar entry) — accessible via the plugin's own nav button
        add_submenu_page(
            null,
            __('Congresses', 'event-tickets-with-ticket-scanner'),
            __('Congresses', 'event-tickets-with-ticket-scanner'),
            'manage_options',
            'saso-et-congresses',
            [$this, 'renderPage']
        );
    }

    public function renderPage(): void {
        if (!current_user_can('manage_options')) wp_die('Forbidden');
        wp_enqueue_script(
            'saso-et-congress-admin',
            plugin_dir_url(dirname(dirname(__FILE__))) . 'js/congress-admin.js',
            ['jquery', 'jquery-ui-sortable', 'wp-i18n'],
            SASO_EVENTTICKETS_PLUGIN_VERSION,
            true
        );
        wp_enqueue_style(
            'saso-et-congress-admin',
            plugin_dir_url(dirname(dirname(__FILE__))) . 'css/congress-admin.css',
            [],
            SASO_EVENTTICKETS_PLUGIN_VERSION
        );
        wp_localize_script('saso-et-congress-admin', 'sasoEtCongress', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('sasoEventtickets'),
        ]);
        echo '<div id="saso-et-congress-app"></div>';
    }

    public function handleAjax(): void {
        check_ajax_referer('sasoEventtickets');
        if (!current_user_can('manage_options')) wp_send_json_error('forbidden', 403);

        $congress_action = sanitize_key($_POST['congress_action'] ?? '');
        $repo = $this->MAIN->getCongressRepository();

        switch ($congress_action) {

            case 'list':
                $all  = $repo->getAll();
                $data = [];
                foreach ($all as $c) {
                    $product_ids = $repo->getProductIds((int)$c['id']);
                    $expired     = !empty($c['access_expires_at']) && strtotime($c['access_expires_at']) < time();
                    $data[]      = [
                        'id'                => (int)$c['id'],
                        'title'             => esc_html($c['title']),
                        'slug'              => esc_html($c['slug']),
                        'product_count'     => count($product_ids),
                        'updated_at'        => esc_html($c['updated_at']),
                        'access_expires_at' => esc_html($c['access_expires_at'] ?? ''),
                        'status'            => $expired ? 'expired' : 'active',
                    ];
                }
                // Raw JSON for DataTables server-side processing (no WP success wrapper)
                wp_send_json(['draw' => 1, 'recordsTotal' => count($data), 'recordsFiltered' => count($data), 'data' => $data]);
                break;

            case 'get':
                $id = (int)($_POST['id'] ?? 0);
                if (!$id) wp_send_json_error('missing_id');
                $congress = $repo->getById($id);
                if (!$congress) wp_send_json_error('not_found');
                $congress['product_ids'] = $repo->getProductIds($id);
                $congress['sections']    = $repo->getSections($id);
                wp_send_json_success($congress);
                break;

            case 'save':
                $id    = (int)($_POST['id'] ?? 0);
                $slug  = sanitize_title($_POST['slug'] ?? '');
                $title = sanitize_text_field($_POST['title'] ?? '');
                $expires     = sanitize_text_field($_POST['access_expires_at'] ?? '');
                $event_start = sanitize_text_field($_POST['event_start_at'] ?? '');
                $event_end   = sanitize_text_field($_POST['event_end_at'] ?? '');
                $is_active   = isset($_POST['is_active']) ? (int) (!empty($_POST['is_active']) && $_POST['is_active'] !== '0') : 1;
                if (empty($slug) || empty($title)) {
                    wp_send_json_error('missing_fields');
                }
                $saved_id = $repo->save([
                    'id'                => $id ?: null,
                    'slug'              => $slug,
                    'title'             => $title,
                    'access_expires_at' => $expires ?: null,
                    'event_start_at'    => $event_start ?: null,
                    'event_end_at'      => $event_end ?: null,
                    'is_active'         => $is_active,
                ]);
                if (!$saved_id) wp_send_json_error('save_failed');
                wp_send_json_success(['id' => $saved_id]);
                break;

            case 'delete':
                $id = (int)($_POST['id'] ?? 0);
                if (!$id) wp_send_json_error('missing_id');
                $repo->delete($id);
                wp_send_json_success();
                break;

            case 'duplicate':
                $id        = (int)($_POST['id'] ?? 0);
                $new_slug  = sanitize_title($_POST['new_slug'] ?? '');
                $new_title = sanitize_text_field($_POST['new_title'] ?? '');
                if (!$id || !$new_slug) wp_send_json_error('missing_fields');
                // Expire the original in 30 days
                $orig = $repo->getById($id);
                if ($orig) {
                    $orig['access_expires_at'] = date('Y-m-d H:i:s', strtotime('+30 days'));
                    if (!$repo->save($orig)) {
                        wp_send_json_error('expire_original_failed');
                    }
                }
                $new_id = $repo->duplicate($id, $new_slug, $new_title ?: null);
                if (!$new_id) wp_send_json_error('duplicate_failed');
                wp_send_json_success(['id' => $new_id]);
                break;

            case 'get_section':
                $section_id = (int)($_POST['section_id'] ?? 0);
                if (!$section_id) wp_send_json_error('missing_section_id');
                $section = $repo->getSectionById($section_id);
                if (!$section) wp_send_json_error('not_found');
                wp_send_json_success($section);
                break;

            case 'save_section':
                $section_id  = (int)($_POST['section_id'] ?? 0);
                $congress_id = (int)($_POST['congress_id'] ?? 0);
                $page_id     = (int)($_POST['page_id'] ?? 0);
                $type        = sanitize_key($_POST['type'] ?? 'info');
                $title       = sanitize_text_field($_POST['title'] ?? '');
                $password    = sanitize_text_field($_POST['password'] ?? '');
                $sort_order  = (int)($_POST['sort_order'] ?? 0);
                $raw_content = stripslashes($_POST['content'] ?? '{}');
                if (!$congress_id) wp_send_json_error('missing_congress_id');
                // New section with no page given → drop it on the start page.
                if (!$page_id) {
                    $start = $repo->getStartPage($congress_id);
                    $page_id = $start ? (int)$start['id'] : 0;
                }
                $content = $this->sanitizeSectionContent($type, $raw_content);
                $sid = $repo->saveSection([
                    'id'          => $section_id ?: null,
                    'congress_id' => $congress_id,
                    'page_id'     => $page_id,
                    'type'        => $type,
                    'title'       => $title,
                    'password'    => $password,
                    'sort_order'  => $sort_order,
                    'content'     => $content,
                ]);
                wp_send_json_success(['id' => $sid]);
                break;

            case 'delete_section':
                $section_id = (int)($_POST['section_id'] ?? 0);
                if (!$section_id) wp_send_json_error('missing_section_id');
                $repo->deleteSection($section_id);
                wp_send_json_success();
                break;

            case 'reorder_sections':
                $congress_id = (int)($_POST['congress_id'] ?? 0);
                $ordered_ids = array_map('intval', (array)($_POST['ordered_ids'] ?? []));
                if (!$congress_id) wp_send_json_error('missing_congress_id');
                $repo->reorderSections($congress_id, $ordered_ids);
                wp_send_json_success();
                break;

            // ── Pages ──
            case 'get_pages':
                $congress_id = (int)($_POST['congress_id'] ?? 0);
                if (!$congress_id) wp_send_json_error('missing_congress_id');
                // Each page with its sections, so the admin editor can render the whole tree.
                $pages = $repo->getPages($congress_id);
                foreach ($pages as &$p) {
                    $p['sections'] = $repo->getSectionsForPage((int)$p['id']);
                }
                unset($p);
                wp_send_json_success(['pages' => $pages]);
                break;

            case 'save_page':
                $congress_id = (int)($_POST['congress_id'] ?? 0);
                $page_id     = (int)($_POST['page_id'] ?? 0);
                $title       = sanitize_text_field($_POST['title'] ?? '');
                if (!$congress_id) wp_send_json_error('missing_congress_id');
                $pid = $repo->savePage([
                    'id'          => $page_id ?: null,
                    'congress_id' => $congress_id,
                    'title'       => $title,
                ]);
                wp_send_json_success(['id' => $pid]);
                break;

            case 'delete_page':
                $page_id = (int)($_POST['page_id'] ?? 0);
                if (!$page_id) wp_send_json_error('missing_page_id');
                // Refuses to delete the last remaining page (returns false).
                if (!$repo->deletePage($page_id)) wp_send_json_error('cannot_delete_last_page');
                wp_send_json_success();
                break;

            case 'reorder_pages':
                $congress_id = (int)($_POST['congress_id'] ?? 0);
                $ordered_ids = array_map('intval', (array)($_POST['ordered_ids'] ?? []));
                if (!$congress_id) wp_send_json_error('missing_congress_id');
                $repo->reorderPages($congress_id, $ordered_ids);
                wp_send_json_success();
                break;

            default:
                wp_send_json_error('unknown_action');
        }
    }

    /**
     * Sanitize section content by type. Strips all JavaScript (wp_kses_post).
     */
    public function sanitizeSectionContent(string $type, string $raw): array {
        $data = json_decode($raw, true) ?? [];
        switch ($type) {
            case 'info':
            case 'custom':
                return ['html' => wp_kses_post($data['html'] ?? '')];

            case 'program':
                $days = [];
                foreach ((array)($data['days'] ?? []) as $day) {
                    $slots = [];
                    foreach ((array)($day['slots'] ?? []) as $slot) {
                        $slots[] = [
                            'time'    => sanitize_text_field($slot['time'] ?? ''),
                            'title'   => sanitize_text_field($slot['title'] ?? ''),
                            'speaker' => sanitize_text_field($slot['speaker'] ?? ''),
                            'room'    => sanitize_text_field($slot['room'] ?? ''),
                        ];
                    }
                    $days[] = ['date' => sanitize_text_field($day['date'] ?? ''), 'slots' => $slots];
                }
                return ['days' => $days];

            case 'download':
                $files = [];
                foreach ((array)($data['files'] ?? []) as $f) {
                    $att_id = (int)($f['attachment_id'] ?? 0);
                    $url    = $att_id ? (wp_get_attachment_url($att_id) ?: '') : esc_url_raw($f['url'] ?? '');
                    if (!$url) continue;
                    $files[] = [
                        'label'         => sanitize_text_field($f['label'] ?? ''),
                        'attachment_id' => $att_id,
                        'filename'      => sanitize_text_field($f['filename'] ?? ''),
                        'url'           => $url,
                        'inline'        => !empty($f['inline']), // true = open in browser, false = force download
                    ];
                }
                return ['files' => $files];

            case 'url':
                $urls = [];
                foreach ((array)($data['urls'] ?? []) as $u) {
                    $href = esc_url_raw($u['url'] ?? '');
                    if (!$href) continue;
                    $urls[] = [
                        'label'    => sanitize_text_field($u['label'] ?? $href),
                        'url'      => $href,
                        'internal' => !empty($u['internal']),
                    ];
                }
                return ['urls' => $urls];

            case 'media':
                $items = [];
                foreach ((array)($data['items'] ?? []) as $item) {
                    $att_id = (int)($item['attachment_id'] ?? 0);
                    $url    = $att_id ? wp_get_attachment_url($att_id) : esc_url_raw($item['url'] ?? '');
                    if (!$url) continue;
                    $items[] = [
                        'attachment_id' => $att_id,
                        'caption'       => sanitize_text_field($item['caption'] ?? ''),
                        'url'           => $url,
                    ];
                }
                return ['items' => $items];

            case 'image':
                $att_id = (int)($data['attachment_id'] ?? 0);
                $url    = $att_id ? (wp_get_attachment_url($att_id) ?: '') : esc_url_raw($data['url'] ?? '');
                $size   = in_array(($data['size'] ?? 'full'), ['full', 'original', 'custom'], true) ? $data['size'] : 'full';
                $unit   = (($data['width_unit'] ?? 'px') === '%') ? '%' : 'px';
                return [
                    'attachment_id' => $att_id,
                    'url'           => $url,
                    'caption'       => sanitize_text_field($data['caption'] ?? ''),
                    'size'          => $size,
                    'width'         => max(0, (int)($data['width'] ?? 0)),
                    'width_unit'    => $unit,
                    'lightbox'      => !empty($data['lightbox']),
                ];

            case 'video':
                // provider: 'oembed' (YouTube/Vimeo etc.) or 'file' (self-hosted MP4 from media library).
                $provider = ($data['provider'] ?? 'oembed') === 'file' ? 'file' : 'oembed';
                $att_id   = (int)($data['attachment_id'] ?? 0);
                if ($provider === 'file') {
                    $url = $att_id ? (wp_get_attachment_url($att_id) ?: '') : esc_url_raw($data['url'] ?? '');
                } else {
                    $url = esc_url_raw($data['url'] ?? '');
                }
                return [
                    'provider'      => $provider,
                    'url'           => $url,
                    'attachment_id' => $att_id,
                    // embed_html is generated server-side at response time (REST prepareContent), never stored.
                ];

            default:
                return [];
        }
    }
}
