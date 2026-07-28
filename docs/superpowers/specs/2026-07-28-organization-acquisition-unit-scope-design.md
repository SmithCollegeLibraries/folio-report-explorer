# Organization Acquisition-Unit Scope Design

**Date:** 2026-07-28
**Status:** Implemented and verified

## Problem

Ask AI can identify `organizations.interfaces__t` for a request about interface
statistics notes, but it may apply the finance/acquisitions campus rule when the
request also names an acquisition unit. That rule routes scope through purchase
orders. For an organization-owned acquisition-unit constraint, this is the wrong
domain path. The model may also invent a direct identity join between an
organization and an interface.

The reported request combines two organization-owned relationships:

- Interface statistics notes come from `organizations.interfaces__t` through the
  organization's `interfaces` bridge.
- Organization acquisition units come from the organization's own
  `acq_unit_ids` bridge.

The solution must correct that composition without rejecting valid order-domain
questions such as “Which vendors did we place AC orders with?”

## Chosen Approach

Add a narrowly applicable organization acquisition-unit semantic contract to
exploratory generation, repair, and validation.

This contract is **intent-triggered**, not globally structure-triggered. The raw
question determines whether organization-owned acquisition-unit semantics are
required; SQL structure determines whether a candidate satisfies that
requirement. This is deliberately different from matching the reported sentence
verbatim.

The contract applies when all of the following are true:

1. The requested entity or output is organization-owned, including organization
   records, organization interfaces, interface fields, or
   organization-level vendor metadata.
2. The question explicitly asks for acquisition-unit scope or names an
   acquisition-unit code in that context.
3. Purchase orders, invoices, vouchers, or another transactional entity are not
   the requested fact domain.

Explicit order-domain wording such as “orders,” “purchase orders,” “POs,”
“invoices,” or “vouchers” prevents this organization contract from applying when
those records are what the user asks to list, count, or analyze, including a
named acquisition-unit code applied to orders. Detection covers direct verbs
(`list`, `show`, `count`, `display`, `give me`, `how many`), transaction-led
noun phrases such as “Invoices by…”, and transaction nouns that precede the
interface output. Mentioning a vendor does not by itself make a question
organization-owned. Explicit
interface or statistics-note output remains organization-owned when
transactional words are merely contextual, as in “statistics notes in
organization interfaces used for orders, limited to AC.” Hyphenated modifiers
such as “purchase-order-related interface statistics notes” are normalized as
context rather than transactional requested facts. Only genuinely hyphenated
adjective tokens are stripped before general normalization. Unhyphenated
constructions such as “orders related to/via/through/by organization
interfaces” still request order rows and remain outside this contract.

Account-owned scope is intentionally excluded. A request such as “List
organization accounts assigned to acquisition unit AC” refers to the account
bridge `organizations.organizations__t__accounts__acq_unit_ids`, not the
organization bridge, and does not receive this contract. Supporting account
acquisition-unit reports requires a separate audited relationship contract.

This choice avoids the false positive where a valid order query reaches an
organization through:

```text
orders.purchase_order__t
  → organizations.organizations__t
  → orders.purchase_order__t__acq_unit_ids
```

## Authoritative Relationship Contract

### Organization interfaces

```text
organizations.organizations__t
  → organizations.organizations__t__interfaces
  → organizations.interfaces__t
```

```sql
organization_interfaces.id = organization.id
interface.id = organization_interfaces.interfaces
```

### Organization acquisition units

```text
organizations.organizations__t
  → organizations.organizations__t__acq_unit_ids
  → orders.acquisitions_unit__t
```

```sql
organization_acq_units.id = organization.id
acquisition_unit.id = organization_acq_units.acq_unit_ids
```

The bridge `id` is the parent organization ID. A valid candidate does not have to
include `organizations.organizations__t` when two organization-owned bridges can
be joined through that common parent ID. For example,
`organization_interfaces.id = organization_acq_units.id` is valid.

The bridge `organizations.organizations__t__interfaces` denormalizes organization
fields such as organization name, code, and status. Those columns describe the
organization, not the interface. Interface name and interface fields must come
from `organizations.interfaces__t`.

The similarly named
`organizations.organizations__t__accounts__acq_unit_ids` is account-level scope.
It must not substitute for the organization-level
`organizations.organizations__t__acq_unit_ids` bridge.

## Acquisition-Unit Predicate

Acquisition-unit codes are exact identifiers, not contains searches. The
validator accepts the exact forms that the conservative SQL analyzer can prove:

```sql
au.name = 'AC'
TRIM(au.name) = 'AC'
```

The literal must use the canonical requested-code casing. PostgreSQL text
equality is case-sensitive, so lowercase `'ac'` would not match the stored
`'AC'` code. Classification recognizes only configured canonical Five Colleges
codes (`SC`, `AC`, `MH`, `UM`, `HC`, `RP`, `YB`) and checks the explicit
post-unit form before the pre-unit form, so prepositions such as “in” and “to”
cannot become codes. `LIKE`, `ILIKE`, nested normalization such as
`UPPER(TRIM(...))`, and wildcard predicates are outside the analyzer's provable
subset and fail closed as ambiguous. `TRIM` is allowed for defensive
normalization but is not mandatory. This deliberately carves acquisition-unit
codes out of the general name-comparison guidance.

## Generation and Repair Guidance

The relationship contract belongs in the unconditional generation rules, not
only in the selected-campus rule:

- Organization-owned acquisition-unit scope uses
  `organizations.organizations__t__acq_unit_ids`.
- Organization interfaces are reached through
  `organizations.organizations__t__interfaces`.
- Do not join `organizations.interfaces__t.id` directly to
  `organizations.organizations__t.id`.
- Do not join `orders.purchase_order__t__acq_unit_ids.id` to an organization ID.
- Purchase-order acquisition-unit scope is valid only when its bridge ID joins
  `orders.purchase_order__t.id`; the purchase order may then join its vendor to
  the organization.
- Match acquisition-unit codes exactly.

The same guidance must be included in every exploratory repair prompt. The
selected-campus rule receives a carve-out:

- Organization and interface reference-data listings do not require an
  artificial campus join.
- When the user explicitly supplies organization acquisition-unit scope, that
  scope is applied through the organization bridge.
- Order, invoice, finance, inventory, and circulation campus rules otherwise
  remain unchanged.

## Contract Integration

`ExploratorySemanticContractService` gains a new organization
acquisition-unit concept. Its contract version increases from `1` to `2`.

The organization concept adds explicit requirement and guidance keys to:

- `ExploratorySqlSemanticValidatorService::RULE_METHODS`
- `ExploratorySqlSemanticValidatorService::GUIDANCE`
- semantic-contract coverage auditing
- initial exploratory prompt construction
- exploratory repair prompt construction

The contract is built from the raw question before SQL generation. In both the
initial and post-preflight repair paths, `GeminiService` passes `$rawQuestion`
rather than resolver-augmented `$generationPrompt` to classification. The one
existing exception is the trusted server-authored follow-up envelope, which
contains the prior raw request plus the user's terse correction and must retain
the prior report's semantic contract.
Validation then analyzes candidate SQL table aliases, joins, selected fields,
filters, and predicate form. It does not look for the exact reported sentence.

## Semantic Enforcement

For an applicable organization acquisition-unit contract, candidates fail
semantic validation when any of these conditions hold:

- The interface relationship, organization acquisition-unit relationship, and
  exact code predicate do not occur in one connected contributing SQL scope.
  Evidence from unrelated CTEs cannot be combined to satisfy the contract.
- The candidate uses CTEs. The current conservative analyzer cannot prove that a
  scoped CTE supplies the selected interface output rather than acting as a
  disconnected decoy, so this narrow contract requires a single SELECT scope
  and repairs CTE candidates to that simpler shape.
- `organizations.interfaces__t` is joined directly to
  `organizations.organizations__t` by their `id` columns.
- An interface is reached without
  `organizations.organizations__t__interfaces.interfaces =
  organizations.interfaces__t.id`.
- Organization-level acquisition scope omits
  `organizations.organizations__t__acq_unit_ids`.
- The organization acquisition-unit bridge does not join
  `orders.acquisitions_unit__t` through
  `acq_unit_ids = acquisitions_unit.id`.
- `orders.purchase_order__t__acq_unit_ids.id` is joined directly to an
  organization or organization-owned bridge ID.
- The account-level acquisition-unit bridge substitutes for the organization
  bridge.
- The requested acquisition-unit code is missing or matched with a non-exact
  predicate.
- An exact code predicate appears only on the nullable side of a `LEFT JOIN`;
  the code predicate must be in `WHERE` or an `INNER JOIN` condition so it
  actually restricts the result.

A purchase-order bridge is not rejected when both of these relationships are
present:

```sql
purchase_order_acq_units.id = purchase_order.id
purchase_order.vendor = organization.id
```

That shape represents an order-domain relationship and is outside the
organization-owned scope contract.

## Failure Behavior

Applicability is intentionally narrow. Once the organization contract applies,
validation is fail-closed:

- A structurally invalid or analyzer-ambiguous candidate is a repairable semantic
  failure.
- The existing shared maximum of two repair attempts remains unchanged.
- Safety and SQL-policy failures remain non-repairable.
- If both repairs fail, the request returns the existing safe “could not build a
  report I could safely run” response.
- The organization contract does not invoke the physical-ROI fallback compiler;
  recovery is family-neutral and explicit.

This expands fail-closed behavior only to questions confidently classified as
organization-owned acquisition-unit requests.

## Expected Query Shape

The reported request should produce the equivalent of:

```sql
SELECT DISTINCT
    org.name AS organization_name,
    intf.name AS interface_name,
    intf.statistics_notes
FROM organizations.organizations__t AS org
JOIN organizations.organizations__t__interfaces AS org_interfaces
    ON org_interfaces.id = org.id
JOIN organizations.interfaces__t AS intf
    ON intf.id = org_interfaces.interfaces
JOIN organizations.organizations__t__acq_unit_ids AS org_units
    ON org_units.id = org.id
JOIN orders.acquisitions_unit__t AS au
    ON au.id = org_units.acq_unit_ids
WHERE au.name = 'AC'
  AND intf.statistics_notes IS NOT NULL
LIMIT 100;
```

The non-null statistics-note predicate is an interpretation of “all statistics
notes,” not part of the relationship contract. Validation does not require it
and does not require empty strings to be removed. The existing conservative SQL
analyzer treats nested `NULLIF(TRIM(...)) IS NOT NULL` predicates as ambiguous;
expanding that parser is outside this relationship fix.
Output may omit organization or interface labels when the user requests a
one-column list. The relationship path and exact acquisition-unit constraint may
not change.

## Regression Matrix

### Offline PHPUnit tests

1. Organization-interface statistics notes without acquisition-unit wording:
   - The organization acquisition-unit contract is not applicable.
   - No acquisition-unit join is required.
2. Organizations with the AC acquisition unit:
   - The contract is applicable.
   - The organization acquisition-unit bridge is required.
3. Organization-interface statistics notes limited to AC:
   - The contract is applicable.
   - Both organization-owned bridges and an exact AC predicate are required.
4. Organization accounts assigned to AC:
   - The organization-level contract is not applicable.
5. The originally reported invalid SQL:
   - Rejected as repairable.
6. A corrected candidate using both authoritative bridges:
   - Accepted.
7. A valid “vendors with AC purchase orders” candidate:
   - The organization contract is not applicable and the purchase-order bridge
     remains valid.
8. A candidate that directly joins a purchase-order bridge ID to an organization
   ID:
   - Rejected when the organization contract applies.
9. A bridge-only organization query with no `organizations__t` parent:
   - Accepted when both bridges share the same organization ID.
10. Account-level `organizations__t__accounts__acq_unit_ids` used as
   organization-level scope:
   - Rejected.
11. `au.name = 'AC'` and `TRIM(au.name) = 'AC'`:
    - Accepted.
12. Lowercase or wrong-code exact literals:
    - Rejected.
13. `ILIKE`, nested normalization, wildcard predicates, and other analyzer
    ambiguity:
    - Repairable failure; after two failed repairs, family-neutral safe recovery.
14. Initial and repair prompt tests:
    - Both contain the same relationship guidance.
15. Coverage audit:
    - Every organization requirement has a rule method and repair guidance.
16. Resolver-augmented generation text containing transactional vocabulary:
    - Cannot change applicability because classification receives the raw
      question.
17. One negative mutation per relationship condition:
    - Direct interface identity join, missing interface bridge, missing
      organization acquisition-unit bridge, wrong acquisition-unit endpoint,
      purchase-order bridge substitution, account bridge substitution, missing
      acquisition-unit predicate, wrong acquisition-unit code, and a correct
      code predicate present only on a nullable `LEFT JOIN` each independently
      produce a repairable rejection.
18. Correct interface and acquisition-unit relationships in disconnected CTEs:
    - Rejected when the final query does not connect them through the same
      organization lineage.
19. Interface statistics notes with contextual “used for orders” wording:
    - The organization contract remains applicable because the requested output
      is organization-owned.
    - Equivalent “purchase-order-related” and “order-related” modifiers before
      the interface/statistics-note noun also remain applicable.
20. “Count invoices…” and “List vouchers…” requests that also mention
    interfaces:
    - The organization contract is not applicable because the transactional
      records are explicitly requested facts.
    - Equivalent “How many invoices…”, “Give me invoices…”, “Display purchase
      orders…”, “list of invoices…”, and entity-led “Invoices by…” forms are
      also excluded.
21. “in acquisition unit AC” and “limited to acquisition unit AC”:
    - Both retain `AC`; the prepositions are never extracted as codes.
22. A fully scoped decoy CTE joined to unscoped interface output:
    - Rejected because organization contracts currently require one SELECT
      scope.

Tests use deterministic contract classification, SQL-analysis fixtures, and
mocked repair candidates. They do not require a live model provider.

### Optional live-provider evaluation

The three user-facing prompts from cases 1–3 may be evaluated against a live
provider after offline tests pass. Live-provider output is observational and not
part of the PHPUnit acceptance gate.

## Compatibility and Scope

- PHP 7.2 compatibility remains required.
- Raw-question contract classification remains separate from resolver-augmented
  generation guidance.
- The two-repair maximum remains unchanged.
- Existing purchase-order, invoice, finance, inventory, and circulation scope
  rules remain unchanged except for the explicit organization carve-out.
- Generated schema, column, subtable, table-mapping, and reference cache files
  are not edited.
- No deterministic compiler for arbitrary organization reports is introduced.

The broader pattern—“when an entity has its own `__acq_unit_ids` bridge, prefer
that bridge for entity-owned scope”—also applies to funds, ledgers, budgets,
fiscal years, invoices, vouchers, order templates, and other entities. This
change intentionally does not generalize enforcement to all thirteen bridge
families because each entity has different parent and fact-domain semantics.
That generalization should be a separate audited contract rather than an
untested global heuristic.

## Verification Evidence

- Focused contract, semantic-validator, prompt, repair, and organization-scope
  regression scripts pass.
- The complete backend suite passes: 119 test scripts, 0 failures.
- Modified PHP services and the new focused test pass `php -l`.
- Independent implementation review found no remaining Critical, Important, or
  Minor issues.
