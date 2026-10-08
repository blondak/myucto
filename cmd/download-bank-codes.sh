#!/usr/bin/env bash
# =============================================================================
#  download-bank-codes.sh — aktualizace číselníku kódů bank (registr ČNB)
#
#  Stáhne aktuální registr kódů platebního styku ČNB a přepíše
#  `api/resources/ciselniky/kody_bank_CR.csv`. Proti němu se kontroluje kód
#  banky v platebním spojení oznámení NEMPRI (C_KODBANKY).
#
#  NENÍ to cron úloha — registr se mění zřídka. Pouštěj ručně, výsledek
#  zkontroluj přes `git diff` a commitni.
#
#  Použití:
#    cmd/download-bank-codes.sh              # stáhne a přepíše číselník
#    cmd/download-bank-codes.sh --dry-run    # jen vypíše rozdíl
# =============================================================================
set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
PHP_BIN="${MYINVOICE_PHP_BIN:-php}"
exec "$PHP_BIN" "$PROJECT_ROOT/api/bin/download-bank-codes.php" "$@"
