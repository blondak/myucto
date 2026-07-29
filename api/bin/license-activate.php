<?php

declare(strict_types=1);

/**
 * CLI: aktivace licenčního klíče (headless, bez přihlášení do UI).
 *
 * Použití:
 *   php api/bin/license-activate.php MYU-XXXX-XXXX-XXXX-XXXX [--takeover]
 *
 * Vytáhne instance_id a fingerprint z instalace, zavolá licenční server a uloží
 * klíč + token lokálně — stejná cesta jako aktivační Action v UI
 * (LicenseService::activate). Hodí se pro demo instance, servery a automatizaci.
 *
 * `--takeover` vynutí přenos vazby z jiné instalace (po chybě already_bound);
 * počítá se do limitu přenosů 2/30 dní.
 *
 * Návratové kódy: 0 OK, 2 chybné argumenty, 3 aktivace selhala.
 */

require __DIR__ . '/../vendor/autoload.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Tento skript musí běžet z CLI.\n");
    exit(1);
}

require __DIR__ . '/license-cli-common.php';

use MyInvoice\Service\License\LicenseService;

$takeover   = false;
$licenseKey = null;
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--takeover') {
        $takeover = true;
    } elseif ($arg === '--help' || $arg === '-h') {
        $licenseKey = null;
        break;
    } elseif ($licenseKey === null && $arg !== '' && $arg[0] !== '-') {
        $licenseKey = $arg;
    }
}

if ($licenseKey === null || trim($licenseKey) === '') {
    fwrite(STDERR, "Použití: php api/bin/license-activate.php <licenční-klíč> [--takeover]\n");
    fwrite(STDERR, "  --takeover  vynutí přenos vazby z jiné instalace (po chybě already_bound).\n");
    exit(2);
}

$service = license_cli_service();
$result  = $service->activate($licenseKey, $takeover);

if (($result['ok'] ?? false) === true && isset($result['state'])) {
    echo "✓ Licence aktivována.\n";
    license_cli_print_state($result['state']);
    exit(0);
}

$error = (string) ($result['error'] ?? 'activation_failed');
fwrite(STDERR, "✗ Aktivace selhala: " . license_cli_error_message($error) . "\n");

if ($error === 'already_bound') {
    fwrite(STDERR, "  Klíč je aktivní na jiné instalaci. Přenos vynutíte přepínačem --takeover.\n");
    if (isset($result['transfers_remaining'])) {
        fwrite(STDERR, "  Zbývající přenosy: " . (int) $result['transfers_remaining'] . "\n");
    }
}

exit(3);
