# Organization Acquisition-Unit Scope Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make exploratory organization/interface reports use the organization-owned acquisition-unit and interface bridges, while preserving valid purchase-order vendor reports.

**Architecture:** Add a narrowly classified `organization_acquisition_unit_scope` semantic contract built from the raw user question. Extend the existing analyzer-backed semantic validator with relationship and exact-code checks, then inject one shared relationship guidance block into both initial and repair prompts.

**Tech Stack:** PHP 7.2, Yii 2 service classes, existing conservative SQL tokenizer/analyzer, executable PHP regression scripts.

## Global Constraints

- PHP 7.2 compatibility remains required.
- Classify applicability from the raw user question, never resolver-augmented generation text.
- Account-owned acquisition-unit requests remain outside this contract.
- Preserve the shared maximum of two exploratory repairs.
- Do not edit generated cache files.
- Do not introduce a deterministic compiler for arbitrary organization reports.

---

### Task 1: Classify the organization-owned acquisition-unit concept

**Files:**
- Modify: `backend/services/ExploratorySemanticContractService.php`
- Modify: `backend/tests/ExploratorySemanticContractServiceTest.php`

**Interfaces:**
- Consumes: `ExploratorySemanticContractService::build(string $question, ?string $campus, array $assumptions, string $routeReason, array $options = []): array`
- Produces: concept `organization_acquisition_unit_scope` with requirements `organization_interface_relationship`, `organization_acquisition_unit_relationship`, and `organization_acquisition_unit_code`

- [ ] **Step 1: Write failing classification and coverage tests**

Add assertions covering:

```php
$organizationContract = ExploratorySemanticContractService::build(
    'List all statistics notes in organization interfaces limited to the AC acquisition unit',
    null,
    [],
    'unsupported_query_family'
);
contractAssertSame(2, $organizationContract['contractVersion'], 'The contract version must advance.');
contractAssertSame(true, $organizationContract['applicable'], 'Organization-owned AC scope must apply.');
contractAssertSame('organization_acquisition_unit_scope', $organizationContract['concept'], 'The organization concept must be selected.');
contractAssertSame(
    ['organization_interface_relationship', 'organization_acquisition_unit_relationship', 'organization_acquisition_unit_code'],
    array_column($organizationContract['requirements'], 'key'),
    'The organization contract must expose all enforceable relationships.'
);
contractAssertSame('AC', $organizationContract['requirements'][2]['parameters']['code'], 'The requested code must be retained.');

contractAssertSame(
    false,
    ExploratorySemanticContractService::build(
        'List all statistics notes in organization interfaces',
        null,
        [],
        'unsupported_query_family'
    )['applicable'],
    'Interface reports without acquisition-unit scope must remain exploratory without this contract.'
);
contractAssertSame(
    false,
    ExploratorySemanticContractService::build(
        'List organization accounts assigned to acquisition unit AC',
        null,
        [],
        'unsupported_query_family'
    )['applicable'],
    'Account-owned acquisition scope must not be forced through the organization bridge.'
);
contractAssertSame(
    false,
    ExploratorySemanticContractService::build(
        'Which vendors did we place AC purchase orders with?',
        null,
        [],
        'unsupported_query_family'
    )['applicable'],
    'Order-domain vendor reports must remain outside the organization-owned contract.'
);
```

- [ ] **Step 2: Run the contract test and verify failure**

Run:

```bash
php backend/tests/ExploratorySemanticContractServiceTest.php
```

Expected: FAIL because the current service returns contract version `1` and no organization concept.

- [ ] **Step 3: Implement the narrow classifier and contract builder**

In `ExploratorySemanticContractService.php`:

- Increase `CONTRACT_VERSION` to `2`.
- Keep ROI detection first.
- Add private helpers that normalize the question, exclude account and transactional fact-domain wording, recognize organization/interface/contact output wording, recognize `acquisition unit` wording, and extract an adjacent two-letter code.
- Return a complete audited organization contract with the three requirements above.
- Do not allow a bare two-letter token such as `AC` to trigger without acquisition-unit context.

Use stable requirement parameters:

```php
[
    self::requirement(
        'organization_interface_relationship',
        'Organization interfaces use the organization interfaces bridge.'
    ),
    self::requirement(
        'organization_acquisition_unit_relationship',
        'Organization acquisition-unit scope uses the organization acquisition-unit bridge.'
    ),
    self::requirement(
        'organization_acquisition_unit_code',
        'The requested acquisition-unit code is matched exactly.',
        ['code' => $code]
    ),
]
```

- [ ] **Step 4: Run the contract test and verify the intended remaining failure**

Run:

```bash
php backend/tests/ExploratorySemanticContractServiceTest.php
```

Expected: FAIL only because the new requirement rules are not registered by the validator coverage audit.

- [ ] **Step 5: Commit the classification slice**

```bash
git add backend/services/ExploratorySemanticContractService.php backend/tests/ExploratorySemanticContractServiceTest.php
git commit -m "feat: classify organization acquisition unit reports"
```

### Task 2: Enforce organization bridge relationships and exact codes

**Files:**
- Modify: `backend/services/ExploratorySqlSemanticValidatorService.php`
- Create: `backend/tests/OrganizationAcquisitionUnitScopeTest.php`

**Interfaces:**
- Consumes: the three requirement rules from Task 1 and `ExploratorySqlAnalysisService::analyze(string $sql): array`
- Produces: deterministic `validated` or repairable `rejected` results for organization acquisition-unit candidates

- [ ] **Step 1: Write the focused validator fixture**

Create a standalone executable test with local assertion helpers. Build the contract from:

```php
$question = 'List all statistics notes in organization interfaces limited to the AC acquisition unit';
$contract = ExploratorySemanticContractService::build(
    $question,
    null,
    [],
    'unsupported_query_family'
);
```

Use this accepted base SQL:

```sql
SELECT intf.statistics_notes
FROM organizations.organizations__t AS org
JOIN organizations.organizations__t__interfaces AS oi
  ON oi.id = org.id
JOIN organizations.interfaces__t AS intf
  ON intf.id = oi.interfaces
JOIN organizations.organizations__t__acq_unit_ids AS ou
  ON ou.id = org.id
JOIN orders.acquisitions_unit__t AS au
  ON au.id = ou.acq_unit_ids
WHERE au.name = 'AC'
  AND intf.statistics_notes IS NOT NULL
LIMIT 100
```

Assert acceptance for:

- the base SQL;
- a bridge-only variant joining `oi.id = ou.id`;
- `TRIM(au.name) = 'AC'`;

Assert rejection, one mutation at a time, for:

- direct `intf.id = org.id`;
- omitted `organizations__t__interfaces`;
- omitted `organizations__t__acq_unit_ids`;
- wrong `au.id = ou.id`;
- purchase-order acquisition bridge substituted for the organization bridge;
- account acquisition bridge substituted for the organization bridge;
- missing code predicate;
- wrong code;
- lowercase `au.name = 'ac'`;
- an exact `au.name = 'AC'` predicate present only in the nullable
  `LEFT JOIN orders.acquisitions_unit__t` condition;
- `ILIKE`, wildcard, or nested `UPPER(TRIM(...))` ambiguity.
- correct relationship fragments placed in disconnected contributing CTEs.
- a fully scoped decoy CTE joined to unscoped selected interface output.

- [ ] **Step 2: Run the focused test and verify failure**

Run:

```bash
php backend/tests/OrganizationAcquisitionUnitScopeTest.php
```

Expected: FAIL because the organization rules are not registered or implemented.

- [ ] **Step 3: Register the three rules and guidance**

Add to `RULE_METHODS`:

```php
'organization_interface_relationship' => 'validateOrganizationInterfaceRelationship',
'organization_acquisition_unit_relationship' => 'validateOrganizationAcquisitionUnitRelationship',
'organization_acquisition_unit_code' => 'validateOrganizationAcquisitionUnitCode',
```

Add corresponding safe `GUIDANCE` entries that name only allowlisted schema relationships and never echo SQL or the user prompt.

- [ ] **Step 4: Implement alias-aware relationship validation**

Use `sourceAliases`, `predicates.columnComparisons`, and table source names to prove:

- `organizations.interfaces__t.id = organizations.organizations__t__interfaces.interfaces`;
- the organization interface bridge and organization acquisition bridge share the same organization ID, either through the parent table or directly;
- `organizations.organizations__t__acq_unit_ids.acq_unit_ids = orders.acquisitions_unit__t.id`;
- neither purchase-order nor account acquisition bridges substitute for the organization bridge.
- interface, organization acquisition-unit, and exact-code evidence occur in
  the same connected contributing scope rather than unrelated reachable CTEs.

Because the current analyzer cannot prove selected-output lineage through CTE
joins for this domain, reject organization acquisition-unit contracts containing
CTEs as repairable coverage gaps and require a single SELECT scope.

Keep the parent `organizations.organizations__t` optional when both authoritative bridges share IDs directly.

- [ ] **Step 5: Implement exact-code validation**

Inspect `literalPredicates` for the alias bound to `orders.acquisitions_unit__t`. Accept only `=` predicates on leaf column `name` whose single literal exactly matches the canonical contract code and whose provenance is enforcing according to the existing `isEnforcingFact` helper (`WHERE` or `INNER JOIN`, never only a nullable `LEFT JOIN`). The analyzer already normalizes a single `TRIM(column)` wrapper to the same column reference. Lowercase literals, wrong codes, non-enforcing predicates, and analyzer-ambiguous candidates remain rejected by the existing fail-closed path.

- [ ] **Step 6: Run the focused and existing semantic tests**

Run:

```bash
php backend/tests/OrganizationAcquisitionUnitScopeTest.php
php backend/tests/ExploratorySqlSemanticValidatorServiceTest.php
php backend/tests/ExploratorySemanticContractServiceTest.php
```

Expected: all three scripts print their pass messages and exit `0`.

- [ ] **Step 7: Commit semantic enforcement**

```bash
git add backend/services/ExploratorySqlSemanticValidatorService.php backend/tests/OrganizationAcquisitionUnitScopeTest.php backend/tests/ExploratorySemanticContractServiceTest.php
git commit -m "feat: validate organization acquisition unit joins"
```

### Task 3: Share relationship guidance across generation and repair

**Files:**
- Modify: `backend/services/GeminiService.php`
- Modify: `backend/tests/GeminiServiceLegacyPromptGuidanceTest.php`
- Modify: `backend/tests/GeminiServiceExploratoryRepairTest.php`

**Interfaces:**
- Consumes: semantic contract concept `organization_acquisition_unit_scope`
- Produces: `GeminiService::buildOrganizationAcquisitionUnitGuidance(): string`, included in initial and repair prompt payloads

- [ ] **Step 1: Write failing initial-prompt guidance tests**

Reflect the new private guidance builder and assert it contains:

```text
organizations.organizations__t__interfaces
organizations.organizations__t__acq_unit_ids
orders.acquisitions_unit__t
purchase_order__t__acq_unit_ids.id is the purchase order ID
acquisition-unit codes use exact equality
```

Also assert the selected-campus text exempts organization/interface reference listings from an artificial purchase-order campus path.

- [ ] **Step 2: Write failing repair-payload tests**

In `GeminiServiceExploratoryRepairTest.php`, enqueue a corrected organization candidate through the existing test transport and assert the captured repair request contains the same five guidance anchors. Add a regression where:

- raw question is the applicable interface/AC request;
- generation prompt contains resolver guidance mentioning purchase orders;
- the resulting semantic contract remains `organization_acquisition_unit_scope`.

- [ ] **Step 3: Run both tests and verify failure**

Run:

```bash
php backend/tests/GeminiServiceLegacyPromptGuidanceTest.php
php backend/tests/GeminiServiceExploratoryRepairTest.php
```

Expected: FAIL because no shared organization guidance exists and the contract is still built from `$generationPrompt` in repair paths.

- [ ] **Step 4: Add one shared guidance builder**

Implement a PHP 7.2-compatible private static method returning the authoritative bridges, the purchase-order carve-out, the account-bridge warning, and exact-code rule. Inject it:

- unconditionally beside Rules 1–21 in the initial exploratory SQL system prompt;
- into every exploratory repair system prompt or user payload;
- into selected-campus Rule 17 as an explicit organization/interface carve-out.

- [ ] **Step 5: Wire raw-question classification**

At every `ExploratorySemanticContractService::build` call, pass the raw user question for classification. Preserve `$generationPrompt` for schema selection and model context only. If a helper needs both values, name them explicitly and add a regression assertion so augmented transactional wording cannot disable the contract.

- [ ] **Step 6: Run prompt and repair tests**

Run:

```bash
php backend/tests/GeminiServiceLegacyPromptGuidanceTest.php
php backend/tests/GeminiServiceExploratoryRepairTest.php
```

Expected: both scripts pass and repair requests contain the same relationship guidance as initial generation.

- [ ] **Step 7: Commit prompt integration**

```bash
git add backend/services/GeminiService.php backend/tests/GeminiServiceLegacyPromptGuidanceTest.php backend/tests/GeminiServiceExploratoryRepairTest.php
git commit -m "fix: guide organization acquisition unit SQL repair"
```

### Task 4: Prove family-neutral exhaustion and run the full gate

**Files:**
- Modify: `backend/tests/GeminiServiceExploratoryRepairTest.php`
- Modify: `docs/superpowers/specs/2026-07-28-organization-acquisition-unit-scope-design.md`

**Interfaces:**
- Consumes: existing `ExploratorySqlRepairService::MAX_REPAIR_ATTEMPTS`
- Produces: regression evidence that rejected organization SQL never reaches the ROI compiler or leaks to users

- [ ] **Step 1: Add an exhaustion regression**

Queue two invalid organization candidates for the applicable request. Assert:

```php
repairAssertSame(2, $result['repairAttempts'] ?? null, 'Organization semantic repair must use the shared two-attempt budget.');
repairAssertSame(false, isset($result['sql']), 'Rejected organization SQL must not be exposed.');
repairAssertContains(
    'I could not build a report I could safely run',
    $result['validationSummary']['message'] ?? '',
    'Organization exhaustion must use family-neutral safe recovery.'
);
```

Before this assertion, change the production fallback guard so
`HardenedPhysicalRoiSqlCompilerService::compile` or
`ExploratoryRoiSqlCompilerService::compile` is called only when
`$semanticContract['concept'] === 'cross_domain_call_number_roi'`. The
organization concept must go directly to family-neutral safe recovery.

- [ ] **Step 2: Run the focused gate**

Run:

```bash
php backend/tests/ExploratorySemanticContractServiceTest.php
php backend/tests/OrganizationAcquisitionUnitScopeTest.php
php backend/tests/ExploratorySqlSemanticValidatorServiceTest.php
php backend/tests/GeminiServiceLegacyPromptGuidanceTest.php
php backend/tests/GeminiServiceExploratoryRepairTest.php
```

Expected: every script exits `0`.

- [ ] **Step 3: Run syntax checks**

Run:

```bash
php -l backend/services/ExploratorySemanticContractService.php
php -l backend/services/ExploratorySqlSemanticValidatorService.php
php -l backend/services/GeminiService.php
php -l backend/tests/OrganizationAcquisitionUnitScopeTest.php
```

Expected: `No syntax errors detected` for every file.

- [ ] **Step 4: Run the complete backend suite**

Run:

```bash
for test_file in backend/tests/*Test.php; do php "$test_file" || exit 1; done
```

Expected: every backend test script exits `0`.

- [ ] **Step 5: Update design status and commit**

Change the design status to `Implemented and verified`, record the focused/full test evidence, then run:

```bash
git add docs/superpowers/specs/2026-07-28-organization-acquisition-unit-scope-design.md backend/tests/GeminiServiceExploratoryRepairTest.php
git commit -m "test: cover organization scope recovery"
```

- [ ] **Step 6: Review only scoped changes**

Run:

```bash
git status --short
git diff main...HEAD -- backend/services/ExploratorySemanticContractService.php backend/services/ExploratorySqlSemanticValidatorService.php backend/services/GeminiService.php backend/tests/ExploratorySemanticContractServiceTest.php backend/tests/OrganizationAcquisitionUnitScopeTest.php backend/tests/GeminiServiceLegacyPromptGuidanceTest.php backend/tests/GeminiServiceExploratoryRepairTest.php docs/superpowers/specs/2026-07-28-organization-acquisition-unit-scope-design.md
```

Expected: only organization acquisition-unit implementation changes appear; generated cache and unrelated user files remain unstaged.
