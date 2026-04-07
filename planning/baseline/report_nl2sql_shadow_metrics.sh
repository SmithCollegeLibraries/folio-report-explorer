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

tmp_jsonl="$(mktemp)"
trap 'rm -f "${tmp_jsonl}"' EXIT

awk -v d="${DATE_FILTER}" '
  index($0, d) == 1 && $0 ~ /nl2sql\.shadow_(compare|error)/ {
    sub(/^.*NL2SQL telemetry: /, "");
    print;
  }
' "${LOG_FILE}" > "${tmp_jsonl}"

line_count="$(wc -l < "${tmp_jsonl}" | tr -d ' ')"

if [[ "${line_count}" -eq 0 ]]; then
  cat > "${OUT_FILE}" <<EOF
# NL2SQL-008 Shadow Metrics Report

- Date: ${DATE_FILTER}
- Log file: ${LOG_FILE}

## Summary
- No shadow telemetry events were found for this date.

## Next Action
- Ensure shadow mode is enabled for the test cohort before collecting daily metrics.
EOF
  echo "Shadow metrics report written: ${OUT_FILE}"
  exit 0
fi

compare_count="$(jq -r 'select(.event == "nl2sql.shadow_compare") | 1' "${tmp_jsonl}" | wc -l | tr -d ' ')"
error_count="$(jq -r 'select(.event == "nl2sql.shadow_error") | 1' "${tmp_jsonl}" | wc -l | tr -d ' ')"

match_count="$(jq -r 'select(.event == "nl2sql.shadow_compare" and .sqlHashMatch == true) | 1' "${tmp_jsonl}" | wc -l | tr -d ' ')"
mismatch_count="$(jq -r 'select(.event == "nl2sql.shadow_compare" and .sqlHashMatch == false) | 1' "${tmp_jsonl}" | wc -l | tr -d ' ')"
unknown_match_count="$(jq -r 'select(.event == "nl2sql.shadow_compare" and .sqlHashMatch == null) | 1' "${tmp_jsonl}" | wc -l | tr -d ' ')"

datasource_mismatch_count="$(jq -r '
  select(.event == "nl2sql.shadow_compare")
  | select((.primaryDataSource // "") != "" and (.shadowDataSource // "") != "")
  | select(.primaryDataSource != .shadowDataSource)
  | 1
' "${tmp_jsonl}" | wc -l | tr -d ' ')"

if [[ "${compare_count}" -gt 0 ]]; then
  match_rate="$(jq -n --arg m "${match_count}" --arg c "${compare_count}" '((($m | tonumber) * 100) / ($c | tonumber))')"
  mismatch_rate="$(jq -n --arg m "${mismatch_count}" --arg c "${compare_count}" '((($m | tonumber) * 100) / ($c | tonumber))')"
else
  match_rate="0"
  mismatch_rate="0"
fi

error_breakdown="$(jq -r 'select(.event == "nl2sql.shadow_error") | (.error // "unknown")' "${tmp_jsonl}" | sort | uniq -c | sort -nr | head -n 5 || true)"

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
  echo "- SQL hash match count: ${match_count}"
  echo "- SQL hash mismatch count: ${mismatch_count}"
  echo "- SQL hash unknown count: ${unknown_match_count}"
  echo "- SQL hash match rate: $(printf '%.2f' "${match_rate}")%"
  echo "- SQL hash mismatch rate: $(printf '%.2f' "${mismatch_rate}")%"
  echo "- Data source mismatch count: ${datasource_mismatch_count}"
  echo
  echo "## Top Shadow Errors"
  if [[ -n "${error_breakdown}" ]]; then
    while IFS= read -r row; do
      echo "- ${row}"
    done <<< "${error_breakdown}"
  else
    echo "- None"
  fi
  echo
  echo "## Gate Worksheet"
  echo "- Required period day status: TODO"
  echo "- Compare/error trend acceptable: TODO"
  echo "- Rollback exercised recently: TODO"
} > "${OUT_FILE}"

echo "Shadow metrics report written: ${OUT_FILE}"
