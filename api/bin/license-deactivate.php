<?php

declare(strict_types=1);

/**
 * CLI: deaktivace licence (headless).
 *
 * Použití:
 *   php api/bin/license-deactivate.php
 *
 * Uvolní vazbu na licenčním serveru a smaže klíč/token lokálně (stejná cesta
 * jako deaktivace v UI, LicenseService::deactivate). Lokální smazání proběhne
 * i když je server nedostupný.
 */

require __DIR__ . '/../vendor/autoload.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Tento skript musí běžet z CLI.\n");
    exit(1);
}

require __DIR__ . '/license-cli-common.php';

$result = license_cli_service()->deactivate();

echo "✓ Licence deaktivována.\n";
if (isset($result['transfers_remaining']) && $result['transfers_remaining'] !== null) {
    echo "  Zbývající přenosy: " . (int) $result['transfers_remaining'] . "\n";
}
if (isset($result['state'])) {
    license_cli_print_state($result['state']);
}
exit(0);
