<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/**
 * Vlastní cíle vytvořené jedním během obnovy do DB commitu nebo rollbacku.
 * Cizí či mezitím změněnou cestu nikdy nemaže.
 */
final class CompanyBackupPublishedFileSet
{
    /**
     * @var list<array{
     *   path:string,
     *   root:string,
     *   registry_key:string,
     *   bytes:int,
     *   sha256:string,
     *   stat:array{dev:int,ino:int,size:int,mtime:int,ctime:int}
     * }>
     */
    private array $files;

    /** @var list<array{path:string,root:string,registry_key:string}> */
    private array $directories;

    private bool $closed = false;

    /**
     * @param array<mixed> $files
     * @param array<mixed> $directories
     */
    public function __construct(array $files, array $directories)
    {
        if (!array_is_list($files) || !array_is_list($directories)) {
            throw new \InvalidArgumentException(
                'Vlastnický seznam publikovaných cest není platný.',
            );
        }
        $seenFiles = [];
        $validatedFiles = [];
        foreach ($files as $rawFile) {
            $file = self::fileMetadata($rawFile);
            $signature = strtolower(str_replace('\\', '/', $file['path']));
            if (isset($seenFiles[$signature])) {
                throw new \InvalidArgumentException(
                    'Vlastnický seznam publikovaných souborů není platný.',
                );
            }
            $seenFiles[$signature] = true;
            $validatedFiles[] = $file;
        }
        $seenDirectories = [];
        $validatedDirectories = [];
        foreach ($directories as $rawDirectory) {
            $directory = self::directoryMetadata($rawDirectory);
            $signature = strtolower(str_replace('\\', '/', $directory['path']));
            if (isset($seenDirectories[$signature])
            ) {
                throw new \InvalidArgumentException(
                    'Vlastnický seznam publikačních adresářů není platný.',
                );
            }
            $seenDirectories[$signature] = true;
            $validatedDirectories[] = $directory;
        }
        $this->files = $validatedFiles;
        $this->directories = $validatedDirectories;
    }

    public function count(): int
    {
        return count($this->files);
    }

    /** Ověří, že všechny dosud nevydané cíle stále patří tomuto běhu. */
    public function verify(): void
    {
        $this->assertOpen();
        foreach ($this->files as $file) {
            $this->assertOwnedFile($file);
        }
    }

    /** Po úspěšném DB commitu přestane sada cílové soubory vlastnit. */
    public function release(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        $this->files = [];
        $this->directories = [];
    }

    /**
     * Odstraní pouze nezměněné cíle vytvořené tímto během. Opakované volání i
     * volání po release je bezpečný no-op.
     */
    public function rollback(): void
    {
        if ($this->closed) {
            return;
        }
        $failure = null;
        foreach (array_reverse($this->files) as $file) {
            try {
                clearstatcache(true, $file['path']);
                if (!file_exists($file['path']) && !is_link($file['path'])) {
                    continue;
                }
                $this->assertOwnedFile($file);
                if (!@unlink($file['path'])) {
                    throw self::error(
                        'file_restore_rollback_failed',
                        $file['registry_key'],
                    );
                }
                clearstatcache(true, $file['path']);
                if (file_exists($file['path']) || is_link($file['path'])) {
                    throw self::error(
                        'file_restore_rollback_failed',
                        $file['registry_key'],
                    );
                }
            } catch (\Throwable $e) {
                $failure ??= $e;
            }
        }
        foreach (array_reverse($this->directories) as $directory) {
            try {
                $this->removeOwnedDirectory($directory);
            } catch (\Throwable $e) {
                $failure ??= $e;
            }
        }
        $this->closed = true;
        $this->files = [];
        $this->directories = [];
        if ($failure instanceof \Throwable) {
            throw $failure;
        }
    }

    public function __destruct()
    {
        if ($this->closed) {
            return;
        }
        try {
            $this->rollback();
        } catch (\Throwable) {
            // Destruktor nesmí překrýt primární chybu obnovy.
        }
    }

    /**
     * @param array{
     *   path:string,
     *   root:string,
     *   registry_key:string,
     *   bytes:int,
     *   sha256:string,
     *   stat:array{dev:int,ino:int,size:int,mtime:int,ctime:int}
     * } $file
     */
    private function assertOwnedFile(array $file): void
    {
        clearstatcache(true, $file['root']);
        clearstatcache(true, $file['path']);
        $realRoot = realpath($file['root']);
        $realPath = realpath($file['path']);
        $before = @stat($file['path']);
        if (!is_string($realRoot)
            || !is_string($realPath)
            || is_link($file['root'])
            || is_link($file['path'])
            || !is_dir($realRoot)
            || !is_file($realPath)
            || !self::samePath($realRoot, $file['root'])
            || !self::samePath($realPath, $file['path'])
            || !self::inside($realPath, $realRoot)
            || !is_array($before)
            || !self::sameStat($before, $file['stat'])
        ) {
            throw self::error(
                'file_restore_rollback_unsafe',
                $file['registry_key'],
            );
        }
        $sha256 = @hash_file('sha256', $realPath);
        clearstatcache(true, $realPath);
        $after = @stat($realPath);
        if (!is_string($sha256)
            || !hash_equals($file['sha256'], $sha256)
            || !is_array($after)
            || !self::sameStat($before, $after)
            || $after['size'] !== $file['bytes']
        ) {
            throw self::error(
                'file_restore_rollback_unsafe',
                $file['registry_key'],
            );
        }
    }

    /** @param array{path:string,root:string,registry_key:string} $directory */
    private function removeOwnedDirectory(array $directory): void
    {
        clearstatcache(true, $directory['path']);
        if (!file_exists($directory['path']) && !is_link($directory['path'])) {
            return;
        }
        $real = realpath($directory['path']);
        if (!is_string($real)
            || is_link($directory['path'])
            || !is_dir($real)
            || !self::samePath($real, $directory['path'])
            || !self::insideOrSame($real, $directory['root'])
        ) {
            throw self::error(
                'file_restore_rollback_unsafe',
                $directory['registry_key'],
            );
        }
        $entries = @scandir($real);
        if (!is_array($entries)) {
            throw self::error(
                'file_restore_rollback_failed',
                $directory['registry_key'],
            );
        }
        if (array_diff($entries, ['.', '..']) !== []) {
            return;
        }
        if (!@rmdir($real)) {
            clearstatcache(true, $real);
            if (is_dir($real) || is_link($real)) {
                throw self::error(
                    'file_restore_rollback_failed',
                    $directory['registry_key'],
                );
            }
        }
    }

    /**
     * @return array{
     *   path:string,
     *   root:string,
     *   registry_key:string,
     *   bytes:int,
     *   sha256:string,
     *   stat:array{dev:int,ino:int,size:int,mtime:int,ctime:int}
     * }
     */
    private static function fileMetadata(mixed $file): array
    {
        if (!is_array($file)
            || array_keys($file) !== [
            'path',
            'root',
            'registry_key',
            'bytes',
            'sha256',
            'stat',
        ]
            || !is_string($file['path'])
            || $file['path'] === ''
            || !is_string($file['root'])
            || $file['root'] === ''
            || !is_string($file['registry_key'])
            || !str_starts_with($file['registry_key'], 'file-area:')
            || !is_int($file['bytes'])
            || $file['bytes'] < 0
            || !is_string($file['sha256'])
            || preg_match('/^[0-9a-f]{64}$/D', $file['sha256']) !== 1
            || !self::inside($file['path'], $file['root'])
        ) {
            throw new \InvalidArgumentException(
                'Vlastnický seznam publikovaných souborů není platný.',
            );
        }
        $stat = self::statMetadata($file['stat']);
        if ($stat['size'] !== $file['bytes']) {
            throw new \InvalidArgumentException(
                'Vlastnický seznam publikovaných souborů není platný.',
            );
        }
        return [
            'path' => $file['path'],
            'root' => $file['root'],
            'registry_key' => $file['registry_key'],
            'bytes' => $file['bytes'],
            'sha256' => $file['sha256'],
            'stat' => $stat,
        ];
    }

    /** @return array{path:string,root:string,registry_key:string} */
    private static function directoryMetadata(mixed $directory): array
    {
        if (!is_array($directory)
            || array_keys($directory) !== ['path', 'root', 'registry_key']
            || !is_string($directory['path'])
            || $directory['path'] === ''
            || !is_string($directory['root'])
            || $directory['root'] === ''
            || !is_string($directory['registry_key'])
            || !str_starts_with($directory['registry_key'], 'file-area:')
            || !self::insideOrSame($directory['path'], $directory['root'])
        ) {
            throw new \InvalidArgumentException(
                'Vlastnický seznam publikačních adresářů není platný.',
            );
        }
        return [
            'path' => $directory['path'],
            'root' => $directory['root'],
            'registry_key' => $directory['registry_key'],
        ];
    }

    /** @return array{dev:int,ino:int,size:int,mtime:int,ctime:int} */
    private static function statMetadata(mixed $stat): array
    {
        if (!is_array($stat)
            || array_keys($stat) !== ['dev', 'ino', 'size', 'mtime', 'ctime']
        ) {
            throw new \InvalidArgumentException(
                'Vlastnický seznam publikovaných souborů není platný.',
            );
        }
        foreach ($stat as $value) {
            if (!is_int($value)) {
                throw new \InvalidArgumentException(
                    'Vlastnický seznam publikovaných souborů není platný.',
                );
            }
        }
        return [
            'dev' => $stat['dev'],
            'ino' => $stat['ino'],
            'size' => $stat['size'],
            'mtime' => $stat['mtime'],
            'ctime' => $stat['ctime'],
        ];
    }

    /**
     * @param array<int|string,mixed> $left
     * @param array{dev:int,ino:int,size:int,mtime:int,ctime:int} $right
     */
    private static function sameStat(array $left, array $right): bool
    {
        foreach (['dev', 'ino', 'size', 'mtime', 'ctime'] as $field) {
            if (($left[$field] ?? null) !== $right[$field]) {
                return false;
            }
        }
        return true;
    }

    private function assertOpen(): void
    {
        if ($this->closed) {
            throw self::error('file_restore_publication_closed');
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

    private static function insideOrSame(string $path, string $root): bool
    {
        return self::samePath($path, $root) || self::inside($path, $root);
    }

    private static function error(
        string $errorCode,
        string $registryKey = 'file-area:inventory',
    ): CompanyBackupFileRestoreException {
        return new CompanyBackupFileRestoreException($errorCode, $registryKey);
    }
}
