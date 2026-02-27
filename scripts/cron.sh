#!/usr/bin/env bash
set -e
cd "$(dirname "$0")/.."
LOGS_DIR=${LOGS_DIR:-logs}
KEEP_DAYS=${KEEP_DAYS:-30}

find "$LOGS_DIR" -mindepth 2 -maxdepth 2 -type d -name "20[0-9][0-9]-[0-9][0-9]-[0-9][0-9]" -mtime +$KEEP_DAYS -exec rm -rf {} + 2>/dev/null || true
