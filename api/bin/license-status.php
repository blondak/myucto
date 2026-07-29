<?php

declare(strict_types=1);

/**
 * CLI: výpis aktuálního stavu licence (headless).
 *
 * Použití:
 *   php api/bin/license-status.php
 *
 * Spočítá aktuální stav (ověří podpis uloženého tokenu) a vypíše přehled —
 * tarif, počty, platnost (u doživotní licence „Neomezeně"), maskovaný klíč.
 */

require __DIR__ . '/../vendor/autoload.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Tento skript musí běžet z CLI.\n");
    exit(1);
}

require __DIR__ . '/license-cli-common.php';

$state = license_cli_service()->current();
echo "Stav licence:\n";
license_cli_print_state($state);
exit(0);
