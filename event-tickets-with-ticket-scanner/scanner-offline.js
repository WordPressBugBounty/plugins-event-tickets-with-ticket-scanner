/* Offline scanning for the ticket scanner — controlled per auth token.
 *
 * The server stays the source of truth: offline scans are validated against
 * a preloaded ticket pack (offline_pack) and queued; the sync endpoint
 * (offline_sync) replays every queued scan through the normal redeem path,
 * idempotent per client event id, and reports conflicts when another
 * scanner was faster. Multiple scanners are the normal case.
 *
 * Storage: localStorage (pack + queue + conflict log). Packs above ~4 MB
 * are refused with a hint to narrow the token's offline lists.
 */
window.SasoScannerOffline = (function () {
    var LS_PACK = 'saso_et_offline_pack_v1';
    var LS_QUEUE = 'saso_et_offline_queue_v1';
    var LS_SID = 'saso_et_scanner_id_v1';
    var LS_CONFLICTS = 'saso_et_offline_conflicts_v1';
    var SYNC_INTERVAL_MS = 60000;

    var state = {
        config: null,      // {offline, mode: off|token|ask, lists, label}
        mode: 'off',
        scope: null,       // ask-mode choice: 'since' | 'today'
        pack: null,        // {v, label, lists: [{id, name, tickets:[{c,r,u,cvv}]}]}
        ready: false,      // config active AND pack loaded
        syncing: false,
        timer: null,
        restOnline: true   // last live REST call state (fallback trigger)
    };

    function token() {
        try {
            var p = new URLSearchParams(window.location.search);
            return p.get('auth') || '';
        } catch (e) { return ''; }
    }

    function restUrl(endpoint, params) {
        var base = (window.Ajax_sasoEventtickets && Ajax_sasoEventtickets._siteUrl)
            ? Ajax_sasoEventtickets._siteUrl : window.location.origin;
        var prefix = (window.Ajax_sasoEventtickets && Ajax_sasoEventtickets._restPrefixUrl)
            ? Ajax_sasoEventtickets._restPrefixUrl : 'event-tickets-with-ticket-scanner';
        var url = base + '/wp-json/' + prefix + '/ticket/scanner/' + endpoint;
        var q = [];
        for (var k in (params || {})) { if (params.hasOwnProperty(k)) q.push(encodeURIComponent(k) + '=' + encodeURIComponent(params[k])); }
        if (token()) q.push('auth=' + encodeURIComponent(token()));
        return url + (q.length ? '?' + q.join('&') : '');
    }

    function lsGet(key, fallback) {
        try { var v = window.localStorage.getItem(key); return v ? JSON.parse(v) : fallback; }
        catch (e) { return fallback; }
    }
    function lsSet(key, value) {
        try { window.localStorage.setItem(key, JSON.stringify(value)); return true; }
        catch (e) { return false; }
    }

    function scannerId() {
        var sid = window.localStorage.getItem(LS_SID);
        if (!sid) {
            sid = 'S' + Math.random().toString(36).slice(2, 10);
            window.localStorage.setItem(LS_SID, sid);
        }
        return sid;
    }

    // ── status bar ────────────────────────────────────────────

    function barEl() { return document.getElementById('saso_et_offline_bar'); }

    function renderBar(extra) {
        var bar = barEl();
        if (!bar) {
            bar = document.createElement('div');
            bar.id = 'saso_et_offline_bar';
            bar.style.cssText = 'padding:6px 10px;font-size:13px;line-height:1.5;background:#eef5fb;border-bottom:1px solid #c9dcee;text-align:center;';
            var host = document.body;
            if (host && host.firstChild) host.insertBefore(bar, host.firstChild);
            else if (host) host.appendChild(bar);
        }
        var q = queue().length;
        var n = packTicketCount();
        var online = navigator.onLine;
        var str = strings();
        var parts = [];
        parts.push('<b>' + offlineLabel() + '</b>');
        parts.push(online ? str.connected : str.offlineHint);
        parts.push(sprintf_like(str.preloaded, String(n)));
        if (q > 0) parts.push('<b style="color:#b35900;">' + sprintf_like(str.waiting, String(q)) + '</b>');
        var cl = conflicts();
        if (cl.length > 0) parts.push('<b style="color:#c00;">' + sprintf_like(str.conflicts, String(cl.length)) + '</b>');
        if (extra) parts.push(extra);
        bar.innerHTML = parts.join(' · ')
            + ' · <a href="#" id="saso_et_offline_sync_btn" style="text-decoration:underline;">' + str.syncNow + '</a>'
            + (state.mode === 'ask' ? ' · <a href="#" id="saso_et_offline_change_btn" style="text-decoration:underline;">' + escapeHtml(str.changeChoice) + '</a>' : '');
        var btn = document.getElementById('saso_et_offline_sync_btn');
        if (btn) btn.onclick = function (e) { e.preventDefault(); syncNow(true); };
        var cbtn = document.getElementById('saso_et_offline_change_btn');
        if (cbtn) cbtn.onclick = function (e) { e.preventDefault(); askDialog(); };
    }

    function offlineLabel() {
        var l = (state.config && state.config.label) || '';
        return strings().scannerTitle + (l ? ' (' + escapeHtml(l) + ')' : '');
    }

    function sprintf_like(f) {
        var args = arguments;
        return f.replace(/%s/g, function () { return Array.prototype.slice.call(args, 1).join(' '); });
    }
    function escapeHtml(s) {
        return String(s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    // ── pack ──────────────────────────────────────────────────

    function queue() { return lsGet(LS_QUEUE, []); }
    function saveQueue(q) { lsSet(LS_QUEUE, q); }
    function conflicts() { return lsGet(LS_CONFLICTS, []); }

    function ticketMap() {
        var map = {};
        if (!state.pack || !state.pack.lists) return map;
        for (var i = 0; i < state.pack.lists.length; i++) {
            var ts = state.pack.lists[i].tickets || [];
            for (var j = 0; j < ts.length; j++) map[ts[j].c] = ts[j];
        }
        return map;
    }
    function packTicketCount() {
        var n = 0;
        if (state.pack && state.pack.lists) {
            for (var i = 0; i < state.pack.lists.length; i++) n += (state.pack.lists[i].tickets || []).length;
        }
        return n;
    }
    function savePack(pack) {
        var json = JSON.stringify(pack);
        if (json.length > 4500000) {
            renderBar('<b style="color:#c00;">' + strings().packTooLarge + '</b>');
            return false;
        }
        return lsSet(LS_PACK, pack);
    }

    function fetchPack(callback) {
        var params = {};
        if (state.mode === 'ask' && state.scope) params.scope = state.scope;
        fetch(restUrl('offline_pack', params), { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (json) {
                var data = json && json.success ? json.data : null;
                if (!data) throw new Error('no pack');
                state.pack = data;
                savePack(data);
                state.ready = true;
                renderBar();
                if (callback) callback(true);
            })
            .catch(function () {
                // keep the cached pack — that is the whole point
                if (state.pack) state.ready = true;
                renderBar();
                if (callback) callback(false);
            });
    }

    // ── scans ─────────────────────────────────────────────────

    function shouldHandle() {
        return state.ready && (!navigator.onLine || !state.restOnline);
    }

    function handleScan(code, cvv, showResult) {
        var t = ticketMap()[code];
        var ts = strings();
        if (!t) {
            scanResult(false, code, ts.offlineUnknown || 'Unknown ticket (offline)', '#c00');
            return;
        }
        if (t.cvv) {
            scanResult(false, code, ts.offlineCvv || 'CVV-protected ticket — an online connection is required', '#b35900');
            return;
        }
        if ((t.u || 0) >= (t.r || 1)) {
            scanResult(false, code, ts.offlineUsed || 'Already redeemed (offline state)', '#c00');
            return;
        }
        // valid: book locally, queue for sync
        t.u = (t.u || 0) + 1;
        savePack(state.pack);
        var q = queue();
        q.push({ eid: eid(), code: code, cvv: cvv || '', t: Date.now() });
        saveQueue(q);
        scanResult(true, code, ts.offlineQueued || 'Valid — saved, queued for sync', '#128a3c');
        renderBar();
    }

    function eid() {
        return 'E' + Date.now().toString(36) + '-' + Math.random().toString(36).slice(2, 10);
    }

    function scanResult(ok, code, msg, color) {
        var out = document.getElementById('reader_output');
        var html = '<div style="text-align:center;padding:18px 10px;">'
            + '<div style="font-size:22px;font-weight:bold;color:' + color + ' !important;">' + escapeHtml(msg) + '</div>'
            + '<div style="margin-top:8px;font-family:monospace;">' + escapeHtml(code) + '</div>'
            + '</div>';
        if (out) { out.innerHTML = html; out.style.display = 'block'; }
        if (window.navigator && navigator.vibrate) { try { navigator.vibrate(ok ? 80 : [60, 40, 60]); } catch (e) {} }
        if (typeof window.sasoScannerShowScanNextButton === 'function') window.sasoScannerShowScanNextButton();
        else showScanNextFallback();
    }

    function showScanNextFallback() {
        // minimal fallback if the scanner did not expose its button helper
        var bar = barEl();
        if (bar) {
            var b = document.createElement('button');
            b.textContent = 'OK';
            b.style.cssText = 'margin-left:8px;';
            b.onclick = function () { b.remove(); };
            bar.appendChild(b);
        }
    }

    // ── sync ──────────────────────────────────────────────────

    function syncNow(manual) {
        var q = queue();
        if (state.syncing || q.length === 0 || !navigator.onLine) { renderBar(); return; }
        state.syncing = true;
        var events = q.slice(0, 200);
        fetch(restUrl('offline_sync'), {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ sid: scannerId(), events: events })
        })
            .then(function (r) { return r.json(); })
            .then(function (json) {
                var data = json && json.success ? json.data : null;
                if (!data || !data.results) throw new Error('sync failed');
                var byEid = {};
                var ok = 0, conflictsNow = [];
                for (var i = 0; i < data.results.length; i++) {
                    var res = data.results[i];
                    byEid[res.eid] = res;
                    if (res.state === 'ok') ok++;
                    if (res.state === 'conflict') conflictsNow.push(res);
                }
                var rest = [];
                var newQueue = [];
                q.forEach(function (ev) {
                    var r = byEid[ev.eid];
                    if (!r) { newQueue.push(ev); return; }  // not answered: keep
                    rest.push(ev);
                });
                saveQueue(newQueue);
                if (conflictsNow.length) {
                    var log = conflicts().slice(0, 49);
                    conflictsNow.forEach(function (c) {
                        log.unshift({ eid: c.eid, at: new Date().toISOString() });
                    });
                    lsSet(LS_CONFLICTS, log.slice(0, 50));
                } else {
                    lsSet(LS_CONFLICTS, []);
                }
                state.syncing = false;
                var extra = '<b style="color:#128a3c;">' + sprintf_like('%s: %s', 'Sync', String(ok)) + ' OK</b>'
                    + (conflictsNow.length ? ' · <b style="color:#c00;">' + sprintf_like('%s: %s', 'Conflicts', String(conflictsNow.length)) + '</b>' : '');
                renderBar(extra);
                fetchPack(); // fresh server state after the replay
            })
            .catch(function () {
                state.syncing = false;
                renderBar('<span style="color:#b35900;">' + strings().syncFailed + '</span>');
            });
    }

    // ── boot ──────────────────────────────────────────────────

    function init() {
        state.pack = lsGet(LS_PACK, null);
        fetch(restUrl('offline_config'), { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (json) {
                var cfg = json && json.success ? json.data : null;
                if (!cfg || !cfg.offline || cfg.mode === 'off') return; // off = behave exactly as before
                state.config = cfg;
                state.mode = cfg.mode || 'off';
                lsSet('saso_et_offline_was_active', true);
                if (state.mode === 'token') {
                    state.ready = !!state.pack; // cached pack works immediately
                    renderBar();
                    fetchPack();
                    startEngines();
                } else if (state.mode === 'ask') {
                    askOrResume();
                }
            })
            .catch(function () { /* offline start with cached pack */
                if (state.pack && lsGet('saso_et_offline_was_active', false)) {
                    state.ready = true;
                    state.mode = 'token';
                    state.config = { offline: true, mode: 'token', label: '' };
                    renderBar();
                    startEngines();
                }
            });
    }

    // ask-mode: on scanner start (fresh session), offer preloading — from
    // today onwards or today only. Choice lives for the session only.
    function askOrResume() {
        var chosen = null;
        try { chosen = window.sessionStorage.getItem('saso_et_offline_scope'); } catch (e) {}
        if (chosen === 'since' || chosen === 'today') {
            applyScope(chosen, false);
            return;
        }
        askDialog();
    }

    function askDialog() {
        var str = strings();
        var old = document.getElementById('saso_et_offline_ask');
        if (old) old.remove();
        var d = document.createElement('div');
        d.id = 'saso_et_offline_ask';
        d.style.cssText = 'position:fixed;top:0;left:0;right:0;z-index:99999;background:#fff;border-bottom:2px solid #2e74b5;padding:14px 12px;text-align:center;box-shadow:0 2px 8px rgba(0,0,0,.25);';
        d.innerHTML = '<div style="font-weight:bold;margin-bottom:4px;">' + escapeHtml(str.askTitle) + '</div>'
            + '<div style="margin-bottom:10px;">' + escapeHtml(str.askText) + '</div>'
            + '<button data-scope="since" style="margin:2px 6px;padding:8px 12px;">' + escapeHtml(str.choiceSince) + '</button>'
            + '<button data-scope="today" style="margin:2px 6px;padding:8px 12px;">' + escapeHtml(str.choiceToday) + '</button>'
            + '<button data-scope="no" style="margin:2px 6px;padding:8px 12px;">' + escapeHtml(str.choiceNo) + '</button>';
        (document.body || document.documentElement).appendChild(d);
        d.querySelectorAll('button').forEach(function (b) {
            b.onclick = function () { d.remove(); applyScope(b.getAttribute('data-scope'), true); };
        });
    }

    function applyScope(scope, chosenNow) {
        var str = strings();
        if (scope === 'no') {
            if (chosenNow) { try { window.sessionStorage.removeItem('saso_et_offline_scope'); } catch (e) {} }
            state.ready = false;
            var bar0 = barEl(); if (bar0) bar0.remove();
            return;
        }
        state.scope = scope;
        try { window.sessionStorage.setItem('saso_et_offline_scope', scope); } catch (e) {}
        state.ready = !!state.pack;
        renderBar('<i>' + escapeHtml(str.loadingPack) + '</i>');
        fetchPack();
        startEngines();
    }

    function startEngines() {
        if (state.timer) return;
        window.addEventListener('online', function () { syncNow(false); });
        document.addEventListener('visibilitychange', function () {
            if (!document.hidden) { fetchPack(); syncNow(false); }
        });
        state.timer = window.setInterval(function () {
            if (queue().length > 0 && navigator.onLine) syncNow(false);
        }, SYNC_INTERVAL_MS);
    }

    // PHP ships the translated strings via wp_add_inline_script
    // (scanner-offline-strings) — no client-side JED split needed.
    function strings() {
        var d = window.ticketScannerOfflineStrings || {};
        return {
            connected: d.connected || 'Connected',
            offlineHint: d.offlineHint || 'Offline — scans are validated locally and queued',
            preloaded: d.preloaded || 'Preloaded tickets',
            waiting: d.waiting || 'Waiting for sync',
            conflicts: d.conflicts || 'Conflicts after last sync',
            syncNow: d.syncNow || 'Sync now',
            scannerTitle: d.scannerTitle || 'Offline scanner',
            packTooLarge: d.packTooLarge || 'Pack too large — restrict the offline lists on the auth token',
            syncFailed: d.syncFailed || 'Sync failed — will retry',
            offlineUnknown: d.offlineUnknown || 'Unknown ticket (offline)',
            offlineCvv: d.offlineCvv || 'CVV-protected ticket — an online connection is required',
            offlineUsed: d.offlineUsed || 'Already redeemed (offline state)',
            offlineQueued: d.offlineQueued || 'Valid — saved, queued for sync',
            askTitle: d.askTitle || 'Offline scanning?',
            askText: d.askText || 'Preload the tickets onto this device so scanning keeps working when the connection drops?',
            choiceSince: d.choiceSince || 'Load tickets — from today onwards',
            choiceToday: d.choiceToday || 'Load tickets — today only',
            choiceNo: d.choiceNo || 'No, stay online',
            changeChoice: d.changeChoice || 'Offline…',
            loadingPack: d.loadingPack || 'Loading tickets…'
        };
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();

    return {
        shouldHandle: shouldHandle,
        handleScan: handleScan,
        isActive: function () { return !!state.config; },
        markRestFailed: function () { state.restOnline = false; },
        markRestOk: function () { state.restOnline = true; },
        syncNow: syncNow,
        state: state
    };
})();
