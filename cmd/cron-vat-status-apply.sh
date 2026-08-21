#!/usr/bin/env bash
# =============================================================================
#  cron-vat-status-apply.sh — denní propsání historie plátcovství DPH
#  Frekvence: 1× denně 00:30
#
#  Změny plátcovství DPH lze v Nastavení plánovat s budoucí účinností —
#  řádek supplier_vat_status_history s effective_from > dnes se do živé
#  cache (supplier.is_vat_payer, supplier.is_identified) propíše až v den
#  účinnosti tímto cronem. Jediný set-based UPDATE, idempotentní.
#
#  Volitelné argumenty:
#    --dry-run    jen vypíše počet firem k aktualizaci
#
#  crontab (denně 00:30):
#    30 0 * * *  /var/www/myucto.cz/cmd/cron-vat-status-apply.sh
# =============================================================================
set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
PHP_BIN="${MYINVOICE_PHP_BIN:-php}"
LOG_DIR="${MYINVOICE_DATA_DIR:-$PROJECT_ROOT}/log/cron"
mkdir -p "$LOG_DIR"
exec "$PHP_BIN" "$PROJECT_ROOT/api/bin/cron-vat-status-apply.php" "$@" \
    >> "$LOG_DIR/vat-status-apply-$(date +%Y-%m-%d).log" 2>&1
