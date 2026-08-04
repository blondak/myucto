#!/usr/bin/env bash

set -euo pipefail

PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

if ! command -v php >/dev/null 2>&1; then
    echo "JMHZ XSD download failed: PHP CLI was not found on PATH." >&2
    exit 1
fi

exec php "$PROJECT_ROOT/tools/downloadJmhzXsd.php"
