<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\License;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\License\LicenseClient;
use MyInvoice\Service\License\LicenseService;
use MyInvoice\Service\License\LicenseTokenVerifier;
use PHPUnit\Framework\TestCase;

/**
 * Odkaz na objednávku je jen odkaz — nesmí viset na databázi.
 *
 * `buyUrl()` přidává `instance` z licenční tabulky, aby si web předvyplnil
 * běžící instalaci. Je to POHODLÍ, ne podmínka: když se řádek přečíst nedá
 * (nedostupná DB, nenakonfigurované spojení), musí odkaz vzniknout dál. Bez
 * toho shodí celou odpověď `/api/license/status` parametr navíc.
 */
final class LicenseBuyUrlTest extends TestCase
{
    public function testBuyUrlSurvivesAnUnusableDatabase(): void
    {
        // Config bez `db.*` — `Connection` na první dotaz vyhodí TypeError.
        $config  = new Config(['license' => ['server_url' => 'https://example.test']]);
        $service = new LicenseService(
            new Connection($config),
            $config,
            new LicenseTokenVerifier(),
            new LicenseClient($config),
        );

        $url = $service->buyUrl();

        self::assertStringStartsWith('https://example.test/objednavka?', $url);
        self::assertStringContainsString('src=app', $url);
        self::assertStringNotContainsString('instance=', $url);
    }
}
