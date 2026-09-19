<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll;

use MyInvoice\Service\Payroll\Migration\PayrollTakeoverMonth;
use MyInvoice\Service\Payroll\Migration\PayrollTakeoverYear;
use MyInvoice\Service\Payroll\Run\PayrollTakeoverRunBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Sestavení převzatého běhu bez databáze.
 *
 * Tvrzení „převzatý výsledek se NEPOČÍTÁ" se dá ověřit jedině tady: dvě
 * pracovní smlouvy jedné osoby se sečtou a nic víc se nestane — žádná sazba,
 * žádný strop, žádný dopočet chybějící veličiny.
 */
final class PayrollRunTakeoverBuilderTest extends TestCase
{
    public function testResultIsASumAndIsMarkedAsNotCalculated(): void
    {
        $build = (new PayrollTakeoverRunBuilder())->build(
            $this->year([
                $this->month('2026-03', 'R-1', 20_000_00, 15_000_00, '2026-04-10'),
                $this->month('2026-03', 'R-2', 6_000_00, 3_000_00, '2026-04-10'),
            ]),
            '2026-03',
        );

        self::assertSame(
            PayrollTakeoverRunBuilder::RESULT_SCHEMA,
            $build->resultSnapshot['schema_reference'],
        );
        self::assertFalse($build->resultSnapshot['calculated']);
        self::assertSame('takeover', $build->resultSnapshot['run_kind']);
        self::assertSame(26_000_00, $build->resultSnapshot['totals']['gross_minor']);
        self::assertSame(18_000_00, $build->resultSnapshot['totals']['net_payable_minor']);
        // Jedna osoba, dva vztahy — pojistné i daň jsou veličiny osoby.
        self::assertCount(1, $build->resultSnapshot['people']);
        self::assertSame(2, $build->resultSnapshot['people'][0]['relationship_count']);
        self::assertSame(1, $build->employeeCount);
        self::assertSame(2, $build->relationshipCount);
        // Otisk je otiskem TOHO, co se uložilo; mění se s obsahem.
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $build->resultSnapshotHash);
        self::assertNotSame($build->inputSnapshotHash, $build->resultSnapshotHash);
    }

    public function testNetWageEvidenceIsReportedOnlyWithAPayoutDate(): void
    {
        $withDate = (new PayrollTakeoverRunBuilder())->build(
            $this->year([$this->month('2026-03', 'R-1', 20_000_00, 15_000_00, '2026-04-10')]),
            '2026-03',
        );
        $withoutDate = (new PayrollTakeoverRunBuilder())->build(
            $this->year([$this->month('2026-03', 'R-1', 20_000_00, 15_000_00, null)]),
            '2026-03',
        );

        self::assertSame('reported', $withDate->paymentEvidence[0]['certainty']);
        self::assertSame('2026-04-10', $withDate->paymentEvidence[0]['paid_on']);
        self::assertSame('derived', $withoutDate->paymentEvidence[0]['certainty']);
        self::assertNull($withoutDate->paymentEvidence[0]['paid_on']);
    }

    /**
     * Dva různé dny výplaty u jedné osoby jsou legitimní stav (souběžné
     * vztahy), ale jednu platbu jimi doložit nejde — datum proto odpadne.
     */
    public function testConflictingPayoutDatesLeaveTheEvidenceWithoutADate(): void
    {
        $build = (new PayrollTakeoverRunBuilder())->build(
            $this->year([
                $this->month('2026-03', 'R-1', 20_000_00, 15_000_00, '2026-04-10'),
                $this->month('2026-03', 'R-2', 6_000_00, 3_000_00, '2026-04-15'),
            ]),
            '2026-03',
        );

        self::assertSame('derived', $build->paymentEvidence[0]['certainty']);
        self::assertNull($build->paymentEvidence[0]['paid_on']);
    }

    public function testMonthWithoutTakeoverDataIsRefused(): void
    {
        $this->expectException(\DomainException::class);
        (new PayrollTakeoverRunBuilder())->build($this->year([]), '2026-05');
    }

    /** @param list<PayrollTakeoverMonth> $months */
    private function year(array $months): PayrollTakeoverYear
    {
        return new PayrollTakeoverYear(1, 2026, '2026-07', $months, []);
    }

    private function month(
        string $period,
        string $relationshipRef,
        int $gross,
        int $netPayable,
        ?string $payoutDate,
    ): PayrollTakeoverMonth {
        return PayrollTakeoverMonth::fromRow([
            'period' => $period,
            'source' => 'pamica',
            'external_person_ref' => 'P-1',
            'external_relationship_ref' => $relationshipRef,
            'employee_id' => 7,
            'employment_id' => null,
            'gross_minor' => $gross,
            'net_payable_minor' => $netPayable,
            'payout_date' => $payoutDate,
        ]);
    }
}
