#!/usr/bin/env bash
# cmd/release-bundle.sh — produkční bundle myucto-X.Y.Z.tar.gz (+ .sha256).
#
# Co dělá:
#   1. Verze čte z VERSION (root).
#   2. Build web/dist (pnpm install + build) — přeskočí, pokud existuje a není --rebuild.
#   3. Build manual HTML + PDF — přeskočí, pokud existuje a není --rebuild.
#   4. Composer install --no-dev s VENDOR DIR mimo api/vendor (nepoškodí dev vendor).
#   5. Sbalí všechno do dist/myucto-X.Y.Z.tar.gz (top-level = "myucto-X.Y.Z/").
#   6. Vypočítá SHA256 sidecar.
#
# Použití:
#   bash cmd/release-bundle.sh           # smart cache (web/dist + manual jen pokud chybí)
#   bash cmd/release-bundle.sh --rebuild # vždy rebuild všeho
#
# Upload do GitHub release (po vytvoření v Issue):
#   gh release upload vX.Y.Z dist/myucto-X.Y.Z.tar.gz dist/myucto-X.Y.Z.tar.gz.sha256
#
# Spouští se z gitbashe na Windows i z Linuxu. Žádný stav lokálního dev vendoru
# neměníme — composer install --no-dev cílí do dist/_vendor.prod přes COMPOSER_VENDOR_DIR.

set -euo pipefail

cd "$(dirname "$0")/.."
REPO_ROOT="$(pwd)"

REBUILD=0
for arg in "$@"; do
  case "$arg" in
    --rebuild) REBUILD=1 ;;
    *) echo "[release-bundle] unknown arg: $arg"; exit 1 ;;
  esac
done

VERSION="$(tr -d '[:space:]' < VERSION)"
if [ -z "$VERSION" ]; then
  echo "[release-bundle] VERSION soubor je prázdný"; exit 1
fi
NAME="myucto-${VERSION}"
OUT_DIR="dist"
OUT_TGZ="${OUT_DIR}/${NAME}.tar.gz"
STAGE_DIR="${OUT_DIR}/_stage/${NAME}"
PROD_VENDOR="${OUT_DIR}/_vendor.prod"

mkdir -p "${OUT_DIR}"

echo "=== MyUcto.cz release bundle ${VERSION} ==="

# 1. web/dist
if [ ${REBUILD} -eq 1 ] || [ ! -d web/dist ] || [ ! -f web/dist/index.html ]; then
  echo "[build] web/dist (pnpm build)"
  ( cd web && pnpm install --frozen-lockfile && pnpm build )
else
  echo "[skip] web/dist už existuje (--rebuild pro fresh)"
fi

# 2. manual HTML + PDF
if [ ${REBUILD} -eq 1 ] || [ ! -f manual/manual.pdf ] || [ ! -f manual/generated/INDEX.html ]; then
  echo "[build] manual HTML + PDF"
  php tools/generateManualHtml.php
  php tools/exportManualToPdf.php
else
  echo "[skip] manual už vygenerovaný (--rebuild pro fresh)"
fi

# 3. Production vendor — do dist/_vendor.prod, NE do api/vendor (dev zůstává)
LOCK_HASH="$(md5sum api/composer.lock | awk '{print $1}')"
NEED_VENDOR_BUILD=1
if [ ${REBUILD} -eq 0 ] && [ -f "${PROD_VENDOR}/.lock-hash" ] && [ -f "${PROD_VENDOR}/autoload.php" ]; then
  if [ "$(cat "${PROD_VENDOR}/.lock-hash")" = "${LOCK_HASH}" ]; then
    NEED_VENDOR_BUILD=0
    echo "[skip] ${PROD_VENDOR} cache hit (composer.lock beze změny)"
  fi
fi

if [ ${NEED_VENDOR_BUILD} -eq 1 ]; then
  echo "[build] composer install --no-dev → ${PROD_VENDOR}"
  rm -rf "${PROD_VENDOR}"
  mkdir -p "${PROD_VENDOR}"
  # COMPOSER_VENDOR_DIR musí být absolutní pro --working-dir cwd kombinaci.
  COMPOSER_VENDOR_DIR="${REPO_ROOT}/${PROD_VENDOR}" \
    composer install \
      --working-dir=api \
      --no-dev \
      --optimize-autoloader \
      --no-interaction \
      --no-progress
  echo "${LOCK_HASH}" > "${PROD_VENDOR}/.lock-hash"
fi

# 4. Stage tracked files via git archive (čisté, žádný build junk)
echo "[stage] ${STAGE_DIR}"
rm -rf "${OUT_DIR}/_stage"
mkdir -p "${STAGE_DIR}"
git archive --format=tar HEAD | tar -x -C "${STAGE_DIR}"

# 5. Přidat built artefakty
echo "[stage] +web/dist +manual/generated +manual.pdf +api/vendor"
mkdir -p "${STAGE_DIR}/web" "${STAGE_DIR}/manual" "${STAGE_DIR}/api"
cp -r web/dist           "${STAGE_DIR}/web/dist"
cp -r manual/generated   "${STAGE_DIR}/manual/generated"
cp    manual/manual.pdf  "${STAGE_DIR}/manual/manual.pdf"
cp -r "${PROD_VENDOR}"   "${STAGE_DIR}/api/vendor"
# .lock-hash je interní marker pro náš cache, do bundle ho nemontuj.
rm -f "${STAGE_DIR}/api/vendor/.lock-hash"

# 6. tar.gz + sha256
echo "[pack] ${OUT_TGZ}"
( cd "${OUT_DIR}/_stage" && tar -czf "../${NAME}.tar.gz" "${NAME}" )
rm -rf "${OUT_DIR}/_stage"

SHA="$(sha256sum "${OUT_TGZ}" | awk '{print $1}')"
echo "${SHA}  ${NAME}.tar.gz" > "${OUT_TGZ}.sha256"

SIZE_MB="$(du -m "${OUT_TGZ}" | awk '{print $1}')"

echo ""
echo "============================================================"
echo "  HOTOVO"
echo "  Bundle:  ${OUT_TGZ}  (${SIZE_MB} MB)"
echo "  SHA256:  ${SHA}"
echo ""
echo "  Upload do GitHub release:"
echo "    gh release upload v${VERSION} ${OUT_TGZ} ${OUT_TGZ}.sha256"
echo "============================================================"
