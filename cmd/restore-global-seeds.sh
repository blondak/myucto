#!/usr/bin/env bash
# Dotah globálních seedů z migrací (svátky, katalogy, příjemci podání), které
# instalace ztratila. Bez voleb jen náhled, viz api/bin/restore-global-seeds.php.
#   cmd/restore-global-seeds.sh
#   cmd/restore-global-seeds.sh --apply
set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
PHP_BIN="${MYINVOICE_PHP_BIN:-php}"
exec "$PHP_BIN" "$PROJECT_ROOT/api/bin/restore-global-seeds.php" "$@"
