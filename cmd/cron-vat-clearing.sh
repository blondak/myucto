#!/usr/bin/env bash
# =============================================================================
#  cron-vat-clearing.sh — interní doklad zúčtování DPH
#  Frekvence: 1× měsíčně, 1. den v měsíci 04:30
#
#  Převede daň zdaňovacího období z analytik 343.100 (vstup) a 343.200 (výstup)
#  na zúčtovací účet 343.900 (migrace 1323/1324):
#      MD 343.200 / D 343.900     MD 343.900 / D 343.100
#  Po dokladu jsou vstup i výstup za období nulové a na 343.900 leží přesně to,
#  co se odvádí — bankovní úhrada (kontace `vat.payment`) ho pak vynuluje.
#
#  Řeší období PŘEDCHOZÍ. Měsíčnímu plátci vyjde minulý měsíc, čtvrtletnímu celé
#  čtvrtletí — a to až poté, co skončí. Zaúčtování je idempotentní, opakovaný
#  běh zápis přepočítá, nikdy nezdvojí.
#
#  Jen plátci (nebo identifikované osoby) v podvojném účetnictví. Uzavřené
#  období ani chyba u jedné firmy běh neshodí — skončí v reportu
#  (Systém → Plánované úlohy).
#
#  Volitelné argumenty:
#    --dry-run           jen vypíše, co by se zaúčtovalo
#    --supplier=ID       jen jeden dodavatel
#    --period=RRRR-MM    dohnat konkrétní období
#    --force             i pro dosud neuzavřené období
#
#  crontab (1. den v měsíci 04:30):
#    30 4 1 * *  /var/www/myucto.cz/cmd/cron-vat-clearing.sh
# =============================================================================
set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
LOG_DIR="${MYINVOICE_DATA_DIR:-$PROJECT_ROOT}/log/cron"
mkdir -p "$LOG_DIR"
exec php "$PROJECT_ROOT/api/bin/cron-vat-clearing.php" "$@" \
    >> "$LOG_DIR/vat-clearing-$(date +%Y-%m-%d).log" 2>&1
