<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Repository\Payroll\PayrollComponentJmhzMappingRepository;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Tests\Support\PayrollFullFlowTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Datové scénáře JMHZ 4 až 6 od založení vztahu po testovací sestavení XML.
 *
 * Datové scénáře, interakce a povinnosti MH 1.4.0.2, list „Datové scénáře",
 * řádky 12 až 14, a kontrola 343 katalogu 1.4.2.10:
 * - scénář 4: druh činnosti 1 až 9 s bližším určením 10502 = 2 (výkon trestu
 *   odnětí svobody nebo zabezpečovací detence) → `formVezen.xsd`,
 * - scénář 5: druh činnosti 11, 13, 14 (náhrada od pojišťovny, jiný příjem ze
 *   závislé činnosti bez jejího výkonu, neuvolněný zastupitel) → `formJinyPrijem.xsd`,
 * - scénář 6: druh činnosti 12 (mezinárodní pronájem pracovní síly) →
 *   `formMezinarodniPronajemSily.xsd`.
 *
 * Každý scénář jde cestou účetní (evidence, docházka, běh, příprava, dry-run
 * s XSD a katalogem kontrol). Na zastavení test vypíše krok a důvod.
 */
#[Group('integration')]
#[Group('payroll-full-flow')]
final class PayrollJmhzSpecialFormsFlowTest extends TestCase
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
     * Scénář 4: odsouzený zařazený do práce je zaměstnanec pro pojistné
     * (§ 5 odst. 1 písm. a) bod 11 ZPSZ) a vykazuje se formulářem `vezen`.
     * Formulář nemá zdravotní pojištění, vyměřovací základy § 5a, pozici ani
     * rozpad mzdy; nese souhrn s čistou mzdou, trvání pojištění, ELDP, slevu
     * zaměstnance, odpracované hodiny, daňový základ a náhradu za DPN.
     */
    public function testPrisonerIsReportedOnVezenForm(): void
    {
        $person = $this->hire('Petr Odsouzený', 'male', '1990-03-15');
        $this->classify($person['employment_id'], '1', '2');
        $this->approveMonth($person['employment_id'], self::workdays(self::PERIOD));
        $this->pay($person, 2_000_000);

        $xml = $this->submission('vezen');

        self::assertSame(1, substr_count($xml, '</formularOsoby>'));
        self::assertStringContainsString('<form:vezen>', $xml);
        self::assertStringNotContainsString('<form:bezPriznaku>', $xml);
        self::assertStringNotContainsString('zdravPoj', $xml);
        self::assertStringNotContainsString('<form:vykonavanaPozice>', $xml);
        self::assertStringNotContainsString('<form:vymerovaciZaklad><form:castkaOdvodPojistneho>', $xml);
        self::assertStringNotContainsString('<form:slevaZamestnavatele>', $xml);
        self::assertStringContainsString('<form:mzdaCista><form:mzdaCista>', $xml);
        self::assertStringContainsString('<form:kod>1++</form:kod>', $xml);
        self::assertStringContainsString('<form:vymerovaciZaklad>20000</form:vymerovaciZaklad>', $xml);
        self::assertStringContainsString(
            '<form:prubehZamestnani><form:odpracovaneHodiny><form:pocet>176.000</form:pocet><form:rozpad><form:prescas>0.000</form:prescas></form:rozpad></form:odpracovaneHodiny></form:prubehZamestnani>',
            $xml,
        );
        self::assertStringContainsString('<form:prijem><form:dan><form:zakladDane>20000</form:zakladDane></form:dan></form:prijem>', $xml);
        self::assertStringContainsString('<form:mzda/>', $xml);
    }

    /**
     * Scénář 5: druh činnosti 13 - příjem ze závislé činnosti vyplácený
     * plátcem, u kterého se činnost nevykonává. Neuvolněný zastupitel (14)
     * i náhrada od pojišťovny (11) mají týž formulář. Pojištění formulář
     * nemá; vztah je proto bez účasti na sociálním pojištění.
     */
    public function testOtherIncomeIsReportedOnJinyPrijemForm(): void
    {
        $person = $this->hire('Olga Provize', 'female', '1982-11-02');
        $this->classify($person['employment_id'], '13', '1');
        $this->approveMonth($person['employment_id'], []);
        $this->pay($person, 800_000);

        $xml = $this->submission('jiny-prijem');

        self::assertSame(1, substr_count($xml, '</formularOsoby>'));
        self::assertStringContainsString('<form:jinyPrijem><form:identifikace>', $xml);
        self::assertStringContainsString('<form:zuctovanoCelkem>8000</form:zuctovanoCelkem>', $xml);
        self::assertStringNotContainsString('<form:pojisteni>', $xml);
        self::assertStringNotContainsString('<form:mzdaCista>', $xml);
        self::assertStringNotContainsString('zdravPoj', $xml);
        self::assertStringContainsString('<form:prijem><form:dan><form:zakladDane>8000</form:zakladDane></form:dan></form:prijem>', $xml);
    }

    /**
     * Formulář jiného příjmu vykonávanou pozici nemá (matice scénáře 5 nevede
     * místo výkonu práce 10229 až 10231): neuvolněný zastupitel nebo provize
     * od cizího plátce pracoviště nemají a hlášení na něm stát nesmí.
     */
    public function testOtherIncomeDoesNotNeedAWorkplace(): void
    {
        $person = $this->hire('Kamil Zastupitel', 'male', '1970-09-09');
        $this->classify($person['employment_id'], '14', '1');
        $this->db->pdo()->prepare(
            'UPDATE payroll_employment_terms
                SET work_place = NULL, jmhz_workplace_municipality_code = NULL,
                    jmhz_workplace_country_code = NULL,
                    jmhz_external_codebook_overlay_key = NULL,
                    jmhz_external_codebook_manifest_sha256 = NULL
              WHERE supplier_id = ? AND employment_id = ?',
        )->execute([$this->supplierId, $person['employment_id']]);
        $this->approveMonth($person['employment_id'], []);
        $this->pay($person, 250_000);

        $xml = $this->submission('jiny-prijem-bez-pracoviste');

        self::assertStringContainsString('<form:jinyPrijem><form:identifikace>', $xml);
        self::assertStringNotContainsString('<form:vykonavanaPozice>', $xml);
    }

    /**
     * Scénář 6: druh činnosti 12 - mezinárodní pronájem pracovní síly. Formulář
     * nese jen identifikaci, zúžený souhrn daně a daňový základ.
     */
    public function testInternationalHireIsReportedOnMezinarodniPronajemSilyForm(): void
    {
        $person = $this->hire('Jan Pronajatý', 'male', '1975-06-20');
        $this->classify($person['employment_id'], '12', '1');
        $this->approveMonth($person['employment_id'], self::workdays(self::PERIOD));
        $this->pay($person, 5_000_000);

        $xml = $this->submission('pronajem');

        self::assertSame(1, substr_count($xml, '</formularOsoby>'));
        self::assertStringContainsString('<form:mezinarodniPronajemSily><form:identifikace>', $xml);
        self::assertStringContainsString('<form:prijmy><form:zuctovanoCelkem>50000</form:zuctovanoCelkem></form:prijmy>', $xml);
        self::assertStringNotContainsString('<form:danBonus>', $xml);
        self::assertStringNotContainsString('<form:pojisteni>', $xml);
        self::assertStringContainsString('<form:zakladniSleva>', $xml);
    }

    private function classify(
        int $employmentId,
        string $activityCode,
        string $detailCode,
        string $social = 'automatic',
        string $health = 'automatic',
    ): void {
        $this->db->pdo()->prepare(
            'UPDATE payroll_employment_terms
                SET activity_code = ?, jmhz_relationship_detail_code = ?,
                    social_insurance_participation = ?, health_insurance_participation = ?
              WHERE supplier_id = ? AND employment_id = ?',
        )->execute([$activityCode, $detailCode, $social, $health, $this->supplierId, $employmentId]);
    }

    /**
     * @return array{employee_id:int,employment_id:int,name:string,average_id:?int,sequence:int}
     */
    private function hire(string $name, string $sex, string $birthDate): array
    {
        $sequence = ++$this->sequence;
        $person = $this->createEmployment(
            $this->officeId,
            $name,
            $sequence,
            'hpp',
            'employment',
            40,
            10_000,
            true,
            self::PERIOD_START,
        );
        [$firstName, $lastName] = explode(' ', $name, 2);
        $this->completeJmhzEmployment($person, identity: [
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

    /** @param list<string> $workedDates */
    private function approveMonth(int $employmentId, array $workedDates): void
    {
        $response = $this->approveTimeMonth($employmentId, self::PERIOD, $workedDates);
        self::assertSame(200, $response->getStatusCode(), 'Zaseknutí: schválení docházky. ' . (string) $response->getBody());
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

    private function submission(string $scenario): string
    {
        $run = $this->runPayrollMonth(self::PERIOD_START, self::PAYDAY, $this->officeId, "scenario-{$scenario}");
        self::assertSame([], $run['blockers'], 'Zaseknutí: výpočet mzdy. ' . CanonicalJson::encode($run['blockers']));
        self::assertSame([], $run['warnings'], 'Zaseknutí: varování běhu. ' . CanonicalJson::encode($run['warnings']));
        self::assertNotNull($run['approved']);
        $preparation = $this->prepareJmhz((int) $run['approved']->revision['id'], "scenario-{$scenario}");
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

        $this->dump($scenario, (string) ($tested['body']['xml'] ?? ''));
        $xml = (string) preg_replace('/>\s+</', '><', (string) ($tested['body']['xml'] ?? ''));

        // Libxml opakuje deklaraci prefixu `form` u každé součásti; pro
        // porovnání tvaru je šum.
        return (string) preg_replace('/ xmlns:form="[^"]+"/', '', $xml);
    }

    /** S proměnnou MYUCTO_JMHZ_SCENARIO_DUMP (adresář) uloží XML pro soukromé porovnání. */
    private function dump(string $scenario, string $xml): void
    {
        $directory = getenv('MYUCTO_JMHZ_SCENARIO_DUMP');
        if (!is_string($directory) || $directory === '' || $xml === '') {
            return;
        }
        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }
        file_put_contents($directory . DIRECTORY_SEPARATOR . "{$scenario}.xml", $xml);
    }
}
