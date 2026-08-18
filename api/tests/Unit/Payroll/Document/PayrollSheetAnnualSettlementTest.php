<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Document;

use MyInvoice\Service\Payroll\Document\PayrollSheetDocumentData;
use MyInvoice\Service\Payroll\Document\PayrollSheetMonth;
use MyInvoice\Service\Payroll\Document\PayrollSheetPdfRenderer;
use MyInvoice\Service\Payroll\Document\PayrollSheetSnapshotBuilder;
use PHPUnit\Framework\TestCase;

/**
 * § 38j odst. 2 písm. h) ZDP — mzdový list musí obsahovat „údaje o výpočtu daně
 * a provedeném ročním zúčtování záloh a daňového zvýhodnění".
 *
 * Stav se dřív zapisoval natvrdo jako „neprovedeno", takže větev „schváleno"
 * v šabloně nešlo dosáhnout a údaje o výpočtu na dokladu nebyly vůbec. Hodnoty
 * se nepřepočítávají: přebírají se ze zmrazené revize ročního zúčtování, na
 * kterou doklad odkazuje jejím otiskem.
 */
final class PayrollSheetAnnualSettlementTest extends TestCase
{
    public function testApprovedSettlementCarriesTheTaxComputation(): void
    {
        $document = $this->hydrate(
            PayrollSheetSnapshotBuilder::SCHEMA_VERSION,
            $this->settlement(),
        );

        self::assertSame(
            PayrollSheetDocumentData::ANNUAL_SETTLEMENT_APPROVED,
            $document->annualSettlementStatus,
        );
        self::assertIsArray($document->annualSettlement);
        self::assertSame('2026-03-20', $document->annualSettlement['settled_on']);
        self::assertSame(360_000_00, $document->annualSettlement['rounded_tax_base_minor_units']);
        self::assertSame(1_820_00, $document->annualSettlement['child_entitlement_minor_units']);
        self::assertSame(12_000_00, $document->annualSettlement['settlement_difference_minor_units']);
        self::assertSame('overpayment', $document->annualSettlement['outcome']);
        self::assertSame(
            $document->annualSettlement,
            $document->toTemplateData()['annual_settlement'],
        );
    }

    /**
     * Zúčtování se neprovedlo. Prázdná buňka by neřekla nic — doklad proto
     * uvádí doložený stav podmínek § 38ch odst. 1 a 3 zmrazený v revizi.
     */
    public function testNotPerformedSettlementNamesTheEvidencedObstacle(): void
    {
        $document = $this->hydrate(
            PayrollSheetSnapshotBuilder::SCHEMA_VERSION,
            null,
            [
                'request_status' => 'requested',
                'prior_employers' => 'missing',
                'filing_obligation' => 'unknown',
                'annual_claims' => 'unknown',
            ],
        );

        self::assertSame(
            PayrollSheetDocumentData::ANNUAL_SETTLEMENT_NOT_PERFORMED,
            $document->annualSettlementStatus,
        );
        self::assertNull($document->annualSettlement);
        self::assertSame(
            'missing',
            $document->toTemplateData()['annual_settlement_evidence']['prior_employers'],
        );
    }

    /**
     * Starší mapování stav ročního zúčtování nezjišťovalo. Vydat znovu slovo
     * „neprovedeno" by znamenalo tvrdit něco, co revize nikdy neposoudila.
     */
    public function testOlderMappingReportsTheStatusAsNotRecorded(): void
    {
        foreach (['payroll-sheet-document.v1', 'payroll-sheet-document.v2'] as $version) {
            $document = $this->hydrate($version, null);

            self::assertSame(
                PayrollSheetDocumentData::ANNUAL_SETTLEMENT_NOT_RECORDED,
                $document->annualSettlementStatus,
                $version,
            );
            self::assertNull($document->annualSettlement);
            self::assertNull($document->annualSettlementEvidence);
        }
    }

    public function testStatusAndComputationMustAgree(): void
    {
        $this->expectExceptionMessage(
            'Stav ročního zúčtování nesouhlasí s údaji o jeho výpočtu.',
        );
        $this->document(
            PayrollSheetDocumentData::ANNUAL_SETTLEMENT_APPROVED,
            null,
        );
    }

    public function testApprovedSettlementMustCarryEveryRequiredField(): void
    {
        $settlement = $this->settlement();
        unset($settlement['payable_minor_units']);

        $this->expectExceptionMessage(
            'Údaje o ročním zúčtování neobsahují payable_minor_units.',
        );
        $this->document(
            PayrollSheetDocumentData::ANNUAL_SETTLEMENT_APPROVED,
            $settlement,
        );
    }

    /** Šablona běží pod `strict_variables` — každá větev se musí vykreslit. */
    public function testRendersEveryAnnualSettlementBranch(): void
    {
        $documents = [
            $this->document(
                PayrollSheetDocumentData::ANNUAL_SETTLEMENT_APPROVED,
                $this->settlement(),
            ),
            $this->document(
                PayrollSheetDocumentData::ANNUAL_SETTLEMENT_NOT_PERFORMED,
                null,
                [
                    'request_status' => 'not_requested',
                    'prior_employers' => 'unknown',
                    'filing_obligation' => 'required',
                    'annual_claims' => 'unknown',
                ],
            ),
            $this->document(PayrollSheetDocumentData::ANNUAL_SETTLEMENT_NOT_PERFORMED, null),
            $this->document(PayrollSheetDocumentData::ANNUAL_SETTLEMENT_NOT_RECORDED, null),
        ];

        foreach ($documents as $document) {
            $rendered = (new PayrollSheetPdfRenderer())->render($document);

            self::assertStringStartsWith('%PDF-', $rendered->bytes);
            self::assertSame(
                PayrollSheetSnapshotBuilder::SCHEMA_VERSION,
                $rendered->templateVersion,
            );
        }
    }

    /** @return array<string,mixed> */
    private function settlement(): array
    {
        return [
            'revision_id' => 42,
            'snapshot_hash' => str_repeat('d', 64),
            'settled_on' => '2026-03-20',
            'completed_months' => 12,
            'advance_base_minor_units' => 360_000_00,
            'rounded_tax_base_minor_units' => 360_000_00,
            'tax_before_credits_minor_units' => 54_000_00,
            'annual_credits_minor_units' => 30_840_00,
            'applied_credits_minor_units' => 30_840_00,
            'child_entitlement_minor_units' => 1_820_00,
            'child_credit_minor_units' => 1_820_00,
            'annual_tax_bonus_minor_units' => 0,
            'tax_after_all_credits_minor_units' => 21_340_00,
            'advance_tax_minor_units' => 33_340_00,
            'monthly_tax_bonus_minor_units' => 0,
            'external_certificate_count' => 1,
            'tax_difference_minor_units' => 12_000_00,
            'bonus_difference_minor_units' => 0,
            'settlement_difference_minor_units' => 12_000_00,
            'payable_minor_units' => 12_000_00,
            'outcome' => 'overpayment',
        ];
    }

    /**
     * @param ?array<string,mixed> $settlement
     * @param ?array<string,string> $evidence
     */
    private function hydrate(
        string $schemaVersion,
        ?array $settlement,
        ?array $evidence = null,
    ): PayrollSheetDocumentData {
        $snapshot = [
            'schema_version' => $schemaVersion,
            'tax_year' => 2026,
            'employer' => [
                'name' => 'Zaměstnavatel s.r.o.',
                'identification_number' => '12345678',
                'address' => 'Ulice 1, 110 00 Praha',
            ],
            'employee' => [
                'name' => 'Jan Novák',
                'previous_names' => [],
                'identifier_label' => 'Rodné číslo',
                'identifier_value' => '000000/0000',
                'address' => 'Ulice 2, 110 00 Praha, CZ',
            ],
            'months' => [$this->monthRow($schemaVersion)],
            'annual_settlement_status' => $settlement === null
                ? PayrollSheetDocumentData::ANNUAL_SETTLEMENT_NOT_PERFORMED
                : PayrollSheetDocumentData::ANNUAL_SETTLEMENT_APPROVED,
            'annual_settlement' => $settlement,
            'annual_settlement_evidence' => $evidence,
        ];
        $method = new \ReflectionMethod(PayrollSheetSnapshotBuilder::class, 'hydrate');
        $document = $method->invoke(
            (new \ReflectionClass(PayrollSheetSnapshotBuilder::class))
                ->newInstanceWithoutConstructor(),
            $snapshot,
            str_repeat('e', 64),
        );
        self::assertInstanceOf(PayrollSheetDocumentData::class, $document);

        return $document;
    }

    /** @return array<string,int> */
    private function monthRow(string $schemaVersion): array
    {
        $row = [
            'month' => 12,
            'source_revision_count' => 1,
            'gross_minor_units' => 30_000_00,
            'cash_income_minor_units' => 30_000_00,
            'non_cash_income_minor_units' => 0,
            'social_assessment_base_minor_units' => 0,
            'employee_social_minor_units' => 0,
            'employer_social_minor_units' => 0,
            'health_assessment_base_minor_units' => 0,
            'employee_health_minor_units' => 0,
            'employer_health_minor_units' => 0,
            'health_minimum_top_up_minor_units' => 0,
            'advance_tax_base_minor_units' => 0,
            'advance_tax_before_credits_minor_units' => 0,
            'non_refundable_credits_minor_units' => 0,
            'child_credit_minor_units' => 0,
            'advance_tax_minor_units' => 0,
            'tax_bonus_minor_units' => 0,
            'withholding_tax_minor_units' => 0,
            'other_deductions_minor_units' => 0,
            'annual_settlement_minor_units' => 0,
            'net_payable_minor_units' => 30_000_00,
        ];
        if ($schemaVersion !== 'payroll-sheet-document.v1') {
            $row['tax_exempt_income_minor_units'] = 0;
            $row['withholding_tax_base_minor_units'] = 0;
        }
        if ($schemaVersion === PayrollSheetSnapshotBuilder::SCHEMA_VERSION) {
            $row['child_entitlement_minor_units'] = 0;
        }

        return $row;
    }

    /**
     * @param ?array<string,mixed> $settlement
     * @param ?array<string,string> $evidence
     */
    private function document(
        string $status,
        ?array $settlement,
        ?array $evidence = null,
    ): PayrollSheetDocumentData {
        return new PayrollSheetDocumentData(
            str_repeat('f', 64),
            2026,
            'Zaměstnavatel s.r.o.',
            '12345678',
            'Ulice 1, 110 00 Praha',
            'Jan Novák',
            [],
            'Rodné číslo',
            '000000/0000',
            'Ulice 2, 110 00 Praha, CZ',
            [new PayrollSheetMonth(
                month: 12,
                sourceRevisionCount: 1,
                grossMinorUnits: 30_000_00,
                cashIncomeMinorUnits: 30_000_00,
                nonCashIncomeMinorUnits: 0,
                socialAssessmentBaseMinorUnits: 0,
                employeeSocialMinorUnits: 0,
                employerSocialMinorUnits: 0,
                healthAssessmentBaseMinorUnits: 0,
                employeeHealthMinorUnits: 0,
                employerHealthMinorUnits: 0,
                healthMinimumTopUpMinorUnits: 0,
                advanceTaxBaseMinorUnits: 0,
                advanceTaxBeforeCreditsMinorUnits: 0,
                nonRefundableCreditsMinorUnits: 0,
                childCreditMinorUnits: 0,
                advanceTaxMinorUnits: 0,
                taxBonusMinorUnits: 0,
                withholdingTaxMinorUnits: 0,
                otherDeductionsMinorUnits: 0,
                netPayableMinorUnits: 30_000_00,
                taxDetailStatus: PayrollSheetMonth::TAX_DETAIL_RECORDED,
                childDetailStatus: PayrollSheetMonth::CHILD_DETAIL_RECORDED,
            )],
            $status,
            [],
            $settlement,
            $evidence,
        );
    }
}
