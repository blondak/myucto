#!/usr/bin/env bash

set -euo pipefail

PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

if ! command -v php >/dev/null 2>&1; then
    echo "Obnova ciselniku JMHZ selhala: PHP CLI nebylo nalezeno na PATH." >&2
    exit 1
fi

exec php "$PROJECT_ROOT/tools/downloadJmhzCodebooks.php" "$@"
