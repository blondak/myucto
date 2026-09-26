#!/usr/bin/env bash
# Přešifrování mzdového archivu a přebalení mzdových hodnot na aktuální klíč.
# Volby se předávají beze změny, viz api/bin/payroll-archive-reencrypt.php.
#   cmd/payroll-archive-reencrypt.sh --dry-run
#   cmd/payroll-archive-reencrypt.sh --rewrap
set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
PHP_BIN="${MYINVOICE_PHP_BIN:-php}"
exec "$PHP_BIN" "$PROJECT_ROOT/api/bin/payroll-archive-reencrypt.php" "$@"
