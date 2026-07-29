#!/usr/bin/env bash
# Prevod dat z MyInvoice do bezici MyUcto.cz Docker instalace.
#
# Obali `api/bin/MyInvoiceMigrate.php` a vyresi to, co v Dockeru navic boli:
# app kontejner musi na zdrojovou databazi vubec dosahnout.
#
# Tri podporovane zdroje:
#
#   1. MyInvoice v JINEM Docker stacku (Docker -> Docker)
#        cmd/docker-migrate-from-myinvoice.sh --source-container myinvoice-db-1 \
#             --source-db myinvoice --source-user root --source-password tajne
#      Skript docasne pripoji zdrojovy kontejner do site MyUcta a po dokonceni
#      ho zase odpoji. Zdrojovy stack zustava beze zmeny.
#
#   2. MyInvoice na HOSTITELI (nativni MariaDB vedle Dockeru)
#        cmd/docker-migrate-from-myinvoice.sh --source-host host.docker.internal \
#             --source-db myinvoice --source-user root --source-password tajne
#
#   3. Vlastni URL (cokoli dosazitelneho z app kontejneru)
#        cmd/docker-migrate-from-myinvoice.sh --source-url "mysql://root:tajne@10.0.0.5:3306/myinvoice"
#
# Cilova databaze se bere z konfigurace bezici instance (cfg.php v kontejneru).
# Migrator sam pripravi schema, prenese data a dojede migrace MyUcta;
# spoustet `migrate.php` rucne netreba.
#
# Postup a kontroly po prevodu: manual/06_Prevod_z_MyInvoice.md
set -euo pipefail

SOURCE_CONTAINER=""
SOURCE_HOST=""
SOURCE_URL=""
SOURCE_DB="myinvoice"
SOURCE_USER="root"
SOURCE_PASSWORD=""
SOURCE_PORT="3306"
AUTO_YES=0
EXTRA_ARGS=()

usage() {
    sed -n '2,30p' "$0" | sed 's/^# \{0,1\}//'
    exit 1
}

while [ $# -gt 0 ]; do
    case "$1" in
        --source-container) SOURCE_CONTAINER="$2"; shift 2 ;;
        --source-host)      SOURCE_HOST="$2"; shift 2 ;;
        --source-url)       SOURCE_URL="$2"; shift 2 ;;
        --source-db)        SOURCE_DB="$2"; shift 2 ;;
        --source-user)      SOURCE_USER="$2"; shift 2 ;;
        --source-password)  SOURCE_PASSWORD="$2"; shift 2 ;;
        --source-port)      SOURCE_PORT="$2"; shift 2 ;;
        --yes|-y)           AUTO_YES=1; shift ;;
        -h|--help)          usage ;;
        *)                  EXTRA_ARGS+=("$1"); shift ;;
    esac
done

selected=0
[ -n "$SOURCE_CONTAINER" ] && selected=$((selected + 1))
[ -n "$SOURCE_HOST" ] && selected=$((selected + 1))
[ -n "$SOURCE_URL" ] && selected=$((selected + 1))
if [ "$selected" -ne 1 ]; then
    echo "Zvol prave jeden zdroj: --source-container | --source-host | --source-url" >&2
    exit 1
fi

command -v docker >/dev/null 2>&1 || { echo "docker not found in PATH" >&2; exit 1; }
docker compose version >/dev/null 2>&1 || { echo "'docker compose' (v2) plugin required" >&2; exit 1; }

PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$PROJECT_ROOT"

COMPOSE_FILE="docker-compose.yml"
if [ -f docker-compose.production.yml ] &&
   [ -n "$(docker compose -f docker-compose.production.yml ps --status running --format '{{.Name}}' 2>/dev/null)" ]; then
    COMPOSE_FILE="docker-compose.production.yml"
fi

APP_CONTAINER="$(docker compose -f "$COMPOSE_FILE" ps -q app 2>/dev/null || true)"
if [ -z "$APP_CONTAINER" ]; then
    echo "Sluzba 'app' nebezi. Spust nejdriv stack: docker compose -f $COMPOSE_FILE up -d" >&2
    exit 1
fi

# URL-encode pro uzivatelske jmeno a heslo (mohou obsahovat @ : / #).
urlenc() {
    local s="$1" out="" c
    for (( i = 0; i < ${#s}; i++ )); do
        c="${s:i:1}"
        case "$c" in
            [a-zA-Z0-9.~_-]) out+="$c" ;;
            *) out+="$(printf '%%%02X' "'$c")" ;;
        esac
    done
    printf '%s' "$out"
}

CONNECTED_NETWORK=""
cleanup() {
    if [ -n "$CONNECTED_NETWORK" ]; then
        echo ""
        echo "==> Odpojuji '$SOURCE_CONTAINER' ze site '$CONNECTED_NETWORK'..."
        docker network disconnect "$CONNECTED_NETWORK" "$SOURCE_CONTAINER" >/dev/null 2>&1 || true
    fi
}
trap cleanup EXIT

if [ -n "$SOURCE_URL" ]; then
    EFFECTIVE_URL="$SOURCE_URL"
elif [ -n "$SOURCE_HOST" ]; then
    EFFECTIVE_URL="mysql://$(urlenc "$SOURCE_USER"):$(urlenc "$SOURCE_PASSWORD")@${SOURCE_HOST}:${SOURCE_PORT}/${SOURCE_DB}"
else
    SRC_ID="$(docker ps -q --filter "name=^/${SOURCE_CONTAINER}$" | head -n1)"
    [ -z "$SRC_ID" ] && SRC_ID="$(docker ps -q --filter "name=${SOURCE_CONTAINER}" | head -n1)"
    if [ -z "$SRC_ID" ]; then
        echo "Kontejner '$SOURCE_CONTAINER' nebezi. Zkontroluj: docker ps" >&2
        exit 1
    fi

    TARGET_NETWORK="$(docker inspect -f '{{range $k,$v := .NetworkSettings.Networks}}{{$k}}{{"\n"}}{{end}}' "$APP_CONTAINER" | head -n1)"
    if [ -z "$TARGET_NETWORK" ]; then
        echo "Nepodarilo se zjistit sit app kontejneru." >&2
        exit 1
    fi

    SRC_NETWORKS="$(docker inspect -f '{{range $k,$v := .NetworkSettings.Networks}}{{$k}} {{end}}' "$SRC_ID")"
    case " $SRC_NETWORKS " in
        *" $TARGET_NETWORK "*) ;;
        *)
            echo "==> Pripojuji '$SOURCE_CONTAINER' do site '$TARGET_NETWORK' (docasne)..."
            docker network connect "$TARGET_NETWORK" "$SRC_ID"
            CONNECTED_NETWORK="$TARGET_NETWORK"
            ;;
    esac

    SRC_NAME="$(docker inspect -f '{{.Name}}' "$SRC_ID" | sed 's|^/||')"
    # Uvnitr site se kontejner adresuje svym jmenem, port je vzdy interni 3306.
    EFFECTIVE_URL="mysql://$(urlenc "$SOURCE_USER"):$(urlenc "$SOURCE_PASSWORD")@${SRC_NAME}:3306/${SOURCE_DB}"
fi

MIGRATE_ARGS=("--source-url=$EFFECTIVE_URL")
[ "$AUTO_YES" -eq 1 ] && MIGRATE_ARGS+=("--yes")
[ ${#EXTRA_ARGS[@]} -gt 0 ] && MIGRATE_ARGS+=("${EXTRA_ARGS[@]}")

# Heslo do konzole nevypisuj.
SHOWN_URL="$(printf '%s' "$EFFECTIVE_URL" | sed -E 's#://([^:@/]+):[^@]*@#://\1:***@#')"
echo "==> Zdroj:  $SHOWN_URL"
echo "==> Cil:    databaze bezici MyUcto instance (z cfg.php v kontejneru)"
echo ""

EXIT_CODE=0
if [ "$AUTO_YES" -eq 1 ]; then
    docker compose -f "$COMPOSE_FILE" exec -T app php api/bin/MyInvoiceMigrate.php "${MIGRATE_ARGS[@]}" || EXIT_CODE=$?
else
    # Bez -T, aby fungoval interaktivni dotaz 'ANO'.
    docker compose -f "$COMPOSE_FILE" exec app php api/bin/MyInvoiceMigrate.php "${MIGRATE_ARGS[@]}" || EXIT_CODE=$?
fi

if [ "$EXIT_CODE" -ne 0 ]; then
    echo ""
    echo "Prevod skoncil s chybou (exit $EXIT_CODE). Viz vypis vyse a manual/06_Prevod_z_MyInvoice.md."
    exit "$EXIT_CODE"
fi

echo ""
echo "Hotovo. Soubory ze storage/ (PDF, prilohy, loga) prenes samostatne — databaze je nenese."
