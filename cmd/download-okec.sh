#!/usr/bin/env bash
# =============================================================================
#  download-okec.sh — aktualizace snapshotu číselníku ČINNOSTI (CZ-NACE / c_okec)
#
#  Stáhne aktuální číselník z rozhraní číselníků Daňového portálu a přepíše
#  `api/resources/ciselniky/okec.txt`. Proti němu se kanonizuje CZ-NACE
#  v přiznání k DPH a jede našeptávač v Nastavení → Daňové nastavení.
#
#  NENÍ to cron úloha — číselník se mění zřídka (poslední velká změna:
#  přechod na NACE rev. 2.1 k 1. 1. 2026). Pouštěj ručně, výsledek zkontroluj
#  přes `git diff` a commitni.
#
#  Použití:
#    cmd/download-okec.sh              # stáhne a přepíše snapshot
#    cmd/download-okec.sh --dry-run    # jen porovná a vypíše rozdíl
# =============================================================================
set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
exec php "$PROJECT_ROOT/api/bin/download-okec.php" "$@"
