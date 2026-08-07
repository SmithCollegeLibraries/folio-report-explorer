# MARC Field, Indicator, and Content Finder Implementation Evidence

## Verification scope

This report records the Task 7 PostgreSQL and release-readiness gate for the
flexible MARC field finder. The live gate is deliberately opt-in and uses a
read-only PostgreSQL transaction. No estimated query plan is represented as a
measured result.

| Check | Result |
| --- | --- |
| Repository baseline | `82c83b0` |
| Offline PostgreSQL gate | PASS — `php backend/tests/CatalogingMarcFieldFinderPostgresTest.php` printed exactly `SKIP: Set RUN_FOLIO_DB_TESTS=1 to run live FOLIO PostgreSQL contract checks.` and exited 0. |
| PHP syntax check | PASS — `php -l backend/tests/CatalogingMarcFieldFinderPostgresTest.php`. |
| Opt-in live gate | Not run to PostgreSQL — `RUN_FOLIO_DB_TESTS=1` stopped before connection because local FOLIO PostgreSQL host, database, and user settings are unavailable. |
| Docker migration audit/run | Not run in this task; no Docker migration evidence is available. |
| Docker API smoke test | Not run in this task; no authenticated application session is available. |
| FOLIO Data Export smoke test | Not run — requires institutional authorization and a generated UUID file. |

## Live gate contract

When `RUN_FOLIO_DB_TESTS=1` is enabled, the test reads the configured FOLIO
PostgreSQL connection, starts a read-only transaction, and applies the
configured statement timeout (default `1,800,000 ms`). It selects the smallest
and largest active effective-item locations, unless
`FOLIO_DB_TEST_LOCATION_IDS` supplies an explicit comma-separated active UUID
set. For each selected set it exercises both `effective_item` and
`permanent_item` bases and emits one JSON evidence line per case with the
prefix `MARC_FINDER_PG_PLAN`.

The five live cases are:

1. `mt245`, matching occurrence, subfield `a`, case-insensitive literal
   contains search;
2. `mt245`, missing matching occurrence, subfield `a`;
3. `mt035`, blank first indicator, second indicator `9`, subfield `a`;
4. `mt100`, `has_lowercase` content rule; and
5. `mt245`, literal `%`, `_`, quote, and backslash search text.

Each plan fails closed if it touches a relation other than the selected
`marctab.mtNNN`, references `folio_source_record.marctab` or
`parsed_record__content`, does not expose the materialized `target_instances`
scope, exceeds the `100001` fetch sentinel, or uses a sequential scan on
`marctab.mt245`. The blank-indicator case also compares its returned rows with
the scoped whitespace/backslash fixture when both encodings are present.

## Release decision

**Blocked — do not enable the report yet.** The offline skip path and test
syntax are verified, but the required representative PostgreSQL plans,
execution times, instance-index evidence, timeout check, Docker migration
audit/run, Docker API worklist/UUID smoke test, and authorized FOLIO Data Export
acceptance test remain unavailable. Run the opt-in gate and Docker/API checks
in an environment with the appropriate read-only credentials and authorization,
then append the exact `MARC_FINDER_PG_PLAN` output and migration/API/CSV results
before enabling the seeded report.
