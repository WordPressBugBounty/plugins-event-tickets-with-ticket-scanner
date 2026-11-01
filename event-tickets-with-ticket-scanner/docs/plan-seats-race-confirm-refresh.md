# Plan: Seating race — internal refresh wipes a confirmed seat selection

## Requirement (ticket: seats race, Maya blocked)

`js/seating_frontend.js` `confirmSelection()` writes the hidden input immediately,
~1s later an internal refresh (getSelectorData → applySelectorData) empties the
field while seats are actually selected/blocked. Requirement:

1. An internal refresh must NEVER leave the hidden field empty while seats are
   selected/blocked.
2. The server's existingBlocks restore path must repopulate the hidden input in
   the same format `updateSelection()` uses (JSON array of seat objects).
3. `closeModal`/`confirmSelection` ordering must be race-safe.

## Ist-Zustand (file:line evidence)

- `confirmSelection()` (js/seating_frontend.js:1230-1257): sets
  `this.selectedSeats`, `updateSelection()` (hidden input) and button text
  synchronously, then `blockSeatsInBackground()` + `closeModal()`.
- Internal refresh chain: `found_variation`/`reset_data` (626-633) →
  `onVariationChanged()` (1520) → AJAX `getSelectorData` (1547-1567) →
  `applySelectorData()` (1578-1614).
- **Root cause — js/seating_frontend.js:1608-1612**: every getSelectorData
  response runs
  `$selector.find('.saso-seat-selection-input').val('')` + `this.selectedSeats = []`
  unconditionally. A response that lands ~1s after `confirmSelection()` (the
  first `found_variation` request still in flight, or a later Woo-triggered
  refresh) wipes the just-confirmed selection.
- Server truth exists but is dropped: `buildSelectorData()`
  (includes/seating/class-seating-frontend.php:314-401) reports the session's
  live blocks as `existingBlocks` (332-374, filtered to the plan's seats,
  with `remaining_seconds`) whenever `currentSelection` is empty — which is
  exactly the AJAX path (`doGetSelectorData`, 542-563). `applySelectorData`
  never reads it.
- Second restore path that already works: `initializeExistingSelections()`
  (781-871) writes restored seats back into the hidden input — but only runs
  once at init, not on AJAX refreshes.
- `onVariationChanged()` legitimately clears a selection before the AJAX
  (1543-1545 `clearSelection`), so a wipe is only correct when there is neither
  a local selection nor server-side session blocks for the new plan.

## Fix (js/seating_frontend.js, applySelectorData only)

1. Before rebuilding: if a modal is open, `closeModal()` first (race-safe
   ordering — rebuild would orphan the modal DOM reference).
2. Derive keep/restore selection:
   - Server restore: map `data.existingBlocks` (skip `remaining_seconds <= 0`)
     to the seat shape `updateSelection()` uses (`seat_id, seat_label,
     seat_category, seat_desc, block_id, expires_at, countdown_end =
     Date.now() + remaining_seconds*1000`), merging the local `owner_token`
     from `currentBlockOwners` (server never returns it).
   - Keep local: if `this.selectedSeats` is non-empty and every seat_id exists
     in `data.seats` (same plan), keep the local seats (covers the race where
     the refresh response beats the blockSeat write).
3. If a keep/restore list exists: `selectedSeats` = list, track
   `currentBlockIds`/`currentBlockOwners`, `updateSelection()` + rebuild UI +
   `updateButtonText()`. Never write `val('')` in this branch.
4. Else: current behaviour (`val('')`, `selectedSeats = []`) — genuine reset
   stays a reset.

## Test plan (jest, js_tests/seatingConfirmRefreshKeepsSelection.test.js)

Extract `confirmSelection`, `applySelectorData`, `updateSelection`, `closeModal`
from the IIFE (balanced-brace extractor, same harness as
startScanProcessingDeadlock.test.js) into a sandbox with a minimal jQuery fake.

- T1 (RED): confirmSelection → getSelectorData response WITH existingBlocks →
  hidden input still carries the selection, selectedSeats non-empty.
- T2 (RED): confirmSelection → refresh response with EMPTY existingBlocks
  (response beat the block write) → input keeps the local selection.
- T3 (restore path): no local selection, server existingBlocks → selectedSeats
  + hidden input populated in updateSelection format, block ids tracked.
- T4 (regression guard): no local selection, no existingBlocks → input emptied
  (genuine reset preserved).
- T5 (ordering): open modal during refresh → modal closed before rebuild,
  `$currentModal` null afterwards.

Non-goals: no changes to PHP, no changes to block/release protocol, no new
AJAX calls, no backend.js work.

## Non-goals / boundaries

- Do not touch ticket_scanner.js, backend.js.
- Do not change `clearSelection()` semantics (variation switch must still
  release blocks).
