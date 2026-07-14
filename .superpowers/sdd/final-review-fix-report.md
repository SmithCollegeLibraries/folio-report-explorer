# Budget Year Fund Report — Final Review Fix Report

## Outcome

Implemented the approved final-review corrections as follow-up migration 037. Migrations 035 and 036 remain byte-for-byte unchanged. Report 37 now calculates percentage and amount distributions according to their declared semantics, documents that behavior, and is recognized as current only when the complete canonical 037 contract is present.

## Changed Files

- `mysql/migrations/037_budget_year_fund_report_payment_distributions.sql`
  - Adds an idempotent fixed-ID update for report 37.
  - Uses case-insensitive distribution types.
  - Calculates `amount` distributions from the distribution value directly.
  - Calculates `percentage` and null-type legacy distributions from invoice-line total times value divided by 100.
  - Preserves the exact two parameters, 11 output columns, and eight numeric two-decimal monetary outputs.
  - Expands report help with the payment distribution semantics.
- `backend/services/MigrationService.php`
  - Adds migration-037 recognition.
  - Makes `databaseAppearsCurrent()` require the complete 037 definition.
  - Validates stable metadata, both complete ordered parameter objects, dynamic option SQL, all 11 aliases, payment semantics, encumbrance scope/arithmetic, calculated remaining, and all help definitions.
  - Leaves the migration-035 and migration-036 predicates intact.
- `backend/tests/BudgetYearFundReportPaymentDistributionsMigrationTest.php`
  - Guards migration-035 and migration-036 checksums.
  - Verifies migration-037 identity, idempotency, payment branches, help, aliases, rounding, and numeric outputs.
  - Covers percentage, amount, mixed/case-insensitive, and null-type legacy fixtures tied to the stored SQL markers.
- `backend/tests/BudgetYearFundReportFinalRevisionRecognitionTest.php`
  - Verifies complete 037 recognition and current-database recognition.
  - Adds negative cases for incomplete/wrong parameter objects, dynamic option SQL, output aliases, payment markers, encumbrance markers, calculated remaining, help, and stable metadata.
- `backend/tests/BudgetYearFundReportFiscalYearOptionsRecognitionTest.php`
  - Preserves migration-036 recognition while asserting that a 036-only definition is no longer current after 037 exists.
- `docs/superpowers/specs/2026-07-13-budget-year-fund-report-fiscal-year-options-design.md`
  - Adds the approved final-review distribution and checksum-preservation amendment.
- `docs/superpowers/plans/2026-07-13-budget-year-fund-report-fiscal-year-options.md`
  - Adds the implementation/testing amendment for migration 037.

## RED Evidence

Command:

```text
php backend/tests/BudgetYearFundReportPaymentDistributionsMigrationTest.php
```

Expected failure, exit 1:

```text
Migration 037 must exist.
```

Command:

```text
php backend/tests/BudgetYearFundReportFinalRevisionRecognitionTest.php
```

Expected failure, exit 1:

```text
Migration 037 must recognize the complete final report contract.
Expected: true
Actual: false

A ledger-less database may baseline only with the complete migration-037 report contract.
Expected: true
Actual: false
```

After production code was added, the earlier 036 recognition regression test failed with:

```text
The fully revised definition should make an otherwise-current database appear current.
Expected: true
Actual: false
```

That assertion was updated to the required post-037 invariant; migration-036-specific recognition remains green.

## GREEN Evidence

Focused migration contracts:

```text
php backend/tests/BudgetYearFundReportMigrationTest.php
php backend/tests/BudgetYearFundReportFiscalYearOptionsMigrationTest.php
php backend/tests/BudgetYearFundReportPaymentDistributionsMigrationTest.php
```

Output:

```text
BudgetYearFundReportMigration test passed
BudgetYearFundReportFiscalYearOptionsMigration test passed
BudgetYearFundReportPaymentDistributionsMigration test passed
```

Focused recognition/service contracts:

```text
php backend/tests/BudgetYearFundReportFiscalYearOptionsRecognitionTest.php
php backend/tests/BudgetYearFundReportFinalRevisionRecognitionTest.php
php backend/tests/MigrationServiceTest.php
```

Output:

```text
BudgetYearFundReportFiscalYearOptionsRecognition test passed
BudgetYearFundReportFinalRevisionRecognition test passed
MigrationService test passed
```

Syntax and diff hygiene:

```text
php -l backend/services/MigrationService.php
php -l backend/tests/BudgetYearFundReportFinalRevisionRecognitionTest.php
php -l backend/tests/BudgetYearFundReportPaymentDistributionsMigrationTest.php
git diff --check
```

All commands exited 0 with no syntax or whitespace errors.

Checksum evidence:

```text
ad4aadbda3259f2bfd68f6e995c86da3f95c8d4fa86a9bd7a726ff1ab0c823ab  mysql/migrations/035_budget_year_fund_report.sql
cb90cd367bee5943c10c9922315f78a0ff16e0465c1eb283df7defa915c19c76  mysql/migrations/036_budget_year_fund_report_fiscal_year_options.sql
```

## Full-Suite Evidence

Command:

```text
for test in backend/tests/*Test.php; do
  if [ "$(basename "$test")" = "QueryWorkerConcurrencyTest.php" ]; then
    continue
  fi
  php "$test" || exit 1
done
```

Result: exit 0; all 79 self-contained backend PHP tests passed. `QueryWorkerConcurrencyTest.php` was excluded as the documented vendor-dependent test. The run emitted existing PHP 8.5 `ReflectionMethod::setAccessible()` deprecations and existing fake-app `stdClass::$db` warnings; no test failed. The suite-generated change to `backend/data/table_mapping_cache.json` was restored and was not committed.

## Commits

- `60002864d96d8ee6189d4056ec8ee1e0722788fb` — `fix: handle budget report payment distributions`
- `a9c0a8882695ed3945d29becfd17a04e0893cb6e` — `docs: amend budget report distribution semantics`

## Self-Review

- Confirmed fixed identity `id = 37` and slug `budget-year-fund-report`.
- Confirmed migration 037 is idempotent through `INSERT ... ON DUPLICATE KEY UPDATE`.
- Confirmed exact two complete ordered parameter objects and dynamic option SQL are preserved.
- Confirmed the exact 11 output aliases remain in order and exactly eight monetary outputs use numeric `ROUND(..., 2)` with no `TO_CHAR`.
- Confirmed percentage, amount, mixed/case-insensitive, and null-type legacy behavior.
- Confirmed recognition rejects omitted parameter, alias, payment, encumbrance, calculated-remaining, help, and metadata components.
- Confirmed earlier migration-specific recognition remains present while current-database recognition requires 037.
- Confirmed migrations 035 and 036 checksums are unchanged.
- Confirmed no migration 035/036, cache, unrelated, or user file is included in the commits.

## Concerns

No implementation concerns. Existing PHP 8.5 deprecation and fake-app warnings remain outside this fix set; the vendor-dependent concurrency test was not run, per the established self-contained suite contract.

## Whole-Branch Review Follow-Up: Executed PostgreSQL Payment Expression

Added `backend/tests/BudgetYearFundReportPaymentDistributionsPostgresTest.php`. The test reads migration 037, extracts the actual `CASE` expression inside `SUM(... ) AS payment`, converts the MySQL-literal doubled quotes back to PostgreSQL quotes, and injects that exact expression into one read-only `WITH fixture AS (VALUES ...) SELECT`. The PostgreSQL result set must equal the deterministic percentage, amount, mixed/case-insensitive, and null-type totals. The test emits an explicit `SKIP` only when the PDO PostgreSQL driver or connection settings/connection are unavailable. PostgreSQL connection attempts use an explicit 10-second `connect_timeout` by default, configurable through `BUDGET_REPORT_PG_TIMEOUT`.

The configured remote FOLIO PostgreSQL connection was attempted first from the existing PHP container with its saved application settings:

```text
docker exec -e FOLIO_APP_ROOT=/var/www/html \
  -e BUDGET_REPORT_MIGRATION_037=/tmp/037_budget_year_fund_report_payment_distributions.sql \
  folio-report-explorer-main-clean-php-1 \
  php /tmp/BudgetYearFundReportPaymentDistributionsPostgresTest.php
```

Result: exit 0 with explicit environment skip after the remote host timed out:

```text
SKIP: FOLIO PostgreSQL connection is unavailable: SQLSTATE[08006] [7] timeout expired
```

To obtain executed PostgreSQL RED/GREEN evidence without relying on that unavailable remote host, an isolated temporary `postgres:16-alpine` container was attached to the existing application's Docker network. The existing `folio-report-explorer-main-clean-php-1` container executed the test; the SQL remained a table-free read-only `VALUES` query.

### Mutation RED Proof

Migration 037 was temporarily changed so the `amount` branch incorrectly used percentage arithmetic. The mutated migration and test were copied to `/tmp` in the existing PHP container and run with:

```text
docker exec \
  -e BUDGET_REPORT_PG_USE_ENV=1 \
  -e FOLIO_PG_HOST=budget-report-expression-postgres \
  -e FOLIO_PG_PORT=5432 \
  -e FOLIO_PG_DB=budget_report_test \
  -e FOLIO_PG_USER=budget_report_test \
  -e FOLIO_PG_PASS=budget_report_test \
  -e FOLIO_PG_SSLMODE=disable \
  -e BUDGET_REPORT_MIGRATION_037=/tmp/037_budget_year_fund_report_payment_distributions.sql \
  folio-report-explorer-main-clean-php-1 \
  php /tmp/BudgetYearFundReportPaymentDistributionsPostgresTest.php
```

Expected failure, exit 1:

```text
Stored payment CASE expression produced incorrect totals.
Expected: {"amount":"25.00","mixed":"62.50","null-type":"10.00","percentage":"50.00"}
Actual: {"amount":"50.00","mixed":"174.88","null-type":"10.00","percentage":"50.00"}
```

The temporary mutation was immediately reverted and was not committed. `git diff --quiet` confirmed migrations 035, 036, and 037 all match `HEAD`.

### Restored Migration GREEN Proof

The exact same Docker/PHP/PostgreSQL command was rerun after copying the restored migration 037:

```text
BudgetYearFundReportPaymentDistributionsPostgres test passed
```

Result: exit 0. The temporary PostgreSQL container was then stopped and removed.

### Focused Regression Evidence

Commands:

```text
php backend/tests/BudgetYearFundReportPaymentDistributionsPostgresTest.php
php backend/tests/BudgetYearFundReportPaymentDistributionsMigrationTest.php
php backend/tests/BudgetYearFundReportFinalRevisionRecognitionTest.php
php backend/tests/BudgetYearFundReportMigrationTest.php
php backend/tests/BudgetYearFundReportFiscalYearOptionsMigrationTest.php
php backend/tests/BudgetYearFundReportFiscalYearOptionsRecognitionTest.php
php backend/tests/MigrationServiceTest.php
php -l backend/tests/BudgetYearFundReportPaymentDistributionsPostgresTest.php
git diff --check
```

Results: all focused self-contained contracts passed; the new integration test explicitly skipped outside Docker because no local FOLIO PostgreSQL environment was available; PHP lint and diff hygiene passed. Per the follow-up contract, the full 79-file suite was not rerun.
