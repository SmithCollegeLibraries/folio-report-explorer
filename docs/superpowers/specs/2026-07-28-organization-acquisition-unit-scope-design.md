# Organization Acquisition-Unit Scope Design

**Date:** 2026-07-28

## Problem

Ask AI can select `organizations.interfaces__t` for a request about interface statistics notes, but it applies the global finance/acquisitions campus rule when the request also mentions an acquisition unit. That rule routes scope through purchase orders. For organization data this is the wrong domain path, and the model may additionally invent a direct identity join between an organization and an interface.

The failure is compositional:

- Interface statistics notes alone require `organizations.interfaces__t`.
- Organizations limited by acquisition unit require the organization's own `acq_unit_ids` subtable.
- The combined request requires both organization-owned subtables.

## Chosen Approach

Add an authoritative Organizations relationship contract to generation and repair guidance, then enforce that contract during semantic validation. This is preferred over:

1. Adding only a prompt example, which would improve model likelihood but remain fail-open.
2. Hard-coding the reported sentence, which would not generalize to other organization interface fields or acquisition-unit values.

The solution is domain-specific but prompt-independent: it applies whenever generated SQL combines organizations or organization interfaces with acquisition-unit scope.

## Authoritative Relationship Contract

Organization interfaces use:

```text
organizations.organizations__t
  → organizations.organizations__t__interfaces
  → organizations.interfaces__t
```

with:

```sql
organization_interfaces.id = organization.id
interface.id = organization_interfaces.interfaces
```

Organization acquisition units use:

```text
organizations.organizations__t
  → organizations.organizations__t__acq_unit_ids
  → orders.acquisitions_unit__t
```

with:

```sql
organization_acq_units.id = organization.id
acquisition_unit.id = organization_acq_units.acq_unit_ids
```

The acquisition-unit name remains a two-letter code such as `AC`, matched with `TRIM(acquisition_unit.name) = 'AC'`.

## Generation and Repair Guidance

The campus/acquisition guidance will distinguish organization/vendor-interface requests from purchase-order and invoice requests:

- Purchase-order and invoice scope continues to use `orders.purchase_order__t__acq_unit_ids`.
- Organization and organization-interface scope uses `organizations.organizations__t__acq_unit_ids`.
- Organization interfaces are reached only through `organizations.organizations__t__interfaces`.
- The generator must not join `organizations.interfaces__t.id` directly to `organizations.organizations__t.id`.
- Purchase-order tables must not be introduced solely to scope organization data.

The same contract must be present in the initial generation prompt and every repair prompt so bounded repair cannot lose it.

## Semantic Enforcement

Organization/acquisition-unit candidates fail semantic validation when any of these conditions hold:

- `organizations.interfaces__t` and `organizations.organizations__t` are joined directly by their `id` columns.
- Organization data is scoped through `orders.purchase_order__t__acq_unit_ids`.
- A query combining organizations, interfaces, and acquisitions units omits either required organization-owned bridge.
- A required bridge uses a column other than its authoritative parent ID or child-array value.

These failures are repairable semantic failures and use the existing shared maximum of two repairs. Safety and SQL-policy failures remain non-repairable.

Validation is based on SQL table/alias structure, not on matching the user's exact wording.

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
WHERE TRIM(au.name) = 'AC'
  AND NULLIF(TRIM(intf.statistics_notes), '') IS NOT NULL
LIMIT 100;
```

Output may omit organization or interface labels only when the user explicitly requests a one-column list. The relationship path and acquisition-unit constraint may not change.

## Regression Matrix

Tests will cover:

1. “List all statistics notes in the interfaces table in Organizations.”
   - Selects interface statistics notes without requiring acquisition-unit joins.
2. “List all Organizations with the AC acquisition unit.”
   - Uses the organization acquisition-unit bridge and no purchase-order bridge.
3. “List all statistics notes in Organizations interfaces limited to AC.”
   - Uses both organization-owned bridges and the exact `AC` constraint.
4. The originally reported invalid SQL.
   - Rejected as repairable.
5. A repaired candidate using the authoritative relationship contract.
   - Accepted without increasing the repair limit.

## Compatibility and Scope

- PHP 7.2 compatibility remains required.
- Raw-question routing remains separate from generated guidance.
- The two-repair maximum remains unchanged.
- Existing purchase-order, invoice, finance, inventory, and circulation scope rules remain unchanged.
- Generated schema, column, subtable, table-mapping, and reference cache files are not edited.
- No deterministic compiler for arbitrary organization reports is introduced in this change.
