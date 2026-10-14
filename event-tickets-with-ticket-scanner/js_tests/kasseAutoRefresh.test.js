/**
 * Tests for the Kasse auto-refresh (3.2.7).
 *
 * Feature (Saso 2026-10-14): with several kassen selling in parallel, the
 * direct sales table runs behind. The toolbar gains a checkbox "Auto
 * refresh" (default OFF) that reloads the table every 30s via the existing
 * __kasseReload() path (ajax.reload, pagination/filter preserved). The
 * choice persists per browser (localStorage), mirroring the scanner
 * options pattern.
 *
 * Contracts pinned here:
 *  1. __renderKasse renders the auto-refresh checkbox into the toolbar.
 *  2. Toggling persists via localStorage with the conventional key
 *     and starts/stops the interval (setInterval / clearInterval).
 *  3. Leaving the Kasse area clears a running interval (no leaked
 *     timers reloading a dead table).
 */

const fs = require('fs');
const path = require('path');

const BACKEND_PATH = path.resolve(__dirname, '..', 'backend.js');
const STORAGE_KEY = 'saso_et_kasse_autorefresh';

// Balanced-brace extractor — parens then body braces. Keep in sync with the
// other js_tests walkers.
function extractFunction(source, name) {
    const signatureRe = new RegExp('function\\s+' + name + '\\s*\\(', 'g');
    let match;
    let found = null;
    while ((match = signatureRe.exec(source)) !== null) {
        const before = source[match.index - 1];
        if (before === undefined || /\s/.test(before) || before === ';') {
            found = match;
            break;
        }
    }
    if (!found) {
        throw new Error('Function ' + name + ' declaration not found in source');
    }
    let i = found.index;
    let depth = 1;
    for (; i < source.length; i++) {
        if (i >= found.index + found[0].length) {
            const ch = source[i];
            if (ch === '(') depth++;
            else if (ch === ')') {
                depth--;
                if (depth === 0) break;
            }
        }
    }
    let bodyStart = -1;
    for (let j = i + 1; j < source.length; j++) {
        if (source[j] === '{') { bodyStart = j; break; }
        if (!/\s/.test(source[j])) {
            throw new Error('Function ' + name + ' has no brace body');
        }
    }
    if (bodyStart === -1) {
        throw new Error('Function ' + name + ' has no brace body');
    }
    depth = 1;
    for (let j = bodyStart + 1; j < source.length; j++) {
        const ch = source[j];
        if (ch === '{') depth++;
        else if (ch === '}') {
            depth--;
            if (depth === 0) return source.slice(found.index, j + 1);
        }
    }
    throw new Error('Function ' + name + ' has unbalanced braces');
}

const SOURCE = fs.readFileSync(BACKEND_PATH, 'utf8');

describe('Kasse auto-refresh (backend.js)', () => {

    test('toolbar renders the auto-refresh checkbox with label', () => {
        expect(SOURCE).toMatch(/et-kasse-toolbar__autorefresh/);
        expect(SOURCE).toMatch(/Auto refresh/);
    });

    test('toggle persists via localStorage and drives the interval', () => {
        const sandbox = makeToggleSandbox();
        // OFF -> ON
        sandbox.toggle(true);
        expect(sandbox.stored()).toBe('1');
        expect(sandbox.hasTimer()).toBe(true);
        // ON -> OFF
        sandbox.toggle(false);
        expect(sandbox.stored()).toBe('0');
        expect(sandbox.hasTimer()).toBe(false);
        expect(sandbox.clearedIds).toContain(1);
        sandbox._restoreGlobals();
    });

    test('leaving the Kasse area clears a running interval', () => {
        const sandbox = makeToggleSandbox();
        sandbox.toggle(true);
        expect(sandbox.hasTimer()).toBe(true);
        sandbox.leaveKasse();
        expect(sandbox.hasTimer()).toBe(false);
        expect(sandbox.clearedIds).toContain(1);
        sandbox._restoreGlobals();
    });
});

function makeToggleSandbox() {
    const fnText = extractFunction(SOURCE, '__kasseAutoRefresh');
    const factory = new Function(
        'storage', 'win', '__kasseReload', 'KASSE_AUTOREFRESH_MS', 'KASSE_AUTOREFRESH_KEY',
        `
        var localStorage = storage;
        var window = win;
        var __kasseAutoRefreshTimer = null;
        var reloadCalls = 0;
        function reload() { reloadCalls++; }
        ${fnText}
        return {
            start: __kasseAutoRefresh,
            reloadCalls: function () { return reloadCalls; },
            timer: function () { return __kasseAutoRefreshTimer; },
        };
        `
    );
    const storage = {
        _data: {},
        getItem: function (k) { return k in this._data ? this._data[k] : null; },
        setItem: function (k, v) { this._data[k] = String(v); },
        removeItem: function (k) { delete this._data[k]; },
    };
    const intervals = [];
    const clears = [];
    const win = {
        setInterval: function (fn, ms) { intervals.push({ fn, ms }); return intervals.length; },
        clearInterval: function (id) { clears.push(id); },
    };
    const box = factory(storage, win, function () {}, 30000, STORAGE_KEY);
    // Produktivcode ruft die GLOBALen setInterval/clearInterval — dem
    // Sandbox-Scope die Fakes unterlegen, damit Clears sichtbar werden.
    box._origSetInterval = global.setInterval;
    box._origClearInterval = global.clearInterval;
    global.setInterval = win.setInterval;
    global.clearInterval = win.clearInterval;
    return {
        _restoreGlobals: () => {
            global.setInterval = box._origSetInterval;
            global.clearInterval = box._origClearInterval;
        },
        stored: () => storage.getItem(STORAGE_KEY),
        intervalId: () => (intervals.length ? intervals.length : null),
        lastIntervalId: 0,
        clearedIds: clears,
        intervalCount: () => intervals.length,
        intervalMs: () => (intervals[0] ? intervals[0].ms : null),
        reloadCalls: () => box.reloadCalls(),
        hasTimer: () => box.timer() !== null,
        toggle: (on) => box.start(on),
        leaveKasse: () => box.start(false),
    };
}
