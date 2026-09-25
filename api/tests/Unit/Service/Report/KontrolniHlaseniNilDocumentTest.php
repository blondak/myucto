<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Report;

use MyInvoice\Service\Report\KontrolniHlaseniBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Doklad bez částky pro KH: vypadne jen tehdy, když je nulový základ I daň ve všech sazbách.
 */
final class KontrolniHlaseniNilDocumentTest extends TestCase
{
    public function testZeroBaseAndTaxInAllRatesIsNil(): void
    {
        self::assertTrue(KontrolniHlaseniBuilder::isNilKhAmount(0.0, 0.0, 0.0, 0.0));
        self::assertTrue(KontrolniHlaseniBuilder::isNilKhAmount(12000.0 - 12000.0, 2520.0 - 2520.0, 0.0, 0.0));
        self::assertTrue(KontrolniHlaseniBuilder::isNilKhAmount(0.004, -0.004, 0.0, 0.0), 'Pod haléř je nula.');
    }

    public function testAnyNonZeroAmountKeepsDocument(): void
    {
        self::assertFalse(KontrolniHlaseniBuilder::isNilKhAmount(0.0, 0.01, 0.0, 0.0), 'Nulový základ, nenulová daň zůstává.');
        self::assertFalse(KontrolniHlaseniBuilder::isNilKhAmount(100.0, 0.0, 0.0, 0.0), 'Nenulový základ, nulová daň zůstává.');
        self::assertFalse(KontrolniHlaseniBuilder::isNilKhAmount(1000.0, 210.0, -1000.0, -120.0), 'Sazby se navzájem nesčítají.');
    }
}
