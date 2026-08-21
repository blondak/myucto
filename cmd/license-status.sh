#!/usr/bin/env bash
# =============================================================================
#  license-status.sh — výpis aktuálního stavu licence (E4)
#  Použití:  ./license-status.sh
# =============================================================================
set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
PHP_BIN="${MYINVOICE_PHP_BIN:-php}"
exec "$PHP_BIN" "$PROJECT_ROOT/api/bin/license-status.php" "$@"
