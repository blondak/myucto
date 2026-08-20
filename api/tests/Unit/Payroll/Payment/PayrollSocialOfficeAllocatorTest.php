<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Payment;

use MyInvoice\Service\Payroll\Payment\PayrollSocialOfficeAllocator;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
final class PayrollSocialOfficeAllocatorTest extends TestCase
{
    public function testSingleOfficeKeepsWholeAmount(): void
    {
        $result = (new PayrollSocialOfficeAllocator())->allocate(
            $this->input([[1, 7, 4]]),
            $this->people([[7, 7_100, [[1, 100_000]]]]),
            7_100,
            24_800,
        );

        self::assertSame([[
            'office_id' => 4,
            'employee_minor' => 7_100,
            'employer_minor' => 24_800,
            'amount_minor' => 31_900,
        ]], $result);
    }

    public function testSplitsAcrossOfficesByCappedAssessmentBase(): void
    {
        $result = (new PayrollSocialOfficeAllocator())->allocate(
            $this->input([[1, 7, 4], [2, 8, 5]]),
            $this->people([
                [7, 5_000, [[1, 75_000]]],
                [8, 3_000, [[2, 25_000]]],
            ]),
            8_000,
            24_000,
        );

        self::assertSame(
            [
                ['office_id' => 4, 'employee_minor' => 5_000, 'employer_minor' => 18_000, 'amount_minor' => 23_000],
                ['office_id' => 5, 'employee_minor' => 3_000, 'employer_minor' => 6_000, 'amount_minor' => 9_000],
            ],
            $result,
        );
    }

    /**
     * Kořenová částka je SSOT: rozdělení ji nesmí ani nafouknout, ani zkrátit —
     * na tom stojí kontrolní součty i rekonciliace účetnictví s platbami.
     */
    public function testDistributesRemainderWithoutChangingTheTotal(): void
    {
        $result = (new PayrollSocialOfficeAllocator())->allocate(
            $this->input([[1, 7, 4], [2, 8, 5], [3, 9, 6]]),
            $this->people([
                [7, 0, [[1, 10_000]]],
                [8, 0, [[2, 10_000]]],
                [9, 0, [[3, 10_000]]],
            ]),
            0,
            10_000,
        );

        self::assertSame(
            10_000,
            array_sum(array_column($result, 'employer_minor')),
        );
        self::assertSame(
            [3_334, 3_333, 3_333],
            array_column($result, 'employer_minor'),
        );
    }

    public function testSplitsPersonContributionAcrossHerOffices(): void
    {
        $result = (new PayrollSocialOfficeAllocator())->allocate(
            $this->input([[1, 7, 4], [2, 7, 5]]),
            $this->people([[7, 6_000, [[1, 60_000], [2, 40_000]]]]),
            6_000,
            0,
        );

        self::assertSame(
            [3_600, 2_400],
            array_column($result, 'employee_minor'),
        );
    }

    public function testRejectsEmploymentWithoutOffice(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('nemá mzdovou účtárnu');
        (new PayrollSocialOfficeAllocator())->allocate(
            $this->input([[1, 7, null]]),
            $this->people([[7, 7_100, [[1, 100_000]]]]),
            7_100,
            24_800,
        );
    }

    public function testRejectsRelationshipMissingFromFrozenInput(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('není ve zmrazeném vstupu');
        (new PayrollSocialOfficeAllocator())->allocate(
            $this->input([[1, 7, 4]]),
            $this->people([[7, 7_100, [[2, 100_000]]]]),
            7_100,
            24_800,
        );
    }

    public function testRejectsPersonTotalsThatDoNotMatchTheRoot(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('nesouhlasí s kořenovým výsledkem');
        (new PayrollSocialOfficeAllocator())->allocate(
            $this->input([[1, 7, 4]]),
            $this->people([[7, 7_000, [[1, 100_000]]]]),
            7_100,
            24_800,
        );
    }

    /**
     * @param list<array{0:int,1:int,2:?int}> $employments employment_id, employee_id, office_id
     * @return array<string,mixed>
     */
    private function input(array $employments): array
    {
        $people = [];
        foreach ($employments as [$employmentId, $employeeId, $officeId]) {
            $people[$employeeId] ??= [
                'employee' => ['id' => $employeeId],
                'employments' => [],
            ];
            $people[$employeeId]['employments'][] = [
                'employment' => [
                    'id' => $employmentId,
                    'employee_id' => $employeeId,
                    'office_id' => $officeId,
                ],
            ];
        }

        return [
            'schema_version' => 'payroll-run-input.v2',
            'office_id' => null,
            'people' => array_values($people),
        ];
    }

    /**
     * @param list<array{0:int,1:int,2:list<array{0:int,1:int}>}> $people
     * @return list<array<string,mixed>>
     */
    private function people(array $people): array
    {
        $rows = [];
        foreach ($people as [$employeeId, $contribution, $relationships]) {
            $relationshipRows = [];
            foreach ($relationships as [$employmentId, $cappedBase]) {
                $relationshipRows[] = [
                    'employment_id' => $employmentId,
                    'result_snapshot' => [
                        'capped_assessment_base_minor_units' => $cappedBase,
                    ],
                ];
            }
            $rows[] = [
                'employee_id' => $employeeId,
                'relationships' => $relationshipRows,
                'result_snapshot' => [
                    'employee_contribution_minor_units' => $contribution,
                ],
            ];
        }

        return $rows;
    }
}
