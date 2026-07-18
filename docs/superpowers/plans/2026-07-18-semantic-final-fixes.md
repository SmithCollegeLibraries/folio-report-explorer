# Semantic Final Fixes Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Close the final purchase-count, governed-filter, campus-domain, PHP 7.2, and safe-label findings without weakening existing exploratory SQL gates.

**Architecture:** Extend the existing token analyzer only where exact structured evidence is missing, then keep semantic decisions in the validator. Purchase proof follows final output/ranking to an exact PO-line distinct count; governed filters use final reachability without row-enforcement suppression; campus proof uses exact source-bound inventory hierarchy edges in both circulation and final acquisition/grouping influence paths. Contract labels come from fixed allowlists keyed by resolved assumption values.

**Tech Stack:** PHP 7.2-compatible service code and standalone PHP tests; React/Vitest only for affected label fixtures; Git verification and deterministic syntax scans.

## Global Constraints

- Do not edit schema/cache/canonical/Builder artifacts or package manifests.
- Preserve privacy-safe violations, repair budget, canonical bypass, controller gate, and all prior Task 4 invariants.
- No raw prompt or SQL values may enter requirement labels.
- All changed PHP must parse on PHP 7.2; arrow functions are forbidden.

---

### Task 1: Exact purchase-count lineage

**Files:**
- Modify: `backend/services/ExploratorySqlAnalysisService.php`
- Modify: `backend/services/ExploratorySqlSemanticValidatorService.php`
- Test: `backend/tests/ExploratorySqlAnalysisServiceTest.php`
- Test: `backend/tests/ExploratorySqlSemanticValidatorServiceTest.php`

**Interfaces:**
- Produces: select-item `exactAggregate` evidence with `function`, `distinct`, and exact qualified `column`.
- Produces: validator proof that final `purchase_count`, spend-CTE `purchase_count`, and first descending order all resolve to the same exact PO-line measure.

- [ ] Add failing mutations for `SUM`, wrong COUNT column/source, non-DISTINCT COUNT, unused correct CTE, decoy trusted alias, arbitrary correct PO-line alias, and ranking by an unrelated alias.
- [ ] Run `php backend/tests/ExploratorySqlSemanticValidatorServiceTest.php` and record the first false acceptance.
- [ ] Extend exact aggregate analysis to recognize only exact `COUNT(DISTINCT qualifier.column)` in addition to existing exact aggregates.
- [ ] Require the final purchase measure source to select `COUNT(DISTINCT <orders.po_line__t alias>.id)` and require first descending ranking to resolve to the final `purchase_count` output alias.
- [ ] Run analyzer and semantic-validator tests to green.

### Task 2: Influential governed filters

**Files:**
- Modify: `backend/services/ExploratorySqlSemanticValidatorService.php`
- Test: `backend/tests/ExploratorySqlSemanticValidatorServiceTest.php`

**Interfaces:**
- Consumes: shared final-reachability graph and structured literal predicates.
- Produces: conservative governed-filter rejection in every final-reachable contributing scope, independent of outer-join row enforcement.

- [ ] Add failing checkout LEFT JOIN `item.material_type_id = 'book'` and nullable acquisition-unit mutations, plus unused CTE and explicit-permission controls.
- [ ] Run the semantic-validator test and record the false acceptance.
- [ ] Separate reachable contributing scopes from enforcing campus scopes; scan all reachable literal predicates for governed columns.
- [ ] Run the semantic-validator test to green.

### Task 3: Campus hierarchy and two-domain influence

**Files:**
- Modify: `backend/services/ExploratorySqlSemanticValidatorService.php`
- Test: `backend/tests/ExploratorySqlSemanticValidatorServiceTest.php`

**Interfaces:**
- Produces: exact hierarchy proof for `item.effective_location_id = location.id`, `location.library_id = loclibrary.id`, and `loclibrary.campus_id = loccampus.id`, with all qualifiers resolved to approved inventory tables.
- Produces: campus proof in the counted circulation-item scope and in an enforcing final acquisition/grouping influence scope.

- [ ] Replace the invalid direct campus fixture with real hierarchy joins in both circulation and final acquisition/grouping paths.
- [ ] Add failing wrong-key, disconnected, unused, circulation-only, and acquisition/grouping-only mutations; retain LEFT/INNER/WHERE and quoted-alias controls.
- [ ] Run the semantic-validator test and record failures.
- [ ] Add exact source-bound hierarchy-edge helpers and require both domain proofs with selected campus value and enforcement semantics.
- [ ] Run semantic-validator, analyzer, and ROI regression tests to green.

### Task 4: PHP 7.2 compatibility and safe labels

**Files:**
- Modify: `backend/tests/ExploratorySqlSemanticValidatorServiceTest.php`
- Modify: `backend/services/ExploratorySemanticContractService.php`
- Modify: `backend/tests/ExploratorySemanticContractServiceTest.php`
- Modify only if fixtures fail: relevant `frontend/src/components/*Semantic*.test.tsx` or recovery tests.

**Interfaces:**
- Produces: allowlisted `requirementLabel(string $key, array $values, ?string $campus): string` behavior with no prompt-derived text.
- Produces: deterministic branch-changed PHP scan with no `fn (...) =>` syntax.

- [ ] Add exact label assertions for default and invoice-date assumptions and verify they fail.
- [ ] Replace branch-introduced arrow functions with PHP 7.2 anonymous closures.
- [ ] Implement fixed label maps for payment/invoice date, paid fund distributions, five-year circulation, item-grain checkouts, primary call-number class, ROI outputs, ranking, campus presence, governed filters, and numeric outputs.
- [ ] Run contract and affected frontend tests to green.
- [ ] Scan branch-changed PHP with `rg --pcre2 '(?<!function\\s)\\bfn\\s*\\('` and require no output; run `php -l` on every changed PHP file.

### Task 5: Full regression, isolation, report, and commit

**Files:**
- Create: `.superpowers/sdd/semantic-final-fix-report.md`

**Interfaces:**
- Consumes: Tasks 1-4.
- Produces: auditable RED/GREEN evidence, self-review, artifact-isolation result, and one scoped commit.

- [ ] Run semantic validator/analyzer/contract/defaults/structure/policy tests.
- [ ] Run ROI regression, Gemini exploratory repair/gate, repair service, controller exploratory/policy tests, and affected frontend semantic/recovery tests.
- [ ] Run PHP lint, PHP 7.2 syntax scan, `git diff --check`, and verify no excluded artifacts changed.
- [ ] Write `.superpowers/sdd/semantic-final-fix-report.md` with commands/output, files, self-review, and concerns.
- [ ] Commit only scoped implementation/tests with message `fix: close final semantic proof gaps` and rerun covering verification post-commit.
