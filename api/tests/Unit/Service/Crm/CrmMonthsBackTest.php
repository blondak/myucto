<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Crm;

use DateTimeImmutable;
use MyInvoice\Service\Crm\CrmAggregationService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Okno „posledních N měsíců" v CRM metrikách nesmí přetéct koncem měsíce.
 *
 * `(new DateTimeImmutable())->modify('-1 month')` nad 31. dnem vrátí 31. 6.,
 * což se normalizuje na 1. 7. — okno se posune o CELÝ měsíc a žebříčky klientů
 * i dodavatelů, DSO, DPO a platební morálka počítaly 29.–31. dne z jiného
 * období než ve zbytku měsíce.
 */
final class CrmMonthsBackTest extends TestCase
{
    private static function monthsBack(string $from, int $months): string
    {
        $m = new ReflectionMethod(CrmAggregationService::class, 'monthsBack');
        return $m->invoke(null, new DateTimeImmutable($from), $months)->format('Y-m-d');
    }

    /** @return iterable<string, array{string, int, string}> */
    public static function cases(): iterable
    {
        // Konec měsíce → den se ořízne na délku cílového měsíce, měsíc se NEPOSUNE.
        yield '31. 7. − 1 měsíc = 30. 6.' => ['2026-07-31', 1, '2026-06-30'];
        yield '31. 5. − 1 měsíc = 30. 4.' => ['2026-05-31', 1, '2026-04-30'];
        yield '31. 3. − 1 měsíc = 28. 2.' => ['2026-03-31', 1, '2026-02-28'];
        yield '29. 3. − 1 měsíc = 28. 2.' => ['2026-03-29', 1, '2026-02-28'];
        yield '31. 3. − 1 měsíc přestupný rok = 29. 2.' => ['2028-03-31', 1, '2028-02-29'];
        // Delší okna přes přelom roku.
        yield '31. 7. − 12 měsíců'  => ['2026-07-31', 12, '2025-07-31'];
        yield '31. 1. − 12 měsíců'  => ['2026-01-31', 12, '2025-01-31'];
        yield '31. 12. − 3 měsíce'  => ['2026-12-31', 3, '2026-09-30'];
        // Běžné dny zůstávají beze změny.
        yield '15. 7. − 1 měsíc'    => ['2026-07-15', 1, '2026-06-15'];
        yield '1. 7. − 6 měsíců'    => ['2026-07-01', 6, '2026-01-01'];
        yield 'nulový posun'        => ['2026-07-31', 0, '2026-07-31'];
    }

    #[DataProvider('cases')]
    public function testMonthsBackDoesNotOverflow(string $from, int $months, string $expected): void
    {
        self::assertSame($expected, self::monthsBack($from, $months));
    }

    /**
     * Kontrola, že naivní varianta je opravdu vadná — jinak by test procházel
     * i po návratu původní implementace.
     */
    public function testNaiveModifyWouldSkipAMonth(): void
    {
        $naive = (new DateTimeImmutable('2026-07-31'))->modify('-1 month')->format('Y-m');
        self::assertSame('2026-07', $naive, 'PHP u 31. 7. přeteče zpět do července.');
        self::assertSame('2026-06', substr(self::monthsBack('2026-07-31', 1), 0, 7));
    }

    /** Pro každý den roku musí okno skončit v očekávaném měsíci. */
    public function testEveryDayOfYearLandsInCorrectMonth(): void
    {
        $day = new DateTimeImmutable('2026-01-01');
        for ($i = 0; $i < 365; $i++, $day = $day->modify('+1 day')) {
            $expectedMonth = $day->modify('first day of this month')->modify('-12 months')->format('Y-m');
            $got = substr(self::monthsBack($day->format('Y-m-d'), 12), 0, 7);
            self::assertSame($expectedMonth, $got, 'Den ' . $day->format('Y-m-d'));
        }
    }
}
