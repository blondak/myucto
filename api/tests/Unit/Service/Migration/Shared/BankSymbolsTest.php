<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Migration\Shared;

use MyInvoice\Service\Migration\Shared\BankAccountRegistrar;
use MyInvoice\Service\Migration\Shared\BankStatementImportWriter;
use MyInvoice\Service\Migration\Shared\BankSymbols;
use PHPUnit\Framework\TestCase;

final class BankSymbolsTest extends TestCase
{
    public function testValidVariableSymbolStaysOthersGoToDescription(): void
    {
        self::assertSame(['0012345678', 'Platba'], BankSymbols::variableSymbolAndDescription('0012345678', 'Platba', false));
        self::assertSame([null, 'Karta (ref. 123456789012345)'], BankSymbols::variableSymbolAndDescription('123456789012345', 'Karta', false));
        self::assertSame([null, '(ref. FV-12)'], BankSymbols::variableSymbolAndDescription('FV-12', '', false));
        self::assertSame([null, null], BankSymbols::variableSymbolAndDescription('', '', false));
        self::assertSame(mb_str_pad('', 255, 'á'), BankSymbols::variableSymbolAndDescription('', mb_str_pad('', 300, 'á'), false)[1]);
    }

    public function testZeroSymbolDependsOnSource(): void
    {
        self::assertSame(['000', 'x'], BankSymbols::variableSymbolAndDescription('000', 'x', false), 'Money S3 nulový VS převezme');
        self::assertSame([null, 'x'], BankSymbols::variableSymbolAndDescription('000', 'x', true), 'POHODA nulový VS zahodí');
        self::assertTrue(BankSymbols::isVariableSymbol('00001234567890'));
        self::assertFalse(BankSymbols::isVariableSymbol('12345678901'));
    }

    public function testDigitsSymbol(): void
    {
        self::assertSame('0308', BankSymbols::digitsSymbol('03-08', 10));
        self::assertNull(BankSymbols::digitsSymbol('0000', 10));
        self::assertNull(BankSymbols::digitsSymbol('12345678901', 10));
        self::assertSame('1234567890', BankSymbols::digitsSymbol('0001234567890', 10), 'vodicí nuly, které se nevejdou, odpadnou');
    }

    public function testAccountKeyIgnoresSeparatorsAndLeadingZeros(): void
    {
        self::assertSame(BankAccountRegistrar::accountKey('19-0000123', '0100'), BankAccountRegistrar::accountKey('0000190000123', ' 100'));
        self::assertSame('190000123/100', BankAccountRegistrar::accountKey('19-0000123', '0100'));
        self::assertNotSame(BankAccountRegistrar::accountKey('1000000005', '0100'), BankAccountRegistrar::accountKey('1000000005', '0300'));
    }

    public function testAmountFromCents(): void
    {
        self::assertSame('-1234.05', BankStatementImportWriter::amount(-123405));
        self::assertSame('0.00', BankStatementImportWriter::amount(0));
    }
}
