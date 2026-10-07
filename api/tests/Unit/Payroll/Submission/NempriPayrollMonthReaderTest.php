<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission;

use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Submission\Sickness\NempriPayrollMonthReader;
use MyInvoice\Service\Payroll\Submission\Sickness\SicknessException;
use PHPUnit\Framework\TestCase;

/**
 * Měsíce rozhodného období ze schválených mzdových běhů (NRO-01): tentýž
 * zdroj jako jednotné měsíční hlášení, ale s vyměřovacím základem PŘED
 * krácením ročním maximem (§ 18 odst. 2 věta třetí) a s vyloučenými dny
 * § 18 odst. 7. Data jsou syntetická.
 */
final class NempriPayrollMonthReaderTest extends TestCase
{
    private const SUPPLIER = 3;
    private const EMPLOYEE = 5;
    private const EMPLOYMENT = 9;

    public function testMonthCarriesUncappedBaseAndSection18Days(): void
    {
        $months = (new NempriPayrollMonthReader())->months(self::SUPPLIER, self::EMPLOYMENT, [
            $this->revision('2026-03-01', 0, 0, [[
                'id' => 11,
                'absence_type' => 'unpaid_leave',
                'date_from' => '2026-03-01',
                'date_to' => '2026-03-31',
            ]]),
            $this->revision('2026-04-01', 25_000_000, 25_000_000),
        ]);

        self::assertSame(['income_minor' => 0, 'excluded_days' => 31], $months['2026-03']);
        self::assertSame(['income_minor' => 25_000_000, 'excluded_days' => 0], $months['2026-04']);
    }

    /** § 19 odst. 9: měsíc dohody bez účasti nese příjem posuzovaný pro účast. */
    public function testAgreementWithoutParticipationCountsParticipationIncome(): void
    {
        $months = (new NempriPayrollMonthReader())->months(self::SUPPLIER, self::EMPLOYMENT, [
            $this->revision('2026-05-01', 0, 900_000),
        ]);

        self::assertSame(900_000, $months['2026-05']['income_minor']);
    }

    /** Nemoc bez zmrazeného okna náhrady mzdy: vyloučené dny nejsou známé, ne nula. */
    public function testUndecidableAbsenceLeavesExcludedDaysUnknown(): void
    {
        $months = (new NempriPayrollMonthReader())->months(self::SUPPLIER, self::EMPLOYMENT, [
            $this->revision('2026-06-01', 1_000_000, 1_000_000, [[
                'id' => 12,
                'absence_type' => 'dpn',
                'date_from' => '2026-06-10',
                'date_to' => '2026-06-30',
            ]]),
        ]);

        self::assertNull($months['2026-06']['excluded_days']);
    }

    public function testOnlyCurrentApprovedRevisionOfTheEmploymentCounts(): void
    {
        $outdated = $this->revision('2026-07-01', 1_000_000, 1_000_000);
        $outdated['current_revision_no'] = 2;
        $foreign = $this->revision('2026-08-01', 1_000_000, 1_000_000, [], 99);

        self::assertSame([], (new NempriPayrollMonthReader())->months(self::SUPPLIER, self::EMPLOYMENT, [$outdated, $foreign]));
    }

    public function testTamperedSnapshotStops(): void
    {
        $revision = $this->revision('2026-03-01', 1_000_000, 1_000_000);
        $revision['input_snapshot_hash'] = str_repeat('0', 64);

        $this->expectException(SicknessException::class);
        (new NempriPayrollMonthReader())->months(self::SUPPLIER, self::EMPLOYMENT, [$revision]);
    }

    /**
     * @param list<array<string,mixed>> $absences
     * @return array<string,mixed>
     */
    private function revision(
        string $periodStart,
        int $assessmentBase,
        int $participationIncome,
        array $absences = [],
        int $employmentId = self::EMPLOYMENT,
    ): array {
        $input = CanonicalJson::encode([
            'schema_version' => 'payroll-run-input.v2',
            'supplier_id' => self::SUPPLIER,
            'period_start' => $periodStart,
            'people' => [[
                'employee' => ['id' => self::EMPLOYEE],
                'employments' => [[
                    'employment' => [
                        'id' => $employmentId,
                        'employee_id' => self::EMPLOYEE,
                        'start_date' => '2020-01-01',
                        'actual_start_date' => '2020-01-01',
                        'end_date' => null,
                    ],
                    'absences' => $absences,
                ]],
            ]],
        ]);
        $result = CanonicalJson::encode([
            'schema_version' => 'payroll-run-result.v2',
            'people' => [[
                'employee_id' => self::EMPLOYEE,
                'statutory' => ['social_insurance' => [
                    'status' => 'calculated',
                    'relationships' => [[
                        'relationship_id' => 'employment:' . $employmentId,
                        'assessment_base_minor_units' => $assessmentBase,
                        'capped_assessment_base_minor_units' => min($assessmentBase, 10_000_000),
                        'participation' => ['participation_income_minor_units' => $participationIncome],
                    ]],
                ]],
            ]],
        ]);

        return [
            'id' => 1,
            'run_id' => 1,
            'revision_no' => 1,
            'current_revision_no' => 1,
            'revision_kind' => 'regular',
            'status' => 'approved',
            'period_start' => $periodStart,
            'input_snapshot_json' => $input,
            'input_snapshot_hash' => hash('sha256', $input),
            'result_snapshot_json' => $result,
            'result_snapshot_hash' => hash('sha256', $result),
        ];
    }
}
