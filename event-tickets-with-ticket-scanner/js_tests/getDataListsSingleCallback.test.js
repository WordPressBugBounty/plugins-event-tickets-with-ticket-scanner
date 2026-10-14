/**
 * Tests for getDataLists() in backend.js — the single-callback contract.
 *
 * Bug (Saso 2026-10-14, "call für die order items zweimal gemacht"):
 * on a warm cache getDataLists invoked the caller's callback TWICE —
 * once synchronously from the cache and again after the background
 * refetch. Every Kasse visit after the first therefore rendered the
 * whole area twice = TWO getInternalOrders requests (plus a wasted
 * DataTable init on a orphaned table).
 *
 * Contracts pinned here:
 *  1. Cache miss: cbf fires exactly once, after the fetch resolves,
 *     with the fetched data.
 *  2. Cache hit: cbf fires exactly ONCE (immediately from cache).
 *     A background refetch may still update DATA_LISTS silently —
 *     freshness stays intact — but it must NOT fire cbf again.
 *  3. Freshness: after the canonical invalidation (DATA_LISTS = null,
 *     as done by addList/editList), the next call refetches.
 */

const fs = require('fs');
const path = require('path');

const BACKEND_PATH = path.resolve(__dirname, '..', 'backend.js');

// Balanced-brace extractor — same walker as the other js_tests. Keep in sync.
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
    // Walk the parameter list (parens), then the body (braces) so nested
    // blocks do not truncate the function.
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

const SAMPLE_LISTS = [
    { id: 1, name: 'Messe Liste', meta: '{"desc":"<b>Eintritt</b>"}' },
    { id: 2, name: 'VIP Liste', meta: '{}' },
];

/**
 * Builds a sandbox around the REAL getDataLists source with mocked
 * _makeGet. Pending fetch callbacks land in `queue`; flushing the queue
 * resolves the fetch with SAMPLE_LISTS.
 */
function makeSandbox(seedLists) {
    const queue = [];
    const fnText = extractFunction(
        fs.readFileSync(BACKEND_PATH, 'utf8'),
        'getDataLists'
    );
    const factory = new Function(
        'queue', 'SAMPLE',
        `
        var DATA_LISTS = ${JSON.stringify(seedLists)};
        var calls = [];
        function _makeGet(action, data, cb) { calls.push(action); queue.push(cb); }
        function __updateFirstStepsProgress() {}
        ${fnText}
        return {
            getDataLists: getDataLists,
            makeGetCalls: calls,
            flushFetch: function () { const cbs = queue.splice(0); cbs.forEach(cb => cb(SAMPLE)); },
            pendingFetches: function () { return queue.length; },
            currentLists: function () { return DATA_LISTS; },
            invalidate: function () { DATA_LISTS = null; },
        };
        `
    );
    return factory(queue, SAMPLE_LISTS);
}

describe('getDataLists single-callback contract (backend.js)', () => {
    test('cache miss: cbf fires exactly once, after the fetch resolves', () => {
        const box = makeSandbox(null);
        let cbCount = 0;
        let gotData = null;
        box.getDataLists((lists) => { cbCount++; gotData = lists; });
        expect(cbCount).toBe(0);            // nothing before the fetch resolves
        expect(box.makeGetCalls).toEqual(['getLists']);
        box.flushFetch();
        expect(cbCount).toBe(1);            // exactly once
        expect(gotData).toEqual(SAMPLE_LISTS);
        expect(box.currentLists()).toEqual(SAMPLE_LISTS);
    });

    test('cache hit: cbf fires exactly ONCE — background refetch stays silent', () => {
        const box = makeSandbox(SAMPLE_LISTS);
        let cbCount = 0;
        box.getDataLists(() => { cbCount++; });
        expect(cbCount).toBe(1);            // immediate render from cache
        expect(box.makeGetCalls).toEqual(['getLists']); // refetch may run …
        box.flushFetch();                   // … but it must NOT re-fire cbf
        expect(cbCount).toBe(1);            // THE bug: was 2
        expect(box.currentLists()).toEqual(SAMPLE_LISTS);
    });

    test('freshness: after invalidation the next call refetches', () => {
        const box = makeSandbox(SAMPLE_LISTS);
        box.invalidate();
        let cbCount = 0;
        box.getDataLists(() => { cbCount++; });
        expect(cbCount).toBe(0);            // cache empty again → must fetch
        box.flushFetch();
        expect(cbCount).toBe(1);
    });
});
