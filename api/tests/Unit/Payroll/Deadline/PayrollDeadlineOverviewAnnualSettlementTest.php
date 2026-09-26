<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Deadline;

use MyInvoice\Repository\Payroll\PayrollDeadlineOverviewRepository;
use MyInvoice\Repository\Payroll\PayrollRegistrationChangeProposalRepository;
use MyInvoice\Repository\Payroll\PayrollSicknessCaseRepository;
use MyInvoice\Service\Payroll\Deadline\PayrollDeadlineOverviewService;
use MyInvoice\Service\Payroll\Deadline\PayrollTaxStatementDeadlinePolicy;
use MyInvoice\Service\Payroll\Ruleset\CzechPayrollRulesets;
use MyInvoice\Service\Payroll\Submission\PayrollDeadlineAssessmentService;
use MyInvoice\Service\Payroll\Submission\Registration\Change\PayrollRegistrationChangeDetectionService;
use MyInvoice\Service\Payroll\Submission\Sickness\SicknessDeadlinePolicy;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;

/**
 * Roční zúčtování a konec povolení cizince v hlídači termínů.
 *
 * Obě lhůty modul znal (§ 38ch ZDP v AnnualSettlementStatute, platnost
 * povolení na kartě osoby), ale přehled termínů o nich nevěděl. Test drží,
 * že se objeví se správným dnem a proklikem, a že žádost po 15. únoru už
 * nestraší — zaměstnanec o zúčtování požádat nemůže.
 */
final class PayrollDeadlineOverviewAnnualSettlementTest extends TestCase
{
    public function testAnnualSettlementDeadlinesAppearWithStatutoryDates(): void
    {
        $service = $this->service('2026-02-10 08:00:00');

        // Výhled 90 dnů, aby do okna padly všechny tři lhůty najednou.
        $items = $this->itemsBySource($service, 'annual_settlement', 90);
        $byTitle = array_column($items, null, 'title');

        self::assertSame(
            ['annual_settlement_request', 'annual_settlement_perform', 'annual_settlement_refund'],
            array_column($items, 'title'),
        );
        $request = $byTitle['annual_settlement_request'];
        self::assertSame('2026-02-15', $request['due_on']);
        self::assertSame('due_soon', $request['phase']);
        self::assertSame(4, $request['undecided_count']);
        self::assertSame(2025, $request['statement_year']);
        self::assertSame('/payroll/annual-settlement?year=2025', $request['path']);

        $perform = $byTitle['annual_settlement_perform'];
        self::assertSame('2026-03-31', $perform['due_on']);
        self::assertSame('Syntetický Žadatel', $perform['subject']);
        self::assertSame('/payroll/annual-settlement?year=2025&person=41', $perform['path']);

        $refund = $byTitle['annual_settlement_refund'];
        self::assertSame('2026-04-30', $refund['due_on']);
        self::assertSame(123_400, $refund['remaining_minor']);
    }

    public function testRequestReminderDisappearsAfterFifteenthOfFebruary(): void
    {
        $service = $this->service('2026-02-20 08:00:00');

        $titles = array_column($this->itemsBySource($service, 'annual_settlement'), 'title');

        self::assertNotContains('annual_settlement_request', $titles);
        self::assertContains('annual_settlement_perform', $titles);
    }

    public function testExpiringForeignPermitIsAPersonDeadline(): void
    {
        $service = $this->service('2026-03-20 08:00:00');

        $items = $this->itemsBySource($service, 'foreign_permit');

        self::assertCount(1, $items);
        self::assertSame('foreign_permit_work', $items[0]['title']);
        self::assertSame('2026-03-31', $items[0]['due_on']);
        self::assertSame('open', $items[0]['phase']);
        self::assertSame('/payroll/people/52', $items[0]['path']);

        $groups = array_values(array_filter(
            $service->groupedOverview(11, 'production')['groups'],
            static fn (array $group): bool => $group['source'] === 'foreign_permit',
        ));
        self::assertTrue($groups[0]['per_person']);
    }

    private function service(string $today): PayrollDeadlineOverviewService
    {
        $clock = $this->createStub(ClockInterface::class);
        $clock->method('now')->willReturn(
            new \DateTimeImmutable($today, new \DateTimeZone('Europe/Prague')),
        );

        $repository = $this->createStub(PayrollDeadlineOverviewRepository::class);
        $repository->method('annualSettlementUndecidedCounts')->willReturnCallback(
            static fn (int $supplierId, array $years): array => in_array(2025, $years, true) ? [2025 => 4] : [],
        );
        $repository->method('annualSettlementsToPerform')->willReturnCallback(
            static fn (int $supplierId, array $years): array => in_array(2025, $years, true)
                ? [['employee_id' => 41, 'full_name' => 'Syntetický Žadatel', 'tax_year' => 2025]]
                : [],
        );
        $repository->method('annualSettlementRefundsUnpaid')->willReturnCallback(
            static fn (int $supplierId, array $years): array => in_array(2025, $years, true)
                ? [['employee_id' => 42, 'full_name' => 'Syntetický Přeplatek', 'tax_year' => 2025, 'payable_minor' => 123_400]]
                : [],
        );
        $repository->method('foreignPermitExpiries')->willReturn([[
            'permit_id' => 7,
            'employee_id' => 52,
            'full_name' => 'Syntetický Cizinec',
            'permit_kind' => 'work',
            'permit_label' => 'Syntetická karta',
            'valid_until' => '2026-03-31',
        ]]);

        $proposals = $this->createStub(PayrollRegistrationChangeProposalRepository::class);
        $proposals->method('openDeadlines')->willReturn([]);
        $sickness = $this->createStub(PayrollSicknessCaseRepository::class);
        $sickness->method('openCases')->willReturn([]);

        return new PayrollDeadlineOverviewService(
            $repository,
            $this->createStub(PayrollDeadlineAssessmentService::class),
            $proposals,
            $this->createStub(PayrollRegistrationChangeDetectionService::class),
            new PayrollTaxStatementDeadlinePolicy(),
            $sickness,
            new SicknessDeadlinePolicy(CzechPayrollRulesets::provider()),
            $clock,
        );
    }

    /** @return list<array<string,mixed>> */
    private function itemsBySource(
        PayrollDeadlineOverviewService $service,
        string $source,
        int $horizonDays = PayrollDeadlineOverviewService::DEFAULT_HORIZON_DAYS,
    ): array {
        return array_values(array_filter(
            $service->overview(11, 'production', $horizonDays)['items'],
            static fn (array $item): bool => $item['source'] === $source,
        ));
    }
}
