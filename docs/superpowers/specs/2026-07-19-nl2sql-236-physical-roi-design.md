# NL2SQL-236 Physical Acquisitions and Circulation ROI Design

## Goal

Make the documented five-year call-number ROI request produce a deterministic, presentation-ready report whose purchases, spending, physical holdings, circulation, and ROI measures share a defensible cohort and cannot be multiplied by one-to-many joins.

The motivating request is:

> Show me which call numbers we have purchased the most from the last 5 years. Compare circulation data to those call numbers and show the return on investment.

For this request, “purchased most” means invoiced physical copies. Electronic-only resources are excluded because this application has no COUNTER or comparable electronic-usage data. Explicit physical formats such as DVD remain supported.

## Selected approach

Harden the existing deterministic exploratory ROI compiler. Do not promote the report into the canonical query-family artifacts during this task. The hardened compiler is selected through a dedicated runtime rollback flag, while the current compiler remains available for immediate rollback.

This approach fixes the report users need now while avoiding the schema caches, table mappings, and canonical query-family contracts being changed by separate work.

## Data contract

### Paid acquisitions

Aggregate invoice-line fund distributions at PO-line grain before joining inventory or circulation. Include only paid invoices whose `payment_date` falls in the requested reporting window. Require the Smith `SC` acquisitions unit and a positive physical quantity on the PO line.

For each qualifying PO line, return:

- paid fund-distribution spending;
- invoiced physical-copy quantity;
- the linked instance;
- currency, retained as a grouping boundary so unlike currencies are never summed together.

The report must not infer paid status from a closed purchase order or use order date as the purchase date.

### Physical item linkage

Prefer exact receiving linkage through `orders.pieces__t.po_line_id` and `orders.pieces__t.item_id`. An exact-linked item is eligible only when it is a current Smith item.

When fewer eligible exact item links exist than the invoiced physical-copy quantity, use the PO line's instance to locate current Smith physical items for the unmatched quantity. For each PO line:

- exact-linked copies equal the lesser of distinct eligible linked item IDs and invoiced physical-copy quantity;
- fallback-linked copies equal the remaining invoiced physical-copy quantity when the instance has a current eligible Smith physical item;
- a PO line with neither an eligible exact item nor an eligible fallback instance is excluded from the physical cohort.

Tag each allocated copy with one linkage method:

- `exact_item`;
- `instance_fallback`.

The fallback preserves useful coverage but is never presented as exact. Results include exact-linked copies, fallback-linked copies, and fallback percentage. The user-facing explanation states the coverage in plain language.

### Current Smith physical eligibility

An eligible inventory item must currently resolve through its effective location, library, and campus to Smith College. A PO line must have positive physical quantity and must not be electronic-only. No default `book` material-type filter is allowed.

If the prompt explicitly requests a physical format such as DVD, apply the resolved physical material-type restriction. Without an explicit format, all eligible physical formats are included.

Other Five Colleges campuses cannot contribute acquisitions, items, classes, or circulation to the report.

### Call-number classification

Normalize each eligible item's effective call number into exactly one class:

- LC: one to three leading letters immediately followed by a digit, normalized to uppercase letters;
- Dewey: leading numeric values grouped into their three-digit hundred;
- `Local/Other`: non-empty call numbers that do not match LC or Dewey, including labels such as `Online`, `Art`, and `Periodical`;
- `Unclassified`: null or blank call numbers.

For an instance with multiple eligible Smith item classes, choose the most frequent class. Break count ties alphabetically. This dominant-class rule ensures that each title and acquisition contributes to only one final class.

### Circulation

Count distinct checkout loans from `circulation.audit_loan__t` using the approved checkout actions and `loan__loan_date` within the same reporting window as purchases. Aggregate circulation at item grain and then instance grain before joining acquisitions.

Only circulation for current eligible Smith physical items in the report cohort is counted. Duplicate audit actions for the same loan must not create extra checkouts.

### Final measures

Group by currency and primary call-number class. Return numeric values for:

- physical copies purchased;
- distinct titles;
- paid spending;
- checkouts;
- checkouts per dollar;
- cost per checkout;
- exact-linked copies;
- fallback-linked copies;
- fallback percentage.

Division by zero returns `NULL`, not an error or misleading zero. Sort by physical copies descending, paid spending descending, then class ascending before applying the row limit.

## User experience

The user asks the question normally. Ask AI recognizes the documented cross-domain ROI contract and invokes the deterministic compiler. Existing SELECT safety, schema-reference validation, semantic conformance, and PostgreSQL preflight remain mandatory before results appear.

Validated results disclose these assumptions in ordinary reporting language:

- physical purchases only;
- Smith acquisitions and current Smith holdings only;
- purchase and circulation activity use the same five-year period;
- purchases use invoice payment date and spending uses paid fund distributions;
- ROI means checkouts per dollar with cost per checkout;
- exact receiving links are preferred and instance fallback coverage is stated.

A representative coverage message is:

> 84% of purchased copies were matched to their exact received item. The remainder used the title record because an exact item link was unavailable.

Users do not see CTE, join-grain, parser, schema-cache, or database terminology.

If the compiler or validation cannot preserve the approved cohort and arithmetic, no results are shown. The existing preserved-request recovery remains available with concise business-language guidance.

## Rollout and rollback

The runtime setting `nl2sql_hardened_physical_roi` selects the hardened compiler and is enabled by default. The hardened response records compiler version `physical_roi_v2`. Disabling the setting restores the existing compiler without a migration or data change. Administrator diagnostics record the compiler version and linkage coverage; ordinary responses expose only the plain-language assumptions and coverage statement.

This task does not modify:

- `backend/data/column_cache.json`;
- `backend/data/subtable_cache.json`;
- `backend/data/table_mapping_cache.json`;
- canonical query-family contracts or graph artifacts;
- database schema or migrations.

## Error handling

- Missing required cached tables or columns fails during automated tests and validation, not after user execution.
- Unlike currencies remain separate unless a documented conversion is added in a future task.
- A low exact-linkage percentage does not block the report; it is disclosed.
- An unsafe, structurally invalid, semantically nonconforming, or preflight-failing query is never returned as a validated result.
- Unsupported documented-assumption variants continue through the existing exploratory path rather than being silently compiled with default semantics.
- No raw SQL, database error, schema identifier, or internal exception is added to ordinary failure messages.

## Testing strategy

Use compiler fixtures that validate the relational plan and numeric semantics, not only isolated SQL substrings.

Required cases:

- exact piece-to-item linkage contributes once;
- missing piece linkage uses and discloses the instance fallback;
- electronic-only PO lines are excluded;
- physical books and DVDs are included by default;
- an explicit DVD request narrows the physical cohort;
- non-Smith acquisitions and current non-Smith items are excluded;
- multiple fund distributions cannot multiply copies, titles, or circulation;
- multiple items and audit events cannot multiply spending;
- duplicate audit actions with one loan ID count once;
- mixed-case LC classes normalize to uppercase;
- Dewey numbers group by hundred;
- arbitrary text prefixes become `Local/Other`;
- blank call numbers become `Unclassified`;
- the dominant-class rule and alphabetical tie-break assign one class per instance;
- zero spending and zero circulation produce safe numeric `NULL` ROI values;
- equivalent prompt wording selects equivalent semantics;
- unsupported assumption variants are not silently forced through this compiler.

Verification gates:

- focused compiler, routing, rollback-flag, and semantic-conformance tests;
- cached-schema physical-column audit;
- PostgreSQL preflight when production-like credentials are available;
- full backend test suite;
- existing Ask AI golden-route tests;
- artifact audit proving excluded schema and canonical-family files are untouched;
- saved production execution of the motivating prompt, including SQL, results, linkage coverage, and a rollback-flag check.

## Success criteria

- The motivating five-year ROI request returns validated results without manual SQL correction.
- Electronic resources and other institutions' acquisitions or circulation cannot enter the cohort.
- Copies, titles, spending, and circulation remain additive and are not multiplied by joins.
- Every title contributes to one deterministic class.
- Exact versus fallback linkage is measurable and understandable to a normal reporting user.
- The hardened behavior can be disabled immediately without reverting schema or data.
