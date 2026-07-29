#!/usr/bin/env bash
# One-click install z pre-built image na GHCR (žádný local build).
#
#   1. Vygeneruje .env s random DB hesly (pokud chybí)
#   2. Vygeneruje cfg.docker.php z cfg.sample.php s random secrets (pokud chybí)
#   3. docker compose pull (image z ghcr.io/radekhulan/myucto:latest)
#   4. docker compose up -d (entrypoint sám spustí migrace před apache2-foreground)
#   5. Počká až app odpoví na HTTP (= migrace doběhly, apache běží)
#   6. Vypíše URL k setup wizardu
#
# Používá docker-compose.production.yml (image pull, žádný build).
# Idempotentní — bezpečné spouštět opakovaně.
set -euo pipefail

# Detekce PROJECT_ROOT — skript se pouští dvěma způsoby:
#   a) standalone install (curl 3 souborů do jedné složky): script vedle compose file
#   b) z klonu repa: script v `cmd/`, compose file o úroveň výš
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
COMPOSE_FILE="docker-compose.production.yml"
if [[ -f "${SCRIPT_DIR}/${COMPOSE_FILE}" ]]; then
  PROJECT_ROOT="${SCRIPT_DIR}"
elif [[ -f "${SCRIPT_DIR}/../${COMPOSE_FILE}" ]]; then
  PROJECT_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"
else
  echo "ERROR: ${COMPOSE_FILE} not found next to script ani v ${SCRIPT_DIR}/.." >&2
  echo "       Stáhni jej z https://raw.githubusercontent.com/radekhulan/myucto/master/${COMPOSE_FILE}" >&2
  exit 1
fi
cd "$PROJECT_ROOT"

if ! command -v docker >/dev/null 2>&1; then
  echo "ERROR: docker not found in PATH" >&2; exit 1
fi
if ! docker compose version >/dev/null 2>&1; then
  echo "ERROR: 'docker compose' (v2) plugin required" >&2; exit 1
fi

COMPOSE=(docker compose -f "$COMPOSE_FILE")

# --- helpers pro robustní start -------------------------------------------
app_log_tail() { "${COMPOSE[@]}" logs --no-color --tail "${1:-40}" app 2>&1 || true; }
app_network_broken() {
  app_log_tail 40 | grep -qiE 'getaddrinfo (for )?db failed|getaddrinfo failed|php_network_getaddresses|Temporary failure in name resolution|Name or service not known'
}
port_holder_status() {
  local port="$1" out name image ports
  out="$(docker ps --format '{{.Names}}|{{.Image}}|{{.Ports}}' 2>/dev/null || true)"
  while IFS='|' read -r name image ports; do
    [[ -z "${ports:-}" ]] && continue
    case "$ports" in *":${port}->"*) ;; *) continue ;; esac
    if [[ "$name" == *myucto* || "$image" == *myucto* ]]; then echo "OURS $name";
    else echo "FOREIGN $name $image"; fi
    return 0
  done <<< "$out"
}

# Smart: pokud už app běží, tohle je spíš update než čerstvá instalace.
running_image="$(docker ps --filter label=com.docker.compose.service=app --format '{{.Image}}' 2>/dev/null | grep -i myucto | head -1 || true)"
if [[ -n "$running_image" ]]; then
  echo "==> Pozn.: app už běží (image '${running_image}'). Pro pouhou aktualizaci použij cmd/docker-update.sh."
  echo "    (tenhle skript je idempotentní — klidně pokračuj, jen přepulluje a nahodí znovu)"
fi

# --- 1. .env ---------------------------------------------------------------
if [[ ! -f .env ]]; then
  echo "==> Generating .env with random DB passwords…"
  DB_ROOT_PASSWORD=$(openssl rand -base64 24 | tr -d '=+/' | head -c 28)
  DB_PASSWORD=$(openssl rand -base64 24      | tr -d '=+/' | head -c 28)
  cat > .env <<EOF
# MyUcto.cz — Docker compose env (gitignored)
APP_PORT=8080
DB_PORT=3307
DB_NAME=myucto
DB_USER=myucto
DB_ROOT_PASSWORD=${DB_ROOT_PASSWORD}
DB_PASSWORD=${DB_PASSWORD}
EOF
  echo "    .env written (passwords randomised)"
else
  echo "==> .env already exists (skipping)"
fi
set -a; . ./.env; set +a

# --- 2. cfg.docker.php -----------------------------------------------------
if [[ ! -f cfg.docker.php ]]; then
  echo "==> Generating cfg.docker.php from cfg.sample.php with Docker defaults…"
  PEPPER=$(openssl rand -base64 32)
  ENC_KEY=$(openssl rand -base64 32)
  cp cfg.sample.php cfg.docker.php
  # cfg.sample.php has TWO `'host' => '127.0.0.1',` lines (db block then redis block).
  # First occurrence becomes 'db', second becomes 'redis' — done via perl (portable;
  # BSD sed on macOS does not support GNU's `0,/pat/` range addressing).
  APP_URL="http://localhost:${APP_PORT}"
  perl -i -pe '
      BEGIN { $n = 0 }
      if (/host.*127\.0\.0\.1/) {
          $n++;
          s/127\.0\.0\.1/db/    if $n == 1;
          s/127\.0\.0\.1/redis/ if $n == 2;
      }
  ' cfg.docker.php
  sed -i.bak \
      -e "s|'name'    => 'myucto',|'name'    => '${DB_NAME}',|" \
      -e "s|'user'    => 'root',|'user'    => '${DB_USER}',|" \
      -e "s|'pass'    => 'CHANGE-ME',|'pass'    => '${DB_PASSWORD}',|" \
      -e "s|'pepper' => 'CHANGE-ME',|'pepper' => '${PEPPER}',|" \
      -e "s|'secret_encryption_key' => '',|'secret_encryption_key' => '${ENC_KEY}',|" \
      -e "s|'env'    => 'production',|'env'    => 'development',|" \
      -e "s|'url'    => 'https://dev.example.com',|'url'    => '${APP_URL}',|" \
      -e "s|'cookie_name'   => '__Host-myinvoice_session',|'cookie_name'   => 'myinvoice_session',|" \
      -e "s|'cookie_secure' => true,|'cookie_secure' => false,|" \
      cfg.docker.php
  rm -f cfg.docker.php.bak
  echo "    cfg.docker.php written"
  echo ""
  echo "    !!  Edit cfg.docker.php to fill in SMTP, Cloudflare Turnstile, IP allowlist  !!"
  echo ""
else
  echo "==> cfg.docker.php already exists (skipping)"
fi

# --- 3. pull image from GHCR ----------------------------------------------
echo "==> Pulling image from GHCR…"
"${COMPOSE[@]}" pull app

# --- pre-flight: hostový port aplikace ------------------------------------
echo "==> Pre-flight: kontrola hostového portu ${APP_PORT}…"
holder="$(port_holder_status "${APP_PORT}")"
if [[ "$holder" == FOREIGN* ]]; then
  echo "ERROR: Host port ${APP_PORT} drží CIZÍ Docker kontejner: ${holder#FOREIGN }" >&2
  echo "       Uvolni ho ('docker stop …') nebo změň APP_PORT v .env a spusť znovu." >&2
  exit 1
elif [[ "$holder" == OURS* ]]; then
  echo "    Port ${APP_PORT} drží vlastní myucto kontejner (${holder#OURS }) — OK."
fi

# --- 4. up databáze --------------------------------------------------------
# --remove-orphans: uklidí stale kontejnery z jiného compose souboru; jinak
# zbylý app kontejner drží port a nový se nepřipojí k síti ('port already
# allocated' → app nepřeloží 'db' → migrace v cyklu padají).
echo "==> Starting database…"
"${COMPOSE[@]}" up -d --remove-orphans db

# --- 5. wait for DB health -------------------------------------------------
echo "==> Waiting for database to become healthy…"
for i in {1..45}; do
  status=$("${COMPOSE[@]}" ps --format json db 2>/dev/null | grep -o '"Health":"[^"]*"' | head -1 | cut -d'"' -f4)
  if [[ "$status" == "healthy" ]]; then echo "    DB ready."; break; fi
  [[ "$status" == "unhealthy" ]] && echo "    DB hlásí 'unhealthy' — čekám dál (attempt $i/45)…"
  sleep 2
  if [[ $i -eq 45 ]]; then
    echo "ERROR: DB failed to become healthy in ~90s. Check '${COMPOSE[*]} logs db'." >&2
    exit 1
  fi
done

# --- 4b. up app ------------------------------------------------------------
echo "==> Starting app…"
"${COMPOSE[@]}" up -d --remove-orphans app

# --- 6. wait for app (entrypoint runs migrations) -------------------------
# Migrace se spouští automaticky z entrypointu. Místo druhého explicitního
# migrate (= race condition s entrypointem) jen čekáme, až app odpoví na
# /api/health (v ALLOWED_PATHS pro FirstRunLockMiddleware → 200 i ve fresh state).
echo "==> Waiting for app to become available (entrypoint runs migrations)…"
recovered=0
for i in {1..90}; do
  if curl -fsS -m 3 -o /dev/null "http://localhost:${APP_PORT}/api/health"; then
    echo "    App ready."
    break
  fi
  if (( i % 5 == 0 )) && [[ "$recovered" == "0" ]] && app_network_broken; then
    echo "    App běží, ale nemá compose síť (DNS 'db' selhává) → auto-recovery: force-recreate app." >&2
    "${COMPOSE[@]}" up -d --remove-orphans --force-recreate app >/dev/null 2>&1 || true
    recovered=1
    sleep 3
    continue
  fi
  sleep 2
  if [[ $i -eq 90 ]]; then
    echo "ERROR: App failed to respond in time. Check '${COMPOSE[*]} logs app':" >&2
    app_log_tail 25 >&2
    exit 1
  fi
done

# --- 6. report -------------------------------------------------------------
APP_PORT="${APP_PORT:-8080}"
echo ""
echo "============================================================"
echo " MyUcto.cz is up at:  http://localhost:${APP_PORT}"
echo " Image:                  ghcr.io/radekhulan/myucto:latest"
echo ""
echo " The browser will land on the setup wizard:"
echo "   1. Admin user (name, email, password ≥ 12 chars)"
echo "   2. Supplier (IČO → Načíst z ARES → bank account)"
echo "   3. Optional sample data"
echo ""
echo " Useful (-f ${COMPOSE_FILE} flag is needed for all compose calls):"
echo "   docker compose -f ${COMPOSE_FILE} logs -f app"
echo "   docker compose -f ${COMPOSE_FILE} pull && docker compose -f ${COMPOSE_FILE} up -d   # update"
echo "   docker compose -f ${COMPOSE_FILE} down           # stop (data persists)"
echo "   docker compose -f ${COMPOSE_FILE} down -v        # stop + WIPE volumes"
echo "============================================================"
