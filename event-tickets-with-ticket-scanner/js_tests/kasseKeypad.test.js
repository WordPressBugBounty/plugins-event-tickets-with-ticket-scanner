/**
 * Tests for the Kasse change-calculator primitives in backend.js
 * (Saso 2026-10-26: "geld zurück rechner mit ziffernblock und
 * schnelleingabe buttons für 5,10,20,50,100 — je oft man klickt,
 * muss aufaddiert werden").
 *
 * Contracts pinned here:
 *  1. kasseKeypadKey: German decimal entry — digits build the euros part,
 *     ',' switches to cents (max 2), 'C' clears. Implicit leading zeros.
 *  2. kasseKeypadCents: state → integer cents ('12','5',dec → 1205).
 *  3. kasseQuickAddCents: additive quick buttons (2x 50 = 100), caps out.
 */

const fs = require('fs');
const path = require('path');
const assert = require('assert');

const BACKEND_PATH = path.resolve(__dirname, '..', 'backend.js');
const source = fs.readFileSync(BACKEND_PATH, 'utf8');

function extractFunction(name) {
    const start = source.indexOf('function ' + name + '(');
    if (start < 0) throw new Error('function ' + name + ' not found');
    let bodyStart = source.indexOf('{', start);
    let depth = 0;
    for (let i = bodyStart; i < source.length; i++) {
        if (source[i] === '{') depth++;
        else if (source[i] === '}') {
            depth--;
            if (depth === 0) return source.slice(start, i + 1);
        }
    }
    throw new Error('unbalanced braces for ' + name);
}

const kasseKeypadKey = eval('(' + extractFunction('kasseKeypadKey') + ')');
const kasseKeypadCents = eval('(' + extractFunction('kasseKeypadCents') + ')');
const kasseQuickAddCents = eval('(' + extractFunction('kasseQuickAddCents') + ')');
const kasseQuickApply = eval('(' + extractFunction('kasseQuickApply') + ')');

test('kasseKeypad + kasseQuickAddCents contracts (Saso 2026-10-26)', () => {
// ── keypad: German decimal entry ─────────────────────────────────────────
let st = kasseKeypadKey(null, 'C');
assert.strictEqual(st.fresh, true, 'initial state is fresh');

// "1" "2" "," "5" "0" → 12.50
st = kasseKeypadKey(st, '1');
st = kasseKeypadKey(st, '2');
assert.strictEqual(kasseKeypadCents(st), 1200, 'digits before comma = euros');
st = kasseKeypadKey(st, ',');
st = kasseKeypadKey(st, '5');
st = kasseKeypadKey(st, '0');
assert.strictEqual(kasseKeypadCents(st), 1250, '"12,50" → 1250 cents');

// comma alone: "," then "5" = 0,50 EUR (first decimal place = tenths)
st = kasseKeypadKey(null, ',');
st = kasseKeypadKey(st, '5');
assert.strictEqual(kasseKeypadCents(st), 50, '",5" → 0.50 = 50 cents');

// second decimal: "9" → 0,59
st = kasseKeypadKey(st, '9');
assert.strictEqual(kasseKeypadCents(st), 59, '0.59 = 59 cents');

// max 2 decimals: third digit after comma is ignored
st = kasseKeypadKey(st, '9');
assert.strictEqual(kasseKeypadCents(st), 59, 'third decimal ignored');

// second comma is a no-op
st = kasseKeypadKey(st, ',');
assert.strictEqual(kasseKeypadCents(st), 59, 'second comma ignored');

// 'C' clears everything
st = kasseKeypadKey(st, 'C');
assert.strictEqual(kasseKeypadCents(st), 0);
assert.strictEqual(st.fresh, true);

// unknown keys are ignored (no crash, no change)
let st2 = kasseKeypadKey(st, 'x');
assert.strictEqual(kasseKeypadCents(st2), 0, 'unknown key ignored');

// leading zero does not accumulate ("0" then "5" = 5 euros, not 05-chain)
st = kasseKeypadKey(null, '0');
st = kasseKeypadKey(st, '5');
assert.strictEqual(kasseKeypadCents(st), 500, '"0" then "5" = 5.00');

// ── quick buttons: additive ──────────────────────────────────────────────
assert.strictEqual(kasseQuickAddCents(0, 5000), 5000, 'one 50 = 5000 cents');
assert.strictEqual(kasseQuickAddCents(5000, 5000), 10000, '2x 50 = 100 — the exact Saso contract');
assert.strictEqual(kasseQuickAddCents(10000, 10000), 20000, '2x 100 stack');
assert.strictEqual(kasseQuickAddCents('nope', 2000), 2000, 'garbage base treated as 0');
assert.strictEqual(kasseQuickAddCents(0, 'nope'), 0, 'garbage add treated as 0');
assert.strictEqual(kasseQuickAddCents(99999999, 100), 99999999, 'hard cap');

});

/**
 * Calculator behaviour (Saso 2026-10-27: "soll wie ein taschenrechner
 * fungieren — zahlen hinten anhängen, back-delete button").
 *
 * Contracts pinned:
 *  4. kasseQuickApply: quick button ADDS to the buffer value and the sum
 *     becomes the new buffer (empty buffer + 10 → "10"), so a following
 *     digit APPENDS to it instead of wiping the quick-button amount.
 *  5. kasseKeypadKey 'back': deletes the last entered character, stepping
 *     back through decimals ("10,50" → "10,5" → "10," → "10" → "1" → "").
 */
test('calculator: quick-apply + backspace + append-after-quick (Saso 2026-10-27)', () => {

// ── quick apply builds on the buffer ────────────────────────────────────
let q = kasseQuickApply(null, 1000);
assert.strictEqual(kasseKeypadCents(q), 1000, 'empty buffer + 10 button → 10.00');
q = kasseQuickApply(q, 5000);
assert.strictEqual(kasseKeypadCents(q), 6000, '10 then 50 button → 60 (still additive)');
q = kasseQuickApply(q, 500);
assert.strictEqual(kasseKeypadCents(q), 6500, 'adding onto a decimal buffer works');

// ── digits APPEND after a quick button (the reported bug) ───────────────
let s = kasseQuickApply(null, 1000);           // "10"
s = kasseKeypadKey(s, '5');                    // append → "105"
assert.strictEqual(kasseKeypadCents(s), 10500, 'digit after quick appends, does not reset');
s = kasseKeypadKey(s, ',');
s = kasseKeypadKey(s, '5');
assert.strictEqual(kasseKeypadCents(s), 10550, 'decimal entry continues on the same value');

// ── backspace walks the entry back ──────────────────────────────────────
let b = kasseKeypadKey(null, 'C');
'10'.split('').forEach(d => { b = kasseKeypadKey(b, d); });
b = kasseKeypadKey(b, ',');
b = kasseKeypadKey(b, '5');                    // "10,5"
assert.strictEqual(kasseKeypadCents(b), 1050, 'precondition: 10,5');
b = kasseKeypadKey(b, 'back');                 // → "10,"
assert.strictEqual(kasseKeypadCents(b), 1000, 'back drops the decimal digit');
b = kasseKeypadKey(b, 'back');                 // → "10" (comma gone)
assert.strictEqual(kasseKeypadCents(b), 1000, 'back drops the dangling comma');
b = kasseKeypadKey(b, 'back');                 // → "1"
assert.strictEqual(kasseKeypadCents(b), 100, 'back eats a major digit');
b = kasseKeypadKey(b, 'back');                 // → ""
assert.strictEqual(kasseKeypadCents(b), 0, 'back down to zero');
b = kasseKeypadKey(b, 'back');                 // stays ""
assert.strictEqual(kasseKeypadCents(b), 0, 'back on empty is a no-op');

});
