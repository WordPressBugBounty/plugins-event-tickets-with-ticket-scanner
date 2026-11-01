/**
 * Pins the source contract of the (B)/(D) Kasse additions:
 *  - the ONE shared order-detail renderer with the context flag
 *    ("ein Renderer, ein Payload, ein Flag": 'kasse' shows the payment
 *    trail, 'online' must not)
 *  - the Receipt button exists ONLY on completed/partially_refunded orders
 *    (drafts have no receipt — endpoint refuses #9277)
 *  - the Details button exists for EVERY order and lazily fetches the
 *    single payload (getInternalOrderDetails) once, toggling afterwards
 */
const fs = require('fs');
const path = require('path');

const src = fs.readFileSync(path.join(__dirname, '..', 'backend.js'), 'utf8');

describe('Kasse order detail + receipt (3.3.0, (B)/(D))', () => {
  test('one shared renderer with the context flag', () => {
    expect(src).toContain('function __kasseRenderOrderDetail(payload, context)');
    expect(src).toMatch(/context\s*=\s*context\s*\|\|\s*'kasse'/);
  });

  test('kasse context renders the payment trail, online does not', () => {
    expect(src).toMatch(/if\s*\(\s*context\s*==\s*'kasse'\s*\)\s*\{[\s\S]*?payment[\s\S]*?Given/);
    // after the kasse-only branch closes, online shares only lines/tax/total/receipt
    const kasseBranch = src.match(/if \(context == 'kasse'\) \{[\s\S]*?\n\t\t\}\n\n\t\tif \(payload\.receipt/);
    expect(kasseBranch).not.toBeNull();
  });

  test('receipt button lives in the completed/partially_refunded branch only', () => {
    const completed = src.match(/status == 'completed' \|\| order\.status == 'partially_refunded'[\s\S]*?\n\t\t\}\n\t\tlet codes/);
    expect(completed).not.toBeNull();
    expect(completed[0]).toContain("getInternalOrderReceipt");
    expect(completed[0]).toContain("'Receipt'");
  });

  test('Details toggle fetches the single payload lazily and toggles after', () => {
    expect(src).toMatch(/et-kasse-order__details-toggle/);
    expect(src).toMatch(/box\.toggle\(\); return;/);
    expect(src).toMatch(/_makeGet\('getInternalOrderDetails', \{id:order\.id\}/);
  });

  test('draft cards render no ticket chips (regression pin for #8001 fix)', () => {
    expect(src).toMatch(/if \(order\.status != 'draft'\) \{\s*\n\s*\(order\.code_ids_arr \|\| \[\]\)\.forEach/);
  });
});
