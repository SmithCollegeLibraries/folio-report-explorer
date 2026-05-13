#!/usr/bin/env bash
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
DATE_FILTER="2026-05-12"
LOG_FILE="$(mktemp)"
REPORT_FILE="${REPO_ROOT}/planning/baseline/reports/${DATE_FILTER}_nl2sql-008-shadow-metrics.md"

cleanup() {
  rm -f "${LOG_FILE}"
  rm -f "${REPORT_FILE}"
}
trap cleanup EXIT

cat > "${LOG_FILE}" <<'EOF'
2026-05-12 18:54:03 [192.168.65.1][-][-][info][nl2sql.telemetry] NL2SQL telemetry: {"event":"nl2sql.shadow_compare","timestamp":"2026-05-12T18:54:03+00:00","promptFingerprint":"abc123","primaryMode":"intent","primaryRoute":"builder_intent","primaryRouteReason":"family_contract_supported:inventory_collection_age","shadowMode":"legacy","shadowRoute":"legacy_freeform","shadowRouteReason":"shadow_mode_enabled","sqlHashMatch":false,"sqlComparisonMethod":"semantic_sql_signature","sqlComparisonMatch":true,"primaryDataSource":"folio","shadowDataSource":"folio","primarySqlLength":788,"shadowSqlLength":794}
2026-05-12 18:54:04 [192.168.65.1][-][-][warning][nl2sql.telemetry] NL2SQL telemetry: {"event":"nl2sql.provider_fallback","timestamp":"2026-05-12T18:54:04+00:00","context":"generateSql","sourceProvider":"gemini","targetProvider":"openai","reasonCode":"quota_exhausted","statusCode":429}
2026-05-12 18:54:05 [192.168.65.1][-][-][warning][nl2sql.provider_fallback] Gemini quota exhausted — falling back to OpenAI for this request.
EOF

bash "${REPO_ROOT}/planning/baseline/report_nl2sql_shadow_metrics.sh" "${DATE_FILTER}" "${LOG_FILE}" >/dev/null

if ! grep -Fq -- "- Provider fallback warning count: 2" "${REPORT_FILE}"; then
  echo "Expected structured and legacy provider fallback events to both contribute to the total fallback count." >&2
  cat "${REPORT_FILE}" >&2
  exit 1
fi

if ! grep -Fq -- "## Provider Fallback Reasons" "${REPORT_FILE}"; then
  echo "Expected the shadow metrics report to include a provider fallback reason section." >&2
  cat "${REPORT_FILE}" >&2
  exit 1
fi

if ! grep -Fq -- "- 1 legacy_unstructured" "${REPORT_FILE}"; then
  echo "Expected raw legacy warning lines to remain visible under a legacy_unstructured provider fallback bucket." >&2
  cat "${REPORT_FILE}" >&2
  exit 1
fi

if ! grep -Fq -- "- 1 quota_exhausted" "${REPORT_FILE}"; then
  echo "Expected structured provider fallback reason codes to appear in the report breakdown." >&2
  cat "${REPORT_FILE}" >&2
  exit 1
fi

echo "Shadow metrics provider fallback report test passed"