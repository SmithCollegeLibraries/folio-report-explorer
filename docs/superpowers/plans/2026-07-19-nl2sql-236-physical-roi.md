# NL2SQL-236 Physical Acquisitions and Circulation ROI Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the documented five-year call-number ROI request compile into a validated physical-only Smith report with additive copies, titles, spending, circulation, ROI, and disclosed exact-versus-fallback linkage.

**Architecture:** Extend the exploratory semantic contract with policy-backed physical and Smith acquisitions constraints, then compile the approved default contract through a new deterministic `physical_roi_v2` service. Select v2 through a default-on backend runtime flag while retaining the current compiler as the rollback path; run every compiled candidate through existing safety, schema-reference, semantic-conformance, and PostgreSQL preflight gates.

**Tech Stack:** PHP 7.2, Yii 2, PostgreSQL SQL generation, cached FOLIO schema metadata, standalone PHP regression scripts, Vitest for unchanged frontend regression verification.

## Global Constraints

- “Purchased most” means invoiced physical copies, not PO-line count.
- Electronic-only resources are excluded because COUNTER or comparable electronic usage is unavailable.
- Require the Smith `SC` acquisitions unit and current eligible Smith physical items.
- Prefer exact receiving-piece or item PO-line linkage; allocate unmatched invoiced quantity through a disclosed instance-level fallback.
- Aggregate invoice fund distributions before inventory or circulation joins.
- Count distinct checkout loan IDs in the same five-year window as paid purchases.
- Assign each instance one dominant normalized class with alphabetical tie-breaking.
- Keep unlike currencies in separate result groups.
- Return numeric measures; zero-safe ROI divisions return `NULL`.
- The runtime setting is exactly `nl2sql_hardened_physical_roi`, defaults to `true`, and selects compiler version `physical_roi_v2`.
- Do not modify `backend/data/column_cache.json`, `backend/data/subtable_cache.json`, `backend/data/table_mapping_cache.json`, canonical query-family contracts, canonical graph artifacts, or database migrations.
- Do not weaken SELECT-only safety, reporting policy, semantic validation, PostgreSQL preflight, cancellation, or ordinary-user error privacy.

---

### Task 1: Formalize the physical ROI semantic contract

**Files:**
- Modify: `backend/services/ExploratorySemanticContractService.php`
- Modify: `backend/services/ExploratorySqlSemanticValidatorService.php`
- Modify: `backend/services/ExploratorySqlAnalysisService.php`
- Modify: `backend/tests/ExploratorySemanticContractServiceTest.php`
- Modify: `backend/tests/ExploratorySqlSemanticValidatorServiceTest.php`

**Interfaces:**
- Produces: `ExploratorySemanticContractService::build(string $question, ?string $campus, array $assumptions, string $routeReason, array $options = []): array`.
- Produces: ROI contracts with `reportPolicy.physicalOnly === true`, `reportPolicy.acquisitionUnitCode === 'SC'`, and optional `reportPolicy.materialType`.
- Produces: governed-filter provenance `reporting_policy` for physical resource and Smith acquisitions filters.
- Produces: blocking semantic rules `physical_item_eligibility` and `acquisition_unit_scope`; permitted filters alone never satisfy these requirements.
- Produces: required final measures `physical_copies_purchased`, `distinct_titles`, `spend`, `circulation`, and the selected ROI outputs; ranking uses `physical_copies_purchased` descending.
- Produces: semantic validation support for invoiced physical-copy measure aliases and `loan__loan_date` checkout windows.
- Preserves: every existing contract key, safe category, and supported assumption alternative.

- [ ] **Step 1: Add failing contract tests for policy-backed filters and explicit DVD**

Append cases that build the motivating contract and a DVD-specific rewording:

```php
$physical = ExploratorySemanticContractService::build(
    $question,
    'Smith College',
    ExploratoryQueryDefaultsService::resolve($question),
    'unsupported_query_family'
);
contractAssertSame(true, $physical['reportPolicy']['physicalOnly'] ?? null, 'ROI policy must require physical purchases.');
contractAssertSame('SC', $physical['reportPolicy']['acquisitionUnitCode'] ?? null, 'ROI policy must require Smith acquisitions.');
contractAssertSame('reporting_policy', $physical['permittedFilters']['physical_resource']['provenance'] ?? null, 'Physical eligibility is policy-backed.');
contractAssertSame('reporting_policy', $physical['permittedFilters']['acquisition_unit']['provenance'] ?? null, 'SC acquisitions are policy-backed.');

$dvdQuestion = 'For DVDs, show call numbers purchased most in five years with circulation and ROI.';
$dvd = ExploratorySemanticContractService::build(
    $dvdQuestion,
    'Smith College',
    ExploratoryQueryDefaultsService::resolve($dvdQuestion),
    'unsupported_query_family'
);
contractAssertSame('dvd', $dvd['reportPolicy']['materialType'] ?? null, 'Explicit DVD scope must be retained.');
contractAssertSame('explicit_prompt', $dvd['permittedFilters']['material_type']['provenance'] ?? null, 'DVD is an explicit material filter.');
```

Also assert the ordinary motivating prompt has no default `book` material type.

Assert the contract's required measures replace legacy PO-line `purchase_count` with `physical_copies_purchased` and `distinct_titles`, and that `purchase_ranking.parameters.measure` is `physical_copies_purchased`.
Assert v2 contracts contain blocking requirement keys `physical_item_eligibility` and `acquisition_unit_scope`; legacy rollback contracts do not.

- [ ] **Step 2: Run the contract test to verify RED**

Run: `php backend/tests/ExploratorySemanticContractServiceTest.php`

Expected: FAIL because `reportPolicy` and policy-backed filter provenance are absent.

- [ ] **Step 3: Implement deterministic report-policy extraction**

Add the optional `$options` argument without breaking existing callers. Resolve `$options['physicalRoiPolicyVersion'] ?? 'v2'`; `v2` builds the new contract below, while `legacy` returns the current ROI requirements and permitted-filter behavior unchanged for rollback. In the v2 branch, add:

```php
$materialType = self::explicitPhysicalMaterialType($question);
$reportPolicy = [
    'physicalOnly' => true,
    'acquisitionUnitCode' => 'SC',
    'materialType' => $materialType,
];
```

In the v2 branch, set required measures and ranking to:

```php
$requiredMeasures = [
    'physical_copies_purchased',
    'distinct_titles',
    'spend',
    'circulation',
    'checkouts_per_dollar',
    'cost_per_checkout',
];
$purchaseRankingMeasure = 'physical_copies_purchased';
```

When the selected ROI formula is cost-per-checkout only, omit `checkouts_per_dollar` exactly as the legacy branch already does.

Add v2-only rule mappings and requirements:

```php
'physical_item_eligibility' => 'physical_item_eligibility',
'acquisition_unit_scope' => 'acquisition_unit_scope',
```

The physical rule parameters require positive physical quantity and a current selected-campus item. The acquisition-unit rule parameter requires exact code `SC`.

Add policy filters in `permittedFilters()`:

```php
$filters['physical_resource'] = ['provenance' => 'reporting_policy'];
$filters['acquisition_unit'] = [
    'value' => 'SC',
    'provenance' => 'reporting_policy',
];
if ($materialType !== null) {
    $filters['material_type'] = [
        'value' => $materialType,
        'provenance' => 'explicit_prompt',
    ];
}
```

Implement `explicitPhysicalMaterialType()` with an allowlist whose first slice is `dvd`; return `null` for generic wording and electronic formats.

- [ ] **Step 4: Add failing semantic-validator fixtures for v2 aliases and policy filters**

Extend the validator test with a compact v2 fixture that contains:

```sql
SUM(paid_line.quantity) AS purchase_count,
SUM(paid_line.quantity) AS physical_copies_purchased,
COUNT(DISTINCT paid_line.instance_id) AS distinct_titles,
SUM(fd.total * (fd.fund_distributions__value * 0.01)) AS spend
```

and predicates for positive physical quantity, `SC` acquisition unit, Smith campus, optional `LOWER(material_type.name) = 'dvd'`, and `audit_loan.loan__loan_date >= CURRENT_DATE - INTERVAL '5 years'`.

Assert the policy-backed generic fixture and explicit-DVD fixture validate. Assert an unrequested `book` filter fails with `unrequested_filter`, a missing positive-physical-quantity predicate fails with `physical_cohort_mismatch`, and a missing Smith acquisition-unit predicate fails with `scope_mismatch`.

- [ ] **Step 5: Run the validator test to verify RED**

Run: `php backend/tests/ExploratorySqlSemanticValidatorServiceTest.php`

Expected: FAIL because the validator recognizes only the legacy measure aliases, only `explicit_prompt` acquisition filters, and only the legacy circulation timestamp shape.

- [ ] **Step 6: Extend analysis and validation without weakening legacy checks**

Add recognized numeric aliases:

```php
$recognized = [
    'purchase_count', 'physical_copies_purchased', 'distinct_titles',
    'spend', 'circulation', 'checkouts_per_dollar', 'cost_per_checkout',
    'exact_linked_copies', 'fallback_linked_copies', 'fallback_percentage',
];
```

Register `validatePhysicalItemEligibility()` and `validateAcquisitionUnitScope()` in `RULE_METHODS`. The first requires a positive `orders.po_line__t.cost__quantity_physical` predicate plus current selected-campus item lineage; the second requires the purchase-order acquisition-unit subtable joined to `orders.acquisitions_unit__t` with trimmed name/code `SC`. Map their safe categories to `physical_cohort_mismatch` and `scope_mismatch`.

Permit acquisition-unit and physical-resource filters only when their contract provenance is `reporting_policy`; continue requiring `explicit_prompt` for a material-type value. Accept `physical_copies_purchased` as the final descending ranking measure while preserving legacy `purchase_count` validation for alternate contracts. Update the circulation-window analyzer to recognize `loan__loan_date` and require it to use the same interval expression as the purchase window. Do not accept order date or an unbounded checkout window.

- [ ] **Step 7: Run focused semantic tests**

Run:

```bash
php backend/tests/ExploratorySemanticContractServiceTest.php
php backend/tests/ExploratorySqlAnalysisServiceTest.php
php backend/tests/ExploratorySqlSemanticValidatorServiceTest.php
```

Expected: all three scripts exit 0.

- [ ] **Step 8: Commit**

```bash
git add backend/services/ExploratorySemanticContractService.php \
  backend/services/ExploratorySqlSemanticValidatorService.php \
  backend/services/ExploratorySqlAnalysisService.php \
  backend/tests/ExploratorySemanticContractServiceTest.php \
  backend/tests/ExploratorySqlSemanticValidatorServiceTest.php
git commit -m "feat: define physical ROI semantic policy"
```

### Task 2: Compile the additive physical ROI SQL shape

**Files:**
- Create: `backend/services/HardenedPhysicalRoiSqlCompilerService.php`
- Create: `backend/tests/HardenedPhysicalRoiSqlCompilerServiceTest.php`

**Interfaces:**
- Consumes: `HardenedPhysicalRoiSqlCompilerService::compile(array $contract): ?array`.
- Produces: `{sql: string, explanation: string, dataSource: 'folio', compilerVersion: 'physical_roi_v2', reportDisclosures: array}`.
- Returns: `null` for non-applicable contracts or unsupported assumption variants.
- Preserves: legacy `ExploratoryRoiSqlCompilerService` unchanged as rollback implementation.

- [ ] **Step 1: Write the failing compiler test**

Create a standalone test that builds the motivating contract and asserts:

```php
$compiled = HardenedPhysicalRoiSqlCompilerService::compile($contract);
compilerAssertSame('physical_roi_v2', $compiled['compilerVersion'] ?? null, 'Compiler version must be disclosed.');
compilerAssertContains('orders.pieces__t', $compiled['sql'], 'Exact receiving linkage is required.');
compilerAssertContains('purchase_order_line_identifier', $compiled['sql'], 'Direct item PO-line linkage is required.');
compilerAssertContains("TRIM(acquisition_unit.name) = 'SC'", $compiled['sql'], 'Smith acquisitions are required.');
compilerAssertContains('cost__quantity_physical > 0', $compiled['sql'], 'Electronic-only lines must be excluded.');
compilerAssertNotContains("LOWER(material_type.name) = 'book'", $compiled['sql'], 'Generic ROI must not force books.');
compilerAssertContains('COUNT(DISTINCT audit_loan.loan__id)', $compiled['sql'], 'Distinct loans must be counted.');
compilerAssertContains('audit_loan.loan__loan_date', $compiled['sql'], 'Checkout date must use loan date.');
compilerAssertContains('ROW_NUMBER() OVER', $compiled['sql'], 'Dominant class must be deterministic.');
compilerAssertContains("ELSE 'Local/Other'", $compiled['sql'], 'Arbitrary text must not become a class.');
compilerAssertContains("'Unclassified'", $compiled['sql'], 'Blank call numbers need a stable class.');
compilerAssertContains('physical_copies_purchased', $compiled['sql'], 'Physical copy output is required.');
compilerAssertContains('distinct_titles', $compiled['sql'], 'Distinct title output is required.');
compilerAssertContains('fallback_percentage', $compiled['sql'], 'Linkage coverage is required.');
```

Reuse the cached-column audit helper and require semantic validation status `validated`. Build a DVD contract and assert the material-type predicate appears only for that contract. Change each supported default assumption in turn and assert compilation returns `null`.

- [ ] **Step 2: Run the compiler test to verify RED**

Run: `php backend/tests/HardenedPhysicalRoiSqlCompilerServiceTest.php`

Expected: FAIL because the hardened compiler class does not exist.

- [ ] **Step 3: Implement contract eligibility and safe literals**

Start `compile()` by extracting requirement values and requiring exactly:

```php
$expected = [
    'purchase_date_basis' => 'payment_date',
    'investment_cost_basis' => 'actual_paid_fund_distribution',
    'circulation_window' => 'same_as_purchase_window',
    'call_number_grouping' => 'primary_call_number_class',
    'roi_formula' => 'checkouts_per_dollar_with_cost_per_use',
];
```

Require `reportPolicy.physicalOnly`, acquisition unit `SC`, and a non-empty campus. Escape SQL literals through one private `quoteLiteralValue(string $value): string` helper. Accept material type only from the policy allowlist.

- [ ] **Step 4: Implement the acquisition and linkage CTEs**

Generate CTEs with these exact responsibilities:

```sql
paid_invoice_lines       -- one row per paid invoice line, quantity and currency
funded_invoice_lines     -- one row per invoice line after fund distributions sum
paid_po_lines            -- one row per PO line/currency, copies and spend
current_smith_items      -- current items through effective location hierarchy
piece_item_links         -- distinct eligible orders.pieces__t links
direct_item_links        -- distinct eligible item.purchase_order_line_identifier links
exact_item_links         -- UNION of exact link sources
linkage_by_po_line       -- exact=min(link count,copies), fallback=remainder
eligible_acquisitions    -- only PO lines with an exact or eligible instance fallback
```

`paid_invoice_lines` filters `invoice.status = 'Paid'` and `invoice.payment_date >= CURRENT_DATE - INTERVAL '5 years'`. `paid_po_lines` joins the purchase order acquisition-unit subtable and `orders.acquisitions_unit__t`, requires `TRIM(name) = 'SC'`, and requires `po_line.cost__quantity_physical > 0`.

Never join a fund-distribution row directly to an inventory item.

- [ ] **Step 5: Implement class, circulation, and final aggregation CTEs**

Use:

```sql
item_classes             -- LC, Dewey, Local/Other, Unclassified per current item
class_counts             -- eligible item count per instance/class
ranked_classes           -- ROW_NUMBER by count DESC, class ASC
dominant_class           -- one class per instance
circulation_by_item      -- COUNT(DISTINCT loan__id) in five-year window
circulation_by_instance  -- one row per instance
acquisitions_by_instance -- one row per instance/currency before circulation join
class_results            -- additive final class/currency measures
```

The final SELECT must keep measures numeric and use:

```sql
circulation / NULLIF(spend, 0) AS checkouts_per_dollar,
spend / NULLIF(circulation, 0) AS cost_per_checkout,
fallback_linked_copies / NULLIF(physical_copies_purchased, 0) AS fallback_percentage
```

Order by `physical_copies_purchased DESC, spend DESC, call_number_class ASC` before `LIMIT 100`.

- [ ] **Step 6: Add compiler disclosures**

Return:

```php
'compilerVersion' => 'physical_roi_v2',
'reportDisclosures' => [
    'Physical purchases and current Smith physical holdings only.',
    'Purchases and circulation use the same five-year period.',
    'Exact receiving links are preferred; fallback-linked copies and percentage are shown.',
    'Electronic-resource ROI is out of scope because usage statistics are unavailable.',
],
```

- [ ] **Step 7: Run focused compiler and legacy tests**

Run:

```bash
php backend/tests/HardenedPhysicalRoiSqlCompilerServiceTest.php
php backend/tests/ExploratoryRoiSqlCompilerServiceTest.php
```

Expected: both scripts exit 0, proving v2 works while the rollback compiler remains intact.

- [ ] **Step 8: Commit**

```bash
git add backend/services/HardenedPhysicalRoiSqlCompilerService.php \
  backend/tests/HardenedPhysicalRoiSqlCompilerServiceTest.php
git commit -m "feat: compile additive physical ROI reports"
```

### Task 3: Add the default-on rollback flag and compiler routing

**Files:**
- Modify: `backend/config/params.php`
- Modify: `backend/services/SettingsService.php`
- Modify: `backend/controllers/FolioQueryController.php`
- Modify: `backend/services/GeminiService.php`
- Create: `backend/tests/PhysicalRoiCompilerRoutingTest.php`
- Modify: `backend/tests/GeminiServiceExploratoryRepairTest.php`

**Interfaces:**
- Produces: Yii parameter `nl2sqlHardenedPhysicalRoi: bool`.
- Consumes: setting `nl2sql_hardened_physical_roi` or environment variable `NL2SQL_HARDENED_PHYSICAL_ROI`.
- Produces: v2 compiler result when enabled and legacy compiler result when disabled.
- Produces: a v2 semantic contract when enabled and the existing legacy semantic contract when disabled, so rollback SQL still passes its original validation contract.
- Preserves: initial generation plus at most two repairs before deterministic fallback.

- [ ] **Step 1: Write the failing routing test**

Create a focused test with a minimal Yii app params object. Exhaust all three model candidates for the motivating prompt, then assert:

```php
Yii::$app->params['nl2sqlHardenedPhysicalRoi'] = true;
$v2 = GeminiService::generateSqlWithShadow($prompt, 'Smith College', null, true);
routingAssertSame('physical_roi_v2', $v2['compilerVersion'] ?? null, 'Default route must use v2.');
routingAssertContains('orders.pieces__t', $v2['sql'] ?? '', 'V2 route must include exact linkage.');

Yii::$app->params['nl2sqlHardenedPhysicalRoi'] = false;
$legacy = GeminiService::generateSqlWithShadow($prompt, 'Smith College', null, true);
routingAssertSame(false, isset($legacy['compilerVersion']), 'Rollback route must retain the legacy result.');
routingAssertContains('WITH spend_by_instance AS', $legacy['sql'] ?? '', 'Rollback must use the existing compiler.');
```

Assert each route still consumes only the initial candidate plus two repairs.
Also assert the disabled route's semantic checklist contains the legacy measure contract rather than v2 physical-copy/title requirements.

- [ ] **Step 2: Run the routing test to verify RED**

Run: `php backend/tests/PhysicalRoiCompilerRoutingTest.php`

Expected: FAIL because the setting and v2 routing do not exist.

- [ ] **Step 3: Add backend setting resolution**

In `backend/config/params.php` add:

```php
'nl2sqlHardenedPhysicalRoi' => filter_var(
    SettingsService::get(
        'nl2sql_hardened_physical_roi',
        'NL2SQL_HARDENED_PHYSICAL_ROI',
        'true'
    ),
    FILTER_VALIDATE_BOOLEAN
),
```

Add the same boolean to `SettingsService::forDisplay()` and add `nl2sql_hardened_physical_roi` to the controller's settings-save allowlist. Do not modify `settings.json` in source control.

- [ ] **Step 4: Route semantic-contract construction and deterministic fallback through the flag**

Require the new compiler in `GeminiService.php`. Resolve the flag before building the semantic contract and pass the matching policy version:

```php
$useHardenedPhysicalRoi = !isset(Yii::$app->params['nl2sqlHardenedPhysicalRoi'])
    || (bool)Yii::$app->params['nl2sqlHardenedPhysicalRoi'];
$semanticContract = ExploratorySemanticContractService::build(
    $prompt,
    is_string($campus) ? $campus : null,
    $assumptions,
    $reason,
    ['physicalRoiPolicyVersion' => $useHardenedPhysicalRoi ? 'v2' : 'legacy']
);
```

Replace the single fallback call with:

```php
$useHardenedPhysicalRoi = !isset(Yii::$app->params['nl2sqlHardenedPhysicalRoi'])
    || (bool)Yii::$app->params['nl2sqlHardenedPhysicalRoi'];
$compiledFallback = $useHardenedPhysicalRoi
    ? HardenedPhysicalRoiSqlCompilerService::compile($semanticContract)
    : ExploratoryRoiSqlCompilerService::compile($semanticContract);
```

Keep `validateCompiledExploratoryFallback()` unchanged as the mandatory validation boundary. Preserve `compilerVersion` and `reportDisclosures` when decorating the validated result.

- [ ] **Step 5: Update the existing exploratory fallback regression**

Set `nl2sqlHardenedPhysicalRoi` to true in the test Yii params and replace the legacy shape assertion with:

```php
repairAssertSame('physical_roi_v2', $compiledFallback['compilerVersion'] ?? null, 'Semantic exhaustion should use the hardened compiler.');
repairAssertContains('physical_copies_purchased', $compiledFallback['sql'] ?? '', 'The hardened fallback should return physical-copy measures.');
repairAssertSame(3, count(TestTransport::$requests), 'The fallback must run only after the initial candidate and two repairs.');
```

- [ ] **Step 6: Run routing and integration tests**

Run:

```bash
php backend/tests/PhysicalRoiCompilerRoutingTest.php
php backend/tests/GeminiServiceExploratoryRepairTest.php
php backend/tests/HardenedPhysicalRoiSqlCompilerServiceTest.php
```

Expected: all scripts exit 0.

- [ ] **Step 7: Commit**

```bash
git add backend/config/params.php backend/services/SettingsService.php \
  backend/controllers/FolioQueryController.php backend/services/GeminiService.php \
  backend/tests/PhysicalRoiCompilerRoutingTest.php \
  backend/tests/GeminiServiceExploratoryRepairTest.php
git commit -m "feat: route ROI reports through hardened compiler"
```

### Task 4: Prove prompt equivalence, disclosure, and artifact isolation

**Files:**
- Modify: `backend/services/ExploratoryQueryDefaultsService.php`
- Modify: `backend/services/ExploratorySemanticContractService.php`
- Create: `backend/tests/PhysicalRoiPresentationRegressionTest.php`
- Modify: `backend/tests/ExploratoryQueryDefaultsServiceTest.php`
- Modify: `planning/2026-07-19-query-reliability-backlog.md`

**Interfaces:**
- Consumes: the v2 semantic contract, compiler, and routing behavior.
- Produces: presentation prompt evidence and explicit rollback instructions.
- Preserves: unrelated Ask AI routes and all excluded schema/canonical artifacts.

- [ ] **Step 1: Write the presentation regression test**

Compile these equivalent prompts:

```php
$prompts = [
    'Show me which call numbers we have purchased the most from the last 5 years. Compare circulation data to those call numbers and show the return on investment.',
    'For the past five years, compare Smith physical purchases and checkouts by primary call-number class and include ROI.',
    'Rank primary call-number classes by physical copies bought in five years, with paid spending, circulation, checkouts per dollar, and cost per checkout.',
];
```

Assert every prompt yields the same compiler version, policy, output aliases, window, Smith acquisitions scope, ordering, and physical-only cohort. Add a non-ROI circulation prompt and assert the v2 compiler returns `null`. Add a DVD prompt and assert only it contains the explicit DVD predicate.

Assert `reportDisclosures` contains four plain-language entries and none contains `CTE`, `join grain`, `schema cache`, raw SQL, or database error terminology.

- [ ] **Step 2: Run the presentation test to verify RED**

Run: `php backend/tests/PhysicalRoiPresentationRegressionTest.php`

Expected: FAIL because `bought` does not yet activate the documented ROI defaults or semantic contract.

- [ ] **Step 3: Normalize the approved purchase-language equivalents**

In both `ExploratoryQueryDefaultsService::isCrossDomainRoiPrompt()` and `ExploratorySemanticContractService::isCrossDomainCallNumberRoi()`, replace the purchase concept expression with:

```php
preg_match('/\b(?:purchas[a-z]*|acquisitions?|bought|buying|buys?)\b/', $normalized) === 1
```

Do not add prompt-specific SQL branches. All equivalent wording must build the same contract policy and compile from that policy. Add matching assertions to `ExploratoryQueryDefaultsServiceTest.php`.

- [ ] **Step 4: Run the focused ROI matrix**

Run:

```bash
php backend/tests/ExploratoryQueryDefaultsServiceTest.php
php backend/tests/ExploratorySemanticContractServiceTest.php
php backend/tests/ExploratorySqlAnalysisServiceTest.php
php backend/tests/ExploratorySqlSemanticValidatorServiceTest.php
php backend/tests/ExploratoryRoiSqlCompilerServiceTest.php
php backend/tests/HardenedPhysicalRoiSqlCompilerServiceTest.php
php backend/tests/PhysicalRoiCompilerRoutingTest.php
php backend/tests/PhysicalRoiPresentationRegressionTest.php
php backend/tests/GeminiServiceExploratoryRepairTest.php
```

Expected: every script exits 0.

- [ ] **Step 5: Audit excluded artifacts**

Run:

```bash
git diff --name-only main...HEAD | rg 'backend/data/(column_cache|subtable_cache|table_mapping_cache|query_family_contracts|canonical_query_graph)'
```

Expected: no output and exit 1 from `rg`.

- [ ] **Step 6: Record verification and rollback evidence**

Update NL2SQL-236 in the backlog with:

- compiler version `physical_roi_v2`;
- focused test names and counts;
- `NL2SQL_HARDENED_PHYSICAL_ROI=false` rollback instruction;
- production smoke prompt and required saved evidence;
- a clear statement that live PostgreSQL preflight remains pending when credentials are unavailable.

- [ ] **Step 7: Commit**

```bash
git add backend/tests/PhysicalRoiPresentationRegressionTest.php \
  backend/services/ExploratoryQueryDefaultsService.php \
  backend/services/ExploratorySemanticContractService.php \
  backend/tests/ExploratoryQueryDefaultsServiceTest.php \
  planning/2026-07-19-query-reliability-backlog.md
git commit -m "test: lock physical ROI presentation semantics"
```

### Task 5: Full verification and release handoff

**Files:**
- Modify only if verification evidence changes: `planning/2026-07-19-query-reliability-backlog.md`

**Interfaces:**
- Produces: merge-ready evidence for NL2SQL-236.
- Does not produce: source changes outside the approved files or generated artifacts.

- [ ] **Step 1: Run every backend test script twice**

Run:

```bash
for run in 1 2; do
  passed=0
  for test_file in backend/tests/*Test.php; do
    php "$test_file" || exit 1
    passed=$((passed + 1))
  done
  printf 'BACKEND_PASS=%s PASSED_SCRIPTS=%s\n' "$run" "$passed"
done
```

Expected: both passes exit 0 with identical script counts. Record credential-dependent PostgreSQL skips separately from failures.

- [ ] **Step 2: Run frontend regression and production build**

Run from `frontend/`:

```bash
npm test
npm run build
```

Expected: all tests pass and `tsc -b && vite build` exits 0. Record the existing chunk-size advisory separately from failures.

- [ ] **Step 3: Run final diff and artifact audits**

Run:

```bash
git diff --check main...HEAD
git status --short
git diff --stat main...HEAD
git diff --cached --name-only
git diff --name-only main...HEAD | rg 'backend/data/(column_cache|subtable_cache|table_mapping_cache|query_family_contracts|canonical_query_graph)'
```

Expected: whitespace check passes, index is empty, only the isolated dependency symlink remains untracked, and excluded artifact search has no matches.

- [ ] **Step 4: Perform production-like preflight when available**

Run the saved motivating prompt through the normal Ask AI endpoint with hardened mode enabled. Preserve generated SQL, result rows, semantic checklist, compiler version, and linkage coverage. Then set `NL2SQL_HARDENED_PHYSICAL_ROI=false`, restart the application configuration, and verify the same exhausted request selects the legacy compiler; restore the flag to true afterward.

If FOLIO credentials are unavailable, record this exact step as pending rather than claiming it passed.

- [ ] **Step 5: Update verification evidence only when necessary**

If final counts differ from Task 4 evidence, edit only the NL2SQL-236 verification block and commit:

```bash
git add planning/2026-07-19-query-reliability-backlog.md
git commit -m "docs: record physical ROI verification"
```

If evidence is already accurate, do not create an empty documentation commit.
