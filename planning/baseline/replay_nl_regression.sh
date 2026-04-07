#!/usr/bin/env bash
set -euo pipefail

# Replay NL prompts and compare current behavior against a baseline artifact.
# Usage:
#   ./planning/baseline/replay_nl_regression.sh [API_BASE] [BASELINE_FILE] [PROMPT_IDS]
# Example:
#   ./planning/baseline/replay_nl_regression.sh http://localhost:8090/api
#   ./planning/baseline/replay_nl_regression.sh http://localhost:8090/api planning/baseline/outputs/2026-04-06_10-20-49_nl2sql-000-merged-results.json P01,P02

API_BASE="${1:-http://localhost:8090/api}"
BASELINE_FILE="${2:-}"
PROMPT_IDS="${3:-}"

PROMPTS_FILE="planning/baseline/NL2SQL-000-prompts.json"
OUT_DIR="planning/baseline/outputs"
REPORT_DIR="planning/baseline/reports"
TS="$(date +%Y-%m-%d_%H-%M-%S)"
OUT_JSON="${OUT_DIR}/${TS}_nl2sql-007-replay-results.json"
OUT_MD="${REPORT_DIR}/${TS}_nl2sql-007-replay-report.md"

MAX_REGRESSIONS_ON_BASELINE_SUCCESS="${MAX_REGRESSIONS_ON_BASELINE_SUCCESS:-0}"
MIN_OVERALL_PASS_RATE="${MIN_OVERALL_PASS_RATE:-80}"
SKIP_HEALTH_CHECK="${SKIP_HEALTH_CHECK:-0}"

if ! command -v jq >/dev/null 2>&1; then
  echo "Error: jq is required." >&2
  exit 1
fi

if ! command -v curl >/dev/null 2>&1; then
  echo "Error: curl is required." >&2
  exit 1
fi

if ! command -v shasum >/dev/null 2>&1; then
  echo "Error: shasum is required." >&2
  exit 1
fi

if [[ ! -f "${PROMPTS_FILE}" ]]; then
  echo "Error: prompts file not found: ${PROMPTS_FILE}" >&2
  exit 2
fi

if [[ -z "${BASELINE_FILE}" ]]; then
  BASELINE_FILE="$(ls -1t planning/baseline/outputs/*_nl2sql-000-merged-results.json 2>/dev/null | head -n 1 || true)"
fi

if [[ -z "${BASELINE_FILE}" || ! -f "${BASELINE_FILE}" ]]; then
  echo "Error: baseline file not found. Provide it as arg #2 or create a merged NL2SQL-000 artifact first." >&2
  exit 2
fi

mkdir -p "${OUT_DIR}" "${REPORT_DIR}"

if [[ "${SKIP_HEALTH_CHECK}" != "1" ]]; then
  HEALTH_URL="${API_BASE%/}/health"
  if ! curl -sS -m 10 "${HEALTH_URL}" >/dev/null; then
    echo "Error: API health check failed at ${HEALTH_URL}" >&2
    exit 3
  fi
fi

hash_sql() {
  local sql_text="$1"
  if [[ -z "${sql_text}" ]]; then
    echo ""
    return 0
  fi
  printf '%s' "${sql_text}" \
    | tr '\n' ' ' \
    | tr -s '[:space:]' ' ' \
    | tr '[:upper:]' '[:lower:]' \
    | shasum -a 256 \
    | awk '{print $1}'
}

jq -n \
  --arg ts "${TS}" \
  --arg api "${API_BASE}" \
  --arg baseline "${BASELINE_FILE}" \
  --arg ids "${PROMPT_IDS}" \
  --argjson maxReg "${MAX_REGRESSIONS_ON_BASELINE_SUCCESS}" \
  --argjson minPass "${MIN_OVERALL_PASS_RATE}" \
  '{
    ticket: "NL2SQL-007",
    capturedAt: $ts,
    apiBase: $api,
    baselineFile: $baseline,
    subset: (if $ids == "" then null else ($ids | split(",")) end),
    thresholds: {
      maxRegressionsOnBaselineSuccess: $maxReg,
      minOverallPassRate: $minPass
    },
    results: []
  }' > "${OUT_JSON}"

if [[ -n "${PROMPT_IDS}" ]]; then
  prompt_stream=$(jq -c --arg ids "${PROMPT_IDS}" '
    ($ids | split(",")) as $wanted
    | .prompts[]
    | select(.id as $id | $wanted | index($id))
  ' "${PROMPTS_FILE}")
else
  prompt_stream=$(jq -c '.prompts[]' "${PROMPTS_FILE}")
fi

if [[ -z "${prompt_stream}" ]]; then
  echo "Error: No prompts selected. Check PROMPT_IDS format (e.g. P01,P02)." >&2
  exit 4
fi

while IFS= read -r row; do
  id=$(echo "${row}" | jq -r '.id')
  category=$(echo "${row}" | jq -r '.category')
  prompt=$(echo "${row}" | jq -r '.prompt')

  baseline_entry=$(jq -c --arg id "${id}" '.results[]? | select(.id == $id)' "${BASELINE_FILE}" | head -n 1)
  if [[ -z "${baseline_entry}" ]]; then
    baseline_entry='{}'
  fi

  baseline_status=$(echo "${baseline_entry}" | jq -r '.status // "missing"')
  baseline_ds=$(echo "${baseline_entry}" | jq -r '.response.dataSource // ""')
  baseline_sql=$(echo "${baseline_entry}" | jq -r '.response.sql // ""')
  baseline_sql_hash=$(hash_sql "${baseline_sql}")

  raw_response=$(curl -sS -m 120 -X POST "${API_BASE%/}/nl" \
    -H 'Content-Type: application/json' \
    -d "$(jq -n --arg p "${prompt}" '{prompt: $p}')" || true)

  if [[ -z "${raw_response}" ]]; then
    response='{"error":"No response from /api/nl"}'
  elif ! echo "${raw_response}" | jq -e . >/dev/null 2>&1; then
    response=$(jq -n --arg raw "${raw_response}" '{error:"Non-JSON response from /api/nl", raw:$raw}')
  else
    response="${raw_response}"
  fi

  current_status=$(echo "${response}" | jq -r 'if has("error") then "error" else "success" end')
  current_ds=$(echo "${response}" | jq -r '.dataSource // ""')
  current_sql=$(echo "${response}" | jq -r '.sql // ""')
  current_sql_hash=$(hash_sql "${current_sql}")

  sql_changed="unknown"
  if [[ "${baseline_status}" == "success" && "${current_status}" == "success" && -n "${baseline_sql_hash}" && -n "${current_sql_hash}" ]]; then
    if [[ "${baseline_sql_hash}" == "${current_sql_hash}" ]]; then
      sql_changed="false"
    else
      sql_changed="true"
    fi
  fi

  regression="false"
  note=""

  if [[ "${baseline_status}" == "success" && "${current_status}" != "success" ]]; then
    regression="true"
    note="baseline_success_now_error"
  elif [[ "${baseline_status}" == "success" && "${current_status}" == "success" && -n "${baseline_ds}" && "${baseline_ds}" != "${current_ds}" ]]; then
    regression="true"
    note="datasource_changed_${baseline_ds}_to_${current_ds}"
  elif [[ "${baseline_status}" == "error" && "${current_status}" == "success" ]]; then
    note="improved_from_baseline_error"
  elif [[ "${baseline_status}" == "success" && "${current_status}" == "success" ]]; then
    if [[ "${sql_changed}" == "true" ]]; then
      note="sql_changed_non_regression"
    else
      note="status_and_sql_stable"
    fi
  elif [[ "${baseline_status}" == "error" && "${current_status}" == "error" ]]; then
    note="both_error"
  else
    note="baseline_missing"
  fi

  verdict="pass"
  if [[ "${regression}" == "true" ]]; then
    verdict="fail"
  fi

  tmp_file="${OUT_JSON}.tmp"
  jq \
    --arg id "${id}" \
    --arg category "${category}" \
    --arg prompt "${prompt}" \
    --argjson baseline "${baseline_entry}" \
    --argjson current "${response}" \
    --arg verdict "${verdict}" \
    --arg note "${note}" \
    --arg regression "${regression}" \
    --arg sqlChanged "${sql_changed}" \
    --arg baselineSqlHash "${baseline_sql_hash}" \
    --arg currentSqlHash "${current_sql_hash}" \
    '.results += [{
      id: $id,
      category: $category,
      prompt: $prompt,
      baseline: {
        status: ($baseline.status // "missing"),
        dataSource: ($baseline.response.dataSource // null),
        route: ($baseline.response.route // null),
        routeReason: ($baseline.response.routeReason // null)
      },
      current: {
        status: (if ($current | has("error")) then "error" else "success" end),
        dataSource: ($current.dataSource // null),
        route: ($current.route // null),
        routeReason: ($current.routeReason // null),
        error: ($current.error // null)
      },
      verdict: $verdict,
      regression: ($regression == "true"),
      sqlChanged: (if $sqlChanged == "unknown" then null else ($sqlChanged == "true") end),
      baselineSqlHash: (if $baselineSqlHash == "" then null else $baselineSqlHash end),
      currentSqlHash: (if $currentSqlHash == "" then null else $currentSqlHash end),
      note: $note
    }]' \
    "${OUT_JSON}" > "${tmp_file}"
  mv "${tmp_file}" "${OUT_JSON}"
done <<< "${prompt_stream}"

tmp_file="${OUT_JSON}.tmp"
jq '
  .summary = (
    .results as $r
    | {
      total: ($r | length),
      pass: ($r | map(select(.verdict == "pass")) | length),
      fail: ($r | map(select(.verdict == "fail")) | length),
      baselineSuccess: ($r | map(select(.baseline.status == "success")) | length),
      regressionsOnBaselineSuccess: ($r | map(select(.baseline.status == "success" and .regression == true)) | length),
      passRate: (
        if ($r | length) == 0 then 0
        else (((($r | map(select(.verdict == "pass")) | length) * 10000) / ($r | length)) | floor / 100)
        end
      )
    }
  )
  | .gate = {
      maxRegressionsOnBaselineSuccess: .thresholds.maxRegressionsOnBaselineSuccess,
      minOverallPassRate: .thresholds.minOverallPassRate,
      met: (
        (.summary.regressionsOnBaselineSuccess <= .thresholds.maxRegressionsOnBaselineSuccess)
        and
        (.summary.passRate >= .thresholds.minOverallPassRate)
      )
    }
' "${OUT_JSON}" > "${tmp_file}"
mv "${tmp_file}" "${OUT_JSON}"

{
  echo "# NL2SQL-007 Replay Report"
  echo
  echo "- Timestamp: ${TS}"
  echo "- API Base: ${API_BASE}"
  echo "- Baseline: ${BASELINE_FILE}"
  echo
  echo "## Thresholds"
  echo "- Max regressions on baseline-success prompts: ${MAX_REGRESSIONS_ON_BASELINE_SUCCESS}"
  echo "- Minimum overall pass rate: ${MIN_OVERALL_PASS_RATE}%"
  echo
  echo "## Summary"
  echo "- Total prompts: $(jq -r '.summary.total' "${OUT_JSON}")"
  echo "- Pass: $(jq -r '.summary.pass' "${OUT_JSON}")"
  echo "- Fail: $(jq -r '.summary.fail' "${OUT_JSON}")"
  echo "- Baseline-success prompts: $(jq -r '.summary.baselineSuccess' "${OUT_JSON}")"
  echo "- Regressions on baseline-success prompts: $(jq -r '.summary.regressionsOnBaselineSuccess' "${OUT_JSON}")"
  echo "- Overall pass rate: $(jq -r '.summary.passRate' "${OUT_JSON}")%"
  echo "- Gate met: $(jq -r '.gate.met' "${OUT_JSON}")"
  echo
  echo "## Results"
  echo "| Prompt | Baseline | Current | Route | Verdict | Notes |"
  echo "|---|---|---|---|---|---|"
  jq -r '.results[] | "| \(.id) | \(.baseline.status) | \(.current.status) | \((.current.route // "-")) | \(.verdict) | \(.note) |"' "${OUT_JSON}"
  echo
  echo "## Failing Prompts"
  failures=$(jq -r '.results[] | select(.verdict == "fail") | "- \(.id): \(.note)"' "${OUT_JSON}")
  if [[ -n "${failures}" ]]; then
    echo "${failures}"
  else
    echo "- None"
  fi
} > "${OUT_MD}"

echo "Replay output JSON: ${OUT_JSON}"
echo "Replay report Markdown: ${OUT_MD}"
