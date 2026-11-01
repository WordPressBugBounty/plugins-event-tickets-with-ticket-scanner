/**
 * Products & Tax section (3.3.0) — locale + structure regression.
 *
 * Its own SECTION, the exact pattern of Seating Plans / Ticket Templates:
 * a tab-nav entry (#tab-productstax) hardcoded in backend.js — no options
 * entry (no h_products_tax heading, no use-case group), own display STATE,
 * deep link &display=productstax pins the tab. Both lists render as
 * DataTables and both entities edit in jQuery-UI dialogs.
 *
 * Pins BOTH sides of the i18n contract for every msgid the section uses
 * (Kasse lesson: Saso is extremely sensitive to German labels under en_US):
 * - backend.js uses the ENGLISH msgid with the exact msgctxt
 * - every German backend.js JSON chunk (de_DE, de_AT, de_CH,
 *   de_CH_informal) carries the translation under BOTH the context key
 *   ("label\u0004...") and the plain key
 * - de_DE.po AND de_CH.po contain the msgctxt+msgid pair with the German
 *   msgstr (the catalog is context-scoped — a bare msgid does NOT satisfy
 *   an _x() call)
 * - the section keeps the plugin's table rules for growing data (keyed
 *   JSON rows, columns.render, deferred rendering, search + sort +
 *   pagination) and moves add/edit into dialogs; deletes stay confirmed
 *   and soft (server-side is_deleted)
 */
const fs = require('fs');
const path = require('path');

const LANG = path.join(__dirname, '..', 'languages');
const DOM = 'event-tickets-with-ticket-scanner';

const INTRO_EN = 'Define your VAT rates once and assign them to products — or set a default rate per ticket list. An event ticket is the mandatory ticket itself, a product is an optional add-on the customer chooses (drinks, merchandise), and a fee is added to every order by default. The online shop and the Kasse both follow these settings.';
const INTRO_DE = 'Definieren Sie Ihre MwSt.-Sätze einmal und weisen Sie sie den Produkten zu — oder legen Sie einen Standard-Satz pro Ticketliste fest. Ein Event-Ticket ist das eigentliche Ticket und gehört immer zum Verkauf, ein Produkt ist ein optionales Zusatzprodukt, das der Kunde wählt (Getränke, Merchandise), und eine Gebühr liegt standardmäßig auf jeder Bestellung. Der Online-Shop und die Kasse richten sich beide nach diesen Einstellungen.';

// msgid, msgctxt, german — EVERY section-owned string the rebuilt UI uses.
const STRINGS = [
  [INTRO_EN, 'label', INTRO_DE],
  ['VAT rates', 'heading', 'MwSt.-Sätze'],
  ['Manage the VAT rates you can assign to products and ticket lists. Prices are gross — the rate documents how the price splits into net and tax, it never changes the price the customer pays. Each product uses its own rate first, then the default rate of its ticket list, and without a rate the price counts as net. You can type the percent with a point or a comma. Removed rates stay on past orders, so their net and tax split does not change afterwards.', 'label', 'Verwalten Sie die MwSt.-Sätze, die Sie Produkten und Ticketlisten zuweisen können. Preise sind Bruttopreise — der Satz dokumentiert, wie sich der Preis in Netto und Steuer aufteilt, er ändert nie den Preis, den der Kunde zahlt. Jedes Produkt nutzt zuerst seinen eigenen Satz, dann den Standard-Satz seiner Ticketliste, und ohne jeden Satz gilt der Preis als Netto. Den Prozentwert können Sie mit Punkt oder Komma eingeben. Entfernte Sätze bleiben auf vergangenen Bestellungen erhalten, damit sich deren Netto- und Steuer-Aufteilung danach nicht mehr ändert.'],
  ['Name', 'column', 'Name'],
  ['Percent', 'column', 'Prozent'],
  ['Active', 'column', 'Aktiv'],
  ['Add VAT rate', 'title', 'MwSt.-Satz hinzufügen'],
  ['Edit VAT rate', 'title', 'MwSt.-Satz bearbeiten'],
  ['Add VAT rate', 'label', 'MwSt.-Satz hinzufügen'],
  ['No VAT rates yet.', 'label', 'Noch keine MwSt.-Sätze.'],
  ['Products', 'heading', 'Produkte'],
  ['All sellable products — event tickets, optional add-ons and fees — across all lists plus global products without a list. An event ticket is the ticket itself and is always sold, an optional add-on is chosen by the customer with a checkbox, and a fee with auto-add sits on every order by default (e.g. a pre-sale fee). Prices are gross — the assigned rate only documents the net and tax split.', 'label', 'Alle verkaufbaren Produkte — Event-Tickets, optionale Zusatzprodukte und Gebühren — über alle Listen hinweg plus globale Produkte ohne Liste. Ein Event-Ticket ist das eigentliche Ticket und wird immer verkauft, ein optionales Zusatzprodukt wählt der Kunde per Checkbox, und eine Gebühr mit Automatik liegt standardmäßig auf jeder Bestellung (z. B. Vorverkaufsgebühr). Preise sind Bruttopreise — der zugewiesene Satz dokumentiert nur die Netto- und Steuer-Aufteilung.'],
  ['WooCommerce is active — you do not need these entries for WooCommerce, because product, price and VAT are handled by WooCommerce itself.', 'label', 'WooCommerce ist aktiv — diese Angaben sind bei WooCommerce nicht nötig, da Produkt, Preis und MwSt. dort über WooCommerce abgewickelt werden.'],
  ['Type', 'column', 'Typ'],
  ['Price', 'column', 'Preis'],
  ['Tax rate', 'column', 'MwSt.-Satz'],
  ['List', 'column', 'Liste'],
  ['Flags', 'column', 'Merkmale'],
  ['Event ticket', 'label', 'Event-Ticket'],
  ['Fee', 'label', 'Gebühr'],
  ['No tax rate', 'label', 'Kein MwSt.-Satz'],
  ['Global (no list)', 'label', 'Global (keine Liste)'],
  ['Optional', 'label', 'Optional'],
  ['Optional add-on: the customer chooses whether to take it (shop and Kasse show a checkbox).', 'label', 'Optionales Zusatzprodukt: Der Kunde entscheidet, ob er es mitnimmt (Shop und Kasse zeigen eine Checkbox).'],
  ['Auto-add', 'label', 'Automatisch hinzufügen'],
  ['The fee is added to every order by default (e.g. pre-sale or system fee).', 'label', 'Die Gebühr liegt standardmäßig auf jeder Bestellung (z. B. Vorverkaufs- oder Systemgebühr).'],
  ['Add product', 'title', 'Produkt hinzufügen'],
  ['Edit product', 'title', 'Produkt bearbeiten'],
  ['Add product', 'label', 'Produkt hinzufügen'],
  ['No products yet.', 'label', 'Noch keine Produkte.'],
  ['Active', 'label', 'Aktiv'],
  ['Remove', 'label', 'Entfernen'],
  ['Percent', 'label', 'Prozent'],
  ['Currency', 'label', 'Währung'],
];

// plain __() strings (no msgctxt) the section adds
const PLAIN = [
  ['Are you sure, you want to delete this VAT rate?', 'Sind Sie sicher, dass Sie diesen MwSt.-Satz löschen möchten?'],
  ['Are you sure, you want to delete this product?', 'Sind Sie sicher, dass Sie dieses Produkt löschen möchten?'],
];

// pairs that ALREADY existed and the section reuses — pinned so a silent
// removal from the catalog breaks loudly here, not for a German admin
const REUSED = [
  ['Name', 'label', 'Name'],
  ['Price', 'label', 'Preis'],
  ['Product', 'label', 'Produkt'],
  ['Save', 'label', 'Speichern'],
  ['Cancel', 'label', 'Abbrechen'],
  ['Edit', 'label', 'Bearbeiten'],
  ['Yes', 'label', 'Ja'],
  ['No', 'label', 'Nein'],
  ['Type', null, 'Typ'],
  ['Do you want to delete?', 'title', 'Willst du es wirklich löschen?'],
  // the tab label — same context-scoped pair, now used in backend.js
  ['Products & Tax', 'label', 'Produkte & MwSt.'],
];

const js = fs.readFileSync(path.join(__dirname, '..', 'backend.js'), 'utf8');
const php = fs.readFileSync(path.join(__dirname, '..', 'sasoEventtickets_Options.php'), 'utf8');

function poEntries(file) {
  const out = new Set();
  const txt = fs.readFileSync(path.join(LANG, file), 'utf8');
  const blocks = txt.split(/\n\n+/);
  for (const blk of blocks) {
    const ctx = (blk.match(/msgctxt "((?:[^"\\]|\\.)*)"/) || [])[1] || null;
    const mid = (blk.match(/(^|\n)msgid "((?:[^"\\]|\\.)*)"/) || [])[2];
    const str = (blk.match(/(^|\n)msgstr "((?:[^"\\]|\\.)*)"/) || [])[2];
    if (mid !== undefined && str !== undefined) out.add([ctx, mid, str].join('\u0000'));
  }
  return out;
}

test('backend.js uses the English msgid with the exact msgctxt for every Products & Tax string', () => {
  for (const [mid, ctx] of STRINGS) {
    const literal = `_x('${mid}', '${ctx}', '${DOM}')`;
    expect({ mid: mid.slice(0, 40), found: js.includes(literal) }).toEqual({ mid: mid.slice(0, 40), found: true });
  }
  for (const [mid] of PLAIN) {
    const literal = `__('${mid}', '${DOM}')`;
    expect({ mid: mid.slice(0, 40), found: js.includes(literal) }).toEqual({ mid: mid.slice(0, 40), found: true });
  }
});

test('own section tab, the Seating Plans pattern: PHP has no options entry, JS owns the tab', () => {
  // PHP side: no h_products_tax heading, no use-case group — the section is
  // registered options-less, exactly like Seating Plans / Ticket Templates
  expect(php).not.toContain('h_products_tax');
  // JS side: the tab sits in the Options page tab-nav right after Seating
  // Plans, mounts the section and owns its display STATE
  const seatAt = js.indexOf('let tabNavSeatingplan');
  const ptAt = js.indexOf('let tabNavProductsTax');
  expect(seatAt).toBeGreaterThan(-1);
  expect(ptAt).toBeGreaterThan(seatAt); // "near Templates/Seating Plans"
  expect(js).toContain(`_x('Products & Tax', 'label', '${DOM}')`);
  expect(js).toContain('href="#tab-productstax"');
  expect(js).toContain("STATE = 'productstax'");
  // intro stays the first element of the section render
  expect(js).toContain(`_x('${INTRO_EN}', 'label', '${DOM}')`);
});

test('still no topmenu button: the section lives in the tab-nav, not the top menu', () => {
  expect(js).not.toContain('_displayProductsTaxArea');
  const menuAt = js.indexOf('function _displaySettingAreaButton');
  const menuEnd = js.indexOf('\n\tfunction ', menuAt + 10);
  const menu = js.slice(menuAt, menuEnd);
  expect(menu).not.toContain('Products & Tax');
});

test('deep link: &display=productstax opens the Options page with the tab pinned', () => {
  // init routes the display parameter to the Options area
  expect(js).toContain("PARAS.display == 'productstax'");
  // the tab init pins #tab-productstax instead of the first tab
  expect(js).toContain("$('.tab-nav a[href=\"#tab-productstax\"]').click()");
});

test('WooCommerce notice: gated on is_wc_available, rendered inside the section', () => {
  // the server flag (AdminSettings versions.is_wc_available = class_exists('WooCommerce'),
  // i.e. present AND active) is the ONLY gate — no second Woo probe in JS
  expect(js).toContain("_getOptions_Versions_isActivatedByKey('is_wc_available')");
  const at = js.indexOf('function __renderProductsTax');
  const end = js.indexOf('\n\tfunction ', at + 10);
  const body = js.slice(at, end);
  expect(body).toContain('notice notice-info');
  // the gate wraps the notice append — notice HTML only inside the if
  expect(body.indexOf("_getOptions_Versions_isActivatedByKey('is_wc_available')")).toBeLessThan(body.indexOf('notice notice-info'));
});

test('both lists render as DataTables (plugin table rules for growing data)', () => {
  for (const [marker, dataKey] of [['function __ptRenderRatesTable', '"data": __ptRates'],
                                   ['function __ptRenderProductsTable', '"data": __ptProducts']]) {
    const at = js.indexOf(marker);
    expect({ marker, found: at > -1 }).toEqual({ marker, found: true });
    const body = js.slice(at, js.indexOf('\t});', at));
    expect(body).toContain('"deferRender": true');   // deferred rendering
    expect(body).toContain(dataKey);                 // keyed JSON rows
    expect(body).toContain('"render":');             // columns.render formatting
    expect(body).toContain('"searching": true');     // search
    expect(body).toContain('"ordering": true');      // sort
    expect(body).toContain('"pageLength": 10');      // pagination
  }
});

test('percent renders with a decimal POINT always', () => {
  const at = js.indexOf('function __ptFormatPercent');
  expect(at).toBeGreaterThan(-1);
  const body = js.slice(at, js.indexOf('}', js.indexOf('return String(Math.round(n * 100) / 100)', at)) + 1);
  expect(body).toContain('String(Math.round(n * 100) / 100)');
  expect(body).not.toContain("replace(',')");
});

test('add/edit runs in jQuery-UI dialogs (modal, Save/Cancel)', () => {
  for (const fn of ['function __ptRateDialog', 'function __ptProductDialog']) {
    const at = js.indexOf(fn);
    expect({ fn, found: at > -1 }).toEqual({ fn, found: true });
    const body = js.slice(at, js.indexOf('\n\tfunction ', at + 10)); // whole function body
    expect(body).toContain('modal: true');
    expect(body).toContain(`_x('Save', 'label', '${DOM}')`);
    expect(body).toContain(`_x('Cancel', 'label', '${DOM}')`);
    expect(body).toContain('closeDialog(dlg); __ptReload()'); // save closes + reloads
  }
  // the product dialog keeps the hidden-flag semantics on edit
  const prodAt = js.indexOf('function __ptProductDialog');
  const prodEnd = js.indexOf('\n\tfunction ', prodAt + 10);
  const prodBody = js.slice(prodAt, prodEnd);
  expect(prodBody).toContain('product_type:');
  expect(prodBody).toContain('optHidden ? (isEdit ? (parseInt(p.is_optional, 10) || 0) : 0)');
  expect(prodBody).toContain('autoHidden ? (isEdit ? (parseInt(p.auto_add, 10) || 0) : 0)');
});

test('create product with hidden flags must not read the absent product (create p=null crashed Save)', () => {
  // __submitProductForm keeps a hidden flag's stored value on EDIT; on CREATE
  // there is no product object — reading p.is_optional threw "Cannot read
  // properties of null" the moment Save was clicked with type event (both
  // flags hidden). Executes the REAL function body: create must default
  // hidden flags to 0 without touching p, edit must keep the stored values.
  const start = js.indexOf('function __submitProductForm');
  const end = js.indexOf('\n\t\tlet _options', start);
  const raw = js.slice(start, end);
  const body = raw.slice(raw.indexOf('{') + 1, raw.lastIndexOf('}'));
  const run = new Function('isEdit', 'p', 'dlg', 'opt', 'auto', 'optWrap', 'autoWrap',
                           '_makePost', 'closeDialog', '__ptReload', 'LAYOUT', body);
  const vals = { '[name="etPtProdName"]': 'Test', '[name="etPtProdType"]': 'event',
                 '[name="etPtProdPrice"]': '10.00', '[name="etPtProdCurrency"]': 'EUR',
                 '[name="etPtProdRate"]': '0', '[name="etPtProdList"]': '0' };
  const cases = [
    { name: 'create type event — both flags hidden',  isEdit: false, p: null,                            disp: { opt: 'none', auto: 'none' }, exp: [0, 0] },
    { name: 'create type product — auto-add hidden',  isEdit: false, p: null, optChecked: true,          disp: { opt: 'flex', auto: 'none' }, exp: [1, 0] },
    { name: 'create type fee — optional hidden',      isEdit: false, p: null, autoChecked: true,         disp: { opt: 'none', auto: 'flex' }, exp: [0, 1] },
    { name: 'edit keeps stored hidden values',        isEdit: true,  p: { id: '7', is_optional: 1, auto_add: 1 }, disp: { opt: 'none', auto: 'none' }, exp: [1, 1] },
  ];
  for (const c of cases) {
    let captured = null;
    const dlg = { find: (sel) => ({ val: () => vals[sel], prop: () => true }) };
    const opt = { prop: () => !!c.optChecked };
    const auto = { prop: () => !!c.autoChecked };
    const optWrap = { css: () => c.disp.opt };
    const autoWrap = { css: () => c.disp.auto };
    let threw = null;
    try {
      run(c.isEdit, c.p, dlg, opt, auto, optWrap, autoWrap,
          (action, payload) => { captured = { action, payload }; },
          () => {}, () => {}, { renderFatalError: () => {} });
    } catch (e) { threw = e.message; }
    expect({ case: c.name, threw: threw || null }).toEqual({ case: c.name, threw: null });
    expect({ case: c.name, is_optional: captured && captured.payload.is_optional, auto_add: captured && captured.payload.auto_add })
      .toEqual({ case: c.name, is_optional: c.exp[0], auto_add: c.exp[1] });
  }
});

test('deletes stay confirmed before the destructive call', () => {
  const delRate = js.indexOf(`_makePost('deleteTaxRate'`);
  const delProd = js.indexOf(`_makePost('removeTicketProduct'`, delRate);
  expect(delRate).toBeGreaterThan(-1);
  expect(delProd).toBeGreaterThan(delRate);
  expect(js.lastIndexOf('LAYOUT.renderYesNo', delRate)).toBeGreaterThan(-1);
  expect(js.lastIndexOf('LAYOUT.renderYesNo', delProd)).toBeGreaterThan(js.lastIndexOf('LAYOUT.renderYesNo', delRate));
});

test('all AJAX actions stay wired (only the presentation layer changed)', () => {
  for (const action of ['getTaxRates', 'saveTaxRate', 'deleteTaxRate', 'getProductsForAdmin',
                        'addTicketProduct', 'editTicketProduct', 'removeTicketProduct']) {
    expect({ action, found: js.includes(`'${action}'`) }).toEqual({ action, found: true });
  }
});

test('no German msgids leaked into the Products & Tax _x() calls', () => {
  expect(js).not.toContain("_x('Produkte");
  expect(js).not.toContain("_x('MwSt");
  expect(js).not.toContain("_x('Verwalten");
  expect(js).not.toContain("_x('Gebühr");
});

test.each(['event-tickets-with-ticket-scanner-de_DE-ajax_script_backend.json',
           'event-tickets-with-ticket-scanner-de_AT-ajax_script_backend.json',
           'event-tickets-with-ticket-scanner-de_CH-ajax_script_backend.json',
           'event-tickets-with-ticket-scanner-de_CH_informal-ajax_script_backend.json'])(
  'German chunk %s translates every Products & Tax string (context + plain key)', (file) => {
  const d = JSON.parse(fs.readFileSync(path.join(LANG, file), 'utf8'));
  const ld = d.locale_data[DOM];
  expect(ld).toBeTruthy();
  for (const [mid, ctx, de] of [...STRINGS, ...REUSED]) {
    if (ctx) {
      const ctxKey = `${ctx}\u0004${mid}`;
      expect({ file, key: ctxKey, got: ld[ctxKey] }).toEqual({ file, key: ctxKey, got: [de] });
    }
    expect({ file, key: mid, got: ld[mid] }).toEqual({ file, key: mid, got: [de] });
  }
  for (const [mid, de] of PLAIN) {
    expect({ file, key: mid, got: ld[mid] }).toEqual({ file, key: mid, got: [de] });
  }
});

test.each(['event-tickets-with-ticket-scanner-de_DE.po',
           'event-tickets-with-ticket-scanner-de_CH.po'])(
  'po catalog %s carries every Products & Tax msgctxt+msgid pair with the German msgstr', (file) => {
  const entries = poEntries(file);
  for (const [mid, ctx, de] of [...STRINGS, ...REUSED]) {
    expect({ file, pair: [ctx, mid], msgstr: de, present: entries.has([ctx, mid, de].join('\u0000')) })
      .toEqual({ file, pair: [ctx, mid], msgstr: de, present: true });
  }
  for (const [mid, de] of PLAIN) {
    expect({ file, mid, msgstr: de, present: entries.has([null, mid, de].join('\u0000')) })
      .toEqual({ file, mid, msgstr: de, present: true });
  }
});
