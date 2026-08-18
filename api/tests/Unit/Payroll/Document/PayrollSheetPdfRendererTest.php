<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Document;

use MyInvoice\Service\Payroll\Document\PayrollSheetDocumentData;
use MyInvoice\Service\Payroll\Document\PayrollSheetMonth;
use MyInvoice\Service\Payroll\Document\PayrollSheetPdfRenderer;
use MyInvoice\Service\Payroll\Document\PayrollSheetSnapshotBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Šablona mzdového listu běží pod `strict_variables`, takže chybějící klíč není
 * prázdná buňka, ale výjimka. Doklad se navíc musí vykreslit i pro revizi, která
 * osvobozené částky neeviduje.
 */
final class PayrollSheetPdfRendererTest extends TestCase
{
    public function testRendersRecordedAndNotRecordedTaxDetail(): void
    {
        foreach ([true, false] as $recorded) {
            $rendered = (new PayrollSheetPdfRenderer())->render($this->document($recorded));

            self::assertStringStartsWith('%PDF-', $rendered->bytes);
            self::assertSame(PayrollSheetPdfRenderer::VERSION, $rendered->rendererVersion);
            self::assertSame(
                PayrollSheetSnapshotBuilder::SCHEMA_VERSION,
                $rendered->templateVersion,
            );
        }
    }

    private function document(bool $recorded): PayrollSheetDocumentData
    {
        return new PayrollSheetDocumentData(
            str_repeat('b', 64),
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
                month: 3,
                sourceRevisionCount: 1,
                grossMinorUnits: 1_000_00,
                cashIncomeMinorUnits: 900_00,
                nonCashIncomeMinorUnits: 100_00,
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
                netPayableMinorUnits: 900_00,
                withholdingTaxBaseMinorUnits: $recorded ? 50_00 : 0,
                taxExemptIncomeMinorUnits: $recorded ? 100_00 : 0,
                taxDetailStatus: $recorded
                    ? PayrollSheetMonth::TAX_DETAIL_RECORDED
                    : PayrollSheetMonth::TAX_DETAIL_NOT_RECORDED,
            )],
            'not_performed',
            $recorded ? [[
                'code' => 'HPP-1',
                'relation_type' => 'employment',
                'start_date' => '2026-01-15',
                'actual_start_date' => null,
                'end_date' => null,
            ]] : [],
        );
    }
}
