<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Repository\Payroll\PayrollComponentJmhzMappingRepository;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Tests\Support\PayrollFullFlowTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Vyloučená doba podle § 16 odst. 4 písm. j) zákona č. 155/1995 Sb. (10536):
 * vztah podle pravomocného rozhodnutí soudu trval po neplatném skončení a
 * náhrada mzdy za tu dobu přiznána nebyla (datový slovník JMHZ 1.4.1.6;
 * Pokyny k vyplnění MH 1.4.14, kap. 3.6.3).
 *
 * Dřív aplikace pro tuhle dobu neměla vstup a 10536 vždy vykázala nulou.
 * Zapisuje se jako nepřítomnost `invalid_termination`.
 */
#[Group('integration')]
#[Group('payroll-full-flow')]
final class PayrollJmhzInvalidTerminationFlowTest extends TestCase
{
    use PayrollFullFlowTrait;

    private const PERIOD = '2026-07';
    private const PERIOD_START = '2026-07-01';
    private const PAYDAY = '2026-08-14';

    private int $officeId;
    private int $baseComponentId;
    private int $sequence = 0;

    protected function setUp(): void
    {
        $this->bootPayrollFullFlow();
        $this->officeId = $this->createOffice('JMHZJ', 'Syntetická registrace JMHZ', '9990005678');
        $this->configureSocialInsuranceOutput($this->officeId);
        $this->configureHealthInsuranceOutput();
        $this->baseComponentId = $this->createComponent('MZDA_NEPLATNE_FLOW', 'base_wage', 'regular');
        $mappings = $this->container->get(PayrollComponentJmhzMappingRepository::class);
        self::assertInstanceOf(PayrollComponentJmhzMappingRepository::class, $mappings);
        $mappings->put($this->supplierId, $this->baseComponentId, '10329', null, $this->actors[0]);
    }

    protected function tearDown(): void
    {
        $this->tearDownPayrollFullFlow();
    }

    /**
     * Jednatel (formulář `cinnostKS`): vztah trval po neplatném odvolání
     * 1.–15. 7., od 16. 7. vykonává funkci za odměnu. Člen orgánu nemá
     * pracovní dobu, takže doba nemá ani žádné neodpracované hodiny.
     */
    public function testStatutoryBodyReportsSection16jDaysOnCorporateBodyForm(): void
    {
        $person = $this->hire(
            'Jan Odvolaný',
            'male',
            '1974-04-04',
            activityCode: 'S',
            employmentType: 'statutory_body',
            relationType: 'statutory_body',
            taxpayerType: 'managing_partner',
            withShifts: false,
        );
        $this->createApprovedAbsence($person['employment_id'], 'invalid_termination', '2026-07-01', '2026-07-15');
        $this->approveMonth($person['employment_id'], []);
        $this->pay($person, 1_500_000);

        $xml = $this->submission('statutory-body');

        self::assertStringContainsString('<form:cinnostKS ', $xml);
        self::assertStringContainsString('<form:vylouceneDobyCelkem>15</form:vylouceneDobyCelkem>', $xml);
        self::assertStringContainsString('<form:vyloucenePar16>15</form:vyloucenePar16>', $xml);
        self::assertStringContainsString('<form:pocetDnu>31</form:pocetDnu>', $xml);
    }

    /**
     * Pracovní poměr: soud určil, že výpověď byla neplatná a poměr trval
     * 1.–10. 7. bez náhrady mzdy; od 13. 7. zaměstnanec znovu pracuje.
     * Směny té doby jsou neodpracované hodiny bez náhrady (10275).
     */
    public function testEmploymentReportsSection16jDaysWithUnpaidHours(): void
    {
        $person = $this->hire('Eva Obnovená', 'female', '1987-07-07');
        $this->createApprovedAbsence($person['employment_id'], 'invalid_termination', '2026-07-01', '2026-07-10');
        $worked = self::workdays(self::PERIOD, self::workdaysBetween('2026-07-01', '2026-07-10'));
        $this->approveMonth($person['employment_id'], $worked);
        $this->pay($person, 2_800_000);

        $xml = $this->submission('employment');

        self::assertStringContainsString('<form:bezPriznaku ', $xml);
        self::assertStringContainsString('<form:vylouceneDobyCelkem>10</form:vylouceneDobyCelkem>', $xml);
        self::assertStringContainsString('<form:vyloucenePar16>10</form:vyloucenePar16>', $xml);
        self::assertStringContainsString('<form:pocetDnu>31</form:pocetDnu>', $xml);
    }

    /** @return list<string> */
    private static function workdaysBetween(string $from, string $to): array
    {
        return array_values(array_filter(
            self::workdays(self::PERIOD),
            static fn (string $day): bool => $day >= $from && $day <= $to,
        ));
    }

    /** @return array{employee_id:int,employment_id:int,name:string,average_id:int,sequence:int} */
    private function hire(
        string $name,
        string $sex,
        string $birthDate,
        ?string $activityCode = null,
        string $employmentType = 'hpp',
        string $relationType = 'employment',
        string $taxpayerType = 'employee',
        bool $withShifts = true,
    ): array {
        $sequence = ++$this->sequence;
        $person = $this->createEmployment(
            $this->officeId,
            $name,
            $sequence,
            $employmentType,
            $relationType,
            40,
            10_000,
            true,
            self::PERIOD_START,
            taxpayerType: $taxpayerType,
        );
        [$firstName, $lastName] = explode(' ', $name, 2);
        $this->completeJmhzEmployment($person, $activityCode, [
            'first_name' => $firstName,
            'last_name' => $lastName,
            'birth_date' => $birthDate,
            'sex' => $sex,
            'birth_number' => self::syntheticBirthNumber($birthDate, $sex, $sequence),
        ]);
        $this->assignJmhzIdentity($person, self::syntheticOic($sequence), sprintf('4%020d', $sequence));
        if ($withShifts) {
            $this->publishShifts($person['employment_id'], self::workdays(self::PERIOD));
        }
        $average = $this->createApprovedAverage($person['employment_id'], 3);

        return $person + ['average_id' => (int) $average['id'], 'sequence' => $sequence];
    }

    /** @param list<string> $workedDates */
    private function approveMonth(int $employmentId, array $workedDates): void
    {
        $response = $this->approveTimeMonth($employmentId, self::PERIOD, $workedDates);
        self::assertSame(200, $response->getStatusCode(), 'Zaseknutí: schválení docházky. ' . (string) $response->getBody());
    }

    /** @param array{employee_id:int,employment_id:int,name:string} $person */
    private function pay(array $person, int $amountMinor): void
    {
        $this->createApprovedInput($person, $this->baseComponentId, $amountMinor, 'base-' . $person['employment_id'], self::PERIOD_START);
    }

    private function submission(string $scenario): string
    {
        $run = $this->runPayrollMonth(self::PERIOD_START, self::PAYDAY, $this->officeId, "invalid-termination-{$scenario}");
        self::assertSame([], $run['blockers'], 'Zaseknutí: výpočet mzdy. ' . CanonicalJson::encode($run['blockers']));
        self::assertSame([], $run['warnings'], 'Zaseknutí: varování běhu. ' . CanonicalJson::encode($run['warnings']));
        self::assertNotNull($run['approved']);
        $revisionId = (int) $run['approved']->revision['id'];

        $preparation = $this->prepareJmhz($revisionId, "invalid-termination-{$scenario}");
        self::assertSame(201, $preparation['status'], 'Zaseknutí: příprava. ' . CanonicalJson::encode($preparation['body']));
        self::assertSame(
            'source_ready',
            $preparation['body']['readiness_status'],
            'Zaseknutí: příprava. ' . CanonicalJson::encode($preparation['body']['issues'] ?? []),
        );
        $tested = $this->dryRunJmhz((int) $preparation['body']['id'], $this->officeId);
        self::assertSame(200, $tested['status'], CanonicalJson::encode($tested['body']));
        self::assertSame(
            'dry_run_valid',
            $tested['body']['status'],
            'Zaseknutí: XSD nebo kontroly. ' . CanonicalJson::encode($tested['body']['controls'] ?? $tested['body']),
        );

        return (string) preg_replace('/>\s+</', '><', (string) $tested['body']['xml']);
    }
}
