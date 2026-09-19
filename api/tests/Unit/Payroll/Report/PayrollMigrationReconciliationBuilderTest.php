<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Report;

use MyInvoice\Service\Payroll\Report\PayrollMigrationReconciliationBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Kontrolní sestava „naše přepočtená mzda vs. mzda převzatá z původního systému".
 *
 * Data jsou SYNTETICKÁ. Repozitář je veřejný, takže tady nesmí být jediný reálný
 * doklad, rodné číslo ani jméno protistrany.
 *
 * Tři věci, které test hlídá, protože právě ony rozhodují, jestli sestava k něčemu je:
 *
 *  1. shodná čísla = nulový rozdíl (jinak by se hlásily odchylky, které nejsou),
 *  2. rozdíl o korunu se objeví v seznamu odchylek (jinak by sestava tiše lhala),
 *  3. chybějící protějšek se NEVYDÁVÁ za nulu — ani na řádku, ani v součtu.
 *
 * Bod 3 je ten podstatný: kdyby se chybějící strana počítala jako 0, sestava by
 * u měsíce, který MyÚčto vůbec nepočítalo, ukázala rozdíl ve výši celé mzdy jako
 * „rozdíl", nebo naopak u 0 = 0 zelenou. Obojí je horší než žádná sestava.
 */
final class PayrollMigrationReconciliationBuilderTest extends TestCase
{
    private const YEAR = 2026;

    public function testMatchingAmountsProduceNoDeviation(): void
    {
        $report = (new PayrollMigrationReconciliationBuilder())->build(
            self::YEAR,
            [$this->reference('2026-03', '1001', '1', 11, 21)],
            [$this->calculated('2026-03', 11)],
            ['2026-03' => 1_066_400],
        );

        self::assertSame([], $report['deviations']);
        self::assertSame(0, $report['summary']['deviation_count']);
        self::assertSame(0, $report['summary']['missing_counterpart_count']);
        self::assertNull($report['summary']['max_abs_difference_minor']);

        $row = $this->onlyRow($report, '2026-03');
        self::assertSame('both', $row['presence']);
        self::assertFalse($row['has_deviation']);
        self::assertSame(0, $row['metrics']['gross']['difference_minor']);
        self::assertSame('match', $row['metrics']['net']['status']);
        // Vztah, ze kterého převzatá strana vznikla, musí být na řádku vidět:
        // rozdíl v POČTU vztahů se jinak za součtem na osobu ztratí.
        self::assertSame(
            [['external_relationship_ref' => '1', 'employment_id' => 21, 'gross_minor' => 4_300_000]],
            $row['relationships'],
        );

        $totals = $report['totals'];
        self::assertSame(0, $totals['gross']['difference_minor']);
        self::assertSame(0, $totals['employer_social']['difference_minor']);
        self::assertFalse($totals['gross']['incomplete']);
    }

    public function testOneCrownDifferenceShowsUpInTheDeviationList(): void
    {
        $report = (new PayrollMigrationReconciliationBuilder())->build(
            self::YEAR,
            [$this->reference('2026-03', '1001', '1', 11, 21)],
            [$this->calculated('2026-03', 11, ['advance_tax_minor' => 388_000 - 100])],
        );

        $row = $this->onlyRow($report, '2026-03');
        self::assertTrue($row['has_deviation']);
        self::assertSame('differs', $row['metrics']['advance_tax']['status']);
        self::assertSame(100, $row['metrics']['advance_tax']['difference_minor']);
        self::assertSame(100, $row['max_abs_difference_minor']);

        $deviations = $report['deviations'];
        self::assertCount(1, $deviations);
        self::assertSame('advance_tax', $deviations[0]['metric']);
        self::assertSame(100, $deviations[0]['difference_minor']);
        self::assertSame('differs', $deviations[0]['status']);
        self::assertSame(11, $deviations[0]['employee_id']);
        self::assertSame(100, $report['summary']['max_abs_difference_minor']);
    }

    public function testDeviationsAreSortedFromTheLargest(): void
    {
        $report = (new PayrollMigrationReconciliationBuilder())->build(
            self::YEAR,
            [
                $this->reference('2026-03', '1001', '1', 11, 21),
                $this->reference('2026-03', '1002', '2', 12, 22),
            ],
            [
                $this->calculated('2026-03', 11, ['advance_tax_minor' => 388_000 - 100]),
                $this->calculated('2026-03', 12, ['gross_minor' => 4_300_000 - 50_000], 'Druhá Zkušební'),
            ],
        );

        self::assertSame(
            [50_000, 100],
            array_map(
                static fn (array $deviation): ?int => $deviation['difference_minor'],
                $report['deviations'],
            ),
        );
        // Řádek s větší odchylkou stojí v měsíci první, ať účetní nemusí scrollovat.
        self::assertSame(12, $report['months'][0]['rows'][0]['employee_id']);
    }

    /**
     * Měsíc, který MyÚčto nepočítalo. Nesmí vyjít jako nulový rozdíl — ani na
     * řádku, ani v ročním součtu, ani v počtu odchylek.
     */
    public function testMonthWithoutOurCalculationIsNotReportedAsZeroDifference(): void
    {
        $report = (new PayrollMigrationReconciliationBuilder())->build(
            self::YEAR,
            [$this->reference('2026-04', '1001', '1', 11, 21)],
            [],
        );

        $row = $this->onlyRow($report, '2026-04');
        self::assertSame('reference_only', $row['presence']);
        self::assertTrue($row['has_deviation']);
        self::assertSame(1, $report['summary']['missing_counterpart_count']);

        $gross = $row['metrics']['gross'];
        self::assertSame(4_300_000, $gross['reference_minor']);
        self::assertNull($gross['calculated_minor'], 'chybějící strana musí být null, ne 0');
        self::assertNull($gross['difference_minor'], 'chybějící protějšek nemá rozdíl čím změřit');
        self::assertSame('calculated_missing', $gross['status']);

        foreach ($report['deviations'] as $deviation) {
            self::assertSame('calculated_missing', $deviation['status']);
            self::assertNull($deviation['difference_minor']);
        }
        self::assertSame(
            count(PayrollMigrationReconciliationBuilder::ROW_METRICS),
            count($report['deviations']),
        );
        self::assertNull(
            $report['summary']['max_abs_difference_minor'],
            'neznámý rozdíl se nesmí započítat jako 0',
        );

        $totals = $report['totals'];
        self::assertSame(4_300_000, $totals['gross']['reference_minor']);
        self::assertNull($totals['gross']['calculated_minor']);
        self::assertNull($totals['gross']['difference_minor']);
        self::assertSame('calculated_missing', $totals['gross']['status']);
    }

    /** Vztah, který původní systém nemá, je druhá polovina téhož pravidla. */
    public function testPersonWithoutReferenceIsNotReportedAsZeroDifference(): void
    {
        $report = (new PayrollMigrationReconciliationBuilder())->build(
            self::YEAR,
            [],
            [$this->calculated('2026-05', 11)],
        );

        $row = $this->onlyRow($report, '2026-05');
        self::assertSame('calculated_only', $row['presence']);
        self::assertNull($row['metrics']['gross']['reference_minor']);
        self::assertSame(4_300_000, $row['metrics']['gross']['calculated_minor']);
        self::assertNull($row['metrics']['gross']['difference_minor']);
        self::assertSame('reference_missing', $row['metrics']['gross']['status']);
        self::assertSame(1, $report['summary']['missing_counterpart_count']);
    }

    /**
     * Osoba z původního systému, kterou převod nenapároval (`employee_id` chybí),
     * nesmí spadnout do cizího řádku ani se ztratit.
     */
    public function testUnmappedReferencePersonGetsItsOwnRow(): void
    {
        $report = (new PayrollMigrationReconciliationBuilder())->build(
            self::YEAR,
            [
                $this->reference('2026-03', '1001', '1', 11, 21),
                $this->reference('2026-03', '1099', '9', null, null),
            ],
            [$this->calculated('2026-03', 11)],
        );

        $rows = $report['months'][0]['rows'];
        self::assertCount(2, $rows);
        $unmapped = array_values(array_filter(
            $rows,
            static fn (array $row): bool => $row['employee_id'] === null,
        ));
        self::assertCount(1, $unmapped);
        self::assertSame('1099', $unmapped[0]['external_person_ref']);
        self::assertSame('reference_only', $unmapped[0]['presence']);
    }

    /**
     * Osoba se dvěma vztahy: převzatá strana má dva řádky `MZ`, naše jeden osobní
     * výsledek. Sečtení vztahů je jediný způsob, jak porovnat daň a pojistné, které
     * jsou ze zákona veličiny osoby.
     */
    public function testRelationshipsOfOnePersonAreSummedIntoOneRow(): void
    {
        $report = (new PayrollMigrationReconciliationBuilder())->build(
            self::YEAR,
            [
                $this->reference('2026-03', '1001', '1', 11, 21, ['gross_minor' => 3_000_000]),
                $this->reference('2026-03', '1001', '2', 11, 22, ['gross_minor' => 1_300_000]),
            ],
            [$this->calculated('2026-03', 11)],
        );

        $row = $this->onlyRow($report, '2026-03');
        self::assertCount(2, $row['relationships']);
        self::assertSame(4_300_000, $row['metrics']['gross']['reference_minor']);
        self::assertSame(0, $row['metrics']['gross']['difference_minor']);
    }

    /**
     * Chybějící osobní výsledek zákonného výpočtu (starší revize) zneplatní jen
     * svůj sloupec — ostatní se porovnají dál a součet se označí jako neúplný.
     */
    public function testMissingStatutoryColumnInvalidatesOnlyThatColumn(): void
    {
        $report = (new PayrollMigrationReconciliationBuilder())->build(
            self::YEAR,
            [$this->reference('2026-03', '1001', '1', 11, 21)],
            [$this->calculated('2026-03', 11, ['social_base_minor' => null])],
        );

        $row = $this->onlyRow($report, '2026-03');
        self::assertSame('both', $row['presence']);
        self::assertSame('calculated_missing', $row['metrics']['social_base']['status']);
        self::assertNull($row['metrics']['social_base']['difference_minor']);
        self::assertSame('match', $row['metrics']['gross']['status']);
        self::assertTrue($report['totals']['social_base']['incomplete']);
        self::assertFalse($report['totals']['gross']['incomplete']);
    }

    /** @param array<string,mixed> $overrides */
    private function reference(
        string $period,
        string $personRef,
        string $relationshipRef,
        ?int $employeeId,
        ?int $employmentId,
        array $overrides = [],
    ): array {
        return [
            'period' => $period,
            'external_person_ref' => $personRef,
            'external_relationship_ref' => $relationshipRef,
            'employee_id' => $employeeId,
            'employment_id' => $employmentId,
            'gross_minor' => 4_300_000,
            'net_minor' => 3_300_000,
            'social_base_minor' => 4_300_000,
            'health_base_minor' => 4_300_000,
            'employee_social_minor' => 279_500,
            'employee_health_minor' => 193_500,
            'employer_social_minor' => 1_066_400,
            'employer_health_minor' => 387_000,
            'advance_tax_minor' => 388_000,
            'withholding_tax_minor' => 0,
            'tax_bonus_minor' => 0,
            ...$overrides,
        ];
    }

    /** @param array<string,mixed> $overrides */
    private function calculated(
        string $period,
        int $employeeId,
        array $overrides = [],
        string $fullName = 'První Zkušební',
    ): array {
        return [
            'period' => $period,
            'employee_id' => $employeeId,
            'full_name' => $fullName,
            'revision_status' => 'calculated',
            'gross_minor' => 4_300_000,
            'net_minor' => 3_300_000,
            'social_base_minor' => 4_300_000,
            'health_base_minor' => 4_300_000,
            'employee_social_minor' => 279_500,
            'employee_health_minor' => 193_500,
            'employer_health_minor' => 387_000,
            'advance_tax_minor' => 388_000,
            'withholding_tax_minor' => 0,
            'tax_bonus_minor' => 0,
            ...$overrides,
        ];
    }

    /**
     * @param array<string,mixed> $report
     * @return array<string,mixed>
     */
    private function onlyRow(array $report, string $period): array
    {
        self::assertCount(1, $report['months'], 'sestava má nést právě jeden měsíc');
        self::assertSame($period, $report['months'][0]['period']);
        self::assertCount(1, $report['months'][0]['rows']);

        return $report['months'][0]['rows'][0];
    }
}
