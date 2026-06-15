/* Congress frontend — fetches the congress from the REST API and builds the UI in the
 * browser (PHP only ships a minimal shell). Sidebar (desktop) + hamburger drawer (mobile)
 * navigation over pages; each page's sections are lazy-loaded on click and cached in JS. */
(function () {
    'use strict';
    var cfg = window.sasoEtCongressFrontend;
    if (!cfg) return;
    var __ = (window.wp && wp.i18n && wp.i18n.__) ? wp.i18n.__ : function (s) { return s; };
    var TD = 'event-tickets-with-ticket-scanner';

    var app = document.getElementById('congress-app');
    if (!app) return;

    var pageCache = {};   // page_id -> sections array (loaded once)
    var currentPageId = 0;
    var navListEl = null; // <ul> of page links
    var contentEl = null; // page content container
    var bootData = null;
    var landingCards = false;
    var backBtn = null;   // back-to-home control (created in buildShell)
    var portalExtras = []; // synthetic nav entries {key,order,label,render} (not REST pages)
    var extrasByKey = {};  // key -> entry, for the click handler
    var activeExtraKey = null; // key of the currently-shown extra (null when a page is shown)

    function el(tag, cls, text) {
        var e = document.createElement(tag);
        if (cls) e.className = cls;
        if (text != null) e.textContent = text;
        return e;
    }

    // Defense-in-depth: only allow safe URL schemes (server already runs esc_url_raw on save).
    function safeUrl(u) {
        if (typeof u !== 'string' || u === '') return '';
        try {
            var p = new URL(u, window.location.origin);
            return /^(https?:|mailto:|tel:)$/.test(p.protocol) ? p.href : '';
        } catch (e) { return ''; }
    }

    // Minimal self-contained lightbox: full-size image overlay, click/Esc to close.
    function openLightbox(url, caption) {
        if (!url) return;
        var overlay = el('div', 'congress-lightbox');
        var img = el('img'); img.src = url; if (caption) img.alt = caption;
        var close = el('button', 'congress-lightbox-close'); close.setAttribute('aria-label', __('Close', TD)); close.textContent = '×';
        overlay.appendChild(close);
        overlay.appendChild(img);
        if (caption) overlay.appendChild(el('div', 'congress-lightbox-caption', caption));
        function destroy() {
            document.removeEventListener('keydown', onKey);
            if (overlay.parentNode) overlay.parentNode.removeChild(overlay);
        }
        function onKey(e) { if (e.key === 'Escape') destroy(); }
        // Click anywhere (overlay or close) closes; clicking the image itself does not.
        overlay.addEventListener('click', function (e) { if (e.target !== img) destroy(); });
        document.addEventListener('keydown', onKey);
        document.body.appendChild(overlay);
    }

    // ── Section renderers ────────────────────────────────────
    function renderContent(section) {
        var c = section.content || {};
        var frag = document.createDocumentFragment();

        if (section.type === 'info' || section.type === 'custom') {
            var d = el('div', 'section-html');
            d.innerHTML = c.html || ''; // server-sanitized via wp_kses_post in the REST API
            frag.appendChild(d);
        } else if (section.type === 'program') {
            (c.days || []).forEach(function (day) {
                var dd = el('div', 'program-day');
                dd.appendChild(el('h3', null, day.date || ''));
                var table = el('table', 'program-table');
                var thead = el('thead'), htr = el('tr');
                [__('Time', TD), __('Title', TD), __('Speaker', TD), __('Room', TD)].forEach(function (x) {
                    htr.appendChild(el('th', null, x));
                });
                thead.appendChild(htr); table.appendChild(thead);
                var tbody = el('tbody');
                (day.slots || []).forEach(function (s) {
                    var tr = el('tr');
                    [s.time, s.title, s.speaker, s.room].forEach(function (v) { tr.appendChild(el('td', null, v || '')); });
                    tbody.appendChild(tr);
                });
                table.appendChild(tbody);
                dd.appendChild(table);
                frag.appendChild(dd);
            });
        } else if (section.type === 'download') {
            var ulD = el('ul', 'download-list');
            (c.files || []).forEach(function (f) {
                var li = el('li');
                var a = el('a', null, f.label || f.filename || f.url || '');
                a.href = safeUrl(f.url) || '#'; a.target = '_blank';
                // inline=true → open in browser (no download attr); else force download.
                if (!f.inline) a.setAttribute('download', '');
                li.appendChild(a); ulD.appendChild(li);
            });
            frag.appendChild(ulD);
        } else if (section.type === 'url') {
            var ulU = el('ul', 'url-list');
            (c.urls || []).forEach(function (u) {
                var li = el('li');
                var a = el('a', null, u.label || u.url || '');
                a.href = safeUrl(u.url) || '#';
                if (!u.internal) { a.target = '_blank'; a.rel = 'noopener noreferrer'; }
                li.appendChild(a); ulU.appendChild(li);
            });
            frag.appendChild(ulU);
        } else if (section.type === 'media') {
            var g = el('div', 'media-gallery');
            (c.items || []).forEach(function (it) {
                var fig = el('figure');
                var img = el('img'); img.src = safeUrl(it.url); img.loading = 'lazy';
                fig.appendChild(img);
                if (it.caption) fig.appendChild(el('figcaption', null, it.caption));
                g.appendChild(fig);
            });
            frag.appendChild(g);
        } else if (section.type === 'image') {
            var figI = el('figure', 'section-image');
            var imgI = el('img'); imgI.src = safeUrl(c.url); imgI.loading = 'lazy';
            if (c.caption) imgI.alt = c.caption;
            // Display size: full (default, 100%), original (natural, capped), or custom width.
            imgI.style.maxWidth = '100%';
            if (c.size === 'original') {
                imgI.style.width = 'auto';
            } else if (c.size === 'custom' && c.width > 0) {
                imgI.style.width = c.width + (c.width_unit === '%' ? '%' : 'px');
            } else {
                imgI.style.width = '100%'; // full
            }
            // Lightbox: clicking the image opens it full-size in an overlay.
            if (c.lightbox) {
                imgI.classList.add('is-zoomable');
                imgI.addEventListener('click', function () { openLightbox(safeUrl(c.url), c.caption); });
            }
            figI.appendChild(imgI);
            if (c.caption) figI.appendChild(el('figcaption', null, c.caption));
            frag.appendChild(figI);
        } else if (section.type === 'speakers') {
            var sp = (c && c.speakers) || [];
            // Build the full content of one speaker (image, name, title, bio as plain text).
            function _speakerFull(spk, cls) {
                var box = el('div', cls || 'congress-speaker-full');
                var url = safeUrl(spk.image_url);
                if (url) {
                    var im = el('img', 'congress-speaker-photo'); im.src = url; im.loading = 'lazy'; im.alt = spk.name || '';
                    box.appendChild(im);
                }
                if (spk.name)  box.appendChild(el('h3', 'congress-speaker-name', spk.name));
                if (spk.title) box.appendChild(el('p', 'congress-speaker-role', spk.title));
                if (spk.bio) {
                    // bio is PLAIN text — use textContent (el sets textContent), preserve line breaks via CSS.
                    box.appendChild(el('p', 'congress-speaker-bio', spk.bio));
                }
                return box;
            }
            if (!sp.length) {
                frag.appendChild(el('p', 'congress-empty', __('No speakers yet.', TD)));
            } else if (sp.length === 1) {
                frag.appendChild(_speakerFull(sp[0]));
            } else {
                // >1 → grid of tappable boxes; clicking one swaps the container to a detail view
                // with a Back button that returns to the boxes. Local view-swap within wrapEl.
                var wrapEl = el('div', 'congress-speakers-wrap');
                function renderBoxes() {
                    wrapEl.innerHTML = '';
                    var grid = el('div', 'congress-speakers-grid');
                    sp.forEach(function (spk, i) {
                        var card = el('button', 'congress-speaker-card');
                        card.setAttribute('data-idx', i);
                        var url = safeUrl(spk.image_url);
                        if (url) {
                            var th = el('img', 'congress-speaker-thumb'); th.src = url; th.loading = 'lazy'; th.alt = spk.name || '';
                            card.appendChild(th);
                        }
                        if (spk.name)  card.appendChild(el('span', 'congress-speaker-card-name', spk.name));
                        if (spk.title) card.appendChild(el('span', 'congress-speaker-card-role', spk.title));
                        card.addEventListener('click', function () { renderDetail(i); });
                        grid.appendChild(card);
                    });
                    wrapEl.appendChild(grid);
                }
                function renderDetail(idx) {
                    wrapEl.innerHTML = '';
                    var back = el('button', 'congress-speaker-back', '← ' + __('Back', TD));
                    back.addEventListener('click', function () { renderBoxes(); });
                    wrapEl.appendChild(back);
                    wrapEl.appendChild(_speakerFull(sp[idx], 'congress-speaker-detail'));
                }
                renderBoxes();
                frag.appendChild(wrapEl);
            }
        } else if (section.type === 'video') {
            var wrap = el('div', 'section-video');
            if (c.provider === 'oembed' && c.embed_html) {
                var resp = el('div', 'video-embed');
                resp.innerHTML = c.embed_html; // server-generated via wp_oembed_get
                wrap.appendChild(resp);
            } else if (c.provider === 'file' && safeUrl(c.url)) {
                var v = el('video'); v.controls = true; v.preload = 'metadata';
                v.src = safeUrl(c.url); v.style.width = '100%';
                wrap.appendChild(v);
            }
            frag.appendChild(wrap);
        }
        return frag;
    }

    // Locked section: password box; on success, replace it with the unlocked content in place.
    function renderLocked(section) {
        var box = el('div', 'section-locked');
        box.appendChild(el('p', 'lock-notice', __('This section is password-protected.', TD)));
        var input = el('input', 'section-pw-input'); input.type = 'password'; input.placeholder = __('Password', TD);
        var btn = el('button', 'section-unlock-btn', __('Unlock', TD));
        var err = el('span', 'section-pw-error', __('Wrong password', TD));
        err.style.display = 'none'; err.style.color = 'red';
        box.appendChild(input); box.appendChild(btn); box.appendChild(err);

        btn.addEventListener('click', function () {
            err.style.display = 'none';
            fetch(cfg.restBase + 'congress/' + encodeURIComponent(cfg.slug) + '/section/' + section.id + '/unlock', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce },
                body: JSON.stringify({ ticket_id: cfg.ticket, password: input.value })
            })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (d && d.content) {
                    section.content = d.content;
                    if (box.parentNode) box.parentNode.replaceChild(renderContent(section), box);
                } else {
                    err.style.display = '';
                }
            })
            .catch(function () { err.style.display = ''; });
        });
        return box;
    }

    function renderSections(sections) {
        var content = el('div', 'congress-content');
        (sections || []).forEach(function (s) {
            var sec = el('div', 'congress-section');
            sec.setAttribute('data-section-id', s.id);
            sec.appendChild(el('h2', 'section-title', s.title || ''));
            if (s.has_password && (s.content === null || typeof s.content === 'undefined')) {
                sec.appendChild(renderLocked(s));
            } else {
                sec.appendChild(renderContent(s));
            }
            content.appendChild(sec);
        });
        if (!(sections || []).length) {
            content.appendChild(el('p', 'congress-empty', __('No content on this page yet.', TD)));
        }
        return content;
    }

    // ── Navigation / lazy page loading ───────────────────────
    function setActiveNav(pageId) {
        // Showing a real page → no extra is active.
        activeExtraKey = null;
        if (!navListEl) return;
        Array.prototype.forEach.call(navListEl.querySelectorAll('a'), function (a) {
            var isExtra = a.hasAttribute('data-extra');
            a.classList.toggle('active', !isExtra && parseInt(a.getAttribute('data-page-id'), 10) === pageId);
        });
    }

    // Synthetic-entry active highlight: clicked extra gets .active, page/other links lose it.
    function setActiveExtra(key) {
        activeExtraKey = key;
        currentPageId = 0;
        if (!navListEl) return;
        Array.prototype.forEach.call(navListEl.querySelectorAll('a'), function (a) {
            a.classList.toggle('active', a.getAttribute('data-extra') === key);
        });
    }

    // Render a synthetic entry into the main content area (defensive: needs content + render).
    function showExtra(entry) {
        if (!entry || typeof entry.render !== 'function' || !contentEl) return;
        contentEl.innerHTML = '';
        entry.render(contentEl);
        setActiveExtra(entry.key);
        updateBackButton();
        closeDrawer();
        contentEl.scrollTop = 0;
        window.scrollTo(0, 0);
    }

    function showPage(sections, pageId) {
        currentPageId = pageId;
        contentEl.innerHTML = '';
        contentEl.appendChild(renderSections(sections));
        setActiveNav(pageId);
        updateBackButton();
        closeDrawer();
        contentEl.scrollTop = 0;
        window.scrollTo(0, 0);
    }

    function loadPage(pageId) {
        if (pageId === currentPageId) { closeDrawer(); return; }
        if (pageCache[pageId]) { showPage(pageCache[pageId], pageId); return; }
        contentEl.innerHTML = '';
        contentEl.appendChild(el('div', 'congress-loading', __('Loading…', TD)));
        setActiveNav(pageId);
        fetch(cfg.restBase + 'congress/' + encodeURIComponent(cfg.slug) + '/page/' + pageId + '?t=' + encodeURIComponent(cfg.ticket), {
            headers: { 'X-WP-Nonce': cfg.nonce }
        })
        .then(function (r) { if (!r.ok) throw new Error('http ' + r.status); return r.json(); })
        .then(function (d) {
            pageCache[pageId] = d.sections || [];
            showPage(pageCache[pageId], pageId);
        })
        .catch(function () {
            contentEl.innerHTML = '';
            contentEl.appendChild(el('p', 'congress-error', __('Could not load this page.', TD)));
        });
    }

    function buildNav(pages, startId) {
        var ul = el('ul', 'congress-nav-list');
        (pages || []).forEach(function (p) {
            var li = el('li');
            var a = el('a', 'congress-nav-link', p.title || ('#' + p.id));
            a.href = '#'; a.setAttribute('data-page-id', p.id);
            a.addEventListener('click', function (ev) { ev.preventDefault(); loadPage(p.id); });
            li.appendChild(a); ul.appendChild(li);
        });
        // Synthetic entries (built-in "My ticket" + any premium-injected) — rendered client-side,
        // appended after the real page links, in their (already-sorted) order.
        portalExtras.forEach(function (entry) {
            var li = el('li', 'congress-nav-extra');
            var a = el('a', 'congress-nav-link', entry.label || entry.key);
            a.href = '#'; a.setAttribute('data-extra', entry.key);
            a.addEventListener('click', function (ev) { ev.preventDefault(); showExtra(entry); });
            li.appendChild(a); ul.appendChild(li);
        });
        return ul;
    }

    // Dashicon slug (without the 'dashicons-' prefix) for a synthetic extra entry:
    // explicit entry.icon wins; else a sensible per-key default; else a generic fallback.
    function extraIcon(entry) {
        if (entry && typeof entry.icon === 'string' && entry.icon) return entry.icon;
        var defaults = { '__ticket': 'tickets-alt', '__networking': 'groups', '__contact': 'id-alt' };
        return (entry && defaults[entry.key]) || 'info';
    }

    // Card-landing grid: one card per page (image-or-icon + title + description),
    // followed by one card per synthetic portal extra (icon + label only).
    function renderCardGrid(pages) {
        var grid = el('div', 'congress-card-grid');
        (pages || []).forEach(function (p) {
            var card = el('button', 'congress-card');
            card.setAttribute('data-page-id', p.id);
            // A card always has a color: use the page's color, else the brand default.
            // Drives the icon tint (--congress-accent) and a top accent. Validated hex only.
            var accent = (typeof p.color === 'string' && /^#[0-9a-fA-F]{3,8}$/.test(p.color)) ? p.color : '#9333ea';
            card.style.setProperty('--congress-accent', accent);
            card.style.borderTopColor = accent;
            card.style.borderTopWidth = '3px';
            // Visual: image wins; else a chosen icon; else nothing (no forced default icon).
            if (p.image) {
                var visual = el('div', 'congress-card-visual');
                var img = el('img'); img.src = safeUrl(p.image); img.loading = 'lazy'; img.alt = p.title || '';
                visual.appendChild(img);
                card.appendChild(visual);
            } else if (p.icon) {
                var visualIc = el('div', 'congress-card-visual');
                visualIc.appendChild(el('span', 'dashicons ' + p.icon));
                card.appendChild(visualIc);
            }
            card.appendChild(el('span', 'congress-card-title', p.title || ('#' + p.id)));
            if (p.description) card.appendChild(el('span', 'congress-card-desc', p.description));
            card.addEventListener('click', function () { loadPage(p.id); });
            grid.appendChild(card);
        });
        // Synthetic portal extras (already sorted by order) as icon-only cards, after the pages.
        portalExtras.forEach(function (entry) {
            var card = el('button', 'congress-card congress-card-extra');
            card.setAttribute('data-extra', entry.key);
            var visual = el('div', 'congress-card-visual');
            visual.appendChild(el('span', 'dashicons dashicons-' + extraIcon(entry)));
            card.appendChild(visual);
            card.appendChild(el('span', 'congress-card-title', entry.label || entry.key));
            card.addEventListener('click', function (ev) { ev.preventDefault(); showExtra(entry); });
            grid.appendChild(card);
        });
        return grid;
    }

    function showCardLanding() {
        currentPageId = 0;
        contentEl.innerHTML = '';
        contentEl.appendChild(renderCardGrid(bootData.pages || []));
        setActiveNav(0);
        closeDrawer();
        // updateBackButton is added in a later task; guard so this works standalone.
        updateBackButton();
        window.scrollTo(0, 0);
    }

    function goHome() {
        if (landingCards) { showCardLanding(); return; }
        var startId = (bootData && bootData.start_page_id) || 0;
        loadPage(startId);
    }

    // Card mode: show back on any page (returns to the grid).
    // Classic mode: show back on every page except the start page.
    function updateBackButton() {
        if (!backBtn) return;
        var startId = (bootData && bootData.start_page_id) || 0;
        var show = landingCards ? (currentPageId !== 0) : (currentPageId !== startId && currentPageId !== 0);
        backBtn.style.display = show ? '' : 'none';
    }

    // ── Mobile drawer ────────────────────────────────────────
    var drawerOpen = false;
    function toggleDrawer() { drawerOpen ? closeDrawer() : openDrawer(); }
    function openDrawer()  { drawerOpen = true;  app.classList.add('drawer-open'); }
    function closeDrawer() { drawerOpen = false; app.classList.remove('drawer-open'); }

    // ── Shell ────────────────────────────────────────────────
    function buildShell(data) {
        app.classList.add('congress-shell');

        // Top bar (hamburger on mobile + title + wallet link).
        var top = el('header', 'congress-topbar');
        var burger = el('button', 'congress-burger');
        burger.setAttribute('aria-label', __('Menu', TD));
        burger.innerHTML = '<span></span><span></span><span></span>';
        burger.addEventListener('click', toggleDrawer);
        top.appendChild(burger);
        backBtn = el('button', 'congress-back');
        backBtn.setAttribute('aria-label', __('Back', TD));
        backBtn.innerHTML = '<span class="dashicons dashicons-arrow-left-alt2"></span>';
        backBtn.style.display = 'none';
        backBtn.addEventListener('click', goHome);
        top.appendChild(backBtn);
        var titleBtn = el('button', 'congress-topbar-title', data.title || cfg.title || '');
        titleBtn.setAttribute('type', 'button');
        titleBtn.addEventListener('click', goHome);
        top.appendChild(titleBtn);
        app.appendChild(top);

        // Generic seam: let extensions (premium) inject header actions into the top bar.
        // No-op without a listener; carries no premium specifics.
        document.dispatchEvent(new CustomEvent('sasoEtCongressHeader', { detail: { topbar: top, cfg: cfg, bootData: bootData } }));

        if (data.expired || cfg.expired) {
            app.appendChild(el('div', 'congress-notice expired', __('This congress has expired.', TD)));
        }

        // Body = sidebar + main.
        var body = el('div', 'congress-body');
        var sidebar = el('nav', 'congress-sidebar');
        navListEl = buildNav(data.pages, data.start_page_id);
        sidebar.appendChild(navListEl);
        body.appendChild(sidebar);

        // Click-away overlay for the mobile drawer.
        var overlay = el('div', 'congress-overlay');
        overlay.addEventListener('click', closeDrawer);
        body.appendChild(overlay);

        contentEl = el('main', 'congress-main');
        body.appendChild(contentEl);
        app.appendChild(body);
    }

    // ── Portal extras (synthetic nav entries) ────────────────
    // Built once after bootData is available, BEFORE the shell/nav is built. The built-in
    // "My ticket" entry shows the ticket QR (so attendees without a badge can show it at the
    // door). Premium/3rd-party can inject more via the 'sasoEtCongressPortalExtras' event.
    function buildPortalExtras() {
        portalExtras = [];
        extrasByKey = {};

        if (cfg.ticketQr) {
            portalExtras.push({
                key: '__ticket',
                order: 1000, // always last
                icon: 'tickets-alt',
                label: __('My ticket', TD),
                render: function (container) {
                    container.innerHTML = '';
                    container.appendChild(el('h2', 'congress-section-title', __('My ticket', TD)));
                    var img = document.createElement('img');
                    img.src = cfg.ticketQr;
                    img.alt = __('Ticket QR code', TD);
                    img.className = 'congress-ticket-qr';
                    container.appendChild(img);
                    if (cfg.ticketLabel) container.appendChild(el('p', 'congress-ticket-id', cfg.ticketLabel));
                    if (cfg.showWalletLink && cfg.walletUrl) {
                        var wa = el('a', 'congress-ticket-wallet', __('Add to Vollstart Wallet', TD));
                        wa.href = cfg.walletUrl; wa.target = '_blank'; wa.rel = 'noopener';
                        container.appendChild(wa);
                    }
                }
            });
        }

        // Let premium add entries (e.g. "My contact details"). They push {key, order, label, render}.
        document.dispatchEvent(new CustomEvent('sasoEtCongressPortalExtras', {
            detail: { extras: portalExtras, cfg: cfg, bootData: bootData }
        }));

        // Keep only well-formed entries, then order them (built-in ticket has order 1000 = last).
        portalExtras = portalExtras.filter(function (e) {
            return e && typeof e.key === 'string' && typeof e.render === 'function';
        });
        portalExtras.sort(function (a, b) { return (a.order || 0) - (b.order || 0); });
        portalExtras.forEach(function (e) { extrasByKey[e.key] = e; });
    }

    // ── Boot ─────────────────────────────────────────────────
    fetch(cfg.restBase + 'congress/' + encodeURIComponent(cfg.slug) + '?t=' + encodeURIComponent(cfg.ticket), {
        headers: { 'X-WP-Nonce': cfg.nonce }
    })
    .then(function (r) { if (!r.ok) throw new Error('http ' + r.status); return r.json(); })
    .then(function (data) {
        app.removeAttribute('data-loading');
        bootData = data;
        landingCards = !!data.landing_cards;
        // Build synthetic nav entries before the shell — both page mode and card-landing mode
        // build the sidebar via buildNav, so extras appear in the sidebar regardless of mode.
        buildPortalExtras();
        function renderApp() {
            app.innerHTML = '';
            buildShell(data);
            var startId = data.start_page_id || 0;
            if (startId && data.sections && data.sections.length) pageCache[startId] = data.sections;
            if (landingCards) {
                showCardLanding();
            } else {
                showPage(data.sections || [], startId);
            }
        }
        var gate = window.sasoEtCongressGate;
        if (gate && typeof gate.intercept === 'function' && gate.intercept(data, cfg, renderApp) === false) {
            return; // premium gate took over; it calls renderApp() after the profile is saved
        }
        renderApp();
    })
    .catch(function () {
        app.innerHTML = '';
        app.appendChild(el('p', 'congress-error', __('Could not load the congress page.', TD)));
    });
})();
