# NL2SQL-008 SRBC 6xx Live Validation

## Summary
- User-confirmed live validation on 2026-05-13 showed that `Show me items from library location code SRBC that are missing 6xx fields` correctly returned results on the current runtime.
- This confirms the runtime-schema-drift hardening around `folio_source_record.records__t` did not break the location-code-scoped missing-`6xx` heuristic path.
- This is still heuristic source-record matching, not a new deterministic query-family capability.

## Validation Evidence
- User-confirmed successful live run for `Show me items from library location code SRBC that are missing 6xx fields`.
- Equivalent local runtime SQL already captured in `backend/runtime/logs/app.log.1` for this prompt family uses the expected repaired shape:
  - `LEFT JOIN folio_source_record.records__t rec ON rec.external_ids_holder__instance_id = inst.id AND COALESCE(rec.deleted, false) = false`
  - `loc.code = 'SRBC'`
  - `rec.parsed_record__content::text NOT ILIKE '%"6xx"%'`

## Remaining Risk
- Whole MARC field-family checks like `6xx` still rely on scoped source-record text matching while `marctab` remains blocked.
- A canonical approved field-family strategy is still needed before this behavior should be treated as fully hardened or deterministic.