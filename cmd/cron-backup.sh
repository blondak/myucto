#!/usr/bin/env bash
# =============================================================================
#  cron-backup.sh — DB backup (mariadb-dump → ZIP)
#  Frekvence: 4× denně 02/08/14/20 (RPO 6 h); ranní běh je PŘED cron-cleanup.
#             Skutečný rozvrh instalace drží tabulka `backup_schedule_contract`
#             (Systém → Plánované úlohy), strop jsou 4 běhy denně.
#  Retention: podle profilu — default 30 denních + 12 měsíčních,
#             `managed` 7 posledních souborů bez měsíčních.
#
#  Vyžaduje v PATH: mariadb-dump (nebo mysqldump).
#
#  crontab:
#    0 2,8,14,20 * * *  /var/www/myucto.cz/cmd/cron-backup.sh
# =============================================================================
set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
PHP_BIN="${MYINVOICE_PHP_BIN:-php}"
LOG_DIR="${MYINVOICE_DATA_DIR:-$PROJECT_ROOT}/log/cron"
mkdir -p "$LOG_DIR"
exec "$PHP_BIN" "$PROJECT_ROOT/api/bin/cron-backup.php" "$@" \
    >> "$LOG_DIR/backup-$(date +%Y-%m-%d).log" 2>&1
