#!/usr/bin/env bash
# =============================================================================
#  cron-storage-usage.sh — měření spotřeby místa instance (H-10)
#  Frekvence: 1× za hodinu. Častěji nemá smysl (spotřeba neroste skokem),
#  řidčeji by se blížící se kvóta ohlásila pozdě.
#
#  Změří velikost databáze (information_schema) a datového prostoru BEZ
#  adresáře záloh a uloží výsledek do `instance_storage_usage`. /api/health
#  a middleware režimu jen pro čtení pak čtou hotové číslo — strom souborů
#  se prochází výhradně tady, nikdy při requestu.
#
#  Zálohy se do kvóty NEZAPOČÍTÁVAJÍ (hosting je z ní taky vyjímá).
#  Dokud měření neproběhne, je spotřeba NEZMĚŘENÁ (null) — ne nula — a
#  nespouští ani upozornění, ani režim jen pro čtení.
#
#  crontab:
#    15 * * * *  /var/www/myucto.cz/cmd/cron-storage-usage.sh
# =============================================================================
set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
PHP_BIN="${MYINVOICE_PHP_BIN:-php}"
LOG_DIR="${MYINVOICE_DATA_DIR:-$PROJECT_ROOT}/log/cron"
mkdir -p "$LOG_DIR"
exec "$PHP_BIN" "$PROJECT_ROOT/api/bin/cron-storage-usage.php" "$@" \
    >> "$LOG_DIR/storage-usage-$(date +%Y-%m-%d).log" 2>&1
