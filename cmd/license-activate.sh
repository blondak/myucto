#!/usr/bin/env bash
# =============================================================================
#  license-activate.sh — aktivace licenčního klíče z příkazové řádky (E4)
#  Použití:  ./license-activate.sh MYU-XXXX-XXXX-XXXX-XXXX [--takeover]
# =============================================================================
set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
PHP_BIN="${MYINVOICE_PHP_BIN:-php}"
exec "$PHP_BIN" "$PROJECT_ROOT/api/bin/license-activate.php" "$@"
