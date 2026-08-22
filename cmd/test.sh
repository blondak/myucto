#!/usr/bin/env bash
# Spustí PHPUnit testovou sadu (api/tests/).
#
# Použití:
#   cmd/test.sh                  # všechny testy (Unit + Integration)
#   cmd/test.sh tests/Unit       # jen Unit
#   cmd/test.sh --testsuite=Unit
#   cmd/test.sh --filter=GpcParser
set -euo pipefail

PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PHP_BIN="${MYINVOICE_PHP_BIN:-php}"
cd "$PROJECT_ROOT/api"

if [[ ! -f vendor/bin/phpunit ]]; then
  echo "ERROR: vendor/bin/phpunit chybí. Spusť: cd api && composer install" >&2
  exit 1
fi

if [ "$#" -eq 0 ]; then
  "$PHP_BIN" bin/test-parallel.php
else
  "$PHP_BIN" vendor/bin/phpunit --colors=auto "$@"
fi
