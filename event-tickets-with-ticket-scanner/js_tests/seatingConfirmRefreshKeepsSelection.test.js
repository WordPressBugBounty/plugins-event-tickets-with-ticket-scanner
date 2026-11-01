/**
 * Tests for the seats-race bug: an internal refresh (getSelectorData ->
 * applySelectorData) that lands shortly after confirmSelection() wiped the
 * hidden seat-selection input.
 *
 * Bug shape (support ticket 015487 follow-up, headless-verified ~1s after
 * Confirm): confirmSelection() writes the hidden input immediately (line
 * ~1235), but applySelectorData() — which runs on EVERY getSelectorData
 * response (found_variation / reset_data / date-change driven refresh) —
 * unconditionally runs `$selector.find('.saso-seat-selection-input').val('')`
 * plus `this.selectedSeats = []`. The wipe lands ~1s after Confirm (the
 * first found_variation request is still in flight when the customer
 * confirms fast, or Woo fires a later refresh), so the form submits without
 * seat data while the seats are actually blocked for the session.
 *
 * The server already reports the truth: buildSelectorData()
 * (includes/seating/class-seating-frontend.php:332ff) returns the session's
 * live blocks as `existingBlocks` (filtered to the plan's seats) whenever
 * `currentSelection` is empty — which is exactly the AJAX path
 * (doGetSelectorData passes null). The client simply never consumed them.
 *
 * Contract under test:
 *   (a) applySelectorData must not blindly val('') while this.selectedSeats
 *       is non-empty or the server reports existingBlocks for this session;
 *   (b) the existingBlocks restore path must write the restored selection
 *       into the hidden input in the same format updateSelection() uses;
 *   (c) closeModal/confirmSelection ordering must be race-safe (a refresh
 *       during an open modal closes it before rebuilding, never leaves the
 *       hidden input empty while a selection survives).
 *
 * Test strategy: extract confirmSelection(), applySelectorData(),
 * updateSelection() and closeModal() from the IIFE with the balanced-brace
 * method extractor (same harness family as startScanProcessingDeadlock.test.js)
 * and compose them into a module object so `this.` references behave exactly
 * like in the IIFE. jQuery is faked at the minimal surface these functions
 * touch.
 */

const fs = require('fs');
const path = require('path');

const SEATING_PATH = path.resolve(__dirname, '..', 'js', 'seating_frontend.js');

function loadSource() {
    if (!fs.existsSync(SEATING_PATH)) {
        throw new Error('seating_frontend.js not found at ' + SEATING_PATH);
    }
    return fs.readFileSync(SEATING_PATH, 'utf8');
}

/**
 * Extract an object-method body (`name: function(...) {...}`) with a
 * string/comment-aware walker. Balance parens AND braces — a walker that
 * stops at the first `}` truncates any function containing nested blocks
 * (see et-backendjs-section-pattern skill, js_tests sandbox section).
 */
function extractMethod(source, name) {
    const re = new RegExp(name + '\\s*:\\s*function', 'g');
    let found = null;
    let m;
    while ((m = re.exec(source)) !== null) {
        const before = m.index > 0 ? source[m.index - 1] : undefined;
        if (before === undefined || /\s/.test(before) || before === ';' || before === ',' || before === '{') {
            found = m;
            break;
        }
    }
    if (!found) {
        throw new Error('Method ' + name + ' not found in source');
    }
    let i = found.index + found[0].length;
    while (i < source.length && source[i] !== '(') i++;
    let depth = 1;
    i++;
    for (; i < source.length; i++) {
        const ch = source[i];
        if (ch === '(') depth++;
        else if (ch === ')') {
            depth--;
            if (depth === 0) { i++; break; }
        }
    }
    if (i >= source.length) throw new Error('No body for ' + name);
    while (i < source.length && /\s/.test(source[i])) i++;
    if (source[i] !== '{') throw new Error('Expected { after params for ' + name);

    depth = 1;
    const start = i;
    i++;
    let inString = false;
    let stringChar = '';
    let inLineComment = false;
    let inBlockComment = false;
    for (; i < source.length; i++) {
        const ch = source[i];
        const next = source[i + 1];
        if (inLineComment) {
            if (ch === '\n') inLineComment = false;
            continue;
        }
        if (inBlockComment) {
            if (ch === '*' && next === '/') { inBlockComment = false; i++; }
            continue;
        }
        if (inString) {
            if (ch === '\\') { i++; continue; }
            if (ch === stringChar) inString = false;
            continue;
        }
        if (ch === '/' && next === '/') { inLineComment = true; i++; continue; }
        if (ch === '/' && next === '*') { inBlockComment = true; i++; continue; }
        if (ch === '"' || ch === "'" || ch === '`') {
            inString = true;
            stringChar = ch;
            continue;
        }
        if (ch === '{') depth++;
        else if (ch === '}') {
            depth--;
            if (depth === 0) {
                return source.slice(start, i + 1);
            }
        }
    }
    throw new Error('Unterminated method ' + name);
}

// --- minimal jQuery fakes for the surface the extracted methods touch -----

function makeInput() {
    const el = {
        _val: '',
        vals: [],
        val(v) {
            if (arguments.length === 0) return el._val;
            el._val = v;
            el.vals.push(v);
            return el;
        },
    };
    return el;
}

function makeSelector(input) {
    const el = {
        length: 1,
        attrs: {},
        dataMap: {},
        find(sel) {
            if (sel === '.saso-seat-selection-input') return input;
            return el;
        },
        children() {
            return {
                not() {
                    return {
                        remove() { return el; },
                    };
                },
            };
        },
        attr(k, v) {
            if (arguments.length >= 2) { el.attrs[k] = v; return el; }
            return el.attrs[k];
        },
        data(k, v) {
            if (arguments.length >= 2) { el.dataMap[k] = v; return el; }
            return el.dataMap[k];
        },
        first() { return el; },
        closest() { return el; },
        addClass() { return el; },
        removeClass() { return el; },
        html() { return el; },
        text() { return el; },
        append() { return el; },
        appendTo() { return el; },
        prepend() { return el; },
        after() { return el; },
        before() { return el; },
        show() { return el; },
        css() { return el; },
        on() { return el; },
    };
    return el;
}

function makeWrapper($selector) {
    return {
        find(sel) {
            if (sel === '.saso-seating-selector') return $selector;
            return $selector;
        },
    };
}

function makeModal() {
    const el = {
        removeClassCalls: [],
        find() { return el; },
        removeClass(cls) { el.removeClassCalls.push(cls); return el; },
        hasClass() { return false; },
        prepend() { return el; },
    };
    return el;
}

// Chainable stand-in for global `$()` calls inside closeModal()
// ($('.saso-seat-map-overlay'), $('body')).
function chainable() {
    const o = {};
    const methods = ['removeClass', 'addClass', 'css', 'find', 'html', 'text', 'append', 'appendTo', 'on', 'attr'];
    for (const m of methods) o[m] = () => o;
    return o;
}

const SEAT_SELECTION = {
    seat_id: 15,
    seat_label: 'A15',
    seat_category: 'VIP',
    seat_desc: '',
};

// Server-side existingBlocks entry as buildSelectorData() delivers it:
// remaining_seconds present, owner_token deliberately NOT included.
function existingBlock(seatId, blockId, remainingSeconds) {
    return {
        seat_id: seatId,
        seat_label: 'A' + seatId,
        seat_category: 'VIP',
        seat_desc: '',
        block_id: blockId,
        expires_at: '2026-10-31 20:10:00',
        remaining_seconds: remainingSeconds,
    };
}

function selectorData(overrides) {
    return Object.assign({
        planId: 7,
        variationId: 123,
        planName: 'Main Hall',
        layoutType: 'visual',
        seats: [
            { id: 15, seat_identifier: 'A15', meta: { seat_label: 'A15' } },
            { id: 16, seat_identifier: 'A16', meta: { seat_label: 'A16' } },
        ],
        currentSelection: null,
        existingBlocks: [],
        meta: {},
    }, overrides || {});
}

function buildSandbox(opts) {
    opts = opts || {};
    const source = loadSource();
    const confirmBody = extractMethod(source, 'confirmSelection');
    const applyBody = extractMethod(source, 'applySelectorData');
    const updateSelBody = extractMethod(source, 'updateSelection');
    const closeModalBody = extractMethod(source, 'closeModal');
    const restoreBody = extractMethod(source, 'restoreSeatsFromSelectorData');
    const belongsBody = extractMethod(source, 'selectionBelongsToPlan');
    const trackBody = extractMethod(source, 'trackRestoredBlocks');

    const input = makeInput();
    const selector = makeSelector(input);
    const wrapper = makeWrapper(selector);

    const calls = {
        updateButtonText: [],
        buildSelectorUI: [],
        setStatus: [],
        blockSeatsInBackground: [],
    };

    const factory = new Function(
        'deps',
        'var $ = deps.$;\n'
        + 'var __ = function(s) { return s; };\n'
        + 'var sasoSeatingData = deps.sasoSeatingData;\n'
        + 'function updateSelection($selector, seatsData) ' + updateSelBody + '\n'
        + 'function closeModal() ' + closeModalBody + '\n'
        + 'function confirmSelection() ' + confirmBody + '\n'
        + 'function applySelectorData($wrapper, productId, variationId, data) ' + applyBody + '\n'
        + 'function restoreSeatsFromSelectorData(data) ' + restoreBody + '\n'
        + 'function selectionBelongsToPlan(seats, data) ' + belongsBody + '\n'
        + 'function trackRestoredBlocks(seats) ' + trackBody + '\n'
        + 'return { updateSelection: updateSelection, closeModal: closeModal,'
        + ' confirmSelection: confirmSelection, applySelectorData: applySelectorData,'
        + ' restoreSeatsFromSelectorData: restoreSeatsFromSelectorData,'
        + ' selectionBelongsToPlan: selectionBelongsToPlan,'
        + ' trackRestoredBlocks: trackRestoredBlocks };'
    );

    const fns = factory({
        $: chainable,
        sasoSeatingData: { blockOnAddToCart: false, fieldName: 'sasoEventtickets_seat_selection' },
    });

    const sandbox = {
        selectedSeats: [],
        tempSelections: [],
        currentBlockIds: {},
        currentBlockOwners: {},
        $currentModal: null,
        $currentSelector: null,
        input: input,
        selector: selector,
        wrapper: wrapper,
        calls: calls,
        stopAutoRefresh() {},
        updateButtonText($s, seats) { calls.updateButtonText.push(seats); },
        buildSelectorUI($s, data) { calls.buildSelectorUI.push(data); },
        setStatus($s, msg, type) { calls.setStatus.push([msg, type]); },
        blockSeatsInBackground($s, seats) { calls.blockSeatsInBackground.push(seats); },
    };
    sandbox.updateSelection = fns.updateSelection;
    sandbox.closeModal = fns.closeModal;
    sandbox.confirmSelection = fns.confirmSelection;
    sandbox.applySelectorData = fns.applySelectorData;
    sandbox.restoreSeatsFromSelectorData = fns.restoreSeatsFromSelectorData;
    sandbox.selectionBelongsToPlan = fns.selectionBelongsToPlan;
    sandbox.trackRestoredBlocks = fns.trackRestoredBlocks;

    if (opts.modal) {
        sandbox.$currentModal = makeModal();
    }
    return sandbox;
}

function parseVal(input) {
    const v = input.val();
    if (v === '' || v === undefined || v === null) return '';
    return JSON.parse(v);
}

describe('seating_frontend: internal refresh after confirmSelection keeps the selection', () => {
    test('refresh with session existingBlocks right after confirm keeps the hidden input filled (server truth wins)', () => {
        const sandbox = buildSandbox();
        sandbox.$currentSelector = sandbox.selector;
        sandbox.tempSelections = [Object.assign({}, SEAT_SELECTION)];

        // Confirm: hidden input set immediately (pre-existing behaviour)
        sandbox.confirmSelection();
        expect(sandbox.input.val()).not.toBe('');
        expect(parseVal(sandbox.input)).toHaveLength(1);

        // ~1s later: the getSelectorData response lands, carrying the
        // session's just-created block as existingBlocks.
        sandbox.applySelectorData(sandbox.wrapper, 42, 123, selectorData({
            existingBlocks: [existingBlock(15, 401, 600)],
        }));

        // THE bug: the input must still carry the selection.
        const kept = parseVal(sandbox.input);
        expect(kept).toHaveLength(1);
        expect(kept[0].seat_id).toBe(15);
        // Server truth (block id) is now part of the selection:
        expect(kept[0].block_id).toBe(401);
        expect(sandbox.selectedSeats).toHaveLength(1);
    });

    test('refresh that beats the blockSeat write (no existingBlocks yet) keeps the local selection', () => {
        const sandbox = buildSandbox();
        sandbox.$currentSelector = sandbox.selector;
        sandbox.tempSelections = [Object.assign({}, SEAT_SELECTION)];

        sandbox.confirmSelection();
        // Local state already carries the block id (blockSeat response landed
        // before the refresh response):
        sandbox.selectedSeats = [Object.assign({}, SEAT_SELECTION, { block_id: 401, owner_token: 'tok_abc' })];
        sandbox.currentBlockIds[15] = 401;
        sandbox.currentBlockOwners[15] = 'tok_abc';
        sandbox.updateSelection(sandbox.selector, sandbox.selectedSeats);

        sandbox.applySelectorData(sandbox.wrapper, 42, 123, selectorData({
            existingBlocks: [],
        }));

        // Requirement (a): never blindly val('') while selectedSeats is
        // non-empty — the input keeps the local (valid) selection.
        const kept = parseVal(sandbox.input);
        expect(kept).toHaveLength(1);
        expect(kept[0].seat_id).toBe(15);
        expect(kept[0].block_id).toBe(401);
        expect(sandbox.selectedSeats).toHaveLength(1);
        expect(sandbox.currentBlockIds[15]).toBe(401);
    });

    test('existingBlocks restore path repopulates the hidden input in updateSelection format (no local selection)', () => {
        const sandbox = buildSandbox();
        // Page-style restore: nothing selected locally, server reports the
        // session's live blocks (e.g. reload / refresh after blockSeat).
        sandbox.input.val('');

        sandbox.applySelectorData(sandbox.wrapper, 42, 123, selectorData({
            existingBlocks: [existingBlock(15, 401, 600), existingBlock(16, 402, 0) /* expired */],
        }));

        expect(sandbox.selectedSeats).toHaveLength(1);
        const restored = parseVal(sandbox.input);
        expect(Array.isArray(restored)).toBe(true);
        expect(restored).toHaveLength(1);
        expect(restored[0].seat_id).toBe(15);
        expect(restored[0].block_id).toBe(401);
        // same shape updateSelection() writes (updateButtonText countdowns rely on it)
        expect(typeof restored[0].countdown_end).toBe('number');
        // block ids tracked for later release
        expect(sandbox.currentBlockIds[15]).toBe(401);
    });

    test('genuine reset stays a reset: no local selection, no existingBlocks empties the input', () => {
        const sandbox = buildSandbox();
        sandbox.input.val('');

        sandbox.applySelectorData(sandbox.wrapper, 42, 123, selectorData({ existingBlocks: [] }));

        expect(sandbox.selectedSeats).toHaveLength(0);
        expect(sandbox.input.val()).toBe('');
    });

    test('refresh with a different plan drops a selection that does not belong to the new plan', () => {
        const sandbox = buildSandbox();
        sandbox.$currentSelector = sandbox.selector;
        sandbox.tempSelections = [Object.assign({}, SEAT_SELECTION)];
        sandbox.confirmSelection();

        // New plan does not contain seat 15 (onVariationChanged already
        // released it via clearSelection in the real flow — here the server
        // data is the guard).
        sandbox.applySelectorData(sandbox.wrapper, 42, 456, selectorData({
            seats: [{ id: 99, seat_identifier: 'B1', meta: { seat_label: 'B1' } }],
            existingBlocks: [],
        }));

        expect(sandbox.selectedSeats).toHaveLength(0);
        expect(sandbox.input.val()).toBe('');
    });

    test('race-safe ordering: refresh during an open modal closes it before rebuilding', () => {
        const sandbox = buildSandbox({ modal: true });
        sandbox.$currentSelector = sandbox.selector;
        sandbox.tempSelections = [Object.assign({}, SEAT_SELECTION)];
        sandbox.confirmSelection();

        // Modal re-opened while the refresh response is in flight:
        sandbox.$currentModal = makeModal();

        sandbox.applySelectorData(sandbox.wrapper, 42, 123, selectorData({
            existingBlocks: [existingBlock(15, 401, 600)],
        }));

        // The refresh must not orphan the modal reference and must not wipe
        // the selection either.
        expect(sandbox.$currentModal).toBeNull();
        const kept = parseVal(sandbox.input);
        expect(kept).toHaveLength(1);
    });

    test('confirmSelection closes the modal AFTER the hidden input is set (ordering pin)', () => {
        const sandbox = buildSandbox({ modal: true });
        sandbox.$currentSelector = sandbox.selector;
        sandbox.tempSelections = [Object.assign({}, SEAT_SELECTION)];

        sandbox.confirmSelection();

        expect(parseVal(sandbox.input)).toHaveLength(1);
        expect(sandbox.$currentModal).toBeNull();
        expect(sandbox.tempSelections).toHaveLength(0);
    });
});
