#!/usr/bin/env sh
# Entrypoint pro alpine variantu (nginx + php-fpm + cronie).
# Funkční parity s docker-entrypoint.sh (Debian/Apache): migrace → cron → web server.
set -eu

# Dynamický port (parity s Apache ${PORT} — Railway/Heroku/Fly přidělují port z env).
PORT="${PORT:-80}"
if [ "$PORT" != "80" ]; then
  sed -i "s/listen          80 default_server;/listen          ${PORT} default_server;/" /etc/nginx/nginx.conf
fi

# RAM tuning: na malém hostu sniž počet php-fpm workerů (každý ~30–60 MB).
# Default 8 (rozumný strop); PHP_FPM_MAX_CHILDREN=4 pro ~512 MB–1 GB RAM stroje.
if [ -n "${PHP_FPM_MAX_CHILDREN:-}" ]; then
  sed -i "s/^pm.max_children = .*/pm.max_children = ${PHP_FPM_MAX_CHILDREN}/" \
    /usr/local/etc/php-fpm.d/zz-myucto.conf
fi
# Volitelně sniž opcache shared paměť (default 128 MB) — OPCACHE_MEMORY=64 pro tiny host.
if [ -n "${OPCACHE_MEMORY:-}" ]; then
  sed -i "s/^opcache.memory_consumption = .*/opcache.memory_consumption = ${OPCACHE_MEMORY}/" \
    /usr/local/etc/php/conf.d/myucto.ini
fi

# --- migrace (stejný kontrakt jako Debian entrypoint) ----------------------
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

# --- vestavěný cron (cronie) -----------------------------------------------
# Cron nedědí ENV kontejneru → vydumpujeme ho pro wrapper (0640 root:www-data,
# obsahuje tajemství). cronie čte /etc/cron.d/myucto (Vixie formát s user polem).
if [ "${MYINVOICE_ENABLE_CRON:-1}" != "0" ]; then
  export -p > /etc/myucto-cron.env
  chmod 0640 /etc/myucto-cron.env
  chown root:www-data /etc/myucto-cron.env 2>/dev/null || true

  # Přegeneruj crontab podle skutečné konfigurace instalace (parity s Debian
  # entrypointem — viz komentář tam). Temp + mv, ať cronie nikdy nečte půlku
  # souboru; při selhání zůstává build-time verze z image.
  if php /var/www/html/tools/generateDockerCrontab.php --runtime > /tmp/myucto-crontab.new 2>/tmp/myucto-crontab.err \
     && [ -s /tmp/myucto-crontab.new ]; then
    mv /tmp/myucto-crontab.new /etc/cron.d/myucto
    chown root:root /etc/cron.d/myucto
    chmod 0644 /etc/cron.d/myucto
    echo "[entrypoint] crontab přegenerován podle konfigurace ($(grep -c myucto-cron-run /etc/cron.d/myucto) úloh)"
  else
    echo "[entrypoint] VAROVÁNÍ: crontab se nepodařilo přegenerovat, používám verzi z image" >&2
    { [ -s /tmp/myucto-crontab.err ] && cat /tmp/myucto-crontab.err >&2; } || true
  fi
  rm -f /tmp/myucto-crontab.new /tmp/myucto-crontab.err

  # Selhání cronu nesmí shodit kontejner (web poběží dál). cronie crond daemonizuje sám.
  if crond; then
    echo "[entrypoint] vestavěný cron spuštěn (logy v \${MYINVOICE_DATA_DIR}/log/cron)"
  else
    echo "[entrypoint] VAROVÁNÍ: cron se nepodařilo spustit — pokračuji bez něj" >&2
  fi
fi

# --- web server ------------------------------------------------------------
# php-fpm v popředí (-F) ale na pozadí shellu → zachová stderr do `docker logs`.
# nginx exec v popředí jako hlavní proces (PID 1 = tini reapuje zombie po fpm).
php-fpm -F &
exec nginx -g 'daemon off;'
