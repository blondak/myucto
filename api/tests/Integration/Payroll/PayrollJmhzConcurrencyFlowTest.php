<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Repository\Payroll\PayrollComponentJmhzMappingRepository;
use MyInvoice\Service\Payroll\Insurance\PayrollInsuranceBreakdownQueryService;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Tests\Support\PayrollFullFlowTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Souběh účastných vztahů, riziková práce, dočasné přidělení a odložený příjem
 * v měsíčním hlášení JMHZ — celou cestou účetní od evidence přes běh až po
 * testovací sestavení XML s XSD a kontrolami katalogu.
 */
#[Group('integration')]
#[Group('payroll-full-flow')]
final class PayrollJmhzConcurrencyFlowTest extends TestCase
{
    use PayrollFullFlowTrait;

    private const PERIOD = '2026-07';
    private const PERIOD_START = '2026-07-01';
    private const PAYDAY = '2026-08-14';

    private int $officeId;
    private int $baseComponentId;
    private int $sequence = 0;
    private int $lastRevisionId = 0;

    protected function setUp(): void
    {
        $this->bootPayrollFullFlow();
        $this->officeId = $this->createOffice('SOUB', 'Syntetická registrace souběhu', '9990004321');
        $this->configureSocialInsuranceOutput($this->officeId);
        $this->configureHealthInsuranceOutput();
        $this->baseComponentId = $this->createComponent('MZDA_SOUBEH_FLOW', 'base_wage', 'regular');
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
     * Pracovní poměr a DPČ nad rozhodným příjmem téže osoby: oba vztahy jsou
     * účastné. Kontrola 118 chce na KAŽDÉM formuláři 10370 = 7,1 % z 10477
     * zaokrouhleno nahoru, kontrola 12 součet 10370 rovný pojistnému za
     * zaměstnance v pojistné části. Obojí sedí jen tehdy, když se pojistné
     * zaokrouhluje po vztazích.
     */
    public function testTwoParticipatingRelationshipsReportContributionsPerRelationship(): void
    {
        $person = $this->hire('Filip Souběžný', 'male', '1979-09-30');
        $agreement = $this->hireAgreement($person, 'dpc');
        $this->approveMonth($person['employment_id'], self::workdays(self::PERIOD));
        $this->approveMonth($agreement['employment_id'], ['2026-07-04', '2026-07-11'], dailyMinutes: 240);
        $this->pay($person, 4_000_100);
        $this->pay($agreement, 500_100);

        $xml = $this->submission('two-participating');

        self::assertSame(2, substr_count($xml, '</formularOsoby>'));
        self::assertSame(2, substr_count($xml, '<form:pojisteniZamestnanec>'));
        // 7,1 % z 40 001 Kč = 2 840,071 → 2 841; z 5 001 Kč = 355,071 → 356.
        self::assertStringContainsString('<form:castkaOdvodPojistneho>40001</form:castkaOdvodPojistneho>', $xml);
        self::assertStringContainsString('<form:castkaOdvodPojistneho>5001</form:castkaOdvodPojistneho>', $xml);
        self::assertStringContainsString(
            '<form:pojisteniZamestnanec><form:socialniPojisteni>2841</form:socialniPojisteni></form:pojisteniZamestnanec>',
            $xml,
        );
        self::assertStringContainsString(
            '<form:pojisteniZamestnanec><form:socialniPojisteni>356</form:socialniPojisteni></form:pojisteniZamestnanec>',
            $xml,
        );
        // 24,8 % z 40 001 Kč = 9 920,248 → 9 921; z 5 001 Kč = 1 240,248 → 1 241.
        self::assertStringContainsString(
            '<form:pojisteniZamestnavatel><form:socialniPojisteni>9921</form:socialniPojisteni></form:pojisteniZamestnavatel>',
            $xml,
        );
        self::assertStringContainsString(
            '<form:pojisteniZamestnavatel><form:socialniPojisteni>1241</form:socialniPojisteni></form:pojisteniZamestnavatel>',
            $xml,
        );
        // Kontrola 12: pojistné za zaměstnance = součet 10370 formulářů.
        self::assertStringContainsString('<pvpoj:pojistneZamestnance>3197</pvpoj:pojistneZamestnance>', $xml);

        // Rozklad pojistného na kartě osoby musí dát tutéž částku po vztazích.
        $breakdown = $this->container->get(PayrollInsuranceBreakdownQueryService::class);
        self::assertInstanceOf(PayrollInsuranceBreakdownQueryService::class, $breakdown);
        $social = $breakdown->breakdown($this->supplierId, $this->lastRevisionId, $person['employee_id'])['social'];
        self::assertSame(319_700, $social['employee']['before_discount_minor']);
        self::assertNull($social['employee']['contribution_step']);
        self::assertSame(
            [284_100, 35_600],
            array_map(
                static fn (array $row): int => $row['before_discount_minor'],
                $social['employee']['relationships'],
            ),
        );
    }

    /**
     * Osoba s úplnou evidencí pro JMHZ, zveřejněnými směnami na pracovní dny
     * měsíce a schváleným průměrem za 3. čtvrtletí.
     *
     * @return array{employee_id:int,employment_id:int,name:string,average_id:?int}
     */
    private function hire(
        string $name,
        string $sex,
        string $birthDate,
        string $employmentType = 'hpp',
        string $relationType = 'employment',
        int $weeklyHours = 40,
        int $workload = 10_000,
        bool $taxDeclarationSigned = true,
        string $socialDiscountStatus = 'not_claimed',
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
            $taxDeclarationSigned,
            self::PERIOD_START,
            socialDiscountStatus: $socialDiscountStatus,
        );
        [$firstName, $lastName] = explode(' ', $name, 2);
        $this->completeJmhzEmployment($person, identity: [
            'first_name' => $firstName,
            'last_name' => $lastName,
            'birth_date' => $birthDate,
            'sex' => $sex,
            'birth_number' => self::syntheticBirthNumber($birthDate, $sex, $sequence),
        ]);
        $this->assignJmhzIdentity($person, self::syntheticOic($sequence), self::syntheticPpv($sequence));
        $this->publishShifts($person['employment_id'], self::workdays(self::PERIOD));
        $average = $this->createApprovedAverage($person['employment_id'], 3);

        return $person + ['average_id' => (int) $average['id']];
    }

    /**
     * Další vztah téže osoby (souběh).
     *
     * @param array{employee_id:int,employment_id:int,name:string} $person
     * @return array{employee_id:int,employment_id:int,name:string}
     */
    private function hireAgreement(array $person, string $relationType): array
    {
        $sequence = ++$this->sequence;
        $agreement = $this->createEmployment(
            $this->officeId,
            $person['name'],
            $sequence,
            $relationType,
            $relationType,
            10,
            2_500,
            true,
            self::PERIOD_START,
            existingEmployeeId: $person['employee_id'],
        );
        $this->completeJmhzEmployment($agreement, withIdentity: false);
        $this->assignJmhzIdentity($agreement, null, self::syntheticPpv($sequence));
        $this->createApprovedAverage($agreement['employment_id'], 3);

        return $agreement;
    }

    private static function syntheticPpv(int $sequence): string
    {
        return sprintf('3%020d', $sequence);
    }

    /** @param list<string> $workedDates */
    private function approveMonth(int $employmentId, array $workedDates, int $dailyMinutes = 480): void
    {
        $response = $this->approveTimeMonth($employmentId, self::PERIOD, $workedDates, dailyMinutes: $dailyMinutes);
        self::assertSame(
            200,
            $response->getStatusCode(),
            'Zaseknutí: schválení docházky. ' . (string) $response->getBody(),
        );
    }

    /** @param array{employee_id:int,employment_id:int,name:string} $person */
    private function pay(array $person, int $amountMinor): void
    {
        $this->createApprovedInput(
            $person,
            $this->baseComponentId,
            $amountMinor,
            'base-' . $person['employment_id'],
            self::PERIOD_START,
        );
    }

    /**
     * Běh, příprava a dry-run. Vrací XML; při zastavení selže s krokem a
     * důvodem.
     */
    private function submission(string $scenario): string
    {
        $run = $this->runPayrollMonth(self::PERIOD_START, self::PAYDAY, $this->officeId, "concurrency-{$scenario}");
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
        $this->lastRevisionId = $revisionId;

        $preparation = $this->prepareJmhz($revisionId, "concurrency-{$scenario}");
        self::assertSame(201, $preparation['status'], 'Zaseknutí: příprava hlášení. ' . CanonicalJson::encode($preparation['body']));
        self::assertSame(
            'source_ready',
            $preparation['body']['readiness_status'],
            'Zaseknutí: příprava hlášení. ' . CanonicalJson::encode($preparation['body']['issues'] ?? []),
        );

        $tested = $this->dryRunJmhz((int) $preparation['body']['id'], $this->officeId);
        self::assertSame(200, $tested['status'], 'Zaseknutí: sestavení XML. ' . CanonicalJson::encode($tested['body']));
        $xml = (string) ($tested['body']['xml'] ?? '');
        self::assertSame(
            'dry_run_valid',
            $tested['body']['status'],
            'Zaseknutí: XSD nebo kontroly. ' . CanonicalJson::encode($tested['body']['controls'] ?? $tested['body']),
        );
        self::assertTrue(
            $tested['body']['controls']['submittable'],
            'Zaseknutí: kontroly katalogu. ' . CanonicalJson::encode($tested['body']['controls']),
        );

        return (string) preg_replace('/>\s+</', '><', $xml);
    }
}
