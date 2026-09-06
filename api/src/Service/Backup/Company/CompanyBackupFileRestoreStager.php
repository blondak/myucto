<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/** Materializuje ověřené archivní soubory do izolovaného flat stagingu. */
final readonly class CompanyBackupFileRestoreStager
{
    public function __construct(
        private CompanyBackupFileStagingRootResolver $roots =
            new CompanyBackupRuntimeFileStagingRootResolver(),
    ) {}

    public function stage(
        CompanyBackupImportFileSource $source,
        string $backupId,
    ): CompanyBackupStagedFileSet {
        if (!CompanyBackupManifestHeader::isCanonicalBackupId($backupId)) {
            throw self::error('file_staging_backup_id_invalid');
        }
        $root = $this->root();
        try {
            $suffix = bin2hex(random_bytes(8));
        } catch (\Throwable $e) {
            throw self::error('file_staging_unavailable', previous: $e);
        }
        $directory = $root . DIRECTORY_SEPARATOR
            . 'restore-' . $backupId . '-' . $suffix;
        if (!@mkdir($directory, 0700)) {
            throw self::error('file_staging_unavailable');
        }
        if (PHP_OS_FAMILY !== 'Windows' && !@chmod($directory, 0700)) {
            @rmdir($directory);
            throw self::error('file_staging_unavailable');
        }
        $realDirectory = realpath($directory);
        if (!is_string($realDirectory)
            || is_link($directory)
            || !self::samePath($realDirectory, $directory)
            || !self::inside($realDirectory, $root)
        ) {
            @rmdir($directory);
            throw self::error('file_staging_unavailable');
        }

        $files = [];
        try {
            foreach ($source->fileInventory()->archiveFiles() as $archivePath => $expected) {
                $registryKey = self::registryKey($archivePath);
                $target = $realDirectory . DIRECTORY_SEPARATOR
                    . 'entry-' . hash('sha256', $archivePath) . '.bin';
                $path = $this->stageFile(
                    $source,
                    $archivePath,
                    $expected,
                    $target,
                    $realDirectory,
                    $registryKey,
                );
                $files[$archivePath] = [
                    'path' => $path,
                    'bytes' => $expected['bytes'],
                    'sha256' => $expected['sha256'],
                ];
            }
            return new CompanyBackupStagedFileSet(
                $root,
                $realDirectory,
                $files,
            );
        } catch (\Throwable $e) {
            self::bestEffortCleanup($realDirectory);
            throw $e;
        }
    }

    /**
     * @param array{sha256:string,bytes:int} $expected
     */
    private function stageFile(
        CompanyBackupImportFileSource $source,
        string $archivePath,
        array $expected,
        string $target,
        string $directory,
        string $registryKey,
    ): string {
        $handle = @fopen($target, 'x+b');
        if (!is_resource($handle)) {
            throw self::error('file_staging_entry_unwritable', $registryKey);
        }
        $bytes = 0;
        $failure = null;
        try {
            $hash = hash_init('sha256');
            $consumed = $source->consumeFile(
                $archivePath,
                static function (string $chunk) use (
                    $handle,
                    $expected,
                    &$bytes,
                    $hash,
                    $registryKey,
                ): void {
                    $length = strlen($chunk);
                    if ($length < 1
                        || $length > $expected['bytes'] - $bytes
                    ) {
                        throw self::error(
                            'file_staging_content_mismatch',
                            $registryKey,
                        );
                    }
                    $offset = 0;
                    while ($offset < $length) {
                        $written = @fwrite($handle, substr($chunk, $offset));
                        if (!is_int($written) || $written < 1) {
                            throw self::error(
                                'file_staging_entry_unwritable',
                                $registryKey,
                            );
                        }
                        $offset += $written;
                    }
                    $bytes += $length;
                    hash_update($hash, $chunk);
                },
            );
            if ($consumed !== $expected['bytes']
                || $bytes !== $expected['bytes']
                || !hash_equals($expected['sha256'], hash_final($hash))
            ) {
                throw self::error(
                    'file_staging_content_mismatch',
                    $registryKey,
                );
            }
            if (!@fflush($handle)) {
                throw self::error(
                    'file_staging_entry_unwritable',
                    $registryKey,
                );
            }
        } catch (\Throwable $e) {
            $failure = $e;
        }
        if (!@fclose($handle) && $failure === null) {
            $failure = self::error(
                'file_staging_entry_unwritable',
                $registryKey,
            );
        }
        if ($failure instanceof \Throwable) {
            @unlink($target);
            throw $failure;
        }
        if (PHP_OS_FAMILY !== 'Windows' && !@chmod($target, 0600)) {
            @unlink($target);
            throw self::error('file_staging_entry_unwritable', $registryKey);
        }

        clearstatcache(true, $target);
        $real = realpath($target);
        $size = @filesize($target);
        if (!is_string($real)
            || !is_int($size)
            || $size !== $expected['bytes']
            || is_link($target)
            || !is_file($real)
            || !self::samePath($real, $target)
            || !self::inside($real, $directory)
        ) {
            @unlink($target);
            throw self::error('file_staging_entry_invalid', $registryKey);
        }
        return $real;
    }

    private function root(): string
    {
        $configured = rtrim($this->roots->root(), "/\\");
        if ($configured === ''
            || str_contains($configured, "\0")
            || is_link($configured)
            || !is_dir($configured)
                && !@mkdir($configured, 0700, true)
                && !is_dir($configured)
        ) {
            throw self::error('file_staging_root_unavailable');
        }
        if (PHP_OS_FAMILY !== 'Windows' && !@chmod($configured, 0700)) {
            throw self::error('file_staging_root_unavailable');
        }
        $real = realpath($configured);
        if (!is_string($real)
            || !is_dir($real)
            || !self::samePath($real, $configured)
        ) {
            throw self::error('file_staging_root_unavailable');
        }
        return $real;
    }

    private static function bestEffortCleanup(string $directory): void
    {
        if ($directory === '' || is_link($directory) || !is_dir($directory)) {
            return;
        }
        $entries = @scandir($directory);
        if (!is_array($entries)) {
            return;
        }
        foreach (array_diff($entries, ['.', '..']) as $entry) {
            $path = $directory . DIRECTORY_SEPARATOR . $entry;
            if (is_file($path) || is_link($path)) {
                @unlink($path);
            }
        }
        @rmdir($directory);
    }

    private static function samePath(string $left, string $right): bool
    {
        return strtolower(str_replace('\\', '/', $left))
            === strtolower(str_replace('\\', '/', $right));
    }

    private static function inside(string $path, string $root): bool
    {
        $path = strtolower(str_replace('\\', '/', $path));
        $root = strtolower(rtrim(str_replace('\\', '/', $root), '/'));
        return str_starts_with($path, $root . '/');
    }

    private static function registryKey(string $archivePath): string
    {
        $parts = explode('/', $archivePath, 3);
        $area = $parts[0] === 'files'
            && isset($parts[1])
            && preg_match('/^[a-z][a-z0-9-]{0,63}$/D', $parts[1]) === 1
                ? $parts[1]
                : 'inventory';
        return 'file-area:' . $area;
    }

    private static function error(
        string $errorCode,
        string $registryKey = 'file-area:inventory',
        ?\Throwable $previous = null,
    ): CompanyBackupFileRestoreException {
        return new CompanyBackupFileRestoreException(
            $errorCode,
            $registryKey,
            $previous,
        );
    }
}
