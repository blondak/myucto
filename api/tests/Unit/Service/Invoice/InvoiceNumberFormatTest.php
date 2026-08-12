<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Invoice;

use MyInvoice\Service\Invoice\InvoiceNumberFormat;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Datumové tokeny číselné řady včetně posunu ({YY+30}, {MM-1}, {YYYY+1}).
 *
 * Posun je ZOBRAZOVACÍ — na období counteru (`invoice_number_period`) nemá vliv.
 * Sémantiku sdílí s {@see \MyInvoice\Service\Invoice\DescriptionPlaceholders}: rok po
 * letech, měsíc po měsících vč. přetečení roku, měsíční tokeny kotvené na 1. den
 * měsíce (jinak by 31. 1. s {MM+1} přeteklo na březen).
 *
 * Klientské zrcadlo je `web/src/utils/varsymbol.ts` — stejné případy tam hlídá
 * Vitest, protože rozejít se smí jen jedno: číslo dokladu.
 */
final class InvoiceNumberFormatTest extends TestCase
{
    /** @return list<array{0:string,1:string,2:string}> */
    public static function templates(): array
    {
        return [
            'rok bez posunu'            => ['{YY}{MM}',   '2026-08-11', '2608'],
            'rok s posunem +30'         => ['{YY+30}{MM}', '2026-08-11', '5608'],
            'rok s posunem na prelomu'  => ['{YY+30}{MM}', '2026-12-31', '5612'],
            'ctyrmistny rok +1'         => ['{YYYY+1}',   '2026-08-11', '2027'],
            'rok zpet'                  => ['{YY-1}',     '2026-01-01', '25'],
            'mesic s pretecenim roku'   => ['{MM+8}',     '2026-05-15', '01'],
            'mesic zpet pres rok'       => ['{MM-1}',     '2026-01-15', '12'],
            'mesic z 31. dne'           => ['{MM+1}',     '2026-01-31', '02'],
            'literal zustava'           => ['FA{YYYY}-',  '2026-08-11', 'FA2026-'],
        ];
    }

    #[DataProvider('templates')]
    public function testExpandDateTokens(string $template, string $date, string $expected): void
    {
        self::assertSame($expected, InvoiceNumberFormat::expandDateTokens($template, new \DateTimeImmutable($date)));
    }

    public function testCounterPlaceholderSurvivesExpansion(): void
    {
        self::assertSame(
            '5608{CCC}',
            InvoiceNumberFormat::expandDateTokens('{YY+30}{MM}{CCC}', new \DateTimeImmutable('2026-08-11')),
            '{C+} musí zůstat netknutý — dosazuje ho až generátor podle counteru.',
        );
    }

    public function testUnresolvedTokenFallsBackToWildcardWidth(): void
    {
        // period='year' zná rok, ale ne měsíc → hodnota není určená a volající si
        // dosadí wildcard o šířce tokenu.
        self::assertNull(InvoiceNumberFormat::tokenValue('MM', 0, 2026, null));
        self::assertSame('56', InvoiceNumberFormat::tokenValue('YY', 30, 2026, null));
        self::assertSame(2, InvoiceNumberFormat::tokenWidth('MM'));
        self::assertSame(4, InvoiceNumberFormat::tokenWidth('YYYY'));
    }

    public function testOffsetTemplatesAreAccepted(): void
    {
        foreach (['{YY+30}{MM}{CCC}', '{MM-1}{CCCC}', '{YYYY+1}{CCCCCC}'] as $template) {
            self::assertSame($template, InvoiceNumberFormat::templateOrNull($template, 'invoice_number_format'));
        }
    }

    public function testMalformedOffsetIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        InvoiceNumberFormat::templateOrNull('{YY+}{CCC}', 'invoice_number_format');
    }

    public function testUnknownTokenIsStillRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        InvoiceNumberFormat::templateOrNull('{DATE+1Y}{CCC}', 'invoice_number_format');
    }
}
