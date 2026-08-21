#!/usr/bin/env bash
# =============================================================================
#  cron-bank-scan.sh — auto-import GPC výpisů z banky (FIO)
#  Frekvence: každých 15–30 minut (FIO export pravidelně dorazí)
#  Skenuje cfg.bank_import.scan_root + podadresáře YYYY-MM/, hledá *.gpc/*.txt.
#  SHA256 dedupe — soubor co už byl naimportovaný se přeskočí.
#
#  crontab:
#    */30 * * * *  /var/www/myucto.cz/cmd/cron-bank-scan.sh
# =============================================================================
set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
PHP_BIN="${MYINVOICE_PHP_BIN:-php}"
LOG_DIR="${MYINVOICE_DATA_DIR:-$PROJECT_ROOT}/log/cron"
mkdir -p "$LOG_DIR"
exec "$PHP_BIN" "$PROJECT_ROOT/api/bin/cron-bank-scan.php" "$@" \
    >> "$LOG_DIR/bank-scan-$(date +%Y-%m-%d).log" 2>&1
