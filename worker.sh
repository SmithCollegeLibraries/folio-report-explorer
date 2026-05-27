#!/usr/bin/env bash
#
# worker.sh — Manage background query worker processes.
#
# Usage:
#   bash worker.sh start    — Start query workers (if not already running)
#   bash worker.sh stop     — Stop query workers
#   bash worker.sh restart  — Restart query workers
#   bash worker.sh status   — Check query worker status
#   bash worker.sh export-start   — Start export worker
#   bash worker.sh export-stop    — Stop export worker
#   bash worker.sh export-restart — Restart export worker
#   bash worker.sh export-status  — Check export worker status
#
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PID_FILE="$SCRIPT_DIR/backend/runtime/worker.pid"
LOG_FILE="$SCRIPT_DIR/backend/runtime/logs/worker.log"
EXPORT_PID_FILE="$SCRIPT_DIR/backend/runtime/export-worker.pid"
EXPORT_LOG_FILE="$SCRIPT_DIR/backend/runtime/logs/export-worker.log"

# Load .env for database credentials
if [ -f "$SCRIPT_DIR/.env" ]; then
    set -a
    source "$SCRIPT_DIR/.env"
    set +a
fi

positive_int_or_default() {
    local value="$1"
    local fallback="$2"
    if [[ "$value" =~ ^[0-9]+$ ]] && [ "$value" -ge 1 ]; then
        echo "$value"
    else
        echo "$fallback"
    fi
}

QUERY_WORKER_COUNT=$(positive_int_or_default "${QUERY_WORKER_COUNT:-2}" 2)
QUERY_WORKER_MAX_FOLIO_JOBS=$(positive_int_or_default "${QUERY_WORKER_MAX_FOLIO_JOBS:-$QUERY_WORKER_COUNT}" "$QUERY_WORKER_COUNT")

query_pid_file() {
    local index="$1"
    if [ "$index" -eq 1 ]; then
        echo "$PID_FILE"
    else
        echo "$SCRIPT_DIR/backend/runtime/worker-$index.pid"
    fi
}

pid_file_is_running() {
    local pid_file="$1"
    if [ -f "$pid_file" ]; then
        local pid
        pid=$(cat "$pid_file")
        if [ -n "$pid" ] && kill -0 "$pid" 2>/dev/null; then
            return 0
        fi
    fi
    return 1
}

known_query_pid_files() {
    echo "$PID_FILE"
    for pid_file in "$SCRIPT_DIR"/backend/runtime/worker-*.pid; do
        [ -e "$pid_file" ] && echo "$pid_file"
    done
    for index in $(seq 2 "$QUERY_WORKER_COUNT"); do
        query_pid_file "$index"
    done
}

query_worker_label() {
    local pid_file="$1"
    local base
    base=$(basename "$pid_file")
    if [ "$base" = "worker.pid" ]; then
        echo "Worker 1"
    elif [[ "$base" =~ ^worker-([0-9]+)\.pid$ ]]; then
        echo "Worker ${BASH_REMATCH[1]}"
    else
        echo "Worker"
    fi
}

is_running() {
    while IFS= read -r pid_file; do
        if pid_file_is_running "$pid_file"; then
            return 0
        fi
    done < <(known_query_pid_files | sort -u)
    return 1
}

do_start() {
    mkdir -p "$(dirname "$LOG_FILE")"
    echo "[$(date)] Starting $QUERY_WORKER_COUNT query worker(s) with FOLIO concurrency $QUERY_WORKER_MAX_FOLIO_JOBS..." >> "$LOG_FILE"

    cd "$SCRIPT_DIR"
    for index in $(seq 1 "$QUERY_WORKER_COUNT"); do
        local pid_file
        pid_file=$(query_pid_file "$index")
        if pid_file_is_running "$pid_file"; then
            echo "$(query_worker_label "$pid_file") is already running (PID $(cat "$pid_file"))"
            continue
        fi

        rm -f "$pid_file"
        QUERY_WORKER_SLOT="$index" \
        QUERY_WORKER_COUNT="$QUERY_WORKER_COUNT" \
        QUERY_WORKER_MAX_FOLIO_JOBS="$QUERY_WORKER_MAX_FOLIO_JOBS" \
            nohup php backend/yii query-worker/run >> "$LOG_FILE" 2>&1 &
        echo $! > "$pid_file"
        echo "$(query_worker_label "$pid_file") started (PID $!)"
    done

    echo "  Log: $LOG_FILE"
    echo "  Query workers: $QUERY_WORKER_COUNT"
    echo "  FOLIO concurrency limit: $QUERY_WORKER_MAX_FOLIO_JOBS"
}

stop_pid_file() {
    local pid_file="$1"
    local label
    label=$(query_worker_label "$pid_file")

    if ! pid_file_is_running "$pid_file"; then
        rm -f "$pid_file"
        return 0
    fi

    local pid
    pid=$(cat "$pid_file")
    echo "Stopping $label (PID $pid)..."
    kill "$pid" 2>/dev/null || true

    # Wait up to 10 seconds for graceful stop
    for i in $(seq 1 10); do
        if ! kill -0 "$pid" 2>/dev/null; then
            break
        fi
        sleep 1
    done

    # Force kill if still running
    if kill -0 "$pid" 2>/dev/null; then
        kill -9 "$pid" 2>/dev/null || true
    fi

    rm -f "$pid_file"
    echo "$label stopped"
}

do_stop() {
    if ! is_running; then
        echo "Workers are not running"
        known_query_pid_files | sort -u | while IFS= read -r pid_file; do
            rm -f "$pid_file"
        done
        return 0
    fi

    known_query_pid_files | sort -u | while IFS= read -r pid_file; do
        stop_pid_file "$pid_file"
    done
}

do_status() {
    local running=0
    while IFS= read -r pid_file; do
        if pid_file_is_running "$pid_file"; then
            echo "$(query_worker_label "$pid_file") is running (PID $(cat "$pid_file"))"
            running=$((running + 1))
        elif [ -f "$pid_file" ]; then
            echo "$(query_worker_label "$pid_file") is not running (stale PID file removed)"
            rm -f "$pid_file"
        fi
    done < <(known_query_pid_files | sort -u)

    if [ "$running" -gt 0 ]; then
        echo "Running query workers: $running / $QUERY_WORKER_COUNT configured"
        echo "FOLIO concurrency limit: $QUERY_WORKER_MAX_FOLIO_JOBS"
    else
        echo "Workers are not running"
    fi
}

is_export_running() {
    if [ -f "$EXPORT_PID_FILE" ]; then
        local pid
        pid=$(cat "$EXPORT_PID_FILE")
        if kill -0 "$pid" 2>/dev/null; then
            return 0
        fi
    fi
    return 1
}

do_export_start() {
    if is_export_running; then
        echo "Export worker is already running (PID $(cat "$EXPORT_PID_FILE"))"
        return 0
    fi

    mkdir -p "$(dirname "$EXPORT_LOG_FILE")"
    echo "[$(date)] Starting export worker..." >> "$EXPORT_LOG_FILE"

    cd "$SCRIPT_DIR"
    nohup php backend/yii export-worker/run >> "$EXPORT_LOG_FILE" 2>&1 &
    echo $! > "$EXPORT_PID_FILE"

    echo "Export worker started (PID $!)"
    echo "  Log: $EXPORT_LOG_FILE"
}

do_export_stop() {
    if ! is_export_running; then
        echo "Export worker is not running"
        rm -f "$EXPORT_PID_FILE"
        return 0
    fi

    local pid
    pid=$(cat "$EXPORT_PID_FILE")
    echo "Stopping export worker (PID $pid)..."
    kill "$pid" 2>/dev/null || true

    for i in $(seq 1 10); do
        if ! kill -0 "$pid" 2>/dev/null; then
            break
        fi
        sleep 1
    done

    if kill -0 "$pid" 2>/dev/null; then
        kill -9 "$pid" 2>/dev/null || true
    fi

    rm -f "$EXPORT_PID_FILE"
    echo "Export worker stopped"
}

do_export_status() {
    if is_export_running; then
        echo "Export worker is running (PID $(cat "$EXPORT_PID_FILE"))"
    else
        echo "Export worker is not running"
        rm -f "$EXPORT_PID_FILE"
    fi
}

case "${1:-}" in
    start)   do_start ;;
    stop)    do_stop ;;
    restart) do_stop; do_start ;;
    status)  do_status ;;
    export-start) do_export_start ;;
    export-stop) do_export_stop ;;
    export-restart) do_export_stop; do_export_start ;;
    export-status) do_export_status ;;
    *)
        echo "Usage: $0 {start|stop|restart|status|export-start|export-stop|export-restart|export-status}"
        exit 1
        ;;
esac
