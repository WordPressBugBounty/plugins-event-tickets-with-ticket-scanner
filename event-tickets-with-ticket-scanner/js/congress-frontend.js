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
        if (!navListEl) return;
        Array.prototype.forEach.call(navListEl.querySelectorAll('a'), function (a) {
            a.classList.toggle('active', parseInt(a.getAttribute('data-page-id'), 10) === pageId);
        });
    }

    function showPage(sections, pageId) {
        currentPageId = pageId;
        contentEl.innerHTML = '';
        contentEl.appendChild(renderSections(sections));
        setActiveNav(pageId);
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
        return ul;
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
        top.appendChild(el('span', 'congress-topbar-title', data.title || cfg.title || ''));
        if (cfg.showWalletLink && cfg.walletUrl) {
            var wa = el('a', 'congress-wallet-link', __('Add to Vollstart Wallet', TD));
            wa.href = cfg.walletUrl; wa.target = '_blank'; wa.rel = 'noopener';
            top.appendChild(wa);
        }
        app.appendChild(top);

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

    // ── Boot ─────────────────────────────────────────────────
    fetch(cfg.restBase + 'congress/' + encodeURIComponent(cfg.slug) + '?t=' + encodeURIComponent(cfg.ticket), {
        headers: { 'X-WP-Nonce': cfg.nonce }
    })
    .then(function (r) { if (!r.ok) throw new Error('http ' + r.status); return r.json(); })
    .then(function (data) {
        app.removeAttribute('data-loading');
        app.innerHTML = '';
        buildShell(data);
        // Start page sections came with the initial load → render immediately, cache them.
        var startId = data.start_page_id || 0;
        if (startId) pageCache[startId] = data.sections || [];
        showPage(data.sections || [], startId);
    })
    .catch(function () {
        app.innerHTML = '';
        app.appendChild(el('p', 'congress-error', __('Could not load the congress page.', TD)));
    });
})();
