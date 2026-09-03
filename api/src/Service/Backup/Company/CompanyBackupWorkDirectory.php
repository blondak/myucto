<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

use MyInvoice\Infrastructure\Config\RuntimePaths;

/** Vlastní krátkodobé plaintext mezisoubory pod runtime storage. */
final readonly class CompanyBackupWorkDirectory
{
    public function create(string $backupId): string
    {
        if (!CompanyBackupManifestHeader::isCanonicalBackupId($backupId)) {
            throw new \InvalidArgumentException(
                'Identifikátor pracovního adresáře zálohy není platný.',
            );
        }
        $root = $this->root(true);
        $path = $root . DIRECTORY_SEPARATOR . 'job-' . $backupId . '-'
            . bin2hex(random_bytes(8));
        if (!@mkdir($path, 0700)) {
            throw new CompanyBackupSnapshotException(
                'snapshot_work_directory_unwritable',
            );
        }
        if (PHP_OS_FAMILY !== 'Windows' && !@chmod($path, 0700)) {
            @rmdir($path);
            throw new CompanyBackupSnapshotException(
                'snapshot_work_directory_unwritable',
            );
        }
        $real = realpath($path);
        if (!is_string($real) || is_link($path) || !$this->inside($real, $root)) {
            @rmdir($path);
            throw new CompanyBackupSnapshotException(
                'snapshot_work_directory_unwritable',
            );
        }
        return $real;
    }

    public function cleanup(string $directory): void
    {
        $root = $this->root(false);
        $real = realpath($directory);
        $name = basename(str_replace('\\', '/', $directory));
        if (!is_string($real)
            || is_link($directory)
            || !$this->inside($real, $root)
            || preg_match(
                '/^job-[0-9a-f-]{36}-[0-9a-f]{16}$/D',
                $name,
            ) !== 1
        ) {
            throw new CompanyBackupSnapshotException('snapshot_cleanup_failed');
        }
        $entries = @scandir($real);
        if (!is_array($entries)) {
            throw new CompanyBackupSnapshotException('snapshot_cleanup_failed');
        }
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $real . DIRECTORY_SEPARATOR . $entry;
            if ((!is_file($path) && !is_link($path)) || !@unlink($path)) {
                throw new CompanyBackupSnapshotException('snapshot_cleanup_failed');
            }
        }
        if (!@rmdir($real)) {
            throw new CompanyBackupSnapshotException('snapshot_cleanup_failed');
        }
    }

    private function root(bool $create): string
    {
        $root = RuntimePaths::storage('tmp/company-backups');
        if ($root === '' || is_link($root)) {
            throw new CompanyBackupSnapshotException(
                'snapshot_work_directory_unwritable',
            );
        }
        if ($create && !is_dir($root)
            && !@mkdir($root, 0700, true)
            && !is_dir($root)
        ) {
            throw new CompanyBackupSnapshotException(
                'snapshot_work_directory_unwritable',
            );
        }
        $real = realpath($root);
        if (!is_string($real) || !is_dir($real)) {
            throw new CompanyBackupSnapshotException(
                $create
                    ? 'snapshot_work_directory_unwritable'
                    : 'snapshot_cleanup_failed',
            );
        }
        return $real;
    }

    private function inside(string $path, string $root): bool
    {
        $path = strtolower(str_replace('\\', '/', $path));
        $root = strtolower(rtrim(str_replace('\\', '/', $root), '/'));
        return str_starts_with($path, $root . '/');
    }
}
