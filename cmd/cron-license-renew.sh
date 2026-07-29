#!/usr/bin/env bash
# =============================================================================
#  cron-license-renew.sh — denní obnova licenčního tokenu (E4)
#  Frekvence: 1× denně. Doplněk k obnově, kterou spouští i první přihlášený
#  request dne (LicenseMiddleware); cron pokrývá instalace, které přes den
#  nikdo neotevře. Mutex uvnitř služby (atomický UPDATE dle CURDATE) zajistí,
#  že obnova proběhne max. 1× denně; síťovou chybu jen zaloguje.
#
#  crontab:
#    0 5 * * *  /var/www/myucto.cz/cmd/cron-license-renew.sh
# =============================================================================
set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
LOG_DIR="${MYINVOICE_DATA_DIR:-$PROJECT_ROOT}/log/cron"
mkdir -p "$LOG_DIR"
exec php "$PROJECT_ROOT/api/bin/cron-license-renew.php" "$@" \
    >> "$LOG_DIR/license-renew-$(date +%Y-%m-%d).log" 2>&1
