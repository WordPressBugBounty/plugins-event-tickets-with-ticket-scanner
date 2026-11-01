/**
 * Auto-add fees start at qty 1 in the Kasse sell dialog
 * (Saso 2026-11-01: "an auto added product should have 1 as a starting
 * amount, if sale is started").
 *
 * Auto-add fees are a fixed default order line on every sale (server adds
 * them by default, 3.3.0), so the Kasse row must start at 1 — otherwise the
 * live total understates the order from the first render. Everything else
 * (tickets, optional add-ons, merch) keeps starting at 0.
 *
 * Contract:
 *  1. kasseInitialQty: auto_add truthy (1, '1') → 1; everything else → 0;
 *     a null/absent product object must not throw (defaults to 0).
 *  2. __renderKasseProducts builds the qty input with value="kasseInitialQty(p)"
 *     — the default is not hardcoded per row.
 */

const fs = require('fs');
const path = require('path');

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
    throw new Error('function ' + name + ' not terminated');
}

const kasseInitialQty = eval('(' + extractFunction('kasseInitialQty')
    .replace(/^function\s+kasseInitialQty/, 'function') + ')');

test('kasseInitialQty: auto-add starts at 1, everything else at 0', () => {
    expect(kasseInitialQty({ auto_add: 1 })).toBe(1);
    expect(kasseInitialQty({ auto_add: '1' })).toBe(1);
    expect(kasseInitialQty({ auto_add: 0 })).toBe(0);
    expect(kasseInitialQty({ auto_add: '0' })).toBe(0);
    expect(kasseInitialQty({})).toBe(0);
    expect(kasseInitialQty({ is_optional: 1, product_type: 'product' })).toBe(0);
    expect(kasseInitialQty(null)).toBe(0);
});

test('the Kasse qty input renders with kasseInitialQty(p), not a hardcoded 0', () => {
    const at = source.indexOf('__renderKasseProducts = prods =>');
    expect(at).toBeGreaterThan(-1);
    const body = source.slice(at, source.indexOf('\n\t\t};', at));
    expect(body).toContain('value="\' + kasseInitialQty(p) + \'"');
    expect(body).not.toContain('value="0"');
});
