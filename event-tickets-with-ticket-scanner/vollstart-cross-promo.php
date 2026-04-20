<?php
/**
 * VOLLSTART Cross-Promotion
 *
 * Zeigt in jedem Vollstart-Plugin eine "More from Vollstart" Sektion
 * mit den anderen Plugins. Nicht-aufdringlich:
 * - Dismissible Admin Notice (einmal pro User, einmal global)
 * - "More Plugins" Submenu-Seite unter jedem Plugin
 *
 * Einbindung: require_once __DIR__ . '/vollstart-cross-promo.php';
 *             vollstart_cross_promo_init('event-tickets-with-ticket-scanner');
 *
 * Alle Daten von wordpress.org API (24h Cache).
 */

if (!defined('ABSPATH')) exit;

if (!function_exists('vollstart_cross_promo_init')):

// Track which plugins called init — used for submenu registration
global $vollstart_cross_promo_callers;
$vollstart_cross_promo_callers = [];

function vollstart_cross_promo_get_plugins() {
    return [
        'event-tickets-with-ticket-scanner' => [
            'name' => 'Event Tickets with Ticket Scanner',
            'short' => 'Sell event tickets in WooCommerce with QR code scanner, PDF tickets, and brute-force protection.',
            'icon' => 'https://ps.w.org/event-tickets-with-ticket-scanner/assets/icon-128x128.png',
            'wporg' => 'https://wordpress.org/plugins/event-tickets-with-ticket-scanner/',
            'menu_slug' => 'event-tickets-with-ticket-scanner',
        ],
        'serial-codes-generator-and-validator' => [
            'name' => 'Serial Codes Generator and Validator',
            'short' => 'Generate and validate serial codes for product authentication, license keys, and anti-counterfeiting.',
            'icon' => 'https://ps.w.org/serial-codes-generator-and-validator/assets/icon-128x128.png',
            'wporg' => 'https://wordpress.org/plugins/serial-codes-generator-and-validator/',
            'menu_slug' => 'sngmbh-serialcodes-validator',
        ],
        'vollstart-appointment-desk' => [
            'name' => 'Appointment Desk',
            'short' => 'Appointment booking with walk-in queue, reception cockpit, and double-booking prevention. Free.',
            'icon' => 'https://ps.w.org/vollstart-appointment-desk/assets/icon-128x128.png',
            'wporg' => 'https://wordpress.org/plugins/vollstart-appointment-desk/',
            'menu_slug' => 'vollstart-appointment-desk',
            'new' => true,
        ],
    ];
}

function vollstart_cross_promo_init($current_slug) {
    if (!is_admin()) return;

    global $vollstart_cross_promo_callers;
    $plugins = vollstart_cross_promo_get_plugins();

    if (!isset($plugins[$current_slug])) return;
    $parent_slug = $plugins[$current_slug]['menu_slug'];

    // Plugins die NICHT installiert sind (für diesen Caller)
    $other_plugins = [];
    foreach ($plugins as $slug => $info) {
        if ($slug !== $current_slug) {
            $other_plugins[$slug] = $info;
        }
    }

    // Submenu pro Plugin registrieren
    add_action('admin_menu', function() use ($parent_slug, $other_plugins) {
        add_submenu_page(
            $parent_slug,
            'More Plugins by Vollstart',
            '★ More Plugins',
            'manage_options',
            $parent_slug . '-more-plugins',
            function() use ($other_plugins) {
                vollstart_render_more_plugins_page($other_plugins);
            }
        );
    }, 99);

    // Admin Notice + AJAX nur EINMAL registrieren (erster Caller)
    $is_first = empty($vollstart_cross_promo_callers);
    $vollstart_cross_promo_callers[] = $current_slug;

    if (!$is_first) return;

    // Admin Notice (einmal global, dismissible)
    add_action('admin_notices', function() {
        $user_id = get_current_user_id();
        if (get_user_meta($user_id, 'vollstart_promo_dismissed', true)) return;

        // Nur auf eigenen Plugin-Seiten zeigen
        $screen = get_current_screen();
        if (!$screen || strpos($screen->id, 'vollstart') === false
            && strpos($screen->id, 'event-tickets') === false
            && strpos($screen->id, 'serial') === false
            && strpos($screen->id, 'appointment') === false) {
            return;
        }

        // Nicht-installierte Plugins als Highlight
        $plugins = vollstart_cross_promo_get_plugins();
        $highlight = null;
        foreach ($plugins as $slug => $info) {
            if (!file_exists(WP_PLUGIN_DIR . '/' . $slug)) {
                if (!empty($info['new']) || !$highlight) {
                    $highlight = $info;
                }
                if (!empty($info['new'])) break;
            }
        }
        if (!$highlight) return; // Alle installiert — keine Notice

        $nonce = wp_create_nonce('vollstart_dismiss_promo');
        ?>
        <div class="notice notice-info is-dismissible vollstart-promo-notice" style="display:flex;align-items:center;gap:16px;padding:12px 16px">
            <img src="<?php echo esc_url($highlight['icon']); ?>" style="width:48px;height:48px;border-radius:8px" alt="">
            <div style="flex:1">
                <strong><?php echo esc_html($highlight['name']); ?></strong>
                <?php if (!empty($highlight['new'])): ?><span style="background:#22c55e;color:#fff;font-size:10px;padding:2px 6px;border-radius:3px;margin-left:6px">NEW</span><?php endif; ?>
                <br>
                <span style="color:#666"><?php echo esc_html($highlight['short']); ?></span>
            </div>
            <a href="<?php echo esc_url($highlight['wporg']); ?>" target="_blank" class="button button-primary" style="white-space:nowrap">Try it free</a>
        </div>
        <script>
        jQuery(function($) {
            $('.vollstart-promo-notice').on('click', '.notice-dismiss', function() {
                $.post(ajaxurl, {action: 'vollstart_dismiss_promo', _wpnonce: '<?php echo $nonce; ?>'});
            });
        });
        </script>
        <?php
    });

    // AJAX Dismiss Handler
    add_action('wp_ajax_vollstart_dismiss_promo', function() {
        check_ajax_referer('vollstart_dismiss_promo');
        update_user_meta(get_current_user_id(), 'vollstart_promo_dismissed', 1);
        wp_send_json_success();
    });
}
endif;


if (!function_exists('vollstart_render_more_plugins_page')):
function vollstart_render_more_plugins_page($plugins) {
    // WP.org API Daten (24h Cache) — always fetch ALL plugins, not just the
    // ones passed to this function, so the cache works across all callers.
    $api_data = get_transient('vollstart_promo_wporg_data');
    if (!$api_data) {
        $api_data = [];
        $all_plugins = vollstart_cross_promo_get_plugins();
        foreach ($all_plugins as $slug => $info) {
            $response = wp_remote_get("https://api.wordpress.org/plugins/info/1.2/?action=plugin_information&slug={$slug}", ['timeout' => 10]);
            if (!is_wp_error($response)) {
                $body = json_decode(wp_remote_retrieve_body($response), true);
                if ($body && !empty($body['name'])) {
                    $api_data[$slug] = [
                        'active_installs' => $body['active_installs'] ?? 0,
                        'rating' => $body['rating'] ?? 0,
                        'num_ratings' => $body['num_ratings'] ?? 0,
                        'downloaded' => $body['downloaded'] ?? 0,
                        'version' => $body['version'] ?? '',
                        'banners' => $body['banners']['low'] ?? '',
                    ];
                }
            }
        }
        set_transient('vollstart_promo_wporg_data', $api_data, DAY_IN_SECONDS);
    }

    echo '<div class="wrap">';
    echo '<h1>More Plugins by Vollstart</h1>';
    echo '<p style="font-size:14px;color:#666;margin-bottom:24px">Built by the same team. Same philosophy: no bloat, no upsell popups, just tools that work.</p>';

    echo '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(380px,1fr));gap:20px">';

    foreach ($plugins as $slug => $info) {
        $api = $api_data[$slug] ?? [];
        $installs = $api['active_installs'] ?? 0;
        $rating = ($api['rating'] ?? 0) / 20; // 0-100 -> 0-5
        $num_ratings = $api['num_ratings'] ?? 0;
        $version = $api['version'] ?? '';
        $banner = $api['banners'] ?? '';
        $is_new = !empty($info['new']);
        $is_installed = file_exists(WP_PLUGIN_DIR . '/' . $slug);

        echo '<div style="background:#fff;border:1px solid #e5e7eb;border-radius:12px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,0.06)">';

        // Banner
        if ($banner) {
            echo '<div style="height:120px;background:url(' . esc_url($banner) . ') center/cover no-repeat"></div>';
        }

        echo '<div style="padding:16px 20px">';

        // Header
        echo '<div style="display:flex;align-items:center;gap:12px;margin-bottom:12px">';
        echo '<img src="' . esc_url($info['icon']) . '" style="width:48px;height:48px;border-radius:8px" alt="">';
        echo '<div>';
        echo '<div style="font-size:16px;font-weight:700">' . esc_html($info['name']) . '</div>';
        if ($is_new) echo '<span style="background:#22c55e;color:#fff;font-size:10px;padding:2px 8px;border-radius:3px">NEW</span> ';
        if ($version) echo '<span style="font-size:11px;color:#999">v' . esc_html($version) . '</span>';
        echo '</div>';
        echo '</div>';

        // Description
        echo '<p style="font-size:13px;color:#555;line-height:1.5;margin-bottom:16px">' . esc_html($info['short']) . '</p>';

        // Stats
        echo '<div style="display:flex;gap:16px;margin-bottom:16px;font-size:12px;color:#888">';
        if ($installs > 0) {
            echo '<span><strong style="color:#333">' . number_format_i18n($installs) . '+</strong> active installs</span>';
        }
        if ($rating > 0) {
            $stars = str_repeat('★', round($rating)) . str_repeat('☆', 5 - round($rating));
            echo '<span style="color:#f59e0b">' . $stars . '</span>';
            echo '<span>(' . $num_ratings . ')</span>';
        }
        echo '</div>';

        // CTA
        echo '<div style="display:flex;gap:8px">';
        if ($is_installed) {
            echo '<span class="button button-secondary" disabled style="opacity:0.7">Already installed</span>';
        } else {
            $install_url = wp_nonce_url(
                admin_url('update.php?action=install-plugin&plugin=' . $slug),
                'install-plugin_' . $slug
            );
            echo '<a href="' . esc_url($install_url) . '" class="button button-primary">Install Free</a>';
        }
        echo '<a href="' . esc_url($info['wporg']) . '" target="_blank" class="button button-secondary">View on WordPress.org</a>';
        echo '</div>';

        echo '</div>';
        echo '</div>';
    }

    echo '</div>';
    echo '</div>';
}
endif;
