# Instances Without Holdings — Cataloging Review

Implemented by migrations `045_cataloging_instances_without_holdings.sql`,
`046_cataloging_holdingsless_marker_patterns.sql`,
`047_cataloging_holdingsless_exclusion_match.sql`, and
`CatalogingHoldingslessReportService`. The existing Cataloging report dispatcher,
CSV worker, 100,000-instance cap / 100,001-row sentinel, and UUID export are reused.

## Behavior

- Zero holdings **anywhere**, including holdings without items and suppressed holdings.
- All instance sources. All colleges includes missing, blank, or unrecognized ownership.
- Independent 998$a ownership; no item/location joins. Named mappings: SM Smith,
  AM Amherst, MH Mount Holyoke, UM Massachusetts, HA Hampshire, YB Yiddish Book Center.
  DP and RC are offered as codes without assigning an institution.
- Defaults: All colleges, exclude both suppression flags, no marker exclusions.
- Four suppression choices: include all, exclude discovery, exclude staff, exclude both.
  Unset flags are retained as not suppressed; raw flags remain visible in exports.
- Optional comma-separated 035$a prefixes / patterns; case-insensitive, ignoring
  leading spaces. Bare codes match with or without an opening parenthesis. `*`
  matches any sequence of characters; only a leading `*` allows a match elsewhere
  in the value. Trailing content is always allowed. `%`, `_`, and `!` remain literal.
  By default any matching row excludes the whole instance. The optional all-pattern
  rule excludes only instances matching every entered pattern across their 035$a
  fields. Empty exclusions exclude nothing in either mode. Patterns are bound parameters and
  do not imply institution ownership. Original inputs remain in the evidence column.
- Evidence includes UUID, HRID, title, source, 998$a and 035$a values, both suppression
  flags, selected filters, and the exclusion rule. MARC links use UUIDs, preserving null HRIDs.
- LKRS membership and missing child relationships are not inferred by this report.

Smith staff's verified request: select Smith / SM, exclude both suppression flags,
enter `SMHASLKR, SMISLKR` (parentheses also work) in the optional exclusion field,
and keep the default **Exclude if any pattern matches** rule.
`SM*LKR` is an explicit broader alternative matching both Smith markers. For consortium-wide
triage, select All colleges and leave prefixes empty; include suppressed records
explicitly when conducting a full audit.

## Live PostgreSQL verification

Read-only transactions against the configured reporting database, with a 120-second
statement timeout. These counts are observations, not hard-coded test expectations.

| Case | Returned / distinct instances | Seconds | Missing ownership marker |
| --- | ---: | ---: | ---: |
| Smith, exclude both flags, exclude the two Smith prefixes | 30 / 30 | 71.685 | 0 |
| Amherst, exclude both flags, no prefixes | 6,396 / 6,396 | 50.489 | 0 |
| All colleges, exclude both flags, no prefixes | 27,329 / 27,329 | 22.589 | 781 |

An initial query shape timed out because PostgreSQL planned global MARC membership
scans. The final SQL first materializes the holding-less/suppression scope, then
uses correlated MARC probes with OFFSET 0 to prevent those probes being converted
into global hashed subplans. Evidence aggregation happens only for retained rows.
Runs covering every suppression mode at consortium scale have not been benchmarked.

Ownership labels were cross-checked using 100-row MARC samples joined to holdings
campuses: AM had Amherst holdings in 98 sampled records; MH 100; UM 97; HA 100;
YB 100. Shared records also had holdings at other campuses. DP/RC associations
were insufficient to assign an institution label. Smith's SM convention was
verified in the earlier staff-query investigation.

## Ask AI

Holding-less prompts now use the SQL generation route that supports anti-joins.
Shared cataloging guidance replaces contradictory positive holdings/location
scoping, and is included in schema context used for generation and repair.
Visible interpretations distinguish no holdings anywhere from no holdings at an
institution, and record suppression behavior. Unspecified suppression includes all;
plain “not suppressed” documents exclusion of both flags and the discovery-only
alternative. Positive/mixed suppression requests remain explicitly requested.
Guidance preserves record-level marker absence and flags undefined LKRS/child scope.
These changes improve prompting and interpretation; they are not a new deterministic
semantic validator for arbitrary generated cataloging SQL. The fixed report has
its own exact seed and parameter contract.

## Verification and installation

- Regression fixtures execute report SQL in SQLite with PostgreSQL-only function
  adaptations: ownership, null HRID/flags, suppressed holdings, duplicate marker rows,
  literal prefixes, evidence, validation, template drift and UUID export contracts.
- Existing cataloging, migration, controller, export-worker, schema prompt and
  generation guidance tests pass alongside the new focused tests.
- Report form and MARC parameter tests: 17 passed. Frontend production build passed.
- Migration applied **twice** in an isolated local MySQL database: exactly one report
  row after each run; JSON metadata matched the compiler's canonical contract. The
  temporary database was then removed.
- Local Docker activation completed on 2026-09-14. Before changing the database,
  a full application database dump was saved with owner-only permissions at
  `/private/tmp/folio-report-explorer-migration-backup-20260914/before-reconcile.sql`.
  The directory also contains the original migration ledger and reconciliation log.
- Migrations 029 and 030 had been edited by commit
  `dd30476424d90857271b630c3e8f29c45faa65c2` solely to add `FROM DUAL` to guarded
  inserts for MariaDB compatibility. Their recorded hashes exactly matched the
  pre-commit files; removing only those added lines reproduced both recorded hashes.
  Only those two local ledger checksums were updated, in one transaction with
  expected-old-checksum guards. Migration SQL and the checksum enforcement remain intact.
- Normal `docker compose exec -T php php yii migration/run` applied 038, 040,
  042–045 and recognized existing effects for 039 and 041. Audit then showed zero
  unapplied migrations, changed checksums, or duplicate numbers. A second run
  skipped all 44 migration files without changes. The audit still lists static
  idempotence warnings for ten older migrations, unrelated to the resolved drift.
- The first live HTTP run exposed a Yii integration issue: MySQL ActiveRecord
  returns JSON columns as arrays. The validator now accepts both decoded arrays
  and JSON strings, with the same strict content contract. A regression test
  failed before the fix and passes afterward; changed decoded metadata is rejected.
  The actual installed ActiveRecord also passes validation.
- All six Docker services are running in `folio-report-explorer-main-clean`.
  The app is at `http://localhost:8080`; the new report is local report ID 40,
  at `/reports/40`. All three Cataloging reports are listed by the API.
- The Smith request completed through HTTP and the export worker in approximately
  31 seconds. Its downloaded CSV contains 30 rows / 30 distinct instance UUIDs,
  matching the earlier read-only verification. Both suppression flags in the
  returned preview are false. Job: `d17c3ca9-549a-40d2-96e5-562b8ea18088`.
- The frontend route, report metadata, normal run endpoint, job completion, and CSV
  download were checked. Visual browser inspection was unavailable because the
  browser runtime failed during initialization (`node:process` import rejected).
- This installation and history repair apply only to the local Docker database.
  Other environments still need their own migration audit and normal deployment.

## Marker input follow-up (migration 046)

The staff run excluding only `(SMHASLKR)` returned 8,392 instances: zero contained
that marker, 8,362 contained `(SMISLKR)`, and 30 contained neither. The preceding
bare-code run returned 8,397 because literal matching missed the five SMHASLKR
instances. These counts were verified from the completed CSV exports.

Migration 046 updates the SQL and input help without editing migration 045.
The compiler retains the normalized user input in `:excluded035Prefixes` for
evidence and supplies escaped LIKE patterns separately in `:excluded035Patterns`.
An optional parenthesized variant is added for bare prefixes; `*` becomes a SQL
wildcard, and SQL wildcard / escape punctuation is escaped. The SQL still excludes
an instance if any 035$a matches, with no change to holdings or suppression scope.

Regression tests cover bare codes, explicit parentheses, internal / leading
wildcards, anchored matching, literal punctuation, repeated 035 rows, and empty
exclusions. The report, migration, controller, and export contract tests pass;
all 11 report-page tests pass. Migration 046 was installed locally after a private
backup (`before-046.sql` in the backup directory above). Audit shows no drift or
unapplied migrations; a repeat run skips all 45 files.

Live Docker API / export-worker runs with Smith ownership and exclusion of both
suppression flags produced the following downloaded CSVs:

| Exclusion input | Rows / unique instances | SMHASLKR present | SMISLKR present |
| --- | ---: | ---: | ---: |
| `SMHASLKR` | 8,392 / 8,392 | 0 | 8,362 |
| `SMHASLKR, SMISLKR` | 30 / 30 | 0 | 0 |
| `SM*LKR` | 30 / 30 | 0 | 0 |

The explicit two-code and wildcard runs returned identical UUID sets. Broader
wildcard patterns intentionally may exclude additional markers.

## Any / all exclusion rule (migration 047)

The report now exposes `035$a exclusion rule`, defaulting to **Exclude if any
pattern matches**. **Exclude only if all patterns match** retains an instance if
at least one entered pattern has no match anywhere among its 035$a fields. Matching
every pattern across separate fields excludes the instance. The selected rule is
included in the worklist CSV as `035$a Exclusion Rule`.

Each entered pattern now produces one bound LIKE pattern. Its optional opening
parenthesis is handled as an alternative within the matching predicate, so it
cannot accidentally become a separate requirement in all-pattern mode. Duplicate
patterns and single wildcard patterns behave consistently in both modes. Omitting
the new parameter preserves the previous any-pattern default.

Regression tests cover records with neither marker, either marker, both in separate
fields, repeated matching fields, equivalent patterns, empty exclusions, invalid
modes, and CSV rule evidence. The 11 report-page tests verify the default selector
and submission of the all-pattern choice. Backend report, migration, controller,
export-worker and execution-contract checks pass.

Migration 047 was applied to local Docker after saving `before-047.sql` in the
private backup directory. Earlier migration files were not edited. The audit shows
zero pending migrations or checksum changes; a repeat run skips all 46 files.
The report remains at `http://localhost:8080/reports/40`.

Live HTTP / export-worker verification with Smith ownership and exclusion of both
suppression flags:

| Patterns | Rule | CSV rows | Marker evidence | Seconds |
| --- | --- | ---: | --- | ---: |
| `SMHASLKR, SMISLKR` | Any | 30 | Neither marker on all 30 instances | 15.282 |
| `SMHASLKR, SMISLKR` | All | 8,397 | 5 SMHASLKR only, 8,362 SMISLKR only, 30 neither; zero both | 23.169 |
| `SM*LKR` | All | 30 | Neither marker; a single wildcard is one pattern | 25.767 |

Each downloaded CSV contains the correct selected-rule label. The separate-field
both-markers exclusion is covered by the regression fixture because these live
Smith candidates contain no instance with both markers.
