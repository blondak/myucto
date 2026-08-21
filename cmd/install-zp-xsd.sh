#!/usr/bin/env bash
set -euo pipefail

if [ "$#" -lt 1 ]; then
    echo "Usage: install-zp-xsd.sh <directory with downloaded XSD>" >&2
    exit 1
fi

script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
project_root="$(cd "${script_dir}/.." && pwd)"
php_bin="${MYINVOICE_PHP_BIN:-php}"

if ! command -v "$php_bin" >/dev/null 2>&1; then
    echo "PHP CLI ('$php_bin') nebylo nalezeno na PATH." >&2
    exit 1
fi

exec "$php_bin" "${project_root}/tools/installZpXsd.php" "$1"
