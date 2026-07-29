<?php

declare(strict_types=1);

/**
 * Sdílené utility pro licenční CLI nástroje (license-activate / -status / -deactivate).
 * Bootstrap DI kontejneru + jednotné vypsání stavu a překlad chybových kódů serveru.
 */

use MyInvoice\Service\License\LicenseService;
use MyInvoice\Service\License\LicenseState;

/** Vytáhne nabootovaný LicenseService z DI kontejneru (stejná instance jako Actions). */
function license_cli_service(): LicenseService
{
    static $service = null;
    if ($service === null) {
        $app = \MyInvoice\Bootstrap::buildApp();
        $service = $app->getContainer()->get(LicenseService::class);
    }
    return $service;
}

/** Platnost licence pro výpis — u perpetual „Neomezeně", jinak datum tokenu. */
function license_cli_validity(LicenseState $state): string
{
    if ($state->perpetual) {
        return 'Neomezeně';
    }
    if ($state->validUntil !== null && $state->validUntil > 0) {
        return date('Y-m-d H:i', $state->validUntil);
    }
    return '—';
}

/** Přehledný výpis stavu licence na STDOUT. */
function license_cli_print_state(LicenseState $state): void
{
    $key = $state->maskedKey() ?? '—';
    echo "  Stav:      {$state->state}\n";
    echo "  Tarif:     " . ($state->tier ?? '—') . "\n";
    echo "  Uživatelé: " . ($state->usersLicensed > 0 ? (string) $state->usersLicensed : '∞') . "\n";
    echo "  Firmy:     " . ($state->maxCompanies === null ? '∞' : (string) $state->maxCompanies) . "\n";
    echo "  Platnost:  " . license_cli_validity($state) . "\n";
    echo "  Klíč:      {$key}\n";
    echo "  Poslední kontrola OK: " . ($state->lastCheckOk ? 'ano' : 'ne') . "\n";
}

/** Srozumitelný český překlad chybových kódů licenčního serveru. */
function license_cli_error_message(string $code): string
{
    return match ($code) {
        'invalid_key'          => 'neplatný licenční klíč (invalid_key).',
        'already_bound'        => 'klíč je aktivní na jiné instalaci (already_bound).',
        'transfer_limit'       => 'vyčerpán limit přenosů 2/30 dní (transfer_limit).',
        'subscription_inactive',
        'subscription_expired' => 'předplatné není aktivní (subscription_inactive).',
        'invalid_token'        => 'server vrátil token s neplatným podpisem (invalid_token).',
        'server_unreachable'   => 'licenční server je nedostupný (server_unreachable).',
        default                => $code,
    };
}
