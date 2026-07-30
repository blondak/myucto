#!/usr/bin/env sh
# Wrapper pro vestavěný cron v Docker image (instaluje se jako /usr/local/bin/myucto-cron-run).
#
# Systémový cron v Debianu NEdědí ENV proměnné kontejneru, proto je docker-entrypoint.sh
# při startu vydumpuje do /etc/myucto-cron.env (0640 root:www-data) a tento wrapper je
# před spuštěním PHP skriptu načte. Skript běží jako www-data (viz user pole v cron.d),
# v pracovním adresáři aplikace, s logem do ${MYINVOICE_DATA_DIR}/log/cron/<skript>.log.
set -eu

if [ -f /etc/myucto-cron.env ]; then
  # Soubor je `export VAR='value'` (z `export -p`) → bezpečně sourcovatelný.
  . /etc/myucto-cron.env
fi

cd /var/www/html

log_dir="${MYINVOICE_DATA_DIR:-/data}/log/cron"
mkdir -p "$log_dir" 2>/dev/null || true
script="$(basename "${1:-cron}" .php)"

# Výstup (stdout+stderr) do logu — dohledatelné přes /data/log/cron/<script>.log.
exec php "$@" >> "$log_dir/${script}.log" 2>&1
