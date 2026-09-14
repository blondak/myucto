<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Import\Attendance;

use MyInvoice\Service\Payroll\Import\Attendance\AttendanceReferenceComparison;
use PHPUnit\Framework\TestCase;

final class AttendanceReferenceComparisonTest extends TestCase
{
    public function testComparesGrossPerEmploymentAndNetOnlyForSingleEmploymentPeople(): void
    {
        $rows = [
            self::row(1, 'Jana Testovací', 'reference_gross', 4_500_000),
            self::row(1, 'Jana Testovací', 'reference_net', 3_500_000),
            self::row(1, 'Jana Testovací', 'worked_hours', null),
            self::row(2, 'Petr Zkušební', 'reference_gross', 4_200_000),
            self::row(3, 'Eva Pokusná', 'reference_gross', 1_000_000),
            self::row(3, 'Eva Pokusná', 'reference_net', 900_000),
            self::row(5, 'Karel Bez Výpočtu', 'reference_gross', 3_000_000),
        ];
        $results = [
            'run' => ['status' => 'calculated', 'revision_status' => 'calculated'],
            // Jana sedí na korunu, Petr má o 500 Kč víc, Eva má dva vztahy (3 a 4).
            'gross' => [1 => 4_500_050, 2 => 4_250_000, 3 => 1_000_000, 4 => 200_000],
            'net' => [10 => 3_499_990, 20 => 3_300_000, 30 => 1_050_000],
            'employee_of' => [1 => 10, 2 => 20, 3 => 30, 4 => 30],
            'employment_count' => [10 => 1, 20 => 1, 30 => 2],
        ];

        $comparison = AttendanceReferenceComparison::compare($rows, $results);
        $byId = array_column($comparison['rows'], null, 'employment_id');

        self::assertSame(['match' => 2, 'diff' => 1, 'missing' => 1], $comparison['summary']);
        self::assertSame([2, 5, 3, 1], array_column($comparison['rows'], 'employment_id'), 'Rozdíly a chybějící výpočty jsou nahoře.');
        self::assertSame('match', $byId[1]['status']);
        self::assertSame(50, $byId[1]['gross_diff_minor']);
        self::assertSame(-10, $byId[1]['net_diff_minor']);
        self::assertSame('diff', $byId[2]['status']);
        self::assertSame(50_000, $byId[2]['gross_diff_minor']);
        self::assertNull($byId[2]['net_diff_minor'], 'Bez reference čisté mzdy se čistá neporovnává.');
        self::assertTrue($byId[3]['net_shared']);
        self::assertNull($byId[3]['computed_net_minor']);
        self::assertSame('match', $byId[3]['status']);
        self::assertSame('missing', $byId[5]['status']);
        self::assertNull($byId[5]['computed_gross_minor']);
    }

    public function testWithoutCalculatedRunEverythingIsMissing(): void
    {
        $comparison = AttendanceReferenceComparison::compare(
            [self::row(1, 'Jana Testovací', 'reference_gross', 4_500_000)],
            ['run' => null, 'gross' => [], 'net' => [], 'employee_of' => [], 'employment_count' => []],
        );

        self::assertNull($comparison['run']);
        self::assertSame(['match' => 0, 'diff' => 0, 'missing' => 1], $comparison['summary']);
    }

    /** @return array<string,mixed> */
    private static function row(int $employmentId, string $name, string $meaning, ?int $amount): array
    {
        return [
            'employment_id' => $employmentId,
            'employee_name' => $name,
            'employment_code' => 'ZAM-' . $employmentId,
            'meaning' => $meaning,
            'component_code' => null,
            'quantity_millihours' => $amount === null ? 8000 : null,
            'amount_minor' => $amount,
            'source' => 'mzdy.csv!CSV!A' . $employmentId,
        ];
    }
}
