<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Accounting;

use MyInvoice\Service\Accounting\Accrual\AccrualPeriodDetector;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Rozpoznání období časového rozlišení z textu položky.
 *
 * Případy sdílí s frontendovým dvojčetem (`web/src/utils/accrualPeriod.ts`, spec
 * `web/src/utils/__tests__/accrualPeriod.spec.ts`) přes jeden JSON soubor, takže
 * editor nabídne přesně totéž období, jaké doplní vytěžení.
 */
final class AccrualPeriodDetectorTest extends TestCase
{
    private const CASES = __DIR__ . '/../../../../../web/src/utils/__tests__/accrualPeriod.cases.json';

    /** @return iterable<string, array{string, array{from:string,to:string}|null}> */
    public static function sharedCases(): iterable
    {
        $raw = file_get_contents(self::CASES);
        self::assertIsString($raw, 'Sdílená sada případů musí existovat.');
        $cases = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        foreach ($cases as $i => $case) {
            yield sprintf('#%02d %s', $i, $case['text']) => [$case['text'], $case['expected']];
        }
    }

    /** @param array{from:string,to:string}|null $expected */
    #[DataProvider('sharedCases')]
    public function testDetect(string $text, ?array $expected): void
    {
        self::assertSame($expected, AccrualPeriodDetector::detect($text));
    }

    public function testSharedCaseListIsNotEmpty(): void
    {
        $cases = iterator_to_array(self::sharedCases());
        self::assertGreaterThan(30, count($cases));
        self::assertNotEmpty(array_filter($cases, static fn (array $c): bool => $c[1] === null));
    }

    public function testNormalizePairAcceptsOnlyValidOrderedDates(): void
    {
        self::assertSame(['from' => '2026-10-01', 'to' => '2027-09-30'], AccrualPeriodDetector::normalizePair('2026-10-01', '2027-09-30'));
        self::assertSame(['from' => '2026-10-01', 'to' => '2026-10-01'], AccrualPeriodDetector::normalizePair('2026-10-01', '2026-10-01'));
        self::assertNull(AccrualPeriodDetector::normalizePair('2027-09-30', '2026-10-01'));
        self::assertNull(AccrualPeriodDetector::normalizePair('2026-02-31', '2026-10-01'));
        self::assertNull(AccrualPeriodDetector::normalizePair('1. 10. 2026', '2027-09-30'));
        self::assertNull(AccrualPeriodDetector::normalizePair(null, '2027-09-30'));
        self::assertNull(AccrualPeriodDetector::normalizePair('', ''));
        self::assertNull(AccrualPeriodDetector::normalizePair(20261001, 20270930));
    }

    public function testResolveForItemPrefersModelValueAndFallsBackToText(): void
    {
        $text = 'Předplatné 10/2026 – 09/2027';

        self::assertSame(
            ['from' => '2026-11-01', 'to' => '2027-10-31'],
            AccrualPeriodDetector::resolveForItem('2026-11-01', '2027-10-31', $text),
        );
        self::assertSame(
            ['from' => '2026-10-01', 'to' => '2027-09-30'],
            AccrualPeriodDetector::resolveForItem(null, null, $text),
        );
        self::assertSame(
            ['from' => '2026-10-01', 'to' => '2027-09-30'],
            AccrualPeriodDetector::resolveForItem('2027-10-31', '2026-11-01', $text),
            'Obrácené období z modelu se zahodí a použije se text.',
        );
        self::assertNull(AccrualPeriodDetector::resolveForItem(null, null, 'Konzultace'));
    }
}
