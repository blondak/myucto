<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Repository\Payroll\PayrollComponentJmhzMappingRepository;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Tests\Support\PayrollFullFlowTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Zvláštní případy měsíčního hlášení JMHZ cestou účetní, od založení vztahu
 * po testovací sestavení XML s XSD a katalogem kontrol: člen orgánu bez
 * pracovní doby, měsíc s porodem a neplaceným volnem, čerpání náhradního
 * volna za přesčas.
 *
 * Docházka se schvaluje s návrhy náhledu tak, jak je dialog předvyplní;
 * kde se tok zastaví, test vypíše krok a důvod.
 */
#[Group('integration')]
#[Group('payroll-full-flow')]
final class PayrollJmhzSpecialCasesFlowTest extends TestCase
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
        $this->officeId = $this->createOffice('JMHZ', 'Syntetická registrace JMHZ', '9990001234');
        $this->configureSocialInsuranceOutput($this->officeId);
        $this->configureHealthInsuranceOutput();
        $this->baseComponentId = $this->createComponent('MZDA_MESICNI_FLOW', 'base_wage', 'regular');
        $mappings = $this->container->get(PayrollComponentJmhzMappingRepository::class);
        if (!$mappings instanceof PayrollComponentJmhzMappingRepository) {
            throw new \RuntimeException('Mapování mzdových složek JMHZ není dostupné.');
        }
        $mappings->put($this->supplierId, $this->baseComponentId, '10329', null, $this->actors[0]);
    }

    protected function tearDown(): void
    {
        $this->tearDownPayrollFullFlow();
    }

    /**
     * Člen představenstva (druh činnosti Q) bez sjednané pracovní doby.
     *
     * Formulář `cinnostKS` (scénář 3). Pokyny MPSV k 10259 i 10260: „V případě
     * zaměstnanců s druhem činnosti K–S … se uvede nulová hodnota." Dialog
     * docházky navrhoval stanovený fond plného úvazku (184 h v červenci).
     */
    public function testBoardMemberHasNoWorkingTimeFund(): void
    {
        $person = $this->hire(
            'Radim Představenstvo',
            'male',
            '1975-05-15',
            activityCode: 'Q',
            employmentType: 'statutory_body',
            relationType: 'statutory_body',
            taxpayerType: 'managing_partner',
        );
        $preview = $this->timeMonthPreview($person['employment_id'], self::PERIOD);
        self::assertSame('0', $preview['suggestions']['standard_fund_hours']);
        self::assertSame('0', $preview['suggestions']['agreed_fund_hours']);
        $this->approveMonth($person['employment_id'], []);
        $this->pay($person, 3_000_000);

        $xml = $this->submission('board-member');

        self::assertStringContainsString('<form:cinnostKS ', $xml);
        self::assertStringContainsString(
            '<form:fondPracovniDoby><form:stanovenyFond>0.000</form:stanovenyFond>'
                . '<form:sjednanyFond>0.000</form:sjednanyFond>'
                . '<form:stanovenaTydenniDoba>99.00</form:stanovenaTydenniDoba></form:fondPracovniDoby>',
            $xml,
        );
    }

    /**
     * Osoba s úplnou evidencí pro JMHZ, zveřejněnými směnami na pracovní dny
     * měsíce a schváleným průměrem za 3. čtvrtletí.
     *
     * @return array{employee_id:int,employment_id:int,name:string,average_id:?int,sequence:int}
     */
    private function hire(
        string $name,
        string $sex,
        string $birthDate,
        ?string $activityCode = null,
        string $employmentType = 'hpp',
        string $relationType = 'employment',
        int $weeklyHours = 40,
        int $workload = 10_000,
        string $taxpayerType = 'employee',
        string $healthTopUpResponsibility = 'employer_obstacle_verified',
    ): array {
        $sequence = ++$this->sequence;
        $person = $this->createEmployment(
            $this->officeId,
            $name,
            $sequence,
            $employmentType,
            $relationType,
            $weeklyHours,
            $workload,
            true,
            self::PERIOD_START,
            taxpayerType: $taxpayerType,
            healthTopUpResponsibility: $healthTopUpResponsibility,
        );
        [$firstName, $lastName] = explode(' ', $name, 2);
        $this->completeJmhzEmployment($person, $activityCode, [
            'first_name' => $firstName,
            'last_name' => $lastName,
            'birth_date' => $birthDate,
            'sex' => $sex,
            'birth_number' => self::syntheticBirthNumber($birthDate, $sex, $sequence),
        ]);
        $this->assignJmhzIdentity($person, self::syntheticOic($sequence), sprintf('2%020d', $sequence));
        $this->publishShifts($person['employment_id'], self::workdays(self::PERIOD));
        $average = $this->createApprovedAverage($person['employment_id'], 3);

        return $person + ['average_id' => (int) $average['id'], 'sequence' => $sequence];
    }

    /**
     * @param list<string> $workedDates
     * @param array<string,mixed> $overrides
     */
    private function approveMonth(
        int $employmentId,
        array $workedDates,
        array $overrides = [],
        string $period = self::PERIOD,
    ): void {
        $response = $this->approveTimeMonth($employmentId, $period, $workedDates, $overrides);
        self::assertSame(
            200,
            $response->getStatusCode(),
            'Zaseknutí: schválení docházky. ' . (string) $response->getBody(),
        );
    }

    /** @param array{employee_id:int,employment_id:int,name:string} $person */
    private function pay(array $person, int $amountMinor, string $periodStart = self::PERIOD_START): void
    {
        $this->createApprovedInput(
            $person,
            $this->baseComponentId,
            $amountMinor,
            'base-' . $person['employment_id'] . '-' . $periodStart,
            $periodStart,
        );
    }

    /**
     * Běh, příprava a dry-run. Vrací XML; při zastavení selže s krokem a
     * důvodem.
     */
    private function submission(
        string $scenario,
        string $periodStart = self::PERIOD_START,
        string $payday = self::PAYDAY,
    ): string {
        $run = $this->runPayrollMonth($periodStart, $payday, $this->officeId, "special-{$scenario}");
        self::assertSame(
            [],
            $run['blockers'],
            'Zaseknutí: výpočet mzdy. ' . CanonicalJson::encode($run['blockers']),
        );
        self::assertSame(
            [],
            $run['warnings'],
            'Zaseknutí: varování před schválením běhu. ' . CanonicalJson::encode($run['warnings']),
        );
        self::assertNotNull($run['approved']);
        $revisionId = (int) $run['approved']->revision['id'];

        $preparation = $this->prepareJmhz($revisionId, "special-{$scenario}");
        self::assertSame(201, $preparation['status'], 'Zaseknutí: příprava hlášení. ' . CanonicalJson::encode($preparation['body']));
        self::assertSame(
            'source_ready',
            $preparation['body']['readiness_status'],
            'Zaseknutí: příprava hlášení. ' . CanonicalJson::encode($preparation['body']['issues'] ?? []),
        );

        $tested = $this->dryRunJmhz((int) $preparation['body']['id'], $this->officeId);
        self::assertSame(200, $tested['status'], 'Zaseknutí: sestavení XML. ' . CanonicalJson::encode($tested['body']));
        self::assertSame(
            'dry_run_valid',
            $tested['body']['status'],
            'Zaseknutí: XSD nebo kontroly. ' . CanonicalJson::encode($tested['body']['controls'] ?? $tested['body']),
        );
        self::assertTrue(
            $tested['body']['controls']['submittable'],
            'Zaseknutí: kontroly katalogu. ' . CanonicalJson::encode($tested['body']['controls']),
        );

        return (string) preg_replace('/>\s+</', '><', (string) ($tested['body']['xml'] ?? ''));
    }
}
