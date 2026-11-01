# Plan: Kaufen-Button → bestehende ET-Kasse (3.3.0 Slice 2b)

## Requirement (Saso, Produktenntscheidung 28.10.)

Der Kaufen-Button der Event-Detail-Seite (`sasoEventTicketsValidator_shop_event`,
Slice 2, 5dcb680) führt in die BESTEHENDE ET-Kasse — nie Woo. Recon-Auftrag:
Kasse-Adressierung finden, Produkt-Preselection ggf. nachrüsten (test-first),
Button-URL serverseitig je Produkt rendern.

## Ist-Zustand (Recon, Code verifiziert 28.10.)

- **Die öffentliche Kasse existiert — Premium-seitig seit 1.8.9 („Stufe 2")**,
  `sasoEventtickets_PremiumFunctions.php`:
  - Shortcode `[eventtickets_shop list_id="N"]` (registerShopShortcode ~:3084),
  - Auto-Seite pro Event: Rewrite `^tickets/([0-9]+)/?$` → `et_shop_page` →
    `shopPageTemplate()` (echte Theme-Seite, 404 bei unbekannter Liste),
  - Katalog: `/tickets/` (`et_shop_catalog`, „the Kasse overview"),
  - URL-Builder `getShopPageUrl(int $list_id)` (~:3136) — KEINE Settings-Option
    mit Seiten-ID; die Seite ist automatisch,
  - Submit: `js/shop.js` → öffentlicher Free-Frontend-AJAX
    (`sasoEventtickets_executeFrontend`, `a_sngmbh=premium`, `d=shopSubmit`;
    `shopSubmit` steht in `$publicActions` ~:1252 — anonym erlaubt, Honeypot +
    IP-Ratelimit) → `createInternalOrder` als DRAFT → Bestätigung der Zahlung
    in der Admin-Kasse („Confirm payment") → Ticket-Mail.
  - Produktzeilen: `buildShopSectionHtml()` (~:3160) rendert qty-Inputs
    `name="qty[<product_id>]" value="0"` — **Preselection per URL gab es NICHT**
    → Nachrüstung nötig (Saso-Auftrag: „KEIN neuer Checkout, nur Preselection").
- **Free-Seite**: `shopEventBuyUrl()` (index.php:2240) rendert bisher nur die
  leere TODO-Konstante `SHOP_EVENT_BUY_URL_TEMPLATE = ''` → `href="#"` + Filter
  `saso_eventtickets_shop_event_buy_url`. Free kann Premium erreichbar abfragen:
  `$this->isPremium()` + `$this->getPremiumFunctions()` (etabliertes Free/Premium-
  Muster, getPremiumFunctions() index.php:291 mit Kompatibilitäts-Gate).
- **Test-Infra**: tests/bootstrap.php lädt Woo + Premium (SKIP_PREMIUM=1
  deaktiviert) → in Free-Tests ist Premium AKTIV; Premium-Tests booten den
  Free-Bootstrap (`require __DIR__.'/../../event-tickets-with-ticket-scanner/tests/bootstrap.php'`).
  Baseline grün: Free 10/15/6/7, Premium ShopPage 7 / Catalog 7 / Submission 6.
  „Kein Premium" ist per Reflection testbar (private `PREMFUNCTIONS`/`_isPrem`
  auf `sasoEventtickets_fakeprem`/`false` setzen, `invalidatePremiumCache()`
  stellt wieder her).

## Architektur-Entscheidung

1. **Preselection an der Kasse (Premium, minimal)**: `?et_product=<id>` —
   `getShopPreselectedProductId()` liest `$_GET['et_product']` (intval; 0 =
   nichts). `buildShopSectionHtml()` befüllt die qty-Zeile DES Produkts mit
   value="1" (nur wenn verfügbar; ausverkauft/unspezifiziert → unverändert 0).
   Der Käufer kann die Menge ändern — die serverseitigen Gates (#9271 etc.)
   bleiben die harte Wahrheit. Kein neuer Endpoint, kein neuer Flow.
2. **Button-URL serverseitig je Produkt (Free)**: `shopEventBuyUrl()` —
   Konstante gesetzt → Platzhalter-Template ({list_id}/{product_id}) als
   manueller Override; sonst wenn Premium aktiv + `getShopPageUrl` existiert:
   `getShopPageUrl(listId)` + `add_query_arg('et_product', productId)`. Ohne
   Premium (oder alte Premium ohne die Methode): `''` → `href="#"` (nichts
   geraten, Free-only-Installationen ändern sich nicht). Der Filter
   `saso_eventtickets_shop_event_buy_url` ($url, $listId, $productId) entscheidet
   IMMER zuletzt (Doku aktualisiert: Default ist jetzt die Kasse-URL, nicht '').
3. **Nie Woo** — Button zeigt ausschließlich auf die eigene ET-Kasse.

## Test-Plan (RED zuerst)

Free — tests/ShopBuyButtonTest.php (neu):
1. Button-URL = `/tickets/<list_id>/?et_product=<pid>` (Premium aktiv im Bootstrap)
2. `shopEventBuyUrl()` direkt: Kasse-URL + et_product; Filter überschreibt (Args intakt)
3. Ohne Premium (Reflection-Swap): `href="#"`-Platzhalter, keine geratene URL
4. Verdeckte Liste (show_in_shop=0) → ganze Seite '' → kein Button-Ziel
5. Katalog rendert weiterhin KEINE Buy-Buttons (Slice-1-Regression)

Free — tests/ShopEventDetailTest.php: der Platzhalter-Vertragstest
(`test_buy_button_target_defaults_to_placeholder_and_filter_wins`) pinnt den
ALTEN Zustand (TODO '') — wird auf den neuen Vertrag umgeschrieben (Default =
Kasse-URL, Filter gewinnt weiter).

Premium — tests/ShopPreselectionTest.php (neu):
1. `?et_product=<pid>` → qty-Input dieses Produkts value="1", andere 0
2. ohne Param → alle 0 (Bestandsverhalten byte-identisch)
3. ausverkauftes Produkt + Param → disabled, value 0 (Preselection ignoriert)
4. Fremd-/Garbage-Param (andere Liste, `"><script>`, 0) → alles 0

Commands (EIN Datei-Argument; Nachbarn EINZELN):
- Free: `WP_TESTS_DIR=/home/web3/wp-test-lib/wordpress-tests-lib tests/vendor/bin/phpunit --no-configuration --bootstrap tests/bootstrap.php tests/ShopBuyButtonTest.php`
- Premium: `WP_TESTS_DIR=… ../event-tickets-with-ticket-scanner/tests/vendor/bin/phpunit --no-configuration --bootstrap ../event-tickets-with-ticket-scanner/tests/bootstrap.php tests/ShopPreselectionTest.php`

## WP-Smoke (dev-WP, wp eval-file)

Kasse-Seite mit Preselection rendern (buildShopHtml mit $_GET['et_product']),
Buy-Button-URL am Shop-Event-Shortcode prüfen, Test-Liste/-Produkt vollständig
entfernen (Cleanup).

## Non-Goals

- Kein neuer Checkout/Endpoint, kein Woo-Link, keine Admin-UI
- Kein Version-Bump (3.3.0-dev offen; Premium 1.9.0-dev offen — nur Changelog-Bullets)
- Keine Twig-Templates (Slice 3)
- Kein Prod-Deploy, kein SVN, kein Zugriff auf /var/www/html/vollstart.de/
