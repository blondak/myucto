<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use DateTimeImmutable;
use MyInvoice\Repository\Payroll\PayrollStatutoryAccumulatorRepository;
use MyInvoice\Service\Payroll\AnnualSettlement\AnnualTaxSettlementService;
use MyInvoice\Service\Payroll\Document\PayrollCarriedOverPeriodReader;
use MyInvoice\Service\Payroll\Migration\PayrollTakeoverCoverage;
use MyInvoice\Service\Payroll\Migration\PayrollTakeoverLayerCheck;
use MyInvoice\Service\Payroll\Migration\PayrollTakeoverManualEntryService;
use MyInvoice\Service\Payroll\PayrollYearCloseService;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\TaxStatement\TaxStatementService;
use MyInvoice\Tests\Support\PayrollFullFlowTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Přechod v průběhu roku celým tokem.
 *
 * Firma vede mzdy v MyÚčtu od října; leden až září zpracoval předchozí program.
 * Účetní převezme měsíce 1–9 ručním zadáním, spočítá a schválí říjen až
 * prosinec a nad celým rokem pak musí sedět tytéž úhrny v ročních kumulacích,
 * ve vyúčtování daně i v ročním zúčtování. Kontrola převzetí ani uzávěrka roku
 * nesmí najít mezeru.
 */
#[Group('integration')]
#[Group('payroll-full-flow')]
final class PayrollMidYearTransitionFlowTest extends TestCase
{
    use PayrollFullFlowTrait;

    private const YEAR = 2026;
    private const MONTHLY_GROSS_MINOR = 40_000_00;
    /** Záloha bez slev: 15 % z 40 000 Kč. */
    private const TAKEOVER_ADVANCE_MINOR = 6_000_00;

    /** @var array{employee_id:int,employment_id:int,name:string} */
    private array $person;
    private int $officeId;

    protected function setUp(): void
    {
        $this->bootPayrollFullFlow();
        $this->db->pdo()->prepare(
            'UPDATE payroll_module_state SET start_period = "2026-10-01" WHERE supplier_id = ?',
        )->execute([$this->supplierId]);

        $this->officeId = $this->createOffice();
        $this->configureSocialInsuranceOutput($this->officeId);
        $this->configureHealthInsuranceOutput();
        $this->configureIncomeTaxOutput();
        $baseComponentId = $this->createComponent('MZDA_PRECHOD', 'base_wage', 'regular');
        $this->person = $this->createEmployment(
            $this->officeId,
            'Syntetická přecházející',
            1,
            'hpp',
            'employment',
            40,
            10_000,
            periodStart: '2026-10-01',
        );
        foreach (['2026-10-01', '2026-11-01', '2026-12-01'] as $period) {
            $this->createApprovedInput(
                $this->person,
                $baseComponentId,
                self::MONTHLY_GROSS_MINOR,
                'transition-base-' . substr($period, 0, 7),
                $period,
            );
        }
        foreach (['2026-11-01', '2026-12-01'] as $period) {
            $this->db->pdo()->prepare(
                'INSERT INTO payroll_enforcement_person_month_evidence
                    (supplier_id, employee_id, period_start,
                     claim_register_evidence_complete, dependants_evidence_complete,
                     spouse_evidence_complete, pension_evidence, updated_by)
                 VALUES (?, ?, ?, 1, 1, 1, "none", ?)',
            )->execute([$this->supplierId, $this->person['employee_id'], $period, $this->actors[0]]);
        }
    }

    protected function tearDown(): void
    {
        $this->tearDownPayrollFullFlow();
    }

    public function testTakeoverOfNineMonthsAndThreeRunsCloseTheYearWithMatchingTotals(): void
    {
        // 1) Převzetí ledna až září jedním ručním zadáním — plní obě vrstvy.
        $manual = $this->service(PayrollTakeoverManualEntryService::class);
        $form = $manual->form($this->supplierId, $this->person['employee_id'], self::YEAR);
        self::assertSame(range(1, 9), $form['takeover_months']);
        $rows = [];
        for ($month = 1; $month <= 9; ++$month) {
            $rows[] = $this->takeoverRow($month);
        }
        $manual->save($this->supplierId, $this->person['employee_id'], self::YEAR, $rows, 'Výplatní listiny 1–9', $this->actors[0]);

        $coverage = $this->service(PayrollTakeoverCoverage::class);
        self::assertSame([], $coverage->gaps($this->supplierId, self::YEAR));
        $layers = $this->service(PayrollTakeoverLayerCheck::class)->check($this->supplierId, self::YEAR);
        self::assertSame([], $layers['differences']);
        self::assertSame([], $layers['opening_only']);
        self::assertSame([], $layers['takeover_only']);

        // 2) Říjen až prosinec počítá MyÚčto.
        foreach ([
            ['2026-10-01', '2026-11-10'],
            ['2026-11-01', '2026-12-10'],
            ['2026-12-01', '2027-01-08'],
        ] as [$period, $payday]) {
            $run = $this->runPayrollMonth($period, $payday, $this->officeId, 'transition-' . substr($period, 0, 7));
            self::assertSame([], $run['blockers'], CanonicalJson::encode($run['blockers']));
            self::assertSame([], $run['warnings'], CanonicalJson::encode($run['warnings']));
            self::assertNotNull($run['approved']);
        }

        // 3) Roční kumulace: převzaté měsíce + tři vlastní běhy = dvanáct měsíců.
        [$runBase, $runAdvance] = $this->runTaxTotals();
        self::assertSame(3 * self::MONTHLY_GROSS_MINOR, $runBase);
        $yearBase = 9 * self::MONTHLY_GROSS_MINOR + $runBase;
        $yearAdvance = 9 * self::TAKEOVER_ADVANCE_MINOR + $runAdvance;
        $state = $this->service(PayrollStatutoryAccumulatorRepository::class)
            ->stateForYear($this->supplierId, $this->person['employee_id'], self::YEAR, 'income_tax');
        self::assertSame(12, $state['totals']['completed_months']);
        self::assertSame($yearBase, $state['totals']['advance_base_minor_units']);
        self::assertSame($yearAdvance, $state['totals']['advance_tax_minor_units']);

        // 4) Vyúčtování daně: převzaté měsíce z počátečních stavů, žádná závora.
        $statement = $this->service(TaxStatementService::class)->preview($this->supplierId, self::YEAR)['dpzvd6'];
        self::assertSame(intdiv($yearAdvance, 100), $statement['total']['advance_due']);
        self::assertCount(12, $statement['months']);
        foreach ($statement['months'] as $row) {
            self::assertSame(1, $row['headcount'], 'Měsíc ' . $row['month']);
        }
        self::assertSame([], $statement['blockers'], implode(' ', $statement['blockers']));
        self::assertSame(range(1, 9), $statement['taken_over_months']);
        self::assertSame([], $statement['takeover_gaps']);

        // 5) Roční zúčtování vidí celý rok.
        $this->prepareAnnualSettlementRequest();
        $settlement = $this->service(AnnualTaxSettlementService::class)->preview(
            $this->supplierId,
            $this->person['employee_id'],
            self::YEAR,
            new DateTimeImmutable('2027-02-20'),
        )['result'];
        self::assertSame([], $settlement->blockerCodes());
        self::assertTrue($settlement->performed);
        self::assertSame(intdiv($yearBase, 100_00) * 100_00, $settlement->roundedTaxBaseMinorUnits);
        self::assertSame(
            $yearAdvance,
            $settlement->taxAfterAllCreditsMinorUnits + $settlement->taxDifferenceMinorUnits,
        );

        // 6) Potvrzení o zdanitelných příjmech a mzdový list berou převzatou část
        //    z téhož zdroje. Celý doklad potřebuje doloženou výplatu vlastních
        //    měsíců; ten krok má AnnualTaxCertificateSnapshotBuilderIntegrationTest.
        $carried = $this->service(PayrollCarriedOverPeriodReader::class)
            ->read($this->supplierId, $this->person['employee_id'], self::YEAR, 10);
        self::assertNotNull($carried);
        self::assertSame(range(1, 9), $carried->months);
        self::assertSame('1–9', $carried->label());
        self::assertSame(9 * self::MONTHLY_GROSS_MINOR, $carried->taxAmount('advance_base_minor_units'));
        self::assertSame(9 * self::TAKEOVER_ADVANCE_MINOR, $carried->taxAmount('advance_tax_minor_units'));
        self::assertSame(
            $yearBase,
            $carried->taxAmount('advance_base_minor_units') + $runBase,
            'Převzatá a vlastní část potvrzení musí dát úhrn, ze kterého počítá roční zúčtování.',
        );

        // 7) Uzávěrka roku nenajde převzatý měsíc bez počátečního stavu.
        $close = $this->service(PayrollYearCloseService::class)->status($this->supplierId, self::YEAR);
        self::assertNotContains('takeover_months_missing', array_column($close['blockers'], 'code'));
        self::assertNotContains('takeover_layers_mismatch', array_column($close['warnings'] ?? [], 'code'));
    }

    /**
     * Dohoda o provedení práce: leden až září po 35 hodinách u předchozího
     * programu = 315 h. Kontrola říjnového běhu musí limit 300 h hlásit, i když
     * MyÚčto samo zatím žádnou hodinu nezapočítalo.
     */
    public function testOctoberRunWarnsWhenTakenOverAgreementHoursExceedTheLimit(): void
    {
        $agreement = $this->createEmployment(
            $this->officeId,
            'Syntetický brigádník',
            2,
            'dpp',
            'dpp',
            10,
            2_500,
            periodStart: '2026-10-01',
        );
        $this->createApprovedInput($agreement, $this->componentId('MZDA_PRECHOD'), 5_000_00, 'transition-dpp-2026-10', '2026-10-01');
        $manual = $this->service(PayrollTakeoverManualEntryService::class);
        foreach ([$this->person, $agreement] as $person) {
            $rows = [];
            for ($month = 1; $month <= 9; ++$month) {
                $rows[] = $person === $agreement
                    ? $this->agreementRow($agreement['employment_id'], $month)
                    : $this->takeoverRow($month);
            }
            $manual->save($this->supplierId, $person['employee_id'], self::YEAR, $rows, 'Výplatní listiny 1–9', $this->actors[0]);
        }

        $run = $this->runPayrollMonth('2026-10-01', '2026-11-10', $this->officeId, 'transition-dpp');

        $statement = $this->db->pdo()->prepare(
            'SELECT severity, message FROM payroll_run_validations
              WHERE supplier_id = ? AND revision_id = ? AND code = "dpp_annual_hours_exceeded"',
        );
        $statement->execute([$this->supplierId, (int) $run['calculated']->revision['id']]);
        $found = $statement->fetchAll(\PDO::FETCH_ASSOC);
        self::assertCount(1, $found);
        self::assertSame('warning', $found[0]['severity']);
        self::assertStringContainsString('Syntetický brigádník', $found[0]['message']);
        self::assertStringContainsString('315 h', $found[0]['message']);
    }

    /** @return array<string,int|bool|null> */
    private function agreementRow(int $employmentId, int $month): array
    {
        $gross = 10_000_00;

        return [
            'employment_id' => $employmentId,
            'month' => $month,
            'gross_minor' => $gross,
            'net_minor' => $gross - 1_500_00,
            'deductions_minor' => 0,
            'net_payable_minor' => $gross - 1_500_00,
            'social_base_minor' => 0,
            'health_base_minor' => 0,
            'employee_social_minor' => 0,
            'employee_health_minor' => 0,
            'employer_social_minor' => 0,
            'employer_health_minor' => 0,
            'health_minimum_top_up_minor' => 0,
            'advance_base_minor' => 0,
            'advance_tax_minor' => 0,
            'withholding_base_minor' => $gross,
            'withholding_tax_minor' => 1_500_00,
            'applied_credits_minor' => 0,
            'applied_child_credit_minor' => 0,
            'tax_bonus_minor' => 0,
            'insurance_days' => 0,
            'excluded_days' => 0,
            'worked_days_hundredths' => 500,
            'worked_minutes' => 35 * 60,
            'pension_participation' => false,
            'payout_date' => sprintf('%04d-%02d-10', self::YEAR, $month + 1),
        ];
    }

    /** @return array<string,int|bool|null> */
    private function takeoverRow(int $month): array
    {
        $gross = self::MONTHLY_GROSS_MINOR;
        $social = intdiv($gross * 71, 1000);
        $health = intdiv($gross * 45, 1000);
        $net = $gross - $social - $health - self::TAKEOVER_ADVANCE_MINOR;

        return [
            'employment_id' => $this->person['employment_id'],
            'month' => $month,
            'gross_minor' => $gross,
            'net_minor' => $net,
            'deductions_minor' => 0,
            'net_payable_minor' => $net,
            'social_base_minor' => $gross,
            'health_base_minor' => $gross,
            'employee_social_minor' => $social,
            'employee_health_minor' => $health,
            'employer_social_minor' => intdiv($gross * 248, 1000),
            'employer_health_minor' => intdiv($gross * 90, 1000),
            'health_minimum_top_up_minor' => 0,
            'advance_base_minor' => $gross,
            'advance_tax_minor' => self::TAKEOVER_ADVANCE_MINOR,
            'withholding_base_minor' => 0,
            'withholding_tax_minor' => 0,
            'applied_credits_minor' => 0,
            'applied_child_credit_minor' => 0,
            'tax_bonus_minor' => 0,
            'insurance_days' => (int) date('t', mktime(0, 0, 0, $month, 1, self::YEAR)),
            'excluded_days' => 0,
            'worked_days_hundredths' => 2000,
            'worked_minutes' => 20 * 8 * 60,
            'pension_participation' => true,
            'payout_date' => sprintf('%04d-%02d-10', self::YEAR, $month + 1),
        ];
    }

    /** @return array{int,int} základ a záloha daně ze schválených běhů */
    private function runTaxTotals(): array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT values_json FROM payroll_statutory_accumulator_entries
              WHERE supplier_id = ? AND employee_id = ? AND tax_year = ? AND calculation_kind = "income_tax"',
        );
        $statement->execute([$this->supplierId, $this->person['employee_id'], self::YEAR]);
        $base = 0;
        $advance = 0;
        $months = 0;
        foreach ($statement->fetchAll(\PDO::FETCH_COLUMN) as $json) {
            $values = json_decode((string) $json, true, 512, JSON_THROW_ON_ERROR);
            $base += (int) $values['advance_base_minor_units'];
            $advance += (int) $values['advance_tax_minor_units'];
            ++$months;
        }
        self::assertSame(3, $months, 'Každý schválený běh zapíše jeden měsíc ročních kumulací.');

        return [$base, $advance];
    }

    private function prepareAnnualSettlementRequest(): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_annual_settlement_requests
                (supplier_id, employee_id, tax_year, request_status,
                 requested_on, request_evidence_reference, prior_employers,
                 filing_obligation, filing_obligation_reason, annual_claims)
             VALUES (?, ?, ?, "requested", "2027-02-05", "synthetic-request", "none",
                     "none", NULL, "none")',
        )->execute([$this->supplierId, $this->person['employee_id'], self::YEAR]);
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_person_tax_credit_claims
                (supplier_id, employee_id, credit_kind, evidence_status,
                 effective_from, evidence_reference)
             VALUES (?, ?, "taxpayer", "verified", "2026-01-01", "synthetic-credit")',
        )->execute([$this->supplierId, $this->person['employee_id']]);
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @return T
     */
    private function service(string $class): object
    {
        $service = $this->container->get($class);
        self::assertInstanceOf($class, $service);

        return $service;
    }
}
