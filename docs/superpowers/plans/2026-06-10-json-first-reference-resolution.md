# JSON-First Reference Resolution Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a JSON-first local reference resolver so location and lookup terms are resolved from approved static FOLIO reference tables before Ask AI generates SQL.

**Architecture:** Add a focused reference bundle service that owns the approved table list, JSON bundle loading, and bundle generation. `ReferenceResolverService` reads the JSON bundle first, uses stronger location-hierarchy matching, and only falls back to MySQL reference rows when JSON is unavailable. SQL validation continues to fail closed when resolved location values are applied to the wrong hierarchy table.

**Tech Stack:** PHP/Yii backend, local JSON files under `backend/data`, existing console commands, existing lightweight PHP test scripts.

---

### Task 1: Approved Reference Bundle Service

**Files:**
- Create: `backend/services/ReferenceJsonBundleService.php`
- Modify: `backend/commands/ReferenceCacheController.php`
- Test: `backend/tests/ReferenceJsonBundleServiceTest.php`

- [ ] **Step 1: Write the failing service test**

Create `backend/tests/ReferenceJsonBundleServiceTest.php` with assertions that approved tables include `inventory.location__t`, `inventory.material_type__t`, and `finance.fund__t`, and excluded tables reject `inventory.item__t`, `inventory.instance__t`, and `inventory.holdings_record__t`.

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec -T php php tests/ReferenceJsonBundleServiceTest.php`

Expected: fail because `ReferenceJsonBundleService.php` does not exist.

- [ ] **Step 3: Implement the service**

Create `ReferenceJsonBundleService` with:

```php
public const DEFAULT_BUNDLE_ALIAS = '@app/data/reference_cache.json';
public static function approvedTables(): array;
public static function excludedTables(): array;
public static function isApprovedTable(string $sourceTable): bool;
public static function normalizeText(string $text): string;
public static function loadBundle(?string $path = null): array;
public static function loadReferences(?string $path = null): array;
public function buildBundle($folioDb): array;
public function writeBundle($folioDb, ?string $path = null): int;
```

The approved table list is exactly the user-approved list from the design spec.

- [ ] **Step 4: Add a console command**

Add `reference-cache/write-json` to `ReferenceCacheController`, implemented by calling `ReferenceJsonBundleService::writeBundle(Yii::$app->folioDb)`.

- [ ] **Step 5: Run test to verify it passes**

Run: `docker compose exec -T php php tests/ReferenceJsonBundleServiceTest.php`

Expected: pass.

### Task 2: JSON-First Resolver Loading

**Files:**
- Modify: `backend/services/ReferenceResolverService.php`
- Test: `backend/tests/ReferenceResolverJsonFirstTest.php`

- [ ] **Step 1: Write failing resolver tests**

Create `ReferenceResolverJsonFirstTest.php` that writes a temporary JSON bundle containing `SC Josten Treasure`, `SC Josten Treasure Folio`, and `SC Josten Library`, then verifies:

- `show items in josten treasure and treasure folio` resolves both location rows.
- `SC Josten Library` resolves as `inventory.loclibrary__t`.
- excluded operational tables cannot appear in the loaded reference list.

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec -T php php tests/ReferenceResolverJsonFirstTest.php`

Expected: fail because the resolver does not load the JSON bundle or match the split partial phrase.

- [ ] **Step 3: Implement JSON-first loading**

Update `ReferenceResolverService::loadEnabledReferenceValues()` to call `ReferenceJsonBundleService::loadReferences()` first. If the JSON bundle returns rows, use them. If it is missing or empty, fall back to the current MySQL `folio_reference_values` query.

- [ ] **Step 4: Implement hard location matching**

Enhance reference matching so `inventory.location__t`, `inventory.loclibrary__t`, `inventory.loccampus__t`, `inventory.locinstitution__t`, and `inventory.service_point__t` support exact name/code, stripped campus-prefix name, and strong partial token matching. Preserve multiple location matches.

- [ ] **Step 5: Run resolver tests**

Run:

```bash
docker compose exec -T php php tests/ReferenceResolverJsonFirstTest.php
docker compose exec -T php php tests/ReferenceResolverServiceTest.php
```

Expected: pass.

### Task 3: Hard SQL Wrong-Level Guard

**Files:**
- Modify: `backend/services/GeminiService.php`
- Test: `backend/tests/GeminiServiceResolvedLocationGuardTest.php`

- [ ] **Step 1: Extend the guard test**

Add a case where SQL filters `loc.name ILIKE '%SC Josten Treasure%'` and also filters `lib.name ILIKE 'SC Josten Treasure%'`; the repair must remove the library predicate and validation must fail if the wrong-level predicate remains.

- [ ] **Step 2: Run test to verify current gap**

Run: `docker compose exec -T php php tests/GeminiServiceResolvedLocationGuardTest.php`

Expected: fail for prefix/partial wrong-level location predicates if current validation does not catch them.

- [ ] **Step 3: Tighten validation**

Update `validateNoResolvedLocationPredicateMisuse()` so a library predicate is invalid when its normalized value equals, contains, or is contained by a resolved `inventory.location__t` predicate value. Keep campus/library legitimate predicates allowed when they are not location names.

- [ ] **Step 4: Run guard test**

Run: `docker compose exec -T php php tests/GeminiServiceResolvedLocationGuardTest.php`

Expected: pass.

### Task 4: Generate Bundle And Verify End To End

**Files:**
- Generate: `backend/data/reference_cache.json`
- Verify: backend PHP tests

- [ ] **Step 1: Generate the approved JSON bundle**

Run: `docker compose exec -T php php yii reference-cache/write-json`

Expected: command reports row count and writes `backend/data/reference_cache.json`.

- [ ] **Step 2: Verify excluded tables**

Run: `rg -n "inventory\\.item__t|inventory\\.instance__t|inventory\\.holdings_record__t" backend/data/reference_cache.json`

Expected: no matches.

- [ ] **Step 3: Verify key location data**

Run: `rg -n "Josten Treasure|Josten Treasure Folio|SC Josten Library" backend/data/reference_cache.json`

Expected: all known Josten reference labels are present if they exist in live FOLIO.

- [ ] **Step 4: Run focused regression suite**

Run:

```bash
docker compose exec -T php php tests/ReferenceJsonBundleServiceTest.php
docker compose exec -T php php tests/ReferenceResolverJsonFirstTest.php
docker compose exec -T php php tests/GeminiServiceResolvedLocationGuardTest.php
docker compose exec -T php php tests/ReferenceCacheInventoryAllowlistTest.php
```

Expected: all pass.

### Task 5: Documentation

**Files:**
- Modify: `docs/reference-cache-operations.md`

- [ ] **Step 1: Document JSON-first command and contract**

Add the command `php yii reference-cache/write-json`, the approved table policy, and the hard exclusions.

- [ ] **Step 2: Verify docs mention exclusions**

Run: `rg -n "reference-cache/write-json|inventory\\.item__t|inventory\\.instance__t|inventory\\.holdings_record__t" docs/reference-cache-operations.md`

Expected: all terms are present.
