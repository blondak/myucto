<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

use MyInvoice\Service\Backup\CanonicalJson;
use MyInvoice\Service\Backup\Registry\TenantDataDefinition;
use MyInvoice\Service\Backup\Registry\TenantDataObjectKind;
use MyInvoice\Service\Backup\Registry\TenantDataRegistry;

/**
 * Streamuje už seřazené řádky do kanonického JSONL a počítá metadata manifestu.
 * SQL výběr a pořadí zůstávají odpovědností navazující snapshotové vrstvy.
 */
final readonly class CompanyBackupJsonlWriter
{
    public function __construct(
        private CompanyBackupArchiveLimits $limits = new CompanyBackupArchiveLimits(),
    ) {}

    /**
     * @param iterable<mixed,mixed> $rows
     */
    public function write(
        TenantDataDefinition $definition,
        int $order,
        iterable $rows,
        string $filePath,
    ): CompanyBackupDataObject {
        self::assertDefinition($definition, $order);
        $registryKey = $definition->key;
        if ($filePath === '' || str_contains($filePath, "\0")) {
            throw new CompanyBackupDataWriteException(
                'data_destination_unwritable',
                $registryKey,
            );
        }
        if (self::destinationExists($filePath)) {
            throw new CompanyBackupDataWriteException(
                'data_destination_exists',
                $registryKey,
            );
        }
        $directory = dirname($filePath);
        if (!is_dir($directory) || !is_writable($directory)) {
            throw new CompanyBackupDataWriteException(
                'data_destination_unwritable',
                $registryKey,
            );
        }

        $stream = @fopen($filePath, 'xb');
        if (!is_resource($stream)) {
            throw new CompanyBackupDataWriteException(
                self::destinationExists($filePath)
                    ? 'data_destination_exists'
                    : 'data_destination_unwritable',
                $registryKey,
            );
        }

        $rowNumber = 0;
        $bytes = 0;
        $hash = hash_init('sha256');
        try {
            if (DIRECTORY_SEPARATOR !== '\\' && !@chmod($filePath, 0600)) {
                throw new CompanyBackupDataWriteException(
                    'data_destination_permissions_failed',
                    $registryKey,
                );
            }
            foreach ($rows as $row) {
                $rowNumber++;
                if (!is_array($row) || array_is_list($row)) {
                    throw new CompanyBackupDataWriteException(
                        'data_row_invalid',
                        $registryKey,
                        $rowNumber,
                    );
                }
                try {
                    $line = CanonicalJson::encode($row) . "\n";
                } catch (\Throwable $e) {
                    throw new CompanyBackupDataWriteException(
                        'data_row_invalid',
                        $registryKey,
                        $rowNumber,
                        $e,
                    );
                }
                $lineBytes = strlen($line);
                if ($lineBytes > $this->limits->maxEntryBytes - $bytes) {
                    throw new CompanyBackupDataWriteException(
                        'data_entry_size_exceeded',
                        $registryKey,
                        $rowNumber,
                    );
                }
                self::writeAll($stream, $line, $registryKey, $rowNumber);
                hash_update($hash, $line);
                $bytes += $lineBytes;
            }
            if (!@fflush($stream)) {
                throw new CompanyBackupDataWriteException(
                    'data_write_failed',
                    $registryKey,
                    $rowNumber === 0 ? null : $rowNumber,
                );
            }
            if (!@fclose($stream)) {
                $stream = null;
                throw new CompanyBackupDataWriteException(
                    'data_write_failed',
                    $registryKey,
                    $rowNumber === 0 ? null : $rowNumber,
                );
            }
            $stream = null;

            clearstatcache(true, $filePath);
            $writtenBytes = @filesize($filePath);
            if (!is_int($writtenBytes) || $writtenBytes !== $bytes || is_link($filePath)) {
                throw new CompanyBackupDataWriteException(
                    'data_write_failed',
                    $registryKey,
                );
            }
            return CompanyBackupDataObject::fromWrittenPayload(
                $definition,
                $order,
                $rowNumber,
                $bytes,
                hash_final($hash),
            );
        } catch (\Throwable $e) {
            if (is_resource($stream)) {
                @fclose($stream);
            }
            @unlink($filePath);
            if ($e instanceof CompanyBackupDataWriteException) {
                throw $e;
            }
            throw new CompanyBackupDataWriteException(
                'data_source_failed',
                $registryKey,
                $rowNumber + 1,
                $e,
            );
        }
    }

    /** @param resource $stream */
    private static function writeAll(
        $stream,
        string $contents,
        string $registryKey,
        int $rowNumber,
    ): void {
        $offset = 0;
        $length = strlen($contents);
        while ($offset < $length) {
            $written = @fwrite($stream, substr($contents, $offset));
            if (!is_int($written) || $written < 1) {
                throw new CompanyBackupDataWriteException(
                    'data_write_failed',
                    $registryKey,
                    $rowNumber,
                );
            }
            $offset += $written;
        }
    }

    private static function assertDefinition(
        TenantDataDefinition $definition,
        int $order,
    ): void {
        if (!in_array(
            $definition->kind,
            [TenantDataObjectKind::Table, TenantDataObjectKind::LogicalObject],
            true,
        )
            || !$definition->policy->hasMachineDataPayload()
            || !$definition->hasProfile(TenantDataRegistry::COMPANY_BACKUP_PROFILE)
            || $order < 1
        ) {
            throw new \InvalidArgumentException(
                'JSONL writer vyžaduje obnovitelný objekt profilu company_backup.',
            );
        }
    }

    /** @phpstan-impure Cíl může souběžně vzniknout mezi kontrolou a fopen(). */
    private static function destinationExists(string $filePath): bool
    {
        clearstatcache(true, $filePath);
        return @lstat($filePath) !== false;
    }
}
