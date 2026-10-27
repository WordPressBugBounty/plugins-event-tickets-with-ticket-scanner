/**
 * Guard for the Kasse sell-dialog payment panel (Saso 2026-10-26:
 * "ich sehe kein wechselgeld anzeige und eingabe").
 *
 * The change-calculator panel lives inside cashDiv and is hidden by
 * default. __renderKasseProducts must make it visible together with the
 * priced products — that .show() was missing, so the panel stayed
 * display:none forever. This test pins the wiring statically so a future
 * refactor cannot silently hide the calculator again.
 */

const fs = require('fs');
const path = require('path');

const BACKEND_PATH = path.resolve(__dirname, '..', 'backend.js');
const source = fs.readFileSync(BACKEND_PATH, 'utf8');

function extractFunction(name) {
    // supports both `function name(...)` and `let name = (...) =>` shapes
    let start = source.indexOf('function ' + name + '(');
    if (start < 0) start = source.indexOf('let ' + name + ' =');
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

test('__renderKasseProducts shows the cash panel with priced products', () => {
    const body = extractFunction('__renderKasseProducts');
    expect(typeof body).toBe('string');
    // products present → panel must be shown BEFORE the rows render
    expect(body).toMatch(/cashDiv\.show\(\)/);
    const showPos = body.indexOf('cashDiv.show()');
    const forEachPos = body.indexOf('prods.forEach');
    expect(showPos).toBeGreaterThan(-1);
    expect(forEachPos).toBeGreaterThan(showPos);
    // empty list → panel hidden again
    expect(body).toMatch(/cashDiv\.hide\(\)/);
});

test('product rows carry touch steppers around the qty field', () => {
    const body = extractFunction('__renderKasseProducts');
    // steppers before and after the input, sharing the qty pipeline
    expect(body).toMatch(/et-kasse-prod__minus/);
    expect(body).toMatch(/et-kasse-prod__plus/);
    const dialogSrc = extractFunction('__kasseSellDialog');
    expect(dialogSrc).toMatch(/\.et-kasse-prod__minus, \.et-kasse-prod__plus/);
    expect(dialogSrc).toMatch(/trigger\('change'\)/);
});

test('__kasseSell reads the hidden given input (sell path intact)', () => {
    const body = extractFunction('__kasseSell');
    expect(body).toMatch(/et-kasse-form__given/);
});
