<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup\Company;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Backup\Company\CompanyBackupArtifactStorage;
use MyInvoice\Service\Backup\Company\CompanyBackupDataRowSource;
use MyInvoice\Service\Backup\Company\CompanyBackupExportPipelineService;
use MyInvoice\Service\Backup\Company\CompanyBackupFileReferenceSource;
use MyInvoice\Service\Backup\Company\CompanyBackupJobException;
use MyInvoice\Service\Backup\Company\CompanyBackupMachineArchiveWriter;
use MyInvoice\Service\Backup\Company\CompanyBackupMachineSnapshotExporter;
use MyInvoice\Service\Backup\Company\CompanyBackupRegistrySnapshotProvider;
use MyInvoice\Service\Backup\Company\CompanyBackupSourceMetadata;
use MyInvoice\Service\Backup\Company\CompanyBackupWorkDirectory;
use MyInvoice\Service\Backup\Company\CurrentCompanyBackupRegistrySnapshotProvider;
use MyInvoice\Service\Backup\Registry\IncompleteTenantDataRegistry;
use MyInvoice\Service\Backup\Registry\TenantDataDefinition;
use MyInvoice\Service\Backup\Registry\TenantDataObjectKind;
use MyInvoice\Service\Backup\Registry\TenantDataPolicy;
use MyInvoice\Service\Backup\Registry\TenantDataRegistry;
use MyInvoice\Service\Backup\Registry\TenantDataRegistryFactory;
use MyInvoice\Service\Backup\Registry\TenantDataRegistrySnapshot;
use PHPUnit\Framework\TestCase;

final class CompanyBackupExportPipelineServiceTest extends TestCase
{
    private const BACKUP_ID = '0191f7a0-7c22-7bd1-8cd4-6e18cb55b8a1';

    public function testCheckPinsWorkerToFingerprintStoredAtCreation(): void
    {
        $snapshot = self::snapshot();
        $provider = $this->createMock(CompanyBackupRegistrySnapshotProvider::class);
        $provider->expects(self::exactly(2))->method('current')->willReturn($snapshot);
        $pipeline = $this->pipeline($provider);

        try {
            $pipeline->check($this->job('sha256:' . str_repeat('f', 64)));
            self::fail('Worker nesmí exportovat přes jinou verzi registru.');
        } catch (CompanyBackupJobException $e) {
            self::assertSame('registry_changed', $e->errorCode);
        }

        $pipeline->check($this->job($snapshot->fingerprint));
    }

    public function testProductionDraftRegistryRemainsFailClosed(): void
    {
        $provider = new CurrentCompanyBackupRegistrySnapshotProvider(
            TenantDataRegistryFactory::draftV1(),
        );

        $this->expectException(IncompleteTenantDataRegistry::class);
        $provider->current();
    }

    private function pipeline(
        CompanyBackupRegistrySnapshotProvider $provider,
    ): CompanyBackupExportPipelineService {
        return new CompanyBackupExportPipelineService(
            $this->createStub(Connection::class),
            $provider,
            new CompanyBackupMachineSnapshotExporter(),
            $this->createStub(CompanyBackupDataRowSource::class),
            $this->createStub(CompanyBackupFileReferenceSource::class),
            new CompanyBackupMachineArchiveWriter(),
            new CompanyBackupArtifactStorage(),
            new CompanyBackupWorkDirectory(),
            new CompanyBackupSourceMetadata(),
        );
    }

    /** @return array<string,mixed> */
    private function job(string $fingerprint): array
    {
        return [
            'backup_id' => self::BACKUP_ID,
            'supplier_id' => 41,
            'registry_fingerprint' => $fingerprint,
        ];
    }

    private static function snapshot(): TenantDataRegistrySnapshot
    {
        $profile = TenantDataRegistry::COMPANY_BACKUP_PROFILE;
        return TenantDataRegistrySnapshot::fromRegistry(
            new TenantDataRegistry(
                1,
                [new TenantDataDefinition(
                    'logical:synthetic-ready',
                    TenantDataObjectKind::LogicalObject,
                    TenantDataPolicy::RuntimeDerived,
                    [$profile],
                    [],
                )],
                [$profile],
            ),
            $profile,
        );
    }
}
