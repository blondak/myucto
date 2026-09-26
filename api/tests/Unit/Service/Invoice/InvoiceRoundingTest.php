<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Invoice;

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
