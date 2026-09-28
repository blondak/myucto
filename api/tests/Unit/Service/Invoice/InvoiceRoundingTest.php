<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Invoice;

use MyInvoice\Service\Invoice\InvoiceMath;
use MyInvoice\Service\Invoice\InvoiceRounding;
use MyInvoice\Service\Validation\InvoiceValidation;
use PHPUnit\Framework\TestCase;

final class InvoiceRoundingTest extends TestCase
{
    public function testHalfCrownsRoundAwayFromZero(): void
    {
        self::assertSame(0.5, InvoiceRounding::adjustment(9.5, 'auto', 'CZK', 'cash', 'invoice'));
        self::assertSame(-0.5, InvoiceRounding::adjustment(-9.5, 'auto', 'CZK', 'cash', 'credit_note'));
        self::assertSame(0.0, InvoiceRounding::adjustment(10, 'auto', 'CZK', 'cash', 'invoice'));
    }

    public function testNegativeInvoiceRoundsSymmetricallyToPositive(): void
    {
        foreach ([607.60, 607.50, 607.49, 0.5, 1234.01] as $payable) {
            self::assertSame(
                -InvoiceRounding::adjustment($payable, 'auto', 'CZK', 'cash', 'invoice'),
                InvoiceRounding::adjustment(-$payable, 'auto', 'CZK', 'cash', 'invoice'),
                (string) $payable,
            );
        }
    }

    public function testRefundInvoiceWithGoodsAndReturnedPackagingRoundsToWholeCrowns(): void
    {
        $computed = InvoiceMath::compute([
            ['quantity' => 1, 'unit_price_without_vat' => 1000.00, 'vat_rate_snapshot' => 21],
            ['quantity' => 1, 'unit_price_without_vat' => -1502.15, 'vat_rate_snapshot' => 21],
        ], false, false);
        self::assertEqualsWithDelta(-607.60, $computed['totals']['with_vat'], 0.001);

        $rounding = InvoiceRounding::adjustment($computed['totals']['with_vat'], 'auto', 'CZK', 'cash', 'invoice');
        self::assertEqualsWithDelta(-0.40, $rounding, 0.001);
        self::assertEqualsWithDelta(-608.00, round($computed['totals']['with_vat'] + $rounding, 2), 0.001);
    }

    public function testOtherCurrenciesAndDocumentTypesAreNotRounded(): void
    {
        self::assertSame(0.0, InvoiceRounding::adjustment(9.7, 'whole_czk', 'EUR', 'cash', 'invoice'));
        foreach (['proforma', 'tax_document', 'payment_calendar', 'cancellation', 'penalty'] as $type) {
            self::assertSame(0.0, InvoiceRounding::adjustment(9.7, 'whole_czk', 'CZK', 'cash', $type));
        }
    }

    public function testValidationRejectsUnknownAndNonScalarModes(): void
    {
        foreach (['ceil', null, [], 1] as $mode) {
            self::assertArrayHasKey('rounding_mode', InvoiceValidation::invoice(['rounding_mode' => $mode]));
        }
    }
}
