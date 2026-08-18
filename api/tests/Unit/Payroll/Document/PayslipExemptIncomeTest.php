<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Document;

use MyInvoice\Service\Payroll\Component\PayrollExemptionBasis;
use MyInvoice\Service\Payroll\Document\PayrollSheetMonth;
use MyInvoice\Service\Payroll\Document\PayrollSheetSnapshotBuilder;
use MyInvoice\Service\Payroll\Document\PayslipDocumentData;
use MyInvoice\Service\Payroll\Document\PayslipDocumentSnapshotHydrator;
use MyInvoice\Service\Payroll\Document\PayslipDocumentSnapshotMapper;
use MyInvoice\Service\Payroll\Document\PayslipLine;
use MyInvoice\Service\Payroll\Document\PayslipPdfRenderer;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Tests\Fixtures\Payroll\SyntheticPayslipFixture;
use PHPUnit\Framework\TestCase;

/**
 * Výplatní páska a mzdový list mluví o téže mzdě, takže se o nezdaněných
 * složkách nesmí rozejít.
 *
 * Páska se vydává podle § 142 odst. 6 zákoníku práce („údaje o jednotlivých
 * složkách mzdy nebo platu a o provedených srážkách"), mzdový list podle § 38j
 * odst. 2 písm. f) bodu 2 ZDP („částky osvobozené od daně z úhrnu zúčtovaných
 * mezd"). Oba čtou TÝŽ zmrazený vstup schválené revize — testy níž to ověřují
 * porovnáním obou dokladů nad jedním scénářem, ne dvěma odhady.
 */
final class PayslipExemptIncomeTest extends TestCase
{
    private const EMPLOYEE_ID = 11;
    private const EMPLOYMENT_ID = 101;

    /** Osvobozený příjem § 6 odst. 9: oba doklady musí ukázat totéž. */
    public function testStatutoryExemptComponentReadsTheSameOnBothDocuments(): void
    {
        $inputs = [
            $this->input(1001, 'Základní mzda', 900_00),
            $this->input(1002, 'Příspěvek na odborný rozvoj', 100_00, 'exempt', 'statutory_exempt'),
        ];

        $payslip = $this->payslip($inputs);
        $month = $this->sheetMonth($inputs);

        self::assertSame(100_00, $payslip->taxExemptIncomeMinorUnits());
        self::assertSame(0, $payslip->notSubjectToTaxIncomeMinorUnits());
        self::assertSame($month->taxExemptIncomeMinorUnits, $payslip->taxExemptIncomeMinorUnits());
        self::assertSame($month->grossMinorUnits, $payslip->grossMinorUnits);
        self::assertTrue($payslip->incomeDetailRecorded());
    }

    /**
     * Cestovní náhrada do limitu není předmětem daně (§ 6 odst. 7 ZDP). Na
     * mzdovém listě mezi osvobozené částky nepatří — na pásce ale musí být
     * vidět, protože je to složka, ze které se nic nesrazilo.
     */
    public function testAmountOutsideTheScopeOfTaxIsShownSeparatelyOnThePayslip(): void
    {
        $inputs = [
            $this->input(1001, 'Základní mzda', 900_00),
            $this->input(1002, 'Cestovní náhrada do limitu', 100_00, 'exempt', 'not_subject_to_tax'),
        ];

        $payslip = $this->payslip($inputs);
        $month = $this->sheetMonth($inputs);

        self::assertSame(0, $month->taxExemptIncomeMinorUnits);
        self::assertSame(0, $payslip->taxExemptIncomeMinorUnits());
        self::assertSame(100_00, $payslip->notSubjectToTaxIncomeMinorUnits());
        self::assertSame(
            '§ 6 odst. 7 ZDP',
            $payslip->toTemplateData()['income_lines'][1]['exemption_statute'],
        );
    }

    /** U benefitního koše se bere zmrazený rozpad, ne dopočet — na obou dokladech. */
    public function testBenefitBasketSplitMatchesOnBothDocuments(): void
    {
        $inputs = [
            $this->input(1001, 'Základní mzda', 900_00),
            $this->input(1002, 'Volnočasový benefit', 100_00, 'exempt', 'benefit_basket', [
                'benefit_basket' => 'non_cash_leisure',
                'benefit_exempt_minor' => 60_00,
                'benefit_taxable_minor' => 40_00,
            ]),
        ];

        $payslip = $this->payslip($inputs);

        self::assertSame(60_00, $payslip->taxExemptIncomeMinorUnits());
        self::assertSame(
            $this->sheetMonth($inputs)->taxExemptIncomeMinorUnits,
            $payslip->taxExemptIncomeMinorUnits(),
        );
        $line = $payslip->toTemplateData()['income_lines'][1];
        self::assertSame(100_00, $line['amount_minor_units']);
        self::assertSame(60_00, $line['exempt_part_minor_units']);
    }

    /** Nezaklasifikovaná složka nesmí projít jako zdanitelná nula. */
    public function testUnclassifiedComponentFailsClosedInTheMapper(): void
    {
        $this->expectExceptionMessage('Daňové zacházení složky vstupu 1001 není uzavřené.');
        $this->payslip([$this->input(1001, 'Neznámá složka', 100_00, 'manual_review')]);
    }

    public function testExemptComponentWithoutBasisFailsClosedInTheMapper(): void
    {
        $this->expectExceptionMessage('Podklad osvobození složky vstupu 1001 není uveden.');
        $this->payslip([$this->input(1001, 'Benefit bez podkladu', 100_00, 'exempt', null)]);
    }

    /**
     * Archivovaná páska se zpětně nemění. Její snapshot zůstává v1 a hydratuje
     * se jako neevidovaný údaj — ne jako nula, kterou by nikdo nespočítal.
     */
    public function testArchivedV1SnapshotStaysUnchangedAndReportsNotRecorded(): void
    {
        $snapshot = $this->payslipSnapshot([
            $this->input(1001, 'Základní mzda', 900_00),
            $this->input(1002, 'Příspěvek na odborný rozvoj', 100_00, 'exempt', 'statutory_exempt'),
        ]);
        $archived = $snapshot;
        $archived['schema_version'] = 'payroll-payslip-document.v1';
        foreach ($archived['income_lines'] as $index => $line) {
            unset(
                $line['exemption_basis'],
                $line['exemption_statute'],
                $line['exempt_part_minor_units'],
            );
            $archived['income_lines'][$index] = $line;
        }
        unset($archived['income_detail_status']);

        $document = (new PayslipDocumentSnapshotHydrator())->hydrate(
            $archived,
            'revision-1',
            hash('sha256', 'archived'),
            '2026-07',
        );

        self::assertFalse($document->incomeDetailRecorded());
        self::assertSame(
            PayslipDocumentData::INCOME_DETAIL_NOT_RECORDED,
            $document->toTemplateData()['income_detail_status'],
        );
        self::assertSame(0, $document->taxExemptIncomeMinorUnits());
        self::assertNull($document->incomeLines[1]->exemptionBasis);
        self::assertSame(1_000_00, $document->grossMinorUnits);
    }

    public function testCurrentSnapshotHydratesWithTheFrozenBasis(): void
    {
        $document = (new PayslipDocumentSnapshotHydrator())->hydrate(
            $this->payslipSnapshot([
                $this->input(1001, 'Základní mzda', 900_00),
                $this->input(1002, 'Jízdenka u dopravce', 100_00, 'exempt', 'statutory_exempt'),
            ]),
            'revision-1',
            hash('sha256', 'current'),
            '2026-07',
        );

        self::assertTrue($document->incomeDetailRecorded());
        self::assertSame(
            PayrollExemptionBasis::StatutoryExempt,
            $document->incomeLines[1]->exemptionBasis,
        );
        self::assertSame(100_00, $document->taxExemptIncomeMinorUnits());
    }

    /** Neevidovaný údaj nesmí zároveň nést podklad — to by byl obojí stav. */
    public function testNotRecordedDetailMustNotCarryABasis(): void
    {
        $this->expectExceptionMessage(
            'Neevidované údaje o složkách mzdy nesmí nést podklad osvobození.',
        );
        new PayslipDocumentData(
            revisionId: 'r1',
            sourceSnapshotSha256: hash('sha256', 'x'),
            employerName: 'Zaměstnavatel s.r.o.',
            employerIdentificationNumber: '00000000',
            employeeDisplayName: 'Jan Novák',
            period: '2026-07',
            employmentLabel: 'Pracovní poměr',
            incomeLines: [new PayslipLine(
                'Benefit',
                100_00,
                PayrollExemptionBasis::StatutoryExempt,
                100_00,
            )],
            grossMinorUnits: 100_00,
            employeeSocialMinorUnits: 0,
            employeeHealthMinorUnits: 0,
            healthMinimumTopUpMinorUnits: 0,
            taxBaseMinorUnits: 0,
            taxBeforeCreditsMinorUnits: 0,
            taxNonRefundableCreditsMinorUnits: 0,
            taxChildCreditMinorUnits: 0,
            taxBonusEligible: false,
            taxAfterCreditsMinorUnits: 0,
            taxBonusMinorUnits: 0,
            otherDeductionLines: [],
            roundingAdjustmentMinorUnits: 0,
            netMinorUnits: 100_00,
            employerSocialMinorUnits: 0,
            employerHealthMinorUnits: 0,
            grossExpenseAccount: '521',
            grossLiabilityAccount: '331',
            insuranceExpenseAccount: '524',
            insuranceLiabilityAccount: '336',
        );
    }

    /** Šablona pásky musí unést obě větve — evidovanou i neevidovanou. */
    public function testRendersBothRecordedAndNotRecordedIncomeDetail(): void
    {
        $recorded = $this->payslip([
            $this->input(1001, 'Základní mzda', 900_00),
            $this->input(1002, 'Cestovní náhrada do limitu', 50_00, 'exempt', 'not_subject_to_tax'),
            $this->input(1003, 'Volnočasový benefit', 50_00, 'exempt', 'benefit_basket', [
                'benefit_basket' => 'non_cash_leisure',
                'benefit_exempt_minor' => 30_00,
                'benefit_taxable_minor' => 20_00,
            ]),
        ]);

        foreach ([$recorded, SyntheticPayslipFixture::document()] as $document) {
            $rendered = (new PayslipPdfRenderer())->render($document);
            self::assertStringStartsWith('%PDF-', $rendered->pdfBytes);
        }
    }

    public function testExemptPartCannotExceedTheLineAmount(): void
    {
        $this->expectExceptionMessage(
            'Nezdaněná část složky výplatní pásky je mimo částku složky.',
        );
        new PayslipLine('Benefit', 100_00, PayrollExemptionBasis::StatutoryExempt, 100_01);
    }

    /**
     * @param list<array<string,mixed>> $inputs
     * @return array<string,mixed>
     */
    private function payslipSnapshot(array $inputs): array
    {
        $result = (new PayslipDocumentSnapshotMapper())->attach(
            $this->inputSnapshot($inputs),
            $this->calculatedResult($inputs),
        );
        $snapshot = $result['people'][0]['payslip_document'];
        self::assertIsArray($snapshot);

        return $snapshot;
    }

    /** @param list<array<string,mixed>> $inputs */
    private function payslip(array $inputs): PayslipDocumentData
    {
        return (new PayslipDocumentSnapshotHydrator())->hydrate(
            $this->payslipSnapshot($inputs),
            'revision-1',
            hash('sha256', 'source'),
            '2026-07',
        );
    }

    /** @param list<array<string,mixed>> $inputs */
    private function sheetMonth(array $inputs): PayrollSheetMonth
    {
        $builder = (new \ReflectionClass(PayrollSheetSnapshotBuilder::class))
            ->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(PayrollSheetSnapshotBuilder::class, 'months');
        [$months] = $method->invoke($builder, [$this->sheetSource($inputs)], self::EMPLOYEE_ID);
        self::assertIsArray($months);
        self::assertCount(1, $months);

        return $months[0];
    }

    /**
     * @param array<string,mixed> $extra
     * @return array<string,mixed>
     */
    private function input(
        int $id,
        string $name,
        int $amountMinor,
        string $treatment = 'included',
        ?string $basis = null,
        array $extra = [],
    ): array {
        $component = [
            'code' => 'SLOZKA' . $id,
            'name' => $name,
            'tax_treatment' => $treatment,
        ];
        if ($basis !== null) {
            $component['exemption_basis'] = $basis;
        }

        return [
            'id' => $id,
            'amount_minor' => $amountMinor,
            'component' => $component,
        ] + $extra;
    }

    /**
     * @param list<array<string,mixed>> $inputs
     * @return array<string,mixed>
     */
    private function inputSnapshot(array $inputs): array
    {
        return [
            'schema_version' => 'payroll-run-input.v2',
            'supplier_id' => 7,
            'period_start' => '2026-07-01',
            'period_end' => '2026-07-31',
            'source_snapshot_hash' => hash('sha256', 'source'),
            'employer' => [
                'name' => 'Syntetická společnost s.r.o.',
                'identification_number' => '00000000',
                'accounting_accounts' => [
                    'employment_gross_debit' => '521',
                    'employment_gross_credit' => '331',
                    'partner_gross_debit' => '522',
                    'partner_gross_credit' => '366',
                    'statutory_gross_debit' => '523',
                    'statutory_gross_credit' => '366',
                    'employer_insurance_debit' => '524',
                    'social_insurance_credit' => '336',
                    'health_insurance_credit' => '336',
                    'income_tax_credit' => '342',
                    'other_deductions_credit' => '379',
                ],
            ],
            'people' => [[
                'employee' => [
                    'id' => self::EMPLOYEE_ID,
                    'full_name' => 'Syntetická Osoba',
                ],
                'deduction_agreements' => [],
                'employments' => [[
                    'employment' => [
                        'id' => self::EMPLOYMENT_ID,
                        'employee_id' => self::EMPLOYEE_ID,
                        'code' => 'SYN-101',
                        'relation_type' => 'employment',
                        'start_date' => '2026-01-15',
                        'actual_start_date' => null,
                        'end_date' => null,
                    ],
                    'inputs' => $inputs,
                ]],
            ]],
        ];
    }

    /**
     * @param list<array<string,mixed>> $inputs
     * @return array<string,mixed>
     */
    private function calculatedResult(array $inputs): array
    {
        $gross = 0;
        $inputResults = [];
        foreach ($inputs as $input) {
            $gross += (int) $input['amount_minor'];
            $inputResults[] = [
                'input_id' => $input['id'],
                'totals' => [
                    'source_amount_minor' => $input['amount_minor'],
                    'cash_payable_minor' => $input['amount_minor'],
                ],
                'accounting' => ['debit_code' => '521', 'credit_code' => '331'],
            ];
        }

        return [
            'schema_version' => 'payroll-run-result.v1',
            'source_snapshot_hash' => hash('sha256', 'source'),
            'people' => [$this->person($gross, $inputResults)],
            'statutory' => [
                'schema_version' => 'payroll-run-statutory-result.v1',
                'status' => 'calculated',
                'employer_social_before_discount_minor_units' => 0,
                'employer_social_part_time_discount_minor_units' => 0,
                'employer_social_minor_units' => 0,
                'people' => [],
            ],
        ];
    }

    /**
     * @param list<array<string,mixed>> $inputResults
     * @return array<string,mixed>
     */
    private function person(int $gross, array $inputResults): array
    {
        return [
            'employee_id' => self::EMPLOYEE_ID,
            'employments' => [[
                'employment_id' => self::EMPLOYMENT_ID,
                'inputs' => $inputResults,
            ]],
            'enforcement' => [
                'result' => [
                    'status' => 'supported',
                    'total_withheld_minor_units' => 0,
                ],
            ],
            'payable_after_enforcement_minor' => $gross,
            'statutory' => [
                'person_reference' => 'employee:' . self::EMPLOYEE_ID,
                'status' => 'calculated',
                'social_insurance' => [
                    'person_id' => 'employee:' . self::EMPLOYEE_ID,
                    'status' => 'calculated',
                    'capped_assessment_base_minor_units' => $gross,
                    'employee_contribution_minor_units' => 0,
                    'relationships' => [[
                        'relationship_id' => 'employment:' . self::EMPLOYMENT_ID,
                        'capped_assessment_base_minor_units' => $gross,
                        'part_time_employer_discount' => 'not_requested',
                    ]],
                ],
                'health_insurance' => [
                    'person_id' => 'employee:' . self::EMPLOYEE_ID,
                    'status' => 'calculated',
                    'assessment_base_minor_units' => $gross,
                    'employee_minimum_top_up_minor_units' => 0,
                    'employee_contribution_minor_units' => 0,
                    'employer_contribution_minor_units' => 0,
                ],
                'income_tax' => [
                    'employee_reference' => 'employee:' . self::EMPLOYEE_ID,
                    'status' => 'calculated',
                    'advance_tax' => null,
                    'withholding_base_minor_units' => 0,
                    'withholding_tax_minor_units' => 0,
                    'claimed_child_credit_minor_units' => 0,
                    'applied_child_credit_minor_units' => 0,
                ],
                'net_pay' => [
                    'person_reference' => 'employee:' . self::EMPLOYEE_ID,
                    'cash_income_minor_units' => $gross,
                    'non_cash_income_minor_units' => 0,
                    'employee_social_minor_units' => 0,
                    'employee_health_minor_units' => 0,
                    'advance_tax_minor_units' => 0,
                    'withholding_tax_minor_units' => 0,
                    'tax_bonus_minor_units' => 0,
                    'correction_minor_units' => 0,
                    'deducted_minor_units' => 0,
                    'net_payable_minor_units' => $gross,
                    'deductions' => [],
                ],
                'net_payable_minor_units' => $gross,
            ],
        ];
    }

    /**
     * @param list<array<string,mixed>> $inputs
     * @return array<string,mixed>
     */
    private function sheetSource(array $inputs): array
    {
        $result = $this->calculatedResult($inputs);
        $person = $result['people'][0];
        $person['payslip_document'] = [
            'gross_minor_units' => $person['payable_after_enforcement_minor'],
            'employer_social_minor_units' => 0,
        ];
        $personJson = CanonicalJson::encode($person);
        $resultJson = CanonicalJson::encode(['people' => [$person]]);
        $inputJson = CanonicalJson::encode($this->inputSnapshot($inputs));

        return [
            'period_start' => '2026-07-01',
            'run_id' => 1,
            'revision_id' => 1,
            'input_snapshot_json' => $inputJson,
            'input_snapshot_hash' => hash('sha256', $inputJson),
            'result_snapshot_json' => $resultJson,
            'result_snapshot_hash' => hash('sha256', $resultJson),
            'person_result_json' => $personJson,
            'person_result_hash' => hash('sha256', $personJson),
        ];
    }
}
