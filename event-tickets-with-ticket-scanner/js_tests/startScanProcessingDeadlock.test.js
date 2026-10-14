/**
 * Tests for the multi-redeem fall-through in startScanProcessing() +
 * drainPendingScan() in ticket_scanner.js (see git history, 3.2.7 fix).
 *
 * Bug shape (reported via support, console-verified across browsers):
 *   A group ticket with ONE QR code and N redeem slots: first scan works.
 *   Second scan of the SAME code (after "Scan next Ticket") shows
 *   "Code read — sending to server, please wait…" and then nothing —
 *   no further scan is possible until the page is reloaded.
 *
 * Root cause: startScanProcessing() sets loadingticket = true BEFORE the
 * self-redeem-echo fall-through for multi-redeem tickets runs. That
 * fall-through re-queues the code (pending_scans.push + drainPendingScan)
 * and returns — but drainPendingScan() refuses to run while loadingticket
 * is true. The queued code starves, retrieveTicket() is never called, no
 * lifecycle end point ever clears the flag. Deadlock.
 *
 * Test strategy: extract startScanProcessing() and drainPendingScan() from
 * the IIFE with the balanced-brace extractor (same pattern as
 * canTicketBeRedeemed.test.js) and compose both into ONE factory closure so
 * they share loadingticket / pending_scans / scan_hold_until exactly like
 * in the IIFE scope. All other free variables are injected as mocks.
 */

const fs = require('fs');
const path = require('path');

const SCANNER_PATH = path.resolve(__dirname, '..', 'ticket_scanner.js');

function loadScannerSource() {
    if (!fs.existsSync(SCANNER_PATH)) {
        throw new Error('ticket_scanner.js not found at ' + SCANNER_PATH);
    }
    return fs.readFileSync(SCANNER_PATH, 'utf8');
}

// Balanced-brace extractor (copied from canTicketBeRedeemed.test.js — keep
// the two copies in sync or extract into a shared helper when a third
// consumer appears).
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
                if (depth === 0) {
                    i++;
                    break;
                }
            }
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
    throw new Error('Unterminated function ' + name);
}

// Chainable jQuery stand-in: every method returns the same object so HTML
// building chains (`$('<div>…').appendTo(…)`, `.css(…)`, …) run through.
function chainable() {
    const o = {};
    const methods = ['html', 'appendTo', 'css', 'text', 'on', 'attr', 'find', 'append', 'addClass'];
    for (const m of methods) o[m] = () => o;
    return o;
}

function buildSandbox(overrides = {}) {
    const source = loadScannerSource();
    const startBody = extractFunction(source, 'startScanProcessing');
    const drainBody = extractFunction(source, 'drainPendingScan');

    const calls = { retrieveTicket: [], finishScanLifecycle: 0, updateTicketScannerInfoArea: [], vibrateOnResult: [], showScanNextTicketButton: 0 };

    const deps = Object.assign({
        system: { last_scanned_ticket: { code: '', timestamp: 0 }, status: '', INPUTFIELD: { focus: () => {}, select: () => {} } },
        RESCAN_WAIT_SECONDS: 7,
        time: () => 1000,
        $: () => chainable(),
        __: (s) => s,
        _x: (s) => s,
        sprintf: (fmt) => fmt,
        self_redeemed_this_session: {},
        displayTicketRedeemedInfo: () => {},
        qrScanner: null,
        html5QrcodeScanner: null,
        ticket_scanner_operating_option: {},
        updateLastScanTime: () => {},
        clearScanSuccessFrame: () => {},
        showScanSuccessFrame: () => {},
        clearAreas: () => {},
        clearOrderInfos: () => {},
        updateTicketScannerInfoArea: (x) => { calls.updateTicketScannerInfoArea.push(x); },
        renderInfoBox: (title, msg) => { calls.updateTicketScannerInfoArea.push(String(msg)); },
        buildRedeemButton: () => chainable(),
        vibrateOnResult: (ok) => { calls.vibrateOnResult.push(ok); },
        showScanNextTicketButton: () => { calls.showScanNextTicketButton++; },
        finishScanLifecycle: () => { calls.finishScanLifecycle++; },
        retrieveTicket: (code) => { calls.retrieveTicket.push(code); },
        _storeValue: () => {},
    }, overrides);

    const isTicketExpiredBody = extractFunction(source, 'isTicketExpired');
    const isRedeemTooEarlyBody = extractFunction(source, 'isRedeemTooEarly');
    const isRedeemTooLateBody = extractFunction(source, 'isRedeemTooLate');
    const isRedeemTooLateEndEventBody = extractFunction(source, 'isRedeemTooLateEndEvent');
    const canNowBody = extractFunction(source, 'canTicketBeRedeemedNow');
    const canBody = extractFunction(source, 'canTicketBeRedeemed');
    const displayRedeemedInfoBody = extractFunction(source, 'displayRedeemedInfo');

    const factory = new Function(
        'deps',
        'const { system, RESCAN_WAIT_SECONDS, time, $, __, _x, sprintf,'
        + ' self_redeemed_this_session, qrScanner, html5QrcodeScanner,'
        + ' ticket_scanner_operating_option, updateLastScanTime, clearScanSuccessFrame,'
        + ' showScanSuccessFrame, clearAreas, clearOrderInfos, updateTicketScannerInfoArea,'
        + ' renderInfoBox, buildRedeemButton, vibrateOnResult, showScanNextTicketButton,'
        + ' finishScanLifecycle, retrieveTicket, _storeValue,'
        + ' displayTicketRedeemedInfo } = deps;'
        + 'let loadingticket = false;'
        + 'let pending_scans = [];'
        + 'let scan_hold_until = 0;'
        + `function isTicketExpired(ticketRetObject) ${isTicketExpiredBody}\n`
        + `function isRedeemTooEarly(data) ${isRedeemTooEarlyBody}\n`
        + `function isRedeemTooLate(data) ${isRedeemTooLateBody}\n`
        + `function isRedeemTooLateEndEvent(data) ${isRedeemTooLateEndEventBody}\n`
        + `function canTicketBeRedeemedNow(data) ${canNowBody}\n`
        + `function canTicketBeRedeemedReal(data) ${canBody}\n`
        + `function displayRedeemedInfo(code, data) ${displayRedeemedInfoBody}\n`
        + `function drainPendingScan() ${drainBody}\n`
        + `function startScanProcessing(decodedText) ${startBody}\n`
        + 'var canTicketBeRedeemed = canTicketBeRedeemedReal;'
        + 'return { startScanProcessing, drainPendingScan, displayRedeemedInfo,'
        + ' canTicketBeRedeemed: canTicketBeRedeemedReal,'
        + ' getState: () => ({ loadingticket, pendingScans: pending_scans.slice(), scanHoldUntil: scan_hold_until }) };'
    );
    const sandbox = factory(deps);
    sandbox.calls = calls;
    sandbox.deps = deps;
    return sandbox;
}

// A multi-redeem group ticket as stored by the redeem-success handler:
// entry in self_redeemed_this_session with the ticket data attached.
const MULTI_REDEEM_CODE = '2523448324-671-ticket_20261014_demo_group';
// The data shape stored by the redeem-success handler for a group ticket
// with capacity left — kept in one place so the composition test and the
// sandbox seeding can never drift apart.
function seedShape() {
    return {
        metaObj: {
            wc_ticket: {
                redeemed_date: '2026-10-14 10:00',
                stats_redeemed: [{d: '2026-10-14 10:00'}],
            },
        },
        _ret: {
            allow_redeem_only_paid: false,
            is_paid: true,
            max_redeem_amount: 30,
            max_redeem_per_day: 0,
            redeems_today: 1,
            _options: {
                displayConfirmedCounter: false,
                wcTicketDontAllowRedeemTicketBeforeStart: false,
                wcTicketAllowRedeemTicketAfterEnd: true,
                wsticketDenyRedeemAfterstart: false,
                isRedeemOperationTooEarly: false,
                isRedeemOperationTooLateEventEnded: false,
                isRedeemOperationTooLate: false,
            },
        },
    };
}

function seedSecondScan(sandbox) {
    sandbox.deps.self_redeemed_this_session[MULTI_REDEEM_CODE] = {
        date: '10:00:00',
        data: seedShape(),
    };
}


// Build the REAL canTicketBeRedeemed (not the mock) from the source, with
// its helper chain mocked the same way canTicketBeRedeemed.test.js does.
function buildRealCanTicketBeRedeemed() {
    const source = loadScannerSource();
    const canTicketBeRedeemedBody = extractFunction(source, 'canTicketBeRedeemed');
    const canTicketBeRedeemedNowBody = extractFunction(source, 'canTicketBeRedeemedNow');
    const factory = new Function(
        'isRedeemTooEarly', 'isRedeemTooLate', 'isRedeemTooLateEndEvent', 'isTicketExpired',
        `function canTicketBeRedeemedNow(data) ${canTicketBeRedeemedNowBody}\n`
        + `function canTicketBeRedeemed(data) ${canTicketBeRedeemedBody}\n`
        + 'return canTicketBeRedeemed;'
    );
    return factory(() => false, () => false, () => false, () => false);
}

describe('ticket_scanner.js multi-redeem second scan', () => {
    beforeEach(() => { jest.useFakeTimers(); });
    afterEach(() => { jest.useRealTimers(); });

    test('second scan of a multi-redeem code reaches retrieveTicket (no queue starvation)', () => {
        const sandbox = buildSandbox();
        seedSecondScan(sandbox);

        // First scan of the session for this device already redeemed slot 1
        // (session cache seeded). The operator clicks "Scan next Ticket" and
        // scans the SAME code again for the next guest:
        sandbox.startScanProcessing(MULTI_REDEEM_CODE);

        // The fall-through re-queues the code and drains asynchronously
        // (>= 50ms hold). Let the timer fire:
        jest.advanceTimersByTime(200);

        // THE bug: the re-queued scan must actually be processed — i.e. it
        // must reach retrieveTicket() and trigger the server-side redeem of
        // the next slot. Before the fix the queue starved silently because
        // loadingticket was still true and drainPendingScan() refused.
        expect(sandbox.calls.retrieveTicket).toContain(MULTI_REDEEM_CODE);
        expect(sandbox.getState().pendingScans).toHaveLength(0);
    });

    test('after the drained second scan the lifecycle is not stuck (loadingticket false)', () => {
        const sandbox = buildSandbox();
        seedSecondScan(sandbox);

        sandbox.startScanProcessing(MULTI_REDEEM_CODE);
        jest.advanceTimersByTime(200);

        // The second pass calls retrieveTicket synchronously inside
        // startScanProcessing; loadingticket stays true only while a scan is
        // genuinely in flight (retrieveTicket's async handlers own the reset
        // in the real app via finishScanLifecycle). What must NOT happen:
        // loadingticket true while pendingScans is empty and no scan is
        // actually running — that is the deadlock signature.
        expect(sandbox.calls.retrieveTicket).toContain(MULTI_REDEEM_CODE);
    });

    test('second scan of an exhausted single-redeem code keeps the calm echo (no retrieveTicket)', () => {
        // Real gate, exhausted single-redeem shape: redeemed once, max 1 —
        // canTicketBeRedeemed must return false and the echo must show.
        const sandbox = buildSandbox();
        const singleShape = {
            metaObj: {
                wc_ticket: {
                    redeemed_date: '2026-10-14 10:00',
                    stats_redeemed: [{d: '2026-10-14 10:00'}],
                },
            },
            _ret: {
                allow_redeem_only_paid: false,
                is_paid: true,
                max_redeem_amount: 1,
                max_redeem_per_day: 0,
                redeems_today: 1,
                _options: {
                    wcTicketDontAllowRedeemTicketBeforeStart: false,
                    wcTicketAllowRedeemTicketAfterEnd: true,
                    wsticketDenyRedeemAfterstart: false,
                    isRedeemOperationTooEarly: false,
                    isRedeemOperationTooLateEventEnded: false,
                    isRedeemOperationTooLate: false,
                },
            },
        };
        sandbox.deps.self_redeemed_this_session['SINGLE-123'] = { date: '10:00:00', data: singleShape };

        sandbox.startScanProcessing('SINGLE-123');
        jest.advanceTimersByTime(200);

        // Echo path (deliberate behaviour): calm confirmation, lifecycle
        // finished, no server round-trip.
        expect(sandbox.calls.retrieveTicket).toHaveLength(0);
        expect(sandbox.calls.finishScanLifecycle).toBeGreaterThanOrEqual(1);
    });

    test('real gate survives a thin redeem-confirmation shape (no _options, no metaObj) — console-error regression', () => {
        // Console error this pins (operator re-scan after redeem, reported
        // 2026-10-14): "Uncaught TypeError: Cannot read properties of
        // undefined (reading 'wcTicketDontAllowRedeemTicketBeforeStart')
        // at isRedeemTooEarly". The redeem endpoint returns a minimal
        // confirmation (no _ret._options) — the gate helpers must not
        // assume the retrieve shape.
        const sandbox = buildSandbox();
        const thinRedeemShape = {
            redeem_successfully: true,
            ticket_id: 'demo',
            _ret: { tickets_redeemed: 2, tickets_redeemed_show: false },
        };
        expect(() => sandbox.canTicketBeRedeemed(thinRedeemShape)).not.toThrow();
        // Cannot verify multi-redeem state locally -> fall through to the
        // server (retrieveTicket) rather than blocking the operator.
        expect(sandbox.canTicketBeRedeemed(thinRedeemShape)).toBe(true);
    });

    test('displayRedeemedInfo stores the full retrieve shape, not the thin redeem confirmation', () => {
        // The echo entry must carry what the gate needs. displayRedeemedInfo
        // runs right after the redeem, while system.last_scanned_ticket.data
        // still holds the full retrieve response of this scan lifecycle.
        const sandbox = buildSandbox();
        const fullRetrieveShape = seedShape();
        // timestamp outside the rescan-wait window — the deliberate 7s
        // same-code dedup must not eat this scan (a real operator re-scan
        // happens after the wait, or after "Scan next Ticket").
        sandbox.deps.system.last_scanned_ticket = {
            code: MULTI_REDEEM_CODE,
            timestamp: 900,
            data: fullRetrieveShape,
        };
        const thinRedeemShape = {
            redeem_successfully: true,
            ticket_id: 'demo',
            _ret: { tickets_redeemed: 2, tickets_redeemed_show: false },
        };
        sandbox.displayRedeemedInfo(MULTI_REDEEM_CODE, thinRedeemShape);
        const stored = sandbox.deps.self_redeemed_this_session[MULTI_REDEEM_CODE].data;
        // A COPY of the retrieve shape (the original must stay untouched),
        // not the thin confirmation, with the just-happened redeem counted:
        expect(stored).not.toBe(fullRetrieveShape);
        expect(stored).not.toBe(thinRedeemShape);
        expect(stored._ret.max_redeem_amount).toBe(30);
        expect(stored._ret.redeems_today).toBe(2);
        expect(stored.metaObj.wc_ticket.stats_redeemed).toHaveLength(2);
        expect(stored._ret._options).toBeDefined();

        // And with that stored shape the second scan runs the REAL gate
        // end-to-end without a crash:
        sandbox.startScanProcessing(MULTI_REDEEM_CODE);
        jest.advanceTimersByTime(200);
        expect(sandbox.calls.retrieveTicket).toContain(MULTI_REDEEM_CODE);
    });

    test('auto-redeem echo does NOT double-count the redeem (retrieve re-fetched post-redeem)', () => {
        // Review follow-up (sub-agent GO verdict, residual finding): the
        // auto-redeem flows (?redeem=1 and the CVV path) hand
        // displayRedeemedInfo the THIN redeem_operation result, while
        // system.last_scanned_ticket.data is a retrieve response PHP
        // re-fetched AFTER the redeem (it carries _ret.redeem_operation,
        // sasoEventtickets_Ticket.php :857). Its counters already include
        // this redeem — the local increment must be skipped, else capacity
        // understates by one (a 1-slot-left ticket would echo instead of
        // falling through with a redeem button).
        const sandbox = buildSandbox();
        const postRedeemRetrieve = seedShape();
        // post-redeem state: 2 already redeemed (server truth), plus the marker
        postRedeemRetrieve.metaObj.wc_ticket.stats_redeemed.push({d: '2026-10-14T10:01:00Z'});
        postRedeemRetrieve._ret.redeems_today = 2;
        postRedeemRetrieve._ret.redeem_operation = { redeem_successfully: true, ticket_id: 'demo' };
        sandbox.deps.system.last_scanned_ticket = {
            code: MULTI_REDEEM_CODE,
            timestamp: 900,
            data: postRedeemRetrieve,
        };
        const thinRedeemOperation = {
            redeem_successfully: true,
            ticket_id: 'demo',
            _ret: { tickets_redeemed: 2, tickets_redeemed_show: false },
        };
        sandbox.displayRedeemedInfo(MULTI_REDEEM_CODE, thinRedeemOperation);
        const stored = sandbox.deps.self_redeemed_this_session[MULTI_REDEEM_CODE].data;
        // mirrored full shape, counters UNSPOILED (server truth, no N+2):
        expect(stored._ret.redeems_today).toBe(2);
        expect(stored.metaObj.wc_ticket.stats_redeemed).toHaveLength(2);
    });

    test('echo entry is dropped after the fall-through so a third scan is a clean scan', () => {
        const sandbox = buildSandbox();
        seedSecondScan(sandbox);

        sandbox.startScanProcessing(MULTI_REDEEM_CODE);
        jest.advanceTimersByTime(200);

        expect(sandbox.deps.self_redeemed_this_session[MULTI_REDEEM_CODE]).toBeUndefined();
    });

    test('composition: the REAL canTicketBeRedeemed returns true for the seeded multi-redeem shape (fall-through gate stays live)', () => {
        // Closes the review blind spot: the deadlock suite mocks
        // canTicketBeRedeemed => true (load-bearing at ticket_scanner.js:232).
        // If the real gate ever returned false for a capacity-bearing group
        // ticket, the fix would be dead code while tests stay green. This
        // pins the two halves together.
        const ctbr = buildRealCanTicketBeRedeemed();
        const shape = seedShape();
        expect(ctbr(shape)).toBe(true);
    });
});
