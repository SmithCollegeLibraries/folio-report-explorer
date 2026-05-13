# NL2SQL-008 Shadow Metrics Report

- Date: 2026-05-13
- Log file: backend/runtime/logs/app.log
- Events scanned: 7

## Summary
- shadow_compare events: 6
- shadow_error events: 1
- SQL comparison match count: 1
- SQL comparison mismatch count: 5
- SQL comparison unknown count: 0
- SQL comparison match rate: 16.67%
- SQL comparison mismatch rate: 83.33%
- Raw SQL hash match count: 0
- Raw SQL hash mismatch count: 6
- Raw SQL hash unknown count: 0
- Raw SQL hash match rate: 0.00%
- Raw SQL hash mismatch rate: 100.00%
- Route divergence count: 6
- Covered-family legacy-primary mismatch count: 1
- Data source mismatch count: 0
- Provider fallback warning count: 3
- builder_intent generated events with slot provenance: 7
- clarification generated events with slot provenance: 0
- validation events with slot provenance: 0

## Provider Fallback Reasons
- 3 quota_exhausted

## Slot Provenance Signals
- 7 campus = model_output
- 6 location = policy_omitted_explicit_prompt_only
- 6 library = model_output
- 1 location = prompt_repaired
- 1 library = prompt_repaired

## Slot Provenance Sources
- 7 generated.builder_intent / campus = model_output
- 6 generated.builder_intent / location = policy_omitted_explicit_prompt_only
- 6 generated.builder_intent / library = model_output
- 1 generated.builder_intent / location = prompt_repaired
- 1 generated.builder_intent / library = prompt_repaired

## Top Route Pairs
- 5 builder_intent -> legacy_freeform
- 1 legacy_freeform -> builder_intent

## Covered-Family Legacy-Primary Divergences
- 1 inventory_collection_age

## Top Shadow Errors
- 1 AI API error: This model is currently experiencing high demand. Spikes in demand are usually temporary. Please try again later.

## Latest Shadow Compare
- Timestamp: 2026-05-13T15:23:47+00:00
- Prompt fingerprint: fc0a4f1e75af2695
- Primary mode/route: intent / builder_intent
- Primary route reason: family_contract_supported:inventory_collection_age
- Shadow mode/route: legacy / legacy_freeform
- Shadow route reason: forced_legacy_mode
- SQL comparison: true (semantic_sql_signature)
- Raw SQL hash match: false
- Data sources: folio -> folio
- SQL lengths: 1019 -> 915

## Latest Covered-Family Legacy-Primary Divergence
- Timestamp: 2026-05-13T15:22:00+00:00
- Prompt fingerprint: fc0a4f1e75af2695
- Covered family: inventory_collection_age
- Primary route reason: forced_legacy_mode
- Shadow route reason: family_contract_supported:inventory_collection_age
- SQL comparison: unknown (raw_sql_hash)
- Raw SQL hash match: false

## Gate Worksheet
- Required period day status: BLOCKED_COVERED_FAMILY_LEGACY_PRIMARY_MISMATCH
- Compare/error trend acceptable: NO
- Covered-family legacy-primary mismatches acceptable: NO
- Rollback exercised recently: TODO
