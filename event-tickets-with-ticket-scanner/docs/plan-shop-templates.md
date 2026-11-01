# Plan: Shop-Template-Overrides (Twig) — Slice 3 / ET 3.3.0

Saso 2026-10-28: Templates für die öffentlichen Shop-Flächen. Spec:
`10_Business/11_Projects/event-tickets/specs/verkaufssystem-templates.md`
(Empfehlungssektion). Slices 1–2b sind gebaut (Basis-Commit `788d984`):
Shortcodes `sasoEventTicketsValidator_shop_catalog` / `..._shop_event`,
Datenlayer `getShopCatalog()`/`getShopEvent()`, `shopEventBuyUrl()`,
Shared-Renderer `renderShopProductGroups($entry,$withBuyButtons)`.

## Ist-Zustand (RECON, verifiziert)

- **Renderer**: `index.php` — `replacingShortcodeShopCatalog()` (~2275) und
  `replacingShortcodeShopEvent()` (~2359) rendern serverseitig PHP-HTML über
  `renderShopProductGroups()` (~2314). Kein Template-Mechanismus.
- **Twig-Engine** (`sasoEventtickets_TicketDesigner.php`): gebündelt Twig
  **3.22.0** unter `vendors/twig/` (autoload via Composer-PLN-Datei).
  Bootstrap (`Instance()`, Z. 12–43): `twig_cycle`-Polyfill,
  `assertPhpSupportsTwig()` (PHP ≥ 8.1, nur WEB-Context, CLI überspringt den
  Guard), `require vendors/twig/autoload.php`.
  Rendering (`renderHTML` Z. 109, `renderInlineString` Z. 378):
  `\Twig\Loader\ArrayLoader(['index'=>…])` + **plain
  `\Twig\Environment`** + `configureTwigEnvironment()` (Z. 334): eigene
  Escaper (`wp_kses_post` …), Filter `format_datetime`/`stripslashes`,
  Funktionen `wc_price`/`getMediaData`/`wc_product_addons_…`/`get_field`,
  Tests `object/array/numeric/string`.
- **Sandbox-Befund**: Der Ticket-Designer nutzt **KEINE**
  `Twig\Extension\SandboxExtension`/`SecurityPolicy` (grep: 0 Treffer outside
  vendor). Seine „Strenge“ = Default-Umgebung + nur die freigegebenen
  Custom-Funktionen/Filter; Templates sind admin-autoriert (vertraute Rolle),
  Variablen sind WC-Objekte (Methodenaufrufe im Template möglich), und beim
  Speichern wird NICHT kompiliert (Syntaxfehler surfen erst beim Rendern auf).
- **DB-Templates heute**: Options-Textarea `wcTicketDesignerTemplate`
  (`sasoEventtickets_Options.php` Z. 679), gelesen via
  `getOptionValue()`, leer → `getDefaultTemplate()` (`TicketDesigner::getTemplate()`).
  Kein Compile-Check beim Speichern — für Shop-Templates bewusst NICHT
  nachgebaut (s. u., Validierung ist Pflicht).
- **Datenlayer**: Entry = `id, name, event{event_start_date, event_start_time,
  event_location}, groups[{category_id, category_name, products[{id, name,
  price_cents, currency, display_desc, category_id, …}]}]`
  (`sasoEventtickets_AdminSettings.php` Z. 4414–4507). Event-Meta wird beim
  Schreiben sanitiziert: Datum `Y-m-d`, Zeit `H:i(:s)`
  (`_setMetaDataForList` Z. 1778–1789, `sanitize_date_from_datepicker`).
- **Buy-URL**: `shopEventBuyUrl(listId, productId)` (index.php Z. 2240) —
  SHOP_EVENT_BUY_URL_TEMPLATE > Premium-Kasse `/tickets/<id>/?et_product=`
  > `''`; Filter `saso_eventtickets_shop_event_buy_url` entscheidet immer zuletzt.

## Architektur-Entscheidung

**Eigene schlanke Twig-Setup-Klasse MIT echter Sandbox** — der Ticket-Designer
ist als Bootstrap wiederverwendbar (Autoload/Guard/Polyfill), aber seine
Render-Umgebung (plain Environment, WC-Objekt-Variablen, keine Validierung)
ist für kundeneigenes Template-Material zu schwach. Mandat: „KEINE unsichere
Twig-Umgebung … nur whitelist Tags/filters/functions“.

- **Neu: `sasoEventtickets_ShopTemplates.php`** (Plugin-Root, wie die
  Geschwister-Klassen; Load via `loadOnce()` + Getter
  `sasoEventtickets::getShopTemplatesHandler()`).
  - Engine-Bootstrap: delegiert an `sasoEventtickets_TicketDesigner::Instance()`
    (Polyfill + PHP-8.1-Guard + Twig-3.22-Autoload, keine neue
    Dependency, dieselbe Library-Version).
  - Environment pro Render: `ArrayLoader` + `Environment` +
    `SandboxExtension(SecurityPolicy, sandboxedGlobally=true)`:
    - Tags: `if, for, set, apply, do, verbatim`
      (KEIN include/import/from/extends/macro/embed/block/filter-tag/autoescape).
    - Filter: Kern-Set (`escape/e, raw, striptags, nl2br, length, first, last,
      slice, join, split, keys, values, column, default, upper, lower, title,
      capitalize, trim, number_format, replace, url_encode, json_encode,
      merge, reverse, abs, round, sort, map, filter, reduce, batch, spaceless,
      date, date_modify, format_datetime, stripslashes`).
    - Funktionen: `shop_buy_url(entry, product)`, `shop_price(cents)`,
      `date, max, min, range` (KEIN `constant/dump/source/attribute/
      template_from_string/random/cycle/country_timezones`).
    - Methods/Properties: **leer** — der Payload ist reine Arrays, Objekt-
      Aufrufe sind im Sandbox-Kontext komplett gesperrt.
  - Autoescape bleibt AN (Default `html`); `display_desc` (serverseitig
    kses-sanitized, Vertrag wie Kasse-Tiles) wird per `|raw` explizit
    ausgegeben — dokumentiert.
  - `validateTemplate(string $source): void` — kompiliert (`tokenize`+`parse`,
    fängt Syntax-Fehler) und macht eine Sandbox-Testrender mit
    Repräsentativ-Payload (fängt `SecurityNotAllowed*Error` zur Speicherzeit,
    nicht erst auf der Besucherseite). Zusätzlich explizite Ablehnung von
    `<?php`/`<?=`/`<? ` im Quelltext.
  - `renderSurface(string $surface, array $vars): ?string` — `null` wenn kein
    Template gesetzt (Caller bleibt beim Code-Renderer), sonst Ausgabe;
    `\Throwable` beim Rendern → `''` (sichere Degradation, gleiche
    Still-Schweige-Verträge wie die Shortcodes selbst, kein 500 auf der
    Besucherseite).

- **Storage — zwei Options-Keys statt eines Array-Options** (Abweichung vom
  Mandats-Beispiel `saso_eventtickets_shop_templates`, bewusst): die
  bestehende Admin-Settings-Fläche rendert/speichert pro Key über die
  Options-Registry und den `changeOption`-AJAX — ein Array-Wert bräuchte
  eigenen Editor + eigenen Save-Command, genau den Scope, den das Mandat
  ausschließt („KEIN neuer visueller Editor“, „bestehender Admin-Settings-
  Bereich“). Zwei Keys = dasselbe Datenmodell, framework-native:
  - `shopTemplateCatalog` (Textarea, def `''`) → Option-ID `sasoEventticketsShopTemplateCatalog`
  - `shopTemplateEventDetail` (Textarea, def `''`) → Option-ID `sasoEventticketsShopTemplateEventDetail`
  Registriert in `sasoEventtickets_Options.php` im Ticket-Designer-Bereich
  (nach `wcTicketDesignerTemplateTest`), mit Heading + Variablen-Doku-Desc
  (Muster `h16`/`h16_desc`). Leer = Default-Rendering (Muster
  `TicketDesigner::getTemplate()`).

- **Validierung beim Speichern**: `sasoEventtickets_AdminSettings::changeOption()`
  (Z. 1051) fängt die beiden Keys VOR dem Write ab: nicht-leerer Wert →
  `validateTemplate()`; Twig-Fehler → `Exception` mit der Twig-Meldung →
  bestehender Dispatcher-Catch (Z. 319) → `wp_send_json_error` im Admin-UI.
  Nichts wird gespeichert. Leer = Reset auf Default (immer gültig).

- **Renderer-Anbindung** (`index.php`, minimal-invasiv): beide Shortcodes
  prüfen NACH Datenfetch/Addressing und VOR dem Default-Markup
  `renderSurface()`; `null` → exakt heutiger Pfad (byte-identisch),
  String → als komplette Shortcode-Ausgabe zurückgeben (Template besitzt das
  volle Markup inkl. Wrapper). CSS-Enqueue bleibt in beiden Pfaden aktiv.
  `renderShopProductGroups()` bleibt unverändert (Default-Renderer + evtl.
  Premium-Aufrufer).

- **Template-Variablen** (siehe Nutzer-Doku unten): `entry` bzw. `entries`
  (Events um `_formatted`-Keys angereichert), `with_buy_buttons`,
  `event_date/event_time/event_datetime` (nur Event-Fläche, formatiert),
  Funktionen `shop_buy_url()`, `shop_price()`. Buy-URL läuft IMMER durch
  `shopEventBuyUrl()` (Filter-Ausgang nie umgehbar über den Helper;
  Neubau per String-Konkatenation bleibt technisch möglich, ist aber nicht
  der freigegebene Weg — dokumentiert).

- **Formatierte Daten**: `date_i18n($fmt, strtotime($raw), true)` mit den
  Plugin-Format-Optionen `displayDateFormat`/`displayTimeFormat`
  (Default `Y/m/d` / `H:i`, `getOptionDateTimeFormat()` für kombiniert) —
  dasselbe Muster wie der `format_datetime`-Filter des Ticket-Designers
  (Z. 346–355). Unparsbar/leer → `''`.

## Test-Plan (TDD, tests/ShopTemplateOverrideTest.php)

1. Kein Template gesetzt → Shortcode-Ausgabe byte-identisch (Vorher/Nachher
   mit explizit `''` gespeichertem Template).
2. Template gesetzt (beide Flächen) → Template-Markup gewinnt, Variablen
   verfügbar (`entry.name`, `entry.event.*`, `groups`, `products` inkl.
   `price_cents/currency/display_desc`).
3. Formatierte Datum-Variablen: `2030-03-07 19:30` → `2030/03/07` +
   `19:30` (+ kombiniert) bei Default-Formaten.
4. Buy-URL: `shop_buy_url(entry, product)` liefert den
   `saso_eventtickets_shop_event_buy_url`-Filter-Ausgang (Test-Filter setzt
   kanonische URL) — Helper-Pfad gepinnt.
5. Ungültiges Template beim Speichern (Syntaxfehler, unbekannter Filter,
   `{% include %}`, PHP-Tag) → Exception, Optionswert unverändert.
6. Sandbox zur Renderzeit: direkt in die Option geschriebenes
   `{% include 'x' %}` (umgeht Save-Validierung) → `renderSurface` → `''`,
   Shortcode wirft keinen Fatal.
7. Nachbarn einzeln: ShopCatalogRenderingTest, ShopEventDetailTest,
   ShopBuyButtonTest, DisplayDescByProductTypeTest, ProductCatalogTest.
8. WP-Smoke (wp eval-file auf dev-WP): Default unverändert, Override greift,
   Cleanup vollständig.

## Non-Goals

- KEIN visueller Template-Editor, KEINE Syntax-Highlighting-UI — nur
  Registertextareas mit Variablen-Doku.
- KEIN Version-Bump (3.3.0-dev bleibt offen, Changelog ohne Datum).
- Premium (1.9.0 „shop presentation templates“) wird NICHT angerührt —
  dessen Twig-Port für Kasse-Flächen baut auf der Schnittstelle hier auf
  (siehe Rückgabe `notes_for_premium_port`).
- Kein Prod-Deploy, kein SVN, kein /var/www/html/vollstart.de/.

## Nutzer-Doku: Template-Variablen

Beide Flächen: leer gespeichert (oder Option ungesetzt) = eingebautes
Standard-Rendering. Ein gespeichertes Template rendert DIE KOMPLETTE
Shortcode-Ausgabe (Eigenes Markup inkl. Wrapper-DIV). Autoescape ist aktiv —
Variablen sind maskiert; `display_desc` ist serverseitig bereits
sicher (wp_kses_post) und wird per `|raw` ausgegeben.

### Fläche `catalog` (`shopTemplateCatalog`)
- `entries` — Liste der sichtbaren Events (Listen mit show_in_shop=1):
  `entry.id`, `entry.name`, `entry.event.event_start_date(_formatted)`,
  `entry.event.event_start_time(_formatted)`, `entry.event.event_location`,
  `entry.event.event_start_datetime_formatted`,
  `entry.groups` → `group.category_id`, `group.category_name`,
  `group.products` → `product.id`, `product.name`, `product.price_cents`,
  `product.currency`, `product.display_desc`
- `with_buy_buttons` — immer `false` (Katalog ist reine Browsing-Fläche).

### Fläche `event_detail` (`shopTemplateEventDetail`)
- `entry` — DAS Event (gleiches Format wie oben).
- `event_date`, `event_time`, `event_datetime` — formatierte Varianten
  (date_i18n mit den Plugin-Format-Optionen, Default `Y/m/d` / `H:i` /
  `Y/m/d H:i`); `''` wenn nicht gesetzt/unparsbar.
- `with_buy_buttons` — immer `true`.
- `shop_buy_url(entry, product)` — Buy-URL des Produkts (läuft immer durch
  `shopEventBuyUrl()` inkl. `saso_eventtickets_shop_event_buy_url`-Filter;
  ohne Premium/Kasse `''`).

### Funktionen (beide Flächen)
- `shop_price(cents)` — Preisformatierung wie im Standard-Renderer
  (`ticketCentsToPrice`), z. B. `{{ shop_price(product.price_cents) }}`.

## Offene Punkte

- Premium-Port (1.9.0): Kasse-/Shop-Flächen des Premium-Plugins nutzen
  künftig denselben Handler/Vertrag — dort ist der Options-Namespace und die
  Flächen-Abstraktion (`renderSurface`) zu übernehmen.
- Format-Quelle „WP-Datumsformat“: umgesetzt mit den Plugin-Optionen
  (`displayDateFormat`/`displayTimeFormat`) statt `get_option('date_format')` —
  konsistent mit dem Rest des Plugins (Kasse/PDF); falls Saso das Core-Format
  will, ist nur `shopFormattedEventMeta()` zu drehen.
