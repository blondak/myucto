<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Repository;

use MyInvoice\Repository\PurchaseInvoiceRepository;
use PHPUnit\Framework\TestCase;

/**
 * Období časového rozlišení na řádku přijatého dokladu: obrácené období (od > do)
 * se neuloží, jednotlivá neplatná data dál tiše propadají na NULL.
 */
final class PurchaseAccrualRangeTest extends TestCase
{
    public function testReversedPeriodIsDropped(): void
    {
        self::assertSame([null, null], PurchaseInvoiceRepository::normalizeAccrualRange('2027-09-30', '2026-10-01'));
    }

    public function testValidAndPartialValuesAreKept(): void
    {
        self::assertSame(['2026-10-01', '2027-09-30'], PurchaseInvoiceRepository::normalizeAccrualRange('2026-10-01', '2027-09-30'));
        self::assertSame(['2026-10-01', '2026-10-01'], PurchaseInvoiceRepository::normalizeAccrualRange('2026-10-01', '2026-10-01'));
        self::assertSame(['2026-10-01', null], PurchaseInvoiceRepository::normalizeAccrualRange('2026-10-01', ''));
        self::assertSame([null, null], PurchaseInvoiceRepository::normalizeAccrualRange(null, 'nesmysl'));
    }
}
