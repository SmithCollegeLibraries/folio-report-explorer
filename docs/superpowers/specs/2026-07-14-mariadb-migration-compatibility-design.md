# MariaDB Migration Compatibility Design

## Problem

Production runs MariaDB and rejects the constant-row form `INSERT ... SELECT ... WHERE NOT EXISTS` used by migrations 029 and 030. Migration 029 therefore stops before it can be recorded in `schema_migrations`, which also prevents the later Budget Year Fund Report migrations from running.

## Scope

Make migrations 029 and 030 portable to the production MariaDB version without changing their data or idempotency semantics. This change does not alter the migration ledger, manually seed production, or modify unrelated deployment behavior.

## Chosen approach

Add `FROM DUAL` between each constant-value `SELECT` list and its `WHERE NOT EXISTS` guard in migrations 029 and 030. `DUAL` supplies the single-row source required by MariaDB while preserving the existing rule: insert the hint only when its matching row is absent.

Alternatives rejected:

- Editing the SQL only on production would create source drift and a future checksum mismatch.
- `INSERT IGNORE` or `ON DUPLICATE KEY UPDATE` would depend on unique constraints that do not express the migrations' current existence predicates.
- Changing the general migration parser would be broader and would not correct the invalid SQL stored in these migration files.

## Data and failure behavior

Each migration remains restart-safe. Its leading `UPDATE` may execute more than once, and each guarded `INSERT` still creates at most one matching row. Neither migration has been recorded on the affected production database, so updating these files does not change the checksum of an applied migration there.

After production pulls the patch, `php backend/yii migration/run` should apply or baseline migration 029, continue through migration 030, and then reach migrations 035 through 037 that install the Budget Year Fund Report.

## Testing

Add a focused PHP regression test that reads migrations 029 and 030 and verifies every guarded constant-row insert uses `FROM DUAL` before `WHERE NOT EXISTS`. Run that test first against the current SQL to demonstrate the production failure condition, then apply the minimum SQL change and rerun it. Finally run the existing migration-service and deploy-policy tests to guard the surrounding migration workflow.
