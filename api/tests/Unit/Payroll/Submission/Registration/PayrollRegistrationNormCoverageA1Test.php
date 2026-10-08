<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission\Registration;

use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationA1Snapshot;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationA1SnapshotBuilder;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationIdentitySnapshot;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationInteraction;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationSchemaCatalog;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationXmlPayload;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationXmlSerializer;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationXmlValidator;
use PHPUnit\Framework\TestCase;

/**
 * Pokrytí norem REGZEC25 (EDV 1.4.0.6) v přihlášce A1: věk, české adresy,
 * zakázané elementy podle varianty a státu, příloh a fiktivní nástup.
 * Zdroj dat je syntetický.
 */
final class PayrollRegistrationNormCoverageA1Test extends TestCase
{
    private const START = '2026-08-05';

    /** REGZEC25-client.birth.dat-06 */
    public function testUnderageEmployeeIsRejectedAtEntryWithoutDppException(): void
    {
        foreach ([['1', '1'], ['T', null]] as [$activity, $detail]) {
            $identity = PayrollRegistrationA1SnapshotBuilderTest::identity();
            $identity['birth_date'] = '2012-08-06';
            $problems = (new PayrollRegistrationA1SnapshotBuilder())->problems(
                PayrollRegistrationA1SnapshotBuilderTest::source($activity, $detail),
                $identity,
                self::scope(),
            );

            self::assertSame(
                'registration_regzec_a1_underage',
                array_column($problems, 'code', 'field')['employment.actual_start_on'] ?? null,
                $activity,
            );
        }
    }

    public function testFourteenthBirthdayOnTheStartDayIsAllowed(): void
    {
        $identity = PayrollRegistrationA1SnapshotBuilderTest::identity();
        $identity['birth_date'] = '2012-08-05';
        $problems = (new PayrollRegistrationA1SnapshotBuilder())->problems(
            PayrollRegistrationA1SnapshotBuilderTest::source('1', '1'),
            $identity,
            self::scope(),
        );

        self::assertSame([], $problems);
    }

    /** REGZEC25-client.adr.pnu-09, adr.num-09, fdr.num-09 */
    public function testCzechAddressNeedsNumericHouseNumberAndValidPostalCode(): void
    {
        foreach (['12a' => 'house_number', '12/3' => 'house_number', '12345' => 'house_number'] as $value => $field) {
            $source = PayrollRegistrationA1SnapshotBuilderTest::source('1', '1');
            $source['permanent_address']['house_number'] = (string) $value;
            $fields = array_column($this->problems($source), 'field');
            self::assertContains('permanent_address.' . $field, $fields, (string) $value);
        }
        foreach (['01234', '81101', '91234', '1234'] as $postalCode) {
            $source = PayrollRegistrationA1SnapshotBuilderTest::source('1', '1');
            $source['permanent_address']['postal_code'] = $postalCode;
            self::assertContains(
                'permanent_address.postal_code',
                array_column($this->problems($source), 'field'),
                $postalCode,
            );
        }
        $source = PayrollRegistrationA1SnapshotBuilderTest::source('1', '1');
        $source['permanent_address']['house_number'] = '1234';
        $source['permanent_address']['postal_code'] = '702 00';
        self::assertSame([], $this->problems($source));
    }

    public function testForeignAddressKeepsGeneralRules(): void
    {
        $source = PayrollRegistrationA1SnapshotBuilderTest::source('1', '1');
        $source['permanent_address']['house_number'] = '12a/3';
        $source['permanent_address']['postal_code'] = '81101';
        $source['permanent_address']['country_code'] = 'SK';
        $source['czech_residence_address'] = self::czechResidence();

        self::assertNotContains(
            'permanent_address.house_number',
            array_column($this->problems($source, self::foreignIdentity()), 'field'),
        );
    }

    public function testCzechResidenceAddressNeedsNumericHouseNumberAndShortOrientation(): void
    {
        $source = self::foreignAbroadSource('1', '1');
        $source['czech_residence_address']['house_number'] = '12b';
        $source['czech_residence_address']['orientation_number'] = '12345';
        $source['czech_residence_address']['postal_code'] = '81101';
        $fields = array_column($this->problems($source, self::foreignIdentity()), 'field');

        self::assertContains('czech_residence_address.house_number', $fields);
        self::assertContains('czech_residence_address.orientation_number', $fields);
        self::assertContains('czech_residence_address.postal_code', $fields);
    }

    /** REGZEC25-client.adr.onum-04, REGZEC25-client.cdr.onum-04 */
    public function testCzechPermanentAndContactAddressAllowFourCharacterOrientationOnly(): void
    {
        $source = PayrollRegistrationA1SnapshotBuilderTest::source('1', '1');
        $source['permanent_address']['orientation_number'] = '12345';
        $source['contact_address'] = [
            'street' => 'Testovací',
            'house_number' => '7',
            'orientation_number' => '1234a',
            'city' => 'Praha',
            'postal_code' => '11000',
            'country_code' => 'CZ',
            'ruian_point' => null,
        ];
        $problems = $this->problems($source, PayrollRegistrationA1SnapshotBuilderTest::identity());
        $byField = array_column($problems, 'message_key', 'field');

        self::assertSame('orientation_number_cz', $byField['permanent_address.orientation_number'] ?? null);
        self::assertSame('orientation_number_cz', $byField['contact_address.orientation_number'] ?? null);

        $source['permanent_address']['orientation_number'] = '12a';
        $source['contact_address']['orientation_number'] = '1234';
        $source['contact_address']['country_code'] = 'SK';
        $source['contact_address']['postal_code'] = '81101';
        $source['contact_address']['orientation_number'] = '123456789012';
        $fields = array_column(
            $this->problems($source, PayrollRegistrationA1SnapshotBuilderTest::identity()),
            'field',
        );

        self::assertNotContains('permanent_address.orientation_number', $fields);
        self::assertNotContains('contact_address.orientation_number', $fields);
    }

    /** REGZEC25-client.fdr.num-05 */
    public function testCzechResidenceIsForbiddenForCzechPermanentResidence(): void
    {
        $source = PayrollRegistrationA1SnapshotBuilderTest::source('1', '1');
        $source['czech_residence_address'] = self::czechResidence();
        $a1 = (new PayrollRegistrationA1SnapshotBuilder())->build(
            $source,
            PayrollRegistrationA1SnapshotBuilderTest::identity(),
            self::scope(),
        );

        self::assertNull($a1->czechResidenceAddress);
        self::assertStringNotContainsString('<fdr', self::serialize($a1));
    }

    /** REGZEC25-client.vcp-03: VČP je u A1-10 zakázané, u A1-OST se pošle. */
    public function testVariableInsuredNumberIsSentOnlyOutsideVariant10(): void
    {
        $identifiers = [
            'birth_number' => null,
            'ecp' => null,
            'vcp' => '612345678',
            'foreign_tax_identifier' => null,
        ];
        $variant10 = (new PayrollRegistrationA1SnapshotBuilder())->build(
            self::foreignAbroadSource('10', null),
            self::foreignIdentity(),
            self::scope(),
        );
        self::assertStringNotContainsString(
            'vcp=',
            self::serialize($variant10, self::foreignIdentity(), self::START, $identifiers),
        );

        $ordinary = (new PayrollRegistrationA1SnapshotBuilder())->build(
            self::foreignAbroadSource('1', '1'),
            self::foreignIdentity(),
            self::scope(),
        );
        self::assertStringContainsString(
            'vcp="612345678"',
            self::serialize($ordinary, self::foreignIdentity(), self::START, $identifiers),
        );
    }

    public function testCzechResidenceIsForbiddenForVariant10(): void
    {
        $source = self::foreignAbroadSource('10', null);
        $a1 = (new PayrollRegistrationA1SnapshotBuilder())->build(
            $source,
            self::foreignIdentity(),
            self::scope(),
        );

        self::assertNull($a1->czechResidenceAddress);
        self::assertStringNotContainsString('<fdr', self::serialize($a1));
    }

    public function testCzechResidenceStaysForForeignPermanentResidence(): void
    {
        $a1 = (new PayrollRegistrationA1SnapshotBuilder())->build(
            self::foreignAbroadSource('11', '1'),
            self::foreignIdentity(),
            self::scope(),
        );

        self::assertNotNull($a1->czechResidenceAddress);
        self::assertStringContainsString('<fdr', self::serialize($a1));
    }

    /** REGZEC25-client.proofid-02 (a nocitizen-02) */
    public function testVariant10NeitherRequiresNorSendsProofAndLabourMarketGroups(): void
    {
        $source = PayrollRegistrationA1SnapshotBuilderTest::source('10', null);
        $source['proof_identity'] = null;
        $source['foreign_worker'] = null;
        $a1 = (new PayrollRegistrationA1SnapshotBuilder())->build(
            $source,
            self::foreignIdentity(),
            self::scope(),
        );
        self::assertNull($a1->proofIdentity);
        self::assertNull($a1->foreignWorker);

        $source['proof_identity'] = [
            'type_code' => 'P',
            'number' => 'SYN000001',
            'foreign_issuer' => null,
            'country_code' => 'SK',
        ];
        $source['foreign_worker'] = [
            'free_access' => true,
            'free_access_reason_code' => '1',
            'permit_type_code' => null,
            'issuing_labour_office_code' => null,
            'permit_identifier' => null,
            'permit_from' => null,
            'permit_to' => null,
        ];
        $a1 = (new PayrollRegistrationA1SnapshotBuilder())->build(
            $source,
            self::foreignIdentity(),
            self::scope(),
        );
        $xml = self::serialize($a1, self::foreignIdentity());

        self::assertNull($a1->proofIdentity);
        self::assertNull($a1->foreignWorker);
        self::assertStringNotContainsString('<proofid', $xml);
        self::assertStringNotContainsString('<nocitizen', $xml);
    }

    public function testOtherVariantsStillRequireProofAndLabourMarketForForeigners(): void
    {
        $source = PayrollRegistrationA1SnapshotBuilderTest::source('11', '1');
        $source['proof_identity'] = null;
        $source['foreign_worker'] = null;
        $codes = array_column(
            $this->problems($source, self::foreignIdentity()),
            'code',
            'field',
        );

        self::assertSame('registration_regzec_a1_foreign_data_missing', $codes['proof_identity'] ?? null);
    }

    /** REGZEC25-client.rdr.cnt-08 */
    public function testResidenceAddressCountryMustMatchTaxResidencyCountry(): void
    {
        $source = self::foreignAbroadSource('1', '1');
        $source['tax_residency']['residence_address']['country_code'] = 'DE';
        $fields = array_column($this->problems($source, self::foreignIdentity()), 'field');

        self::assertContains('tax_residency.residence_address.country_code', $fields);
    }

    public function testMatchingResidenceAddressCountryIsAccepted(): void
    {
        $source = self::foreignAbroadSource('1', '1');
        $fields = array_column($this->problems($source, self::foreignIdentity()), 'field');

        self::assertNotContains('tax_residency.residence_address.country_code', $fields);
    }

    /** REGZEC25-client.rdr.num-04 */
    public function testResidenceAddressIsDroppedForCzechTaxResidency(): void
    {
        $source = PayrollRegistrationA1SnapshotBuilderTest::source('1', '1');
        $source['tax_residency']['residence_address'] = [
            'street' => null,
            'house_number' => '7',
            'orientation_number' => null,
            'city' => 'Bratislava',
            'postal_code' => '81101',
            'country_code' => 'SK',
            'ruian_point' => null,
        ];
        $a1 = (new PayrollRegistrationA1SnapshotBuilder())->build(
            $source,
            PayrollRegistrationA1SnapshotBuilderTest::identity(),
            self::scope(),
        );

        self::assertNull($a1->taxResidency['residence_address']);
        self::assertStringNotContainsString('<rdr', self::serialize($a1));
    }

    /** REGZEC25-job.sme-05 */
    public function testSmallScaleEmploymentIsRejectedForAgreementsToPerformWork(): void
    {
        foreach (['T', 'ZA', 'ZC'] as $activity) {
            $source = PayrollRegistrationA1SnapshotBuilderTest::source($activity, null);
            $source['employment']['small_scale'] = true;
            $codes = array_column($this->problems($source), 'code', 'field');
            self::assertSame(
                'registration_regzec_a1_field_value_invalid',
                $codes['employment.small_scale'] ?? null,
                $activity,
            );
        }
        $source = PayrollRegistrationA1SnapshotBuilderTest::source('T', null);
        $source['employment']['small_scale'] = false;
        self::assertSame([], $this->problems($source));
        $source = PayrollRegistrationA1SnapshotBuilderTest::source('1', '1');
        $source['employment']['small_scale'] = true;
        self::assertSame([], $this->problems($source));
    }

    /** REGZEC25-attachs.attach-02 */
    public function testAttachmentsAreCheckedForExtensionNameAndSizes(): void
    {
        $small = base64_encode('syntetický text');
        $cases = [
            'extension' => [['name' => 'zadost.exe', 'data_base64' => $small]],
            'no extension' => [['name' => 'zadost', 'data_base64' => $small]],
            'duplicate' => [
                ['name' => 'a.pdf', 'data_base64' => $small],
                ['name' => 'A.PDF', 'data_base64' => $small],
            ],
            'file size' => [[
                'name' => 'velky.pdf',
                'data_base64' => base64_encode(str_repeat('x', 2 * 1024 * 1024 + 1)),
            ]],
            'total size' => [
                ['name' => 'a.pdf', 'data_base64' => base64_encode(str_repeat('x', 2 * 1024 * 1024))],
                ['name' => 'b.pdf', 'data_base64' => base64_encode(str_repeat('x', 2 * 1024 * 1024))],
                ['name' => 'c.txt', 'data_base64' => $small],
            ],
        ];
        foreach ($cases as $label => $attachments) {
            $source = PayrollRegistrationA1SnapshotBuilderTest::source('1', '1');
            $source['attachments'] = array_map(
                static fn (array $a): array => $a + ['description' => null],
                $attachments,
            );
            self::assertContains(
                'attachments',
                array_column($this->problems($source), 'field'),
                $label,
            );
        }
        $source = PayrollRegistrationA1SnapshotBuilderTest::source('1', '1');
        $source['attachments'] = [
            ['name' => 'a.PDF', 'description' => null, 'data_base64' => base64_encode(str_repeat('x', 2 * 1024 * 1024))],
            ['name' => 'b.docx', 'description' => null, 'data_base64' => base64_encode(str_repeat('x', 2 * 1024 * 1024))],
        ];
        self::assertSame([], $this->problems($source));
    }

    /** REGZEC25-PROC.sp3-dates-01 */
    public function testSpecialActivitiesBeforeTwentyTwentySixAreReportedWithFictiveStart(): void
    {
        $start = '2025-12-01';
        $source = PayrollRegistrationA1SnapshotBuilderTest::source('11', '1');
        $source['source']['effective_on'] = $start;
        $source['employment']['actual_start_on'] = $start;
        $source['employment']['contract_start_on'] = $start;
        $scope = ['effective_on' => $start] + self::scope();

        $a1 = (new PayrollRegistrationA1SnapshotBuilder())->build(
            $source,
            PayrollRegistrationA1SnapshotBuilderTest::identity(),
            $scope,
        );
        $xml = self::serialize($a1, null, $start);

        self::assertSame($start, $a1->employment['actual_start_on']);
        self::assertStringContainsString(' fro="2026-01-01"', $xml);
        self::assertStringNotContainsString(' fro="' . $start . '"', $xml);
    }

    public function testOrdinaryActivityKeepsTheRealStartBeforeTwentyTwentySix(): void
    {
        $start = '2025-12-01';
        $source = PayrollRegistrationA1SnapshotBuilderTest::source('1', '1');
        $source['source']['effective_on'] = $start;
        $source['employment']['actual_start_on'] = $start;
        $source['employment']['contract_start_on'] = $start;
        $a1 = (new PayrollRegistrationA1SnapshotBuilder())->build(
            $source,
            PayrollRegistrationA1SnapshotBuilderTest::identity(),
            ['effective_on' => $start] + self::scope(),
        );

        self::assertStringContainsString(' fro="' . $start . '"', self::serialize($a1, null, $start));
    }

    /**
     * @param array<string,mixed> $source
     * @param array<string,mixed>|null $identity
     * @return list<array{field:?string,code:string,message:string,message_key:?string,params:array<string,int|string>}>
     */
    private function problems(array $source, ?array $identity = null): array
    {
        return (new PayrollRegistrationA1SnapshotBuilder())->problems(
            $source,
            $identity ?? PayrollRegistrationA1SnapshotBuilderTest::identity(),
            self::scope(),
        );
    }

    /** @return array<string,mixed> */
    private static function czechResidence(): array
    {
        return [
            'street' => 'Testovací',
            'house_number' => '15',
            'orientation_number' => '2',
            'city' => 'Testov',
            'postal_code' => '60200',
            'ruian_point' => null,
        ];
    }

    /** Cizinec s trvalým pobytem na Slovensku, daňová rezidence SK s adresou. */
    private static function foreignAbroadSource(string $activity, ?string $detail): array
    {
        $source = PayrollRegistrationA1SnapshotBuilderTest::source($activity, $detail);
        $source['permanent_address'] = [
            'street' => 'Testovacia',
            'house_number' => '7',
            'orientation_number' => null,
            'city' => 'Bratislava',
            'postal_code' => '81101',
            'country_code' => 'SK',
            'ruian_point' => null,
        ];
        $source['czech_residence_address'] = self::czechResidence();
        $source['employment']['expected_workplaces'] = 'Sídlo zaměstnavatele';
        $source['employment']['required_education_code'] = 'T';
        $source['tax_residency'] = [
            'country_code' => 'SK',
            'identifier_type' => 'D',
            'identifier' => 'SK123456789',
            'residence_address' => [
                'street' => 'Testovacia',
                'house_number' => '7',
                'orientation_number' => null,
                'city' => 'Bratislava',
                'postal_code' => '81101',
                'country_code' => 'SK',
                'ruian_point' => null,
            ],
        ];
        $source['proof_identity'] = [
            'type_code' => 'P',
            'number' => 'SYN000001',
            'foreign_issuer' => 'Municipal office, Testov',
            'country_code' => 'SK',
        ];
        $source['foreign_worker'] = [
            'free_access' => true,
            'free_access_reason_code' => '1',
            'permit_type_code' => null,
            'issuing_labour_office_code' => null,
            'permit_identifier' => null,
            'permit_from' => null,
            'permit_to' => null,
        ];

        return $source;
    }

    /** @return array<string,mixed> */
    private static function foreignIdentity(): array
    {
        $identity = PayrollRegistrationA1SnapshotBuilderTest::identity();
        $identity['citizenship_country_code'] = 'SK';
        $identity['birth_country_code'] = 'SK';

        return $identity;
    }

    /** @return array<string,mixed> */
    private static function scope(): array
    {
        return [
            'supplier_id' => 11,
            'employee_id' => 41,
            'employment_id' => 51,
            'effective_on' => self::START,
        ];
    }

    /**
     * @param array<string,mixed>|null $identity
     * @param array<string,?string>|null $identifiers
     */
    private static function serialize(
        PayrollRegistrationA1Snapshot $a1,
        ?array $identity = null,
        string $start = self::START,
        ?array $identifiers = null,
    ): string {
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
                    'effective_on' => $start,
                ],
                identity: $identity ?? PayrollRegistrationA1SnapshotBuilderTest::identity(),
                identifiers: $identifiers ?? [
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
            interaction: new PayrollRegistrationInteraction(
                'REGZEC25',
                'direct_full_registration',
                1,
            ),
            sequenceNumber: 1,
            formGuid: '12345678-1234-1234-1234-123456789ABC',
            preparedOn: '2026-09-26',
            expectedStartOn: null,
            actualStartOn: $start,
            employerVariableSymbol: '1100000007',
            employerName: 'Syntetický zaměstnavatel s.r.o.',
            csszWorkplaceCode: '110',
        );
        $xml = (new PayrollRegistrationXmlSerializer())->serialize($payload);
        (new PayrollRegistrationXmlValidator(new PayrollRegistrationSchemaCatalog()))
            ->validate($payload, $xml);

        return $xml;
    }
}
