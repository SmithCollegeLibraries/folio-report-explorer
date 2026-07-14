# MariaDB Migration Compatibility Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make guarded constant-row inserts in migrations 029 and 030 execute on the production MariaDB version without changing their idempotency semantics.

**Architecture:** Keep the correction local to the SQL migration files by supplying MariaDB's required single-row `DUAL` source before each `WHERE NOT EXISTS`. A focused file-contract test prevents either migration from reintroducing a source-less guarded `SELECT`.

**Tech Stack:** PHP 7.2-compatible test scripts, MySQL/MariaDB SQL, Yii2 migration runner

## Global Constraints

- Do not alter migration data, existence predicates, or migration-ledger behavior.
- Do not manually seed production or mark a migration as applied.
- Keep the change limited to migrations 029 and 030 plus their focused regression test.
- Preserve compatibility with the project's PHP 7.2 production floor.

---

### Task 1: Make guarded training-hint inserts portable to MariaDB

**Files:**
- Create: `backend/tests/MariaDbGuardedInsertMigrationTest.php`
- Modify: `mysql/migrations/029_same_title_holdings_overlap_hint.sql:12-58`
- Modify: `mysql/migrations/030_collection_location_reference_hint.sql:12-58`

**Interfaces:**
- Consumes: migration SQL loaded from `mysql/migrations/029_same_title_holdings_overlap_hint.sql` and `mysql/migrations/030_collection_location_reference_hint.sql`
- Produces: MariaDB-compatible guarded inserts with unchanged inserted values and `WHERE NOT EXISTS` predicates

- [ ] **Step 1: Write the failing compatibility test**

Create `backend/tests/MariaDbGuardedInsertMigrationTest.php`:

```php
<?php

$migrationPaths = [
    __DIR__ . '/../../mysql/migrations/029_same_title_holdings_overlap_hint.sql',
    __DIR__ . '/../../mysql/migrations/030_collection_location_reference_hint.sql',
];

foreach ($migrationPaths as $migrationPath) {
    $sql = (string)file_get_contents($migrationPath);
    $guardCount = substr_count($sql, 'WHERE NOT EXISTS (');
    $dualGuardCount = preg_match_all('/FROM DUAL\s+WHERE NOT EXISTS\s*\(/i', $sql, $matches);

    if ($guardCount !== 2) {
        fwrite(STDERR, basename($migrationPath) . " must retain exactly two guarded inserts.\n");
        exit(1);
    }

    if ($dualGuardCount !== $guardCount) {
        fwrite(STDERR, basename($migrationPath) . " must use FROM DUAL before every WHERE NOT EXISTS insert guard.\n");
        exit(1);
    }
}

fwrite(STDOUT, "MariaDB guarded insert migration test passed\n");
```

- [ ] **Step 2: Run the test and verify the production incompatibility is reproduced**

Run:

```bash
php backend/tests/MariaDbGuardedInsertMigrationTest.php
```

Expected: exit code 1 with `029_same_title_holdings_overlap_hint.sql must use FROM DUAL before every WHERE NOT EXISTS insert guard.`

- [ ] **Step 3: Add the portable single-row source to all four guarded inserts**

In both migration files, change each occurrence shaped as:

```sql
    NOW()
WHERE NOT EXISTS (
```

to:

```sql
    NOW()
FROM DUAL
WHERE NOT EXISTS (
```

Do this twice in migration 029 and twice in migration 030. Do not change the selected values or existence predicates.

- [ ] **Step 4: Run the focused compatibility test**

Run:

```bash
php backend/tests/MariaDbGuardedInsertMigrationTest.php
```

Expected: `MariaDB guarded insert migration test passed`

- [ ] **Step 5: Run surrounding migration regression tests**

Run:

```bash
php backend/tests/MigrationServiceTest.php
php backend/tests/DeployMigrationPolicyTest.php
php backend/tests/BudgetYearFundReportMigrationTest.php
php backend/tests/BudgetYearFundReportFiscalYearOptionsMigrationTest.php
php backend/tests/BudgetYearFundReportPaymentDistributionsMigrationTest.php
```

Expected: every command exits 0 and prints its passing message.

- [ ] **Step 6: Check the patch for unintended changes**

Run:

```bash
git diff --check
git diff -- backend/tests/MariaDbGuardedInsertMigrationTest.php mysql/migrations/029_same_title_holdings_overlap_hint.sql mysql/migrations/030_collection_location_reference_hint.sql
```

Expected: `git diff --check` prints nothing; the diff contains one new focused test and four `FROM DUAL` lines only.

- [ ] **Step 7: Commit the compatibility fix**

Run:

```bash
git add backend/tests/MariaDbGuardedInsertMigrationTest.php mysql/migrations/029_same_title_holdings_overlap_hint.sql mysql/migrations/030_collection_location_reference_hint.sql
git commit -m "fix: support guarded inserts on MariaDB"
```
