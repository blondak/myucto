#!/usr/bin/env sh
set -eu

if [ "${MYINVOICE_SKIP_MIGRATIONS:-0}" != "1" ]; then
  attempts="${MYINVOICE_MIGRATE_ATTEMPTS:-20}"
  delay="${MYINVOICE_MIGRATE_DELAY:-3}"
  current_attempt=1
  while :; do
    if php /var/www/html/api/bin/migrate.php; then
      break
    fi
    if [ "$current_attempt" -ge "$attempts" ]; then
      echo "Migration failed after $attempts attempts. Aborting startup." >&2
      exit 1
    fi
    echo "Migration attempt $current_attempt/$attempts failed. Retrying in ${delay}s..." >&2
    current_attempt=$((current_attempt + 1))
    sleep "$delay"
  done
fi

# Vestavěný cron (default zapnutý; multi-replica deployment si dá MYINVOICE_ENABLE_CRON=0,
# jinak by úlohy běžely v každé replice). Spouští se PO migracích, aby schéma bylo hotové.
if [ "${MYINVOICE_ENABLE_CRON:-1}" != "0" ]; then
  # Cron v Debianu nedědí ENV kontejneru → vydumpujeme ho pro wrapper. Obsahuje tajemství
  # (DB heslo, SMTP, klíče), proto jen pro root + www-data (0640), ne world-readable.
  export -p > /etc/myucto-cron.env
  chmod 0640 /etc/myucto-cron.env
  chown root:www-data /etc/myucto-cron.env 2>/dev/null || true

  # Přegeneruj crontab podle SKUTEČNÉ konfigurace instalace. V image je z buildu
  # plný katalog (runtime config tam ještě nebyl); tady už config i schéma známe,
  # takže úlohy bez nastaveného vstupu (inbox přijatých faktur, adresář výpisů)
  # do plánu vůbec nedáme. Píšeme přes temp + mv, ať cron nikdy nevidí půlku
  # souboru, a při jakémkoli selhání necháváme build-time verzi na pokoji.
  if php /var/www/html/tools/generateDockerCrontab.php --runtime > /tmp/myucto-crontab.new 2>/tmp/myucto-crontab.err \
     && [ -s /tmp/myucto-crontab.new ]; then
    mv /tmp/myucto-crontab.new /etc/cron.d/myucto
    chown root:root /etc/cron.d/myucto
    chmod 0644 /etc/cron.d/myucto
    echo "[entrypoint] crontab přegenerován podle konfigurace ($(grep -c myucto-cron-run /etc/cron.d/myucto) úloh)"
  else
    echo "[entrypoint] VAROVÁNÍ: crontab se nepodařilo přegenerovat, používám verzi z image" >&2
    # `|| true` je kvůli `set -e` výše — prázdný .err nesmí shodit entrypoint.
    { [ -s /tmp/myucto-crontab.err ] && cat /tmp/myucto-crontab.err >&2; } || true
  fi
  rm -f /tmp/myucto-crontab.new /tmp/myucto-crontab.err

  # Selhání cronu nesmí shodit kontejner (Apache poběží dál).
  if cron; then
    echo "[entrypoint] vestavěný cron spuštěn (logy v \${MYINVOICE_DATA_DIR}/log/cron)"
  else
    echo "[entrypoint] VAROVÁNÍ: cron se nepodařilo spustit — pokračuji bez něj" >&2
  fi
fi

exec apache2-foreground
