/**
 * Locale label regression (Saso 29./30.10.): the former German topmenu
 * msgid 'Kasse' became the English msgid 'Sales' (backend.js). English
 * locales see 'Sales' (the msgid), German locales MUST see 'Kasse' via
 * the language JSON chunks. Pins both sides so neither regresses.
 */
const fs = require('fs');
const path = require('path');

const LANG = path.join(__dirname, '..', 'languages');

test("backend.js uses the English msgid 'Sales' for the kasse topmenu button", () => {
  const js = fs.readFileSync(path.join(__dirname, '..', 'backend.js'), 'utf8');
  expect(js).toContain("_x('Sales', 'label', 'event-tickets-with-ticket-scanner')");
  expect(js).not.toContain("_x('Kasse', 'label', 'event-tickets-with-ticket-scanner')");
});

test("every German backend.js chunk translates Sales -> Kasse", () => {
  const files = fs.readdirSync(LANG).filter(f => /^event-tickets-with-ticket-scanner-de.*\.json$/.test(f));
  expect(files.length).toBeGreaterThan(0);
  let chunks = 0;
  for (const f of files) {
    const d = JSON.parse(fs.readFileSync(path.join(LANG, f), 'utf8'));
    for (const dom of Object.keys(d.locale_data || {})) {
      const ld = d.locale_data[dom];
      if (!ld || typeof ld !== 'object') continue;
      const carriesBackendJs = 'Ticket Scanner' in ld || ('button\u0004Delete' in ld);
      if (!carriesBackendJs) continue;
      chunks++;
      const t = ld['label\u0004Sales'] || ld['Sales'];
      expect([f, t && t[0]]).toEqual([f, 'Kasse']);
    }
  }
  expect(chunks).toBeGreaterThan(0);
});
