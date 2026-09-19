<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Document;

use MyInvoice\Service\Payroll\Document\AnnualTaxCertificateDocumentData;
use MyInvoice\Service\Payroll\Document\AnnualTaxCertificateFormCatalog;
use MyInvoice\Service\Payroll\Document\AnnualTaxCertificatePdfRenderer;
use MyInvoice\Service\Payroll\Document\AnnualTaxCertificateSnapshotBuilder;
use MyInvoice\Service\Payroll\Document\PayrollCarriedOverPeriod;
use MyInvoice\Service\Payroll\Document\PayrollDocumentEmployerSnapshot;
use MyInvoice\Service\Payroll\Document\PayrollDocumentKind;
use MyInvoice\Service\Payroll\Document\PayrollSheetDocumentData;
use MyInvoice\Service\Payroll\Document\PayrollSheetMonth;
use MyInvoice\Service\Payroll\Document\PayrollSheetPdfRenderer;
use PHPUnit\Framework\TestCase;
use Smalot\PdfParser\Parser;

/**
 * Rok přechodu z jiného mzdového programu: MyÚčto počítalo jen srpen až
 * prosinec, leden až červenec drží počáteční stavy kumulací. Roční doklady
 * podle § 38j musejí vykázat celý rok a zároveň nesmějí vydávat převzatou
 * část za vlastní výpočet.
 *
 * Data jsou syntetická.
 */
final class PayrollCarriedOverPeriodTest extends TestCase
{
    private const FIRST_PROCESSED_MONTH = 8;

    public function testOpeningPokryvaMesicePredPrvnimZpracovanymObdobim(): void
    {
        $carried = PayrollCarriedOverPeriod::fromOpenings(
            self::openings(),
            self::FIRST_PROCESSED_MONTH,
        );

        self::assertInstanceOf(PayrollCarriedOverPeriod::class, $carried);
        self::assertSame([1, 2, 3, 4, 5, 6, 7], $carried->months);
        self::assertSame('1–7', $carried->label());
        self::assertSame(280_000_00, $carried->taxAmount('advance_base_minor_units'));
        self::assertSame(31_500_00, $carried->taxAmount('advance_tax_minor_units'));
        self::assertSame(0, $carried->taxAmount('neexistujici_pole'));
        self::assertSame(
            ['income_tax', 'social_insurance'],
            array_keys($carried->manifestAnchor()['record_hashes']),
        );
    }

    /**
     * Bod 3 zadání: chybějící počáteční stav neznamená tichý neúplný doklad.
     * Hláška musí říct, co s tím — doplnit počáteční stavy.
     */
    public function testChybejiciOpeningDokladOdmitne(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/počáteční stavy/u');

        PayrollCarriedOverPeriod::fromOpenings(
            ['income_tax' => null, 'social_insurance' => null],
            self::FIRST_PROCESSED_MONTH,
        );
    }

    /** Firma, která vede mzdy od ledna, nesmí novou branou projít jinak než dřív. */
    public function testChybejiciOpeningNevadiKdyzMyUctoVedeRokOdLedna(): void
    {
        self::assertNull(PayrollCarriedOverPeriod::fromOpenings(
            ['income_tax' => null, 'social_insurance' => null],
            1,
        ));
    }

    /** Doložená nula: opening existuje a říká, že před nástupem nic nebylo. */
    public function testPrazdnyRozpisNeniPrevzataCast(): void
    {
        self::assertNull(PayrollCarriedOverPeriod::fromOpenings(
            ['income_tax' => self::opening([], ['completed_months' => 0])],
            self::FIRST_PROCESSED_MONTH,
        ));
    }

    public function testPrekryvSVlastnimMesicemJeOdmitnut(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/dvakrát/u');

        PayrollCarriedOverPeriod::fromOpenings(self::openings(), 5);
    }

    public function testPotvrzeniPrebiraZalohovyZakladDanIBonus(): void
    {
        $amounts = self::certificateCarriedAmounts(
            PayrollDocumentKind::TaxableIncomeAdvanceCertificate,
        );

        self::assertSame(280_000_00, $amounts['income_minor_units']);
        self::assertSame(31_500_00, $amounts['tax_minor_units']);
        self::assertSame(1_267_00, $amounts['tax_bonus_minor_units']);
        self::assertIsArray($amounts['snapshot']);
        self::assertSame('1–7', $amounts['snapshot']['months_label']);
    }

    /** Srážkové potvrzení bere jen srážkovou větev, ne zálohovou. */
    public function testSrazkovePotvrzeniPrebiraSrazkovouVetev(): void
    {
        $amounts = self::certificateCarriedAmounts(
            PayrollDocumentKind::TaxableIncomeWithholdingCertificate,
        );

        self::assertSame(70_000_00, $amounts['income_minor_units']);
        self::assertSame(10_500_00, $amounts['tax_minor_units']);
        self::assertSame(0, $amounts['tax_bonus_minor_units']);
    }

    public function testPotvrzeniUvadiCelychDvanactMesicuAPoznamkuOPrevzeti(): void
    {
        $document = self::certificate();
        $template = $document->toTemplateData();

        self::assertSame('1–12', $template['months_label']);
        self::assertSame('8–12', $template['own_months_label']);
        self::assertIsArray($template['carried_over']);
        self::assertSame('1–7', $template['carried_over']['months_label']);
        self::assertSame(
            'Sestava z předchozího mzdového programu',
            $template['carried_over']['source_reference'],
        );
        // Úhrn řádku 1 je za celý rok: 200 000 vlastních + 280 000 převzatých.
        self::assertSame(480_000, $template['amounts']['accrued_income_czk']);

        $text = self::pdfText(
            (new AnnualTaxCertificatePdfRenderer())->render($document)->bytes,
        );
        // Nadpisy prochází přes text-transform: uppercase, takže z PDF vyjdou
        // velkými písmeny.
        self::assertStringContainsString('PŘEVZATÉ OBDOBÍ', $text);
        self::assertStringContainsString(
            'Kalendářní měsíce 1–7 roku 2026 plátce nezpracoval v MyÚčtu',
            $text,
        );
        self::assertStringContainsString(
            'Sestava z předchozího mzdového programu',
            $text,
        );
        self::assertStringContainsString('480 000', $text);
    }

    public function testMzdovyListVedePrevzatouCastOddeleneOdVlastnichMesicu(): void
    {
        $template = self::payrollSheet()->toTemplateData();

        self::assertTrue($template['carried_over_assessed']);
        self::assertIsArray($template['carried_over']);
        self::assertSame('1–7', $template['carried_over']['months_label']);
        self::assertCount(7, $template['carried_over']['rows']);
        self::assertSame(
            280_000_00,
            $template['carried_over']['totals']['advance_base_minor_units'],
        );
        // Vlastní měsíční řada zůstává nedotčená — převzaté měsíce se do ní
        // nemíchají, protože nemají hrubý ani čistý příjem.
        self::assertCount(5, $template['months']);

        $text = self::pdfText(
            (new PayrollSheetPdfRenderer())->render(self::payrollSheet())->bytes,
        );
        self::assertStringContainsString('PŘEVZATÉ OBDOBÍ 1–7', $text);
        self::assertStringContainsString(
            'Měsíce 1–7 zpracoval předchozí mzdový program, ne MyÚčto',
            $text,
        );
        self::assertStringContainsString(
            'zadal (zdroj: Sestava z předchozího mzdového programu)',
            $text,
        );
    }

    /**
     * Dopředná kompatibilita: až průvodce počátečních stavů začne zapisovat
     * zdravotní pojištění, musí se v dokladu objevit bez zásahu do kódu.
     */
    public function testZdravotniPojisteniSeProjeviAzSeVOpeninguObjevi(): void
    {
        $withoutHealth = self::payrollSheet()->toTemplateData();
        self::assertNotContains(
            'health_assessment_base_minor_units',
            array_column($withoutHealth['carried_over']['columns'], 'field'),
        );

        $withHealth = self::payrollSheet(health: true)->toTemplateData();
        $fields = array_column($withHealth['carried_over']['columns'], 'field');
        self::assertContains('health_assessment_base_minor_units', $fields);
        self::assertSame(
            280_000_00,
            $withHealth['carried_over']['totals']['health_assessment_base_minor_units'],
        );
    }

    /**
     * @return array{
     *   income_minor_units:int,
     *   tax_minor_units:int,
     *   tax_bonus_minor_units:int,
     *   snapshot:?array<string,mixed>,
     *   manifest:?array<string,mixed>
     * }
     */
    private static function certificateCarriedAmounts(
        PayrollDocumentKind $kind,
    ): array {
        $builder = (new \ReflectionClass(AnnualTaxCertificateSnapshotBuilder::class))
            ->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(
            AnnualTaxCertificateSnapshotBuilder::class,
            'carriedAmounts',
        );
        $amounts = $method->invoke(
            $builder,
            PayrollCarriedOverPeriod::fromOpenings(
                self::openings(),
                self::FIRST_PROCESSED_MONTH,
            ),
            $kind,
        );
        self::assertIsArray($amounts);

        return $amounts;
    }

    private static function certificate(): AnnualTaxCertificateDocumentData
    {
        $kind = PayrollDocumentKind::TaxableIncomeAdvanceCertificate;
        $carried = PayrollCarriedOverPeriod::fromOpenings(
            self::openings(),
            self::FIRST_PROCESSED_MONTH,
        );
        self::assertInstanceOf(PayrollCarriedOverPeriod::class, $carried);

        return new AnnualTaxCertificateDocumentData(
            sourceSnapshotSha256: str_repeat('c', 64),
            kind: $kind,
            taxYear: 2026,
            form: AnnualTaxCertificateFormCatalog::resolve(2026, $kind),
            employer: new PayrollDocumentEmployerSnapshot(
                name: 'Syntetická společnost s.r.o.',
                identificationNumber: '00000019',
                taxIdentificationNumber: 'CZ00000019',
                streetLine: 'Testovací 1',
                city: 'Praha',
                postalCode: '100 00',
                countryCode: 'CZ',
                countryName: 'Česká republika',
                issuerName: 'Syntetická mzdová účtárna',
                issuerEmail: 'synthetic@example.invalid',
                issuerPhone: '+420 200 000 000',
            ),
            employeeName: 'Syntetická osoba',
            employeeFirstName: 'Syntetická',
            employeeLastName: 'Osoba',
            previousNames: [],
            personalIdentifierLabel: 'Rodné číslo',
            personalIdentifierValue: '0001010009',
            employeeAddress: 'Modelová 2, 602 00 Brno, CZ',
            months: [8, 9, 10, 11, 12],
            taxDeclarationStatus: 'signed',
            taxDeclarationSignedMonths: [8, 9, 10, 11, 12],
            taxResidenceStatus: 'czech-resident',
            taxResidenceCountryCode: 'CZ',
            issuedAt: '2027-01-20 09:15:00',
            replacesIssuedAt: null,
            correctionReason: null,
            employerProductContributionsMinorUnits: [
                'supplementary_pension' => 0,
                'pension_insurance' => 0,
                'private_life_insurance' => 0,
                'long_term_investment_product' => 0,
            ],
            childTaxBenefits: [],
            disabilityTaxCredits: [],
            annualSettlement: ['performed' => false, 'result' => null],
            nonresidentInsuranceMinorUnits: null,
            accruedIncomeMinorUnits: 480_000_00,
            paidIncomeMinorUnits: 480_000_00,
            advanceTaxMinorUnits: 54_000_00,
            withholdingTaxMinorUnits: 0,
            taxBonusMinorUnits: 1_267_00,
            paymentEvidenceCutoff: '2027-01-31',
            lastProvenPaymentDate: '2027-01-10',
            carriedOver: PayrollCarriedOverPeriod::fromSnapshot(
                $carried->toSnapshot(),
            ),
        );
    }

    private static function payrollSheet(bool $health = false): PayrollSheetDocumentData
    {
        $carried = PayrollCarriedOverPeriod::fromOpenings(
            self::openings($health),
            self::FIRST_PROCESSED_MONTH,
        );
        self::assertInstanceOf(PayrollCarriedOverPeriod::class, $carried);
        $months = [];
        foreach ([8, 9, 10, 11, 12] as $month) {
            $months[] = new PayrollSheetMonth(
                month: $month,
                sourceRevisionCount: 1,
                grossMinorUnits: 40_000_00,
                cashIncomeMinorUnits: 40_000_00,
                nonCashIncomeMinorUnits: 0,
                socialAssessmentBaseMinorUnits: 40_000_00,
                employeeSocialMinorUnits: 2_600_00,
                employerSocialMinorUnits: 9_920_00,
                healthAssessmentBaseMinorUnits: 40_000_00,
                employeeHealthMinorUnits: 1_800_00,
                employerHealthMinorUnits: 3_600_00,
                healthMinimumTopUpMinorUnits: 0,
                advanceTaxBaseMinorUnits: 40_000_00,
                advanceTaxBeforeCreditsMinorUnits: 6_000_00,
                nonRefundableCreditsMinorUnits: 2_570_00,
                childCreditMinorUnits: 0,
                advanceTaxMinorUnits: 3_430_00,
                taxBonusMinorUnits: 0,
                withholdingTaxMinorUnits: 0,
                otherDeductionsMinorUnits: 0,
                netPayableMinorUnits: 32_170_00,
                taxDetailStatus: PayrollSheetMonth::TAX_DETAIL_RECORDED,
                childDetailStatus: PayrollSheetMonth::CHILD_DETAIL_RECORDED,
                creditDetailStatus: PayrollSheetMonth::CREDIT_DETAIL_APPLIED,
            );
        }

        return new PayrollSheetDocumentData(
            str_repeat('a', 64),
            2026,
            'Syntetická společnost s.r.o.',
            '00000019',
            'Testovací 1, 100 00 Praha',
            'Syntetická osoba',
            [],
            'Rodné číslo',
            '0001010009',
            'Modelová 2, 602 00 Brno, CZ',
            $months,
            PayrollSheetDocumentData::ANNUAL_SETTLEMENT_NOT_PERFORMED,
            [],
            null,
            null,
            PayrollCarriedOverPeriod::fromSnapshot($carried->toSnapshot()),
            true,
        );
    }

    /** @return array<string,array<string,mixed>|null> */
    private static function openings(bool $health = false): array
    {
        $monthRows = [];
        for ($month = 1; $month <= 7; $month++) {
            $row = [
                'month' => $month,
                'social_assessment_base_minor_units' => 40_000_00,
                'advance_base_minor_units' => 40_000_00,
                'advance_tax_minor_units' => 4_500_00,
                'withholding_base_minor_units' => 10_000_00,
                'withholding_tax_minor_units' => 1_500_00,
                'applied_non_refundable_credits_minor_units' => 2_570_00,
                'applied_child_credit_minor_units' => 0,
                'tax_bonus_minor_units' => 181_00,
                'bonus_qualifying_income_minor_units' => 40_000_00,
            ];
            if ($health) {
                // Druh, který dnes do openingu nikdo nezapisuje. Až ho průvodce
                // doplní, projde tudy beze změny kódu.
                $row['health_assessment_base_minor_units'] = 40_000_00;
            }
            $monthRows[] = $row;
        }

        $openings = [
            'income_tax' => self::opening($monthRows, [
                'completed_months' => 7,
                'advance_base_minor_units' => 280_000_00,
                'withholding_base_minor_units' => 70_000_00,
                'advance_tax_minor_units' => 31_500_00,
                'withholding_tax_minor_units' => 10_500_00,
                'applied_non_refundable_credits_minor_units' => 17_990_00,
                'applied_child_credit_minor_units' => 0,
                'tax_bonus_minor_units' => 1_267_00,
                'bonus_qualifying_income_minor_units' => 280_000_00,
            ]),
            'social_insurance' => self::opening($monthRows, [
                'assessment_base_minor_units' => 280_000_00,
            ]),
        ];
        if ($health) {
            $openings['health_insurance'] = self::opening($monthRows, [
                'assessment_base_minor_units' => 280_000_00,
            ]);
        }

        return $openings;
    }

    /**
     * @param list<array<string,int>> $monthRows
     * @param array<string,int> $values
     * @return array<string,mixed>
     */
    private static function opening(array $monthRows, array $values): array
    {
        return [
            'id' => 1,
            'values' => $values,
            'source_reference' => 'Sestava z předchozího mzdového programu',
            'evidence' => ['months' => $monthRows],
            'replaces_opening_id' => null,
            'record_hash' => hash('sha256', serialize([$monthRows, $values])),
            'created_at' => '2026-08-01 08:00:00',
        ];
    }

    private static function pdfText(string $bytes): string
    {
        return preg_replace(
            '/\s+/u',
            ' ',
            (new Parser())->parseContent($bytes)->getText(),
        ) ?? '';
    }
}
