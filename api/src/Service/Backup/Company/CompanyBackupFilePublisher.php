<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/** Publikuje ověřený staging do registrovaných runtime kořenů bez přepsání. */
final readonly class CompanyBackupFilePublisher
{
    private const CHUNK_BYTES = 1_048_576;

    public function __construct(
        private CompanyBackupFileAreaRootResolver $roots =
            new CompanyBackupRuntimeFileAreaRootResolver(),
    ) {}

    public function publish(
        CompanyBackupFilePublicationPlan $plan,
        CompanyBackupStagedFileSet $staged,
    ): CompanyBackupPublishedFileSet {
        $actualArchivePaths = $staged->archivePaths();
        if ($actualArchivePaths !== $plan->archivePaths()) {
            throw self::error('file_restore_staging_scope_mismatch');
        }

        /**
         * @var list<array{
         *   path:string,
         *   root:string,
         *   registry_key:string,
         *   bytes:int,
         *   sha256:string,
         *   stat:array{dev:int,ino:int,size:int,mtime:int,ctime:int}
         * }> $files
         */
        $files = [];
        /** @var list<array{path:string,root:string,registry_key:string}> $directories */
        $directories = [];
        /** @var array<string,string> $resolvedRoots */
        $resolvedRoots = [];
        $registryKey = 'file-area:inventory';
        try {
            foreach ($plan->entries as $entry) {
                if ($entry->state !== CompanyBackupFileState::Present) {
                    continue;
                }
                $registryKey = $entry->registryKey;
                $root = $resolvedRoots[$entry->storageSubdirectory] ?? null;
                if (!is_string($root)) {
                    $root = $this->root(
                        $entry,
                        $directories,
                    );
                    $resolvedRoots[$entry->storageSubdirectory] = $root;
                }
                $target = $this->target(
                    $root,
                    $entry,
                    $directories,
                );
                $files[] = $this->publishFile(
                    $staged,
                    $entry,
                    $root,
                    $target,
                );
            }
            if (count($files) !== $plan->presentEntryCount()) {
                throw self::error('file_restore_publication_count_mismatch');
            }
            $published = new CompanyBackupPublishedFileSet($files, $directories);
            $published->verify();
            return $published;
        } catch (\Throwable $e) {
            try {
                (new CompanyBackupPublishedFileSet(
                    $files,
                    $directories,
                ))->rollback();
            } catch (\Throwable) {
                throw self::error(
                    'file_restore_publication_rollback_failed',
                    $registryKey,
                    $e,
                );
            }
            if ($e instanceof CompanyBackupFileRestoreException) {
                throw $e;
            }
            throw self::error(
                'file_restore_publication_failed',
                $registryKey,
                $e,
            );
        }
    }

    /**
     * @param list<array{path:string,root:string,registry_key:string}> $directories
     */
    private function root(
        CompanyBackupFilePublicationEntry $entry,
        array &$directories,
    ): string {
        $configured = rtrim(
            $this->roots->resolve($entry->storageSubdirectory),
            "/\\",
        );
        if ($configured === ''
            || str_contains($configured, "\0")
            || is_link($configured)
        ) {
            throw self::error(
                'file_restore_destination_root_unsafe',
                $entry->registryKey,
            );
        }
        $created = false;
        if (!is_dir($configured)) {
            if (file_exists($configured)) {
                throw self::error(
                    'file_restore_destination_root_unavailable',
                    $entry->registryKey,
                );
            }
            $created = @mkdir($configured, 0750, true);
            if (!$created && !is_dir($configured)) {
                throw self::error(
                    'file_restore_destination_root_unavailable',
                    $entry->registryKey,
                );
            }
        }
        clearstatcache(true, $configured);
        $real = realpath($configured);
        if (!is_string($real)
            || is_link($configured)
            || !is_dir($real)
            || !self::samePath($real, $configured)
        ) {
            if ($created && !is_link($configured) && is_dir($configured)) {
                @rmdir($configured);
            }
            throw self::error(
                'file_restore_destination_root_unsafe',
                $entry->registryKey,
            );
        }
        if ($created) {
            $directories[] = [
                'path' => $real,
                'root' => $real,
                'registry_key' => $entry->registryKey,
            ];
            if (PHP_OS_FAMILY !== 'Windows' && !@chmod($real, 0750)) {
                throw self::error(
                    'file_restore_destination_root_unavailable',
                    $entry->registryKey,
                );
            }
        }
        return $real;
    }

    /**
     * @param list<array{path:string,root:string,registry_key:string}> $directories
     */
    private function target(
        string $root,
        CompanyBackupFilePublicationEntry $entry,
        array &$directories,
    ): string {
        $segments = explode('/', $entry->targetPath);
        $fileName = array_pop($segments);
        if ($fileName === '') {
            throw self::error(
                'file_restore_target_path_invalid',
                $entry->registryKey,
            );
        }
        $parent = $root;
        foreach ($segments as $segment) {
            $candidate = $parent . DIRECTORY_SEPARATOR . $segment;
            clearstatcache(true, $candidate);
            $created = false;
            if (!file_exists($candidate) && !is_link($candidate)) {
                if (@mkdir($candidate, 0750)) {
                    $created = true;
                } elseif (!is_dir($candidate)) {
                    throw self::error(
                        'file_restore_destination_unwritable',
                        $entry->registryKey,
                    );
                }
            }
            $real = realpath($candidate);
            if (!is_string($real)
                || is_link($candidate)
                || !is_dir($real)
                || !self::samePath($real, $candidate)
                || !self::inside($real, $root)
            ) {
                if ($created && !is_link($candidate) && is_dir($candidate)) {
                    @rmdir($candidate);
                }
                throw self::error(
                    'file_restore_destination_path_unsafe',
                    $entry->registryKey,
                );
            }
            if ($created) {
                $directories[] = [
                    'path' => $real,
                    'root' => $root,
                    'registry_key' => $entry->registryKey,
                ];
                if (PHP_OS_FAMILY !== 'Windows' && !@chmod($real, 0750)) {
                    throw self::error(
                        'file_restore_destination_unwritable',
                        $entry->registryKey,
                    );
                }
            }
            $parent = $real;
        }
        $target = $parent . DIRECTORY_SEPARATOR . $fileName;
        clearstatcache(true, $target);
        if (file_exists($target) || is_link($target)) {
            throw self::error(
                'file_restore_destination_exists',
                $entry->registryKey,
            );
        }
        return $target;
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
    private function publishFile(
        CompanyBackupStagedFileSet $staged,
        CompanyBackupFilePublicationEntry $entry,
        string $root,
        string $target,
    ): array {
        $archivePath = $entry->archivePath;
        $expectedBytes = $entry->bytes;
        $expectedSha256 = $entry->sha256;
        if (!is_string($archivePath)
            || !is_int($expectedBytes)
            || !is_string($expectedSha256)
        ) {
            throw new \LogicException(
                'Existující publication položka nemá úplná metadata.',
            );
        }
        $source = $staged->pathFor($archivePath);
        $input = @fopen($source, 'rb');
        if (!is_resource($input)) {
            throw self::error(
                'file_restore_staging_entry_unavailable',
                $entry->registryKey,
            );
        }
        $output = @fopen($target, 'x+b');
        if (!is_resource($output)) {
            @fclose($input);
            clearstatcache(true, $target);
            throw self::error(
                file_exists($target) || is_link($target)
                    ? 'file_restore_destination_exists'
                    : 'file_restore_destination_unwritable',
                $entry->registryKey,
            );
        }
        $created = @fstat($output);
        if (!is_array($created)) {
            @fclose($input);
            @fclose($output);
            $created = @lstat($target);
            $this->cleanupIncomplete(
                $target,
                $root,
                is_array($created) ? $created : null,
                $entry->registryKey,
            );
            throw self::error(
                'file_restore_destination_unwritable',
                $entry->registryKey,
            );
        }

        $failure = null;
        $bytes = 0;
        $hash = hash_init('sha256');
        try {
            while (!feof($input)) {
                $chunk = @fread($input, self::CHUNK_BYTES);
                if (!is_string($chunk)
                    || $chunk === '' && !feof($input)
                ) {
                    throw self::error(
                        'file_restore_staging_entry_unavailable',
                        $entry->registryKey,
                    );
                }
                if ($chunk === '') {
                    break;
                }
                $length = strlen($chunk);
                if ($length > $expectedBytes - $bytes) {
                    throw self::error(
                        'file_restore_publication_content_mismatch',
                        $entry->registryKey,
                    );
                }
                $offset = 0;
                while ($offset < $length) {
                    $written = @fwrite($output, substr($chunk, $offset));
                    if (!is_int($written) || $written < 1) {
                        throw self::error(
                            'file_restore_destination_unwritable',
                            $entry->registryKey,
                        );
                    }
                    $offset += $written;
                }
                $bytes += $length;
                hash_update($hash, $chunk);
            }
            if ($bytes !== $expectedBytes
                || !hash_equals($expectedSha256, hash_final($hash))
            ) {
                throw self::error(
                    'file_restore_publication_content_mismatch',
                    $entry->registryKey,
                );
            }
            if (!@fflush($output)) {
                throw self::error(
                    'file_restore_destination_unwritable',
                    $entry->registryKey,
                );
            }
            if (function_exists('fsync')) {
                @fsync($output);
            }
        } catch (\Throwable $e) {
            $failure = $e;
        }
        if (!@fclose($input) && $failure === null) {
            $failure = self::error(
                'file_restore_staging_entry_unavailable',
                $entry->registryKey,
            );
        }
        if (!@fclose($output) && $failure === null) {
            $failure = self::error(
                'file_restore_destination_unwritable',
                $entry->registryKey,
            );
        }
        if ($failure instanceof \Throwable) {
            try {
                $this->cleanupIncomplete(
                    $target,
                    $root,
                    $created,
                    $entry->registryKey,
                );
            } catch (\Throwable) {
                throw self::error(
                    'file_restore_publication_rollback_failed',
                    $entry->registryKey,
                    $failure,
                );
            }
            throw $failure;
        }
        try {
            if (PHP_OS_FAMILY !== 'Windows' && !@chmod($target, 0640)) {
                throw self::error(
                    'file_restore_destination_unwritable',
                    $entry->registryKey,
                );
            }
            $staged->pathFor($archivePath);

            clearstatcache(true, $target);
            $real = realpath($target);
            $before = @stat($target);
            $sha256 = @hash_file('sha256', $target);
            clearstatcache(true, $target);
            $after = @stat($target);
            if (!is_string($real)
                || is_link($target)
                || !is_file($real)
                || !self::samePath($real, $target)
                || !self::inside($real, $root)
                || !is_array($before)
                || !is_array($after)
                || !self::sameFile($before, $after)
                || $after['size'] !== $expectedBytes
                || !is_string($sha256)
                || !hash_equals($expectedSha256, $sha256)
            ) {
                throw self::error(
                    'file_restore_publication_content_mismatch',
                    $entry->registryKey,
                );
            }
            return [
                'path' => $real,
                'root' => $root,
                'registry_key' => $entry->registryKey,
                'bytes' => $expectedBytes,
                'sha256' => $expectedSha256,
                'stat' => self::statSignature($after),
            ];
        } catch (\Throwable $failure) {
            try {
                $this->cleanupIncomplete(
                    $target,
                    $root,
                    $created,
                    $entry->registryKey,
                );
            } catch (\Throwable) {
                throw self::error(
                    'file_restore_publication_rollback_failed',
                    $entry->registryKey,
                    $failure,
                );
            }
            throw $failure;
        }
    }

    /** @param array<int|string,mixed>|null $created */
    private function cleanupIncomplete(
        string $target,
        string $root,
        ?array $created,
        string $registryKey,
    ): void {
        clearstatcache(true, $target);
        if (!file_exists($target) && !is_link($target)) {
            return;
        }
        $real = realpath($target);
        $current = @lstat($target);
        if (!is_string($real)
            || is_link($target)
            || !is_file($real)
            || !self::samePath($real, $target)
            || !self::inside($real, $root)
            || !is_array($created)
            || !is_array($current)
            || $created['dev'] !== $current['dev']
            || $created['ino'] !== $current['ino']
        ) {
            throw self::error('file_restore_rollback_unsafe', $registryKey);
        }
        if (!@unlink($target)) {
            throw self::error('file_restore_rollback_failed', $registryKey);
        }
    }

    /**
     * @param array<int|string,mixed> $stat
     * @return array{dev:int,ino:int,size:int,mtime:int,ctime:int}
     */
    private static function statSignature(array $stat): array
    {
        $signature = [];
        foreach (['dev', 'ino', 'size', 'mtime', 'ctime'] as $field) {
            $value = $stat[$field] ?? null;
            if (!is_int($value)) {
                throw new \RuntimeException(
                    'Metadata publikovaného souboru nejsou dostupná.',
                );
            }
            $signature[$field] = $value;
        }
        return $signature;
    }

    /**
     * @param array<int|string,mixed> $before
     * @param array<int|string,mixed> $after
     */
    private static function sameFile(array $before, array $after): bool
    {
        foreach (['dev', 'ino', 'size', 'mtime', 'ctime'] as $field) {
            if (($before[$field] ?? null) !== ($after[$field] ?? null)) {
                return false;
            }
        }
        return true;
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
