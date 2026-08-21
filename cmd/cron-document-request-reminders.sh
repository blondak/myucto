#!/usr/bin/env bash
# =============================================================================
#  cron-document-request-reminders.sh — e-mailová urgence klientovi na otevřené
#  vyžádání chybějícího dokladu (Fáze F, audit 2026-07).
#  Frekvence: 1x denně, doporučeno 09:00 v pracovní dny (Po–Pá)
#
#  Volitelné argumenty (předej jako parametry .sh):
#    --days=N      požadavek musí být starší než N dní (default 3)
#    --cooldown=N  cooldown mezi urgencemi (default 7)
#    --dry-run     jen vypíše, co by se odeslalo
#
#  crontab (každý pracovní den 09:00):
#    0 9 * * 1-5  /var/www/myucto.cz/cmd/cron-document-request-reminders.sh
# =============================================================================
set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
PHP_BIN="${MYINVOICE_PHP_BIN:-php}"
LOG_DIR="${MYINVOICE_DATA_DIR:-$PROJECT_ROOT}/log/cron"
mkdir -p "$LOG_DIR"
exec "$PHP_BIN" "$PROJECT_ROOT/api/bin/cron-document-request-reminders.php" "$@" \
    >> "$LOG_DIR/document-request-reminders-$(date +%Y-%m-%d).log" 2>&1
