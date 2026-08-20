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
cd "$PROJECT_ROOT/api"

if [[ ! -f vendor/bin/phpunit ]]; then
  echo "ERROR: vendor/bin/phpunit chybí. Spusť: cd api && composer install" >&2
  exit 1
fi

if [ "$#" -eq 0 ]; then
  php bin/test-parallel.php
else
  vendor/bin/phpunit --colors=auto "$@"
fi
