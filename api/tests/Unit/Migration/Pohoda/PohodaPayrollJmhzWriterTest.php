<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Migration\Pohoda;

use MyInvoice\Service\Migration\Pohoda\Payroll\PohodaPayrollJmhzWriter;
use MyInvoice\Service\Payroll\Import\Jmhz\JmhzReportForm;
use MyInvoice\Service\Payroll\Migration\PayrollTakeoverEmploymentWriter;
use PHPUnit\Framework\TestCase;

/**
 * Co převod PAMICA z podaných hlášení odvodí: průměr čtvrtletí, nárok na děti po měsících
 * a podmínky vztahu z registrace.
 */
final class PohodaPayrollJmhzWriterTest extends TestCase
{
    public function testAverageOfQuarterComesFromItsLatestMonth(): void
    {
        $averages = PohodaPayrollJmhzWriter::averages([
            '2026-01' => ['form' => self::form(averageHourlyMilli: 250_000)],
            '2026-02' => ['form' => self::form(averageHourlyMilli: 255_500)],
            '2026-04' => ['form' => self::form(averageHourlyMilli: null)],
            '2026-05' => ['form' => self::form(averageHourlyMilli: 262_000)],
        ]);

        self::assertSame([
            ['year' => 2026, 'quarter' => 1, 'hourly' => 255.5, 'from' => '', 'to' => '', 'gross' => 0.0, 'worked' => 0.0, 'days' => 0.0],
            ['year' => 2026, 'quarter' => 2, 'hourly' => 262.0, 'from' => '', 'to' => '', 'gross' => 0.0, 'worked' => 0.0, 'days' => 0.0],
        ], $averages);
    }

    public function testChildrenRunFromFirstMonthAndEndWhenTheyDisappear(): void
    {
        $first = ['given_name' => 'Anna', 'family_name' => 'Syntetická', 'birth_date' => null, 'birth_number' => '1501010005', 'ztp_p' => false, 'order' => '1'];
        $second = ['given_name' => 'Boris', 'family_name' => 'Syntetický', 'birth_date' => null, 'birth_number' => '1702020009', 'ztp_p' => false, 'order' => '2'];
        $none = ['given_name' => 'Cyril', 'family_name' => 'Syntetický', 'birth_date' => null, 'birth_number' => '1803030001', 'ztp_p' => false, 'order' => 'N'];

        $result = PohodaPayrollJmhzWriter::children([
            '2026-03' => [self::form(children: [$first])],
            '2026-01' => [self::form(declarationSigned: false, children: [$first, $second])],
            '2026-02' => [self::form(children: [$first, $second, $none]), self::form(summary: false)],
        ]);

        self::assertSame('2026-02', $result['first_signed'], 'Leden nemá podepsané prohlášení, nárok z něj neplyne.');
        self::assertSame([
            ['order' => 1, 'code' => 'jmhz', 'reference' => 'pamica:mh:202602', 'given_name' => 'Anna', 'family_name' => 'Syntetická',
                'birth_number' => '1501010005', 'from' => '2026-02-01', 'to' => null],
            ['order' => 2, 'code' => 'jmhz', 'reference' => 'pamica:mh:202602', 'given_name' => 'Boris', 'family_name' => 'Syntetický',
                'birth_number' => '1702020009', 'from' => '2026-02-01', 'to' => '2026-02-28'],
        ], $result['children']);
    }

    public function testRegistrationTermsTakeOnlyValidValues(): void
    {
        self::assertSame([
            'cz_isco_code' => '41101',
            'activity_code' => '1',
            'jmhz_relationship_detail_code' => '1',
            'work_place' => 'Praha',
            'jmhz_workplace_municipality_code' => '554782',
            'jmhz_workplace_country_code' => 'CZ',
            'regular_workplace' => 'Sklad Sever',
        ], PohodaPayrollJmhzWriter::registrationTerms([
            10234 => '41101', 10239 => '1', 10502 => '1', 10528 => 'Praha', 10529 => '554782', 10527 => 'Sklad Sever',
        ]));
        self::assertSame([], PohodaPayrollJmhzWriter::registrationTerms([10234 => 'abc', 10529 => '55478', 10528 => 'Praha', 10502 => '12']));
    }

    /**
     * Plný úvazek 37,5 h u zaměstnavatele v třísměnném režimu: založení osoby ho
     * dopočítalo ze 40 h jako 93,75 %, podané hlášení (10259 = 10260 = 172,5,
     * 10261 = 37,5) dokládá 100 %. Úvazek, který někdo změnil, zůstane.
     */
    public function testWorkloadDefaultedAtCreationIsCorrectedFromSubmittedReport(): void
    {
        $desired = ['weekly_hours' => '37.50', 'workload_basis_points' => '10000'];
        self::assertSame(10_000, PayrollTakeoverEmploymentWriter::defaultedWorkloadCorrection(
            ['weekly_hours' => '37.50', 'workload_basis_points' => 9375],
            $desired,
        ));
        self::assertNull(PayrollTakeoverEmploymentWriter::defaultedWorkloadCorrection(
            ['weekly_hours' => '37.50', 'workload_basis_points' => 9000],
            $desired,
        ), 'Úvazek změněný ručně se nepřepisuje.');
        self::assertNull(PayrollTakeoverEmploymentWriter::defaultedWorkloadCorrection(
            ['weekly_hours' => '30.00', 'workload_basis_points' => 7500],
            $desired,
        ), 'Jiná týdenní doba není oprava úvazku.');
        self::assertNull(PayrollTakeoverEmploymentWriter::defaultedWorkloadCorrection(
            ['weekly_hours' => '37.50', 'workload_basis_points' => 9375],
            ['weekly_hours' => '37.50', 'workload_basis_points' => '9375'],
        ));
    }

    /** @param list<array<string,mixed>> $children */
    private static function form(
        ?int $averageHourlyMilli = null,
        bool $summary = true,
        bool $declarationSigned = true,
        array $children = [],
    ): JmhzReportForm {
        return new JmhzReportForm(
            position: 1,
            formGuid: '11111111-1111-4111-8111-111111111111',
            formType: 'R',
            primary: true,
            variant: 'bezPriznaku',
            hasSummary: $summary,
            declarationSigned: $summary ? $declarationSigned : null,
            childCredit: $summary && $children !== [] ? ['monthly' => null, 'applied' => null, 'other_caregiver' => false, 'caregivers' => [], 'children' => $children] : null,
            averageHourlyMilli: $averageHourlyMilli,
        );
    }
}
