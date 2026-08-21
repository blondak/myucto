#!/usr/bin/env bash
set -euo pipefail

# cron-bank-email-notices.sh — auto-import bankovnich emailovych aviz
PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PHP_BIN="${MYINVOICE_PHP_BIN:-php}"
LOG_DIR="$PROJECT_ROOT/log"
mkdir -p "$LOG_DIR"

exec "$PHP_BIN" "$PROJECT_ROOT/api/bin/cron-bank-email-notices.php" "$@" \
    >> "$LOG_DIR/bank-email-notices-$(date +%Y-%m-%d).log" 2>&1
