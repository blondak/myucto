#!/usr/bin/env bash
# =============================================================================
#  cron-purchase-approval-reminders.sh: připomínky schvalovatelům přijatých
#  dokladů (schvalování manažerem střediska), kteří ještě nerozhodli.
#  Frekvence: 1× denně, doporučeno 09:20 v pracovní dny (Po–Pá)
#
#  Připomínka jde N dní po žádosti nebo poslední připomínce (výchozí
#  cfg.purchase_approval.reminder_after_days = 3), nejvýš
#  cfg.purchase_approval.max_reminders (výchozí 3) a vždy s novým odkazem.
#
#  Volitelné argumenty (předej jako parametry .sh):
#    --days=N    override reminder_after_days
#    --dry-run   jen vypíše, co by se odeslalo
#
#  crontab (každý pracovní den 09:20):
#    20 9 * * 1-5  /var/www/myucto.cz/cmd/cron-purchase-approval-reminders.sh
# =============================================================================
set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
PHP_BIN="${MYINVOICE_PHP_BIN:-php}"
LOG_DIR="${MYINVOICE_DATA_DIR:-$PROJECT_ROOT}/log/cron"
mkdir -p "$LOG_DIR"
exec "$PHP_BIN" "$PROJECT_ROOT/api/bin/cron-purchase-approval-reminders.php" "$@" \
    >> "$LOG_DIR/purchase-approval-reminders-$(date +%Y-%m-%d).log" 2>&1
