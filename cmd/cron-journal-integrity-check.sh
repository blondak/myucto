#!/usr/bin/env bash
# =============================================================================
#  cron-journal-integrity-check.sh — noční integrity job nad účetním deníkem
#  Frekvence: 1× denně, doporučeno v noci (např. 02:30)
#
#  Pro každého dodavatele v podvojném účetnictví zkontroluje konzistenci
#  doklad ↔ deník (sirotčí zápisy, Σ MD ≠ Σ D, booked_at bez zápisu a naopak,
#  doklad ≠ zápis částkou). ČISTĚ ČTECÍ — nic neopravuje, jen uloží poslední
#  výsledek do journal_integrity_findings pro dashboard.
#
#  Volitelné argumenty:
#    --dry-run          jen vypíše nálezy, nic neuloží
#    --supplier=ID      jen jeden dodavatel
#
#  crontab (každý den 02:30):
#    30 2 * * *  /var/www/myucto.cz/cmd/cron-journal-integrity-check.sh
# =============================================================================
set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
PHP_BIN="${MYINVOICE_PHP_BIN:-php}"
LOG_DIR="${MYINVOICE_DATA_DIR:-$PROJECT_ROOT}/log/cron"
mkdir -p "$LOG_DIR"
exec "$PHP_BIN" "$PROJECT_ROOT/api/bin/cron-journal-integrity-check.php" "$@" \
    >> "$LOG_DIR/journal-integrity-$(date +%Y-%m-%d).log" 2>&1
