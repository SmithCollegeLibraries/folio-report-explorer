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
2026-05-12 19:12:00 [192.168.65.1][-][-][info][nl2sql.telemetry] NL2SQL telemetry: {"event":"nl2sql.shadow_compare","timestamp":"2026-05-12T19:12:00+00:00","promptFingerprint":"abc123","primaryMode":"intent","primaryRoute":"builder_intent","primaryRouteReason":"family_contract_supported:inventory_collection_age","shadowMode":"legacy","shadowRoute":"legacy_freeform","shadowRouteReason":"forced_legacy_mode","sqlHashMatch":false,"sqlComparisonMethod":"semantic_sql_signature","sqlComparisonMatch":true,"primaryDataSource":"folio","shadowDataSource":"folio","primarySqlLength":785,"shadowSqlLength":791}
2026-05-12 19:12:01 [192.168.65.1][-][-][info][nl2sql.telemetry] NL2SQL telemetry: {"event":"nl2sql.generated","timestamp":"2026-05-12T19:12:01+00:00","route":"builder_intent","routeReason":"family_contract_supported:inventory_collection_age","promptFingerprint":"abc123","dataSource":"folio","slotProvenance":{"library":"prompt_explicit","campus":"default_context","location":"policy_omitted_explicit_prompt_only"}}
2026-05-12 19:12:02 [192.168.65.1][-][-][info][nl2sql.telemetry] NL2SQL telemetry: {"event":"nl2sql.generated","timestamp":"2026-05-12T19:12:02+00:00","route":"builder_intent","routeReason":"family_contract_supported:inventory_collection_age","promptFingerprint":"def456","dataSource":"folio","slotProvenance":{"library":"prompt_repaired","location":"prompt_explicit"}}
2026-05-12 19:12:03 [192.168.65.1][-][-][info][nl2sql.telemetry] NL2SQL telemetry: {"event":"nl2sql.generated","timestamp":"2026-05-12T19:12:03+00:00","route":"clarification","routeReason":"family_slot_missing_required_slot","promptFingerprint":"ghi789","dataSource":null,"missingSlots":["library"],"slotProvenance":{"location_code":"model_output"}}
2026-05-12 19:12:04 [192.168.65.1][-][-][warning][nl2sql.telemetry] NL2SQL telemetry: {"event":"nl2sql.validation_failure","timestamp":"2026-05-12T19:12:04+00:00","stage":"family_contract_mismatch","promptFingerprint":"jkl012","slotProvenance":{"library":"model_output"}}
2026-05-12 19:12:05 [192.168.65.1][-][-][warning][nl2sql.telemetry] NL2SQL telemetry: {"event":"nl2sql.validation_failure","timestamp":"2026-05-12T19:12:05+00:00","stage":"family_fallback_guard","promptFingerprint":"jkl012","slotProvenance":{"library":"model_output"}}
EOF

bash "${REPO_ROOT}/planning/baseline/report_nl2sql_shadow_metrics.sh" "${DATE_FILTER}" "${LOG_FILE}" >/dev/null

if ! grep -Fq -- "## Slot Provenance Signals" "${REPORT_FILE}"; then
  echo "Expected the shadow metrics report to include a slot provenance section." >&2
  cat "${REPORT_FILE}" >&2
  exit 1
fi

if ! grep -Fq -- "- builder_intent generated events with slot provenance: 2" "${REPORT_FILE}"; then
  echo "Expected builder_intent generated events with slot provenance to be counted in the report summary." >&2
  cat "${REPORT_FILE}" >&2
  exit 1
fi

if ! grep -Fq -- "- clarification generated events with slot provenance: 1" "${REPORT_FILE}"; then
  echo "Expected clarification generated events with slot provenance to be counted in the report summary." >&2
  cat "${REPORT_FILE}" >&2
  exit 1
fi

if ! grep -Fq -- "- validation events with slot provenance: 2" "${REPORT_FILE}"; then
  echo "Expected validation warnings with slot provenance to be counted in the report summary." >&2
  cat "${REPORT_FILE}" >&2
  exit 1
fi

if ! grep -Fq -- "- 1 location = policy_omitted_explicit_prompt_only" "${REPORT_FILE}"; then
  echo "Expected policy-driven location omissions to appear in the slot provenance breakdown." >&2
  cat "${REPORT_FILE}" >&2
  exit 1
fi

if ! grep -Fq -- "- 1 library = prompt_explicit" "${REPORT_FILE}"; then
  echo "Expected explicit prompt-derived library scope to appear in the slot provenance breakdown." >&2
  cat "${REPORT_FILE}" >&2
  exit 1
fi

if ! grep -Fq -- "- 1 library = prompt_repaired" "${REPORT_FILE}"; then
  echo "Expected prompt-repaired library scope to appear in the slot provenance breakdown." >&2
  cat "${REPORT_FILE}" >&2
  exit 1
fi

if ! grep -Fq -- "- 1 generated.clarification / location_code = model_output" "${REPORT_FILE}"; then
  echo "Expected clarification slot provenance to appear in the slot provenance breakdown." >&2
  cat "${REPORT_FILE}" >&2
  exit 1
fi

if ! grep -Fq -- "- 1 validation.family_contract_mismatch / library = model_output" "${REPORT_FILE}"; then
  echo "Expected family contract mismatch slot provenance to appear in the slot provenance breakdown." >&2
  cat "${REPORT_FILE}" >&2
  exit 1
fi

if ! grep -Fq -- "- 1 validation.family_fallback_guard / library = model_output" "${REPORT_FILE}"; then
  echo "Expected family fallback guard slot provenance to appear in the slot provenance breakdown." >&2
  cat "${REPORT_FILE}" >&2
  exit 1
fi

echo "Shadow metrics slot provenance report test passed"