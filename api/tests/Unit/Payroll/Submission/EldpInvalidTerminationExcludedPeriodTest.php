<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission;

use MyInvoice\Service\Payroll\Submission\Eldp\EldpExcludedPeriodDeriver;
use PHPUnit\Framework\TestCase;

/**
 * § 16 odst. 4 písm. j) zákona č. 155/1995 Sb.: doba, po kterou podle
 * pravomocného rozhodnutí soudu vztah trval po neplatném skončení, aniž byla
 * přiznána náhrada mzdy, je vyloučenou dobou (JMHZ 10536 `vyloucenePar16`,
 * datový slovník 1.4.1.6). Vstupem je nepřítomnost `invalid_termination`.
 */
final class EldpInvalidTerminationExcludedPeriodTest extends TestCase
{
    public function testDaysAfterInvalidTerminationAreSection16jExcludedDays(): void
    {
        $derived = (new EldpExcludedPeriodDeriver())->derive(
            [self::absence('2026-06-20', '2026-07-12')],
            '2026-07-01',
            '2026-07-31',
            '2026-07',
            1_500_000,
        );

        self::assertSame([], $derived['blockers']);
        self::assertSame(12, $derived['components']['vyloucenePar16']);
        self::assertSame(12, $derived['total']);
        self::assertSame('vyloucenePar16', $derived['provenance'][0]['attribute']);
        self::assertSame('2026-07-01', $derived['provenance'][0]['counted_from']);
    }

    /**
     * Vztah po tu dobu trval a účast by bez neplatného skončení vznikla:
     * měsíc bez příjmu zůstává dobou pojištění, ne „X".
     */
    public function testWholeMonthWithoutIncomeStaysInsured(): void
    {
        self::assertSame(
            EldpExcludedPeriodDeriver::MONTH_INSURED,
            EldpExcludedPeriodDeriver::insuranceMonthStatus(
                [self::absence('2026-07-01', '2026-07-31')],
                0,
                '2026-07-01',
                '2026-07-31',
            ),
        );
    }

    /** @return array<string,mixed> */
    private static function absence(string $from, string $to): array
    {
        return [
            'id' => 71,
            'absence_type' => 'invalid_termination',
            'date_from' => $from,
            'date_to' => $to,
        ];
    }
}
