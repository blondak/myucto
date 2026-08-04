<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Document;

use MyInvoice\Service\Payroll\Document\PayrollSheetDocumentData;
use MyInvoice\Service\Payroll\Document\PayrollSheetMonth;
use MyInvoice\Service\Payroll\Document\PayrollSheetPdfRenderer;
use PHPUnit\Framework\TestCase;

final class PayrollSheetDocumentDataTest extends TestCase
{
    public function testTwelveMonthSheetKeepsBackpayAndChecksAnnualTotals(): void
    {
        $data = self::document();

        self::assertSame(1_200_000, $data->totals()['gross_minor_units']);
        self::assertSame(13, $data->totals()['source_revision_count']);
        self::assertSame('not_performed', $data->annualSettlementStatus);
    }

    public function testRendererCreatesOpaqueAuditablePdf(): void
    {
        $data = self::document();
        $artifact = (new PayrollSheetPdfRenderer())->render($data);

        self::assertStringStartsWith('%PDF-', $artifact->bytes);
        self::assertSame('application/pdf', $artifact->mimeType);
        self::assertSame($data->sourceSnapshotSha256, $artifact->sourceSnapshotHash);
        self::assertMatchesRegularExpression(
            '/^mzdovy-list-2026-[a-f0-9]{12}\.pdf$/D',
            $artifact->suggestedFilename,
        );
        self::assertStringNotContainsString($data->employeeName, $artifact->suggestedFilename);
        self::assertStringContainsString('PayrollSourceSnapshotHMACSHA256', $artifact->bytes);
        self::assertStringContainsString('PayrollRendererVersion', $artifact->bytes);

        $qaOutput = getenv('MYINVOICE_PAYROLL_SHEET_QA_OUTPUT');
        if (is_string($qaOutput) && $qaOutput !== '') {
            $qaDir = dirname($qaOutput);
            if (!is_dir($qaDir)) {
                mkdir($qaDir, 0755, true);
            }
            file_put_contents($qaOutput, $artifact->bytes);
        }
    }

    private static function document(): PayrollSheetDocumentData
    {
        $months = [];
        for ($month = 1; $month <= 12; ++$month) {
            $months[] = new PayrollSheetMonth(
                month: $month,
                sourceRevisionCount: $month === 12 ? 2 : 1,
                grossMinorUnits: 100_000,
                cashIncomeMinorUnits: 90_000,
                nonCashIncomeMinorUnits: 10_000,
                socialAssessmentBaseMinorUnits: 100_000,
                employeeSocialMinorUnits: 6_500,
                employerSocialMinorUnits: 24_800,
                healthAssessmentBaseMinorUnits: 100_000,
                employeeHealthMinorUnits: 4_500,
                employerHealthMinorUnits: 9_000,
                healthMinimumTopUpMinorUnits: 0,
                advanceTaxBaseMinorUnits: 100_000,
                advanceTaxBeforeCreditsMinorUnits: 15_000,
                nonRefundableCreditsMinorUnits: 2_570,
                childCreditMinorUnits: 0,
                advanceTaxMinorUnits: 12_430,
                taxBonusMinorUnits: 0,
                withholdingTaxMinorUnits: 0,
                otherDeductionsMinorUnits: 0,
                netPayableMinorUnits: 66_570,
            );
        }

        return new PayrollSheetDocumentData(
            sourceSnapshotSha256: str_repeat('a', 64),
            taxYear: 2026,
            employerName: 'Syntetická společnost s.r.o.',
            employerIdentificationNumber: '00000019',
            employerAddress: 'Testovací 1, 100 00 Praha',
            employeeName: 'Testovací Zaměstnanec',
            previousNames: ['Dřívější Zaměstnanec'],
            personalIdentifierLabel: 'Rodné číslo',
            personalIdentifierValue: '0001010009',
            employeeAddress: 'Modelová 2, 602 00 Brno, CZ',
            months: $months,
            annualSettlementStatus: 'not_performed',
        );
    }

    public function testRejectsDuplicateMonth(): void
    {
        $month = new PayrollSheetMonth(
            month: 1,
            sourceRevisionCount: 1,
            grossMinorUnits: 100,
            cashIncomeMinorUnits: 100,
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
            netPayableMinorUnits: 100,
        );

        $this->expectException(\InvalidArgumentException::class);
        new PayrollSheetDocumentData(
            str_repeat('b', 64),
            2026,
            'Syntetická společnost s.r.o.',
            '00000019',
            'Testovací 1, Praha',
            'Testovací Zaměstnanec',
            [],
            'Datum narození',
            '01.01.2000',
            'Modelová 2, Brno, CZ',
            [$month, $month],
            'not_performed',
        );
    }

    public function testRejectsCompromisedNetAmount(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Čistá výplata');

        new PayrollSheetMonth(
            month: 1,
            sourceRevisionCount: 1,
            grossMinorUnits: 100_000,
            cashIncomeMinorUnits: 100_000,
            nonCashIncomeMinorUnits: 0,
            socialAssessmentBaseMinorUnits: 100_000,
            employeeSocialMinorUnits: 6_500,
            employerSocialMinorUnits: 24_800,
            healthAssessmentBaseMinorUnits: 100_000,
            employeeHealthMinorUnits: 4_500,
            employerHealthMinorUnits: 9_000,
            healthMinimumTopUpMinorUnits: 0,
            advanceTaxBaseMinorUnits: 100_000,
            advanceTaxBeforeCreditsMinorUnits: 15_000,
            nonRefundableCreditsMinorUnits: 2_570,
            childCreditMinorUnits: 0,
            advanceTaxMinorUnits: 12_430,
            taxBonusMinorUnits: 0,
            withholdingTaxMinorUnits: 0,
            otherDeductionsMinorUnits: 0,
            netPayableMinorUnits: 100_000,
        );
    }
}
