<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Accounting\Closing;

use MyInvoice\Service\Accounting\Closing\ClosingException;
use MyInvoice\Service\Accounting\Closing\DocumentSeriesService;
use PHPUnit\Framework\TestCase;

/**
 * Unit test formátu čísla dokladu číselné řady (Epic F4, §6.1 U13, R13):
 * vestavěné `{PREFIX}-{YYYY}-{CCCC}` i volitelná šablona per řada (#22) —
 * čistá statická funkce bez DB.
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

    // ── #22: volitelná šablona čísla řady ────────────────────────────────────

    public function testCustomTemplateRendersPlaceholders(): void
    {
        // Přesně případ z #22 — navázání na řadu 26HP00010 z jiného systému.
        self::assertSame('26HP00011', DocumentSeriesService::format('26HP', 2026, 11, '{PREFIX}{CCCCC}'));
        self::assertSame('26HP00011', DocumentSeriesService::format('X', 2026, 11, '26HP{CCCCC}'));
        self::assertSame('PPD/26/007', DocumentSeriesService::format('PPD', 2026, 7, '{PREFIX}/{YY}/{CCC}'));
        self::assertSame('2026.0042', DocumentSeriesService::format('UZ', 2026, 42, '{YYYY}.{CCCC}'));
    }

    public function testEmptyTemplateFallsBackToBuiltIn(): void
    {
        self::assertSame('UZ-2026-0007', DocumentSeriesService::format('UZ', 2026, 7, null));
        self::assertSame('UZ-2026-0007', DocumentSeriesService::format('UZ', 2026, 7, ''));
        self::assertSame('UZ-2026-0007', DocumentSeriesService::format('UZ', 2026, 7, DocumentSeriesService::DEFAULT_TEMPLATE));
    }

    public function testCustomTemplateDoesNotTruncateOverflow(): void
    {
        self::assertSame('26HP100000', DocumentSeriesService::format('26HP', 2026, 100000, '{PREFIX}{CCCCC}'));
    }

    public function testNormalizeTemplateNullsOutDefaultAndEmpty(): void
    {
        self::assertNull(DocumentSeriesService::normalizeTemplate(null));
        self::assertNull(DocumentSeriesService::normalizeTemplate('   '));
        self::assertNull(DocumentSeriesService::normalizeTemplate(DocumentSeriesService::DEFAULT_TEMPLATE));
    }

    public function testNormalizeTemplateUppercasesAndTrims(): void
    {
        self::assertSame('26HP{CCCCC}', DocumentSeriesService::normalizeTemplate(' 26hp{ccccc} '));
    }

    public function testNormalizeTemplateRejectsMissingCounter(): void
    {
        $this->expectException(ClosingException::class);
        DocumentSeriesService::normalizeTemplate('{PREFIX}-{YYYY}');
    }

    public function testNormalizeTemplateRejectsUnknownCharacters(): void
    {
        $this->expectException(ClosingException::class);
        DocumentSeriesService::normalizeTemplate('{PREFIX} {CCCC}');
    }

    public function testNormalizeTemplateRejectsTooLongTemplate(): void
    {
        $this->expectException(ClosingException::class);
        DocumentSeriesService::normalizeTemplate(str_repeat('A', 40) . '{CCCC}');
    }
}
