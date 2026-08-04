<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Document;

use MyInvoice\Service\Payroll\Document\AverageEarningsCertificateDocumentData;
use MyInvoice\Service\Payroll\Document\AverageEarningsCertificatePdfRenderer;
use MyInvoice\Service\Payroll\Document\EmploymentCertificateDeduction;
use MyInvoice\Service\Payroll\Document\EmploymentCertificateDocumentData;
use MyInvoice\Service\Payroll\Document\EmploymentCertificatePdfRenderer;
use MyInvoice\Service\Payroll\Document\PayrollDocumentEmployerSnapshot;
use MyInvoice\Service\Payroll\Document\PayrollDocumentKind;
use PHPUnit\Framework\TestCase;

final class EmploymentCertificateDocumentTest extends TestCase
{
    public function testRendersEmploymentCertificateFromCompleteEvidence(): void
    {
        $data = new EmploymentCertificateDocumentData(
            sourceSnapshotSha256: str_repeat('a', 64),
            employer: self::employer(),
            employeeName: 'Syntetická Zaměstnankyně',
            employeeBirthDate: '1990-05-17',
            employeeAddress: 'Testovací 10, 100 00 Praha',
            relationshipKind: 'employment',
            employmentFrom: '2022-04-01',
            employmentTo: '2026-07-31',
            workDescription: 'Účetní specialista',
            achievedQualification: 'Úplné střední odborné vzdělání',
            exposureAssessmentComplete: true,
            exposureFacts: [],
            deductionAssessmentComplete: true,
            deductions: [
                new EmploymentCertificateDeduction(
                    beneficiary: 'Syntetický oprávněný',
                    claimAmountMinorUnits: 250_000,
                    withheldAmountMinorUnits: 75_000,
                    priorityDate: '2025-02-03',
                    orderingAuthority: 'Syntetický orgán',
                    decisionReference: 'SYNTH-2025-001',
                ),
            ],
            pensionCategoryAssessmentComplete: true,
            pre1993PensionCategoryPeriods: [],
            issuedAt: '2026-08-03',
        );

        $artifact = (new EmploymentCertificatePdfRenderer())->render($data);

        self::assertSame(PayrollDocumentKind::EmploymentCertificate, $artifact->kind);
        self::assertStringStartsWith('%PDF-', $artifact->bytes);
        self::assertSame(
            'potvrzeni-o-zamestnani-' . substr(hash('sha256', $artifact->bytes), 0, 12) . '.pdf',
            $artifact->suggestedFilename,
        );
        self::assertStringNotContainsString(
            $data->employeeName,
            $artifact->suggestedFilename,
        );
        self::assertStringContainsString(
            'PayrollSourceSnapshotSHA256',
            $artifact->bytes,
        );

        self::writeQaArtifact(
            'MYINVOICE_EMPLOYMENT_CERTIFICATE_QA_OUTPUT',
            $artifact->bytes,
        );
    }

    public function testEmploymentCertificateFailsClosedOnMissingAssessment(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('srážek');

        new EmploymentCertificateDocumentData(
            sourceSnapshotSha256: str_repeat('a', 64),
            employer: self::employer(),
            employeeName: 'Syntetický Zaměstnanec',
            employeeBirthDate: '1990-05-17',
            employeeAddress: 'Testovací 10, 100 00 Praha',
            relationshipKind: 'employment',
            employmentFrom: '2022-04-01',
            employmentTo: '2026-07-31',
            workDescription: 'Účetní specialista',
            achievedQualification: 'Úplné střední odborné vzdělání',
            exposureAssessmentComplete: true,
            exposureFacts: [],
            deductionAssessmentComplete: false,
            deductions: [],
            pensionCategoryAssessmentComplete: true,
            pre1993PensionCategoryPeriods: [],
            issuedAt: '2026-08-03',
        );
    }

    public function testRendersSeparateAverageEarningsCertificate(): void
    {
        $data = new AverageEarningsCertificateDocumentData(
            sourceSnapshotSha256: str_repeat('b', 64),
            averageSnapshotSha256: str_repeat('c', 64),
            employer: self::employer(),
            employeeName: 'Syntetický Zaměstnanec',
            employeeBirthDate: '1990-05-17',
            employeeAddress: 'Testovací 10, 100 00 Praha',
            relationshipKind: 'employment',
            employmentFrom: '2022-04-01',
            employmentTo: '2026-07-31',
            pensionInsurancePeriods: [
                ['from' => '2024-08-01', 'to' => '2026-07-31'],
            ],
            averageKind: 'actual',
            averageApplicableYear: 2026,
            averageApplicableQuarter: 3,
            averageMonthlyNetMinorUnits: 3_245_600,
            terminationReasonKind: 'organizational',
            employeeStatedReason: null,
            issuedAt: '2026-08-03',
        );

        $artifact = (new AverageEarningsCertificatePdfRenderer())->render($data);

        self::assertSame(
            PayrollDocumentKind::AverageEarningsCertificate,
            $artifact->kind,
        );
        self::assertStringStartsWith('%PDF-', $artifact->bytes);
        self::assertSame(
            'potvrzeni-pro-podporu-v-nezamestnanosti-'
                . substr(hash('sha256', $artifact->bytes), 0, 12)
                . '.pdf',
            $artifact->suggestedFilename,
        );
        self::assertStringContainsString(
            'PayrollAverageSnapshotSHA256',
            $artifact->bytes,
        );

        self::writeQaArtifact(
            'MYINVOICE_AVERAGE_EARNINGS_CERTIFICATE_QA_OUTPUT',
            $artifact->bytes,
        );
    }

    public function testAverageCertificateRejectsUnsupportedTerminationReason(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Důvod');

        new AverageEarningsCertificateDocumentData(
            sourceSnapshotSha256: str_repeat('b', 64),
            averageSnapshotSha256: str_repeat('c', 64),
            employer: self::employer(),
            employeeName: 'Syntetický Zaměstnanec',
            employeeBirthDate: '1990-05-17',
            employeeAddress: 'Testovací 10, 100 00 Praha',
            relationshipKind: 'employment',
            employmentFrom: '2022-04-01',
            employmentTo: '2026-07-31',
            pensionInsurancePeriods: [],
            averageKind: 'actual',
            averageApplicableYear: 2026,
            averageApplicableQuarter: 3,
            averageMonthlyNetMinorUnits: 3_245_600,
            terminationReasonKind: 'unknown',
            employeeStatedReason: null,
            issuedAt: '2026-08-03',
        );
    }

    private static function employer(): PayrollDocumentEmployerSnapshot
    {
        return new PayrollDocumentEmployerSnapshot(
            name: 'Syntetický zaměstnavatel s.r.o.',
            identificationNumber: '00000000',
            taxIdentificationNumber: 'CZ00000000',
            streetLine: 'Testovací 1',
            city: 'Praha',
            postalCode: '10000',
            countryCode: 'CZ',
            countryName: 'Česká republika',
            issuerName: 'Syntetická mzdová účetní',
            issuerEmail: 'synthetic@example.test',
            issuerPhone: '+420 200 000 000',
        );
    }

    private static function writeQaArtifact(
        string $environmentVariable,
        string $bytes,
    ): void {
        $qaOutput = getenv($environmentVariable);
        if (!is_string($qaOutput) || $qaOutput === '') {
            return;
        }
        $directory = dirname($qaOutput);
        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }
        file_put_contents($qaOutput, $bytes);
    }
}
