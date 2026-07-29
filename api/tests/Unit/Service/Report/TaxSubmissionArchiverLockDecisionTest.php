<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Report;

use MyInvoice\Service\Report\TaxSubmissionArchiver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Jednotkové testy čisté rozhodovací logiky VAT-locku
 * ({@see TaxSubmissionArchiver::lockDateFor()}) — dorevize B8.
 *
 * Pokrývá HIGH#1 (nezamykat probíhající/budoucí období, readonly nezamyká,
 * neplatné přiznání nezamyká) a LOW#4 (dphshv se nezamyká, roční fallback → null).
 */
final class TaxSubmissionArchiverLockDecisionTest extends TestCase
{
    private const TODAY = '2026-07-07';

    // ── happy path: uzavřené období v minulosti se zamkne ──────────────────────

    public function testMonthlyPastPeriodLocksToMonthEnd(): void
    {
        self::assertSame(
            '2026-05-31',
            TaxSubmissionArchiver::lockDateFor('dphdp3', 2026, 5, null, 'passed', true, self::TODAY),
        );
    }

    public function testQuarterlyPastPeriodLocksToQuarterEnd(): void
    {
        self::assertSame(
            '2026-03-31',
            TaxSubmissionArchiver::lockDateFor('dphkh1', 2026, null, 1, 'passed', true, self::TODAY),
        );
    }

    public function testSkippedValidationStillLocks(): void
    {
        // XSD schema není nainstalované (status=skipped) — zámek se přesto posune.
        self::assertSame(
            '2026-05-31',
            TaxSubmissionArchiver::lockDateFor('dphdp3', 2026, 5, null, 'skipped', true, self::TODAY),
        );
    }

    // ── HIGH#1: probíhající / budoucí období se NIKDY nezamyká ─────────────────

    public function testCurrentMonthNotLocked(): void
    {
        // Náhled běžného měsíce (konec 2026-07-31 >= dnešek) NESMÍ zamknout účtování.
        self::assertNull(
            TaxSubmissionArchiver::lockDateFor('dphdp3', 2026, 7, null, 'passed', true, self::TODAY),
        );
    }

    public function testFuturePeriodNotLocked(): void
    {
        self::assertNull(
            TaxSubmissionArchiver::lockDateFor('dphdp3', 2027, 1, null, 'passed', true, self::TODAY),
        );
    }

    public function testPeriodEndingExactlyTodayNotLocked(): void
    {
        // Konec == dnešek: den ještě probíhá → nezamykat (>= today).
        self::assertNull(
            TaxSubmissionArchiver::lockDateFor('dphdp3', 2026, 7, null, 'passed', true, '2026-07-31'),
        );
    }

    // ── HIGH#1: readonly cesta nesmí mutovat zámek ─────────────────────────────

    public function testReadonlyPathDoesNotLock(): void
    {
        self::assertNull(
            TaxSubmissionArchiver::lockDateFor('dphdp3', 2026, 5, null, 'passed', false, self::TODAY),
        );
    }

    // ── HIGH#1: neplatné (neodeslatelné) přiznání nezamyká ─────────────────────

    public function testFailedValidationDoesNotLock(): void
    {
        self::assertNull(
            TaxSubmissionArchiver::lockDateFor('dphdp3', 2026, 5, null, 'failed', true, self::TODAY),
        );
    }

    // ── LOW#4: dphshv (souhrnné hlášení) + roční fallback se nezamyká ──────────

    public function testSouhrnneHlaseniNeverLocks(): void
    {
        self::assertNull(
            TaxSubmissionArchiver::lockDateFor('dphshv', 2026, 5, null, 'passed', true, self::TODAY),
        );
    }

    public function testYearlyFallbackDoesNotLock(): void
    {
        // Bez měsíce i kvartálu (obojí null) — žádné jednoznačné období → nezamykat.
        self::assertNull(
            TaxSubmissionArchiver::lockDateFor('dphdp3', 2026, null, null, 'passed', true, self::TODAY),
        );
    }

    #[DataProvider('nonVatFormsProvider')]
    public function testNonVatFormsDoNotLock(string $formCode): void
    {
        self::assertNull(
            TaxSubmissionArchiver::lockDateFor($formCode, 2026, 5, null, 'passed', true, self::TODAY),
        );
    }

    /** @return iterable<array{0:string}> */
    public static function nonVatFormsProvider(): iterable
    {
        yield 'income tax PO' => ['dppdp9'];
        yield 'income tax FO' => ['dpfdp5'];
        yield 'cssz osvc'     => ['osvc25'];
        yield 'isdoc'         => ['isdoc'];
    }
}
