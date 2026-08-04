<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll;

use MyInvoice\Service\Payroll\PayrollPersonStatutoryEvidenceValidator;
use PHPUnit\Framework\TestCase;

final class PayrollPersonStatutoryEvidenceValidatorTest extends TestCase
{
    private PayrollPersonStatutoryEvidenceValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new PayrollPersonStatutoryEvidenceValidator();
    }

    public function testNormalizesCompleteEffectiveSnapshot(): void
    {
        $snapshot = $this->validator->normalize(42, '2026-06-30', $this->completeRaw());

        self::assertSame('payroll-person-statutory-evidence.v1', $snapshot['schema_version']);
        self::assertSame(42, $snapshot['employee_id']);
        self::assertSame('111', $snapshot['health']['coverage']['insurer_code']);
        self::assertSame(
            'other-employer:test',
            $snapshot['health']['other_employer_bases'][0]['employer_reference'],
        );
        self::assertSame('signed', $snapshot['income_tax']['declaration']['status']);
        self::assertSame('child:test-1', $snapshot['income_tax']['child_claims'][0]['child_reference']);
        self::assertSame(
            'verified',
            $snapshot['social']['working_pensioner_discount']['status'],
        );
    }

    public function testRejectsOverlappingSingularAndScopedIntervals(): void
    {
        $raw = $this->completeRaw();
        $raw['health']['coverages'][] = [
            ...$raw['health']['coverages'][0],
            'id' => 2,
            'effective_from' => '2026-06-01',
        ];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('překrývá');
        $this->validator->normalize(42, '2026-06-30', $raw);
    }

    public function testRejectsVerifiedEvidenceWithoutCanonicalReference(): void
    {
        $raw = $this->completeRaw();
        $raw['income_tax']['credit_claims'][0]['evidence_reference'] = 'neplatná reference';

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('evidence_reference');
        $this->validator->normalize(42, '2026-06-30', $raw);
    }

    public function testRejectsForeignJurisdictionWithoutCountryAndA1Evidence(): void
    {
        $raw = $this->completeRaw();
        $raw['social']['jurisdictions'][0] = [
            ...$raw['social']['jurisdictions'][0],
            'jurisdiction' => 'foreign_regime_verified',
            'foreign_country_code' => null,
            'a1_status' => 'verified',
            'a1_certificate_reference' => null,
        ];

        $this->expectException(\InvalidArgumentException::class);
        $this->validator->normalize(42, '2026-06-30', $raw);
    }

    public function testMissingEvidenceRemainsExplicitlyAbsent(): void
    {
        $snapshot = $this->validator->normalize(42, '2026-06-30', [
            'health' => [
                'coverages' => [],
                'minimum_reductions' => [],
                'month_evidence' => [],
                'other_employer_bases' => [],
            ],
            'income_tax' => [
                'declarations' => [],
                'residences' => [],
                'credit_claims' => [],
                'child_claims' => [],
            ],
            'social' => [
                'jurisdictions' => [],
                'discount_claims' => [],
            ],
        ]);

        self::assertNull($snapshot['health']['coverage']);
        self::assertNull($snapshot['income_tax']['declaration']);
        self::assertNull($snapshot['income_tax']['residence']);
        self::assertNull($snapshot['social']['jurisdiction']);
        self::assertNull($snapshot['social']['working_pensioner_discount']);
    }

    public function testKeepsHealthReductionIntersectingMonthButUsesMonthStartForTax(): void
    {
        $raw = $this->completeRaw();
        $raw['health']['minimum_reductions'][0]['effective_to'] = '2026-06-15';
        $raw['income_tax']['declarations'][0]['effective_from'] = '2026-06-15';

        $snapshot = $this->validator->normalize(42, '2026-06-30', $raw);

        self::assertCount(1, $snapshot['health']['minimum_reductions']);
        self::assertNull($snapshot['income_tax']['declaration']);
    }

    /** @return array<string,mixed> */
    private function completeRaw(): array
    {
        return [
            'health' => [
                'coverages' => [[
                    'id' => 1,
                    'jurisdiction' => 'czech_regime_verified',
                    'foreign_country_code' => null,
                    'jurisdiction_evidence_reference' => null,
                    'insurer_status' => 'verified',
                    'insurer_code' => '111',
                    'insurer_evidence_reference' => 'document:health-insurer',
                    'effective_from' => '2026-01-01',
                    'effective_to' => null,
                    'row_version' => 1,
                ]],
                'minimum_reductions' => [[
                    'id' => 1,
                    'reason' => 'state_insured',
                    'evidence_reference' => 'document:state-insured',
                    'effective_from' => '2026-01-01',
                    'effective_to' => null,
                    'row_version' => 1,
                ]],
                'month_evidence' => [[
                    'id' => 1,
                    'period_start' => '2026-06-01',
                    'top_up_responsibility' => 'employee',
                    'top_up_responsibility_evidence_reference' => null,
                    'selected_top_up_employer_reference' => 'other-employer:test',
                    'selected_top_up_employer_evidence_reference' => 'document:top-up-selection',
                    'row_version' => 1,
                ]],
                'other_employer_bases' => [[
                    'id' => 1,
                    'period_start' => '2026-06-01',
                    'employer_reference' => 'other-employer:test',
                    'assessment_base_minor_units' => 3000000,
                    'employment_from' => '2026-01-01',
                    'employment_to' => null,
                    'evidence_reference' => 'document:other-employer',
                    'row_version' => 1,
                ]],
            ],
            'income_tax' => [
                'declarations' => [[
                    'id' => 1,
                    'status' => 'signed',
                    'effective_from' => '2026-01-01',
                    'effective_to' => null,
                    'evidence_reference' => 'document:tax-declaration',
                    'row_version' => 1,
                ]],
                'residences' => [[
                    'id' => 1,
                    'residence' => 'czech-resident',
                    'country_code' => 'CZ',
                    'effective_from' => '2026-01-01',
                    'effective_to' => null,
                    'evidence_reference' => 'document:tax-residence',
                    'row_version' => 1,
                ]],
                'credit_claims' => [[
                    'id' => 1,
                    'credit_kind' => 'taxpayer',
                    'evidence_status' => 'verified',
                    'effective_from' => '2026-01-01',
                    'effective_to' => null,
                    'evidence_reference' => 'document:tax-credit',
                    'row_version' => 1,
                ]],
                'child_claims' => [[
                    'id' => 1,
                    'child_reference' => 'child:test-1',
                    'child_order' => 1,
                    'ztp_p' => false,
                    'evidence_status' => 'verified',
                    'shared_household_confirmed' => true,
                    'other_claimant_excluded' => true,
                    'effective_from' => '2026-01-01',
                    'effective_to' => null,
                    'evidence_reference' => 'document:child-claim',
                    'row_version' => 1,
                ]],
            ],
            'social' => [
                'jurisdictions' => [[
                    'id' => 1,
                    'jurisdiction' => 'czech_regime_verified',
                    'foreign_country_code' => null,
                    'jurisdiction_evidence_reference' => null,
                    'a1_status' => 'not_applicable',
                    'a1_certificate_reference' => null,
                    'a1_valid_until' => null,
                    'effective_from' => '2026-01-01',
                    'effective_to' => null,
                    'row_version' => 1,
                ]],
                'discount_claims' => [[
                    'id' => 1,
                    'status' => 'verified',
                    'effective_from' => '2026-01-01',
                    'effective_to' => null,
                    'evidence_reference' => 'document:working-pensioner',
                    'row_version' => 1,
                ]],
            ],
        ];
    }
}
