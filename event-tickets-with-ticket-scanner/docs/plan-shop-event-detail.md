# Plan: Öffentliche Event-Detail-Seite (3.3.0 Slice 2)

## Requirement (Saso 28.10., Produktenntscheidungen nachrüstbar)

Eine Besucher-Ansicht PRO Liste (Event): Event-Kopf aus Listen-Meta
(event_start_date, event_start_time, event_location — kamen mit 3.2.9) +
darunter die Produkte dieser Liste (Wiederverwendung Slice-1-Rendering) +
Kaufen-Buttons. Saso-Entscheidungen: (1) NUR Listen-Produkte im Katalog —
KEINE list_id=0; (2) show_in_shop absent = versteckt (striktes Opt-in,
wie Slice 1); (3) Kaufen-Button führt DIREKT in die eigene ET-Kasse
(interner Bestellflow) — NICHT auf Woo-Produktseiten.

## Ist-Zustand (Recon, Code verifiziert 28.10.)

- **Event-Meta liegt in der Listen-Meta-JSON**: `_setMetaDataForList`
  (sasoEventtickets_AdminSettings.php:1738) schreibt `event_location`
  (sanitize_text_field, ≤255), `event_start_date`/`event_end_date`
  (datepicker-Sanitize → `YYYY-MM-DD` oder ''), `event_start_time`/
  `event_end_time` (`H:MM|HH:MM(:SS)` oder ''). Konsumenten (Core:1267,
  Ticket:606) lesen die String-Form unverändert.
- **Addressierung öffentlicher Flächen (bestehendes Muster)**: Shortcode +
  Attribute mit GET-Fallback — `ticket_detail` liest `code`-Attribut oder
  `?ticket=` (index.php:1710). Kein Custom Routing/keine Rewrites im Free;
  die Premium-Referenz `/tickets/<id>/` existiert nur in Kommentaren
  (Premium-Repo: kein Zugriff). → Andocken: neuer Shortcode mit
  `list_id`-Attribut + `?event=`-Fallback.
- **Öffentlicher Kasse-Einstieg im FREE: nicht vorhanden.**
  `sasoEventtickets_Frontend::executeJSON` kennt nur checkCode/getOptions/
  registerToCode/premium; `addInternalOrder` ist Admin-AJAX (Nonce +
  Capability). Der öffentliche Kauf-Start liegt Premium-seitig
  (executeJSONPremium), Repo nicht zugreifbar → Kaufen-Button-Ziel als
  klar markierte TODO-Konstante + Filter, NICHT raten.
- **Slice 1** (6337572): `getShopCatalog()` (AdminSettings:4414, Gate
  `show_in_shop === 1`), Renderer `replacingShortcodeShopCatalog()`
  (index.php:2216), `css/shop_catalog.css`, Doku-Zeile in
  `$_shortcodes_table` (~:1400), Tests `ShopCatalogRenderingTest.php`
  10/10.

## Architektur-Entscheidung

1. **Datenlayer**: `getShopCatalog()` liefert je Liste zusätzlich
   `"event" => [event_start_date, event_start_time, event_location]`
   (additiv, rückwärtskompatibel — Slice-1-Tests unangetastet). Neu:
   `getShopEvent($data)` = Detail-Payload einer Liste; Gate identisch
   `show_in_shop === 1` (0/absent → Detail-Seite ebenfalls unsichtbar),
   baut intern auf `getShopCatalog(['only_ids'=>[id]])` auf → gleiche
   Gruppen-/Produkt-Logik, keine Kopie. Rückgabe `[]` bei unbekannter oder
   versteckter Liste.
2. **Rendering**: neuer Shortcode `sasoEventTicketsValidator_shop_event`
   (Property + add_shortcode + Doku-Zeile), PHP-serverseitig wie Slice 1.
   Adressierung: `list_id`-Attribut oder `$_GET['event']` (intval; Muster
   wie `ticket_detail`), Attribut gewinnt. Versteckt/unbekannt/leer → ''
   (stille Degradation wie der Katalog). Event-Kopf: Listenname (h2) +
   Datum/Uhrzeit/Ort als Meta-Items — nur gerendert, wenn nicht leer;
   alles `esc_html`. Produkte: gemeinsamer Helper
   `renderShopProductGroups($entry, $withBuyButtons)` — Slice-1-Katalog
   rendert mit `false` (Ausgabe byte-identisch), Detail-Seite mit `true`.
   display_desc bleibt roh (kses-gesichert aus getTicketProducts).
3. **Kaufen-Button**: `<a class="et-shop-catalog__buy" data-et-buy="{id}"
   href="{url}">Buy</a>` pro Produkt. URL: Klassen-Konstante
   `SHOP_EVENT_BUY_URL_TEMPLATE = ''` (klar markiertes TODO — öffentlicher
   Kasse-Einstieg ist Premium-seitig, hier NICHT geraten) + Filter
   `saso_eventtickets_shop_event_buy_url` (Args: url, list_id, product_id)
   als Integrationpunkt. Leer → `href="#"`. Kein Woo-Link.
4. **CSS**: `shop_catalog.css` erweitert um `.et-shop-event__*` BEM-Blöcke
   (keine Inline-Styles). Kein JS.

## Test-Plan (RED zuerst) — tests/ShopEventDetailTest.php

Datenlayer:
1. Katalog-Eintrag trägt event-Meta additiv (Slice-1-Verträge bleiben)
2. getShopEvent (gated Liste): name + event-Felder + nur Produkte DIESER Liste
3. show_in_shop=0 → []; Flag absent → [] (Detail unsichtbar)
4. unbekannte list_id → []
5. inaktive Produkte außen vor
Renderer:
6. Kopf rendert Datum/Uhrzeit/Ort esc_html'd (location mit `<script>`
   → escaped, kein `<script` im HTML)
7. leere Meta-Felder rendern kein Element
8. Kaufen-Button pro Produkt: class et-shop-catalog__buy + data-et-buy
9. Button-Ziel: Default '' → href="#"; Filter gesetzt → Filter-URL
10. nur Produkte der Liste im HTML (Fremdprodukt einer anderen gated Liste fehlt)
11. Adressierung: list_id-Attr; ?event=-Fallback; Attr gewinnt; hidden → ''
12. Regression: Katalog-HTML (Slice 1) enthält keinen Buy-Button

Command (EIN Datei-Argument; Nachbarn EINZELN):
`WP_TESTS_DIR=/home/web3/wp-test-lib/wordpress-tests-lib vendor/bin/phpunit --no-configuration --bootstrap tests/bootstrap.php tests/ShopEventDetailTest.php`

## Non-Goals (Slice 2)

- Kein Twig-Template (Slice 3: Ticket-Designer-Engine wiederverwenden)
- Kein echter Checkout-Endpunkt / keine Bestell-Logik im Free
- Kein Woo-Link, keine Premium-Änderungen, keine Admin-UI
- Keine neuen Rewrite-Rules/Permalinks (bestehendes Shortcode-Muster)
- Keine globalen Produkte (list_id=0) — Gate liegt auf Listen
