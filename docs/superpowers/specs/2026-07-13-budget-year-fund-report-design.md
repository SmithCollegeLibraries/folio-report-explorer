# Budget Year Fund Report Design

## Purpose

Add a fixed Finance report named **Budget Year Fund Report**. It shows every allocated FOLIO fund assigned to a selected acquisition unit for one selected FOLIO fiscal year. It compares transaction-derived payments, encumbrances, and remaining balances with the corresponding totals stored on each FOLIO budget.

## Report Parameters

The report has two required FOLIO-backed dropdowns:

1. **Fiscal Year** — stores `finance.fiscal_year__t.id`. The selected row supplies its name, series, period start, and period end. No separate date parameters are exposed.
2. **Acquisition Unit** — stores `orders.acquisitions_unit__t.id`.

The report includes every fund that:

- is assigned to the selected acquisition unit through `finance.fund__t__acq_unit_ids`; and
- has a budget for the selected fiscal year with a nonzero allocation.

The acquisition-unit filter uses `EXISTS` so funds assigned to multiple units cannot duplicate budget totals.

## Data Sources and Calculations

The report is a FOLIO report, not a composite report.

### Budget values

Budget values come from `finance.budget__t` for the selected fiscal year:

- Allocated
- Net Transfers
- Total Funding
- FOLIO Expenditures
- FOLIO Encumbered
- FOLIO Available

### Calculated payments

Payments come from paid invoice-line fund distributions whose invoice payment date falls inside the selected FOLIO fiscal-year period. They are grouped by fund and fiscal year using the established report formula:

```text
invoice line total × fund distribution value × 0.01
```

### Calculated current encumbrances

Current encumbrances come from `finance.transaction__t` rows for the selected fiscal year with transaction type `Encumbrance` and status `Unreleased` or `Active`. They are grouped by `from_fund_id` using:

```text
initial amount encumbered − amount expended − amount awaiting payment
```

### Comparison values

The report calculates:

```text
Expenditure Difference = Calculated Payments − FOLIO Expenditures
Encumbrance Difference = Calculated Current Encumbrances − FOLIO Encumbered
Calculated Total Committed = Calculated Payments + Calculated Current Encumbrances
Calculated Remaining = Total Funding − Calculated Payments − Calculated Current Encumbrances
Remaining Difference = Calculated Remaining − FOLIO Available
```

Differences are diagnostic rather than corrections to FOLIO.

## Output Columns

The result columns, in order, are:

1. Fund Code
2. Fund Name
3. Fiscal Year
4. Allocated
5. Net Transfers
6. Total Funding
7. Calculated Payments
8. FOLIO Expenditures
9. Expenditure Difference
10. Calculated Current Encumbrances
11. FOLIO Encumbered
12. Encumbrance Difference
13. Calculated Total Committed
14. Calculated Remaining
15. FOLIO Available
16. Remaining Difference

Rows are ordered by fund code and fund name.

## Decimal Handling

Every monetary output uses PostgreSQL `ROUND(..., 2)` and remains numeric. No monetary value uses `TO_CHAR`, so table sorting, charting, and CSV export remain numeric. Null inputs are converted to zero before arithmetic. Long database decimals are therefore rounded to two decimal places without becoming formatted strings.

## Reusable Report Help Modal

Add nullable `help_text` metadata to `report_templates`. Reports without help text behave exactly as they do today.

The report API exposes this field as `helpText`. On a report detail page with help text, an info button labeled **How to read this report** opens an accessible modal. The modal:

- displays the report-specific explanatory text with preserved paragraph breaks;
- explains each calculated/FOLIO pair and each difference column;
- explains that FOLIO values are authoritative operational balances while calculated values are reconciliation aids;
- closes through a visible Close button, the backdrop, or Escape; and
- restores focus to the trigger when closed.

The content is stored with the report template rather than hardcoded to its slug, allowing future fixed reports to use the same interaction.

## Persistence

A new idempotent migration will:

1. add nullable `help_text` storage to `report_templates`;
2. insert or update fixed report template ID `37` with slug `budget-year-fund-report`;
3. store its SQL, parameter definitions, description, Finance category, FOLIO data source, and help text; and
4. be recognized by `MigrationService` only when both the column and report row exist.

The report default limit is 1,000 rows and all execution remains read-only through the existing report job path.

## Error and Empty-State Behavior

- Both parameters are required and securely bound.
- A fiscal year with no allocated funds returns an empty result, not an error.
- Missing payment or encumbrance activity produces numeric zeroes.
- SQL safety, preflight validation, job execution, CSV export, and existing report error handling remain unchanged.

## Testing

Automated regression coverage will verify:

- the migration contains the fixed report, both required parameters, acquisition-unit `EXISTS` filtering, transaction-derived encumbrances, fiscal-year-derived payment dates, all comparison columns, and numeric two-decimal rounding;
- report serialization exposes optional `helpText` without affecting templates that omit it;
- the help button/modal renders only when help text is present, shows the definitions, supports accessible close behavior, and restores focus; and
- existing report and frontend suites continue to pass.

## Success Criteria

An authorized user can open **Budget Year Fund Report**, select one FOLIO fiscal year and one acquisition unit, run it, and receive one row per allocated fund. Every monetary value is numeric and rounded to two decimal places. The page provides an accessible modal explaining calculated values, FOLIO values, and reconciliation differences.
