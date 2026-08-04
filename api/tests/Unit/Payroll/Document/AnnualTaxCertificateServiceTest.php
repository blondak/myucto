<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Document;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Payroll\Document\AnnualTaxCertificateDocumentData;
use MyInvoice\Service\Payroll\Document\AnnualTaxCertificatePdfRenderer;
use MyInvoice\Service\Payroll\Document\AnnualTaxCertificateService;
use MyInvoice\Service\Payroll\Document\AnnualTaxCertificateSnapshotBuilder;
use MyInvoice\Service\Payroll\Document\PayrollArtifact;
use MyInvoice\Service\Payroll\Document\PayrollDocumentKind;
use MyInvoice\Service\Payroll\Document\PayrollDocumentService;
use MyInvoice\Service\Payroll\Document\PayrollDocumentStorageScope;
use PDO;
use PHPUnit\Framework\TestCase;

final class AnnualTaxCertificateServiceTest extends TestCase
{
    public function testAuditFailureRollsBackDatabaseAndNewStorage(): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->method('inTransaction')->willReturnOnConsecutiveCalls(
            false,
            true,
        );
        $pdo->expects(self::once())->method('beginTransaction');
        $pdo->expects(self::never())->method('commit');
        $pdo->expects(self::once())->method('rollBack');
        $lock = $this->createMock(\PDOStatement::class);
        $lock->expects(self::once())->method('execute')
            ->with(['payroll-document-storage:supplier:11']);
        $lock->expects(self::once())->method('fetchColumn')->willReturn(1);
        $release = $this->createMock(\PDOStatement::class);
        $release->expects(self::once())->method('execute')
            ->with(['payroll-document-storage:supplier:11']);
        $release->expects(self::once())->method('fetchColumn')->willReturn(1);
        $pdo->expects(self::exactly(2))->method('prepare')
            ->willReturnOnConsecutiveCalls($lock, $release);

        $connection = (new \ReflectionClass(Connection::class))
            ->newInstanceWithoutConstructor();
        $pdoProperty = new \ReflectionProperty(Connection::class, 'pdo');
        $pdoProperty->setValue($connection, $pdo);
        $snapshot = $this->createMock(
            AnnualTaxCertificateSnapshotBuilder::class,
        );
        $data = (new \ReflectionClass(
            AnnualTaxCertificateDocumentData::class,
        ))->newInstanceWithoutConstructor();
        $issuedAt = new \ReflectionProperty(
            AnnualTaxCertificateDocumentData::class,
            'issuedAt',
        );
        $issuedAt->setValue($data, '2026-08-04 10:20:30');
        $snapshot->expects(self::once())
            ->method('build')
            ->willReturn([
                'revision' => ['id' => 71],
                'document' => $data,
            ]);
        $artifact = new PayrollArtifact(
            PayrollDocumentKind::TaxableIncomeAdvanceCertificate,
            '%PDF-1.7 synthetic',
            'application/pdf',
            'tax-certificate-synthetic.pdf',
            str_repeat('a', 64),
            'template-v1',
            'renderer-v1',
        );
        $renderer = $this->createMock(AnnualTaxCertificatePdfRenderer::class);
        $renderer->expects(self::once())
            ->method('render')
            ->with($data)
            ->willReturn($artifact);

        $scope = new PayrollDocumentStorageScope();
        $documents = $this->createMock(PayrollDocumentService::class);
        $documents->expects(self::once())
            ->method('beginStorageScope')
            ->willReturn($scope);
        $documents->expects(self::once())
            ->method('archiveAnnualPdf')
            ->willReturn([
                'id' => 81,
                'annual_revision_id' => 71,
                'document_kind' =>
                    PayrollDocumentKind::TaxableIncomeAdvanceCertificate->value,
                'file_sha256' => str_repeat('b', 64),
                'created_at' => '2026-08-04 10:20:30',
            ]);
        $documents->expects(self::never())->method('commitStorageScope');
        $documents->expects(self::once())
            ->method('cleanupStorageScope')
            ->with(11, $scope);

        $service = new AnnualTaxCertificateService(
            $connection,
            $snapshot,
            $renderer,
            $documents,
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('synthetic audit failure');
        $service->generate(
            11,
            21,
            2026,
            PayrollDocumentKind::TaxableIncomeAdvanceCertificate,
            31,
            static function (): never {
                throw new \RuntimeException('synthetic audit failure');
            },
        );
    }
}
