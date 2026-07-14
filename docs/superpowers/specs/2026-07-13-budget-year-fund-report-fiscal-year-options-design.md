# Budget Year Fund Report Fiscal-Year Options Design

## Goal

Revise the fixed **Budget Year Fund Report** so users choose a campus-neutral fiscal year such as `FY2027` and an acquisition unit such as `SC`. The report will infer the campus fiscal-year series (`SCFY`) and use the matching FOLIO fiscal-year record, without hardcoded years, dates, or campus codes.

The result will use the concise 11-column layout requested by the user and retain the reusable help modal to explain calculated and FOLIO values.

## User Inputs

The report exposes exactly two required dropdowns:

1. `fiscalYearEndYear`
   - Label: `Fiscal Year`
   - Values come from distinct years in `finance.fiscal_year__t.period_end`.
   - Labels are generated as `FY` plus the four-digit end year, for example `FY2027`.
   - Duplicate campus-specific fiscal-year rows collapse into one option.
   - New years appear automatically when FOLIO receives the next fiscal-year records.
2. `acqUnitId`
   - Label: `Acquisition Unit`
   - Values remain acquisition-unit UUIDs from `orders.acquisitions_unit__t`.
   - Labels use `TRIM(name)`, producing values such as `SC`, `AC`, `HC`, `MH`, and `UM` without trailing whitespace.

No start-date, end-date, fiscal-year UUID, campus code, or series input is exposed.

## Fiscal-Year Resolution

The SQL resolves the selected acquisition unit first and derives its fiscal-year series as:

```sql
TRIM(au.name) || 'FY'
```

For example, acquisition unit `SC` becomes series `SCFY`. The selected fiscal-year CTE then finds the FOLIO row where:

```sql
fy.series = TRIM(au.name) || 'FY'
AND EXTRACT(YEAR FROM fy.period_end::date)::int = CAST(:fiscalYearEndYear AS integer)
```

The matching FOLIO row supplies the fiscal-year UUID, `period_start`, and `period_end`. Payment dates and encumbrance transactions are scoped using this resolved row. This keeps the report dynamic and prevents users from accidentally combining one campus's fiscal-year UUID with another campus's acquisition unit.

If no matching campus/year fiscal-year row exists, the report returns no rows rather than falling back to a different campus or date range.

## Fund and Financial Scope

- Funds must be assigned to the selected acquisition-unit UUID through `finance.fund__t__acq_unit_ids`.
- Budgets must belong to the resolved FOLIO fiscal-year UUID.
- Only budgets with a nonzero allocation are included.
- Paid invoice-line fund distributions are included when the invoice payment date falls within the resolved fiscal year's stored period dates.
- Current encumbrances include only `Encumbrance` transactions whose status is `Unreleased` or `Active` and whose fiscal-year UUID is the resolved fiscal year.
- Current encumbrance is calculated as initial encumbered amount minus expended amount minus awaiting-payment amount.

Each component aggregates by fund and fiscal year before the final joins, preserving one output row per allocated fund and resolved fiscal year.

## Output Contract

The report returns exactly these columns in order:

1. `Fund Code`
2. `Fund Name`
3. `Fiscal Year`
4. `Allocated`
5. `Payments`
6. `Calculated Current Encumbrances`
7. `Total Committed`
8. `Calculated Remaining`
9. `FOLIO Expenditures`
10. `FOLIO Encumbered`
11. `FOLIO Available`

`Fiscal Year` uses the campus-neutral label, such as `FY2027`.

All eight monetary columns remain numeric and use PostgreSQL `ROUND(..., 2)`. The report does not use `TO_CHAR`.

Calculations:

- `Total Committed = Payments + Calculated Current Encumbrances`
- `Calculated Remaining = Allocated - Payments - Calculated Current Encumbrances`

The report intentionally does not display transfer, total-funding, or difference columns. The FOLIO columns remain alongside the calculated columns so users can compare them directly.

## Help Modal Content

The existing reusable report-help modal remains the explanation surface. Migration 036 replaces the report's help text with definitions for:

- Allocated
- Payments
- Calculated Current Encumbrances
- Total Committed
- Calculated Remaining
- FOLIO Expenditures
- FOLIO Encumbered
- FOLIO Available

The modal explicitly explains that calculated remaining starts from allocation, while FOLIO available is the operational balance and may reflect transfers, credits, releases, rollover activity, adjustments, timing, and synchronization. This prevents the two remaining values from being interpreted as equivalent accounting fields.

## Migration Strategy

Migration `035_budget_year_fund_report.sql` is already applied and must not be edited again because the migration ledger verifies checksums.

A new idempotent migration `036_budget_year_fund_report_fiscal_year_options.sql` will update fixed report ID 37 using `INSERT ... ON DUPLICATE KEY UPDATE`. It will replace the SQL template, parameter JSON, description, help text, and stable report metadata while preserving the report's fixed identity.

`MigrationService` will recognize migration 036 only when the report contains the revised year parameter, acquisition-unit parameter, concise output markers, and revised help metadata. Fresh databases apply migrations 035 and 036 in order; existing databases apply only 036.

## Testing and Docker Validation

Automated contracts will verify:

- Migration 035 remains byte-for-byte unchanged.
- Migration 036 exists and is idempotent.
- Exactly two required select parameters are stored: `fiscalYearEndYear` and `acqUnitId`.
- Fiscal-year options group distinct `period_end` years into `FY####` labels.
- SQL derives the series from trimmed acquisition-unit name plus `FY`.
- No hardcoded campus code, fiscal year, or report date range is present.
- The 11 output columns appear in the required order.
- All eight monetary columns use numeric two-decimal rounding and no `TO_CHAR`.
- The revised help text explains both calculated and FOLIO values.
- Migration recognition distinguishes the original report definition from the revised definition.

Docker validation will apply migration 036 to the existing `folio-report-explorer-main-clean` MySQL volume, confirm a clean migration audit, inspect report 37 through the live API, and run the report using a real year/acquisition-unit combination such as `FY2027 + SC`.

## Out of Scope

- Hardcoded campus mappings or annual year maintenance.
- Changes to shared report execution, parameter binding, jobs, exports, charts, or the reusable modal component.
- Adding difference columns or restoring the wider reconciliation layout.
- Changing FOLIO source data when a campus/year combination is missing.

## Final-Review Amendment: Payment Distributions

Payments preserve each FOLIO fund distribution's declared semantics. A `percentage` distribution contributes the invoice-line total multiplied by its value divided by 100, while an `amount` distribution contributes its value directly. Distribution types are compared case-insensitively; a null type retains the legacy percentage calculation.

Because migrations 035 and 036 may already be recorded in migration ledgers, their bytes and checksums remain unchanged. Idempotent migration `037_budget_year_fund_report_payment_distributions.sql` replaces report 37 with the corrected payment expression and expanded help text. Migration 037 preserves the two-parameter and 11-column contracts, and current-database recognition requires the materially complete 037 definition while migration-specific recognition for 035 and 036 remains available.
