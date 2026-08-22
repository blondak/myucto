#!/usr/bin/env bash

set -euo pipefail

PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PHP_BIN="${MYINVOICE_PHP_BIN:-php}"

if ! command -v "$PHP_BIN" >/dev/null 2>&1; then
    echo "JMHZ XSD download failed: PHP CLI ('$PHP_BIN') was not found on PATH." >&2
    exit 1
fi

exec "$PHP_BIN" "$PROJECT_ROOT/tools/downloadJmhzXsd.php"
