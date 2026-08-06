# MARC Missing-Tag Report Implementation Evidence

## Versions and Configuration

| Item | Measured value |
| --- | --- |
| Repository baseline | `156ff54` (`test: cover file export truncation polling`) |
| PHP | `8.5.9` |
| Node.js / npm | `v26.5.0` / `11.17.0` |
| Docker Compose | `v5.3.0` |
| PostgreSQL test command without opt-in | `SKIP: Set RUN_FOLIO_DB_TESTS=1 to run live FOLIO PostgreSQL contract checks.` |
| PostgreSQL test command with opt-in | Not run against a database — `RUN_FOLIO_DB_TESTS=1` stopped before connection because FOLIO PostgreSQL host, database, and user are not configured locally. |
| Configured live-test statement timeout | `1,800,000 ms` default in `CatalogingMarcMissingTagReportPostgresTest.php`; not exercised against PostgreSQL. |
| Backend standalone suite | Exit `0`; all `backend/tests/*Test.php` completed. Existing PHP 8.5 deprecation/warning output was emitted by unrelated tests. |
| Frontend tests | Exit `0`; `36` files and `186` tests passed. |
| Frontend lint | Not run successfully — `npm run lint` exits `127`: `eslint: command not found`. |
| Frontend production build | Exit `0`; Vite transformed `2509` modules. Existing Browserslist and >500 kB chunk warnings were emitted. |

## Migration and Seed Verification

| Check | Measured value |
| --- | --- |
| Offline migration contract | `php backend/tests/CatalogingMarcMissingTagReportMigrationTest.php` passed. |
| Offline migration policy | `php backend/tests/DeployMigrationPolicyTest.php` passed. |
| Docker mount correction | The PHP service now mounts `./mysql` read-only at `/var/www/mysql`, matching `MigrationService::DEFAULT_MIGRATION_DIR` as resolved from `/var/www/html`. |
| Docker `migration/audit` | Ran after the mount correction. Measured: `39` files, `3` unapplied, `2` changed applied checksums (`029_same_title_holdings_overlap_hint.sql`, `030_collection_location_reference_hint.sql`), `0` duplicate numbers, `10` non-idempotent risks. Exit was non-zero because of the pre-existing checksum drift. |
| Docker first `migration/run` | Not run — audit reported changed applied checksums; applying migrations with checksum drift is unsafe. |
| Docker second `migration/run` | Not run — the first run was intentionally not attempted after the failed audit. |
| Live MySQL migration 040 / seed state | Not run — the Docker audit checksum-drift gate blocks migration execution. |
| Required static-contract scan | The plan-specified scan found the UUID anti-join at line `74` and `LIMIT 100001` at line `79`. Its `instance.source = .MARC.` arm does not match the MySQL string-literal source because the stored PostgreSQL SQL escapes it as `instance.source = ''MARC''`; the passing offline migration-contract test covers that exact stored predicate. |

## Query Plans by Location Basis and Tag

`CatalogingMarcMissingTagReportPostgresTest.php` is opt-in and, when enabled, selects the smallest and largest active locations (or `FOLIO_DB_TEST_LOCATION_IDS`), checks `marctab.mt245` and `marctab.mt856`, compiles all basis/tag combinations, uses a read-only transaction with `EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON)`, and emits `MARC_PG_PLAN` JSON evidence. No FOLIO PostgreSQL connection is configured in this environment, so no plan may be represented as measured.

| Location UUID/name/code | Basis | Tag | Planning ms | Execution ms | Returned rows | Shared hits / reads | 100001st row | Timeout | Indexes |
| --- | --- | --- | ---: | ---: | ---: | --- | --- | --- | --- |
| Not run — largest active location unavailable | effective_item | 245 | Not run | Not run | Not run | Not run | Not run | `1,800,000 ms` not exercised | Not run |
| Not run — largest active location unavailable | effective_item | 856 | Not run | Not run | Not run | Not run | Not run | `1,800,000 ms` not exercised | Not run |
| Not run — largest active location unavailable | permanent_item | 245 | Not run | Not run | Not run | Not run | Not run | `1,800,000 ms` not exercised | Not run |
| Not run — largest active location unavailable | permanent_item | 856 | Not run | Not run | Not run | Not run | Not run | `1,800,000 ms` not exercised | Not run |
| Not run — largest active location unavailable | permanent_holdings | 245 | Not run | Not run | Not run | Not run | Not run | `1,800,000 ms` not exercised | Not run |
| Not run — largest active location unavailable | permanent_holdings | 856 | Not run | Not run | Not run | Not run | Not run | `1,800,000 ms` not exercised | Not run |
| Not run — smallest active location unavailable | effective_item | 245 | Not run | Not run | Not run | Not run | Not run | `1,800,000 ms` not exercised | Not run |
| Not run — smallest active location unavailable | effective_item | 856 | Not run | Not run | Not run | Not run | Not run | `1,800,000 ms` not exercised | Not run |
| Not run — smallest active location unavailable | permanent_item | 245 | Not run | Not run | Not run | Not run | Not run | `1,800,000 ms` not exercised | Not run |
| Not run — smallest active location unavailable | permanent_item | 856 | Not run | Not run | Not run | Not run | Not run | `1,800,000 ms` not exercised | Not run |
| Not run — smallest active location unavailable | permanent_holdings | 245 | Not run | Not run | Not run | Not run | Not run | `1,800,000 ms` not exercised | Not run |
| Not run — smallest active location unavailable | permanent_holdings | 856 | Not run | Not run | Not run | Not run | Not run | `1,800,000 ms` not exercised | Not run |

The live test fails closed if a required `marctab.mtNNN` table is absent, a plan touches anything other than the selected `marctab.mtNNN`, the forbidden combined MARC view appears, a plan returns over `100001` rows, or any reported UUID is present in that tag table. It also records whether an in-scope tagged candidate exists for each run. The `100,000`-row workload is **not acceptable to enable yet** because no representative execution has been measured within the configured timeout.

## 100,000-Row Cap and Worker Behavior

| Check | Measured value |
| --- | --- |
| Table-worker sentinel fixture | `QueryWorkerReportCapTest.php` passed: `3` source rows with a test cap of `2` persisted `2` rows and `truncated=true`; exactly `2` source rows persisted `2` and `truncated=false`. |
| File-worker sentinel fixture | `ExportWorkerReportContractTest.php` passed: `3` source rows with a test cap of `2` retained `2` worklist rows and set `truncated=true`. |
| Table/file status parity at production `100000/100001` | Not run — no controlled PostgreSQL fixture or deployed report page is available. |
| Worker memory and streaming at 100,000 rows | Not run — requires a representative live workload. |
| Truncation notice beside inline and file downloads | Offline frontend coverage passed; deployed-page observation not run — requires an authenticated application session and live FOLIO PostgreSQL data. |

## Worklist CSV Verification

| Check | Measured value |
| --- | --- |
| Offline six-column header | `ExportWorkerReportContractTest.php` passed with `Instance UUID`, `Instance HRID`, `Title`, `Selected Location`, `Location Basis`, `Missing MARC Tag`, in that order. |
| Offline retained worklist rows | The same fixture wrote the header plus `2` retained rows from `3` source rows; the sentinel was not written. |
| Small-location page run and direct-SQL comparison | Not run — no live PostgreSQL connection or authenticated report-page session is configured. |
| Downloaded production worklist | Not run — no report-page session is authorized/configured. |
| Warning row absent from a deployed CSV | Not run — requires the controlled live export fixture. |

## FOLIO UUID CSV Verification

| Check | Measured value |
| --- | --- |
| Offline encoding and validation | `FolioIdentifierCsvServiceTest.php` passed: header encoding is exactly `UUID\r\n`; valid UUIDs are normalized; blank, malformed, and non-configured-column values are excluded. |
| Offline identifier worker fixture | `ExportWorkerReportContractTest.php` passed: source duplicates and invalid UUIDs produced one UUID-only preview row and exactly `UUID\r\n11111111-1111-4111-8111-111111111111\r\n`. |
| Deterministic live UUID export | Not run — no live PostgreSQL/report-page session is configured. |
| CRLF/UUID/dedup inspection of a downloaded live file | Not run — no generated live file is available. |

## FOLIO Data Export Smoke Test

| FOLIO release | Data Export profile | Accepted rows | Rejected rows | Exported MARC rows vs valid UUIDs | Measured value |
| --- | --- | ---: | ---: | --- | --- |
| Not run — production authorization required | Not run — production authorization required | Not run — production authorization required | Not run — production authorization required | Not run — production authorization required | No generated UUID file was uploaded to FOLIO Data Export. |

## Required Indexes or Deployment Actions

| Item | Measured value / action |
| --- | --- |
| Required relation availability | Not run live — `marctab.mt245` and `marctab.mt856` must be verified by the opt-in test before enablement. |
| Relevant index names | Not run — no PostgreSQL catalog access is configured. Verify the per-tag `marctab.mtNNN.instance_id` index and Inventory location/join indexes with the captured EXPLAIN plans. |
| Migration deployment action | Resolve Docker migration checksum drift for migrations 029 and 030, rerun audit, then run migration 040 twice and record both results. |
| Performance deployment action | Configure read-only FOLIO PostgreSQL credentials, run the opt-in test for largest and smallest active locations across all six basis/tag combinations, and retain its `MARC_PG_PLAN` output. |
| Operational smoke-test action | Obtain production authorization, generate a small UUID CSV, and run it through the institution's normal FOLIO Data Export profile. |

## Release Decision

**Blocked — do not enable the report in production.** Offline compiler, migration-contract, worker-cap, CSV, backend-suite, frontend-test, and production-build checks have evidence. Release remains blocked because PostgreSQL plan/performance/index evidence is not available, the required 100,000-row live fixture has not run, Docker migration audit has checksum drift and therefore migration runs were not performed, frontend lint cannot execute in this checkout, and the FOLIO Data Export smoke test is `Not run — production authorization required`.
