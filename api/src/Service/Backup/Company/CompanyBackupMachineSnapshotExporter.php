<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

use MyInvoice\Service\Backup\Registry\TenantDataRegistrySnapshot;
use PDO;

/** Sestaví všechny registrované JSONL položky nad jediným DB read view. */
final readonly class CompanyBackupMachineSnapshotExporter
{
    public function __construct(
        private CompanyBackupSnapshotTransaction $transaction = new CompanyBackupSnapshotTransaction(),
        private CompanyBackupJsonlWriter $jsonlWriter = new CompanyBackupJsonlWriter(),
    ) {}

    public function export(
        PDO $pdo,
        TenantDataRegistrySnapshot $registry,
        int $supplierId,
        string $workDirectory,
        CompanyBackupDataRowSource $source,
    ): CompanyBackupMachineSnapshot {
        if ($supplierId < 1) {
            throw new \InvalidArgumentException('Firma snapshotu musí mít kladné ID.');
        }
        $resolvedDirectory = realpath($workDirectory);
        if (!is_string($resolvedDirectory)
            || !is_dir($resolvedDirectory)
            || !is_writable($resolvedDirectory)
        ) {
            throw new CompanyBackupSnapshotException('snapshot_work_directory_unwritable');
        }

        $createdFiles = [];
        try {
            return $this->transaction->run(
                $pdo,
                function (PDO $snapshot) use (
                    $registry,
                    $supplierId,
                    $resolvedDirectory,
                    $source,
                    &$createdFiles,
                ): CompanyBackupMachineSnapshot {
                    $objects = [];
                    $sourceFiles = [];
                    foreach (CompanyBackupDataInventory::payloadDefinitions($registry) as $index => $definition) {
                        $filePath = $resolvedDirectory . DIRECTORY_SEPARATOR
                            . 'company-data-' . bin2hex(random_bytes(16)) . '.jsonl';
                        $object = $this->jsonlWriter->write(
                            $definition,
                            $index + 1,
                            $source->rows($snapshot, $supplierId, $definition),
                            $filePath,
                        );
                        $createdFiles[] = $filePath;
                        $objects[] = $object;
                        $sourceFiles[$object->path] = $filePath;
                    }
                    $inventory = CompanyBackupDataInventory::fromObjects($objects, $registry);
                    return new CompanyBackupMachineSnapshot(
                        $supplierId,
                        $registry,
                        $inventory,
                        $sourceFiles,
                    );
                },
            );
        } catch (\Throwable $e) {
            if (!self::cleanup($createdFiles)) {
                throw new CompanyBackupSnapshotException('snapshot_cleanup_failed', $e);
            }
            throw $e;
        }
    }

    /** @param list<string> $files */
    private static function cleanup(array $files): bool
    {
        $clean = true;
        foreach ($files as $file) {
            clearstatcache(true, $file);
            if ((is_file($file) || is_link($file)) && !@unlink($file)) {
                $clean = false;
            }
        }
        return $clean;
    }
}
