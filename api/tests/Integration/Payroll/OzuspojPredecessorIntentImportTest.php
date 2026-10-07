<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Repository\Payroll\PayrollDiscountIntentRepository;
use MyInvoice\Service\Payroll\Import\Jmhz\JmhzExternalSubmissionStore;
use MyInvoice\Service\Payroll\Import\Ozuspoj\OzuspojPredecessorIntentImporter;
use MyInvoice\Service\Payroll\Migration\PayrollTakeoverDiscountIntentCheck;
use MyInvoice\Service\Payroll\Run\PayrollRunSnapshotBatchLoader;
use MyInvoice\Service\Payroll\Submission\Ozuspoj\OzuspojClaimDeadlinePolicy;
use MyInvoice\Service\Payroll\Submission\Ozuspoj\OzuspojDeadlinePolicy;
use MyInvoice\Service\Payroll\Submission\Ozuspoj\OzuspojDiscountEligibility;
use MyInvoice\Service\Payroll\Submission\Ozuspoj\OzuspojEligibilityOutcome;
use MyInvoice\Service\Payroll\Submission\Ozuspoj\OzuspojException;
use MyInvoice\Service\Payroll\Submission\Ozuspoj\OzuspojIntentEvidence;
use MyInvoice\Service\Payroll\Submission\Ozuspoj\OzuspojIntentService;
use MyInvoice\Service\Payroll\Submission\Ozuspoj\OzuspojSubmissionKind;
use MyInvoice\Service\Payroll\Submission\Ozuspoj\OzuspojXmlPayload;
use MyInvoice\Service\Payroll\Submission\Ozuspoj\OzuspojXmlSerializer;
use MyInvoice\Tests\Support\PayrollFullFlowTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;

/**
 * Záměr uplatňovat slevu na pojistném (OZUSPOJ) po převodu mezd a dolní mez
 * jeho oznámení podle § 7a odst. 5 zákona č. 589/1992 Sb.
 *
 * Všechna data jsou syntetická (rodné číslo projde jen kontrolou mod 11,
 * variabilní symbol a kód OSSZ jsou smyšlené); transakci vrací tearDown.
 */
#[Group('integration')]
final class OzuspojPredecessorIntentImportTest extends TestCase
{
    use PayrollFullFlowTrait;

    private const VARIABLE_SYMBOL = '1234567890';
    private const BIRTH_NUMBER = '7001010126';

    private int $officeId;

    protected function setUp(): void
    {
        $this->bootPayrollFullFlow();
        $this->officeId = $this->createOffice('OZUS', 'Syntetická účtárna slevy', self::VARIABLE_SYMBOL);
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_employer_settings
                (supplier_id, default_office_id, social_security_office_code)
             VALUES (?, ?, "112")',
        )->execute([$this->supplierId, $this->officeId]);
    }

    protected function tearDown(): void
    {
        $this->tearDownPayrollFullFlow();
    }

    /**
     * PRE-03: přijatý záměr předchozího programu po převodu chyběl a sleva
     * se neuplatnila (NotNotified). Po převzetí datové věty OZUSPOJ23 s dnem
     * doručení z protokolu ho čte výpočet a sleva je doložená.
     */
    public function testPredecessorIntentIsTakenOverAndEvidencesTheDiscount(): void
    {
        $person = $this->discountEmployment();

        $result = $this->importer()->import(
            $this->supplierId,
            'production',
            $this->ozuspojXml(OzuspojSubmissionKind::Start, '2026-02-01'),
            '2026-01-20',
            $this->actors[0],
        );

        self::assertSame('created', $result['import_status']);
        self::assertSame('accepted', $result['status']);
        self::assertSame(OzuspojPredecessorIntentImporter::SOURCE, $result['predecessor_source']);

        $intents = $this->loader()->discountIntents(
            $this->supplierId,
            [$person['employment_id']],
            '2026-03-01',
            '2026-03-31',
        );
        self::assertArrayHasKey($person['employment_id'], $intents);
        $verdict = (new OzuspojDiscountEligibility(new OzuspojClaimDeadlinePolicy()))->assess(
            OzuspojIntentEvidence::fromRow($intents[$person['employment_id']]),
            '2026-03-01',
            '2026-03-31',
            '2026-01-01',
            null,
        );
        self::assertSame(OzuspojEligibilityOutcome::Evidenced, $verdict->outcome);

        // Převzatý záměr nemá podání z MyÚčta, a tedy ani povinnost oznámení.
        self::assertSame(0, (int) $this->scalar(
            'SELECT COUNT(*) FROM payroll_obligations WHERE supplier_id = ? AND agenda_code = "OZUSPOJ"',
            [$this->supplierId],
        ));

        $again = $this->importer()->import(
            $this->supplierId,
            'production',
            $this->ozuspojXml(OzuspojSubmissionKind::Start, '2026-02-01'),
            '2026-01-20',
            $this->actors[0],
        );
        self::assertSame('unchanged', $again['import_status']);
    }

    public function testPredecessorEndClosesTheTakenOverIntent(): void
    {
        $this->discountEmployment();
        $this->importer()->import(
            $this->supplierId,
            'production',
            $this->ozuspojXml(OzuspojSubmissionKind::Start, '2026-02-01'),
            '2026-01-20',
            $this->actors[0],
        );

        $ended = $this->importer()->import(
            $this->supplierId,
            'production',
            $this->ozuspojXml(OzuspojSubmissionKind::End, null, '2026-05-31'),
            '2026-06-05',
            $this->actors[0],
        );

        self::assertSame('ended', $ended['status']);
        self::assertSame('2026-05-31', $ended['intent_to']);
        self::assertSame('2026-06-05', $ended['ended_accepted_on']);
    }

    public function testForeignEmployerFileIsRefused(): void
    {
        $this->discountEmployment();

        try {
            $this->importer()->import(
                $this->supplierId,
                'production',
                $this->ozuspojXml(OzuspojSubmissionKind::Start, '2026-02-01', variableSymbol: '9876543210'),
                '2026-01-20',
                $this->actors[0],
            );
            self::fail('Oznámení jiného zaměstnavatele se nesmí převzít.');
        } catch (OzuspojException $exception) {
            self::assertSame('ozuspoj_import_foreign_employer', $exception->validationCode);
        }
    }

    /**
     * Kontrola převodu upozorní na převzatý vztah s důvodem slevy bez
     * přijatého záměru; po převzetí oznámení předchozího programu zmizí.
     */
    public function testTakeoverCheckListsEmploymentWithDiscountReasonButNoIntent(): void
    {
        $person = $this->discountEmployment();
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_migration_reference_totals
                (supplier_id, source, period_start, external_person_ref,
                 external_relationship_ref, employee_id, employment_id)
             VALUES (?, "pamica", "2026-01-01", "SYN-1", "SYN-1-1", ?, ?)',
        )->execute([$this->supplierId, $person['employee_id'], $person['employment_id']]);
        $check = $this->container->get(PayrollTakeoverDiscountIntentCheck::class);
        self::assertInstanceOf(PayrollTakeoverDiscountIntentCheck::class, $check);

        $missing = $check->missingIntents($this->supplierId, 2026);
        self::assertSame([$person['employment_id']], array_column($missing, 'employment_id'));
        self::assertSame('age_55_plus', $missing[0]['discount_reason']);

        $this->importer()->import(
            $this->supplierId,
            'production',
            $this->ozuspojXml(OzuspojSubmissionKind::Start, '2026-02-01'),
            '2026-01-20',
            $this->actors[0],
        );
        self::assertSame([], $check->missingIntents($this->supplierId, 2026));
    }

    /**
     * EO-03: § 7a odst. 5 věta druhá — záměr nelze oznámit dříve než dnem
     * podání přihlášky zaměstnance. Doručení před přihláškou aplikace dřív
     * přijala, protože hlídala jen „měsíc před zahájením".
     */
    public function testAcceptanceBeforeRegistrationIsRefused(): void
    {
        $person = $this->discountEmployment();
        $this->recordPredecessorRegistration($person, '2026-10-28 09:00:00');
        $service = $this->intentService('2026-12-01');
        $created = $service->create(
            $this->supplierId,
            'production',
            $person['employment_id'],
            '2026-11-01',
            null,
            $this->actors[0],
        );
        self::assertSame('2026-10-28', $created['earliest_notification_on']);
        self::assertSame('2026-10-28', $created['registration_submitted_on']);
        $this->markSubmitted((int) $created['id']);

        try {
            $service->recordReceipt(
                $this->supplierId,
                'production',
                (int) $created['id'],
                'accepted',
                '2026-10-20',
                null,
            );
            self::fail('Doručení záměru před podáním přihlášky se nesmí zapsat jako přijetí.');
        } catch (OzuspojException $exception) {
            self::assertSame('ozuspoj_accepted_on_too_early', $exception->validationCode);
        }

        $accepted = $service->recordReceipt(
            $this->supplierId,
            'production',
            (int) $created['id'],
            'accepted',
            '2026-10-29',
            null,
        );
        self::assertSame('accepted', $accepted['status']);
    }

    public function testIntentWithoutKnownRegistrationCarriesTheWarningFlag(): void
    {
        $person = $this->discountEmployment();
        $created = $this->intentService('2026-12-01')->create(
            $this->supplierId,
            'production',
            $person['employment_id'],
            '2026-11-01',
            null,
            $this->actors[0],
        );

        self::assertNull($created['registration_submitted_on']);
        self::assertSame('2026-10-01', $created['earliest_notification_on']);
    }

    /** @return array{employee_id:int,employment_id:int,name:string} */
    private function discountEmployment(): array
    {
        $person = $this->createEmployment(
            $this->officeId,
            'Zaměstnanec Slevový',
            1,
            'hpp',
            'employment',
            20,
            5_000,
        );
        $this->db->pdo()->prepare(
            'UPDATE payroll_employment_terms
                SET social_part_time_discount_reason = "age_55_plus"
              WHERE supplier_id = ? AND employment_id = ?',
        )->execute([$this->supplierId, $person['employment_id']]);
        $this->insertPersonIdentifier($person['employee_id'], 'birth_number', self::BIRTH_NUMBER);

        return $person;
    }

    /** @param array{employee_id:int,employment_id:int,name:string} $person */
    private function recordPredecessorRegistration(array $person, string $submittedAt): void
    {
        $store = $this->container->get(JmhzExternalSubmissionStore::class);
        self::assertInstanceOf(JmhzExternalSubmissionStore::class, $store);
        $store->store($this->supplierId, 'production', JmhzExternalSubmissionStore::SOURCE_PAMICA, [
            'source_key' => 'synthetic-registration-a1',
            'document_kind' => 'registration',
            'period' => null,
            'submission_type' => null,
            'submission_guid' => null,
            'corrected_source_key' => null,
            'status' => JmhzExternalSubmissionStore::STATUS_SENT,
            'filled_at' => $submittedAt,
            'submitted_at' => $submittedAt,
            'accepted_at' => null,
            'program' => 'Syntetický program',
            'file_name' => null,
            'payload' => ['synthetic' => true],
        ], [[
            'position' => 1,
            'form_guid' => null,
            'form_type' => 'A1',
            'source_relation_ref' => null,
            'employee_id' => $person['employee_id'],
            'employment_id' => $person['employment_id'],
            'payload' => ['synthetic' => true],
        ]], $this->actors[0]);
    }

    private function markSubmitted(int $intentId): void
    {
        $repository = $this->container->get(PayrollDiscountIntentRepository::class);
        self::assertInstanceOf(PayrollDiscountIntentRepository::class, $repository);
        $row = $repository->find($this->supplierId, 'production', $intentId);
        self::assertIsArray($row);
        self::assertTrue($repository->update(
            $this->supplierId,
            'production',
            $intentId,
            (int) $row['row_version'],
            ['status' => 'submitted'],
        ));
    }

    private function ozuspojXml(
        OzuspojSubmissionKind $kind,
        ?string $intentFrom,
        ?string $intentTo = null,
        string $variableSymbol = self::VARIABLE_SYMBOL,
    ): string {
        return (new OzuspojXmlSerializer())->serialize(new OzuspojXmlPayload(
            kind: $kind,
            osszCode: 112,
            intentFrom: $intentFrom,
            intentTo: $intentTo,
            employerVariableSymbol: $variableSymbol,
            employerIdentificationNumber: '00000019',
            employerName: 'Syntetický zaměstnavatel',
            employeeFirstName: 'Zaměstnanec',
            employeeLastName: 'Slevový',
            employeeBirthDate: '1970-01-01',
            employeeBirthNumber: self::BIRTH_NUMBER,
            productName: 'Syntetický mzdový program',
            productVersion: '1.0',
        ));
    }

    private function importer(): OzuspojPredecessorIntentImporter
    {
        $importer = $this->container->get(OzuspojPredecessorIntentImporter::class);
        self::assertInstanceOf(OzuspojPredecessorIntentImporter::class, $importer);

        return $importer;
    }

    private function loader(): PayrollRunSnapshotBatchLoader
    {
        $loader = $this->container->get(PayrollRunSnapshotBatchLoader::class);
        self::assertInstanceOf(PayrollRunSnapshotBatchLoader::class, $loader);

        return $loader;
    }

    private function intentService(string $today): OzuspojIntentService
    {
        $repository = $this->container->get(PayrollDiscountIntentRepository::class);
        self::assertInstanceOf(PayrollDiscountIntentRepository::class, $repository);

        return new OzuspojIntentService(
            $repository,
            new OzuspojDeadlinePolicy(),
            new OzuspojClaimDeadlinePolicy(),
            new class ($today) implements ClockInterface {
                public function __construct(private readonly string $today) {}

                public function now(): \DateTimeImmutable
                {
                    return new \DateTimeImmutable($this->today . ' 12:00:00', new \DateTimeZone('Europe/Prague'));
                }
            },
        );
    }
}
