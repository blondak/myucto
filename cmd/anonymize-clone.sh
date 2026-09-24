#!/usr/bin/env bash
# =============================================================================
#  anonymize-clone.sh — anonymizovaná kopie databáze pro testovací instanci
#
#  Originál se jen čte; kopie vznikne jako nová databáze na témž serveru.
#
#  Použití:
#    ./anonymize-clone.sh --from=myucto --to=myucto_anon
#    ./anonymize-clone.sh --to=myucto_anon --replace --dump=/tmp/anon.sql
#    ./anonymize-clone.sh --to=myucto_anon --files-out=/srv/test/storage
#
#  Návratový kód: 0 = hotovo, 1 = chyba běhu, 2 = chyba argumentů.
# =============================================================================
set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
PHP_BIN="${MYINVOICE_PHP_BIN:-php}"
exec "$PHP_BIN" "$PROJECT_ROOT/api/bin/anonymize-clone.php" "$@"
