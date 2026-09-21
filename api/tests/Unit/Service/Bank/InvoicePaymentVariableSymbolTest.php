<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Bank;

use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Service\Bank\VariableSymbolNormalizer;
use MyInvoice\Service\Import\IdokladImportService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Samostatný platební VS vydané faktury (#249): efektivní VS, validace vstupu
 * a převzetí iDokladového VariableSymbol vedle čísla dokladu.
 */
final class InvoicePaymentVariableSymbolTest extends TestCase
{
    public static function effectiveSymbols(): array
    {
        return [
            'samostatný VS má přednost'        => [['varsymbol' => '20260399', 'payment_variable_symbol' => '16'], '16'],
            'bez VS se odvodí z čísla dokladu' => [['varsymbol' => '2026-00001', 'payment_variable_symbol' => null], '202600001'],
            'prázdný VS = fallback'            => [['varsymbol' => '2026-00001', 'payment_variable_symbol' => ''], '202600001'],
            'vodicí nuly se zachovají'         => [['varsymbol' => 'FV1', 'payment_variable_symbol' => '0012345'], '0012345'],
            'dlouhé číslo dokladu se ořízne'   => [['varsymbol' => '2026-0000000001'], '2026000000'],
            'bez čísla i VS'                   => [[], ''],
        ];
    }

    #[DataProvider('effectiveSymbols')]
    public function testEffectivePaymentSymbol(array $invoice, string $expected): void
    {
        self::assertSame($expected, VariableSymbolNormalizer::forInvoicePayment($invoice));
    }

    public function testValidSymbolsAreNormalized(): void
    {
        self::assertSame('16', InvoiceRepository::normalizePaymentVariableSymbol('16'));
        self::assertSame('1234567890', InvoiceRepository::normalizePaymentVariableSymbol(' 12345 67890 '));
        self::assertNull(InvoiceRepository::normalizePaymentVariableSymbol(''));
        self::assertNull(InvoiceRepository::normalizePaymentVariableSymbol(null));
    }

    public static function invalidSymbols(): array
    {
        return [['12345678901'], ['2026-001'], ['ABC'], ['12.5']];
    }

    #[DataProvider('invalidSymbols')]
    public function testInvalidSymbolIsRejected(string $value): void
    {
        $this->expectException(\InvalidArgumentException::class);
        InvoiceRepository::normalizePaymentVariableSymbol($value);
    }

    public function testIdokladVariableSymbolDifferentFromDocumentNumberIsKept(): void
    {
        self::assertSame('16', IdokladImportService::idokladPaymentVariableSymbol([
            'DocumentNumber' => '20260399', 'VariableSymbol' => '16',
        ]));
    }

    public function testIdokladVariableSymbolEqualToDocumentNumberIsDropped(): void
    {
        self::assertNull(IdokladImportService::idokladPaymentVariableSymbol([
            'DocumentNumber' => '20260399', 'VariableSymbol' => '20260399',
        ]));
        self::assertNull(IdokladImportService::idokladPaymentVariableSymbol([
            'DocumentNumber' => 'FV-2026-0399', 'VariableSymbol' => '20260399',
        ]));
    }

    public function testIdokladInvalidOrMissingVariableSymbolIsDropped(): void
    {
        self::assertNull(IdokladImportService::idokladPaymentVariableSymbol(['DocumentNumber' => '20260399']));
        self::assertNull(IdokladImportService::idokladPaymentVariableSymbol(['DocumentNumber' => '1', 'VariableSymbol' => 'VS-16']));
        self::assertNull(IdokladImportService::idokladPaymentVariableSymbol(['DocumentNumber' => '1', 'VariableSymbol' => '12345678901']));
    }
}
