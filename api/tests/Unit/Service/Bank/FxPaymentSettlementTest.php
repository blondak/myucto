<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Bank;

use MyInvoice\Service\Bank\FxPaymentSettlement;
use PHPUnit\Framework\TestCase;

final class FxPaymentSettlementTest extends TestCase
{
    public function testExpectedLocalAmountIsRoundedToCents(): void
    {
        self::assertSame(3024.53, FxPaymentSettlement::expectedLocalAmount(123.45, 24.5));
    }

    public function testFullSettlementUsesSharedFxTolerance(): void
    {
        self::assertTrue(FxPaymentSettlement::isFullCzkSettlement(24300.0, 1000.0, 'EUR', 24.5, 'CZK'));
        self::assertFalse(FxPaymentSettlement::isFullCzkSettlement(23000.0, 1000.0, 'EUR', 24.5, 'CZK'));
        self::assertFalse(FxPaymentSettlement::isFullCzkSettlement(1000.0, 1000.0, 'EUR', 24.5, 'EUR'));
        self::assertFalse(FxPaymentSettlement::isFullCzkSettlement(1000.0, 1000.0, 'EUR', 0.0, 'CZK'));
    }

    public function testPaymentAmountKeepsFullAndPartialSemanticsSeparate(): void
    {
        self::assertSame(1000.0, FxPaymentSettlement::amountInInvoiceCurrency(
            24300.0,
            'EUR',
            24.5,
            'CZK',
            1000.0,
            true,
        ));
        self::assertSame(408.16, FxPaymentSettlement::amountInInvoiceCurrency(
            10000.0,
            'EUR',
            24.5,
            'CZK',
            1000.0,
        ));
        self::assertSame(999.98, FxPaymentSettlement::amountInInvoiceCurrency(
            999.98,
            'EUR',
            24.5,
            'EUR',
            1000.0,
            true,
        ));
    }
}
