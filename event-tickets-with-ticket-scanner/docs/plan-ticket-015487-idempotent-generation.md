# Plan: 015487 — idempotent ticket-code generation (atomic, cache-independent claim)

## Requirement (osTicket 18133, JCastles, HPOS+Xendit+Redis)

~1% of multi-qty orders: duplicate ticket codes (exactly 2x qty) in two
batches seconds apart — batch 1 at the payment-recorded note, batch 2 at
processing→completed. Not a settings error. The 0264407 per-order lock does
not help: the two hooks fire ~2s apart, so the lock is FREE again for run 2.
Idempotency must become CACHE-INDEPENDENT and ATOMIC: direct SQL claim with a
unique key on the order/item; the meta "codes generated" write stays but is
never the only guard.

## Ist-Zustand (file:line evidence)

- Trigger chain: `woocommerce_order_status_changed`
  (index.php:666 → class-order.php:377) → `add_serialcode_to_order()`
  (530, GET_LOCK 0264407) → `add_serialcode_to_order_run()` (548) →
  per item `add_serialcode_to_order_forItem()` (651).
  Xendit webhook fires the hook twice seconds apart (pending→processing,
  later processing→completed) — sequentially, not in parallel: the lock is
  released between them (finally at 537-539).
- **Root cause — class-order.php:673-677**: the ONLY "already generated"
  guard is `wc_get_order_item_meta($item_id, self::META_ORDER_ITEM_CODES, true)`
  — a read through the WP meta cache (Redis-backed on the affected install).
  If the second trigger's read serves the PRE-generation snapshot (empty),
  `quantity_needed = quantity` and a full second batch is generated.
- The guard's write side is `addMetaToOrderItem()` (1433-1453,
  `wc_add_order_item_meta`, comma-joined codes) — kept as is.
- codes table (db.php:31-56) has order_id but no item_id column, and the
  codes are a comma-joined string in order-item meta — so per-item truth
  must come from `wp_woocommerce_order_item_meta` via direct SQL
  (cache-independent), not from the WP meta API.

## Fix

1. **New table `saso_eventtickets_tgen_claims`** (db.php):
   `id BIGINT PK, time DATETIME, order_id BIGINT UNSIGNED, item_id BIGINT
   UNSIGNED, UNIQUE INDEX (order_id, item_id)`. `_tabellen[]` += 'tgen_claims';
   dbversion 1.26 → 1.27 (installiereTabellen runs on version mismatch).
2. **`claimTicketGenerationForItem(int $order_id, int $item_id): bool`**
   (class-order.php, public): one `INSERT IGNORE` via `$wpdb->query(prepare)`
   — atomic in MySQL, no read-modify-write, nothing cacheable involved.
   Returns true only when rows_affected === 1 (fresh claim). Any wpdb error
   (e.g. table not yet installed) → false → caller falls through to the
   DB-verify path, which is safe by itself.
3. **`getOrderItemCodesDirectFromDb(int $item_id): string`** (public):
   `SELECT meta_value FROM {$wpdb->prefix}woocommerce_order_item_meta WHERE
   order_item_id = %d AND meta_key = %s LIMIT 1` — bypasses the object cache
   entirely (order item meta is classic-table in HPOS too).
4. **In `add_serialcode_to_order_forItem()`**: claim first; then the meta
   read as before — but when the claim was NOT fresh (second trigger within
   ANY window), the codes come from the direct DB read, never from the
   (possibly stale) cache. quantity_needed = quantity - count(existing) —
   top-up semantics (partial generation, manual admin regeneration) survive:
   a duplicate claim with fewer codes in the DB tops up only the missing ones.

## Test plan (tests/WCOrderTicketGenerationIdempotencyTest.php)

- T1 (RED): both hooks for one order — status processing fires batch 1
  (real `woocommerce_order_status_changed` action), then the item-meta cache
  is poisoned with the pre-generation empty snapshot (exactly what Redis
  served the second run), then processing→completed fires. Codes must equal
  qty (RED today: 2x qty).
- T2 (RED): top-up stays exact — DB item meta carries 1 of 3 codes, cache
  poisoned empty, second trigger → exactly 2 more (RED today: generates 3 → 4).
- T3 (RED): claim is DB-atomic — `claimTicketGenerationForItem` true once,
  false on repeat for the same (order,item), true for another item of the
  same order (RED today: method missing).
- T4: fresh-claim first run keeps normal generation (guard against over-
  blocking; green with the lock suite as neighbour).

## Non-goals

- No changes to the GET_LOCK (parallel-run) semantics from 0264407.
- No backfill/cleanup of the 24 affected orders in code (customer-side
  cleanup is answered in the support reply draft).
- No premium-repo changes (the guarded path lives in the free class the
  premium calls into).
