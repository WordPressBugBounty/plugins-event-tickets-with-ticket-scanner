/**
 * Geo-pricing resilience for the seating flow (3.3.0).
 *
 * Context: the separate plugin vollstart-geo-pricing (0.5.5) refreshes
 * product price blocks after an async geo resolution. On fresh visitors
 * without the geo cookie this rebuild re-initializes the seat selector
 * AFTER confirmSelection() and the hidden .saso-seat-selection-input can
 * end up empty at add-to-cart ("Please select 1 seat(s)"):
 *   (a) the rebuild can land in the window between confirm and the
 *       blockSeat response — applySelectorData then clears selectedSeats
 *       and the input (server reports no existingBlocks yet);
 *   (b) the rebuild can replace the input element entirely (new DOM node
 *       = the old node reference is gone, new node starts empty).
 *
 * Contract under test:
 *   (1) confirmSelection() remembers the selection on the instance
 *       (lastConfirmedSelection); block success keeps it in sync with the
 *       fresh block ids; clearSelection() and a genuine variation switch
 *       forget it (never resurrect removed/foreign seats);
 *   (2) the add-to-cart submit hook (form.cart, synchronous, runs before
 *       the POST leaves) refills an existing-but-empty input from the live
 *       selection, falling back to the remembered selection — and restores
 *       the instance state with it;
 *   (3) applySelectorData() re-applies the remembered selection to the NEW
 *       input node when the rebuild wiped/kept a fresh empty node and the
 *       selection belongs to the plan.
 *
 * Test strategy: same harness family as
 * seatingConfirmRefreshKeepsSelection.test.js — balanced-brace method
 * extractor + minimal jQuery fakes. The submit handler is an anonymous
 * function, so it is extracted via a marker-anchored brace walker and
 * invoked with `this` = the fake instance and $ = the fake form factory;
 * the $form.append(...) call stands in for the serialized POST payload.
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
 * Extract `name: function(...) {...}` with a string/comment-aware walker
 * (balanced parens + braces — see seatingConfirmRefreshKeepsSelection).
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
    return source.slice(i, balanceBrace(source, i) + 1);
}

/**
 * Extract the anonymous submit handler registered after `marker`
 * (`$(document).on('submit', 'form.cart', function(e) { ... })`).
 */
function extractSubmitHandler(source) {
    const marker = "on('submit', 'form.cart', function(e) {";
    const at = source.indexOf(marker);
    if (at < 0) throw new Error('submit handler marker not found');
    const bodyStart = at + marker.length - 1; // index of the handler's '{'
    return source.slice(bodyStart, balanceBrace(source, bodyStart) + 1);
}

function balanceBrace(source, start) {
    let depth = 0;
    let inString = false;
    let stringChar = '';
    let inLineComment = false;
    let inBlockComment = false;
    let lastSignificant = '';
    for (let i = start; i < source.length; i++) {
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
        if (ch === '/' && ')(,=:[!&|?+-*%~^<>;{'.indexOf(lastSignificant) > -1) {
            // regex literal (e.g. replace(/"/g, ...)) — skip to its unescaped end
            i++;
            for (; i < source.length; i++) {
                if (source[i] === '\\') { i++; continue; }
                if (source[i] === '[') {
                    // char class: a / inside needs no escape
                    i++;
                    for (; i < source.length && source[i] !== ']'; i++) {
                        if (source[i] === '\\') i++;
                    }
                    continue;
                }
                if (source[i] === '/') break;
            }
            lastSignificant = '/';
            continue;
        }
        if (ch === '"' || ch === "'" || ch === '`') {
            inString = true;
            stringChar = ch;
            continue;
        }
        if (ch === '{') depth++;
        else if (ch === '}') {
            depth--;
            if (depth === 0) return i;
        }
        if (!/\s/.test(ch)) lastSignificant = ch;
    }
    throw new Error('Unterminated block');
}

// --- minimal jQuery fakes -------------------------------------------------

function makeInput(emptyVal) {
    const el = {
        length: 1,
        _val: emptyVal || '',
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
        find(sel) {
            if (sel === '.saso-seat-selection-input') return input;
            return el;
        },
        children() {
            return {
                not() {
                    return { remove() { return el; } };
                },
            };
        },
        attr(k, v) {
            if (arguments.length >= 2) { el.attrs[k] = v; return el; }
            return el.attrs[k];
        },
        first() { return el; },
        addClass() { return el; },
        removeClass() { return el; },
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

function chainable() {
    const o = {};
    const methods = ['removeClass', 'addClass', 'css', 'find', 'html', 'text', 'append', 'appendTo', 'on', 'attr'];
    for (const m of methods) o[m] = () => o;
    return o;
}

// --- shared fixtures -------------------------------------------------------

const SEAT_SELECTION = {
    seat_id: 15,
    seat_label: 'A15',
    seat_category: 'VIP',
    seat_desc: '',
};

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

    const input = makeInput(opts.inputVal);
    const selector = makeSelector(input);
    const wrapper = makeWrapper(selector);

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
        lastConfirmedSelection: [],
        tempSelections: [],
        currentBlockIds: {},
        currentBlockOwners: {},
        $currentModal: null,
        $currentSelector: null,
        input: input,
        selector: selector,
        wrapper: wrapper,
        calls: {
            updateButtonText: [],
            buildSelectorUI: [],
            setStatus: [],
            blockSeatsInBackground: [],
        },
        stopAutoRefresh() {},
        updateButtonText($s, seats) { sandbox.calls.updateButtonText.push(seats); },
        buildSelectorUI($s, data) { sandbox.calls.buildSelectorUI.push(data); },
        setStatus($s, msg, type) { sandbox.calls.setStatus.push([msg, type]); },
        blockSeatsInBackground($s, seats) { sandbox.calls.blockSeatsInBackground.push(seats); },
    };
    sandbox.updateSelection = fns.updateSelection;
    sandbox.closeModal = fns.closeModal;
    sandbox.confirmSelection = fns.confirmSelection;
    sandbox.applySelectorData = fns.applySelectorData;
    sandbox.restoreSeatsFromSelectorData = fns.restoreSeatsFromSelectorData;
    sandbox.selectionBelongsToPlan = fns.selectionBelongsToPlan;
    sandbox.trackRestoredBlocks = fns.trackRestoredBlocks;

    return sandbox;
}

/**
 * Build the add-to-cart submit handler with an injectable instance (`self`)
 * and a fake form whose `find()` answers the field-name selector with
 * `formInput` (the — possibly replaced — input node). `$form.append(html)`
 * calls are recorded as the serialized POST payload.
 */
function buildSubmitHandler(instance, formInput, opts) {
    opts = opts || {};
    const source = loadSource();
    const handlerBody = extractSubmitHandler(source);

    const appends = [];
    function fakeDollar() {
        const form = {
            length: 1,
            find(sel) {
                if (sel === 'input[name="sasoEventtickets_seat_selection"]') {
                    return formInput;
                }
                if (sel === '.saso-seat-selection-input') {
                    return opts.selectorInput || formInput;
                }
                return form;
            },
            append(html) { appends.push(html); return form; },
        };
        return form;
    }

    const factory = new Function(
        'deps',
        'var $ = deps.$;\n'
        + 'var self = deps.self;\n'
        + 'var sasoSeatingData = deps.sasoSeatingData;\n'
        + 'return function(e) ' + handlerBody + ';'
    );
    const handler = factory({
        $: fakeDollar,
        self: instance,
        sasoSeatingData: { fieldName: 'sasoEventtickets_seat_selection' },
    });
    handler.call(instance, {});
    return appends;
}

function parseVal(input) {
    const v = input.val();
    if (v === '' || v === undefined || v === null) return '';
    return JSON.parse(v);
}

describe('seating_frontend: geo-pricing rebuild keeps the seat selection intact', () => {
    test('submit hook: input replaced + selection wiped by the rebuild — POST payload carries the remembered selection', () => {
        const seat = Object.assign({}, SEAT_SELECTION, { block_id: 401, owner_token: 'tok_abc' });
        // Geo rebuild wiped the live selection and left a fresh empty node.
        const instance = {
            selectedSeats: [],
            lastConfirmedSelection: [seat],
        };
        const replacedInput = makeInput('');

        const appends = buildSubmitHandler(instance, replacedInput);

        // The found-branch fills the NEW node from the remembered selection…
        expect(parseVal(replacedInput)).toHaveLength(1);
        expect(parseVal(replacedInput)[0].seat_id).toBe(15);
        expect(parseVal(replacedInput)[0].block_id).toBe(401);
        // …and restores the instance state, so post-submit logic keeps working.
        expect(instance.selectedSeats).toHaveLength(1);
        expect(instance.selectedSeats[0].block_id).toBe(401);
        // No extra input appended: the field exists in the form and now carries
        // the selection — the serialized POST payload includes it.
        expect(appends).toHaveLength(0);
        expect(replacedInput.val()).toContain('"seat_id":15');
    });

    test('submit hook: no field input in the form — hidden input is created from the remembered selection', () => {
        const seat = Object.assign({}, SEAT_SELECTION, { block_id: 402, owner_token: 'tok_def' });
        const instance = {
            selectedSeats: [],
            lastConfirmedSelection: [seat],
        };
        // formInput not found in the form (length 0)
        const formInput = makeInput('');
        formInput.length = 0;

        const appends = buildSubmitHandler(instance, formInput);

        expect(appends).toHaveLength(1);
        expect(appends[0]).toContain('name="sasoEventtickets_seat_selection"');
        expect(appends[0]).toContain('&quot;seat_id&quot;:15');
    });

    test('submit hook: live selection still wins over the remembered one', () => {
        const instance = {
            selectedSeats: [Object.assign({}, SEAT_SELECTION, { block_id: 500 })],
            lastConfirmedSelection: [Object.assign({}, SEAT_SELECTION, { block_id: 401 })],
        };
        const replacedInput = makeInput('');

        buildSubmitHandler(instance, replacedInput);

        expect(parseVal(replacedInput)[0].block_id).toBe(500);
    });

    test('submit hook: nothing selected anywhere leaves the payload empty (no ghosts)', () => {
        const instance = { selectedSeats: [], lastConfirmedSelection: [] };
        const replacedInput = makeInput('');

        const appends = buildSubmitHandler(instance, replacedInput);

        expect(replacedInput.val()).toBe('');
        expect(appends).toHaveLength(0);
    });

    test('applySelectorData: rebuild landing in the confirm→blockSeat window re-applies the remembered selection to the (new) input node', () => {
        const sandbox = buildSandbox();
        sandbox.$currentSelector = sandbox.selector;
        sandbox.tempSelections = [Object.assign({}, SEAT_SELECTION)];

        // Confirm remembers the selection and fills the input…
        sandbox.confirmSelection();
        expect(sandbox.lastConfirmedSelection).toHaveLength(1);
        // …then the geo rebuild wipes the live selection before the blockSeat
        // response lands (server reports no existingBlocks yet) and leaves a
        // fresh empty input node behind.
        sandbox.selectedSeats = [];
        sandbox.input.val('');

        sandbox.applySelectorData(sandbox.wrapper, 42, 123, selectorData({
            existingBlocks: [],
        }));

        // The remembered selection is re-applied to the NEW node in
        // updateSelection format and the instance state is restored.
        const kept = parseVal(sandbox.input);
        expect(kept).toHaveLength(1);
        expect(kept[0].seat_id).toBe(15);
        expect(sandbox.selectedSeats).toHaveLength(1);
        expect(sandbox.selectedSeats[0].seat_id).toBe(15);
    });

    test('genuine variation switch forgets the remembered selection (no cross-plan resurrection)', () => {
        const sandbox = buildSandbox();
        sandbox.$currentSelector = sandbox.selector;
        sandbox.tempSelections = [Object.assign({}, SEAT_SELECTION)];
        sandbox.confirmSelection();

        // The new plan does not contain seat 15.
        sandbox.selectedSeats = [];
        sandbox.input.val('');
        sandbox.applySelectorData(sandbox.wrapper, 42, 456, selectorData({
            seats: [{ id: 99, seat_identifier: 'B1', meta: { seat_label: 'B1' } }],
            existingBlocks: [],
        }));

        expect(sandbox.selectedSeats).toHaveLength(0);
        expect(sandbox.lastConfirmedSelection).toHaveLength(0);
        expect(sandbox.input.val()).toBe('');

        // …and a submit afterwards carries no seats.
        const instance = {
            selectedSeats: sandbox.selectedSeats,
            lastConfirmedSelection: sandbox.lastConfirmedSelection,
        };
        const replacedInput = makeInput('');
        const appends = buildSubmitHandler(instance, replacedInput);
        expect(replacedInput.val()).toBe('');
        expect(appends).toHaveLength(0);
    });

    test('clearSelection forgets the remembered selection (deselected seats are never resurrected)', () => {
        const source = loadSource();
        const clearBody = extractMethod(source, 'clearSelection');
        const factory = new Function(
            'deps',
            'var $ = deps.$;\n'
            + 'return { clearSelection: function($selector) ' + clearBody + ' };'
        );
        const fns = factory({ $: chainable });
        const sandbox = buildSandbox();
        sandbox.lastConfirmedSelection = [Object.assign({}, SEAT_SELECTION, { block_id: 401 })];
        sandbox.selectedSeats = [Object.assign({}, SEAT_SELECTION, { block_id: 401 })];

        fns.clearSelection.call(sandbox, sandbox.selector);

        expect(sandbox.selectedSeats).toHaveLength(0);
        expect(sandbox.lastConfirmedSelection).toHaveLength(0);
    });

    test('confirmSelection populates the memory; block success keeps block ids in sync', () => {
        const sandbox = buildSandbox();
        sandbox.$currentSelector = sandbox.selector;
        sandbox.tempSelections = [Object.assign({}, SEAT_SELECTION)];

        sandbox.confirmSelection();
        expect(sandbox.lastConfirmedSelection).toHaveLength(1);

        // blockSeat response lands: fresh block ids must reach the memory too.
        const blocked = [Object.assign({}, SEAT_SELECTION, { block_id: 777, countdown_end: Date.now() + 600000 })];
        sandbox.selectedSeats = blocked;
        sandbox.lastConfirmedSelection = blocked.slice();
        sandbox.updateSelection(sandbox.selector, blocked);
        expect(sandbox.lastConfirmedSelection[0].block_id).toBe(777);
    });
});
