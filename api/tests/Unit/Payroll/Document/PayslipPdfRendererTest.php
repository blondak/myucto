<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Document;

use MyInvoice\Service\Payroll\Document\PayslipDocumentData;
use MyInvoice\Service\Payroll\Document\PayslipLine;
use MyInvoice\Service\Payroll\Document\PayslipPdfRenderer;
use MyInvoice\Tests\Fixtures\Payroll\SyntheticPayslipFixture;
use PHPUnit\Framework\TestCase;

final class PayslipPdfRendererTest extends TestCase
{
    public function testRendersSyntheticPayslipWithIntegrityMetadata(): void
    {
        $source = SyntheticPayslipFixture::document();
        $rendered = (new PayslipPdfRenderer())->render($source);

        self::assertStringStartsWith('%PDF-', $rendered->pdfBytes);
        self::assertSame(hash('sha256', $rendered->pdfBytes), $rendered->fileSha256);
        self::assertSame(strlen($rendered->pdfBytes), $rendered->sizeBytes);
        self::assertSame('application/pdf', $rendered->mimeType);
        self::assertSame(
            'vyplatni-paska-2026-07-' . substr($rendered->fileSha256, 0, 12) . '.pdf',
            $rendered->suggestedFilename,
        );
        self::assertStringNotContainsString($source->employeeDisplayName, $rendered->suggestedFilename);
        self::assertSame($source->sourceSnapshotSha256, $rendered->sourceSnapshotSha256);
        self::assertSame(PayslipPdfRenderer::VERSION, $rendered->rendererVersion);
        self::assertSame($rendered->fileSha256, $rendered->metadata()['file_sha256']);
        self::assertStringContainsString('PayrollRevision', $rendered->pdfBytes);
        self::assertStringContainsString('PayrollSourceSnapshotSHA256', $rendered->pdfBytes);
        self::assertStringContainsString('PayrollRendererVersion', $rendered->pdfBytes);
        self::assertSame(50_000, $source->taxBonusMinorUnits);
        self::assertSame(0, $source->taxAfterCreditsMinorUnits);

        $qaOutput = getenv('MYINVOICE_PAYSLIP_QA_OUTPUT');
        if (is_string($qaOutput) && $qaOutput !== '') {
            $qaDir = dirname($qaOutput);
            if (!is_dir($qaDir)) {
                mkdir($qaDir, 0755, true);
            }
            file_put_contents($qaOutput, $rendered->pdfBytes);
        }
    }

    public function testAcceptsNegativeCorrectionLinesWhenAggregatesRemainNonNegative(): void
    {
        $source = SyntheticPayslipFixture::document();

        self::assertSame(-10_000, $source->incomeLines[1]->amountMinorUnits);
        self::assertSame(4_790_000, $source->grossMinorUnits);
        self::assertSame(-2_000, $source->otherDeductionLines[1]->amountMinorUnits);
        self::assertSame(8_000, $source->totalOtherDeductionsMinorUnits());
        self::assertSame(4_276_360, $source->netMinorUnits);
    }

    public function testHealthMinimumTopUpReducesNetPayExplicitly(): void
    {
        $source = SyntheticPayslipFixture::document(healthMinimumTopUpMinorUnits: 12_300);

        self::assertSame(12_300, $source->healthMinimumTopUpMinorUnits);
        self::assertSame(4_264_060, $source->netMinorUnits);
        self::assertSame(575_940, $source->totalEmployeeDeductionsMinorUnits());
    }

    public function testRejectsTaxBonusTogetherWithPositiveTax(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('mutually exclusive');

        SyntheticPayslipFixture::document(
            taxNonRefundableCreditsMinorUnits: 0,
            taxChildCreditMinorUnits: 0,
            taxAfterCreditsMinorUnits: 718_500,
            taxBonusMinorUnits: 50_000,
        );
    }

    public function testRejectsTaxBonusThatDoesNotFollowFromCredits(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Tax bonus does not match');

        SyntheticPayslipFixture::document(taxBonusMinorUnits: 90_000);
    }

    public function testRejectsACompromisedNetAmount(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Net pay does not match');

        new PayslipDocumentData(
            revisionId: 'MZ16-INVALID',
            sourceSnapshotSha256: str_repeat('a', 64),
            employerName: 'Syntetický zaměstnavatel',
            employerIdentificationNumber: '00000000',
            employeeDisplayName: 'Syntetický zaměstnanec',
            period: '2026-07',
            employmentLabel: 'Pracovní poměr',
            incomeLines: [new PayslipLine('Základní mzda', 100_000)],
            grossMinorUnits: 100_000,
            employeeSocialMinorUnits: 7_100,
            employeeHealthMinorUnits: 4_500,
            healthMinimumTopUpMinorUnits: 0,
            taxBaseMinorUnits: 100_000,
            taxBeforeCreditsMinorUnits: 15_000,
            taxNonRefundableCreditsMinorUnits: 0,
            taxChildCreditMinorUnits: 0,
            taxAfterCreditsMinorUnits: 15_000,
            taxBonusMinorUnits: 0,
            otherDeductionLines: [new PayslipLine('Syntetická srážka', 1_000)],
            roundingAdjustmentMinorUnits: 0,
            netMinorUnits: 99_999,
            employerSocialMinorUnits: 24_800,
            employerHealthMinorUnits: 9_000,
            grossExpenseAccount: '521',
            grossLiabilityAccount: '331',
            insuranceExpenseAccount: '524',
            insuranceLiabilityAccount: '336',
        );
    }
}
