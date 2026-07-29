<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Codebooks;

use MyInvoice\Service\Accounting\Codebooks\AbstractCodebookImportService;
use MyInvoice\Service\Accounting\Codebooks\ChartOfAccountsImportService;
use PHPUnit\Framework\TestCase;

/**
 * Čisté unit testy parserů importu číselníků (Epic F5 §7.1). Bez DB.
 */
final class CodebookParsersTest extends TestCase
{
    public function testParseDecimalCzAndEn(): void
    {
        self::assertSame(12345.67, AbstractCodebookImportService::parseDecimal('12 345,67'));
        self::assertSame(12345.67, AbstractCodebookImportService::parseDecimal('12345.67'));
        self::assertSame(1000.0, AbstractCodebookImportService::parseDecimal('1 000'));
        self::assertSame(1234.56, AbstractCodebookImportService::parseDecimal('1.234,56')); // CZ tisíce + desetinná čárka
        self::assertSame(1234.56, AbstractCodebookImportService::parseDecimal('1,234.56')); // EN tisíce + desetinná tečka
        self::assertSame(-5.0, AbstractCodebookImportService::parseDecimal('-5'));
        self::assertNull(AbstractCodebookImportService::parseDecimal(''));
        self::assertNull(AbstractCodebookImportService::parseDecimal('abc'));
    }

    public function testParseBool(): void
    {
        self::assertTrue(AbstractCodebookImportService::parseBool('ano'));
        self::assertTrue(AbstractCodebookImportService::parseBool('1'));
        self::assertTrue(AbstractCodebookImportService::parseBool('yes'));
        self::assertTrue(AbstractCodebookImportService::parseBool('TRUE'));
        self::assertFalse(AbstractCodebookImportService::parseBool('ne'));
        self::assertFalse(AbstractCodebookImportService::parseBool('0'));
        self::assertFalse(AbstractCodebookImportService::parseBool('no'));
        self::assertNull(AbstractCodebookImportService::parseBool(''));
        self::assertNull(AbstractCodebookImportService::parseBool('možná'));
    }

    public function testParseDate(): void
    {
        self::assertSame('2026-07-02', AbstractCodebookImportService::parseDate('2026-07-02'));
        self::assertSame('2026-07-02', AbstractCodebookImportService::parseDate('2.7.2026'));
        self::assertSame('2026-07-02', AbstractCodebookImportService::parseDate('02.07.2026'));
        self::assertSame('2024-01-01', AbstractCodebookImportService::parseDate('45292')); // Excel serial
        self::assertNull(AbstractCodebookImportService::parseDate(''));
        self::assertNull(AbstractCodebookImportService::parseDate('32.13.2026'));
        self::assertNull(AbstractCodebookImportService::parseDate('nedatum'));
    }

    public function testParseEnumCzAliases(): void
    {
        $map = ['aktiva' => 'asset', 'asset' => 'asset', 'rovnomerny' => 'straight', 'straight' => 'straight'];
        self::assertSame('asset', AbstractCodebookImportService::parseEnum('aktiva', $map));
        self::assertSame('asset', AbstractCodebookImportService::parseEnum('Aktiva', $map));   // case
        self::assertSame('straight', AbstractCodebookImportService::parseEnum('rovnoměrný', $map)); // diakritika
        self::assertSame('straight', AbstractCodebookImportService::parseEnum('STRAIGHT', $map));
        self::assertNull(AbstractCodebookImportService::parseEnum('neznamy', $map));
        self::assertSame('none', AbstractCodebookImportService::parseEnum('', $map, 'none')); // default při prázdné
    }

    public function testMapHeaderDiacriticsCaseAliasesAndDuplicates(): void
    {
        $aliasMap = AbstractCodebookImportService::aliasMapForColumns(ChartOfAccountsImportService::columns());

        // Diakritika + case + EN alias; 'account_code' je alias 'code' → duplicita se ignoruje (první vyhrává).
        $header = ['ÚČET', 'Název', 'account_code', 'nadřízený', 'aktivni'];
        $map = AbstractCodebookImportService::mapHeader($header, $aliasMap);

        self::assertSame(0, $map['code']);
        self::assertSame(1, $map['name']);
        self::assertSame(3, $map['parent']);
        self::assertSame(4, $map['active']);
        // 'account_code' (index 2) je duplicitní alias 'code' → nepřepíše první výskyt.
        self::assertSame(0, $map['code']);
    }
}
