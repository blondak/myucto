<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Report;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Report\VatClassificationDefaulter;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Audit 2026-07 (fix 5): default VAT klasifikace pro reverse-charge prodej do EU.
 *
 * Bez explicitního signálu „zboží" má EU RC prodej defaultovat na SLUŽBY ('22',
 * § 9 odst. 1, ř.21) — u typického uživatele (OSVČ / malá firma) jsou služby
 * častější než dodání zboží ('20'). Dřív se pevně defaultovalo na '20' (zboží).
 *
 * Soft-skip bez cfg.php (CI runner bez DB).
 */
#[Group('integration')]
final class VatClassificationDefaulterTest extends TestCase
{
    public function testZeroRateDomesticSaleNeedsExplicitClassification(): void
    {
        $this->assertNull($this->defaulter->defaultForSale(0.0));
    }

    /**
     * issue #30: nulová sazba na přijatém dokladu se tady NEROZHODUJE. Jediný purchase
     * kód se sazbou 0,00 je '30' (třístranný obchod, § 17) — DB lookup ho dřív přiřadil
     * každému nulovému dokladu a poslal ho na ř. 30 přiznání. Kód doplní až country-aware
     * SSOT {@see PurchaseInvoiceRepository::defaultClassificationCode()}.
     */
    public function testZeroRatePurchaseNeverAutoAssignsTriangularCode(): void
    {
        $this->assertNull($this->defaulter->defaultForPurchase(0.0));
        $this->assertNull($this->defaulter->defaultForPurchase(0.0, true));
        // Kladné sazby se defaultují beze změny.
        $this->assertSame('40', $this->defaulter->defaultForPurchase(21.0));
        $this->assertSame('41', $this->defaulter->defaultForPurchase(12.0));
    }
    private VatClassificationDefaulter $defaulter;
    private ?Connection $conn = null;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection (CI runner skipne).');
        }
        try {
            $container = Bootstrap::buildApp()->getContainer();
            $this->conn = $container->get(Connection::class);
            $this->defaulter = $container->get(VatClassificationDefaulter::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }
    }

    protected function tearDown(): void
    {
        $this->conn?->close();
    }

    public function testEuReverseChargeSaleDefaultsToServicesNotGoods(): void
    {
        // EU odběratel + reverse charge + 0 % bez explicitního kódu → služby '22'.
        $this->assertSame(
            '22',
            $this->defaulter->defaultForSale(0.0, true, null, 0, customerEuForeign: true),
            'EU RC prodej bez signálu zboží → služby (22), NE zboží (20)'
        );
        // Tuzemský RC (§ 92a dodavatel) zůstává '25s' (ř.25) — beze změny.
        $this->assertSame(
            '25s',
            $this->defaulter->defaultForSale(0.0, true, null, 0, customerEuForeign: false),
            'Tuzemský RC dodavatel → 25s (ř.25)'
        );
    }

    /**
     * Audit follow-up (nahrazuje slepou konstantu z 88794465): měrná jednotka položky
     * je reálný signál zboží/služba pro RC prodej do EU.
     */
    public function testEuReverseChargeUsesUnitSignalGoodsVsServices(): void
    {
        // Fyzikální jednotka „kg" → dodání zboží do JČS '20' (ř.20).
        $this->assertSame(
            '20',
            $this->defaulter->defaultForSale(0.0, true, null, 0, customerEuForeign: true, units: ['kg']),
            'EU RC prodej s jednotkou zboží (kg) → dodání zboží 20 (ř.20)'
        );
        // Časová jednotka „h" (hodina) → poskytnutí služby '22' (ř.21).
        $this->assertSame(
            '22',
            $this->defaulter->defaultForSale(0.0, true, null, 0, customerEuForeign: true, units: ['h']),
            'EU RC prodej s jednotkou služby (h) → služba 22 (ř.21)'
        );
        // Neutrální „ks" bez NACE dodavatele → statistický default služba '22'.
        $this->assertSame(
            '22',
            $this->defaulter->defaultForSale(0.0, true, null, 0, customerEuForeign: true, units: ['ks']),
            'EU RC prodej bez jednoznačného signálu → default služba 22'
        );
    }
}
