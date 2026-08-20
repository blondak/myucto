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
            $this->root(7_100, 24_800),
        );

        self::assertSame(
            [[
                'office_id' => 4,
                'employee_minor' => 7_100,
                'employer_minor' => 24_800,
                'amount_minor' => 31_900,
            ]],
            $this->legacyShape($result),
        );
    }

    public function testSplitsAcrossOfficesByCappedAssessmentBase(): void
    {
        $result = (new PayrollSocialOfficeAllocator())->allocate(
            $this->input([[1, 7, 4], [2, 8, 5]]),
            $this->people([
                [7, 5_000, [[1, 75_000]]],
                [8, 3_000, [[2, 25_000]]],
            ]),
            $this->root(8_000, 24_000),
        );

        self::assertSame(
            [
                ['office_id' => 4, 'employee_minor' => 5_000, 'employer_minor' => 18_000, 'amount_minor' => 23_000],
                ['office_id' => 5, 'employee_minor' => 3_000, 'employer_minor' => 6_000, 'amount_minor' => 9_000],
            ],
            $this->legacyShape($result),
        );
    }

    /**
     * Rozpad musí sedět v OBOU směrech: bloky A/B/C jedné účtárny dají její
     * závazek a součet přes účtárny dá kořenový výsledek blok po bloku. Kdyby
     * se dělila jen jedna celková částka, jeden ze součtů by se rozešel.
     */
    public function testSplitsEachRateCategorySeparately(): void
    {
        $result = (new PayrollSocialOfficeAllocator())->allocate(
            $this->input([[1, 7, 4], [2, 8, 5], [3, 8, 5]]),
            $this->people([
                [7, 6_500, [[1, 100_000, 'ordinary']]],
                [8, 4_600, [[2, 40_000, 'ordinary'], [3, 30_000, 'risk_employment']]],
            ]),
            $this->root(11_100, 43_200, [
                ['category' => 'ordinary', 'assessment_base_minor_units' => 140_000, 'contribution_minor_units' => 34_800],
                ['category' => 'risk_employment', 'assessment_base_minor_units' => 30_000, 'contribution_minor_units' => 8_400],
            ]),
        );

        self::assertSame(
            [
                ['ordinary' => 24_900, 'risk_employment' => 0],
                ['ordinary' => 9_900, 'risk_employment' => 8_400],
            ],
            array_column($result, 'category_contribution_minor'),
        );
        self::assertSame(
            34_800,
            array_sum(array_column(
                array_column($result, 'category_contribution_minor'),
                'ordinary',
            )),
        );
        self::assertSame(
            [24_900, 18_300],
            array_column($result, 'employer_minor'),
        );
    }

    /**
     * Sleva zaměstnavatele za kratší úvazek visí na VZTAHU, takže patří celá do
     * účtárny toho vztahu — jinak by ji druhá účtárna vykázala bez nároku.
     */
    public function testAttributesPartTimeDiscountToTheOfficeThatClaimsIt(): void
    {
        $result = (new PayrollSocialOfficeAllocator())->allocate(
            $this->input([[1, 7, 4], [2, 8, 5]]),
            $this->people([
                [7, 5_000, [[1, 100_000, 'ordinary', true]]],
                [8, 5_000, [[2, 100_000, 'ordinary']]],
            ]),
            $this->root(10_000, 49_600, [
                ['category' => 'ordinary', 'assessment_base_minor_units' => 200_000, 'contribution_minor_units' => 49_600],
            ], 5_000),
        );

        self::assertSame(
            [5_000, 0],
            array_column($result, 'employer_discount_minor'),
        );
        self::assertSame(
            [1, 0],
            array_column($result, 'employer_discount_person_count'),
        );
        self::assertSame(
            [19_800, 24_800],
            array_column($result, 'employer_minor'),
        );
    }

    /**
     * Kořenová částka je SSOT: rozdělení ji nesmí ani nafouknout, ani zkrátit —
     * na tom stojí kontrolní součty i rekonciliace účetnictví s platbami.
     *
     * Zbytek se rozdává v CELÝCH KORUNÁCH: pojistné jinou jednotku nezná
     * (§ 7 odst. 3, § 8 odst. 1) a PVPOJ haléře vykázat neumí.
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
            $this->root(0, 10_000),
        );

        self::assertSame(
            10_000,
            array_sum(array_column($result, 'employer_minor')),
        );
        self::assertSame(
            [3_400, 3_300, 3_300],
            array_column($result, 'employer_minor'),
        );
    }

    /** Shodná váha i shodný zbytek: pořadí rozhoduje `office_id`, ne náhoda. */
    public function testDistributionIsDeterministic(): void
    {
        $allocator = new PayrollSocialOfficeAllocator();
        $arguments = [
            $this->input([[1, 7, 9], [2, 8, 4], [3, 9, 6]]),
            $this->people([
                [7, 0, [[1, 10_000]]],
                [8, 0, [[2, 10_000]]],
                [9, 0, [[3, 10_000]]],
            ]),
            $this->root(0, 10_000),
        ];

        $first = $allocator->allocate(...$arguments);
        self::assertSame(
            [4, 6, 9],
            array_column($first, 'office_id'),
        );
        self::assertSame(
            [3_400, 3_300, 3_300],
            array_column($first, 'employer_minor'),
        );
        self::assertSame($first, $allocator->allocate(...$arguments));
    }

    public function testSplitsPersonContributionAcrossHerOffices(): void
    {
        $result = (new PayrollSocialOfficeAllocator())->allocate(
            $this->input([[1, 7, 4], [2, 7, 5]]),
            $this->people([[7, 6_000, [[1, 60_000], [2, 40_000]]]]),
            $this->root(6_000, 0),
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
            $this->root(7_100, 24_800),
        );
    }

    public function testRejectsRelationshipMissingFromFrozenInput(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('není ve zmrazeném vstupu');
        (new PayrollSocialOfficeAllocator())->allocate(
            $this->input([[1, 7, 4]]),
            $this->people([[7, 7_100, [[2, 100_000]]]]),
            $this->root(7_100, 24_800),
        );
    }

    public function testRejectsPersonTotalsThatDoNotMatchTheRoot(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('nesouhlasí s kořenovým výsledkem');
        (new PayrollSocialOfficeAllocator())->allocate(
            $this->input([[1, 7, 4]]),
            $this->people([[7, 7_000, [[1, 100_000]]]]),
            $this->root(7_100, 24_800),
        );
    }

    public function testRejectsCategoryRollupThatDoesNotMatchRelationships(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('neodpovídá součtu vztahů');
        (new PayrollSocialOfficeAllocator())->allocate(
            $this->input([[1, 7, 4]]),
            $this->people([[7, 5_000, [[1, 100_000, 'ordinary']]]]),
            $this->root(5_000, 24_800, [
                ['category' => 'ordinary', 'assessment_base_minor_units' => 90_000, 'contribution_minor_units' => 24_800],
            ]),
        );
    }

    /**
     * @param list<array{category:string,assessment_base_minor_units:int,contribution_minor_units:int}> $categories
     * @return array<string,mixed>
     */
    private function root(
        int $employee,
        int $employerBeforeDiscount,
        array $categories = [],
        int $discount = 0,
    ): array {
        return [
            'status' => 'calculated',
            'employee_contribution_minor_units' => $employee,
            'employer_contribution_before_discount_minor_units' =>
                $employerBeforeDiscount,
            'part_time_discount_minor_units' => $discount,
            'employer_contribution_minor_units' =>
                $employerBeforeDiscount - $discount,
            'employer_categories' => $categories,
        ];
    }

    /**
     * @param list<array<string,mixed>> $result
     * @return list<array<string,mixed>>
     */
    private function legacyShape(array $result): array
    {
        return array_map(
            static fn (array $row): array => [
                'office_id' => $row['office_id'],
                'employee_minor' => $row['employee_minor'],
                'employer_minor' => $row['employer_minor'],
                'amount_minor' => $row['amount_minor'],
            ],
            $result,
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
     * @param list<array{0:int,1:int,2:list<array{0:int,1:int,2?:string,3?:bool}>}> $people
     * @return list<array<string,mixed>>
     */
    private function people(array $people): array
    {
        $rows = [];
        foreach ($people as [$employeeId, $contribution, $relationships]) {
            $relationshipRows = [];
            foreach ($relationships as $relationship) {
                [$employmentId, $cappedBase] = $relationship;
                $snapshot = [
                    'capped_assessment_base_minor_units' => $cappedBase,
                ];
                if (isset($relationship[2])) {
                    $snapshot['employer_rate_category'] = $relationship[2];
                }
                if ($relationship[3] ?? false) {
                    $snapshot['part_time_employer_discount'] = 'verified';
                }
                $relationshipRows[] = [
                    'employment_id' => $employmentId,
                    'result_snapshot' => $snapshot,
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
