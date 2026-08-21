#!/usr/bin/env bash
# =============================================================================
#  cron-payroll-post.sh — automatické měsíční zaúčtování mezd
#  Frekvence: 1× měsíčně, 1. den v měsíci 04:00
#
#  Zaúčtuje mzdovou rekapitulaci za PŘEDCHOZÍ měsíc všem aktivním zaměstnancům,
#  kteří mají na kartě zapnuté „Účtovat automaticky" a vyplněnou pravidelnou
#  hrubou mzdu (payroll_employees.auto_post + monthly_gross, migrace 1175).
#  Datum účtování je poslední den účtovaného měsíce, takže zápis padne do
#  správného období i při zpožděném běhu.
#
#  Jen firmy v podvojném účetnictví. Uzavřené období ani chyba u jednoho
#  zaměstnance běh neshodí — skončí v reportu (Systém → Plánované úlohy).
#
#  Volitelné argumenty:
#    --dry-run           jen vypíše, co by se zaúčtovalo
#    --supplier=ID       jen jeden dodavatel
#    --period=RRRR-MM    dohnat konkrétní měsíc
#
#  crontab (1. den v měsíci 04:00):
#    0 4 1 * *  /var/www/myucto.cz/cmd/cron-payroll-post.sh
# =============================================================================
set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
PHP_BIN="${MYINVOICE_PHP_BIN:-php}"
LOG_DIR="${MYINVOICE_DATA_DIR:-$PROJECT_ROOT}/log/cron"
mkdir -p "$LOG_DIR"
exec "$PHP_BIN" "$PROJECT_ROOT/api/bin/cron-payroll-post.php" "$@" \
    >> "$LOG_DIR/payroll-post-$(date +%Y-%m-%d).log" 2>&1
