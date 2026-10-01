#!/usr/bin/env bash
# =============================================================================
#  cron-retention.sh: denní úklid záloh, logů a dočasných souborů
#  Frekvence: 1× denně, doporučeno 03:15 (po nočních zálohách a cron-cleanup)
#
#  Ve spravovaném provozu povinná, na self-hostu volitelná
#  (cron.retention.enabled = true v cfg.php). Limity v cron.retention.*:
#  DB dumpy 7 dnů (48 h všechny, pak 1 denně), PDF/Dokumenty/Mzdy 3 poslední,
#  logy 14 dnů, dočasné soubory 48 h, Twig cache 30 dnů, archivy kompletního
#  exportu po jejich platnosti (export.instance.ttl_days).
#
#  crontab:
#    15 3 * * *  /var/www/myucto.cz/cmd/cron-retention.sh
# =============================================================================
set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
PHP_BIN="${MYINVOICE_PHP_BIN:-php}"
LOG_DIR="${MYINVOICE_DATA_DIR:-$PROJECT_ROOT}/log/cron"
mkdir -p "$LOG_DIR"
exec "$PHP_BIN" "$PROJECT_ROOT/api/bin/cron-retention.php" "$@" \
    >> "$LOG_DIR/retention-$(date +%Y-%m-%d).log" 2>&1
