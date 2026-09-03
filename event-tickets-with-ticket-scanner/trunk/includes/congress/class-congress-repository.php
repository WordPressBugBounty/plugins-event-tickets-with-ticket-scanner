<?php
if (!defined('ABSPATH')) exit;

class sasoEventtickets_CongressRepository {

    private sasoEventtickets $MAIN;

    public function __construct(sasoEventtickets $main) {
        $this->MAIN = $main;
    }

    // ── Congress CRUD ──────────────────────────────────────────

    public function getAll(): array {
        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}saso_eventtickets_congresses ORDER BY title ASC",
            ARRAY_A
        );
        return $rows ?: [];
    }

    public function getById(int $id): ?array {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}saso_eventtickets_congresses WHERE id = %d",
            $id
        ), ARRAY_A);
        return $row ?: null;
    }

    /** Per-portal label, falling back to the global default option. */
    public function resolveLabel(?array $congress): string {
        $own = trim((string)($congress['label'] ?? ''));
        if ($own !== '') return $own;
        return (string) $this->MAIN->getOptions()->getOptionValue('congressDefaultLabel');
    }

    public function getBySlug(string $slug): ?array {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}saso_eventtickets_congresses WHERE slug = %s",
            $slug
        ), ARRAY_A);
        return $row ?: null;
    }

    public function getForProduct(int $product_id): ?array {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT c.* FROM {$wpdb->prefix}saso_eventtickets_congresses c
             JOIN {$wpdb->prefix}saso_eventtickets_congress_products cp ON c.id = cp.congress_id
             WHERE cp.product_id = %d",
            $product_id
        ), ARRAY_A);
        return $row ?: null;
    }

    /**
     * Single source for building the public congress URL.
     * Always anchored on home_url() so it adapts to the site location
     * (subdirectory installs etc.) — same self-adjusting behaviour as the
     * other plugin URLs. Pass an empty $ticket_code to get the bare pattern.
     */
    /**
     * Public congress URL for a ticket — reuses the ticket URL with a "congress" marker
     * (like ?pdf / ?ics), so it inherits the ticket routing entirely: path-based
     * (…/ticket/{TICKETID}?congress) by default, ?code=…&congress in compatibility mode.
     * Path-based is primary so the ticket id survives proxies that strip query params.
     * The congress is derived from the ticket's product — no slug in the URL.
     */
    public function getUrl(string $public_ticket_id): string {
        $base = $this->MAIN->getCore()->getTicketURLBase();
        if ($this->MAIN->getOptions()->isOptionCheckboxActive('wcTicketCompatibilityMode')) {
            $url = $base . '?code=' . urlencode($public_ticket_id) . '&congress';
        } else {
            $url = $base . $public_ticket_id . '?congress';
        }
        return apply_filters($this->MAIN->_add_filter_prefix . 'core_getCongressURL', $url, $public_ticket_id);
    }

    /**
     * Resolve the congress for a ticket and verify access (order status, expiry, event
     * window, product linkage). Returns the congress array or null. Accepts the raw code
     * or the public ticket id.
     */
    public function getForTicket(string $ticket_id): ?array {
        $a = $this->getAvailability($ticket_id);
        return $a['available'] ? $a['congress'] : null;
    }

    /**
     * Single source of truth for "may this ticket open its congress right now, and when".
     * Considers: master option, per-congress is_active, order status, access_expires_at,
     * event window (hoursBefore/daysAfter). The expensive ticket→congress DB resolution is
     * cached 5 min; the gating (option/flag/time) is evaluated live on every call.
     *
     * @return array{congress: array|null, available: bool, reason: string,
     *               available_from: int|null, available_until: int|null, url: string|null}
     */
    public function getAvailability(string $public_ticket_id, ?array $codeObj = null): array {
        $none = ['congress' => null, 'available' => false, 'reason' => 'none',
                 'available_from' => null, 'available_until' => null, 'url' => null];
        if (empty($public_ticket_id)) return $none;

        // Cached: congress_id, order_id, product_id.
        $cache_key = 'congress_resolve_' . md5($public_ticket_id);
        $resolved  = get_transient($cache_key);
        if ($resolved === false) {
            $resolved = $this->resolveTicketCongress($public_ticket_id);
            set_transient($cache_key, $resolved ?: 0, 300);
        }
        if (!$resolved || empty($resolved['congress_id'])) return $none;

        $congress = $this->getById((int) $resolved['congress_id']);
        if (!$congress) return $none;

        $out = ['congress' => $congress, 'available' => false, 'reason' => 'ok',
                'available_from' => null, 'available_until' => null,
                'url' => $this->getUrl($public_ticket_id)];

        $opts = $this->MAIN->getOptions();
        if (!$opts->isOptionCheckboxActive('congressModeActive')) {
            $out['reason'] = 'mode_off'; return $out;
        }
        $hours_before = (int) $opts->getOptionValue('congressAccessHoursBefore');
        $days_after   = (int) $opts->getOptionValue('congressRetentionDaysAfter');
        if ((int) ($congress['is_active'] ?? 1) !== 1) {
            $out['reason'] = 'inactive'; return $out;
        }
        $order = function_exists('wc_get_order') ? wc_get_order((int) $resolved['order_id']) : null;
        if ($order && !in_array($order->get_status(), ['completed', 'processing'], true)) {
            $out['reason'] = 'order'; return $out;
        }

        $now = time();

        $event_start_ts = null; $event_end_ts = null;
        $product_id = (int) ($resolved['product_id'] ?? 0);
        if ($product_id > 0) {
            try {
                $dates = $this->MAIN->getTicketHandler()->calcDateStringAllowedRedeemFrom($product_id, $codeObj);
                if (!empty($dates['is_date_set'])) {
                    $event_start_ts = $dates['ticket_start_date_timestamp'] ?? null;
                    $event_end_ts   = $dates['ticket_end_date_timestamp'] ?? null;
                }
            } catch (\Throwable $e) { /* no event date → no time gating */ }
        }

        if ($hours_before > 0 && $event_start_ts) {
            $out['available_from'] = $event_start_ts - ($hours_before * HOUR_IN_SECONDS);
        }
        $until = null;
        if ($days_after > 0 && $event_end_ts) {
            $until = $event_end_ts + ($days_after * DAY_IN_SECONDS);
        }
        if (!empty($congress['access_expires_at'])) {
            $exp   = strtotime($congress['access_expires_at']);
            $until = ($until === null) ? $exp : min($until, $exp);
        }
        $out['available_until'] = $until;

        if (!empty($congress['access_expires_at']) && strtotime($congress['access_expires_at']) < $now) {
            $out['reason'] = 'expired'; return $out;
        }
        if ($out['available_from'] !== null && $now < $out['available_from']) {
            $out['reason'] = 'too_early'; return $out;
        }
        if ($until !== null && $now > $until) {
            $out['reason'] = 'too_late'; return $out;
        }

        $out['available'] = true;
        $out['reason']    = 'ok';
        return $out;
    }

    /** Resolve a ticket id (code or public id) to its assigned congress + order. */
    private function resolveTicketCongress(string $ticket_id): array {
        global $wpdb;
        $code = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}saso_eventtickets_codes
             WHERE (code = %s OR JSON_UNQUOTE(JSON_EXTRACT(meta, '$.wc_ticket._public_ticket_id')) = %s)
             AND aktiv = 1",
            $ticket_id, $ticket_id
        ), ARRAY_A);
        if (!$code) return [];
        $order_id = (int) ($code['order_id'] ?? 0);
        if (!$order_id || !function_exists('wc_get_order')) return [];
        $order = wc_get_order($order_id);
        if (!$order) return [];
        foreach ($order->get_items() as $item) {
            $product_id = (int) $item->get_product_id();
            $c = $this->getForProduct($product_id);
            if ($c) return ['congress_id' => (int) $c['id'], 'order_id' => $order_id, 'product_id' => $product_id];
        }
        return [];
    }

    /** Resolve a ticket id (raw code or public id) to its codeObj (metaObj filled), or null. */
    public function getCodeObjForTicket(string $ticket_id): ?array {
        global $wpdb;
        if ($ticket_id === '') return null;
        $code = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}saso_eventtickets_codes
             WHERE (code = %s OR JSON_UNQUOTE(JSON_EXTRACT(meta, '$.wc_ticket._public_ticket_id')) = %s)
             AND aktiv = 1",
            $ticket_id, $ticket_id
        ), ARRAY_A);
        if (!$code) return null;
        return $this->MAIN->getCore()->setMetaObj($code);
    }

    public function save(array $data): int {
        global $wpdb;
        $now = current_time('mysql');
        // Preserve existing meta JSON on update when the caller doesn't pass 'meta'
        // (the admin 'save' handler doesn't send it — without this, meta like parent_id would be wiped).
        if (array_key_exists('meta', $data)) {
            $meta = is_array($data['meta']) ? $data['meta'] : (json_decode((string)$data['meta'], true) ?: []);
        } elseif (!empty($data['id'])) {
            $existing = $this->getById((int)$data['id']);
            $meta = ($existing && !empty($existing['meta'])) ? (json_decode($existing['meta'], true) ?: []) : [];
        } else {
            $meta = [];
        }
        if (!array_key_exists('is_active', $data) && !empty($data['id'])) {
            $existingRow = $this->getById((int) $data['id']);
            $data['is_active'] = $existingRow ? (int) $existingRow['is_active'] : 1;
        }
        // Preserve existing label on partial update (mirrors meta-preservation pattern above).
        if (array_key_exists('label', $data)) {
            $label = sanitize_text_field($data['label']);
        } elseif (!empty($data['id'])) {
            $existingForLabel = $this->getById((int)$data['id']);
            $label = $existingForLabel ? (string)($existingForLabel['label'] ?? '') : '';
        } else {
            $label = '';
        }
        $row = [
            'slug'              => sanitize_title($data['slug'] ?? ''),
            'title'             => sanitize_text_field($data['title'] ?? ''),
            'access_expires_at' => !empty($data['access_expires_at']) ? sanitize_text_field($data['access_expires_at']) : null,
            'event_start_at'    => !empty($data['event_start_at']) ? sanitize_text_field($data['event_start_at']) : null,
            'event_end_at'      => !empty($data['event_end_at']) ? sanitize_text_field($data['event_end_at']) : null,
            'is_active'         => array_key_exists('is_active', $data) ? (int) (!empty($data['is_active'])) : 1,
            'label'             => $label,
            'updated_at'        => $now,
            'meta'              => wp_json_encode($meta),
        ];
        $fmt = ['%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s'];
        if (empty($row['slug'])) {
            return 0;
        }
        if (!empty($data['id'])) {
            $wpdb->update(
                "{$wpdb->prefix}saso_eventtickets_congresses",
                $row,
                ['id' => (int)$data['id']],
                $fmt,
                ['%d']
            );
            return (int)$data['id'];
        }
        $wpdb->insert("{$wpdb->prefix}saso_eventtickets_congresses", $row, $fmt);
        return (int)$wpdb->insert_id;
    }

    public function touch(int $id): void {
        global $wpdb;
        $wpdb->update(
            "{$wpdb->prefix}saso_eventtickets_congresses",
            ['updated_at' => current_time('mysql')],
            ['id' => $id],
            ['%s'],
            ['%d']
        );
    }

    public function delete(int $id): void {
        global $wpdb;
        $wpdb->delete("{$wpdb->prefix}saso_eventtickets_congress_sections", ['congress_id' => $id], ['%d']);
        $wpdb->delete("{$wpdb->prefix}saso_eventtickets_congress_pages", ['congress_id' => $id], ['%d']);
        $wpdb->delete("{$wpdb->prefix}saso_eventtickets_congress_products", ['congress_id' => $id], ['%d']);
        $wpdb->delete("{$wpdb->prefix}saso_eventtickets_congresses", ['id' => $id], ['%d']);
    }

    public function duplicate(int $id, string $new_slug, ?string $new_title = null): int {
        $orig = $this->getById($id);
        if (!$orig) return 0;
        // Carry the parent/lineage link in the child's meta JSON (no schema column).
        $meta = json_decode($orig['meta'] ?: '[]', true) ?: [];
        $meta['parent_id'] = (int)$id;                                   // direct source edition
        $meta['root_id']   = isset($meta['root_id']) ? (int)$meta['root_id'] : (int)$id; // original of the chain
        $new_id = $this->save([
            'slug'              => $new_slug,
            'title'             => $new_title ?? $orig['title'] . ' (Kopie)',
            'access_expires_at' => $orig['access_expires_at'] ?? null,
            'label'             => $orig['label'] ?? '',
            'meta'              => $meta,
        ]);
        // Copy pages first, mapping old page id → new page id, so sections keep their page.
        $page_map = [];
        foreach ($this->getPages($id) as $p) {
            $old_page_id = (int)$p['id'];
            $new_page_id = $this->savePage([
                'congress_id' => $new_id,
                'title'       => $p['title'],
            ]);
            $page_map[$old_page_id] = $new_page_id;
        }
        foreach ($this->getSections($id) as $s) {
            $old_page_id = (int)($s['page_id'] ?? 0);
            unset($s['id']);
            $s['congress_id'] = $new_id;
            $s['page_id']     = $page_map[$old_page_id] ?? 0;
            $this->saveSection($s);
        }
        $product_ids = $this->getProductIds($id);
        if ($product_ids) {
            $this->setProducts($new_id, $product_ids);
        }
        return $new_id;
    }

    // ── Products ──────────────────────────────────────────────

    public function getProductIds(int $congress_id): array {
        global $wpdb;
        return $wpdb->get_col($wpdb->prepare(
            "SELECT product_id FROM {$wpdb->prefix}saso_eventtickets_congress_products WHERE congress_id = %d",
            $congress_id
        )) ?: [];
    }

    public function setProducts(int $congress_id, array $product_ids): void {
        global $wpdb;
        $wpdb->delete("{$wpdb->prefix}saso_eventtickets_congress_products", ['congress_id' => $congress_id], ['%d']);
        foreach (array_map('intval', $product_ids) as $pid) {
            if ($pid <= 0) continue;
            $wpdb->insert(
                "{$wpdb->prefix}saso_eventtickets_congress_products",
                ['congress_id' => $congress_id, 'product_id' => $pid],
                ['%d', '%d']
            );
        }
    }

    // ── Sections ──────────────────────────────────────────────

    public function getSections(int $congress_id): array {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}saso_eventtickets_congress_sections
             WHERE congress_id = %d ORDER BY sort_order ASC",
            $congress_id
        ), ARRAY_A);
        return $rows ?: [];
    }

    public function getSectionById(int $id): ?array {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}saso_eventtickets_congress_sections WHERE id = %d",
            $id
        ), ARRAY_A);
        return $row ?: null;
    }

    public function saveSection(array $data): int {
        global $wpdb;
        $content = is_array($data['content']) ? wp_json_encode($data['content']) : ($data['content'] ?? '');
        $password_hash = null;
        if (!empty($data['password'])) {
            $password_hash = wp_hash_password($data['password']);
        } elseif (isset($data['password_hash'])) {
            $password_hash = $data['password_hash'];
        }
        $now     = current_time('mysql');
        $user_id = get_current_user_id();
        $page_id = (int)($data['page_id'] ?? 0);
        // Moving a section to another page → append it to the end of the target page.
        if (!empty($data['id']) && $page_id > 0) {
            $existing = $this->getSectionById((int)$data['id']);
            if ($existing && (int)$existing['page_id'] !== $page_id) {
                $max = (int)$wpdb->get_var($wpdb->prepare(
                    "SELECT MAX(sort_order) FROM {$wpdb->prefix}saso_eventtickets_congress_sections WHERE page_id = %d",
                    $page_id
                ));
                $data['sort_order'] = $max + 1;
            }
        }
        $row = [
            'congress_id'        => (int)($data['congress_id'] ?? 0),
            'page_id'            => $page_id,
            'type'               => sanitize_key($data['type'] ?? 'info'),
            'title'              => sanitize_text_field($data['title'] ?? ''),
            'password_hash'      => $password_hash,
            'sort_order'         => (int)($data['sort_order'] ?? 0),
            'content'            => $content,
            'updated_at'         => $now,
            'updated_by_user_id' => $user_id,
        ];
        $fmt = ['%d', '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%d'];
        if (!empty($data['id'])) {
            $wpdb->update(
                "{$wpdb->prefix}saso_eventtickets_congress_sections",
                $row,
                ['id' => (int)$data['id']],
                $fmt,
                ['%d']
            );
            $this->touch((int)($data['congress_id'] ?? 0));
            return (int)$data['id'];
        }
        $row['created_at']          = $now;
        $row['created_by_user_id']  = $user_id;
        $fmt[] = '%s';
        $fmt[] = '%d';
        $wpdb->insert("{$wpdb->prefix}saso_eventtickets_congress_sections", $row, $fmt);
        $this->touch((int)($data['congress_id'] ?? 0));
        return (int)$wpdb->insert_id;
    }

    public function deleteSection(int $id): void {
        global $wpdb;
        $section = $this->getSectionById($id);
        $wpdb->delete("{$wpdb->prefix}saso_eventtickets_congress_sections", ['id' => $id], ['%d']);
        if ($section) $this->touch((int)$section['congress_id']);
    }

    public function reorderSections(int $congress_id, array $ordered_ids): void {
        global $wpdb;
        foreach ($ordered_ids as $pos => $section_id) {
            $wpdb->update(
                "{$wpdb->prefix}saso_eventtickets_congress_sections",
                ['sort_order' => $pos],
                ['id' => (int)$section_id, 'congress_id' => $congress_id],
                ['%d'],
                ['%d', '%d']
            );
        }
        $this->touch($congress_id);
    }

    // ── Pages ─────────────────────────────────────────────────

    /** All pages of a congress, ordered. First (smallest sort_order) = start page. */
    public function getPages(int $congress_id): array {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}saso_eventtickets_congress_pages
             WHERE congress_id = %d ORDER BY sort_order ASC, id ASC",
            $congress_id
        ), ARRAY_A);
        return $rows ?: [];
    }

    public function getPageById(int $id): ?array {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}saso_eventtickets_congress_pages WHERE id = %d",
            $id
        ), ARRAY_A);
        return $row ?: null;
    }

    /** Start page = the page with the smallest sort_order. */
    public function getStartPage(int $congress_id): ?array {
        $pages = $this->getPages($congress_id);
        return $pages[0] ?? null;
    }

    /** Page display meta, read-with-defaults (old rows may have no/empty/foreign meta). */
    public function getPageMeta(?array $page): array {
        $defaults = ['icon' => '', 'image_id' => 0, 'description' => '', 'color' => ''];
        $decoded  = json_decode((string)($page['meta'] ?? ''), true);
        $meta     = array_merge($defaults, is_array($decoded) ? $decoded : []);
        return [
            'icon'        => (string) $meta['icon'],
            'image_id'    => (int) $meta['image_id'],
            'description' => (string) $meta['description'],
            'color'       => (string) $meta['color'],
        ];
    }

    /** Congress behaviour meta, read-with-defaults. */
    public function getCongressMeta(?array $congress): array {
        $defaults = ['landing_cards' => false];
        $decoded  = json_decode((string)($congress['meta'] ?? ''), true);
        $meta     = array_merge($defaults, is_array($decoded) ? $decoded : []);
        return ['landing_cards' => !empty($meta['landing_cards'])];
    }

    public function savePage(array $data): int {
        global $wpdb;
        $now     = current_time('mysql');
        $user_id = get_current_user_id();
        $table   = "{$wpdb->prefix}saso_eventtickets_congress_pages";
        $congress_id = (int)($data['congress_id'] ?? 0);

        // Merge only the page-meta keys the caller actually sent (merge-on-write).
        $mergePageMeta = function(?array $existingRow = null) use ($data) {
            $decoded = $existingRow ? (json_decode((string)($existingRow['meta'] ?? ''), true) ?: []) : [];
            if (array_key_exists('icon', $data))        $decoded['icon']        = sanitize_text_field($data['icon']);
            if (array_key_exists('image_id', $data))    $decoded['image_id']    = (int) $data['image_id'];
            if (array_key_exists('description', $data)) $decoded['description'] = sanitize_text_field($data['description']);
            if (array_key_exists('color', $data))       $decoded['color']       = sanitize_hex_color($data['color']) ?: '';
            return wp_json_encode($decoded);
        };

        if (!empty($data['id'])) {
            $existing = $this->getPageById((int)$data['id']);
            $wpdb->update($table, [
                'title'              => sanitize_text_field($data['title'] ?? ''),
                'meta'               => $mergePageMeta($existing),
                'updated_at'         => $now,
                'updated_by_user_id' => $user_id,
            ], ['id' => (int)$data['id']], ['%s', '%s', '%s', '%d'], ['%d']);
            $this->touch($congress_id);
            return (int)$data['id'];
        }
        // New page → append to the end.
        $max = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT MAX(sort_order) FROM {$table} WHERE congress_id = %d", $congress_id
        ));
        $wpdb->insert($table, [
            'congress_id'        => $congress_id,
            'title'              => sanitize_text_field($data['title'] ?? ''),
            'sort_order'         => $max + 1,
            'meta'               => $mergePageMeta(null),
            'created_at'         => $now,
            'updated_at'         => $now,
            'created_by_user_id' => $user_id,
            'updated_by_user_id' => $user_id,
        ], ['%d', '%s', '%d', '%s', '%s', '%s', '%d', '%d']);
        $this->touch($congress_id);
        return (int)$wpdb->insert_id;
    }

    /**
     * Delete a page. Its sections are moved to the start page (if another page exists),
     * otherwise deleted with the page. The last remaining page cannot be deleted.
     */
    public function deletePage(int $id): bool {
        global $wpdb;
        $page = $this->getPageById($id);
        if (!$page) return false;
        $congress_id = (int)$page['congress_id'];
        $pages = $this->getPages($congress_id);
        if (count($pages) <= 1) return false; // never delete the only page

        $t_sec = "{$wpdb->prefix}saso_eventtickets_congress_sections";
        // Target = first page that is not the one being deleted.
        $target = null;
        foreach ($pages as $p) {
            if ((int)$p['id'] !== $id) { $target = (int)$p['id']; break; }
        }
        if ($target) {
            $max = (int)$wpdb->get_var($wpdb->prepare(
                "SELECT MAX(sort_order) FROM {$t_sec} WHERE page_id = %d", $target
            ));
            // Re-sequence the moved sections onto the end of the target page.
            $moving = $wpdb->get_col($wpdb->prepare(
                "SELECT id FROM {$t_sec} WHERE page_id = %d ORDER BY sort_order ASC", $id
            ));
            foreach ($moving as $sid) {
                $max++;
                $wpdb->update($t_sec, ['page_id' => $target, 'sort_order' => $max], ['id' => (int)$sid], ['%d', '%d'], ['%d']);
            }
        }
        $wpdb->delete("{$wpdb->prefix}saso_eventtickets_congress_pages", ['id' => $id], ['%d']);
        $this->touch($congress_id);
        return true;
    }

    public function reorderPages(int $congress_id, array $ordered_ids): void {
        global $wpdb;
        foreach ($ordered_ids as $pos => $page_id) {
            $wpdb->update(
                "{$wpdb->prefix}saso_eventtickets_congress_pages",
                ['sort_order' => $pos],
                ['id' => (int)$page_id, 'congress_id' => $congress_id],
                ['%d'],
                ['%d', '%d']
            );
        }
        $this->touch($congress_id);
    }

    /** Sections of one page, ordered. */
    public function getSectionsForPage(int $page_id): array {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}saso_eventtickets_congress_sections
             WHERE page_id = %d ORDER BY sort_order ASC",
            $page_id
        ), ARRAY_A);
        return $rows ?: [];
    }

    // ── Access Check ──────────────────────────────────────────

    /**
     * Returns congress array if ticket has valid access, null otherwise.
     * Checks: ticket exists in codes table, product linked to congress,
     * order not refunded/cancelled. Result cached 5 min.
     *
     * Security note: Ticket-ID = Bearer Token, shareable by design (documented decision 2026-05-30).
     */
    public function checkAccess(string $slug, string $ticket_id): ?array {
        if (empty($slug) || empty($ticket_id)) return null;
        $a = $this->getAvailability($ticket_id);
        if (!$a['available'] || empty($a['congress'])) return null;
        // The page/API addresses a specific congress by slug — must match.
        if (strval($a['congress']['slug']) !== strval($slug)) return null;
        return $a['congress'];
    }
}
