<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Repository\Payroll\PayrollPersonStatutoryEvidenceRepository;
use MyInvoice\Tests\Support\PayrollFullFlowTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Minimum vyměřovacího základu zdravotního pojištění od zákonné evidence
 * osoby až po výsledek mzdového běhu.
 *
 * Scénář je jednatel s odměnou 4 500 Kč bez prohlášení (typický případ, kdy
 * převzatý program doplatek do minima nepočítal):
 *
 * 1. bez výjimky platí § 3 odst. 6 a 10 zákona č. 592/1992 Sb.: doplatek
 *    13,5 % z rozdílu do minimální mzdy hradí zaměstnanec;
 * 2. výjimka zadaná na kartě osoby (§ 3 odst. 8) doplatek zruší, od půlky
 *    měsíce ho poměrně sníží (§ 3 odst. 9 písm. c));
 * 3. pracující důchodce s ověřenou slevou na pojistném je státní pojištěnec
 *    i bez ručně zadané výjimky.
 *
 * Dřív výjimky zadat nešlo vůbec (sekce chyběla v zapisovací cestě) a důchodce
 * se z evidence slevy neodvozoval, běh doplácel pojistné, které se nedluží.
 */
#[Group('integration')]
#[Group('payroll-full-flow')]
final class PayrollHealthMinimumExemptionFlowTest extends TestCase
{
    use PayrollFullFlowTrait;

    /** Doplatek za červenec 2026 při odměně 4 500 Kč (viz PayrollSyntheticFullFlowTest). */
    private const FULL_MONTH_TOP_UP = 241_600;

    private int $officeId;
    private int $componentId;

    protected function setUp(): void
    {
        $this->bootPayrollFullFlow();
        $this->officeId = $this->createOffice('ZPMIN', 'Syntetická účtárna minima', '9990004321');
        $this->configureSocialInsuranceOutput($this->officeId);
        $this->configureHealthInsuranceOutput();
        $this->componentId = $this->createComponent('ODMENA_JEDNATELE_ZP', 'base_wage', 'regular');
    }

    protected function tearDown(): void
    {
        $this->tearDownPayrollFullFlow();
    }

    public function testStatutoryBodyBelowMinimumWithoutExemptionOwesTheTopUp(): void
    {
        $health = $this->runJuly($this->statutoryBody(21), 'zp-min-none');

        self::assertSame(self::FULL_MONTH_TOP_UP, $health['employee_minimum_top_up_minor_units']);
    }

    public function testStateInsuredExemptionEnteredOnThePersonCardRemovesTheTopUp(): void
    {
        $person = $this->statutoryBody(22);
        $this->addReduction($person['employee_id'], 'state_insured', '2026-01-01');

        $health = $this->runJuly($person, 'zp-min-state');

        self::assertSame(0, $health['employee_minimum_top_up_minor_units']);
    }

    public function testExemptionFromMidMonthReducesTheMinimumProportionally(): void
    {
        $person = $this->statutoryBody(23);
        $this->addReduction($person['employee_id'], 'ztp_or_ztp_p', '2026-07-16');

        $health = $this->runJuly($person, 'zp-min-half');

        $topUp = $health['employee_minimum_top_up_minor_units'];
        self::assertGreaterThan(0, $topUp);
        self::assertLessThan(self::FULL_MONTH_TOP_UP, $topUp);
    }

    public function testVerifiedWorkingPensionerIsStateInsuredWithoutManualExemption(): void
    {
        $person = $this->createEmployment(
            $this->officeId,
            'Důchodce Syntetický',
            24,
            'hpp',
            'employment',
            10,
            2_500,
            false,
            '2026-07-01',
            false,
            'employee',
            false,
            'ineligible',
            'advance',
            'verified',
        );

        $health = $this->runJuly($person, 'zp-min-pensioner');

        self::assertSame(0, $health['employee_minimum_top_up_minor_units']);
    }

    // --- pomocníci ---------------------------------------------------------

    /** @return array{employee_id:int,employment_id:int,name:string} */
    private function statutoryBody(int $sequence): array
    {
        return $this->createEmployment(
            $this->officeId,
            'Jednatel Syntetický',
            $sequence,
            'statutory_body',
            'statutory_body',
            40,
            10_000,
            false,
            '2026-07-01',
            false,
            'managing_partner',
            false,
            'ineligible',
        );
    }

    /**
     * Výjimka zapsaná TOU cestou, kterou jde karta osoby: editor vrátí celý
     * stav, přidá se řádek a uloží se cílový stav.
     */
    private function addReduction(int $employeeId, string $reason, string $from): void
    {
        $repository = $this->container->get(PayrollPersonStatutoryEvidenceRepository::class);
        self::assertInstanceOf(PayrollPersonStatutoryEvidenceRepository::class, $repository);
        $view = $repository->editorView($this->supplierId, $employeeId, '2026-07-31');
        self::assertIsArray($view);
        $sections = $view['sections'];
        $sections['health_minimum_reductions'][] = [
            'reason' => $reason,
            'evidence_reference' => null,
            'effective_from' => $from,
            'effective_to' => null,
        ];
        $repository->save(
            $this->supplierId,
            $employeeId,
            ['sections' => $sections],
            '2026-07-31',
            $this->actors[0],
            null,
            'health-minimum-flow-test',
        );
    }

    /**
     * @param array{employee_id:int,employment_id:int,name:string} $person
     * @return array<string,mixed>
     */
    private function runJuly(array $person, string $key): array
    {
        $this->createApprovedTimeMonth($person['employment_id'], '2026-07');
        $this->createApprovedInput($person, $this->componentId, 450_000, $key . '-base', '2026-07-01');
        $run = $this->runs->createRun(
            $this->supplierId,
            '2026-07-01',
            '2026-08-15',
            $this->officeId,
            $this->actors[0],
        );
        $locked = $this->runs->lockInputs(
            $this->supplierId,
            (int) $run['id'],
            (int) $run['row_version'],
            "{$key}-lock",
            $this->actors[0],
        );
        $calculated = $this->runs->calculate(
            $this->supplierId,
            (int) $run['id'],
            (int) $locked->run['row_version'],
            "{$key}-calculate",
            $this->actors[0],
        );
        self::assertSame([], $this->blockingValidations((int) $calculated->revision['id']));

        return $this->healthResultSnapshot((int) $calculated->revision['id']);
    }
}
