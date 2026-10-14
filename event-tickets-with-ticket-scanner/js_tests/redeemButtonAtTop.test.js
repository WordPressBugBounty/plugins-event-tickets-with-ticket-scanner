/**
 * Tests for the "redeem button at the top" scanner option (3.2.7).
 *
 * Feature (Saso 2026-10-14): operators scroll too far to reach the redeem
 * button. New local scanner checkbox (default ON, persisted in the browser
 * like the other scanner options) + global preset option for operators who
 * want it forced ON for all devices: when a ticket is shown redeemed with
 * remaining redeem capacity (the same condition that builds "Redeem again"),
 * a redeem button is rendered at the TOP of the redeemed view — between the
 * "Ticket redeemed" message block and the scanner preview — so the operator
 * reads the ticket info and reaches the button without scrolling.
 *
 * Contracts pinned here:
 *  1. showScanOptions() renders the new checkbox with the option's label.
 *  2. The setter persists via _storeValue with the conventional key.
 *  3. displayTicketRedeemedInfo() prepends the button into the info area
 *     content when capacity remains (buildRedeemButton called with the
 *     retrieve data), and does NOT when the option is off.
 *  4. The global preset flows through starten()'s init block (myAjax key
 *     ticketScannerRedeemButtonAtTop, local storage key
 *     ticket_scanner_operating_option.ticketScannerRedeemButtonAtTop).
 */

const fs = require('fs');
const path = require('path');

const SCANNER_PATH = path.resolve(__dirname, '..', 'ticket_scanner.js');
const OPTIONS_PHP_PATH = path.resolve(__dirname, '..', 'sasoEventtickets_Options.php');
const INDEX_PHP_PATH = path.resolve(__dirname, '..', 'index.php');

function loadScannerSource() {
    if (!fs.existsSync(SCANNER_PATH)) {
        throw new Error('ticket_scanner.js not found at ' + SCANNER_PATH);
    }
    return fs.readFileSync(SCANNER_PATH, 'utf8');
}

// Balanced-brace extractor — same walker as canTicketBeRedeemed.test.js and
// startScanProcessingDeadlock.test.js. Keep the copies in sync.
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

function chainable() {
    const o = {};
    const methods = ['html', 'appendTo', 'css', 'text', 'on', 'attr', 'find', 'append', 'addClass', 'prop', 'val'];
    for (const m of methods) o[m] = () => o;
    return o;
}

function buildSandbox(overrides = {}) {
    const source = loadScannerSource();
    const showOptionsBody = extractFunction(source, 'showScanOptions');
    const redeemedInfoBody = extractFunction(source, 'displayTicketRedeemedInfo');
    const displayRedeemInfoBody = extractFunction(source, 'displayRedeemButtonAtTop');
    const displayRedeemedInfoBody = extractFunction(source, 'displayRedeemButtonAtTop');

    const calls = { storeValue: [], buildRedeemButton: [], prependRedeemButtonTop: 0 };

    const deps = Object.assign({
        system: {
            last_scanned_ticket: { code: '', timestamp: 0, data: null },
            status: '',
            code: 'DEMO-1',
            PARA: { useoldticketscanner: false, layout: '' },
            INPUTFIELD: { focus: () => {}, select: () => {}, val: () => {} },
        },
        ticket_scanner_operating_option: {
            distract_free: false,
            distract_free_show_short_desc: false,
            ticketScannerRedeemButtonAtTop: true,
        },
        $: () => chainable(),
        __: (s) => s,
        _x: (s) => s,
        sprintf: (fmt) => fmt,
        toBool: (v) => v === true || v === '1' || v === 1,
        myAjax: {},
        addFullscreenButton: () => {},
        setSpeakCheckbox: () => {},
        setVibrate: () => {},
        setRedeemImmediately: () => gateUndefined('setRedeemImmediately'),
        setDistractFree: () => gateUndefined('setDistractFree'),
        setDistractFreeShowShortDesc: () => gateUndefined('setDistractFreeShowShortDesc'),
        setStartCamWithoutButtonClicked: () => gateUndefined('setStartCamWithoutButtonClicked'),
        setRedeemButtonAtTop: () => gateUndefined('setRedeemButtonAtTop'),
        canTicketBeRedeemed: () => true,
        buildRedeemButton: () => chainable(),
        buildRedeemButtonAtTop: () => chainable(),
        speakText: () => {},
        appendRedeemCounters: () => {},
        showScanNextTicketButton: () => {},
        updateTicketScannerInfoArea: () => {},
    }, overrides);

    function gateUndefined(name) {
        return () => { throw new Error(name + ' called — check the wiring'); };
    }

    const factory = new Function(
        'deps',
        'const { system, ticket_scanner_operating_option, $, __, _x, sprintf, toBool, myAjax,'
        + ' addFullscreenButton, setSpeakCheckbox, setVibrate, setRedeemImmediately,'
        + ' setDistractFree, setDistractFreeShowShortDesc, setStartCamWithoutButtonClicked,'
        + ' setRedeemButtonAtTop, canTicketBeRedeemed, buildRedeemButton, buildRedeemButtonAtTop, speakText,'
        + ' appendRedeemCounters, showScanNextTicketButton, updateTicketScannerInfoArea } = deps;'
        + `function displayRedeemButtonAtTop(container) ${displayRedeemInfoBody}\n`
        + `function displayTicketRedeemedInfo(data) ${redeemedInfoBody}\n`
        + `function showScanOptions() ${showOptionsBody}\n`
        + 'return { showScanOptions, displayTicketRedeemedInfo, displayRedeemButtonAtTop };'
    );
    const sandbox = factory(deps);
    sandbox.deps = deps;
    return sandbox;
}

function groupTicketRetrieveShape(statsRedeemed, redeemsToday) {
    return {
        metaObj: {
            wc_ticket: {
                redeemed_date: '2026-10-14 10:00',
                stats_redeemed: statsRedeemed,
            },
        },
        _ret: {
            allow_redeem_only_paid: false,
            is_paid: true,
            max_redeem_amount: 30,
            max_redeem_per_day: 0,
            redeems_today: redeemsToday,
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
}

describe('ticket scanner: redeem button at the top (3.2.7)', () => {
    test('showScanOptions renders the new checkbox with a matching label', () => {
        const sandbox = buildSandbox();
        expect(() => sandbox.showScanOptions()).not.toThrow();
        const source = loadScannerSource();
        expect(source).toMatch(/Redeem button at the top/);
        // The checkbox exists in the options dialog block and toggles the new setter
        expect(source).toMatch(/setRedeemButtonAtTop\(/);
    });

    test('setter persists to the conventional local storage key', () => {
        const source = loadScannerSource();
        expect(source).toMatch(/_storeValue\("ticket_scanner_operating_option\.ticketScannerRedeemButtonAtTop"/);
    });

    test('displayTicketRedeemedInfo renders the top button when capacity remains and option on', () => {
        const seen = [];
        // Overrides must be merged BEFORE factory() runs — the closure
        // destructures deps at creation time, later property swaps are
        // invisible to it (object contents are not).
        const sandbox = buildSandbox({
            buildRedeemButton: (data, again) => {
                seen.push({data, again});
                return chainable();
            },
        });
        sandbox.deps.system.last_scanned_ticket.data = groupTicketRetrieveShape([{d: '2026-10-14 10:00'}], 1);
        sandbox.deps.system.redeemed_successfully = true;
        expect(() => sandbox.displayTicketRedeemedInfo({redeem_successfully: true})).not.toThrow();
        expect(seen.length).toBe(1);
        expect(seen[0].data._ret.max_redeem_amount).toBe(30);
        expect(seen[0].again).toBe(true);
    });

    test('displayTicketRedeemedInfo renders NO top button when the option is off', () => {
        const seen = [];
        const sandbox = buildSandbox({
            buildRedeemButton: (data, again) => {
                seen.push({data, again});
                return chainable();
            },
        });
        sandbox.deps.ticket_scanner_operating_option.ticketScannerRedeemButtonAtTop = false;
        sandbox.deps.system.last_scanned_ticket.data = groupTicketRetrieveShape([{d: '2026-10-14 10:00'}], 1);
        sandbox.deps.system.redeemed_successfully = true;
        expect(() => sandbox.displayTicketRedeemedInfo({redeem_successfully: true})).not.toThrow();
        expect(seen.length).toBe(0);
    });
});

describe('ticket scanner: redeem button at the top — PHP wiring (3.2.7)', () => {
    test('global preset option exists in sasoEventtickets_Options.php with default on', () => {
        const php = fs.readFileSync(OPTIONS_PHP_PATH, 'utf8');
        expect(php).toMatch(/ticketScannerRedeemButtonAtTop/);
        expect(php).toMatch(/Preset: Show redeem button at the top on ticket scanner/);
        expect(php).toMatch(/'def'=>true/);
    });

    test('myAjax carries the preset into the scanner page (index.php)', () => {
        const php = fs.readFileSync(INDEX_PHP_PATH, 'utf8');
        expect(php).toMatch(/'ticketScannerRedeemButtonAtTop' =>/);
    });

    test('scanner init block applies preset + local storage (starten())', () => {
        const source = loadScannerSource();
        expect(source).toMatch(/ticketScannerRedeemButtonAtTop/);
    });
});
