<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Repository\Payroll\PayrollComponentJmhzMappingRepository;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Submission\Eldp\EldpStatementService;
use MyInvoice\Tests\Support\PayrollFullFlowTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Evidenční list cestou účetní: založení osoby s hlavním pracovním poměrem
 * a dohodou o pracovní činnosti, docházka, mzdový běh, schválení a příprava
 * evidenčního listu na výzvu ČSSZ. Dřív list pro DPČ vůbec nevznikl
 * („evidenční list zatím podporuje jen pracovní poměr").
 *
 * Všechna data jsou syntetická; transakci vrací tearDown.
 */
#[Group('integration')]
#[Group('payroll-full-flow')]
final class EldpScenarioFlowTest extends TestCase
{
    use PayrollFullFlowTrait;

    private const PERIOD = '2026-07';
    private const PERIOD_START = '2026-07-01';
    private const PAYDAY = '2026-08-14';

    private int $officeId;
    private int $baseComponentId;

    protected function setUp(): void
    {
        $this->bootPayrollFullFlow();
        $this->officeId = $this->createOffice('ELDP', 'Syntetická registrace ELDP', '9990004321');
        $this->configureSocialInsuranceOutput($this->officeId);
        $this->configureHealthInsuranceOutput();
        $this->baseComponentId = $this->createComponent('MZDA_MESICNI_ELDP', 'base_wage', 'regular');
        $mappings = $this->container->get(PayrollComponentJmhzMappingRepository::class);
        self::assertInstanceOf(PayrollComponentJmhzMappingRepository::class, $mappings);
        $mappings->put($this->supplierId, $this->baseComponentId, '10329', null, $this->actors[0]);
    }

    protected function tearDown(): void
    {
        $this->tearDownPayrollFullFlow();
    }

    public function testAgreementToCompleteAJobReachesAStatementOnAuthorityRequest(): void
    {
        $person = $this->createEmployment(
            $this->officeId,
            'Olga Dohodová',
            1,
            'hpp',
            'employment',
            40,
            10_000,
            true,
            self::PERIOD_START,
        );
        $this->completeJmhzEmployment($person, identity: [
            'first_name' => 'Olga',
            'last_name' => 'Dohodová',
            'birth_date' => '1985-03-14',
            'sex' => 'female',
            'birth_number' => self::syntheticBirthNumber('1985-03-14', 'female', 1),
        ]);
        $this->assignJmhzIdentity($person, self::syntheticOic(1), sprintf('2%020d', 1));
        $this->publishShifts($person['employment_id'], self::workdays(self::PERIOD));
        $this->createApprovedAverage($person['employment_id'], 3);

        $agreement = $this->createEmployment(
            $this->officeId,
            $person['name'],
            2,
            'dpc',
            'dpc',
            10,
            2_500,
            true,
            self::PERIOD_START,
            existingEmployeeId: $person['employee_id'],
        );
        $this->completeJmhzEmployment($agreement, withIdentity: false);
        $this->assignJmhzIdentity($agreement, null, sprintf('2%020d', 2));
        $this->createApprovedAverage($agreement['employment_id'], 3);
        // Sdílený tok zakládá vztahy od 1. 1.; evidenční list na výzvu pokrývá
        // jen měsíce se schválenou mzdou, takže vztahy začínají až červencem.
        $this->startOn($person['employment_id']);
        $this->startOn($agreement['employment_id']);

        foreach ([
            [$person, self::workdays(self::PERIOD), 480, 4_000_000],
            [$agreement, ['2026-07-04', '2026-07-11', '2026-07-18'], 240, 1_000_000],
        ] as [$relation, $days, $minutes, $amount]) {
            $response = $this->approveTimeMonth($relation['employment_id'], self::PERIOD, $days, dailyMinutes: $minutes);
            self::assertSame(200, $response->getStatusCode(), 'Zaseknutí: schválení docházky. ' . (string) $response->getBody());
            $this->createApprovedInput($relation, $this->baseComponentId, $amount, 'base-' . $relation['employment_id'], self::PERIOD_START);
        }

        $run = $this->runPayrollMonth(self::PERIOD_START, self::PAYDAY, $this->officeId, 'eldp-flow');
        self::assertSame([], $run['blockers'], 'Zaseknutí: výpočet mzdy. ' . CanonicalJson::encode($run['blockers']));
        self::assertSame([], $run['warnings'], 'Zaseknutí: varování. ' . CanonicalJson::encode($run['warnings']));
        self::assertNotNull($run['approved']);

        $service = $this->container->get(EldpStatementService::class);
        self::assertInstanceOf(EldpStatementService::class, $service);
        $prepared = $service->prepare(
            $this->supplierId,
            $agreement['employment_id'],
            2026,
            'test',
            [
                'excluded_days_confirmed' => true,
                'deducted_days_none' => true,
                'requested_by_authority' => true,
                'authority_request_received_on' => '2026-08-20',
                'note' => 'Syntetická výzva ČSSZ.',
            ],
            'eldp-flow-dpc',
            $this->actors[0],
        );

        self::assertTrue($prepared['created']);
        self::assertSame('01', $prepared['eldp_type']);
        self::assertSame(31, $prepared['insurance_days']);

        $statement = $service->statement($this->supplierId, 'test', $agreement['employment_id'], 2026);
        self::assertIsArray($statement);
        $section = $statement['payload']['eldp_sections'][0];
        self::assertSame('A++', $section['code']);
        self::assertSame('2026-07-01', $section['valid_from']);
        self::assertSame('2026-07-31', $section['valid_to']);
        self::assertSame(10_000, $section['assessment_base_czk']);
        self::assertSame('2026-07-01', $statement['payload']['form']['employed_from']);
        self::assertSame('2026-07-31', $statement['payload']['form']['prepared_on']);
    }

    /** Vztah i jeho podmínky začínají až vykazovaným měsícem. */
    private function startOn(int $employmentId): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'UPDATE payroll_employments SET start_date = ?, actual_start_date = ?
              WHERE supplier_id = ? AND id = ?',
        )->execute([self::PERIOD_START, self::PERIOD_START, $this->supplierId, $employmentId]);
        $pdo->prepare(
            'UPDATE payroll_employment_terms
                SET effective_from = ?, planned_start_on = ?, actual_start_on = ?
              WHERE supplier_id = ? AND employment_id = ?',
        )->execute([
            self::PERIOD_START,
            self::PERIOD_START,
            self::PERIOD_START,
            $this->supplierId,
            $employmentId,
        ]);
    }
}
