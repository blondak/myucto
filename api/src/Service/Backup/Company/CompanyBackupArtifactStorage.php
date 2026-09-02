<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/** Bezpečné tenantové úložiště neměnných hotových archivů. */
final readonly class CompanyBackupArtifactStorage
{
    public function __construct(
        private CompanyBackupArtifactRootResolver $rootResolver =
            new CompanyBackupRuntimeArtifactRootResolver(),
    ) {}

    public function prepareDestination(int $supplierId, string $backupId): string
    {
        $path = $this->expectedPath($supplierId, $backupId, true);
        if (file_exists($path) || is_link($path)) {
            throw new CompanyBackupJobException('artifact_destination_exists');
        }

        return $path;
    }

    public function capture(
        int $supplierId,
        string $backupId,
        CompanyBackupArchiveWriteResult $result,
    ): CompanyBackupStoredArtifact {
        $expected = $this->expectedPath($supplierId, $backupId, false);
        $actual = realpath($result->archivePath);
        $expectedReal = realpath($expected);
        clearstatcache(true, $expected);
        $bytes = @filesize($expected);
        if ($actual === false
            || $expectedReal === false
            || is_link($expected)
            || !$this->samePath($actual, $expectedReal)
            || !is_int($bytes)
            || $bytes !== $result->archiveBytes
        ) {
            throw new CompanyBackupJobException('artifact_path_invalid');
        }
        @chmod($expectedReal, 0440);

        return new CompanyBackupStoredArtifact(
            $supplierId,
            $backupId,
            self::relativePath($supplierId, $backupId),
            self::downloadName($backupId),
            $result->archiveBytes,
            $result->archiveSha256,
            $result->entryCount,
        );
    }

    public function resolve(CompanyBackupStoredArtifact $artifact): string
    {
        $expected = $this->expectedPath(
            $artifact->supplierId,
            $artifact->backupId,
            false,
        );
        $real = realpath($expected);
        clearstatcache(true, $expected);
        $bytes = @filesize($expected);
        if ($real === false
            || is_link($expected)
            || !is_file($real)
            || !is_int($bytes)
            || $bytes !== $artifact->bytes
        ) {
            throw new CompanyBackupJobException('artifact_unavailable');
        }

        return $real;
    }

    public function openDownload(
        CompanyBackupStoredArtifact $artifact,
        CompanyBackupDownloadPlan $plan,
    ): CompanyBackupDownloadStream {
        if ($plan->totalBytes !== $artifact->bytes
            || $plan->etag !== '"sha256:' . $artifact->sha256 . '"'
        ) {
            throw new \InvalidArgumentException(
                'Plán stažení nepatří uloženému archivu.',
            );
        }

        return CompanyBackupDownloadStream::open(
            $this->resolve($artifact),
            $plan,
        );
    }

    /**
     * Odstraní pouze přesnou deterministickou cestu archivu. Chybějící soubor
     * je úspěch pro opakovatelný cleanup; nebezpečný nebo zamčený cíl se odloží.
     */
    public function remove(CompanyBackupStoredArtifact $artifact): void
    {
        $path = $this->removalPath($artifact);
        if ($path === null) {
            return;
        }
        if (!@unlink($path)) {
            clearstatcache(true, $path);
            if (file_exists($path) || is_link($path)) {
                throw new CompanyBackupJobException('artifact_delete_deferred');
            }
            return;
        }
        clearstatcache(true, $path);
        if (file_exists($path) || is_link($path)) {
            throw new CompanyBackupJobException('artifact_delete_deferred');
        }
    }

    public static function relativePath(int $supplierId, string $backupId): string
    {
        self::assertCoordinates($supplierId, $backupId);
        return 'sup-' . $supplierId . '/' . $backupId . '.zip';
    }

    public static function downloadName(string $backupId): string
    {
        self::assertCoordinates(1, $backupId);
        return 'myucto-company-backup-' . $backupId . '.zip';
    }

    private function expectedPath(
        int $supplierId,
        string $backupId,
        bool $create,
    ): string {
        self::assertCoordinates($supplierId, $backupId);
        $root = $this->canonicalRoot($create);
        $directory = $root . DIRECTORY_SEPARATOR . 'sup-' . $supplierId;
        if ($create && !is_dir($directory)
            && !@mkdir($directory, 0750)
            && !is_dir($directory)
        ) {
            throw new CompanyBackupJobException('artifact_storage_unavailable');
        }
        $realDirectory = realpath($directory);
        if ($realDirectory === false
            || is_link($directory)
            || !$this->inside($realDirectory, $root)
        ) {
            throw new CompanyBackupJobException(
                $create ? 'artifact_storage_unavailable' : 'artifact_unavailable',
            );
        }

        return $realDirectory . DIRECTORY_SEPARATOR . $backupId . '.zip';
    }

    private function canonicalRoot(bool $create): string
    {
        $root = rtrim($this->rootResolver->root(), "/\\");
        if ($root === '' || is_link($root)) {
            throw new CompanyBackupJobException('artifact_storage_unavailable');
        }
        if ($create && !is_dir($root)
            && !@mkdir($root, 0750, true)
            && !is_dir($root)
        ) {
            throw new CompanyBackupJobException('artifact_storage_unavailable');
        }
        $realRoot = realpath($root);
        if ($realRoot === false || !is_dir($realRoot)) {
            throw new CompanyBackupJobException(
                $create ? 'artifact_storage_unavailable' : 'artifact_unavailable',
            );
        }

        return $realRoot;
    }

    private function removalPath(
        CompanyBackupStoredArtifact $artifact,
    ): ?string {
        $root = rtrim($this->rootResolver->root(), "/\\");
        if ($root === '' || is_link($root)) {
            throw new CompanyBackupJobException('artifact_delete_unsafe');
        }
        if (!file_exists($root)) {
            return null;
        }
        $realRoot = realpath($root);
        if ($realRoot === false || !is_dir($realRoot)) {
            throw new CompanyBackupJobException('artifact_delete_unsafe');
        }

        $directory = $realRoot . DIRECTORY_SEPARATOR
            . 'sup-' . $artifact->supplierId;
        if (is_link($directory)) {
            throw new CompanyBackupJobException('artifact_delete_unsafe');
        }
        if (!file_exists($directory)) {
            return null;
        }
        $realDirectory = realpath($directory);
        if ($realDirectory === false
            || !is_dir($realDirectory)
            || !$this->inside($realDirectory, $realRoot)
        ) {
            throw new CompanyBackupJobException('artifact_delete_unsafe');
        }

        $path = $realDirectory . DIRECTORY_SEPARATOR
            . $artifact->backupId . '.zip';
        if (is_link($path)) {
            throw new CompanyBackupJobException('artifact_delete_unsafe');
        }
        if (!file_exists($path)) {
            return null;
        }
        $realPath = realpath($path);
        if ($realPath === false
            || !is_file($realPath)
            || !$this->samePath($realPath, $path)
        ) {
            throw new CompanyBackupJobException('artifact_delete_unsafe');
        }

        return $path;
    }

    private static function assertCoordinates(int $supplierId, string $backupId): void
    {
        if ($supplierId < 1
            || !CompanyBackupManifestHeader::isCanonicalBackupId($backupId)
        ) {
            throw new \InvalidArgumentException(
                'Souřadnice archivu zálohy firmy nejsou platné.',
            );
        }
    }

    private function samePath(string $left, string $right): bool
    {
        return strtolower(str_replace('\\', '/', $left))
            === strtolower(str_replace('\\', '/', $right));
    }

    private function inside(string $path, string $base): bool
    {
        $path = strtolower(str_replace('\\', '/', $path));
        $base = strtolower(rtrim(str_replace('\\', '/', $base), '/'));
        return str_starts_with($path, $base . '/');
    }
}
