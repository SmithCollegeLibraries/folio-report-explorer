# Local Reference Cache Operations

The local reference cache stores stable FOLIO lookup tables in MySQL so NL2SQL can resolve local terms before prompt generation.

## Setup

Run the migration:

```bash
mysql "$MYSQL_DATABASE" < mysql/migrations/032_folio_reference_cache.sql
```

The migration is idempotent. It creates the reference-cache tables and safely patches `ai_clarification_events` for batched clarification metadata.

## Discover Candidates

Inspect the FOLIO PostgreSQL backend and record small/reference-shaped tables:

```bash
php backend/yii reference-cache/discover-candidates
```

Review disabled candidates:

```bash
php backend/yii reference-cache/review-candidates
```

Admins can also review candidates in Settings under Local Reference Cache.

## Refresh

Refresh all enabled reference tables:

```bash
php backend/yii reference-cache/refresh
```

Refresh one enabled table:

```bash
php backend/yii reference-cache/refresh --table=inventory.location__t
```

The Settings page can also refresh one enabled table immediately.

## Nightly Cron

Recommended nightly job:

```bash
php /path/to/app/backend/yii reference-cache/discover-candidates
php /path/to/app/backend/yii reference-cache/refresh
```

Discovery is safe to rerun. It preserves enabled tables and updates candidate size/classification metadata.

## Review Rules

Enable only tables that are stable reference data. The API validates enabled candidates before allowing review:

- the FOLIO table must have `id`
- it must have one safe label column: `name`, `label`, `value`, `display_name`, or `description`
- optional code columns are limited to `code`, `key`, or `slug`

Rejected candidates are marked `do_not_cache`.

## Status

CLI:

```bash
php backend/yii reference-cache/status
```

API:

```http
GET /api/reference-cache/status
GET /api/reference-cache/candidates
POST /api/reference-cache/candidates/review
POST /api/reference-cache/refresh
```

## Pre-Commit Verification

Run before committing reference-cache changes:

```bash
for test in backend/tests/*Test.php; do php "$test" || exit 1; done
cd frontend && npm test -- --run
cd frontend && npm run build
```

Verify migration idempotency:

```bash
docker compose exec -T mysql mysql -uroot -prootpass folio_reports < mysql/migrations/032_folio_reference_cache.sql
docker compose exec -T mysql mysql -uroot -prootpass folio_reports < mysql/migrations/032_folio_reference_cache.sql
```
