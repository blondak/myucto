<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission;

use MyInvoice\Service\Payroll\Submission\HealthInsurance\HealthBulkNotificationPayload;
use MyInvoice\Service\Payroll\Submission\HealthInsurance\HealthEmployerIdentification;
use MyInvoice\Service\Payroll\Submission\HealthInsurance\HealthInsuranceSchemaCatalog;
use MyInvoice\Service\Payroll\Submission\HealthInsurance\HealthInsuranceXmlSerializer;
use MyInvoice\Service\Payroll\Submission\HealthInsurance\HealthInsuranceXmlValidator;
use MyInvoice\Service\Payroll\Submission\HealthInsurance\HealthNotificationChange;
use MyInvoice\Service\Payroll\Submission\HealthInsurance\HealthNotificationCodeCatalog;
use MyInvoice\Service\Payroll\Submission\HealthInsurance\HealthNotificationDeadlinePolicy;
use MyInvoice\Service\Payroll\Submission\HealthInsurance\HealthNotificationDutyCatalog;
use MyInvoice\Service\Payroll\Submission\HealthInsurance\HealthNotificationDutyKind;
use MyInvoice\Service\Payroll\Submission\HealthInsurance\HealthNotificationDutyResolver;
use MyInvoice\Service\Payroll\Submission\HealthInsurance\HealthNotificationException;
use MyInvoice\Service\Payroll\Submission\HealthInsurance\HealthNotificationFacts;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Kódy nástupu a skončení, které oznámení zdravotní pojišťovně dřív vyrobit
 * neumělo: jednodenní zaměstnání („Q") a první přihlášení cizince („A", „E",
 * „C"). Význam písmen je z anotace `kodZmenyZamestnaceTyp` v připnutém HOZ
 * XSD; přijatá podání jiného mzdového systému kód „Q" používají.
 */
final class HealthNotificationEmploymentCodesTest extends TestCase
{
    private HealthNotificationCodeCatalog $codes;
    private HealthNotificationDutyResolver $resolver;

    protected function setUp(): void
    {
        $this->codes = new HealthNotificationCodeCatalog();
        $this->resolver = new HealthNotificationDutyResolver(
            new HealthNotificationDutyCatalog(),
            new HealthNotificationDeadlinePolicy(),
        );
    }

    /**
     * Zaměstnání, které vzniklo a zaniklo týž den, je JEDNA věta s kódem „Q".
     * Dřív z něj vznikla přihláška „P" i odhláška „O" téhož dne.
     */
    public function testSameDayStartAndEndIsASingleDayEmploymentWithCodeQ(): void
    {
        $duties = $this->resolver->resolve(new HealthNotificationFacts(
            employmentId: 7,
            employeeId: 3,
            relationType: 'employment',
            participates: true,
            insurerCode: '111',
            startedOn: '2026-03-01',
            endedOn: '2026-03-01',
        ));

        self::assertCount(1, $duties);
        self::assertSame(HealthNotificationDutyKind::SingleDayEmployment, $duties[0]->kind);
        self::assertTrue($duties[0]->reportedByEmployer);
        self::assertSame('2026-03-09', $duties[0]->deadline?->dueOn);
        self::assertSame('Q', $this->codes->codeFor($duties[0]->kind));
        self::assertTrue($this->codes->isCodeMappingDocumented($duties[0]->kind));
    }

    public function testDifferentDaysStayStartAndEnd(): void
    {
        $duties = $this->resolver->resolve(new HealthNotificationFacts(
            employmentId: 7,
            employeeId: 3,
            relationType: 'employment',
            participates: true,
            insurerCode: '111',
            startedOn: '2026-03-01',
            endedOn: '2026-03-02',
        ));

        self::assertSame(
            [HealthNotificationDutyKind::EmploymentStart, HealthNotificationDutyKind::EmploymentEnd],
            array_map(static fn ($duty) => $duty->kind, $duties),
        );
    }

    /** @return iterable<string,array{0:?string,1:bool,2:string}> */
    public static function startCodes(): iterable
    {
        yield 'občan ČR' => ['CZ', true, 'P'];
        yield 'bez státní příslušnosti v evidenci' => [null, true, 'P'];
        yield 'občan EU s číslem pojištěnce' => ['SK', true, 'A'];
        yield 'občan EU poprvé' => ['SK', false, 'E'];
        yield 'občan EHP poprvé' => ['NO', false, 'E'];
        yield 'cizinec mimo EU poprvé' => ['UA', false, 'C'];
        yield 'cizinec mimo EU s přiděleným číslem' => ['UA', true, 'P'];
        yield 'malá písmena z evidence' => ['de', false, 'E'];
    }

    #[DataProvider('startCodes')]
    public function testStartCodeFollowsCitizenshipAndAssignedNumber(
        ?string $citizenship,
        bool $hasNumber,
        string $expected,
    ): void {
        self::assertSame(
            $expected,
            $this->codes->employmentStartCode($citizenship, $hasNumber),
        );
        self::assertTrue($this->codes->isKnown($expected));
    }

    /** HOZ-KZ-5: ZP MV ČR (211) kódy E a C nepoužívá, nástup se hlásí kódem P nebo A. */
    public function testZpMvDoesNotGetFirstRegistrationCodes(): void
    {
        self::assertSame('A', $this->codes->employmentStartCode('SK', true, '211'));
        self::assertSame('P', $this->codes->employmentStartCode('UA', true, '211'));
        self::assertSame('P', $this->codes->employmentStartCode('CZ', false, '211'));
        // jiná pojišťovna dál ohlašuje první přihlášení
        self::assertSame('E', $this->codes->employmentStartCode('SK', false, '111'));

        foreach (['SK', 'UA'] as $citizenship) {
            try {
                $this->codes->employmentStartCode($citizenship, false, '211');
                self::fail('Pro ZP MV ČR nelze první přihlášení ohlásit kódem E ani C.');
            } catch (HealthNotificationException $exception) {
                self::assertSame('zp_mv_registration_required', $exception->errorCode);
            }
        }
    }

    public function testFirstRegistrationNumberIsSexAndBirthDate(): void
    {
        self::assertSame(
            'M05071980',
            $this->codes->firstRegistrationInsuranceNumber('male', '1980-07-05'),
        );
        self::assertSame(
            'Z12101982',
            $this->codes->firstRegistrationInsuranceNumber('female', '1982-10-12'),
        );
        self::assertTrue($this->codes->isFirstRegistrationCode('E'));
        self::assertTrue($this->codes->isFirstRegistrationCode('C'));
        self::assertFalse($this->codes->isFirstRegistrationCode('A'));
    }

    public function testFirstRegistrationNumberIsNotGuessedWithoutSex(): void
    {
        try {
            $this->codes->firstRegistrationInsuranceNumber('unspecified', '1980-07-05');
            self::fail('Bez pohlaví se číslo pojištěnce nevymýšlí.');
        } catch (HealthNotificationException $exception) {
            self::assertSame('zp_first_registration_identity_missing', $exception->errorCode);
        }
    }

    /** Nové kódy projdou doménovou validací věty i připnutým XSD. */
    public function testNewCodesPassThePinnedSchema(): void
    {
        $schemas = new HealthInsuranceSchemaCatalog();
        $serializer = new HealthInsuranceXmlSerializer($schemas, $this->codes);
        $validator = new HealthInsuranceXmlValidator($schemas, $this->codes, $serializer);
        $payload = new HealthBulkNotificationPayload(
            insurerCode: '111',
            employer: HealthEmployerIdentification::fromBusinessId(
                businessId: '12345678',
                accountingUnit: '00',
                name: 'Testovací firma s.r.o.',
                street: 'Zkušební',
                houseNumber: '12',
                postalCode: '11000',
                city: 'Praha 1',
                phone: '+420111222333',
            ),
            changes: [
                new HealthNotificationChange('Q', '2026-03-01', '9001011234', 'Jan', 'Testovací'),
                new HealthNotificationChange('E', '2026-03-02', 'M05071980', 'Petr', 'Zkušební'),
                new HealthNotificationChange('C', '2026-03-03', 'Z12101982', 'Eva', 'Vzorová'),
                new HealthNotificationChange('A', '2026-03-04', '9001011234', 'Adam', 'Pokusný'),
            ],
        );
        $xml = $serializer->serializeBulkNotification($payload);

        $validator->validateBulkNotification($payload, $xml);

        self::assertStringContainsString('<kodzmeny>Q</kodzmeny>', $xml);
        self::assertStringContainsString('<cisloPojistence>M05071980</cisloPojistence>', $xml);
    }
}
