<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

use MyInvoice\Service\Backup\Registry\TenantDataDefinition;
use PDO;
use ZipArchive;

/**
 * Dvouprůchodová, read-only kontrola úplného zdrojového grafu JSONL.
 * První průchod naplní dočasný SQL index, druhý ověří každou referenci.
 */
final readonly class CompanyBackupDataPreflight
{
    private CompanyBackupJsonlReader $reader;

    public function __construct(
        private CompanyBackupArchiveLimits $limits = new CompanyBackupArchiveLimits(),
    ) {
        $this->reader = new CompanyBackupJsonlReader($limits);
    }

    public function inspect(
        string $archivePath,
        #[\SensitiveParameter] string $password,
        CompanyBackupTechnicalValidation $validation,
        PDO $database,
    ): CompanyBackupDataPreflightResult {
        if ($password === '' || strlen($password) > 1_024) {
            throw new CompanyBackupPreflightException(
                'source_archive_unlock_failed',
            );
        }

        $expectedArchiveHash = $validation->inspection->archiveSha256;
        $before = $this->archiveState($archivePath);
        if (!hash_equals($expectedArchiveHash, $before['sha256'])) {
            throw new CompanyBackupPreflightException('source_archive_changed');
        }

        $contexts = $this->contexts($validation);
        $zip = new ZipArchive();
        $opened = $zip->open($archivePath, ZipArchive::RDONLY);
        if ($opened !== true) {
            throw new CompanyBackupPreflightException('source_archive_unreadable');
        }

        $index = null;
        $result = null;
        $failure = null;
        try {
            if (!$zip->setPassword($password)) {
                throw new CompanyBackupPreflightException(
                    'source_archive_unlock_failed',
                );
            }
            $index = new CompanyBackupSqlSourceIdentityIndex($database, $this->limits);
            $rowCount = 0;
            foreach ($validation->inspection->dataInventory->objects as $object) {
                $context = $contexts[$object->registryKey];
                $this->consumeRows(
                    $zip,
                    $context['definition'],
                    $object,
                    null,
                    function (array $row) use (
                        $index,
                        $context,
                        &$rowCount,
                    ): void {
                        $index->add($context['identity']->identityForRow($row));
                        $rowCount++;
                    },
                );
            }
            $index->seal();

            $collector = new CompanyBackupExternalReferenceCollector($this->limits);
            $integrity = new CompanyBackupReferenceIntegrityValidator(
                $index,
                $this->limits,
            );
            $referenceOccurrenceCount = 0;
            foreach ($validation->inspection->dataInventory->objects as $object) {
                $context = $contexts[$object->registryKey];
                $this->consumeRows(
                    $zip,
                    $context['definition'],
                    $object,
                    function (CompanyBackupReferenceOccurrence $occurrence) use (
                        $collector,
                        $integrity,
                        &$referenceOccurrenceCount,
                    ): void {
                        $referenceOccurrenceCount++;
                        if ($referenceOccurrenceCount
                            > $this->limits->maxReferenceOccurrences
                        ) {
                            throw new CompanyBackupPreflightException(
                                'source_reference_occurrence_limit_exceeded',
                                $occurrence->sourceRegistryKey,
                                $occurrence->sourceColumn,
                            );
                        }
                        $collector->accept($integrity->normalize($occurrence));
                    },
                    static function (array $row): void {},
                );
            }

            $result = new CompanyBackupDataPreflightResult(
                $collector->finish(),
                $rowCount,
                $index->identityCount(),
                $index->entryCount(),
                $index->indexedBytes(),
                $referenceOccurrenceCount,
                $validation->targetRegistryFingerprint,
                $validation->bindingSha256,
            );
        } catch (\Throwable $e) {
            $failure = $e;
        }

        if ($index instanceof CompanyBackupSourceIdentityIndex) {
            try {
                $index->close();
            } catch (\Throwable $e) {
                $failure ??= $e;
            }
        }
        try {
            if (!$zip->close()) {
                throw new CompanyBackupPreflightException(
                    'source_archive_close_failed',
                );
            }
        } catch (\Throwable $e) {
            $failure ??= $e;
        }

        if ($failure instanceof \Throwable) {
            throw $failure;
        }
        if (!$result instanceof CompanyBackupDataPreflightResult) {
            throw new \LogicException('Datový preflight nevytvořil výsledek.');
        }

        $after = $this->archiveState($archivePath);
        if ($after['size'] !== $before['size']
            || $after['mtime'] !== $before['mtime']
            || !hash_equals($before['sha256'], $after['sha256'])
        ) {
            throw new CompanyBackupPreflightException('source_archive_changed');
        }
        return $result;
    }

    /**
     * @return array<string,array{
     *   definition:TenantDataDefinition,
     *   identity:CompanyBackupSourceIdentityProjection
     * }>
     */
    private function contexts(
        CompanyBackupTechnicalValidation $validation,
    ): array {
        $contexts = [];
        $expectedRows = 0;
        $sourceRegistry = $validation->inspection->sourceRegistry->registry;
        foreach ($validation->inspection->dataInventory->objects as $object) {
            $definition = $sourceRegistry->definition($object->registryKey);
            if (!$definition instanceof TenantDataDefinition) {
                throw new CompanyBackupPreflightException(
                    'source_registry_object_missing',
                    $object->registryKey,
                );
            }
            if ($object->rows
                > $this->limits->maxSourceIdentities - $expectedRows
            ) {
                throw new CompanyBackupPreflightException(
                    'source_identity_limit_exceeded',
                    $object->registryKey,
                );
            }
            try {
                $projection = CompanyBackupTableProjection::fromDefinition($definition);
                $projection->assertRegistryTargets($sourceRegistry);
            } catch (CompanyBackupDataSourceException $e) {
                throw new CompanyBackupPreflightException(
                    $e->errorCode,
                    $e->registryKey,
                    $e->column,
                    $e,
                );
            }
            $contexts[$object->registryKey] = [
                'definition' => $definition,
                'identity' => CompanyBackupSourceIdentityProjection::fromDefinition(
                    $definition,
                    $this->limits,
                ),
            ];
            $expectedRows += $object->rows;
        }
        return $contexts;
    }

    /**
     * @param null|callable(CompanyBackupReferenceOccurrence):void $referenceVisitor
     * @param callable(array<string,mixed>):void $rowVisitor
     */
    private function consumeRows(
        ZipArchive $zip,
        TenantDataDefinition $definition,
        CompanyBackupDataObject $object,
        ?callable $referenceVisitor,
        callable $rowVisitor,
    ): void {
        $index = $zip->locateName($object->path);
        if (!is_int($index)) {
            throw new CompanyBackupPreflightException(
                'source_data_entry_missing',
                $object->registryKey,
            );
        }
        $stat = $zip->statIndex($index);
        if (!is_array($stat)
            || $stat['name'] !== $object->path
            || $stat['size'] !== $object->bytes
        ) {
            throw new CompanyBackupPreflightException(
                'source_data_entry_changed',
                $object->registryKey,
            );
        }
        $stream = @$zip->getStreamIndex($index);
        if (!is_resource($stream)) {
            throw new CompanyBackupPreflightException(
                'source_data_entry_unreadable',
                $object->registryKey,
            );
        }
        try {
            foreach ($this->reader->rows(
                $stream,
                $definition,
                $object,
                $referenceVisitor,
            ) as $row) {
                $rowVisitor($row);
            }
        } finally {
            @fclose($stream);
        }
    }

    /** @return array{size:int,mtime:int,sha256:string} */
    private function archiveState(string $archivePath): array
    {
        clearstatcache(true, $archivePath);
        $before = @stat($archivePath);
        if ($before === false
            || !is_file($archivePath)
            || is_link($archivePath)
            || $before['size'] < 1
            || $before['size'] > $this->limits->maxArchiveBytes
        ) {
            throw new CompanyBackupPreflightException('source_archive_unreadable');
        }
        $sha256 = @hash_file('sha256', $archivePath);
        if (!is_string($sha256)) {
            throw new CompanyBackupPreflightException('source_archive_unreadable');
        }
        clearstatcache(true, $archivePath);
        $after = @stat($archivePath);
        if ($after === false
            || $after['size'] !== $before['size']
            || $after['mtime'] !== $before['mtime']
        ) {
            throw new CompanyBackupPreflightException('source_archive_changed');
        }
        return [
            'size' => $before['size'],
            'mtime' => $before['mtime'],
            'sha256' => $sha256,
        ];
    }
}
