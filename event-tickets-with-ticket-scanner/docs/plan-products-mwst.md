# Products & Tax — proper architecture (Saso, 30.10.2026)

Saso's directive (verbatim core): Options, new section. Products, MWST (VAT).
Manage VAT rates, assign them to products and/or ticket lists. Product types:
event (ticket for the event, mandatory), produkt (e.g. drinks, optional),
gebuehr (fee, automatically added as default).

## Data model

1. New table `saso_eventtickets_tax_rates`:
   id, time, timezone, name (e.g. "Reduced 7%"), percent DECIMAL(5,2),
   aktiv TINYINT, is_deleted TINYINT, created_by, updated_by, created_at, updated_at
   (conventions like all plugin tables; percent stored with decimal POINT,
   input accepts comma or point — decimal convention 29.10.)

2. Extend `saso_eventtickets_products` (self-healing ALTER, ADD COLUMN only):
   - product_type VARCHAR(20) NOT NULL DEFAULT 'event'   -- 'event' | 'product' | 'fee'
   - tax_rate_id BIGINT NOT NULL DEFAULT 0                -- 0 = no rate / 0%
   - is_optional TINYINT NOT NULL DEFAULT 0               -- for type 'product': choosable add-on
   - auto_add TINYINT NOT NULL DEFAULT 0                  -- for type 'fee': auto-added default line
   Legacy: meta.tax_rate stays as fallback; migration backfills tax_rate_id by
   creating matching rates (19 => "Standard 19%") if none exists (idempotent).

3. Ticket lists (`lists`): optional default_tax_rate_id BIGINT DEFAULT 0 —
   applies to products of that list that have no own rate (list-level assignment
   per Saso: "diese produkten und/oder ticket liste zuweisen").

## Behaviour (shop + kasse)

- Type 'event' = the ticket itself: mandatory in its list, listed first.
- Type 'product' = optional add-on (drinks): rendered with checkbox, only
  included when chosen; is_optional=1 default for this type.
- Type 'fee' with auto_add=1: always present as order line by default
  (system fee / Vorverkaufsgebuehr), shown non-selectable in UI;
  fee with auto_add=0 behaves like an optional product.
- Rate resolution order per line: product.tax_rate_id > list.default_tax_rate_id > 0.
- Freeze per order line stays exactly as built (Slice 5C) — resolution happens
  at order creation, orders never re-resolve.

## Admin UI (Options, new section "Products & Tax")

- New topmenu tab (backend.js): msgid 'Products & Tax' (EN msgid from day one,
  German translation in de po/mo/json — Kasse lesson).
- Tax rates CRUD: name, percent, active.
- Products CRUD: name, type (event/product/fee), price, tax rate (dropdown),
  optional/auto-add flags, list assignment (dropdown of lists + "global").
- Server actions routed through AdminSettings AJAX router like getTicketProducts.

## Tests (test-first, BUGFIX-REGEL)

- TaxRateCrudTest (create/update/soft-delete, percent comma/point equivalence)
- ProductTypeMigrationTest (ALTER idempotent, legacy meta.tax_rate -> tax_rate_id backfill)
- ProductAssignmentTest (rate resolution product > list > 0)
- FeeAutoAddTest (order creation includes auto_add fee line by default)
- ShopOptionalProductTest (optional product only when chosen; mandatory event included)
- Labels: jest pins EN msgids + de translations present (Sales/Kasse pattern)

## Changelog

readme.txt + changelog.txt 3.3.0 block, EN, no PII.
