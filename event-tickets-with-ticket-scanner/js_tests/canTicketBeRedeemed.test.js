/**
 * Tests for canTicketBeRedeemed() in ticket_scanner.js (#000356 Sharam).
 *
 * Bug (reported 2026-10-07 by Saso from id=3002 console log):
 *   Uncaught TypeError: Cannot read properties of undefined (reading 'wc_ticket')
 *     at canTicketBeRedeemed (ticket_scanner.js:1867:29)
 *     at startScanProcessing (ticket_scanner.js:233:79)
 *
 * Root cause: ticket_scanner.js:1867 reads data.metaObj['wc_ticket']
 * directly without first checking that data.metaObj and data.metaObj.wc_ticket
 * exist. The other data-shape-aware code paths in the file already do
 * defensive guards (lines 829, 1455, 1494); canTicketBeRedeemed() does not.
 *
 * The function is called from 6 places (233, 256, 528, 1961, plus two
 * internal recursive checks). Three of those callers only check `_d.data`
 * or `system.last_scanned_ticket.data`, not the metaObj/wc_ticket shape.
 *
 * Test strategy: ticket_scanner.js is a vanilla-IIFE monolith, not an ES
 * module. We extract the canTicketBeRedeemed function by re-evaluating the
 * source with a small AST/regex slice in jsdom. This keeps the test
 * independent of the IIFE's global state (no jQuery, no wpI18n) and
 * focuses the assertion on the data-shape contract — exactly what the bug
 * is about.
 *
 * We do NOT test the broader startScanProcessing() flow here (that path
 * requires the QR scanner lib, jQuery, the camera, etc.). The bug is
 * localised in canTicketBeRedeemed()'s data-shape handling. Future tests
 * can climb up the call chain once the consumer patch (#015130) lands and
 * the flow becomes testable in jsdom.
 */

const fs = require('fs');
const path = require('path');

// Source-of-truth path. The plugin's working tree puts ticket_scanner.js
// at the top level (it's enqueued by index.php:1584 with a ?_v=<version>
// cache-buster).
const SCANNER_PATH = path.resolve(__dirname, '..', 'ticket_scanner.js');

function loadScannerSource() {
    if (!fs.existsSync(SCANNER_PATH)) {
        throw new Error('ticket_scanner.js not found at ' + SCANNER_PATH);
    }
    return fs.readFileSync(SCANNER_PATH, 'utf8');
}

// Extract just the canTicketBeRedeemed function body. The source uses
// ES2015-ish function declarations inside the IIFE; a balanced-brace
// slice starting from the function keyword is robust enough for our
// scoped test (we don't care about the outer IIFE's identifiers — we
// supply our own data object).
//
// Returns the body INCLUDING the outer { ... } so it can be pasted
// directly into a function declaration template. Caller is expected
// to write `function <name>(data) <body>` — the closing `}` of the
// body is the closing `}` of the function declaration.
function extractFunction(source, name) {
        // Anchor on the full signature `function <name>(` to skip comments and
        // method calls that happen to contain the name (the source has both
        // `canTicketBeRedeemed(...)` call sites and the function declaration).
        const signatureRe = new RegExp('function\\s+' + name + '\\s*\\(', 'g');
        // Match ALL occurrences, then for each check whether the preceding
        // character is NOT an identifier char (so we don't match a call site
        // like `foo canTicketBeRedeemed(...)` — there's none in this codebase
        // but the guard is cheap). For this codebase the FIRST match is
        // already the declaration, but for robustness we walk through them.
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
        // Walk forward over the signature `function NAME(` and the parameter
        // list. The opening `{` of the body comes AFTER the parameter list's
        // closing `)`. We track paren depth starting from 1 (we're already
        // inside the function NAME's `(`).
        let depth = 1;  // we're inside the function's opening `(`
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
        // Skip whitespace until body opening brace
        while (i < source.length && /\s/.test(source[i])) i++;
        if (source[i] !== '{') throw new Error('Expected { after params for ' + name);

        // Walk the body braces. We start AT the opening `{` (depth becomes 1),
        // and stop when depth returns to 0 — the slice goes from the opening
        // `{` to the matching closing `}` inclusive. Caller must NOT add another
        // closing brace.
        depth = 1;
        const start = i;
        i++;
        let inString = false;
        let stringChar = '';
        let inLineComment = false;
        let inBlockComment = false;
        let inRegex = false;
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

describe('ticket_scanner.js', () => {
    describe('canTicketBeRedeemed()', () => {
        let source;
        beforeAll(() => { source = loadScannerSource(); });

        // Build a callable canTicketBeRedeemed() from the extracted body.
        // canTicketBeRedeemed() internally calls canTicketBeRedeemedNow(), which
        // calls four other helpers (isRedeemTooEarly / isRedeemTooLate /
        // isRedeemTooLateEndEvent / isTicketExpired). The bug we're testing lives
        // in canTicketBeRedeemed()'s direct handling of data.metaObj.wc_ticket —
        // the helper chain has its own data-shape assumptions and we don't want
        // to fail this suite if one of them changes. So we mock the helpers to
        // return true (the "happy path" that lets canTicketBeRedeemed() reach its
        // own logic). When those helpers become a test target themselves, they
        // get their own file in this directory.
        function buildFn() {
            // Extract the two functions from the IIFE. canTicketBeRedeemed()
            // calls canTicketBeRedeemedNow(), which calls four helpers. The
            // bug is in canTicketBeRedeemed()'s direct handling of
            // data.metaObj.wc_ticket — the helper chain has its own
            // data-shape assumptions and we mock them out so the test
            // focuses on the bug surface. When those helpers get their own
            // tests, the file grows.
            const canTicketBeRedeemedBody = extractFunction(source, 'canTicketBeRedeemed');
            const canTicketBeRedeemedNowBody = extractFunction(source, 'canTicketBeRedeemedNow');
            // Compose into a Function() with explicit parameter declarations:
            //   function canTicketBeRedeemedNow(data) <body>
            //   function canTicketBeRedeemed(data) <body>
            //   return canTicketBeRedeemed;
            // The mock helpers (isRedeemTooEarly etc.) are passed as
            // parameters and shadow any names in scope.
            const factory = new Function(
                'isRedeemTooEarly',
                'isRedeemTooLate',
                'isRedeemTooLateEndEvent',
                'isTicketExpired',
                `function canTicketBeRedeemedNow(data) ${canTicketBeRedeemedNowBody}\n`
                + `function canTicketBeRedeemed(data) ${canTicketBeRedeemedBody}\n`
                + `return canTicketBeRedeemed;`
            );
            return factory(
                (_data) => false,
                (_data) => false,
                (_data) => false,
                (_data) => false
            );
        }

            test('returns true on data without metaObj when the default-allow path applies (no crash)', () => {
                // When allow_redeem_only_paid is false AND metaObj is missing,
                // the function can't check redeemed_date — it does NOT crash
                // (the bug). It returns true because there's no evidence the
                // ticket should be blocked. Returning false here would silently
                // break single-redeem tickets whenever metaObj is missing for
                // an unrelated reason (a future code path), so the
                // conservative-but-correct behaviour is to allow the operator
                // to drive the manual flow, not to block.
                const fn = buildFn();
                expect(() => fn({
                    _ret: {
                        allow_redeem_only_paid: false,
                        is_paid: true,
                        max_redeem_amount: 1,
                        max_redeem_per_day: 0,
                        redeems_today: 0
                    }
                })).not.toThrow();
                expect(fn({
                    _ret: {
                        allow_redeem_only_paid: false,
                        is_paid: true,
                        max_redeem_amount: 1,
                        max_redeem_per_day: 0,
                        redeems_today: 0
                    }
                })).toBe(true);
            });

            test('does not throw on data with metaObj but no wc_ticket (partial server response)', () => {
                const fn = buildFn();
                expect(() => fn({
                    _ret: {
                        allow_redeem_only_paid: false,
                        is_paid: true,
                        max_redeem_amount: 1,
                        max_redeem_per_day: 0,
                        redeems_today: 0
                    },
                    metaObj: { /* no wc_ticket */ }
                })).not.toThrow();
            });

            test('does not throw when wc_ticket has no stats_redeemed (older server)', () => {
                const fn = buildFn();
                expect(() => fn({
                    _ret: {
                        allow_redeem_only_paid: false,
                        is_paid: true,
                        max_redeem_amount: 1,
                        max_redeem_per_day: 0,
                        redeems_today: 0
                    },
                    metaObj: {
                        wc_ticket: {
                            redeemed_date: ''
                            // no stats_redeemed
                        }
                    }
                })).not.toThrow();
            });

            test('treats a redeemed multi-redeem ticket as still redeemable when capacity left', () => {
                // The path that triggered the bug for Sharam: a 1×QR/N-guest
                // ticket redeemed once must still be redeemable for guests
                // #2..N. Pre-fix the crash happened BEFORE this branch could
                // run, so this behaviour was untested. Post-fix, the function
                // returns true.
                const fn = buildFn();
                expect(fn({
                    _ret: {
                        allow_redeem_only_paid: false,
                        is_paid: true,
                        max_redeem_amount: 3,
                        max_redeem_per_day: 0,
                        redeems_today: 1
                    },
                    metaObj: {
                        wc_ticket: {
                            redeemed_date: '2026-10-07 12:00',
                            stats_redeemed: [{d: '2026-10-07 12:00'}]
                        }
                    }
                })).toBe(true);
            });

            test('returns true for a fully-shaped ticket that is fresh (regression-safe)', () => {
                const fn = buildFn();
                const result = fn({
                    _ret: {
                        allow_redeem_only_paid: false,
                        is_paid: true,
                        max_redeem_amount: 1,
                        max_redeem_per_day: 0,
                        redeems_today: 0
                    },
                    metaObj: {
                        wc_ticket: {
                            redeemed_date: '',
                            stats_redeemed: []
                        }
                    }
                });
                expect(result).toBe(true);
            });

            test('returns false when the ticket is already redeemed (regression-safe)', () => {
                const fn = buildFn();
                const result = fn({
                    _ret: {
                        allow_redeem_only_paid: false,
                        is_paid: true,
                        max_redeem_amount: 1,
                        max_redeem_per_day: 0,
                        redeems_today: 0
                    },
                    metaObj: {
                        wc_ticket: {
                            redeemed_date: '2026-10-07 12:00',
                            stats_redeemed: [{d: '2026-10-07 12:00'}]
                        }
                    }
                });
                expect(result).toBe(false);
            });

            // The three tests below cover decision branches the original
            // 6-test set did not exercise. They were added after the
            // sub-agent code review (deleg_1e0530af, 2026-10-08) flagged
            // them as regression-safety holes. They do NOT add new
            // function behaviour; they pin down branches that existed
            // before #000356 and that any future refactor could quietly
            // break without the test suite noticing.

            test('returns false when allow_redeem_only_paid is true but is_paid is false (paid-gate block)', () => {
                // ticket_scanner.js:1859-1862 — the paid-gate. Tickets
                // that require payment must be paid before any other
                // check (multi-redeem, per-day, canTicketBeRedeemedNow)
                // can allow redemption. Without this test, a refactor
                // that drops the `else { allow_redeem = true; }` branch
                // would silently make unpaid tickets redeemable.
                const fn = buildFn();
                const result = fn({
                    _ret: {
                        allow_redeem_only_paid: true,
                        is_paid: false,
                        max_redeem_amount: 1,
                        max_redeem_per_day: 0,
                        redeems_today: 0
                    },
                    metaObj: {
                        wc_ticket: {
                            redeemed_date: '',
                            stats_redeemed: []
                        }
                    }
                });
                expect(result).toBe(false);
            });

            test('returns false when per-day redeem limit is hit (max_redeem_per_day > 0 path)', () => {
                // ticket_scanner.js:1893-1895 — the per-day limit. The
                // original 6 tests all use max_redeem_per_day: 0, so the
                // `redeems_today >= max_redeem_per_day` branch was never
                // exercised. A future refactor that flips the comparison
                // would silently break the per-day limit.
                const fn = buildFn();
                const result = fn({
                    _ret: {
                        allow_redeem_only_paid: false,
                        is_paid: true,
                        max_redeem_amount: 5,
                        max_redeem_per_day: 3,
                        redeems_today: 3  // already at the limit
                    },
                    metaObj: {
                        wc_ticket: {
                            redeemed_date: '',
                            stats_redeemed: []
                        }
                    }
                });
                expect(result).toBe(false);
            });

            test('returns false for a multi-redeem ticket that has used all slots (stats_redeemed.length == max_redeem_amount)', () => {
                // ticket_scanner.js:1885-1890 — the multi-redeem capacity
                // check. Test 5 ('treats a redeemed multi-redeem ticket
                // as still redeemable when capacity left') covers the
                // still-has-room path with max_redeem_amount: 3 and 1
                // redemption. The opposite path — capacity exhausted —
                // was not covered. Without this test, a refactor that
                // drops the `< max_redeem_amount` comparison would
                // silently allow over-redemption.
                const fn = buildFn();
                const result = fn({
                    _ret: {
                        allow_redeem_only_paid: false,
                        is_paid: true,
                        max_redeem_amount: 3,
                        max_redeem_per_day: 0,
                        redeems_today: 3
                    },
                    metaObj: {
                        wc_ticket: {
                            redeemed_date: '2026-10-07 12:00',
                            stats_redeemed: [
                                {d: '2026-10-07 12:00'},
                                {d: '2026-10-07 12:30'},
                                {d: '2026-10-07 13:00'}
                            ]
                        }
                    }
                });
                expect(result).toBe(false);
            });
        });
});
