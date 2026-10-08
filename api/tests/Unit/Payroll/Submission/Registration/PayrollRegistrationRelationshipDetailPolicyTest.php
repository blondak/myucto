<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission\Registration;

use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationA1SnapshotBuilder;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationIdentitySnapshot;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationInteraction;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationRelationshipDetailPolicy;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationSchemaCatalog;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationXmlPayload;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationXmlSerializer;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationXmlValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Bližší určení PPV (10502) podle EDV REGZEC 1.4.0.6: povinné u A1–A4 OST
 * i SPEC, tedy i u dohod, kde ho evidence nevede. Cizí mzdové programy
 * posílají u DPP a DPČ `relDetail="1"` a ČSSZ to přijímá.
 */
final class PayrollRegistrationRelationshipDetailPolicyTest extends TestCase
{
    /** @return iterable<string,array{string,?string,?string}> */
    public static function regzecValues(): iterable
    {
        yield 'DPP bez hodnoty v evidenci' => ['T', null, '1'];
        yield 'DPP s hodnotou 1' => ['T', '1', '1'];
        yield 'DPČ bez hodnoty v evidenci' => ['A', null, '1'];
        yield 'příslušník celní správy' => ['15', null, '1'];
        yield 'prokurista' => ['P', '1', '1'];
        yield 'pracovní poměr, výkon trestu' => ['1', '2', '2'];
        yield 'pracovní poměr, specifická skupina' => ['1', '3', '3'];
        yield 'druh činnosti 10' => ['10', null, null];
    }

    #[DataProvider('regzecValues')]
    public function testRegzecValueFollowsEdv(
        string $activity,
        ?string $stored,
        ?string $expected,
    ): void {
        self::assertSame(
            $expected,
            PayrollRegistrationRelationshipDetailPolicy::requireForActivity($activity, $stored),
        );
    }

    public function testEvidenceStillKeepsAgreementsWithoutDetail(): void
    {
        self::assertNull(
            PayrollRegistrationRelationshipDetailPolicy::requireForEvidence('T', null),
        );
        $this->expectException(\InvalidArgumentException::class);
        PayrollRegistrationRelationshipDetailPolicy::requireForEvidence('T', '1');
    }

    public function testOtherValueForAgreementIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        PayrollRegistrationRelationshipDetailPolicy::requireForActivity('T', '2');
    }

    /**
     * Číselník: 1 = žádné, 2 = výkon trestu, 3 = specifická skupina. Hláška
     * dřív tvrdila, že 2 je DPP a 3 DPČ — účetní by podle ní vybrala špatně.
     */
    public function testInvalidValueMessageUsesTheCodebookMeaning(): void
    {
        try {
            PayrollRegistrationRelationshipDetailPolicy::requireForActivity('1', '4');
            self::fail('Hodnota 4 není v číselníku.');
        } catch (\InvalidArgumentException $exception) {
            $message = $exception->getMessage();
            self::assertStringContainsString('1 (žádné)', $message);
            self::assertStringContainsString('2 (výkon trestu', $message);
            self::assertStringContainsString('3 (pracovní vztah specifické skupiny)', $message);
            self::assertStringNotContainsString('dohoda o provedení', $message);
            self::assertStringNotContainsString('pracovní poměr)', $message);
        }
    }

    public function testDppA1CarriesRelationshipDetailOneAndPassesXsd(): void
    {
        $source = PayrollRegistrationA1SnapshotBuilderTest::source('T', null);
        $a1 = (new PayrollRegistrationA1SnapshotBuilder())->build(
            $source,
            PayrollRegistrationA1SnapshotBuilderTest::identity(),
            ['supplier_id' => 11, 'employee_id' => 41, 'employment_id' => 51, 'effective_on' => '2026-08-05'],
        );
        self::assertSame('1', $a1->employment['relationship_detail_code']);

        $payload = new PayrollRegistrationXmlPayload(
            identity: new PayrollRegistrationIdentitySnapshot(
                scope: [
                    'supplier_id' => 11,
                    'submission_id' => 21,
                    'source_revision_id' => null,
                    'employee_id' => 41,
                    'employment_id' => 51,
                    'environment' => 'production',
                    'agenda_code' => 'REGZEC25',
                    'effective_on' => '2026-08-05',
                ],
                identity: PayrollRegistrationA1SnapshotBuilderTest::identity(),
                identifiers: [
                    'birth_number' => '9152031234',
                    'ecp' => null,
                    'vcp' => null,
                    'foreign_tax_identifier' => null,
                ],
                employmentExternalIdentifier: null,
                registrationEligibility: [
                    'status' => 'not_applicable',
                    'basis' => 'agenda_not_prezec',
                ],
                sourceVersions: ['regzec_a1' => $a1->source],
                regzecA1: $a1,
            ),
            interaction: new PayrollRegistrationInteraction('REGZEC25', 'direct_full_registration', 1),
            sequenceNumber: 1,
            formGuid: '12345678-1234-1234-1234-123456789ABC',
            preparedOn: '2026-08-04',
            expectedStartOn: null,
            actualStartOn: '2026-08-05',
            employerVariableSymbol: '1100000007',
            employerName: 'Syntetický zaměstnavatel s.r.o.',
            csszWorkplaceCode: '110',
        );
        $xml = (new PayrollRegistrationXmlSerializer())->serialize($payload);
        (new PayrollRegistrationXmlValidator(new PayrollRegistrationSchemaCatalog()))
            ->validate($payload, $xml);

        self::assertStringContainsString(' rel="T"', $xml);
        self::assertStringContainsString(' relDetail="1"', $xml);
    }

    public function testDppTerminationCarriesRelationshipDetailEvenFromOlderEvent(): void
    {
        $event = [
            'schema_reference' => 'payroll-registration-event-snapshot.v1',
            'supplier_id' => 11,
            'employee_id' => 41,
            'employment_id' => 51,
            'environment' => 'production',
            'interaction' => 'termination',
            'action_code' => 2,
            'effective_on' => '2026-08-04',
            'notification_trigger_on' => '2026-08-04',
            'person_external_identifier' => ['id' => 61, 'row_version' => 1, 'value' => '1000000001'],
            'employment_external_identifier' => ['id' => 71, 'row_version' => 1, 'value' => '200000000000000000002'],
            'employer' => [
                'variable_symbol' => '1100000007',
                'name' => 'Syntetický zaměstnavatel s.r.o.',
                'workplace_code' => '110',
            ],
            'data' => [
                'end_on' => '2026-08-04',
                'activity_code' => 'T',
                'relationship_detail_code' => null,
                'ended_by_death' => false,
                'unemployment' => null,
            ],
            'source' => ['kind' => 'synthetic', 'reference' => 'synthetic:termination'],
        ];
        $payload = new PayrollRegistrationXmlPayload(
            identity: new PayrollRegistrationIdentitySnapshot(
                scope: [
                    'supplier_id' => 11,
                    'submission_id' => 21,
                    'source_revision_id' => 31,
                    'employee_id' => 41,
                    'employment_id' => 51,
                    'environment' => 'production',
                    'agenda_code' => 'REGZEC25',
                    'effective_on' => '2026-08-04',
                ],
                identity: PayrollRegistrationA1SnapshotBuilderTest::identity() + ['title_suffix' => null],
                identifiers: [
                    'birth_number' => '9152031234',
                    'ecp' => null,
                    'vcp' => null,
                    'foreign_tax_identifier' => null,
                ],
                employmentExternalIdentifier: null,
                registrationEligibility: ['status' => 'not_applicable', 'basis' => 'agenda_not_prezec'],
                sourceVersions: [],
            ),
            interaction: new PayrollRegistrationInteraction('REGZEC25', 'termination', 2),
            sequenceNumber: 1,
            formGuid: '12345678-1234-1234-1234-123456789ABC',
            preparedOn: '2026-08-04',
            expectedStartOn: null,
            actualStartOn: null,
            employerVariableSymbol: '1100000007',
            employerName: 'Syntetický zaměstnavatel s.r.o.',
            csszWorkplaceCode: '110',
            eventSnapshot: $event,
        );
        $xml = (new PayrollRegistrationXmlSerializer())->serialize($payload);
        (new PayrollRegistrationXmlValidator(new PayrollRegistrationSchemaCatalog()))
            ->validate($payload, $xml);

        self::assertStringContainsString(' rel="T"', $xml);
        self::assertStringContainsString(' relDetail="1"', $xml);
    }
}
