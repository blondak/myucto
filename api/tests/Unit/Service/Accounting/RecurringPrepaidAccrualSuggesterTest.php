<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Accounting;

use MyInvoice\Service\Accounting\Expense\RecurringPrepaidAccrualSuggester;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Automatizace 2026 — heuristika návrhu časového rozlišení ročního předplatného (381). Pure, bez DB.
 */
final class RecurringPrepaidAccrualSuggesterTest extends TestCase
{
    public function testSecondHalfYearAnnualSubscriptionSpansIntoNextYear(): void
    {
        $s = RecurringPrepaidAccrualSuggester::suggest('2025-09-01');

        self::assertNotNull($s);
        self::assertSame('2025-09-01', $s['from']);
        self::assertSame('2026-08-31', $s['to'], 'Roční krytí = od data plnění na 12 měsíců, konec je den před.');
    }

    public function testJulyIsAlreadySecondHalf(): void
    {
        $s = RecurringPrepaidAccrualSuggester::suggest('2025-07-01');

        self::assertNotNull($s);
        self::assertSame('2026-06-30', $s['to']);
    }

    public function testDecemberInvoiceStillSuggests(): void
    {
        $s = RecurringPrepaidAccrualSuggester::suggest('2025-12-15');

        self::assertNotNull($s);
        self::assertSame('2026-12-14', $s['to']);
    }

    /** Roční předplatné z 1. pololetí se do konce roku spotřebuje — není co rozlišovat. */
    #[DataProvider('firstHalfDates')]
    public function testFirstHalfYearIsNotSuggested(string $date): void
    {
        self::assertNull(RecurringPrepaidAccrualSuggester::suggest($date));
    }

    /** @return iterable<array{string}> */
    public static function firstHalfDates(): iterable
    {
        yield ['2025-01-10'];
        yield ['2025-03-01'];
        yield ['2025-06-30']; // měsíc 6 < 7 — ještě 1. pololetí
    }

    /** Účetní už rozlišení určila — návrh nesmí přepsat její rozhodnutí. */
    public function testExistingAccrualIsNeverOverwritten(): void
    {
        self::assertNull(RecurringPrepaidAccrualSuggester::suggest('2025-09-01', '2025-09-01', '2026-08-31'));
        self::assertNull(RecurringPrepaidAccrualSuggester::suggest('2025-09-01', '2025-09-01', null));
        self::assertNull(RecurringPrepaidAccrualSuggester::suggest('2025-09-01', null, '2026-08-31'));
    }

    /** Kratší krytí, které do dalšího roku nepřesáhne, se nenavrhuje (není co odkládat). */
    public function testShortCoverageThatStaysInSameYearIsNotSuggested(): void
    {
        // 3 měsíce od července = do září téhož roku → přelom roku nepřekročí.
        self::assertNull(RecurringPrepaidAccrualSuggester::suggest('2025-07-01', null, null, 3));
    }

    public function testShortCoverageThatCrossesYearEndIsSuggested(): void
    {
        // 3 měsíce od listopadu = do ledna N+1 → přesahuje.
        $s = RecurringPrepaidAccrualSuggester::suggest('2025-11-01', null, null, 3);

        self::assertNotNull($s);
        self::assertSame('2026-01-31', $s['to']);
    }

    public function testInvalidDateOrCoverageYieldsNull(): void
    {
        self::assertNull(RecurringPrepaidAccrualSuggester::suggest('nesmysl'));
        self::assertNull(RecurringPrepaidAccrualSuggester::suggest('2025-09-01', null, null, 0));
    }
}
