<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Repository\Payroll\PayrollInstitutionAccountRepository;
use MyInvoice\Service\Payroll\Deadline\PayrollDeadlineOverviewService;
use MyInvoice\Service\Payroll\Submission\HealthInsurance\HealthInsuranceSubmissionService;
use MyInvoice\Service\Payroll\Submission\Sickness\SicknessCaseService;
use MyInvoice\Service\Payroll\Submission\Sickness\SicknessDocumentKind;
use MyInvoice\Service\Payroll\Submission\Sickness\SicknessException;
use MyInvoice\Service\Payroll\Submission\Sickness\SicknessSubmissionService;
use MyInvoice\Tests\Support\PayrollFullFlowTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Response;

/**
 * Nemocenské dávky cestou účetní, od absence po datovou větu:
 *
 * 1. schválená neschopnost založí případ NEMPRI s lhůtou, navazující absence
 *    ho prodlouží, hlídač termínů ukáže NEMPRI i HZUPN (od dne nástupu),
 * 2. neschopnost po skončení zaměstnání projde jen v ochranné lhůtě § 15,
 * 3. ošetřovné ze schváleného OČR nese vztah z číselníku CIS_RODVZTAH,
 * 4. dlouhodobé ošetřovné odmítnuté zaměstnavatelem (§ 191a ZP) se nepředá,
 * 5. změna zdravotní pojišťovny na kartě osoby vydá HOZ oběma pojišťovnám
 *    (odhláška „O", přihláška „P").
 *
 * Všechna data jsou syntetická; transakci vrací tearDown.
 */
#[Group('integration')]
#[Group('payroll-full-flow')]
final class PayrollSicknessFullFlowTest extends TestCase
{
    use PayrollFullFlowTrait;

    private const ENVIRONMENT = 'production';

    private int $officeId;

    protected function setUp(): void
    {
        $this->bootPayrollFullFlow();
        $this->officeId = $this->createOffice('NEM', 'Syntetická účtárna dávek', '9990007777');
        $this->configureSocialInsuranceOutput($this->officeId);
        $this->configureHealthInsuranceOutput();
        // Identifikátory zaměstnavatele pro ČSSZ žijí v Mzdách (VS u účtárny,
        // kód OSSZ v nastavení zaměstnavatele), ne na firmě.
        $this->db->pdo()->prepare(
            'UPDATE payroll_employer_settings SET social_security_office_code = "115" WHERE supplier_id = ?',
        )->execute([$this->supplierId]);
    }

    protected function tearDown(): void
    {
        $this->tearDownPayrollFullFlow();
    }

    public function testApprovedIncapacityCreatesCaseWithDeadlinesAndSubmissions(): void
    {
        $person = $this->sicknessPerson(1, 'Jana Nemocná');
        $average = $this->createApprovedAverage($person['employment_id'], 2);
        $this->publishShifts($person['employment_id'], self::workdays('2026-06'));
        $dpn = ['first_day_fully_worked' => false, 'insurance_eligibility_confirmed' => true, 'conflicting_benefit_excluded' => true];

        // 8. až 19. 6. je 12 dnů: celé je kryje náhrada mzdy (§ 192 ZP),
        // nemocenské by náleželo až od 15. dne (§ 26 odst. 1), případ nevzniká.
        $first = $this->approveAbsence($person['employment_id'], 'dpn', '2026-06-08', '2026-06-19', (int) $average['id'], $dpn);
        self::assertNull($first['sickness_case'], json_encode($first['sickness_case']) ?: '');

        // Neschopnost zapsaná po částech je jedna událost: prodloužení ji
        // dotáhne přes 14. den a teprve teď vznikne případ — od prvního dne.
        $second = $this->approveAbsence($person['employment_id'], 'dpn', '2026-06-20', '2026-06-22', (int) $average['id'], $dpn);
        self::assertSame('created', $second['sickness_case']['outcome'], json_encode($second['sickness_case']) ?: '');
        self::assertSame('NEM', $second['sickness_case']['benefit_kind']);
        // § 97 odst. 2: neprodleně po uplynutí prvních 14 dnů, tedy od 22. 6.
        self::assertSame('2026-06-22', $second['sickness_case']['nempri_due_on']);
        $caseId = (int) $second['sickness_case']['case_id'];

        $third = $this->approveAbsence($person['employment_id'], 'dpn', '2026-06-23', '2026-06-26', (int) $average['id'], $dpn);
        self::assertSame('extended', $third['sickness_case']['outcome']);
        self::assertSame($caseId, (int) $third['sickness_case']['case_id']);

        $cases = $this->service(SicknessCaseService::class);
        $case = $cases->requireCase($this->supplierId, self::ENVIRONMENT, $caseId);
        self::assertSame('2026-06-08', $case['incapacity_from']);
        self::assertSame('2026-06-26', $case['incapacity_to']);
        self::assertSame((int) $first['absence']['id'], (int) $case['absence_id']);

        $this->cashPayout($person['employee_id']);
        $case = $cases->update($this->supplierId, self::ENVIRONMENT, $caseId, (int) $case['row_version'], [
            'decision_number' => 'A1234567',
            'daily_working_hours' => '8',
            'issued_on' => '2026-06-29',
            'returned_to_work' => '1',
            'returned_on' => '2026-06-29',
        ]);

        $overview = $this->service(PayrollDeadlineOverviewService::class)
            ->overview($this->supplierId, self::ENVIRONMENT, 400);
        $due = [];
        foreach ($overview['items'] as $item) {
            if (($item['case_id'] ?? null) === $caseId) {
                $due[$item['title']] = $item['due_on'];
            }
        }
        self::assertSame(['HZUPN' => '2026-06-29', 'NEMPRI' => '2026-06-22'], $this->sorted($due));

        $submissions = $this->service(SicknessSubmissionService::class);
        $nempri = (string) $submissions->preview($this->supplierId, self::ENVIRONMENT, $caseId, SicknessDocumentKind::Nempri)['xml'];
        self::assertStringContainsString('<druhDavky>NEM</druhDavky>', $nempri);
        self::assertStringContainsString('<cisloRozhodnuti>A1234567</cisloRozhodnuti>', $nempri);
        $hzupn = (string) $submissions->preview($this->supplierId, self::ENVIRONMENT, $caseId, SicknessDocumentKind::Hzupn)['xml'];
        self::assertStringContainsString('<datumNavratDoPrace>2026-06-29</datumNavratDoPrace>', $hzupn);
    }

    /**
     * Případ zapsaný ručně k neschopnosti do 14 dnů: dávka z ní neplyne
     * (§ 26 odst. 1 zák. č. 187/2006 Sb.), hlídač termínů k ní NEMPRI ani HZUPN
     * neukáže a NEMPRI se připravit nedá.
     */
    public function testIncapacityWithinWageCompensationWindowHasNoNempri(): void
    {
        $person = $this->sicknessPerson(7, 'Olga Krátká');
        $this->cashPayout($person['employee_id']);
        $cases = $this->service(SicknessCaseService::class);
        $case = $cases->create($this->supplierId, self::ENVIRONMENT, $person['employment_id'], 'NEM', [
            'incapacity_from' => '2026-06-08',
            'incapacity_to' => '2026-06-21',
            'decision_number' => 'A1112223',
            'daily_working_hours' => '8',
        ], $this->actors[0]);
        $caseId = (int) $case['id'];

        $overview = $this->service(PayrollDeadlineOverviewService::class)
            ->overview($this->supplierId, self::ENVIRONMENT, 400);
        foreach ($overview['items'] as $item) {
            self::assertNotSame($caseId, $item['case_id'] ?? null, json_encode($item) ?: '');
        }

        try {
            $this->service(SicknessSubmissionService::class)
                ->preview($this->supplierId, self::ENVIRONMENT, $caseId, SicknessDocumentKind::Nempri);
            self::fail('NEMPRI k neschopnosti do 14 dnů nevzniká.');
        } catch (SicknessException $exception) {
            self::assertSame('nempri_within_wage_compensation_window', $exception->validationCode);
        }
    }

    /**
     * Zrušená absence zruší i případ, ze kterého se ještě nic nepodalo —
     * jinak by hlídač termínů dál připomínal lhůtu k události, která nenastala.
     */
    public function testCancelledAbsenceCancelsDraftCase(): void
    {
        $person = $this->sicknessPerson(6, 'Ivo Zrušený');
        $approved = $this->approveAbsence($person['employment_id'], 'ocr', '2026-06-08', '2026-06-10');
        $caseId = (int) $approved['sickness_case']['case_id'];

        $cancelled = $this->absences->cancel(
            $this->request('POST', '/api/payroll/absences/cancel')->withParsedBody([
                'row_version' => $approved['absence']['row_version'],
            ]),
            new Response(),
            ['id' => (string) $approved['absence']['id']],
        );
        self::assertSame(200, $cancelled->getStatusCode(), (string) $cancelled->getBody());
        self::assertSame('cancelled', $this->json($cancelled)['sickness_case']['outcome']);
        $case = $this->service(SicknessCaseService::class)
            ->requireCase($this->supplierId, self::ENVIRONMENT, $caseId);
        self::assertSame('cancelled', $case['status']);
    }

    /**
     * Zaměstnání skončilo 30. 6. Neschopnost od 3. 7. je v sedmidenní
     * ochranné lhůtě a NEMPRI ji předá s koncem zaměstnání; od 8. 7. už nárok
     * z tohoto vztahu nevzniká a případ nejde založit.
     */
    public function testIncapacityAfterEmploymentEndIsSubmittedOnlyWithinProtectionPeriod(): void
    {
        $person = $this->sicknessPerson(2, 'Petr Odcházející');
        $this->cashPayout($person['employee_id']);
        $this->db->pdo()->prepare(
            'UPDATE payroll_employments SET end_date = "2026-06-30", status = "ended"
              WHERE supplier_id = ? AND id = ?',
        )->execute([$this->supplierId, $person['employment_id']]);
        $cases = $this->service(SicknessCaseService::class);

        $case = $cases->create($this->supplierId, self::ENVIRONMENT, $person['employment_id'], 'NEM', [
            'incapacity_from' => '2026-07-03',
            'decision_number' => 'A7654321',
            'daily_working_hours' => '8',
        ], $this->actors[0]);
        $listed = array_values(array_filter(
            $cases->list($this->supplierId, self::ENVIRONMENT, $person['employment_id']),
            static fn (array $row): bool => (int) $row['id'] === (int) $case['id'],
        ));
        self::assertSame('protection_period', $listed[0]['protection_period']['status']);
        self::assertSame('2026-07-07', $listed[0]['protection_period']['protection_until']);

        $xml = (string) $this->service(SicknessSubmissionService::class)
            ->preview($this->supplierId, self::ENVIRONMENT, (int) $case['id'], SicknessDocumentKind::Nempri)['xml'];
        self::assertStringContainsString('<zamestnanDo>2026-06-30</zamestnanDo>', $xml);

        try {
            $cases->create($this->supplierId, self::ENVIRONMENT, $person['employment_id'], 'NEM', [
                'incapacity_from' => '2026-07-08',
            ], $this->actors[0]);
            self::fail('Neschopnost osmý den po skončení zaměstnání nárok nezakládá.');
        } catch (SicknessException $exception) {
            self::assertSame('sickness_event_outside_protection_period', $exception->validationCode);
        }
    }

    public function testCareBenefitFromApprovedAbsenceCarriesCodebookRelationship(): void
    {
        $person = $this->sicknessPerson(3, 'Eva Pečující');
        $this->cashPayout($person['employee_id']);
        $approved = $this->approveAbsence($person['employment_id'], 'ocr', '2026-06-08', '2026-06-12');
        self::assertSame('created', $approved['sickness_case']['outcome']);
        self::assertSame('OSE', $approved['sickness_case']['benefit_kind']);
        $caseId = (int) $approved['sickness_case']['case_id'];
        $cases = $this->service(SicknessCaseService::class);
        $case = $cases->requireCase($this->supplierId, self::ENVIRONMENT, $caseId);

        try {
            $cases->update($this->supplierId, self::ENVIRONMENT, $caseId, (int) $case['row_version'], [
                'relationship_code' => '1',
            ]);
            self::fail('Kód z CIS_VZTAH (DLO) u ošetřovného neplatí.');
        } catch (SicknessException $exception) {
            self::assertSame('nempri_relationship_code_invalid', $exception->validationCode);
        }

        $cases->update($this->supplierId, self::ENVIRONMENT, $caseId, (int) $case['row_version'], [
            'decision_number' => 'B1234567',
            'daily_working_hours' => '8',
            'action_start' => true,
            'action_end' => true,
            'cared_first_name' => 'Dítě',
            'cared_last_name' => 'Syntetické',
            'cared_birth_date' => '2018-05-05',
            'care_reason' => 'ill',
            'relationship_code' => 'PL',
            'care_days' => [['from' => '2026-06-08', 'to' => '2026-06-12']],
            'planned_shifts' => true,
        ]);

        $xml = (string) $this->service(SicknessSubmissionService::class)
            ->preview($this->supplierId, self::ENVIRONMENT, $caseId, SicknessDocumentKind::Nempri)['xml'];
        self::assertStringContainsString('<druhDavky>OSE</druhDavky>', $xml);
        self::assertStringContainsString('<kodRodVztah>PL</kodRodVztah>', $xml);
    }

    /**
     * § 191a zákoníku práce: odmítnutí dlouhodobé péče nese den a důvod
     * a z případu se pak NEMPRI nepředá. Souhlas podání uvolní.
     */
    public function testLongTermCareRefusedByEmployerIsNotSubmitted(): void
    {
        $person = $this->sicknessPerson(4, 'Karel Ošetřující');
        $this->cashPayout($person['employee_id']);
        $cases = $this->service(SicknessCaseService::class);
        $case = $cases->create($this->supplierId, self::ENVIRONMENT, $person['employment_id'], 'DLO', [
            'incapacity_from' => '2026-06-08',
            'decision_number' => 'C1234567',
            'daily_working_hours' => '8',
            'action_start' => true,
            'cared_first_name' => 'Rodič',
            'cared_last_name' => 'Syntetický',
            'cared_birth_date' => '1950-01-01',
            'relationship_code' => '2',
            'alternation' => false,
        ], $this->actors[0]);

        try {
            $cases->update($this->supplierId, self::ENVIRONMENT, (int) $case['id'], (int) $case['row_version'], [
                'long_term_care_consent' => 'refused',
                'long_term_care_consent_on' => '2026-06-05',
            ]);
            self::fail('Odmítnutí bez důvodu nesmí projít.');
        } catch (SicknessException $exception) {
            self::assertSame('dlo_employer_refusal_reason_missing', $exception->validationCode);
        }

        $case = $cases->update($this->supplierId, self::ENVIRONMENT, (int) $case['id'], (int) $case['row_version'], [
            'long_term_care_consent' => 'refused',
            'long_term_care_consent_on' => '2026-06-05',
            'long_term_care_refusal_reason' => 'Syntetický vážný provozní důvod.',
        ]);
        $submissions = $this->service(SicknessSubmissionService::class);
        try {
            $submissions->preview($this->supplierId, self::ENVIRONMENT, (int) $case['id'], SicknessDocumentKind::Nempri);
            self::fail('Odmítnutá dlouhodobá péče se nepředává.');
        } catch (SicknessException $exception) {
            self::assertSame('dlo_employer_refused', $exception->validationCode);
        }

        $case = $cases->update($this->supplierId, self::ENVIRONMENT, (int) $case['id'], (int) $case['row_version'], [
            'long_term_care_consent' => 'granted',
            'long_term_care_consent_on' => '2026-06-06',
            'long_term_care_refusal_reason' => null,
        ]);
        self::assertSame('granted', $case['long_term_care_consent']);
        $xml = (string) $submissions->preview($this->supplierId, self::ENVIRONMENT, (int) $case['id'], SicknessDocumentKind::Nempri)['xml'];
        self::assertStringContainsString('<kodVztah>2</kodVztah>', $xml);
    }

    /**
     * Přestup k jiné pojišťovně od 1. 7.: dosavadní 111 dostane odhlášku „O"
     * k 30. 6., nová 205 přihlášku „P" k 1. 7. Dřív z přestupu nevznikla věta
     * vůbec (kód byl fail-closed).
     */
    public function testInsurerChangeProducesBulkNotificationForBothInsurers(): void
    {
        $person = $this->sicknessPerson(5, 'Zuzana Přestupující');
        $accounts = $this->service(PayrollInstitutionAccountRepository::class);
        $accounts->create($this->supplierId, [
            'institution_type' => 'health_insurer',
            'institution_code' => '205',
            'institution_name' => 'Syntetická druhá pojišťovna',
            'bank_account' => '1000000005/0100',
            'currency_code' => 'CZK',
            'variable_symbol' => '0000002050',
            'specific_symbol' => null,
            'constant_symbol' => null,
            'valid_from' => '2026-01-01',
            'valid_to' => null,
            'source_kind' => 'official_document',
            'source_reference' => 'synthetic:sickness-flow-health-205',
            'verified_on' => '2026-06-15',
        ], $this->actors[0]);
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'UPDATE payroll_person_health_coverage_history SET effective_to = "2026-06-30"
              WHERE supplier_id = ? AND employee_id = ? AND effective_to IS NULL',
        )->execute([$this->supplierId, $person['employee_id']]);
        $pdo->prepare(
            'INSERT INTO payroll_person_health_coverage_history
                (supplier_id, employee_id, jurisdiction, insurer_status, insurer_code,
                 insurer_evidence_reference, effective_from)
             VALUES (?, ?, "czech_regime_verified", "verified", "205", "document:synthetic-card-205", "2026-07-01")',
        )->execute([$this->supplierId, $person['employee_id']]);

        $health = $this->service(HealthInsuranceSubmissionService::class);
        $outgoing = (string) $health->bulkNotificationDownload($this->supplierId, '2026-07', '111')['bytes'];
        $incoming = (string) $health->bulkNotificationDownload($this->supplierId, '2026-07', '205')['bytes'];

        self::assertStringContainsString('<kodZdravotniPojistovny>111</kodZdravotniPojistovny>', $outgoing);
        self::assertStringContainsString('<kodzmeny>O</kodzmeny>', $outgoing);
        self::assertStringContainsString('<datumZmeny>2026-06-30</datumZmeny>', $outgoing);
        self::assertStringContainsString('<kodZdravotniPojistovny>205</kodZdravotniPojistovny>', $incoming);
        self::assertStringContainsString('<kodzmeny>P</kodzmeny>', $incoming);
        self::assertStringContainsString('<datumZmeny>2026-07-01</datumZmeny>', $incoming);
    }

    /** @return array{employee_id:int,employment_id:int,name:string} */
    private function sicknessPerson(int $sequence, string $name): array
    {
        $person = $this->createEmployment($this->officeId, $name, $sequence, 'hpp', 'employment', 40, 10_000);
        [$first, $last] = explode(' ', $name, 2);
        $this->completeJmhzEmployment($person, identity: [
            'first_name' => $first,
            'last_name' => $last,
            'birth_date' => '1988-04-12',
            'sex' => 'female',
            'birth_number' => self::syntheticBirthNumber('1988-04-12', 'female', $sequence),
        ]);

        return $person;
    }

    /** Výplata mzdy v hotovosti na adresu bydliště — NEMPRI nese způsob výplaty. */
    private function cashPayout(int $employeeId): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'UPDATE payroll_employee_profiles SET payout_method = "cash" WHERE supplier_id = ? AND employee_id = ?',
        )->execute([$this->supplierId, $employeeId]);
        $pdo->prepare(
            'INSERT INTO payroll_person_addresses
                (supplier_id, employee_id, address_type, street_line, city, postal_code,
                 country_code, effective_from)
             VALUES (?, ?, "residence", "Zkušební 12", "Testov", "11000", "CZ", "2026-05-01")',
        )->execute([$this->supplierId, $employeeId]);
    }

    /**
     * @param array<string,mixed> $decisionExtra
     * @return array<string,mixed>
     */
    private function approveAbsence(
        int $employmentId,
        string $type,
        string $from,
        string $to,
        ?int $averageId = null,
        array $decisionExtra = [],
    ): array {
        $created = $this->requestAbsence($employmentId, $type, $from, $to, $averageId);
        self::assertSame(201, $created->getStatusCode(), (string) $created->getBody());
        $absence = $this->json($created)['absence'];
        $decision = $this->absences->decision(
            $this->request('POST', '/api/payroll/absences/decision')->withParsedBody($decisionExtra + [
                'row_version' => $absence['row_version'],
                'decision' => 'approved',
            ]),
            new Response(),
            ['id' => (string) $absence['id']],
        );
        self::assertSame(200, $decision->getStatusCode(), (string) $decision->getBody());

        return $this->json($decision);
    }

    /**
     * @param array<string,string> $values
     * @return array<string,string>
     */
    private function sorted(array $values): array
    {
        ksort($values);

        return $values;
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
