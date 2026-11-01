/**
 * #8001 follow-up: draft (unconfirmed) direct sales must not offer ticket
 * printing. The Kasse prints after "Confirm payment" — same rule as the
 * delivery gate (#8030) and the print-url endpoint (#9276), which both
 * refuse drafts server-side. This pins the two UI surfaces so a refactor
 * cannot reintroduce print buttons on draft orders.
 */

const fs = require('fs');
const path = require('path');

const BACKEND_PATH = path.resolve(__dirname, '..', 'backend.js');
const source = fs.readFileSync(BACKEND_PATH, 'utf8');

function extractFunction(name) {
    let start = source.indexOf('function ' + name + '(');
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

test('__kasseOrderRow renders print chips only for non-draft orders', () => {
    const body = extractFunction('__kasseOrderRow');
    const guard = body.search(/if\s*\(order\.status\s*!=\s*'draft'\)/);
    const printChip = body.indexOf('Print preview');
    expect(guard).toBeGreaterThan(-1);
    expect(printChip).toBeGreaterThan(guard);
});

test('orders table hides the Print tickets button on draft rows', () => {
    expect(source).toMatch(/row\.tickets_count > 0 && row\.status != 'draft'/);
});
