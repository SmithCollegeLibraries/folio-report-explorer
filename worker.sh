#!/usr/bin/env bash
#
# worker.sh — Manage the background query worker process.
#
# Usage:
#   bash worker.sh start    — Start the worker (if not already running)
#   bash worker.sh stop     — Stop the worker
#   bash worker.sh restart  — Restart the worker
#   bash worker.sh status   — Check if the worker is running
#
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PID_FILE="$SCRIPT_DIR/backend/runtime/worker.pid"
LOG_FILE="$SCRIPT_DIR/backend/runtime/logs/worker.log"

# Load .env for database credentials
if [ -f "$SCRIPT_DIR/.env" ]; then
    set -a
    source "$SCRIPT_DIR/.env"
    set +a
fi

is_running() {
    if [ -f "$PID_FILE" ]; then
        local pid
        pid=$(cat "$PID_FILE")
        if kill -0 "$pid" 2>/dev/null; then
            return 0
        fi
    fi
    return 1
}

do_start() {
    if is_running; then
        echo "Worker is already running (PID $(cat "$PID_FILE"))"
        return 0
    fi

    mkdir -p "$(dirname "$LOG_FILE")"
    echo "[$(date)] Starting query worker..." >> "$LOG_FILE"

    cd "$SCRIPT_DIR"
    nohup php backend/yii query-worker/run >> "$LOG_FILE" 2>&1 &
    echo $! > "$PID_FILE"

    echo "Worker started (PID $!)"
    echo "  Log: $LOG_FILE"
}

do_stop() {
    if ! is_running; then
        echo "Worker is not running"
        rm -f "$PID_FILE"
        return 0
    fi

    local pid
    pid=$(cat "$PID_FILE")
    echo "Stopping worker (PID $pid)..."
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

    rm -f "$PID_FILE"
    echo "Worker stopped"
}

do_status() {
    if is_running; then
        echo "Worker is running (PID $(cat "$PID_FILE"))"
    else
        echo "Worker is not running"
        rm -f "$PID_FILE"
    fi
}

case "${1:-}" in
    start)   do_start ;;
    stop)    do_stop ;;
    restart) do_stop; do_start ;;
    status)  do_status ;;
    *)
        echo "Usage: $0 {start|stop|restart|status}"
        exit 1
        ;;
esac
