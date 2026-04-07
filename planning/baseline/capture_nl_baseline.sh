#!/usr/bin/env bash
set -euo pipefail

# Capture NL->SQL baseline responses for NL2SQL-000.
# Usage:
#   ./planning/baseline/capture_nl_baseline.sh [API_BASE] [PROMPT_IDS]
# Example:
#   ./planning/baseline/capture_nl_baseline.sh http://localhost:8090/api
#   ./planning/baseline/capture_nl_baseline.sh http://localhost:8090/api P01,P02

API_BASE="${1:-http://localhost:8090/api}"
PROMPT_IDS="${2:-}"
PROMPTS_FILE="planning/baseline/NL2SQL-000-prompts.json"
OUT_DIR="planning/baseline/outputs"
TS="$(date +%Y-%m-%d_%H-%M-%S)"
OUT_FILE="${OUT_DIR}/${TS}_nl2sql-000-baseline-results.json"

if ! command -v jq >/dev/null 2>&1; then
  echo "Error: jq is required." >&2
  exit 1
fi

mkdir -p "${OUT_DIR}"

HEALTH_URL="${API_BASE%/}/health"
if ! curl -sS -m 10 "${HEALTH_URL}" >/dev/null; then
  echo "Error: API health check failed at ${HEALTH_URL}" >&2
  exit 2
fi

# Build output header
jq -n --arg ts "${TS}" --arg api "${API_BASE}" --arg ids "${PROMPT_IDS}" '{
  ticket: "NL2SQL-000",
  capturedAt: $ts,
  apiBase: $api,
  subset: (if $ids == "" then null else ($ids | split(",")) end),
  results: []
}' > "${OUT_FILE}"

# Build prompt stream (all prompts or selected IDs)
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
  exit 3
fi

# Iterate selected prompts and append results
while IFS= read -r row; do
  id=$(echo "${row}" | jq -r '.id')
  category=$(echo "${row}" | jq -r '.category')
  prompt=$(echo "${row}" | jq -r '.prompt')

  response=$(curl -sS -m 120 -X POST "${API_BASE%/}/nl" \
    -H 'Content-Type: application/json' \
    -d "$(jq -n --arg p "${prompt}" '{prompt: $p}')")

  status="success"
  if echo "${response}" | jq -e '.error' >/dev/null 2>&1; then
    status="error"
  fi

  tmp_file="${OUT_FILE}.tmp"
  jq \
    --arg id "${id}" \
    --arg category "${category}" \
    --arg prompt "${prompt}" \
    --arg status "${status}" \
    --argjson response "${response}" \
    '.results += [{id: $id, category: $category, prompt: $prompt, status: $status, response: $response}]' \
    "${OUT_FILE}" > "${tmp_file}"
  mv "${tmp_file}" "${OUT_FILE}"
done <<< "${prompt_stream}"

echo "Baseline capture complete: ${OUT_FILE}"
