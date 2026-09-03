<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Backup\Registry\TenantDataRegistrySnapshot;

/** Produkční složení konzistentního snapshotu a atomicky zveřejněného ZIPu. */
final readonly class CompanyBackupExportPipelineService implements
    CompanyBackupExportPipeline
{
    public function __construct(
        private Connection $db,
        private CompanyBackupRegistrySnapshotProvider $registry,
        private CompanyBackupMachineSnapshotExporter $snapshotExporter,
        private CompanyBackupDataRowSource $rows,
        private CompanyBackupFileReferenceSource $files,
        private CompanyBackupMachineArchiveWriter $archiveWriter,
        private CompanyBackupArtifactStorage $storage,
        private CompanyBackupWorkDirectory $workDirectories,
        private CompanyBackupSourceMetadata $metadata,
    ) {}

    public function check(array $job): void
    {
        $this->registryFor($job);
    }

    public function export(
        array $job,
        #[\SensitiveParameter] string $password,
        \Closure $beforePackaging,
    ): CompanyBackupStoredArtifact {
        CompanyBackupPasswordPolicy::assertValid($password);
        $registry = $this->registryFor($job);
        [$supplierId, $backupId] = self::coordinates($job);
        $version = $this->metadata->version();
        $workDirectory = $this->workDirectories->create($backupId);
        $destinationPrepared = false;
        $artifact = null;
        $failure = null;

        try {
            $snapshot = $this->snapshotExporter->export(
                $this->db->pdo(),
                $registry,
                $supplierId,
                $workDirectory,
                $this->rows,
                $this->files,
                $backupId,
                $password,
            );
            $beforePackaging();
            $destination = $this->storage->prepareDestination(
                $supplierId,
                $backupId,
            );
            $destinationPrepared = true;
            $result = $this->archiveWriter->write(
                $snapshot,
                $destination,
                $password,
                $version,
                $this->metadata->readme($backupId, $version),
            );
            $artifact = $this->storage->capture(
                $supplierId,
                $backupId,
                $result,
            );
        } catch (\Throwable $e) {
            $failure = $e;
        }

        try {
            $this->workDirectories->cleanup($workDirectory);
        } catch (\Throwable $e) {
            $failure = new CompanyBackupSnapshotException(
                'snapshot_cleanup_failed',
                $failure ?? $e,
            );
        }

        if ($failure !== null) {
            if ($destinationPrepared) {
                try {
                    $this->storage->discardDestination($supplierId, $backupId);
                } catch (\Throwable $cleanupError) {
                    throw new CompanyBackupJobException(
                        'artifact_cleanup_failed',
                        $cleanupError,
                    );
                }
            }
            throw $failure;
        }
        return $artifact ?? throw new \LogicException(
            'Exportní pipeline nedodala archiv ani chybu.',
        );
    }

    public function discard(CompanyBackupStoredArtifact $artifact): void
    {
        $this->storage->remove($artifact);
    }

    /**
     * @param array<string,mixed> $job
     * @return array{int,string}
     */
    private static function coordinates(array $job): array
    {
        $supplierId = (int) ($job['supplier_id'] ?? 0);
        $backupId = (string) ($job['backup_id'] ?? '');
        if ($supplierId < 1
            || !CompanyBackupManifestHeader::isCanonicalBackupId($backupId)
        ) {
            throw new CompanyBackupJobException('job_metadata_invalid');
        }
        return [$supplierId, $backupId];
    }

    /** @param array<string,mixed> $job */
    private function registryFor(array $job): TenantDataRegistrySnapshot
    {
        self::coordinates($job);
        $expected = $job['registry_fingerprint'] ?? null;
        if (!is_string($expected)
            || preg_match('/^sha256:[0-9a-f]{64}$/D', $expected) !== 1
        ) {
            throw new CompanyBackupJobException('job_metadata_invalid');
        }
        $registry = $this->registry->current();
        if (!hash_equals($expected, $registry->fingerprint)) {
            throw new CompanyBackupJobException('registry_changed');
        }
        return $registry;
    }
}
