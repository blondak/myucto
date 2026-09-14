<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Run;

use MyInvoice\Service\Payroll\Run\PayrollRunSourceDrift;
use PHPUnit\Framework\TestCase;

final class PayrollRunSourceDriftTest extends TestCase
{
    public function testIdenticalSourcesReportNoChange(): void
    {
        $drift = PayrollRunSourceDrift::between(
            $this->frozen(),
            $this->current(),
        );

        self::assertFalse($drift->hasChanges());
        self::assertSame(0, $drift->toArray()['total']);
    }

    public function testCountsEveryKindOfChange(): void
    {
        $current = $this->current();
        $current['eligible_employment_ids'] = [7, 8];
        $current['inputs'] = [
            7 => [
                ['id' => 1, 'amount_minor' => 125_000],
                ['id' => 2, 'amount_minor' => 10_000],
            ],
            8 => [],
        ];
        $current['absences'] = [7 => [], 8 => []];
        $current['statutory_evidence'] = [10 => ['income_tax' => ['declaration' => 'signed']]];

        $drift = PayrollRunSourceDrift::between($this->frozen(), $current)->toArray();

        self::assertSame(1, $drift['inputs_added']);
        self::assertSame(1, $drift['inputs_changed']);
        self::assertSame(0, $drift['inputs_removed']);
        self::assertSame(1, $drift['absences_removed']);
        self::assertSame(1, $drift['employments_added']);
        self::assertSame(0, $drift['employments_removed']);
        self::assertSame(1, $drift['statutory_evidence_changed']);
        self::assertSame(5, $drift['total']);
    }

    /** Bez repozitáře evidence (volitelná závislost) se evidence neporovnává. */
    public function testMissingEvidenceSourceIsNotReportedAsChange(): void
    {
        $current = $this->current();
        $current['statutory_evidence'] = null;

        self::assertFalse(PayrollRunSourceDrift::between($this->frozen(), $current)->hasChanges());
    }

    public function testCancelledInputAndEndedEmploymentAreReported(): void
    {
        $current = $this->current();
        $current['eligible_employment_ids'] = [];
        $current['inputs'] = [7 => []];

        $drift = PayrollRunSourceDrift::between($this->frozen(), $current);

        self::assertSame(1, $drift->inputsRemoved);
        self::assertSame(1, $drift->employmentsRemoved);
    }

    /** @return array<string,mixed> */
    private function frozen(): array
    {
        return [
            'period_start' => '2026-06-01',
            'payment_date' => '2026-07-15',
            'office_id' => null,
            'people' => [[
                'employee' => ['id' => 10],
                'statutory_evidence' => ['income_tax' => ['declaration' => 'not-signed']],
                'employments' => [[
                    'employment' => ['id' => 7],
                    'inputs' => [['id' => 1, 'amount_minor' => 120_000]],
                    'absences' => [['id' => 5, 'absence_type' => 'vacation']],
                ]],
            ]],
        ];
    }

    /**
     * @return array{
     *   eligible_employment_ids:list<int>,
     *   inputs:array<int,list<array<string,mixed>>>,
     *   absences:array<int,list<array<string,mixed>>>,
     *   statutory_evidence:array<int,mixed>|null
     * }
     */
    private function current(): array
    {
        return [
            'eligible_employment_ids' => [7],
            'inputs' => [7 => [['id' => 1, 'amount_minor' => 120_000]]],
            'absences' => [7 => [['id' => 5, 'absence_type' => 'vacation']]],
            'statutory_evidence' => [10 => ['income_tax' => ['declaration' => 'not-signed']]],
        ];
    }
}
