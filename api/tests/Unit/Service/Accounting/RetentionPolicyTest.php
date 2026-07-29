<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Accounting;

use MyInvoice\Service\Accounting\RetentionPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Retenční lhůty § 31 ZoÚ a § 35a ZDPH.
 *
 * Matice účetnictví vedla § 31 jako CHYBÍ a platilo to doslova: řetězce „10 let" ani
 * „5 let" se v kódu nevyskytovaly a invariant I27 („účetní záznam existuje po celou
 * retenční lhůtu") neměl ani pravidlo, natož kontrolu.
 *
 * Nejdůležitější je tu {@see testTaxDocumentKeepsLongerPeriod()}: daňový doklad je
 * současně účetním dokladem, takže podle ZoÚ by stačilo 5 let, ale podle ZDPH je to 10.
 * Implementace nad samotným § 31 by vydala ke skartaci doklady, které je nutné držet
 * dalších pět let — a udělala by to tiše.
 */
final class RetentionPolicyTest extends TestCase
{
    /** Lhůta běží od KONCE účetního období (§ 31 odst. 3), ne ode dne vystavení. */
    public function testRetentionRunsFromPeriodEnd(): void
    {
        self::assertSame(
            '2034-12-31',
            RetentionPolicy::retainUntil(RetentionPolicy::TAX_DOCUMENTS, '2024-12-31'),
        );
    }

    /** Hospodářský rok (§ 3 odst. 2 ZoÚ) posouvá i konec lhůty. */
    public function testNonCalendarPeriodShiftsDeadline(): void
    {
        self::assertSame(
            '2035-06-30',
            RetentionPolicy::retainUntil(RetentionPolicy::FINANCIAL_STATEMENTS, '2025-06-30'),
        );
    }

    /**
     * Souběh ZoÚ × ZDPH: doklad s daní se drží 10 let, ne 5. Tohle je celý důvod,
     * proč je kategorie odvozená a ne zadaná ručně.
     */
    public function testTaxDocumentKeepsLongerPeriod(): void
    {
        self::assertSame(
            RetentionPolicy::TAX_DOCUMENTS,
            RetentionPolicy::categoryForDocument(hasVat: true),
        );
        self::assertSame(10, RetentionPolicy::retentionYears(RetentionPolicy::TAX_DOCUMENTS));

        self::assertSame(
            RetentionPolicy::ACCOUNTING_RECORDS,
            RetentionPolicy::categoryForDocument(hasVat: false),
        );
        self::assertSame(5, RetentionPolicy::retentionYears(RetentionPolicy::ACCOUNTING_RECORDS));
    }

    /** Brána musí počítat s nejdelší lhůtou, která se období týká. */
    public function testLongestRetentionWins(): void
    {
        self::assertSame('2034-12-31', RetentionPolicy::longestRetainUntil('2024-12-31'));
    }

    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function boundaryCases(): iterable
    {
        // Doklady z 2024, účetní záznam (5 let) → do 31. 12. 2029 včetně.
        yield 'den před uplynutím' => ['2029-12-30', RetentionPolicy::ACCOUNTING_RECORDS, true];
        yield 'poslední den lhůty' => ['2029-12-31', RetentionPolicy::ACCOUNTING_RECORDS, true];
        yield 'den po uplynutí'    => ['2030-01-01', RetentionPolicy::ACCOUNTING_RECORDS, false];
        // Tentýž doklad jako daňový (10 let) je v roce 2030 pořád chráněný.
        yield 'daňový doklad 2030' => ['2030-01-01', RetentionPolicy::TAX_DOCUMENTS, true];
    }

    #[DataProvider('boundaryCases')]
    public function testRetentionBoundaryIsInclusive(string $asOf, string $category, bool $expected): void
    {
        self::assertSame(
            $expected,
            RetentionPolicy::isWithinRetention($category, '2024-12-31', $asOf),
        );
    }

    /** Rozpis pro UI nese lhůtu i to, jestli už uplynula — u každé kategorie zvlášť. */
    public function testScheduleCoversAllCategories(): void
    {
        $schedule = RetentionPolicy::scheduleFor('2024-12-31', '2030-06-01');

        self::assertCount(3, $schedule);
        $byCategory = array_column($schedule, null, 'category');

        self::assertTrue($byCategory[RetentionPolicy::ACCOUNTING_RECORDS]['expired'],
            '5letá lhůta účetních dokladů v polovině roku 2030 uplynula.');
        self::assertFalse($byCategory[RetentionPolicy::TAX_DOCUMENTS]['expired'],
            '10letá lhůta daňových dokladů běží do konce roku 2034.');
        self::assertFalse($byCategory[RetentionPolicy::FINANCIAL_STATEMENTS]['expired']);
    }

    public function testUnknownCategoryIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        RetentionPolicy::retentionYears('cokoliv');
    }
}
