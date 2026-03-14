#!/bin/sh
set -eu

ROOT_DIR="/var/www/html"
RUNTIME_DIR="$ROOT_DIR/storage/runtime"
HEARTBEAT_FILE="$RUNTIME_DIR/scheduler-heartbeat"

mkdir -p "$RUNTIME_DIR"

(
  while :; do
    date -u +%s > "$HEARTBEAT_FILE"
    sleep 10
  done
) &
HEARTBEAT_PID=$!

WORKER_PID=0

cleanup() {
  kill "$HEARTBEAT_PID" >/dev/null 2>&1 || true
}

on_terminate() {
  if [ "$WORKER_PID" -gt 1 ]; then
    kill "$WORKER_PID" >/dev/null 2>&1 || true
    wait "$WORKER_PID" >/dev/null 2>&1 || true
  fi
  exit 0
}

trap cleanup EXIT
trap on_terminate INT TERM

cd "$ROOT_DIR"
php artisan schedule:work &
WORKER_PID=$!
wait "$WORKER_PID"
