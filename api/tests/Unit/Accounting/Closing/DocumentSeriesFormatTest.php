<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Accounting\Closing;

use MyInvoice\Service\Accounting\Closing\DocumentSeriesService;
use PHPUnit\Framework\TestCase;

/**
 * Unit test formátu čísla dokladu číselné řady (Epic F4, §6.1 U13, R13):
 * `{prefix}-{fiscal_year}-{NNNN}` (%04d) — čistá statická funkce bez DB.
 */
final class DocumentSeriesFormatTest extends TestCase
{
    public function testU13FormatPadsToFourDigits(): void
    {
        self::assertSame('UZ-2026-0007', DocumentSeriesService::format('UZ', 2026, 7));
    }

    public function testFormatDefaultPrefixesAndOverflow(): void
    {
        self::assertSame('OT-2099-0001', DocumentSeriesService::format('OT', 2099, 1));
        self::assertSame('KR-2098-0042', DocumentSeriesService::format('KR', 2098, 42));
        self::assertSame('PP-2098-9999', DocumentSeriesService::format('PP', 2098, 9999));
        // %04d nezkracuje — po 9999 řada pokračuje pětimístně
        self::assertSame('ID-2098-10000', DocumentSeriesService::format('ID', 2098, 10000));
    }

    public function testDefaultPrefixMapMatchesSpec(): void
    {
        self::assertSame(
            [
                'closing' => 'UZ', 'opening' => 'OT', 'fx' => 'KR', 'transfer' => 'PP', 'manual' => 'ID',
                'cash_in' => 'PPD', 'cash_out' => 'VPD', // mini-epic POKLADNA (#14)
                'stock_in' => 'PRI', 'stock_out' => 'VYD', 'stock_transfer' => 'PRE', // Epic SKLAD (#16)
                'offset' => 'ZAP', // Fáze F (audit 2026-07): zápočty
                // Epic SKLAD „na cestě": objednávka není účetní doklad, ale číslo
                // z řady dostává — musí být unikátní a bez souběhových děr.
                'purchase_order' => 'OBJ',
            ],
            DocumentSeriesService::DEFAULT_PREFIXES,
            'Výchozí prefixy řad dle R13 + pokladní řady (#14) + skladové řady (#16) + zápočty (audit 2026-07 Fáze F) + objednávky dodavatelům.',
        );
    }
}
