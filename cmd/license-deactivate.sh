#!/usr/bin/env bash
# =============================================================================
#  license-deactivate.sh — deaktivace licence (E4)
#  Použití:  ./license-deactivate.sh
# =============================================================================
set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
exec php "$PROJECT_ROOT/api/bin/license-deactivate.php" "$@"
