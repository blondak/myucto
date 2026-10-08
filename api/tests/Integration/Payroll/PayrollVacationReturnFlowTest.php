<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Repository\Payroll\PayrollComponentJmhzMappingRepository;
use MyInvoice\Repository\Payroll\PayrollComponentRepository;
use MyInvoice\Service\Payroll\Absence\VacationCompensationReturn;
use MyInvoice\Service\Payroll\Net\PayrollNetResultQueryService;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Tests\Support\PayrollFullFlowTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Vrácená náhrada za dovolenou (§ 147 odst. 1 písm. e) ZP) od vstupu po
 * měsíční hlášení.
 *
 * Zaměstnanec vyčerpal dovolenou, na kterou mu právo nevzniklo; v měsíci,
 * kdy se na to přijde, se mu náhrada za ni srazí zápornou částkou. Tak ji
 * vede vyrovnání při skončení i převod z PAMICA (J10). Dřív záporný vstup
 * zablokoval výpočet (`negative_component_requires_revision`) a běh nešel
 * spočítat vůbec.
 *
 * Mzda 45 000 Kč, vrácená náhrada −3 431 Kč: hrubá mzda 41 569 Kč.
 */
#[Group('integration')]
#[Group('payroll-full-flow')]
final class PayrollVacationReturnFlowTest extends TestCase
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
        $this->officeId = $this->createOffice('JMHZ', 'Syntetická registrace JMHZ', '1100001237');
        $this->configureSocialInsuranceOutput($this->officeId);
        $this->configureHealthInsuranceOutput();
        $this->baseComponentId = $this->createComponent('MZDA_MESICNI_VRACENI', 'base_wage', 'regular');
        $mappings = $this->container->get(PayrollComponentJmhzMappingRepository::class);
        self::assertInstanceOf(PayrollComponentJmhzMappingRepository::class, $mappings);
        $mappings->put($this->supplierId, $this->baseComponentId, '10329', null, $this->actors[0]);
        $components = $this->container->get(PayrollComponentRepository::class);
        self::assertInstanceOf(PayrollComponentRepository::class, $components);
        $components->ensureDefaults($this->supplierId);
    }

    protected function tearDown(): void
    {
        $this->tearDownPayrollFullFlow();
    }

    public function testReturnedVacationCompensationReducesGrossAndReachesJmhz(): void
    {
        $person = $this->createEmployment(
            $this->officeId,
            'Vendula Vrácená',
            1,
            'hpp',
            'employment',
            40,
            10_000,
            true,
            self::PERIOD_START,
        );
        $this->completeJmhzEmployment($person, identity: [
            'first_name' => 'Vendula',
            'last_name' => 'Vrácená',
            'birth_date' => '1990-05-20',
            'sex' => 'female',
            'birth_number' => self::syntheticBirthNumber('1990-05-20', 'female', 1),
        ]);
        $this->assignJmhzIdentity($person, self::syntheticOic(1), sprintf('2%020d', 1));
        $this->publishShifts($person['employment_id'], self::workdays(self::PERIOD));
        $this->createApprovedAverage($person['employment_id'], 3);
        $response = $this->approveTimeMonth($person['employment_id'], self::PERIOD, self::workdays(self::PERIOD));
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        $this->createApprovedInput($person, $this->baseComponentId, 4_500_000, 'base-vraceni', self::PERIOD_START);
        $this->createApprovedInput(
            $person,
            $this->componentId(VacationCompensationReturn::SETTLEMENT_CODE),
            -343_100,
            'j10-vraceni',
            self::PERIOD_START,
        );

        $run = $this->runPayrollMonth(self::PERIOD_START, self::PAYDAY, $this->officeId, 'vacation-return');
        self::assertSame([], $run['blockers'], 'Zaseknutí: výpočet mzdy. ' . CanonicalJson::encode($run['blockers']));
        self::assertNotNull($run['approved']);
        $revisionId = (int) $run['approved']->revision['id'];

        $netResults = $this->container->get(PayrollNetResultQueryService::class);
        self::assertInstanceOf(PayrollNetResultQueryService::class, $netResults);
        $breakdown = $netResults->breakdown($this->supplierId, $revisionId, $person['employee_id']);
        self::assertSame(4_156_900, $breakdown['income']['gross_minor']);

        $preparation = $this->prepareJmhz($revisionId, 'vacation-return');
        self::assertSame(201, $preparation['status'], CanonicalJson::encode($preparation['body']));
        self::assertSame('source_ready', $preparation['body']['readiness_status'], CanonicalJson::encode($preparation['body']['issues'] ?? []));
        $tested = $this->dryRunJmhz((int) $preparation['body']['id'], $this->officeId);
        self::assertSame('dry_run_valid', $tested['body']['status'], CanonicalJson::encode($tested['body']['controls'] ?? $tested['body']));
        // Záporný součet náhrad se hlásí nulou (celé nezáporné číslo), úhrn
        // zúčtovaného příjmu už vrácení nese.
        $xml = (string) preg_replace('/>\s+</', '><', (string) $tested['body']['xml']);
        self::assertStringContainsString('<form:mzdyZuctovane>0</form:mzdyZuctovane><form:dovolena>0</form:dovolena>', $xml);
    }
}
