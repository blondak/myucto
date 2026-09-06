<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/**
 * Vlastní jednu izolovanou sadu ověřených souborů až do publikace nebo
 * rollbacku. Destruktor provádí pouze bezpečný best-effort úklid.
 */
final class CompanyBackupStagedFileSet
{
    /**
     * @var array<string,array{path:string,bytes:int,sha256:string}>
     */
    private array $files;

    private bool $closed = false;

    /** @param array<mixed> $files */
    public function __construct(
        private readonly string $root,
        private readonly string $stagingDirectory,
        array $files,
    ) {
        if (!$this->validDirectory()) {
            throw self::error('file_staging_directory_invalid');
        }
        ksort($files, SORT_STRING);
        $validated = [];
        foreach ($files as $archivePath => $metadata) {
            if (!is_string($archivePath)
                || !is_array($metadata)
                || array_keys($metadata) !== ['path', 'bytes', 'sha256']
                || !is_string($metadata['path'])
                || !is_int($metadata['bytes'])
                || $metadata['bytes'] < 0
                || !is_string($metadata['sha256'])
                || preg_match('/^[0-9a-f]{64}$/D', $metadata['sha256']) !== 1
                || !$this->validEntryPath($metadata['path'])
            ) {
                throw self::error('file_staging_entry_invalid');
            }
            $validated[$archivePath] = $metadata;
        }
        $this->files = $validated;
    }

    public function count(): int
    {
        return count($this->files);
    }

    /** @return list<string> */
    public function archivePaths(): array
    {
        return array_keys($this->files);
    }

    public function directory(): string
    {
        return $this->stagingDirectory;
    }

    public function pathFor(string $archivePath): string
    {
        $this->assertOpen();
        $metadata = $this->files[$archivePath] ?? null;
        if ($metadata === null || !$this->validEntryPath($metadata['path'])) {
            throw self::error(
                'file_staging_entry_unavailable',
                self::registryKey($archivePath),
            );
        }
        clearstatcache(true, $metadata['path']);
        $bytes = @filesize($metadata['path']);
        $sha256 = @hash_file('sha256', $metadata['path']);
        if (!is_int($bytes)
            || $bytes !== $metadata['bytes']
            || !is_string($sha256)
            || !hash_equals($metadata['sha256'], $sha256)
            || is_link($metadata['path'])
        ) {
            throw self::error(
                'file_staging_entry_changed',
                self::registryKey($archivePath),
            );
        }
        return $metadata['path'];
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->cleanup();
        $this->closed = true;
    }

    public function __destruct()
    {
        if ($this->closed) {
            return;
        }
        try {
            $this->cleanup();
        } catch (\Throwable) {
            // Destruktor nesmí překrýt primární chybu obnovy.
        }
        $this->closed = true;
    }

    private function cleanup(): void
    {
        clearstatcache(true, $this->stagingDirectory);
        if (!file_exists($this->stagingDirectory)
            && !is_link($this->stagingDirectory)
        ) {
            return;
        }
        if (!$this->validDirectory()) {
            throw self::error('file_staging_cleanup_unsafe');
        }
        $entries = @scandir($this->stagingDirectory);
        if (!is_array($entries)) {
            throw self::error('file_staging_cleanup_failed');
        }
        $allowed = [];
        foreach ($this->files as $metadata) {
            $allowed[basename($metadata['path'])] = true;
        }
        $paths = [];
        foreach (array_diff($entries, ['.', '..']) as $entry) {
            $path = $this->stagingDirectory . DIRECTORY_SEPARATOR . $entry;
            if (!isset($allowed[$entry])
                || is_link($path)
                || !is_file($path)
                || !$this->validEntryPath($path)
            ) {
                throw self::error('file_staging_cleanup_unsafe');
            }
            $paths[] = $path;
        }
        foreach ($paths as $path) {
            if (!@unlink($path)) {
                throw self::error('file_staging_cleanup_failed');
            }
        }
        if (!@rmdir($this->stagingDirectory)) {
            throw self::error('file_staging_cleanup_failed');
        }
    }

    private function validDirectory(): bool
    {
        if ($this->root === ''
            || $this->stagingDirectory === ''
            || is_link($this->root)
            || is_link($this->stagingDirectory)
        ) {
            return false;
        }
        $realRoot = realpath($this->root);
        $realDirectory = realpath($this->stagingDirectory);
        return is_string($realRoot)
            && is_string($realDirectory)
            && is_dir($realRoot)
            && is_dir($realDirectory)
            && self::samePath($realRoot, $this->root)
            && self::samePath($realDirectory, $this->stagingDirectory)
            && self::inside($realDirectory, $realRoot)
            && preg_match(
                '/^restore-[0-9a-f-]{36}-[0-9a-f]{16}$/D',
                basename(str_replace('\\', '/', $realDirectory)),
            ) === 1;
    }

    private function validEntryPath(string $path): bool
    {
        if (is_link($path) || !is_file($path)) {
            return false;
        }
        $real = realpath($path);
        return is_string($real)
            && self::samePath($real, $path)
            && self::inside($real, $this->stagingDirectory)
            && preg_match(
                '/^entry-[0-9a-f]{64}\.bin$/D',
                basename(str_replace('\\', '/', $real)),
            ) === 1;
    }

    private function assertOpen(): void
    {
        if ($this->closed) {
            throw self::error('file_staging_closed');
        }
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
    ): CompanyBackupFileRestoreException {
        return new CompanyBackupFileRestoreException($errorCode, $registryKey);
    }
}
