<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission\Registration;

use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationIdentitySnapshot;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationIdentitySnapshotBuilder;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationInteraction;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationInteractionResolver;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationSchemaCatalog;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationXmlException;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationXmlPayload;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationXmlSerializer;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationXmlValidator;
use PHPUnit\Framework\TestCase;

final class PayrollRegistrationXmlCoreTest extends TestCase
{
    public function testResolverChoosesP1ThenRegzecA1AndP2WithoutGuessing(): void
    {
        $resolver = new PayrollRegistrationInteractionResolver();
        $snapshot = self::snapshot('CZ');

        $p1 = $resolver->resolve($snapshot, [
            'work_started' => false,
            'full_registration_data' => false,
            'pre_registration_accepted' => false,
            'did_not_start' => false,
        ]);
        self::assertSame(
            ['PREZEC26', 'limited_pre_registration', 9],
            [$p1->documentType, $p1->interaction, $p1->actionCode],
        );

        $a1 = $resolver->resolve(self::snapshot('CZ', 'REGZEC25'), [
            'work_started' => true,
            'full_registration_data' => true,
            'pre_registration_accepted' => true,
            'did_not_start' => false,
        ]);
        self::assertSame(
            ['REGZEC25', 'full_registration_after_p1', 1],
            [$a1->documentType, $a1->interaction, $a1->actionCode],
        );

        $p2 = $resolver->resolve($snapshot, [
            'work_started' => false,
            'full_registration_data' => false,
            'pre_registration_accepted' => true,
            'did_not_start' => true,
        ]);
        self::assertSame(
            ['PREZEC26', 'pre_registration_no_show', 10],
            [$p2->documentType, $p2->interaction, $p2->actionCode],
        );
    }

    public function testPrezecP1HasStableExactBytesAndPassesPinnedXsd(): void
    {
        $payload = self::payload(
            self::snapshot('CZ'),
            new PayrollRegistrationInteraction(
                'PREZEC26',
                'limited_pre_registration',
                9,
            ),
        );
        $xml = (new PayrollRegistrationXmlSerializer())->serialize($payload);

        self::assertSame(self::prezecGolden(), $xml);
        (new PayrollRegistrationXmlValidator(
            new PayrollRegistrationSchemaCatalog(),
        ))->validate($payload, $xml);
    }

    public function testRegzecA1HasStableExactBytesAndPassesPinnedXsd(): void
    {
        $payload = self::payload(
            self::snapshot('SK'),
            new PayrollRegistrationInteraction(
                'REGZEC25',
                'direct_full_registration',
                1,
            ),
            expectedStartOn: null,
            actualStartOn: '2026-08-05',
        );
        $xml = (new PayrollRegistrationXmlSerializer())->serialize($payload);

        self::assertSame(self::regzecGolden(), $xml);
        (new PayrollRegistrationXmlValidator(
            new PayrollRegistrationSchemaCatalog(),
        ))->validate($payload, $xml);
    }

    public function testPrezecP2HasStableExactBytesAndPassesPinnedXsd(): void
    {
        $payload = self::payload(
            self::snapshot('CZ'),
            new PayrollRegistrationInteraction(
                'PREZEC26',
                'pre_registration_no_show',
                10,
            ),
            expectedStartOn: null,
        );
        $xml = (new PayrollRegistrationXmlSerializer())->serialize($payload);

        self::assertSame(self::prezecP2Golden(), $xml);
        (new PayrollRegistrationXmlValidator(
            new PayrollRegistrationSchemaCatalog(),
        ))->validate($payload, $xml);
    }

    public function testPrezecRejectsForeignIdentityAndTamperedBytes(): void
    {
        // Agenda i zmrazená způsobilost odpovídají PREZEC26 — testuje se právě
        // podmínka občanství, ne vazba snapshotu (tu hlídá vlastní test).
        $payload = self::payload(
            self::snapshot('SK', 'PREZEC26'),
            new PayrollRegistrationInteraction(
                'PREZEC26',
                'limited_pre_registration',
                9,
            ),
        );
        $xml = (new PayrollRegistrationXmlSerializer())->serialize($payload);
        $validator = new PayrollRegistrationXmlValidator(
            new PayrollRegistrationSchemaCatalog(),
        );

        $this->expectCode(
            'registration_prezec_foreign_requires_full_registration',
            static fn () => $validator->validate($payload, $xml),
        );

        $validPayload = self::payload(
            self::snapshot('CZ'),
            $payload->interaction,
        );
        $validXml = (new PayrollRegistrationXmlSerializer())
            ->serialize($validPayload);
        $this->expectCode(
            'registration_xml_snapshot_mismatch',
            static fn () => $validator->validate(
                $validPayload,
                str_replace('Testov', 'Jinov', $validXml),
            ),
        );
    }

    /**
     * Integrační scénář proti dnešnímu kontraktu: snapshot staví skutečný
     * builder, takže se ověří i zmrazená způsobilost (`verified` /
     * `domestic_citizenship_country_code`), ne jen ručně složená fixture.
     */
    public function testCzechCitizenWithAssignedBirthNumberPassesTheWholeCore(): void
    {
        $snapshot = self::builtSnapshot('PREZEC26', 'CZ', '9152031234', null);

        self::assertSame(
            'verified',
            $snapshot->registrationEligibility['status'],
        );
        self::assertSame(
            'domestic_citizenship_country_code',
            $snapshot->registrationEligibility['basis'],
        );

        $interaction = (new PayrollRegistrationInteractionResolver())->resolve(
            $snapshot,
            [
                'work_started' => false,
                'full_registration_data' => false,
                'pre_registration_accepted' => false,
                'did_not_start' => false,
            ],
        );
        self::assertSame(
            ['PREZEC26', 'limited_pre_registration', 9],
            [
                $interaction->documentType,
                $interaction->interaction,
                $interaction->actionCode,
            ],
        );

        $payload = self::payload($snapshot, $interaction);
        $xml = (new PayrollRegistrationXmlSerializer())->serialize($payload);

        self::assertSame(self::prezecGolden(), $xml);
        (new PayrollRegistrationXmlValidator(
            new PayrollRegistrationSchemaCatalog(),
        ))->validate($payload, $xml);
    }

    /**
     * `client/@bno` je v připnutém PREZEC26 XSD popsané jako
     * „Rodné číslo / EČP (ID 10057)" a jeho `simpleNNType` má délku 9–10,
     * do níž se devítimístné EČP vejde. Stejné pravidlo drží snapshot builder
     * i `private/Mzdy/05-VYSTUPY-A-PODANI.md`, takže core nesmí být přísnější
     * — osoba vedená jen pod EČP musí PREZEC projít.
     */
    public function testEcpAloneIsAValidPrezecIdentifier(): void
    {
        $snapshot = self::builtSnapshot('PREZEC26', 'CZ', null, '123456789');

        self::assertNull($snapshot->identifiers['birth_number']);
        self::assertSame('123456789', $snapshot->identifiers['ecp']);

        $interaction = (new PayrollRegistrationInteractionResolver())->resolve(
            $snapshot,
            [
                'work_started' => false,
                'full_registration_data' => false,
                'pre_registration_accepted' => false,
                'did_not_start' => false,
            ],
        );
        self::assertSame(
            ['PREZEC26', 'limited_pre_registration', 9],
            [
                $interaction->documentType,
                $interaction->interaction,
                $interaction->actionCode,
            ],
        );

        $payload = self::payload($snapshot, $interaction);
        $xml = (new PayrollRegistrationXmlSerializer())->serialize($payload);

        self::assertSame(
            str_replace('bno="9152031234"', 'bno="123456789"', self::prezecGolden()),
            $xml,
        );
        (new PayrollRegistrationXmlValidator(
            new PayrollRegistrationSchemaCatalog(),
        ))->validate($payload, $xml);
    }

    /**
     * Bez identifikátoru nemá PREZEC co do povinného `bno` zapsat. Snapshot
     * builder takový snapshot nepostaví, ale core to musí odmítnout sám.
     */
    public function testPrezecWithoutAnyIdentifierStaysClosed(): void
    {
        $payload = self::payload(
            self::snapshot('CZ', identifiers: [
                'birth_number' => null,
                'ecp' => null,
                'vcp' => null,
                'foreign_tax_identifier' => null,
            ]),
            new PayrollRegistrationInteraction(
                'PREZEC26',
                'limited_pre_registration',
                9,
            ),
        );

        $this->expectCode(
            'registration_prezec_identifier_required',
            static fn () => (new PayrollRegistrationXmlSerializer())
                ->serialize($payload),
        );
        $this->expectCode(
            'registration_prezec_identifier_required',
            static fn () => (new PayrollRegistrationXmlValidator(
                new PayrollRegistrationSchemaCatalog(),
            ))->validate($payload, '<PREZEC/>'),
        );
    }

    /**
     * `predat` je pro P1 povinné. Bez explicitní kontroly by se prázdný řetězec
     * dostal do `new DateTimeImmutable('')`, tedy do dnešního data — okno by se
     * počítalo proti systémovému času a výsledek testu by závisel na dni běhu.
     * Nesmyslné datum navíc vyhodí `DateMalformedStringException` mimo
     * validační kontrakt.
     */
    public function testPrezecP1RejectsMissingOrMalformedStartDateDeterministically(): void
    {
        $validator = new PayrollRegistrationXmlValidator(
            new PayrollRegistrationSchemaCatalog(),
        );
        $interaction = new PayrollRegistrationInteraction(
            'PREZEC26',
            'limited_pre_registration',
            9,
        );

        foreach ([null, '', '2026-13-45', 'zítra'] as $startOn) {
            $payload = self::payload(
                self::snapshot('CZ'),
                $interaction,
                expectedStartOn: $startOn,
            );
            $this->expectCode(
                'registration_prezec_start_date_invalid',
                static fn () => $validator->validate($payload, '<PREZEC/>'),
            );
        }
    }

    public function testCorrectionsCancellationsAndOtherActionsStayClosed(): void
    {
        $serializer = new PayrollRegistrationXmlSerializer();
        $validator = new PayrollRegistrationXmlValidator(
            new PayrollRegistrationSchemaCatalog(),
        );
        // REGZEC25 XSD povoluje act 1..99; A2–A8 smí zastavit jen náš allowlist.
        foreach ([2, 3, 4, 5, 6, 7, 8] as $actionCode) {
            $payload = self::payload(
                self::snapshot('SK'),
                new PayrollRegistrationInteraction(
                    'REGZEC25',
                    'correction',
                    $actionCode,
                ),
                expectedStartOn: null,
                actualStartOn: '2026-08-05',
            );
            $this->expectCode(
                'registration_interaction_unsupported',
                static fn () => $serializer->serialize($payload),
            );
            $this->expectCode(
                'registration_interaction_unsupported',
                static fn () => $validator->validate($payload, '<REGZEC/>'),
            );
        }

        $storno = self::payload(
            self::snapshot('CZ'),
            new PayrollRegistrationInteraction('PREZEC26', 'cancellation', 9),
        );
        $this->expectCode(
            'registration_interaction_unsupported',
            static fn () => $serializer->serialize($storno),
        );
        $this->expectCode(
            'registration_schema_unavailable',
            static fn () => (new PayrollRegistrationSchemaCatalog())
                ->schemaFor('ZREZAM26'),
        );
        self::assertSame(
            ['PREZEC26', 'PREZEC26', 'REGZEC25', 'REGZEC25'],
            array_column(
                PayrollRegistrationInteraction::SUPPORTED,
                'document_type',
            ),
        );
        self::assertSame(
            [9, 10, 1, 1],
            array_column(
                PayrollRegistrationInteraction::SUPPORTED,
                'action_code',
            ),
        );
    }

    /**
     * `PayrollRegistrationXmlPayload` je volně sestavitelný, takže resolver lze
     * obejít. Vazba interakce na zmrazený snapshot (agenda + zmrazená
     * způsobilost) proto nesmí žít jen v resolveru — validátor ji musí vynutit
     * na stejné implementaci, jinak je to jednomístná pojistka bez brány.
     */
    public function testDirectlyBuiltPayloadCannotSkipTheSnapshotBinding(): void
    {
        $validator = new PayrollRegistrationXmlValidator(
            new PayrollRegistrationSchemaCatalog(),
        );
        $prezecP1 = new PayrollRegistrationInteraction(
            'PREZEC26',
            'limited_pre_registration',
            9,
        );

        $agendaMismatch = self::payload(
            self::snapshot('CZ', 'REGZEC25'),
            $prezecP1,
        );
        $this->expectCode(
            'registration_interaction_snapshot_agenda_mismatch',
            static fn () => $validator->validate($agendaMismatch, '<PREZEC/>'),
        );

        $foreignBasis = self::payload(
            self::snapshot('CZ', 'PREZEC26', eligibility: [
                'status' => 'verified',
                'basis' => 'assigned_birth_number',
                'citizenship_country_code' => 'CZ',
            ]),
            $prezecP1,
        );
        $this->expectCode(
            'registration_interaction_eligibility_basis_unsupported',
            static fn () => $validator->validate($foreignBasis, '<PREZEC/>'),
        );
    }

    public function testFrozenSnapshotStillDeclaresTransportUnsupported(): void
    {
        $snapshot = self::builtSnapshot('PREZEC26', 'CZ', '9152031234', null);
        $official = $snapshot->toArray()['official_submission'];

        self::assertIsArray($official);
        self::assertFalse($official['supported']);
        self::assertSame(
            'xml_and_legal_validation_not_implemented',
            $official['reason_code'],
        );
    }

    private static function payload(
        PayrollRegistrationIdentitySnapshot $snapshot,
        PayrollRegistrationInteraction $interaction,
        ?string $expectedStartOn = '2026-08-05',
        ?string $actualStartOn = null,
    ): PayrollRegistrationXmlPayload {
        return new PayrollRegistrationXmlPayload(
            identity: $snapshot,
            interaction: $interaction,
            sequenceNumber: 1,
            formGuid: '12345678-1234-1234-1234-123456789ABC',
            preparedOn: '2026-08-04',
            expectedStartOn: $expectedStartOn,
            actualStartOn: $actualStartOn,
            employerVariableSymbol: '1234567890',
            employerName: 'Syntetický zaměstnavatel s.r.o.',
            csszWorkplaceCode: '110',
        );
    }

    /**
     * @param array{
     *   birth_number:?string,ecp:?string,vcp:?string,
     *   foreign_tax_identifier:?string
     * }|null $identifiers
     * @param array<string,mixed>|null $eligibility
     */
    private static function snapshot(
        string $citizenship,
        ?string $agendaCode = null,
        ?array $identifiers = null,
        ?array $eligibility = null,
    ): PayrollRegistrationIdentitySnapshot {
        $agenda = $agendaCode
            ?? ($citizenship === 'CZ' ? 'PREZEC26' : 'REGZEC25');

        return new PayrollRegistrationIdentitySnapshot(
            scope: [
                'supplier_id' => 11,
                'submission_id' => 21,
                'source_revision_id' => 31,
                'employee_id' => 41,
                'employment_id' => 51,
                'environment' => 'production',
                'agenda_code' => $agenda,
                'effective_on' => '2026-08-04',
            ],
            identity: [
                'first_name' => 'Jana',
                'last_name' => 'Novotná',
                'title_prefix' => 'Ing.',
                'title_suffix' => null,
                'birth_surname' => 'Nováková',
                'birth_date' => '1991-02-03',
                'birth_place' => 'Testov',
                'birth_country_code' => 'CZ',
                'citizenship_country_code' => $citizenship,
                'sex' => 'female',
            ],
            identifiers: $identifiers ?? [
                'birth_number' => '9152031234',
                'ecp' => null,
                'vcp' => null,
                'foreign_tax_identifier' => null,
            ],
            employmentExternalIdentifier: null,
            registrationEligibility: $eligibility ?? ($agenda === 'PREZEC26'
                ? [
                    'status' => 'verified',
                    'basis' => 'domestic_citizenship_country_code',
                    'citizenship_country_code' => $citizenship,
                ]
                : [
                    'status' => 'not_applicable',
                    'basis' => 'agenda_not_prezec',
                ]),
            sourceVersions: [],
        );
    }

    private static function builtSnapshot(
        string $agendaCode,
        string $citizenship,
        ?string $birthNumber,
        ?string $ecp,
    ): PayrollRegistrationIdentitySnapshot {
        $identifierSources = [];
        if ($birthNumber !== null) {
            $identifierSources['birth_number'] = [
                'id' => 121,
                'row_version' => 1,
            ];
        }
        if ($ecp !== null) {
            $identifierSources['ecp'] = ['id' => 122, 'row_version' => 1];
        }

        return (new PayrollRegistrationIdentitySnapshotBuilder())->build(
            [
                'supplier_id' => 11,
                'submission_id' => 21,
                'source_revision_id' => 31,
                'employee_id' => 41,
                'employment_id' => 51,
                'environment' => 'production',
                'agenda_code' => $agendaCode,
                'effective_on' => '2026-08-04',
            ],
            [
                'identity' => [
                    'id' => 111,
                    'employee_id' => 41,
                    'first_name' => 'Jana',
                    'last_name' => 'Novotná',
                    'title_prefix' => 'Ing.',
                    'title_suffix' => null,
                    'birth_surname' => 'Nováková',
                    'birth_date' => '1991-02-03',
                    'birth_place' => 'Testov',
                    'birth_country_code' => 'CZ',
                    'citizenship_country_code' => $citizenship,
                    'sex' => 'female',
                    'effective_from' => '2026-01-01',
                    'effective_to' => null,
                    'row_version' => 2,
                ],
                'identifiers' => [
                    'birth_number' => $birthNumber,
                    'ecp' => $ecp,
                    'vcp' => null,
                    'foreign_tax_identifier' => null,
                ],
                'identifier_sources' => $identifierSources,
                'employment_external_identifier' => null,
                'resolution' => [
                    'person_identity' => 'resolved',
                    'employment_external_id' => 'not_assigned',
                ],
            ],
        );
    }

    /** @param callable():mixed $callback */
    private function expectCode(string $code, callable $callback): void
    {
        try {
            $callback();
            self::fail("Očekávána chyba {$code}.");
        } catch (PayrollRegistrationXmlException $exception) {
            self::assertSame($code, $exception->validationCode);
        }
    }

    private static function prezecGolden(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<PREZEC xmlns="http://schemas.cssz.cz/PREZEC/2026">
  <employees>
    <employee sqnr="1" act="9" idform="12345678-1234-1234-1234-123456789ABC" dat="2026-08-04" predat="2026-08-05">
      <client bno="9152031234">
        <name sur="Novotná" fir="Jana"/>
        <birth nam="Nováková" cit="Testov"/>
        <stat cnt="CZ"/>
      </client>
      <comp vs="1234567890"/>
    </employee>
  </employees>
</PREZEC>
XML;
    }

    private static function regzecGolden(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<REGZEC xmlns="http://schemas.cssz.cz/REGZEC/2025">
  <employees>
    <employee sqnr="1" dep="110" act="1" dat="2026-08-04">
      <client bno="9152031234">
        <name sur="Novotná" fir="Jana" tit="Ing."/>
        <birth dat="1991-02-03" nam="Nováková" cit="Testov" stat="CZ"/>
        <stat cnt="SK"/>
      </client>
      <comp vs="1234567890" nam="Syntetický zaměstnavatel s.r.o."/>
      <job fro="2026-08-05"/>
    </employee>
  </employees>
</REGZEC>
XML;
    }

    private static function prezecP2Golden(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<PREZEC xmlns="http://schemas.cssz.cz/PREZEC/2026">
  <employees>
    <employee sqnr="1" act="10" idform="12345678-1234-1234-1234-123456789ABC" dat="2026-08-04">
      <client bno="9152031234"/>
      <comp vs="1234567890"/>
    </employee>
  </employees>
</PREZEC>
XML;
    }
}
