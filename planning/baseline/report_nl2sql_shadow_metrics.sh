#!/usr/bin/env bash
set -euo pipefail

# Build a daily NL2SQL-008 shadow-mode metrics report from app telemetry logs.
# Usage:
#   ./planning/baseline/report_nl2sql_shadow_metrics.sh [YYYY-MM-DD] [LOG_FILE]
# Example:
#   ./planning/baseline/report_nl2sql_shadow_metrics.sh 2026-04-06

DATE_FILTER="${1:-$(date +%Y-%m-%d)}"
LOG_FILE="${2:-backend/runtime/logs/app.log}"
OUT_DIR="planning/baseline/reports"
OUT_FILE="${OUT_DIR}/${DATE_FILTER}_nl2sql-008-shadow-metrics.md"

if [[ ! -f "${LOG_FILE}" ]]; then
  echo "Error: log file not found: ${LOG_FILE}" >&2
  exit 1
fi

if ! command -v jq >/dev/null 2>&1; then
  echo "Error: jq is required." >&2
  exit 1
fi

mkdir -p "${OUT_DIR}"

emit_breakdown_rows() {
  local rows="${1:-}"
  if [[ -n "${rows}" ]]; then
    while IFS= read -r row; do
      row="$(printf '%s' "${row}" | sed -E 's/^[[:space:]]+//')"
      echo "- ${row}"
    done <<< "${rows}"
  else
    echo "- None"
  fi
}

tmp_jsonl="$(mktemp)"
tmp_provider_fallback_jsonl="$(mktemp)"
trap 'rm -f "${tmp_jsonl}" "${tmp_provider_fallback_jsonl}"' EXIT

awk -v d="${DATE_FILTER}" '
  index($0, d) == 1 && $0 ~ /NL2SQL telemetry:/ {
    sub(/^.*NL2SQL telemetry: /, "");
    print;
  }
' "${LOG_FILE}" > "${tmp_jsonl}"

line_count="$(jq -r 'select(.event == "nl2sql.shadow_compare" or .event == "nl2sql.shadow_error") | 1' "${tmp_jsonl}" | wc -l | tr -d ' ')"

if [[ "${line_count}" -eq 0 ]]; then
  cat > "${OUT_FILE}" <<EOF
# NL2SQL-008 Shadow Metrics Report

- Date: ${DATE_FILTER}
- Log file: ${LOG_FILE}

## Summary
- No shadow telemetry events were found for this date.

## Gate Worksheet
- Required period day status: BLOCKED_NO_SHADOW_TELEMETRY
- Compare/error trend acceptable: NO
- Covered-family legacy-primary mismatches acceptable: UNKNOWN
- Rollback exercised recently: TODO

## Next Action
- Ensure shadow mode is enabled for the test cohort before collecting daily metrics.
EOF
  echo "Shadow metrics report written: ${OUT_FILE}"
  exit 0
fi

compare_count="$(jq -r 'select(.event == "nl2sql.shadow_compare") | 1' "${tmp_jsonl}" | wc -l | tr -d ' ')"
error_count="$(jq -r 'select(.event == "nl2sql.shadow_error") | 1' "${tmp_jsonl}" | wc -l | tr -d ' ')"

jq -rc 'select(.event == "nl2sql.provider_fallback")' "${tmp_jsonl}" > "${tmp_provider_fallback_jsonl}" || true

comparison_match_count="$(jq -r '
  select(.event == "nl2sql.shadow_compare")
  | (if has("sqlComparisonMatch") then .sqlComparisonMatch else .sqlHashMatch end)
  | select(. == true)
  | 1
' "${tmp_jsonl}" | wc -l | tr -d ' ')"
comparison_mismatch_count="$(jq -r '
  select(.event == "nl2sql.shadow_compare")
  | (if has("sqlComparisonMatch") then .sqlComparisonMatch else .sqlHashMatch end)
  | select(. == false)
  | 1
' "${tmp_jsonl}" | wc -l | tr -d ' ')"
comparison_unknown_count="$(jq -r '
  select(.event == "nl2sql.shadow_compare")
  | (if has("sqlComparisonMatch") then .sqlComparisonMatch else .sqlHashMatch end)
  | select(. == null)
  | 1
' "${tmp_jsonl}" | wc -l | tr -d ' ')"

raw_hash_match_count="$(jq -r 'select(.event == "nl2sql.shadow_compare" and .sqlHashMatch == true) | 1' "${tmp_jsonl}" | wc -l | tr -d ' ')"
raw_hash_mismatch_count="$(jq -r 'select(.event == "nl2sql.shadow_compare" and .sqlHashMatch == false) | 1' "${tmp_jsonl}" | wc -l | tr -d ' ')"
raw_hash_unknown_count="$(jq -r 'select(.event == "nl2sql.shadow_compare" and .sqlHashMatch == null) | 1' "${tmp_jsonl}" | wc -l | tr -d ' ')"

route_divergence_count="$(jq -r '
  select(.event == "nl2sql.shadow_compare")
  | select((.primaryRoute // "") != (.shadowRoute // ""))
  | 1
' "${tmp_jsonl}" | wc -l | tr -d ' ')"

covered_family_legacy_primary_mismatch_count="$(jq -r '
  select(.event == "nl2sql.shadow_compare")
  | select((.primaryRoute // "") == "legacy_freeform")
  | select((.shadowRoute // "") == "builder_intent")
  | select((.shadowRouteReason // "") | startswith("family_contract_supported:"))
  | 1
' "${tmp_jsonl}" | wc -l | tr -d ' ')"

datasource_mismatch_count="$(jq -r '
  select(.event == "nl2sql.shadow_compare")
  | select((.primaryDataSource // "") != "" and (.shadowDataSource // "") != "")
  | select(.primaryDataSource != .shadowDataSource)
  | 1
' "${tmp_jsonl}" | wc -l | tr -d ' ')"

structured_provider_fallback_count="$(wc -l < "${tmp_provider_fallback_jsonl}" | tr -d ' ')"
legacy_provider_fallback_count="$(awk -v d="${DATE_FILTER}" '
  index($0, d) == 1 && $0 ~ /\[nl2sql\.provider_fallback\]/ && $0 !~ /NL2SQL telemetry:/ {
    count++
  }
  END {
    print count + 0
  }
' "${LOG_FILE}")"
provider_fallback_count="$((structured_provider_fallback_count + legacy_provider_fallback_count))"

slot_provenance_generated_count="$(jq -r '
  select(.event == "nl2sql.generated")
  | select((.route // "") == "builder_intent")
  | select((.slotProvenance | type) == "object")
  | select((.slotProvenance | length) > 0)
  | 1
' "${tmp_jsonl}" | wc -l | tr -d ' ')"

slot_provenance_clarification_count="$(jq -r '
  select(.event == "nl2sql.generated")
  | select((.route // "") == "clarification")
  | select((.slotProvenance | type) == "object")
  | select((.slotProvenance | length) > 0)
  | 1
' "${tmp_jsonl}" | wc -l | tr -d ' ')"

slot_provenance_validation_count="$(jq -r '
  select(.event == "nl2sql.validation_failure")
  | select((.slotProvenance | type) == "object")
  | select((.slotProvenance | length) > 0)
  | 1
' "${tmp_jsonl}" | wc -l | tr -d ' ')"

if [[ "${compare_count}" -gt 0 ]]; then
  comparison_match_rate="$(jq -n --arg m "${comparison_match_count}" --arg c "${compare_count}" '((($m | tonumber) * 100) / ($c | tonumber))')"
  comparison_mismatch_rate="$(jq -n --arg m "${comparison_mismatch_count}" --arg c "${compare_count}" '((($m | tonumber) * 100) / ($c | tonumber))')"
  raw_hash_match_rate="$(jq -n --arg m "${raw_hash_match_count}" --arg c "${compare_count}" '((($m | tonumber) * 100) / ($c | tonumber))')"
  raw_hash_mismatch_rate="$(jq -n --arg m "${raw_hash_mismatch_count}" --arg c "${compare_count}" '((($m | tonumber) * 100) / ($c | tonumber))')"
else
  comparison_match_rate="0"
  comparison_mismatch_rate="0"
  raw_hash_match_rate="0"
  raw_hash_mismatch_rate="0"
fi

required_period_day_status="PASS_CANDIDATE"
compare_error_trend_acceptable="YES"
covered_family_mismatches_acceptable="YES"

if [[ "${compare_count}" -eq 0 ]]; then
  required_period_day_status="BLOCKED_NO_SHADOW_COMPARE"
  compare_error_trend_acceptable="NO"
fi

if [[ "${error_count}" -gt 0 ]]; then
  required_period_day_status="BLOCKED_SHADOW_ERRORS"
  compare_error_trend_acceptable="NO"
fi

if [[ "${datasource_mismatch_count}" -gt 0 ]]; then
  required_period_day_status="BLOCKED_DATA_SOURCE_MISMATCH"
  compare_error_trend_acceptable="NO"
fi

if [[ "${covered_family_legacy_primary_mismatch_count}" -gt 0 ]]; then
  required_period_day_status="BLOCKED_COVERED_FAMILY_LEGACY_PRIMARY_MISMATCH"
  compare_error_trend_acceptable="NO"
  covered_family_mismatches_acceptable="NO"
fi

error_breakdown="$(jq -r 'select(.event == "nl2sql.shadow_error") | (.error // "unknown")' "${tmp_jsonl}" | sort | uniq -c | sort -nr | head -n 5 || true)"
route_pair_breakdown="$(jq -r '
  select(.event == "nl2sql.shadow_compare")
  | ((.primaryRoute // "unknown") + " -> " + (.shadowRoute // "unknown"))
' "${tmp_jsonl}" | sort | uniq -c | sort -nr | head -n 5 || true)"

covered_family_breakdown="$(jq -r '
  select(.event == "nl2sql.shadow_compare")
  | select((.primaryRoute // "") == "legacy_freeform")
  | select((.shadowRoute // "") == "builder_intent")
  | select((.shadowRouteReason // "") | startswith("family_contract_supported:"))
  | (.shadowRouteReason | sub("^family_contract_supported:"; ""))
' "${tmp_jsonl}" | sort | uniq -c | sort -nr | head -n 5 || true)"

provider_fallback_reason_breakdown="$({
  jq -r '.reasonCode // "unknown"' "${tmp_provider_fallback_jsonl}" || true
  if [[ "${legacy_provider_fallback_count}" -gt 0 ]]; then
    for ((i = 0; i < legacy_provider_fallback_count; i++)); do
      echo "legacy_unstructured"
    done
  fi
} | sort | uniq -c | sort -nr | head -n 5 || true)"

slot_provenance_breakdown="$(jq -r '
  select((.slotProvenance | type) == "object")
  | select((.slotProvenance | type) == "object")
  | .slotProvenance
  | to_entries[]
  | (.key + " = " + (.value | tostring))
' "${tmp_jsonl}" | sort | uniq -c | sort -nr | head -n 10 || true)"

slot_provenance_source_breakdown="$(jq -r '
  select((.slotProvenance | type) == "object")
  | (
      if .event == "nl2sql.generated" then
        "generated." + (.route // "unknown")
      elif .event == "nl2sql.validation_failure" then
        "validation." + (.stage // "unknown")
      else
        (.event // "unknown")
      end
    ) as $source
  | .slotProvenance
  | to_entries[]
  | ($source + " / " + .key + " = " + (.value | tostring))
' "${tmp_jsonl}" | sort | uniq -c | sort -nr | head -n 15 || true)"

latest_compare_json="{}"
if [[ "${compare_count}" -gt 0 ]]; then
  latest_compare_json="$(jq -cs 'map(select(.event == "nl2sql.shadow_compare")) | last // {}' "${tmp_jsonl}")"
fi

latest_covered_family_compare_json="{}"
if [[ "${covered_family_legacy_primary_mismatch_count}" -gt 0 ]]; then
  latest_covered_family_compare_json="$(jq -cs '
    map(
      select(.event == "nl2sql.shadow_compare")
      | select((.primaryRoute // "") == "legacy_freeform")
      | select((.shadowRoute // "") == "builder_intent")
      | select((.shadowRouteReason // "") | startswith("family_contract_supported:"))
    )
    | last // {}
  ' "${tmp_jsonl}")"
fi

{
  echo "# NL2SQL-008 Shadow Metrics Report"
  echo
  echo "- Date: ${DATE_FILTER}"
  echo "- Log file: ${LOG_FILE}"
  echo "- Events scanned: ${line_count}"
  echo
  echo "## Summary"
  echo "- shadow_compare events: ${compare_count}"
  echo "- shadow_error events: ${error_count}"
  echo "- SQL comparison match count: ${comparison_match_count}"
  echo "- SQL comparison mismatch count: ${comparison_mismatch_count}"
  echo "- SQL comparison unknown count: ${comparison_unknown_count}"
  echo "- SQL comparison match rate: $(printf '%.2f' "${comparison_match_rate}")%"
  echo "- SQL comparison mismatch rate: $(printf '%.2f' "${comparison_mismatch_rate}")%"
  echo "- Raw SQL hash match count: ${raw_hash_match_count}"
  echo "- Raw SQL hash mismatch count: ${raw_hash_mismatch_count}"
  echo "- Raw SQL hash unknown count: ${raw_hash_unknown_count}"
  echo "- Raw SQL hash match rate: $(printf '%.2f' "${raw_hash_match_rate}")%"
  echo "- Raw SQL hash mismatch rate: $(printf '%.2f' "${raw_hash_mismatch_rate}")%"
  echo "- Route divergence count: ${route_divergence_count}"
  echo "- Covered-family legacy-primary mismatch count: ${covered_family_legacy_primary_mismatch_count}"
  echo "- Data source mismatch count: ${datasource_mismatch_count}"
  echo "- Provider fallback warning count: ${provider_fallback_count}"
  echo "- builder_intent generated events with slot provenance: ${slot_provenance_generated_count}"
  echo "- clarification generated events with slot provenance: ${slot_provenance_clarification_count}"
  echo "- validation events with slot provenance: ${slot_provenance_validation_count}"
  echo
  echo "## Provider Fallback Reasons"
  emit_breakdown_rows "${provider_fallback_reason_breakdown}"
  echo
  echo "## Slot Provenance Signals"
  emit_breakdown_rows "${slot_provenance_breakdown}"
  echo
  echo "## Slot Provenance Sources"
  emit_breakdown_rows "${slot_provenance_source_breakdown}"
  echo
  echo "## Top Route Pairs"
  emit_breakdown_rows "${route_pair_breakdown}"
  echo
  echo "## Covered-Family Legacy-Primary Divergences"
  emit_breakdown_rows "${covered_family_breakdown}"
  echo
  echo "## Top Shadow Errors"
  emit_breakdown_rows "${error_breakdown}"
  echo
  echo "## Latest Shadow Compare"
  if [[ "${compare_count}" -gt 0 ]]; then
    echo "- Timestamp: $(jq -r '.timestamp // "unknown"' <<< "${latest_compare_json}")"
    echo "- Prompt fingerprint: $(jq -r '.promptFingerprint // "unknown"' <<< "${latest_compare_json}")"
    echo "- Primary mode/route: $(jq -r '(.primaryMode // "unknown") + " / " + (.primaryRoute // "unknown")' <<< "${latest_compare_json}")"
    echo "- Primary route reason: $(jq -r '.primaryRouteReason // "unknown"' <<< "${latest_compare_json}")"
    echo "- Shadow mode/route: $(jq -r '(.shadowMode // "unknown") + " / " + (.shadowRoute // "unknown")' <<< "${latest_compare_json}")"
    echo "- Shadow route reason: $(jq -r '.shadowRouteReason // "unknown"' <<< "${latest_compare_json}")"
    echo "- SQL comparison: $(jq -r '
      ((if has("sqlComparisonMatch") then .sqlComparisonMatch else .sqlHashMatch end) // "unknown") as $match
      | ((.sqlComparisonMethod // "raw_sql_hash")) as $method
      | (if $match == "unknown" then "unknown" else ($match | tostring) end) + " (" + $method + ")"
    ' <<< "${latest_compare_json}")"
    echo "- Raw SQL hash match: $(jq -r 'if .sqlHashMatch == null then "unknown" else (.sqlHashMatch | tostring) end' <<< "${latest_compare_json}")"
    echo "- Data sources: $(jq -r '(.primaryDataSource // "unknown") + " -> " + (.shadowDataSource // "unknown")' <<< "${latest_compare_json}")"
    echo "- SQL lengths: $(jq -r '(.primarySqlLength // 0 | tostring) + " -> " + (.shadowSqlLength // 0 | tostring)' <<< "${latest_compare_json}")"
  else
    echo "- None"
  fi
  echo
  echo "## Latest Covered-Family Legacy-Primary Divergence"
  if [[ "${covered_family_legacy_primary_mismatch_count}" -gt 0 ]]; then
    echo "- Timestamp: $(jq -r '.timestamp // "unknown"' <<< "${latest_covered_family_compare_json}")"
    echo "- Prompt fingerprint: $(jq -r '.promptFingerprint // "unknown"' <<< "${latest_covered_family_compare_json}")"
    echo "- Covered family: $(jq -r '(.shadowRouteReason // "unknown") | sub("^family_contract_supported:"; "")' <<< "${latest_covered_family_compare_json}")"
    echo "- Primary route reason: $(jq -r '.primaryRouteReason // "unknown"' <<< "${latest_covered_family_compare_json}")"
    echo "- Shadow route reason: $(jq -r '.shadowRouteReason // "unknown"' <<< "${latest_covered_family_compare_json}")"
    echo "- SQL comparison: $(jq -r '
      ((if has("sqlComparisonMatch") then .sqlComparisonMatch else .sqlHashMatch end) // "unknown") as $match
      | ((.sqlComparisonMethod // "raw_sql_hash")) as $method
      | (if $match == "unknown" then "unknown" else ($match | tostring) end) + " (" + $method + ")"
    ' <<< "${latest_covered_family_compare_json}")"
    echo "- Raw SQL hash match: $(jq -r 'if .sqlHashMatch == null then "unknown" else (.sqlHashMatch | tostring) end' <<< "${latest_covered_family_compare_json}")"
  else
    echo "- None"
  fi
  echo
  echo "## Gate Worksheet"
  echo "- Required period day status: ${required_period_day_status}"
  echo "- Compare/error trend acceptable: ${compare_error_trend_acceptable}"
  echo "- Covered-family legacy-primary mismatches acceptable: ${covered_family_mismatches_acceptable}"
  echo "- Rollback exercised recently: TODO"
} > "${OUT_FILE}"

echo "Shadow metrics report written: ${OUT_FILE}"
