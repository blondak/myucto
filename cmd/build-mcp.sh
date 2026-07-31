#!/usr/bin/env bash
# Sestaví jednosouborový build MCP serveru do MCP/dist/myucto-mcp.mjs.
#
# Výsledek je jeden .mjs bez závislostí — uživatel ho zkopíruje kamkoliv a spustí
# `node myucto-mcp.mjs`. Node 20+ je potřeba pořád; build odstraňuje jen
# `npm install` a adresář node_modules, ne runtime.
#
# Windows obdoba: cmd/build-mcp.ps1 (drž je v synchronizaci).
#
# Použití:
#   ./cmd/build-mcp.sh
#   ./cmd/build-mcp.sh --clean     # smaže node_modules a nainstaluje načisto

set -euo pipefail

CLEAN=0
for arg in "$@"; do
    case "$arg" in
        --clean) CLEAN=1 ;;
        *) echo "Neznámý přepínač: $arg" >&2; exit 2 ;;
    esac
done

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
MCP_DIR="$(dirname "$SCRIPT_DIR")/MCP"

[ -d "$MCP_DIR" ] || { echo "Složka MCP nenalezena: $MCP_DIR" >&2; exit 1; }

# Ověření Node — bez něj build ani běh nedávají smysl a chyba z npm by byla méně čitelná.
command -v node >/dev/null 2>&1 || {
    echo 'Node.js nenalezen v PATH. Nainstalujte Node 20 nebo novější.' >&2; exit 1
}
NODE_VERSION="$(node --version | sed 's/^v//')"
NODE_MAJOR="${NODE_VERSION%%.*}"
if [ "$NODE_MAJOR" -lt 20 ]; then
    echo "Node $NODE_VERSION je příliš starý, potřeba je 20 nebo novější." >&2
    exit 1
fi
echo "Node $NODE_VERSION"

cd "$MCP_DIR"

if [ "$CLEAN" -eq 1 ] && [ -d node_modules ]; then
    echo 'Mažu node_modules…'
    rm -rf node_modules
fi

if [ ! -d node_modules ]; then
    echo 'Instaluji závislosti…'
    npm install --no-audit --no-fund
fi

echo 'Sestavuji…'
npm run build

OUT="$MCP_DIR/dist/myucto-mcp.mjs"
[ -f "$OUT" ] || { echo "Build neskončil chybou, ale $OUT neexistuje." >&2; exit 1; }

# Kouřová zkouška: soubor se musí dát načíst. Bez toho by se rozbitý bundle
# poznal až u uživatele — server nic nevypíše, dokud nedostane první zprávu.
node --check "$OUT"

SIZE_KB=$(( $(wc -c < "$OUT") / 1024 ))
echo
echo "Hotovo: MCP/dist/myucto-mcp.mjs (${SIZE_KB} kB)"
echo 'Spuštění: node MCP/dist/myucto-mcp.mjs'
