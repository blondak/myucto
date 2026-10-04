#!/usr/bin/env bash
# =============================================================================
#  cron-backup-personnel.sh — denní záloha personálních spisů
#  (storage/payroll-personnel/) do storage/backup/{dbname}-personnel-YYYY-MM-DD.zip
#
#  Oddělené od cron-backup-payroll.sh schválně: personální spisy (pracovní
#  smlouvy, dodatky) jsou soukromá data zaměstnanců a jejich zálohu jde držet
#  jinde a s jinými právy než ostatní zálohy.
#  Frekvence: 1× denně, doporučeno 02:45 (po cron-backup-payroll)
#  Retention: 30 denních + měsíční (1. v měsíci) drženy 365 dní
#
#  crontab:
#    45 2 * * *  /var/www/myucto.cz/cmd/cron-backup-personnel.sh
# =============================================================================
set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
PHP_BIN="${MYINVOICE_PHP_BIN:-php}"
LOG_DIR="${MYINVOICE_DATA_DIR:-$PROJECT_ROOT}/log/cron"
mkdir -p "$LOG_DIR"
exec "$PHP_BIN" "$PROJECT_ROOT/api/bin/cron-backup-personnel.php" "$@" \
    >> "$LOG_DIR/backup-personnel-$(date +%Y-%m-%d).log" 2>&1
