<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll\Import\Registration;

use MyInvoice\Action\Payroll\PayrollRegistrationImportAction;
use MyInvoice\Repository\Payroll\PayrollSicknessCaseRepository;
use MyInvoice\Security\EffectiveRole;
use Slim\Psr7\Response;
use MyInvoice\Service\Payroll\Import\Ozuspoj\OzuspojPredecessorIntentImporter;
use MyInvoice\Service\Payroll\Import\Registration\RegistrationImportService;
use MyInvoice\Service\Payroll\Submission\Ozuspoj\OzuspojSubmissionKind;
use MyInvoice\Service\Payroll\Submission\Ozuspoj\OzuspojXmlPayload;
use MyInvoice\Service\Payroll\Submission\Ozuspoj\OzuspojXmlSerializer;
use MyInvoice\Service\Payroll\Submission\Sickness\SicknessCaseService;
use MyInvoice\Service\Payroll\Submission\Sickness\SicknessDocumentKind;
use MyInvoice\Tests\Support\PayrollFullFlowTrait;
use MyInvoice\Tests\Unit\Payroll\Import\Sickness\SicknessImportXmlFixtures;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Import NEMPRI, HZUPN a OZUSPOJ předchozího programu (K3): podání už odeslal
 * jiný program, takže se do evidence jen zapíše, že je vyřídil, a hlídač lhůt
 * je nepožaduje podruhé. Rozběhnutá neschopnost bez HZUPN zůstává hlídaná.
 *
 * Všechna data jsou syntetická; transakci vrací tearDown.
 */
#[Group('integration')]
#[Group('payroll-full-flow')]
final class SicknessDocumentImportTest extends TestCase
{
    use PayrollFullFlowTrait;

    private const ENVIRONMENT = 'production';
    private const DECISION = 'A1234567';

    private int $officeId;
    private string $birthNumber;
    /** @var array{employee_id:int,employment_id:int,name:string} */
    private array $person;
    private RegistrationImportService $imports;

    protected function setUp(): void
    {
        $this->bootPayrollFullFlow();
        $this->officeId = $this->createOffice('DAV', 'Syntetická účtárna dávek', SicknessImportXmlFixtures::VARIABLE_SYMBOL);
        $this->configureSocialInsuranceOutput($this->officeId);
        $this->db->pdo()->prepare(
            'UPDATE payroll_employer_settings SET social_security_office_code = "112" WHERE supplier_id = ?',
        )->execute([$this->supplierId]);
        $imports = $this->container->get(RegistrationImportService::class);
        self::assertInstanceOf(RegistrationImportService::class, $imports);
        $this->imports = $imports;

        $this->birthNumber = self::syntheticBirthNumber('1990-01-01', 'male', 3);
        $this->person = $this->createEmployment($this->officeId, 'Zkušební Pojištěnec', 3, 'hpp', 'employment', 40, 10_000);
        $this->completeJmhzEmployment($this->person, identity: [
            'first_name' => 'Zkušební',
            'last_name' => 'Pojištěnec',
            'birth_date' => '1990-01-01',
            'sex' => 'male',
            'birth_number' => $this->birthNumber,
        ]);
    }

    protected function tearDown(): void
    {
        $this->tearDownPayrollFullFlow();
    }

    /**
     * Ošetřovné nese den vzniku i konec, takže případ vznikne z podání samotného
     * jako převzatý z předchozího programu. Opakovaný import téhož souboru
     * nic nezdvojí.
     */
    public function testCareNempriCreatesPredecessorCaseAndReimportIsIdempotent(): void
    {
        $files = [$this->file('ose.xml', SicknessImportXmlFixtures::nempri25('OSE', $this->birthNumber, ['decision' => '10278000600075284N']))];

        $preview = $this->imports->preview($this->supplierId, self::ENVIRONMENT, $files);
        self::assertNull($preview['files'][0]['error']);
        self::assertSame('NEMPRI25', $preview['files'][0]['document_type']);
        $record = $preview['records'][0];
        self::assertSame('create_case', $record['operation'], json_encode($record, JSON_UNESCAPED_UNICODE) ?: '');
        self::assertTrue($record['selectable']);
        self::assertNull($record['blocker']);
        self::assertSame('NEMPRI', $record['benefit']['document']);
        self::assertSame('2026-09-14', $record['benefit']['incapacity_from']);
        self::assertSame($this->person['employment_id'], $record['match']['employment_id']);
        self::assertSame(1, $preview['summary']['create']);

        $applied = $this->apply($files, [$record['key']]);
        self::assertSame('applied', $applied['results'][0]['status'], (string) $applied['results'][0]['message']);
        self::assertContains('case_created', $applied['results'][0]['operations']);

        $case = $this->singleCase();
        self::assertSame('OSE', $case['benefit_kind']);
        self::assertSame('predecessor', $case['source']);
        self::assertSame('predecessor', $case['nempri_status']);
        self::assertNull($case['nempri_accepted_on']);
        self::assertSame('pending', $case['hzupn_status']);
        self::assertSame('2026-09-14', $case['incapacity_from']);
        self::assertSame('2026-09-20', $case['incapacity_to']);
        self::assertSame('10278000600075284N', $case['decision_number']);
        self::assertStringContainsString('NEMPRI ' . $record['key'], (string) $case['external_reference']);
        self::assertSame('accepted', $case['status'], 'Ošetřovné nemá HZUPN, případ je vyřízený.');

        $again = $this->imports->preview($this->supplierId, self::ENVIRONMENT, $files)['records'][0];
        self::assertSame('none', $again['operation']);
        self::assertFalse($again['selectable']);
        self::assertSame($case['id'], $again['benefit']['case_id']);
        $second = $this->apply($files, [$record['key']]);
        self::assertSame('skipped', $second['results'][0]['status']);
        self::assertSame(1, $this->caseCount());
    }

    /**
     * Rozběhnutá DPN, jejíž NEMPRI podal předchozí program: NEMPRI se označí
     * jako vyřízené a HZUPN k návratu do práce zůstává v hlídači lhůt.
     */
    public function testSicknessNempriMarksTakenOverCaseAndHzupnStaysWatched(): void
    {
        $caseId = $this->predecessorSicknessCase('2026-09-17', '2026-10-14');
        $files = [$this->file('nem.xml', SicknessImportXmlFixtures::nempri25('NEM', $this->birthNumber, ['decision' => self::DECISION]))];

        $record = $this->imports->preview($this->supplierId, self::ENVIRONMENT, $files)['records'][0];
        self::assertSame('update_case', $record['operation'], json_encode($record, JSON_UNESCAPED_UNICODE) ?: '');
        self::assertSame($caseId, $record['benefit']['case_id']);
        self::assertSame('2026-09-17', $record['benefit']['incapacity_from']);

        $applied = $this->apply($files, [$record['key']]);
        self::assertSame('applied', $applied['results'][0]['status'], (string) $applied['results'][0]['message']);
        self::assertContains('nempri_predecessor', $applied['results'][0]['operations']);

        $case = $this->caseRow($caseId);
        self::assertSame('predecessor', $case['nempri_status']);
        self::assertSame('pending', $case['hzupn_status']);
        self::assertSame(self::DECISION, $case['decision_number']);
        self::assertSame('submitted', $case['status']);
        self::assertContains($caseId, $this->watchedCaseIds(), 'Rozběhnutá DPN bez HZUPN musí zůstat v hlídači lhůt.');
    }

    public function testHzupnCompletesTheCaseAndLeavesTheWatcher(): void
    {
        $caseId = $this->predecessorSicknessCase('2026-09-17', null, self::DECISION);
        $this->container->get(SicknessCaseService::class)
            ->recordReceipt($this->supplierId, self::ENVIRONMENT, $caseId, SicknessDocumentKind::Nempri, 'predecessor', null, null);
        self::assertContains($caseId, $this->watchedCaseIds(), 'Bez HZUPN je rozběhnutá DPN hlídaná.');
        $files = [$this->file('hzupn.xml', SicknessImportXmlFixtures::hzupn20($this->birthNumber))];

        $record = $this->imports->preview($this->supplierId, self::ENVIRONMENT, $files)['records'][0];
        self::assertSame('HZUPN20', $record['document_type']);
        self::assertSame('update_case', $record['operation'], json_encode($record, JSON_UNESCAPED_UNICODE) ?: '');
        self::assertSame($caseId, $record['benefit']['case_id']);

        $applied = $this->apply($files, [$record['key']]);
        self::assertSame('applied', $applied['results'][0]['status'], (string) $applied['results'][0]['message']);

        $case = $this->caseRow($caseId);
        self::assertSame('predecessor', $case['hzupn_status']);
        self::assertSame('1', (string) $case['returned_to_work']);
        self::assertSame('2026-10-15', $case['returned_on']);
        self::assertSame('2026-10-14', $case['incapacity_to']);
        self::assertSame('2026-10-16', $case['issued_on']);
        self::assertEquals(4.0, (float) $case['hours_worked_last_day']);
        self::assertStringContainsString('HZUPN ' . $record['key'], (string) $case['external_reference']);
        self::assertNotContains($caseId, $this->watchedCaseIds(), 'HZUPN vyřídil předchozí program, případ se přestává hlídat.');
        $again = $this->imports->preview($this->supplierId, self::ENVIRONMENT, $files)['records'][0];
        self::assertSame('none', $again['operation']);
    }

    public function testHzupnWithoutCaseCreatesItFromApprovedAbsenceAndKeepsNempriWatched(): void
    {
        $absenceId = $this->approvedAbsence('2026-09-10', '2026-10-14');
        $files = [$this->file('hzupn.xml', SicknessImportXmlFixtures::hzupn20($this->birthNumber))];

        $record = $this->imports->preview($this->supplierId, self::ENVIRONMENT, $files)['records'][0];
        self::assertSame('create_case', $record['operation'], json_encode($record, JSON_UNESCAPED_UNICODE) ?: '');
        self::assertSame('2026-09-10', $record['benefit']['incapacity_from']);
        self::assertSame('2026-10-14', $record['benefit']['incapacity_to']);

        $applied = $this->apply($files, [$record['key']]);
        self::assertSame('applied', $applied['results'][0]['status'], (string) $applied['results'][0]['message']);
        $case = $this->singleCase();
        self::assertSame('predecessor', $case['hzupn_status']);
        self::assertSame('pending', $case['nempri_status'], 'NEMPRI hlášení nedokládá.');
        self::assertSame($absenceId, (int) $case['absence_id']);
        self::assertSame('2026-10-15', $case['returned_on']);
        self::assertContains((int) $case['id'], $this->watchedCaseIds(), 'NEMPRI zůstává otevřené a hlídané.');
    }

    /**
     * Návrat v pondělí: odvozený konec je neděle, ale neschopnost skončila
     * v pátek. HZUPN najde případ i přes víkend, skutečný konec nepřepíše
     * a rozdíl neohlásí jako rozpor.
     */
    public function testHzupnWithMondayReturnKeepsTheRealFridayEnd(): void
    {
        $caseId = $this->predecessorSicknessCase('2026-10-05', '2026-10-16', null);
        $this->container->get(SicknessCaseService::class)
            ->recordReceipt($this->supplierId, self::ENVIRONMENT, $caseId, SicknessDocumentKind::Nempri, 'predecessor', null, null);
        $files = [$this->file('hzupn.xml', SicknessImportXmlFixtures::hzupn20($this->birthNumber, [
            'decision' => 'A7654321',
            'returnedOn' => '2026-10-19',
            'issued' => '2026-10-20',
        ]))];

        $record = $this->imports->preview($this->supplierId, self::ENVIRONMENT, $files)['records'][0];
        self::assertNull($record['blocker'], json_encode($record, JSON_UNESCAPED_UNICODE) ?: '');
        self::assertSame('update_case', $record['operation']);
        self::assertSame($caseId, $record['benefit']['case_id']);
        self::assertNotContains('incapacity_to', array_column($record['changes'], 'field'));
        foreach ($record['warnings'] as $warning) {
            self::assertStringNotContainsString('Poslední den neschopnosti', (string) $warning);
        }

        $applied = $this->apply($files, [$record['key']]);
        self::assertSame('applied', $applied['results'][0]['status'], (string) $applied['results'][0]['message']);
        $case = $this->caseRow($caseId);
        self::assertSame('2026-10-16', $case['incapacity_to']);
        self::assertSame('2026-10-19', $case['returned_on']);
        self::assertSame('predecessor', $case['hzupn_status']);
    }

    public function testHzupnWithMondayReturnTakesFridayEndOfApprovedAbsence(): void
    {
        $absenceId = $this->approvedAbsence('2026-10-05', '2026-10-16');
        $files = [$this->file('hzupn.xml', SicknessImportXmlFixtures::hzupn20($this->birthNumber, [
            'decision' => 'A7654321',
            'returnedOn' => '2026-10-19',
            'issued' => '2026-10-20',
        ]))];

        $record = $this->imports->preview($this->supplierId, self::ENVIRONMENT, $files)['records'][0];
        self::assertNull($record['blocker'], json_encode($record, JSON_UNESCAPED_UNICODE) ?: '');
        self::assertSame('create_case', $record['operation']);
        self::assertSame('2026-10-16', $record['benefit']['incapacity_to']);

        $applied = $this->apply($files, [$record['key']]);
        self::assertSame('applied', $applied['results'][0]['status'], (string) $applied['results'][0]['message']);
        $case = $this->singleCase();
        self::assertSame('2026-10-16', $case['incapacity_to']);
        self::assertSame($absenceId, (int) $case['absence_id']);
    }

    public function testHzupnEndingBeforeTheCaseStartedIsBlocked(): void
    {
        $this->predecessorSicknessCase('2026-09-17', null, self::DECISION);
        $files = [$this->file('hzupn.xml', SicknessImportXmlFixtures::hzupn20($this->birthNumber, ['returnedOn' => '2026-09-10']))];

        $record = $this->imports->preview($this->supplierId, self::ENVIRONMENT, $files)['records'][0];

        self::assertStringContainsString('předchází dni vzniku', (string) $record['blocker']);
        self::assertFalse($record['selectable']);
    }

    public function testNempriAlreadySettledByPredecessorIsLeftAlone(): void
    {
        $caseId = $this->predecessorSicknessCase('2026-09-17', '2026-10-14', self::DECISION);
        $this->container->get(SicknessCaseService::class)
            ->recordReceipt($this->supplierId, self::ENVIRONMENT, $caseId, SicknessDocumentKind::Nempri, 'predecessor', null, null);
        $files = [$this->file('nem.xml', SicknessImportXmlFixtures::nempri25('NEM', $this->birthNumber, ['decision' => self::DECISION]))];

        $record = $this->imports->preview($this->supplierId, self::ENVIRONMENT, $files)['records'][0];

        self::assertSame('none', $record['operation']);
        self::assertNull($record['blocker']);
        self::assertFalse($record['selectable']);
        self::assertSame($caseId, $record['benefit']['case_id']);
    }

    public function testDeliveryDayFromProtocolIsStoredWhenGiven(): void
    {
        $caseId = $this->predecessorSicknessCase('2026-09-17', '2026-10-14');
        $files = [$this->file('nem.xml', SicknessImportXmlFixtures::nempri25('NEM', $this->birthNumber))];
        $key = $this->imports->preview($this->supplierId, self::ENVIRONMENT, $files)['records'][0]['key'];

        $applied = $this->imports->apply(
            $this->supplierId,
            self::ENVIRONMENT,
            $files,
            [$key],
            true,
            null,
            $this->actors[0],
            null,
            'test',
            null,
            false,
            false,
            false,
            false,
            false,
            null,
            null,
            [['key' => $key, 'received_on' => '2026-09-30']],
            true,
        );

        self::assertSame('applied', $applied['results'][0]['status'], (string) $applied['results'][0]['message']);
        $case = $this->caseRow($caseId);
        self::assertSame('predecessor', $case['nempri_status']);
        self::assertSame('2026-09-30', $case['nempri_accepted_on']);
    }

    public function testCaseOwnedByMyUctoIsNotSettledByPredecessor(): void
    {
        $this->predecessorSicknessCase('2026-09-17', '2026-10-14', null, 'myucto');
        $files = [$this->file('nem.xml', SicknessImportXmlFixtures::nempri25('NEM', $this->birthNumber))];

        $record = $this->imports->preview($this->supplierId, self::ENVIRONMENT, $files)['records'][0];

        self::assertNotNull($record['blocker']);
        self::assertStringContainsString('vede MyÚčto', (string) $record['blocker']);
        self::assertFalse($record['selectable']);
        $result = $this->apply($files, [$record['key']])['results'][0];
        self::assertSame('skipped', $result['status']);
        self::assertSame('pending', $this->singleCase()['nempri_status']);
    }

    public function testSicknessNempriWithoutCaseOrAbsenceIsBlockedNotGuessed(): void
    {
        $files = [$this->file('nem.xml', SicknessImportXmlFixtures::nempri25('NEM', $this->birthNumber))];

        $record = $this->imports->preview($this->supplierId, self::ENVIRONMENT, $files)['records'][0];

        self::assertSame('none', $record['operation']);
        self::assertStringContainsString('nenese den vzniku', (string) $record['blocker']);
        self::assertSame(0, $this->caseCount());
    }

    public function testSicknessNempriCreatesCaseFromApprovedAbsenceOfEventMonth(): void
    {
        $absenceId = $this->approvedAbsence('2026-09-10', '2026-10-02');
        $files = [$this->file('nem.xml', SicknessImportXmlFixtures::nempri25('NEM', $this->birthNumber, ['decision' => self::DECISION]))];

        $record = $this->imports->preview($this->supplierId, self::ENVIRONMENT, $files)['records'][0];
        self::assertSame('create_case', $record['operation'], json_encode($record, JSON_UNESCAPED_UNICODE) ?: '');
        self::assertSame('2026-09-10', $record['benefit']['incapacity_from']);

        $applied = $this->apply($files, [$record['key']]);
        self::assertSame('applied', $applied['results'][0]['status'], (string) $applied['results'][0]['message']);
        $case = $this->singleCase();
        self::assertSame('2026-09-10', $case['incapacity_from']);
        self::assertSame('2026-10-02', $case['incapacity_to']);
        self::assertSame($absenceId, (int) $case['absence_id']);
        self::assertSame('predecessor', $case['nempri_status']);
        self::assertSame('pending', $case['hzupn_status']);
        self::assertContains((int) $case['id'], $this->watchedCaseIds());
    }

    public function testUnknownPersonAndForeignEmployerAreBlocked(): void
    {
        $other = self::syntheticBirthNumber('1985-05-05', 'male', 8);
        $unknown = $this->imports->preview(
            $this->supplierId,
            self::ENVIRONMENT,
            [$this->file('a.xml', SicknessImportXmlFixtures::nempri25('OSE', $other))],
        )['records'][0];
        self::assertSame('not_found', $unknown['match']['status']);
        self::assertStringContainsString('v evidenci není', (string) $unknown['blocker']);

        $foreign = $this->imports->preview(
            $this->supplierId,
            self::ENVIRONMENT,
            [$this->file('b.xml', SicknessImportXmlFixtures::nempri25('OSE', $this->birthNumber, ['vs' => '9876543210']))],
        )['records'][0];
        self::assertStringContainsString('variabilní symbol', (string) $foreign['blocker']);
        self::assertSame(0, $this->caseCount());
    }

    public function testConcurrentEmploymentsMakeTheSentenceAmbiguous(): void
    {
        $this->createEmployment(
            $this->officeId,
            'Zkušební Pojištěnec',
            4,
            'hpp',
            'employment',
            20,
            5_000,
            existingEmployeeId: $this->person['employee_id'],
        );
        $files = [$this->file('ose.xml', SicknessImportXmlFixtures::nempri25('OSE', $this->birthNumber))];

        $record = $this->imports->preview($this->supplierId, self::ENVIRONMENT, $files)['records'][0];

        self::assertSame('ambiguous', $record['match']['status']);
        self::assertCount(2, $record['match']['candidates']);
        self::assertFalse($record['selectable']);
    }

    public function testOnlySelectedSentencesAreWritten(): void
    {
        $first = $this->file('a.xml', SicknessImportXmlFixtures::nempri25('OSE', $this->birthNumber, ['from' => '2026-09-01', 'to' => '2026-09-05']));
        $second = $this->file('b.xml', SicknessImportXmlFixtures::nempri25('OSE', $this->birthNumber, ['from' => '2026-09-14', 'to' => '2026-09-20']));
        $preview = $this->imports->preview($this->supplierId, self::ENVIRONMENT, [$first, $second]);
        self::assertCount(2, $preview['records']);

        $applied = $this->apply([$first, $second], [$preview['records'][1]['key']]);

        self::assertCount(1, $applied['results']);
        self::assertSame(1, $this->caseCount());
        self::assertSame('2026-09-14', $this->singleCase()['incapacity_from']);
    }

    public function testOldNempri20OfMoneyMarksTheCaseByDecisionNumber(): void
    {
        $caseId = $this->predecessorSicknessCase('2026-08-20', null, '103600000000000001');
        $files = [$this->file('nempri20.xml', SicknessImportXmlFixtures::nempri20($this->birthNumber))];

        $preview = $this->imports->preview($this->supplierId, self::ENVIRONMENT, $files);
        self::assertSame('NEMPRI20', $preview['files'][0]['document_type']);
        self::assertNotEmpty($preview['files'][0]['warnings']);
        $record = $preview['records'][0];
        self::assertSame('update_case', $record['operation'], json_encode($record, JSON_UNESCAPED_UNICODE) ?: '');

        $applied = $this->apply($files, [$record['key']]);
        self::assertSame('applied', $applied['results'][0]['status'], (string) $applied['results'][0]['message']);
        self::assertSame('predecessor', $this->caseRow($caseId)['nempri_status']);
    }

    /**
     * Podání dávek a záměry slevy jinde chrání právo `payroll.submissions`;
     * import je nesmí zapsat s pouhým právem na osoby a vztahy. Náhled je
     * ukáže každému, kdo import smí otevřít.
     */
    public function testSicknessAndOzuspojApplyNeedsSubmissionsPermission(): void
    {
        $this->db->pdo()->prepare(
            'UPDATE payroll_employment_terms
                SET social_part_time_discount_reason = "age_55_plus"
              WHERE supplier_id = ? AND employment_id = ?',
        )->execute([$this->supplierId, $this->person['employment_id']]);
        $files = [
            $this->file('ose.xml', SicknessImportXmlFixtures::nempri25('OSE', $this->birthNumber, ['decision' => '10278000600075284N'])),
            $this->file('ozuspoj.xml', $this->ozuspojXml('2026-02-01')),
        ];
        $records = $this->imports->preview($this->supplierId, self::ENVIRONMENT, $files)['records'];
        $keys = array_column($records, 'key');
        self::assertCount(2, $keys);
        $received = array_map(static fn (string $key): array => ['key' => $key, 'received_on' => '2026-02-03'], $keys);
        $body = [
            'environment' => self::ENVIRONMENT,
            'files' => $files,
            'keys' => $keys,
            'evidence_confirmed' => true,
            'received_on' => $received,
        ];
        $action = $this->container->get(PayrollRegistrationImportAction::class);
        self::assertInstanceOf(PayrollRegistrationImportAction::class, $action);

        $preview = $action->preview($this->importRequest($body, false), new Response());
        if ($preview->getStatusCode() === 403) {
            self::markTestSkipped('Mzdový modul není v téhle instalaci licencovaný.');
        }
        self::assertSame(200, $preview->getStatusCode(), (string) $preview->getBody());

        $denied = $action->apply($this->importRequest($body, false), new Response());
        self::assertSame(200, $denied->getStatusCode(), (string) $denied->getBody());
        $results = $this->json($denied)['results'];
        self::assertCount(2, $results);
        foreach ($results as $result) {
            self::assertSame('skipped', $result['status'], (string) json_encode($result, JSON_UNESCAPED_UNICODE));
            self::assertStringContainsString('právo ke správě mzdových podání', (string) $result['message']);
        }
        self::assertSame(0, $this->caseCount());

        $allowed = $action->apply($this->importRequest($body, true), new Response());
        self::assertSame(200, $allowed->getStatusCode(), (string) $allowed->getBody());
        foreach ($this->json($allowed)['results'] as $result) {
            self::assertSame('applied', $result['status'], (string) json_encode($result, JSON_UNESCAPED_UNICODE));
        }
        self::assertSame(1, $this->caseCount());
    }

    /** @param array<string,mixed> $body */
    private function importRequest(array $body, bool $withSubmissions): \Psr\Http\Message\ServerRequestInterface
    {
        $permissions = ['payroll.person.write' => 2, 'payroll.employment.write' => 2];
        if ($withSubmissions) {
            $permissions['payroll.submissions'] = 2;
        }

        return $this->request('POST', '/api/payroll/imports/registrations/apply')
            ->withParsedBody($body)
            ->withAttribute('auth.effective_role', new EffectiveRole(0, 'Test', 'staff', true, $permissions, 'custom'));
    }

    public function testOzuspojNeedsDeliveryDayAndThenIsTakenOverOnce(): void
    {
        $this->db->pdo()->prepare(
            'UPDATE payroll_employment_terms
                SET social_part_time_discount_reason = "age_55_plus"
              WHERE supplier_id = ? AND employment_id = ?',
        )->execute([$this->supplierId, $this->person['employment_id']]);
        $files = [$this->file('ozuspoj.xml', $this->ozuspojXml('2026-02-01'))];

        $preview = $this->imports->preview($this->supplierId, self::ENVIRONMENT, $files);
        self::assertSame('OZUSPOJ23', $preview['files'][0]['document_type']);
        $record = $preview['records'][0];
        self::assertSame('import_intent', $record['operation']);
        self::assertTrue($record['benefit']['needs_received_on']);
        self::assertFalse($record['selectable']);
        self::assertStringContainsString('den doručení', (string) $record['blocker']);

        $received = [['key' => $record['key'], 'received_on' => '2026-01-20']];
        $withDate = $this->imports->preview($this->supplierId, self::ENVIRONMENT, $files, null, null, null, $received)['records'][0];
        self::assertTrue($withDate['selectable']);
        self::assertNull($withDate['blocker']);

        $applied = $this->imports->apply(
            $this->supplierId,
            self::ENVIRONMENT,
            $files,
            [$record['key']],
            true,
            null,
            $this->actors[0],
            null,
            'test',
            null,
            false,
            false,
            false,
            false,
            false,
            null,
            null,
            $received,
            true,
        );
        self::assertSame('applied', $applied['results'][0]['status'], (string) $applied['results'][0]['message']);
        $intent = $this->db->pdo()->prepare(
            'SELECT status, accepted_on, predecessor_source FROM payroll_discount_intents WHERE supplier_id = ? AND employment_id = ?',
        );
        $intent->execute([$this->supplierId, $this->person['employment_id']]);
        $row = $intent->fetch(\PDO::FETCH_ASSOC);
        self::assertIsArray($row);
        self::assertSame('accepted', $row['status']);
        self::assertSame('2026-01-20', $row['accepted_on']);
        self::assertSame(OzuspojPredecessorIntentImporter::SOURCE, $row['predecessor_source']);

        $again = $this->imports->preview($this->supplierId, self::ENVIRONMENT, $files, null, null, null, $received)['records'][0];
        self::assertSame('none', $again['operation']);
        self::assertFalse($again['selectable']);
        self::assertNull($again['blocker']);
    }

    private function ozuspojXml(string $intentFrom): string
    {
        return (new OzuspojXmlSerializer())->serialize(new OzuspojXmlPayload(
            kind: OzuspojSubmissionKind::Start,
            osszCode: 112,
            intentFrom: $intentFrom,
            intentTo: null,
            employerVariableSymbol: SicknessImportXmlFixtures::VARIABLE_SYMBOL,
            employerIdentificationNumber: SicknessImportXmlFixtures::BUSINESS_ID,
            employerName: 'Syntetický zaměstnavatel',
            employeeFirstName: 'Zkušební',
            employeeLastName: 'Pojištěnec',
            employeeBirthDate: '1990-01-01',
            employeeBirthNumber: $this->birthNumber,
            productName: 'Syntetický mzdový program',
            productVersion: '1.0',
        ));
    }

    /** Případ nemocenského převzatý z předchozího programu (nebo vedený v MyÚčtu). */
    private function predecessorSicknessCase(string $from, ?string $to, ?string $decision = null, string $source = 'predecessor'): int
    {
        $cases = $this->container->get(SicknessCaseService::class);
        self::assertInstanceOf(SicknessCaseService::class, $cases);
        $input = ['incapacity_from' => $from, 'daily_working_hours' => '8'];
        if ($to !== null) {
            $input['incapacity_to'] = $to;
        }
        if ($decision !== null) {
            $input['decision_number'] = $decision;
        }
        $case = $cases->create(
            $this->supplierId,
            self::ENVIRONMENT,
            $this->person['employment_id'],
            'NEM',
            $input,
            $this->actors[0],
            $source === 'predecessor' ? ['source' => 'predecessor'] : [],
        );

        return (int) $case['id'];
    }

    private function approvedAbsence(string $from, string $to): int
    {
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_absences
                (supplier_id, employment_id, absence_type, date_from, date_to, status)
             VALUES (?, ?, "dpn", ?, ?, "approved")',
        )->execute([$this->supplierId, $this->person['employment_id'], $from, $to]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    /** @param list<array{name:string,content_base64:string}> $files @param list<string> $keys @return array<string,mixed> */
    private function apply(array $files, array $keys): array
    {
        return $this->imports->apply(
            $this->supplierId,
            self::ENVIRONMENT,
            $files,
            $keys,
            true,
            null,
            $this->actors[0],
            null,
            'sickness-import-test',
            null,
            false,
            false,
            false,
            false,
            false,
            null,
            null,
            null,
            true,
        );
    }

    /** @return array{name:string,content_base64:string} */
    private function file(string $name, string $content): array
    {
        return ['name' => $name, 'content_base64' => base64_encode($content)];
    }

    /** @return list<int> */
    private function watchedCaseIds(): array
    {
        $repository = $this->container->get(PayrollSicknessCaseRepository::class);
        self::assertInstanceOf(PayrollSicknessCaseRepository::class, $repository);

        return array_map(
            static fn (array $row): int => (int) $row['case_id'],
            $repository->openCases($this->supplierId, self::ENVIRONMENT),
        );
    }

    /** @return array<string,mixed> */
    private function caseRow(int $caseId): array
    {
        $statement = $this->db->pdo()->prepare('SELECT * FROM payroll_sickness_cases WHERE supplier_id = ? AND id = ?');
        $statement->execute([$this->supplierId, $caseId]);
        $row = $statement->fetch(\PDO::FETCH_ASSOC);
        self::assertIsArray($row);

        return $row;
    }

    /** @return array<string,mixed> */
    private function singleCase(): array
    {
        $statement = $this->db->pdo()->prepare('SELECT * FROM payroll_sickness_cases WHERE supplier_id = ?');
        $statement->execute([$this->supplierId]);
        $rows = $statement->fetchAll(\PDO::FETCH_ASSOC);
        self::assertCount(1, $rows);

        return $rows[0];
    }

    private function caseCount(): int
    {
        return (int) $this->scalar('SELECT COUNT(*) FROM payroll_sickness_cases WHERE supplier_id = ?', [$this->supplierId]);
    }
}
