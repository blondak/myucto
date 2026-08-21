#!/usr/bin/env bash
# =============================================================================
#  cron-cnb-rates.sh — denní stažení kurzovního lístku ČNB
#  Frekvence: 1× denně 15:00 (ČNB vyhlašuje kurz kolem 14:30, jen pracovní dny)
#
#  Bez téhle úlohy se exchange_rates plní jen jako ad-hoc cache při prvním
#  dotazu na konkrétní den, takže historie zůstává děravá a cizoměnová úhrada
#  ke dni bez kurzu nemá čím ocenit pohyb. Skript dohání i mezery za posledních
#  30 dnů; dny, které kurz už mají, přeskočí bez HTTP volání. Idempotentní.
#
#  Volitelné argumenty:
#    --days=N     jak daleko zpět dohánět mezery (default 30, max 400)
#    --dry-run    jen vypíše, které dny chybí
#
#  Jednorázové doplnění celé historie: api/bin/backfill-cnb-rates.php
#
#  crontab (denně 15:00):
#    0 15 * * *  /var/www/myucto.cz/cmd/cron-cnb-rates.sh
# =============================================================================
set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
PHP_BIN="${MYINVOICE_PHP_BIN:-php}"
LOG_DIR="${MYINVOICE_DATA_DIR:-$PROJECT_ROOT}/log/cron"
mkdir -p "$LOG_DIR"
exec "$PHP_BIN" "$PROJECT_ROOT/api/bin/cron-cnb-rates.php" "$@" \
    >> "$LOG_DIR/cnb-rates-$(date +%Y-%m-%d).log" 2>&1
