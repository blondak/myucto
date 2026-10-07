<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission\Registration;

use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationA1SnapshotBuilder;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationIdentitySnapshot;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationInteractionResolver;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationSchemaCatalog;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationXmlException;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationXmlPayload;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationXmlSerializer;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationXmlValidator;
use PHPUnit\Framework\TestCase;

/**
 * Nástup před 1. 7. 2026 (V1).
 *
 * Pravidla pro REGZEC: událost do 31. 3. 2026 neohlášená do té doby se od
 * 1. 4. 2026 hlásí jen přes REGZEC. Cizí programy přihlášky s nástupem od
 * ledna 2026 podávaly a ČSSZ je přijala — aplikace je nesmí blokovat.
 */
final class PayrollRegistrationTransitionalStartTest extends TestCase
{
    private const START = '2026-02-15';

    public function testA1WithFebruaryStartResolvesSerializesAndPassesXsd(): void
    {
        $a1 = (new PayrollRegistrationA1SnapshotBuilder())->build(
            self::source('1', '1'),
            PayrollRegistrationA1SnapshotBuilderTest::identity(),
            self::scope(),
        );
        $snapshot = self::snapshot($a1, 'REGZEC25');

        $interaction = (new PayrollRegistrationInteractionResolver())->resolve(
            $snapshot,
            [
                'work_started' => true,
                'full_registration_data' => true,
                'pre_registration_accepted' => false,
                'did_not_start' => false,
            ],
        );
        self::assertSame(
            ['REGZEC25', 'direct_full_registration', 1],
            [$interaction->documentType, $interaction->interaction, $interaction->actionCode],
        );

        $payload = new PayrollRegistrationXmlPayload(
            identity: $snapshot,
            interaction: $interaction,
            sequenceNumber: 1,
            formGuid: '12345678-1234-1234-1234-123456789ABC',
            preparedOn: '2026-09-26',
            expectedStartOn: null,
            actualStartOn: self::START,
            employerVariableSymbol: '1234567890',
            employerName: 'Syntetický zaměstnavatel s.r.o.',
            csszWorkplaceCode: '110',
        );
        $xml = (new PayrollRegistrationXmlSerializer())->serialize($payload);
        (new PayrollRegistrationXmlValidator(new PayrollRegistrationSchemaCatalog()))
            ->validate($payload, $xml);

        self::assertStringContainsString(' fro="' . self::START . '"', $xml);
        self::assertStringContainsString(' relat="1111"', $xml);
    }

    public function testPrezecBeforeItsWindowStillPointsToFullRegistration(): void
    {
        try {
            (new PayrollRegistrationInteractionResolver())->resolve(
                self::snapshot(null, 'PREZEC26'),
                [
                    'work_started' => false,
                    'full_registration_data' => false,
                    'pre_registration_accepted' => false,
                    'did_not_start' => false,
                ],
            );
            self::fail('PREZEC P1 před 23. 6. 2026 podat nejde.');
        } catch (PayrollRegistrationXmlException $exception) {
            self::assertSame(
                'registration_interaction_before_supported_window',
                $exception->validationCode,
            );
            self::assertStringContainsString('REGZEC A1', $exception->getMessage());
        }
    }

    /**
     * EDV 10223: druh činnosti 10–16 a výkon trestu až od 1. 1. 2026. Zásady
     * REGZEC (specifický postup č. 3) pro dřívější vztahy předepisují fiktivní
     * nástup 1. 1. 2026, takže přihláška se nezamítá, ale hlásí s tímto datem.
     */
    public function testSpecialActivitiesBeforeTwentyTwentySixAreNotRejected(): void
    {
        $source = self::source('11', '1');
        foreach (['source', 'employment'] as $section) {
            $key = $section === 'source' ? 'effective_on' : 'actual_start_on';
            $source[$section][$key] = '2025-12-01';
        }
        $source['employment']['contract_start_on'] = '2025-12-01';
        $scope = ['effective_on' => '2025-12-01'] + self::scope();

        self::assertSame([], (new PayrollRegistrationA1SnapshotBuilder())->problems(
            $source,
            PayrollRegistrationA1SnapshotBuilderTest::identity(),
            $scope,
        ));
    }

    /** @return array<string,mixed> */
    private static function source(string $activity, ?string $detail): array
    {
        $source = PayrollRegistrationA1SnapshotBuilderTest::source($activity, $detail);
        $source['source']['effective_on'] = self::START;
        $source['employment']['actual_start_on'] = self::START;
        $source['employment']['contract_start_on'] = self::START;

        return $source;
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

    private static function snapshot(
        ?\MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationA1Snapshot $a1,
        string $agenda,
    ): PayrollRegistrationIdentitySnapshot {
        return new PayrollRegistrationIdentitySnapshot(
            scope: [
                'supplier_id' => 11,
                'submission_id' => 21,
                'source_revision_id' => null,
                'employee_id' => 41,
                'employment_id' => 51,
                'environment' => 'production',
                'agenda_code' => $agenda,
                'effective_on' => self::START,
            ],
            identity: PayrollRegistrationA1SnapshotBuilderTest::identity(),
            identifiers: [
                'birth_number' => '9152031234',
                'ecp' => null,
                'vcp' => null,
                'foreign_tax_identifier' => null,
            ],
            employmentExternalIdentifier: null,
            registrationEligibility: $agenda === 'PREZEC26'
                ? [
                    'status' => 'verified',
                    'basis' => 'domestic_citizenship_country_code',
                    'citizenship_country_code' => 'CZ',
                ]
                : ['status' => 'not_applicable', 'basis' => 'agenda_not_prezec'],
            sourceVersions: $a1 === null ? [] : ['regzec_a1' => $a1->source],
            regzecA1: $a1,
        );
    }
}
