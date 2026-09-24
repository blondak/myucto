<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Accounting\Assets;

use MyInvoice\Service\Accounting\Assets\DisposalResiduals;
use PHPUnit\Framework\TestCase;

/**
 * Účetní ZC k vyřazení: stejný výpočet pro zápis vyřazení v modulu majetku
 * i pro majetek vyřazený mimo modul (převod z jiného systému).
 */
final class DisposalResidualsTest extends TestCase
{
    public function testDepreciableResidualIsIncreasedPriceLessAccumulatedDepreciation(): void
    {
        self::assertSame(1000.0, DisposalResiduals::bookResidual(100000.0, 99000.0, true));
        self::assertSame(0.0, DisposalResiduals::bookResidual(100000.0, 100000.0, true));
        self::assertSame(0.0, DisposalResiduals::bookResidual(100000.0, 100000.01, true), 'Přeodepsaná karta nedá zápornou ZC.');
    }

    public function testNonDepreciableResidualIsWholePrice(): void
    {
        self::assertSame(32112.0, DisposalResiduals::bookResidual(32112.0, 0.0, false));
        self::assertSame(32112.0, DisposalResiduals::bookResidual(32112.0, 5000.0, false), 'Neodpisovaný majetek oprávky nemá.');
    }
}
