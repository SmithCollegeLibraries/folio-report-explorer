#!/usr/bin/env bash
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
DATE_FILTER="2026-10-30"
LOG_FILE="$(mktemp)"
REPORT_DIR="$(mktemp -d)"
REPORT_FILE="${REPORT_DIR}/${DATE_FILTER}_nl2sql-008-shadow-metrics.md"

cleanup() {
  rm -f "${LOG_FILE}"
  rm -rf "${REPORT_DIR}"
}
trap cleanup EXIT

php -r '
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);
require $argv[1];
require $argv[2];
$line = "2026-10-30 12:00:00 [-][-][-][info][nl2sql.telemetry] NL2SQL telemetry: "
    . json_encode([
        "event" => "nl2sql.shadow_compare",
        "timestamp" => "2026-10-30T12:00:00+00:00",
        "promptFingerprint" => "synthetic-fingerprint",
        "primaryMode" => "intent",
        "shadowMode" => "legacy",
        "sqlHashMatch" => true,
        "OPENAI_API_KEY" => "SYNTHETIC_REDACTED_TELEMETRY_KEY_123456",
    ]);
echo app\services\ApiKeyRedactor::redact($line), PHP_EOL;
' "${REPO_ROOT}/backend/vendor/autoload.php" "${REPO_ROOT}/backend/vendor/yiisoft/yii2/Yii.php" > "${LOG_FILE}"

if grep -Fq -- "SYNTHETIC_REDACTED_TELEMETRY_KEY_123456" "${LOG_FILE}"; then
  echo "The redacted telemetry fixture still contains its synthetic API key." >&2
  exit 1
fi
if ! grep -Fq -- "[REDACTED]" "${LOG_FILE}"; then
  echo "The telemetry fixture should preserve the redaction marker." >&2
  exit 1
fi

bash "${REPO_ROOT}/scripts/nl2sql/report_shadow_metrics.sh" "${DATE_FILTER}" "${LOG_FILE}" "${REPORT_DIR}" >/dev/null

if ! grep -Fq -- "- shadow_compare events: 1" "${REPORT_FILE}"; then
  echo "The shadow metrics report did not parse the redacted telemetry line." >&2
  cat "${REPORT_FILE}" >&2
  exit 1
fi

echo "Shadow metrics redacted telemetry test passed"
