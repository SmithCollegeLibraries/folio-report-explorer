# NL2SQL-008 Collection-Age Legacy Shadow Alignment

## Summary
- Tightened the legacy freeform prompt path for the covered `inventory_collection_age` family so the shadow query for `What is the average age of items in the Neilson Reference collection?` is no longer semantically drifting onto record-created dates or merged location keywords.
- The legacy path now receives prompt-specific rewrite text that:
  - preserves the original user prompt
  - injects recovered `Neilson Library` and `Reference collection` scopes
  - requires bibliographic publication-year age logic
  - requires the holdings -> instance -> publication join path
  - requires separate full-phrase library and location predicates

## Files Changed
- `backend/services/GeminiService.php`
- `backend/tests/GeminiServiceLegacyPromptGuidanceTest.php`
- `planning/tickets.md`

## Validation Evidence
- `php backend/tests/GeminiServiceLegacyPromptGuidanceTest.php`
- `php -l backend/services/GeminiService.php`
- Direct forced-mode comparison inside the running `php` container now shows:
  - deterministic SQL: `inventory.instance__t__publication`, `'%Neilson Library%'`, `'%Reference collection%'`
  - legacy SQL: `inventory.instance__t__publication`, `'%Neilson Library%'`, `'%Reference collection%'`
- Live `/api/nl` request under current Step 8 settings still routes primary to `builder_intent` with `routeReason=family_contract_supported:inventory_collection_age`.

## Step 8 Status
- Regenerated `planning/baseline/reports/2026-05-12_nl2sql-008-shadow-metrics.md` after the fresh live request.
- Current report values:
  - `Events scanned: 5`
  - `shadow_compare events: 5`
  - `SQL hash mismatch count: 5`
  - `Route divergence count: 5`
  - `Covered-family legacy-primary mismatch count: 1`
  - `Required period day status: BLOCKED_COVERED_FAMILY_LEGACY_PRIMARY_MISMATCH`
- The day is still blocked by the earlier same-day covered-family primary-legacy event at `17:48:13+00:00`, not by the current collection-age legacy shadow semantics.

## Remaining Risk
- SQL hash parity is still not achieved even though the current legacy and deterministic queries are now semantically aligned for this prompt. The remaining mismatch appears to be SQL-shape/text differences rather than the earlier incorrect age source or scope decomposition.