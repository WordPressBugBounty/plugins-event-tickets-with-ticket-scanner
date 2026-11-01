# Plan: Öffentlicher Shop-Katalog (3.3.0 Slice 1)

## Requirement (Saso 28.10.)

„weiter machen mit der ET-Erweiterung zum full eigenen Ticket-Shop" — Slice 1 NUR:
(a) Produkt-in-Liste-Rendering im Katalogkontext, (b) Listen-Katalog-Ansicht mit
`show_in_shop`-Meta als Gate (1 = im Katalog, 0 = nicht), inkl. Kategorie-Gruppierung
(Kategorien-Infra: 4dac8fc). KEINE Event-Detail-Seite (Slice 2), KEIN Twig-Editor (Slice 3).

## Ist-Zustand (verifiziert 28.10.)

- `show_in_shop` = Listen-Meta-Flag, gespeichert via editList (`sasoEventtickets_AdminSettings.php:1773`).
  Öffentliches Rendering dafür existiert NICHT.
- Produktdaten: `getTicketProducts()` (AdminSettings:4340) liefert `display_desc`
  (Vertrag: own desc > Listen-desc nur wenn list_id>0 > ''; kses-Sanitize, Tests:
  `tests/DisplayDescByProductTypeTest.php`), `price_cents`, `category_name` (4dac8fc).
- Kategorien: `getProductCategories()` (AdminSettings) — sort asc, name asc.
- Shortcode-Architektur: index.php — Property (~:56), `add_shortcode` (~:540),
  Renderer-Methode (Referenz `replacingShortcodeEventViews` ~:2056), Doku-Tabelle
  `$_shortcodes_table` (~:1361).

## Architektur-Entscheidung

- **Datenlayer**: `getShopCatalog($data)` in `sasoEventtickets_AdminSettings.php` (public
  Getter wie `getTicketProducts`). Gate strikt `show_in_shop === 1` (Absent/0 = versteckt —
  öffentliche Fläche ist Opt-in; bewusst anders als die Premium-/tickets/-Übersicht, wo
  Absent sichtbar bleibt — Kommentar am editList-Flag dokumentiert das Premium-Verhalten).
  Produkte JE Liste via `getTicketProducts(['list_id'=>$id,'aktiv'=>1])` — erbt display_desc-Vertrag
  + Sanitize + category_name unverändert (keine Logik-Kopie).
- **Gruppierung**: statischer Pure-Helper `groupShopProductsByCategory(array $products, array $categories)`
  — Gruppen in Kategorien-Sortierung, Produkte ohne Kategorie (category_id=0/unbekannt)
  als letzte Gruppe ohne Namen. Direkt testbar.
- **Rendering**: neuer Shortcode `sasoEventTicketsValidator_shop_catalog` in index.php,
  PHP-serverseitig (öffentliche Besucherfläche, kein Nonce/AJAX nötig; Kasse-Muster =
  Server liefert sanitized Daten, Ausgabe rendert HTML: desc roh — ist kses-gesichert —,
  Name/Preis/Kategorie `esc_html`). BEM-Klassen `.et-shop-catalog__*` in neuem
  `css/shop_catalog.css` (keine Inline-Styles, Frontend-Redesign-Regel). Leerer Katalog → ''.
- Kein JS in Slice 1 (reine Anzeige, keine Interaktion).

## Test-Plan (RED zuerst)

`tests/ShopCatalogRenderingTest.php`:
1. show_in_shop=1 → Liste + Produkte im Katalog (Payload + HTML)
2. show_in_shop=0 → Liste nicht im Katalog
3. Flag absent → nicht im Katalog (strikt, Opt-in)
4. display_desc-Prio im Katalog: eigene desc > Listen-desc; Fallback wenn own leer
5. Kategorie-Gruppierung: Sortierung nach Kategorie-sort, „ohne Kategorie" zuletzt, Namen aufgelöst
6. inaktive Produkte (aktiv=0) erscheinen nicht
7. HTML: Preis+Währung, desc kses-gefiltert (script gestrippt), show_in_shop=0-Listenname nicht im HTML
8. leerer Katalog → ''

Command: `WP_TESTS_DIR=/home/web3/wp-test-lib/wordpress-tests-lib vendor/bin/phpunit --no-configuration --bootstrap tests/bootstrap.php tests/ShopCatalogRenderingTest.php`

## Non-Goals (Slice 1)

- Keine Event-Detail-Seite (Slice 2: Event-Kopf Datum/Uhrzeit/Ort aus Listen-Meta + Produkte)
- Kein Twig-Template-Editor (Slice 3: Wiederverwendung Ticket-Designer-Engine prüfen)
- Kein Kauf-Button/Checkout im öffentlichen Katalog (Kasse ist fertig; Anbindung später)
- Keine globalen Produkte (list_id=0) im öffentlichen Katalog — Gate liegt auf Listen
- Keine Admin-UI für den Shortcode (nur Doku-Zeile in der Shortcode-Tabelle)
