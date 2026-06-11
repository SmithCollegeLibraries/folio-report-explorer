# NL2SQL routing fix + pre-commit review remediation — 2026-06-08

Tracking doc for the debugging session that started from "Ask AI generates wrong
SQL for e-book / in-process listings" and expanded into a pre-commit review of
the uncommitted reference-cache/resolver feature branch.

## Part 1 — Original bug (FIXED + verified)

**Symptom:** Dev generated junk SQL (`ic.name ILIKE 'Smith College'`,
`imt.name ILIKE 'e-book'`, joined `material_type__t`) for a generic item listing,
while production produced the correct `camp.code='SC'` + material-type-subquery +
`MATERIALIZED` CTE pattern.

**Root cause:** `GeminiService::generateSqlWithShadow` decided query-family
routing from the **resolver-augmented** prompt. `ReferenceResolverService`
appends boilerplate ("…Do not apply this value to **library** or campus name
columns") to every resolved reference. The family matcher
`promptMentionsLibraryLocationListingScope` matches a bare `\blibrary\b`, so that
boilerplate word misrouted the query into the `inventory_library_location_listing`
intent compiler, which scoped everything to the default campus "Smith College"
with name-`ILIKE` filters.

**Fix:** Route on the raw `$prompt`, not `$effectivePrompt`
(`GeminiService.php` family routing + `resolvePrimaryModeForPrompt`). Guidance
still flows into `generateSql()` so the LLM sees it; only routing ignores it.

**Tests:** `backend/tests/GeminiServiceResolverGuidanceRoutingTest.php` (new) —
RED reproduced the misroute, GREEN after fix. Full backend suite 52/52.

**Secondary (also done):** Seeded dev's empty `ai_training_hints` table from
`mysql/seed_training_hints.sql` (206 rows) — needed for the freeform/shadow path.

---

## Part 2 — Pre-commit review findings & remediation

Status legend: ✅ fixed & verified · 🔧 in progress · ⏳ deferred (tracked) · ❎ won't-fix (rationale)

### Commit hygiene
| # | Item | Status |
|---|------|--------|
| H1 | `.playwright-mcp/` and `ask-*.png` not gitignored (would be committed) | ✅ added to `.gitignore`; `git check-ignore` confirms |
| H2 | New migrations `031`/`032` + `Reference*` services/tests untracked | ⏳ **must `git add`** before commit |

### 🔴 Deploy-breaker
| # | Finding | Status |
|---|---------|--------|
| 1 | `ai_clarification_events` columns only added to existing DBs by untracked migration `032`; controller inserts them → "Unknown column" on prod if not shipped | ✅ resolved by staging `031`/`032`; `deploy.sh:189` applies every `mysql/migrations/*.sql` in order. **No code change — just ensure the files are committed.** |

### 🔴 Silent wrong/empty SQL (same class as Part 1)
| # | Finding | File | Status |
|---|---------|------|--------|
| 2 | `campusCodeForName` 2-char fallback emits phantom `camp.code` for unmapped campus → 0 rows, no error | `QueryFamilyCompilerService.php` | ✅ map completed (+RP/Five Colleges/UMass Amherst); unmapped campus now **throws** `InvalidArgumentException` instead of guessing |
| 3 | Material-type extraction regex lacks `at/in/for` terminators → over-captured value → 0 rows | `GeminiService.php` | ✅ added `at/in/for/from` terminators to the unquoted pattern |
| 4 | Campus path uses exact `LOWER(name)=` while non-campus uses `ILIKE '%…%'` | `QueryFamilyCompilerService.php` / `QueryFamilySlotService.php` | ❎ **won't-change on campus path** — exact `LOWER(name)=` is the *canonical/blessed* pattern (matches production output + `ai_training_hints` examples). The non-campus `ILIKE '%…%'` is the looser one (can over-match, e.g. `%book%`→"Audiobook"); tightening it is a deferred follow-up (could regress existing partial-name matches) |
| 5 | `item_status` exact match with unnormalized value (`checked-out` vs `Checked out`) → 0 rows | `GeminiService.php`, `QueryFamilyCompilerService.php`, `QueryFamilySlotService.php` | ✅ normalized (hyphen/underscore/case → spaced canonical) at extraction **and** both SQL-build paths |
| 6 | Short reference codes (`art`/`gen`, len≥3) match ordinary prompt words → spurious filter | `ReferenceResolverService.php` | ⏳ deferred — proper fix requires threading the **raw** prompt into `matchReference` to require a case-sensitive code token (codes are uppercase like `SC`); a pure length/heuristic bump is too fragile to do safely now. Lower impact after the Part 1 routing fix (spurious guidance no longer hijacks routing) |

### 🟠 Error semantics & security
| # | Finding | Status |
|---|---------|--------|
| 7 | `buildAskContinuationFromFailure` downgrades 403/422/500 → 200 | ❎ **by design** — `FolioQueryControllerAskContinuationPolicyTest` explicitly asserts soft `RuntimeException` failures return 200 "recovery". This is the intended "Ask pause/continuation" UX. Residual: unexpected server errors are not visible as 5xx to monitoring — accepted trade-off; revisit if alerting needs it. |
| 8 | `isAskSecurityPolicyFailure` substring matching under/over-matches | ✅ added `app\exceptions\PolicyViolationException` (subclass of `InvalidArgumentException`); `SqlBuilderService::validateTablePolicy` throws it for blocked table/schema; controller now returns 403 on `instanceof` (regex kept as fallback). Decision is type-based, not message-based. |

### 🟠 New-service correctness
| # | Finding | Status |
|---|---------|--------|
| 9 | Non-atomic cache refresh (deactivate-all then row-by-row reinsert, no txn) → empty-cache window | ✅ deactivate + reinsert now wrapped in a DB transaction (commit on success, **rollBack** on failure → no wipe, no zero-active window). Residual: `queryAll()` is still unbounded — left as-is to avoid silent truncation; transaction makes a mid-load failure safe. |
| 10 | Resolver appends "Do not apply to library/campus columns" even when the match IS a campus/library | ✅ guidance is now source-table-aware: location-hierarchy matches drop the contradictory guard and no longer suppress code filters (camp.code is canonical); non-location refs keep the guard |

### Lower severity (confirmed)
| # | Finding | Status |
|---|---------|--------|
| 11 | Client `clarificationBatchId` inserted into `CHAR(36)` with no validation | ✅ added `normalizeClarificationBatchId()` (≤36 chars, safe token, else null) at both insert sites → no 1406/truncation |
| 6 | Short reference codes (`art`/`gen`) match ordinary prompt words → spurious filter | ✅ `matchReference` now matches codes **case-sensitively against the raw prompt** as standalone tokens (uppercase `ART`/`SC` no longer triggered by lowercase prose). Residual: a lowercase code that is also a dictionary word remains inherently ambiguous. |
| 12 | Frontend multi-round clarifications stack (`Ask.tsx` reads already-clarified `history[0].prompt`) | ⏳ deferred — genuine (edge case: 2+ clarification rounds in one flow). Proper fix = track the true original question in dedicated state, decoupled from `history[0]`, rather than a quick patch in the 1388-line component. Recommend as a focused frontend task with a vitest test. |
| 13 | Exploratory-approval consent gate removed (auto-runs unvetted SQL) | ❎ **by design** (confirmed 2026-06-08) — the continuation/recovery UX intentionally replaces the explicit "try anyway?" approval. No change. |

---

## Change log

### 2026-06-08 — Batch 1 (silent wrong/empty-SQL bugs + hygiene)
All changes test-first (RED → GREEN), full backend suite **55/55** after.

Production code:
- `GeminiService.php`: material-type unquoted regex now stops at `at/in/for/from`;
  added `normalizeItemStatusValue()`; item-status extraction returns the
  normalized value; `valueLooksLikeItemStatus()` reuses the helper.
- `QueryFamilyCompilerService.php`: `campusCodeForName()` map completed and now
  throws on unmapped campus; added `normalizeItemStatusValue()`; campus-scoped
  item_status param normalized.
- `QueryFamilySlotService.php`: non-campus `item_status` ILIKE value normalized
  to the spaced canonical form.
- `.gitignore`: ignore `.playwright-mcp/` and `/ask-*.png`.

New tests:
- `QueryFamilyCampusCodeTest.php` (#2 + #5 campus path)
- `GeminiServiceInventoryListingExtractionTest.php` (#3 + #5 extraction)
- `QueryFamilySlotItemStatusTest.php` (#5 non-campus path)

Outcome: each test reproduced the bug (RED) then passed (GREEN); no regressions
across the 55-test backend suite.

### 2026-06-08 — Batch 2 (deferred set)
All changes test-first (RED → GREEN), full backend suite **59/59** after.

Production code:
- `ReferenceResolverService.php`: `buildReferenceGuidanceLine()` now source-table-aware
  (#10); `matchReference()` takes the raw prompt and matches codes case-sensitively
  via new `promptContainsCaseSensitiveCode()` (#6).
- `ReferenceCacheRefreshService.php`: deactivate + reinsert wrapped in a transaction
  with rollback (#9).
- `FolioQueryController.php`: added `normalizeClarificationBatchId()` used at both
  clarification insert sites (#11).

New tests:
- `ReferenceResolverGuidanceLineTest.php` (#10)
- `ReferenceResolverCodeMatchTest.php` (#6)
- `ReferenceCacheRefreshAtomicityTest.php` (#9, source-assertion per repo convention)
- `FolioQueryControllerClarificationBatchIdTest.php` (#11)

### 2026-06-08 — Batch 3 (#8 structured policy error)
Test-first; full backend suite **61/61** after.
- New `backend/exceptions/PolicyViolationException.php` (extends `InvalidArgumentException`).
- `SqlBuilderService::validateTablePolicy` throws it for blocked table/schema
  references (MARC-redirect guidance left as plain `InvalidArgumentException`).
  `SqlBuilderService` `require_once`s the exception so standalone test harnesses
  (no autoloader) can construct it.
- `FolioQueryController::buildAskContinuationFromFailure` returns 403 when the
  error `instanceof PolicyViolationException`, with the keyword regex kept as a
  fallback for policy errors raised elsewhere.
- New tests: `SqlBuilderServicePolicyViolationTest.php`,
  `FolioQueryControllerPolicyViolationStatusTest.php`.

### Still open (focused follow-up tasks)
- **#13 — RESOLVED (by design):** confirmed 2026-06-08; continuation/recovery UX
  replaces the explicit "try anyway?" approval. No change.
- **#12** — frontend multi-round clarification stacking; track original question
  in dedicated state.
- **#4** (non-campus material-type `ILIKE` over-match) — optional tightening to
  exact match for consistency with the canonical pattern.
