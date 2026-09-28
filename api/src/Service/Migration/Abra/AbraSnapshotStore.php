<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Abra;

use MyInvoice\Infrastructure\Config\RuntimePaths;

/** Dočasný tenantový soubor se snímkem; neúspěšný běh jej drží nejvýše 24 hodin. */
final class AbraSnapshotStore
{
    public const FAILED_RETENTION_SECONDS = 86400;

    public function writePage(int $supplierId, int $jobId, string $evidence, array $query, int $start, array $payload): void
    {
        if (!preg_match('/^[a-z][a-z0-9-]*$/D', $evidence) || $start < 0) throw new \InvalidArgumentException('Invalid snapshot page.');
        $dir = $this->dir($supplierId, $jobId);
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) throw new AbraException('snapshot_storage', 'Dočasný snímek ABRA Flexi nelze bezpečně uložit.');
        $name = 'page-' . $evidence . '-' . hash('sha256', json_encode($query, JSON_THROW_ON_ERROR)) . '-' . $start . '.json';
        $tmp = $dir . DIRECTORY_SEPARATOR . bin2hex(random_bytes(8)) . '.tmp';
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (@file_put_contents($tmp, $json, LOCK_EX) === false) throw new AbraException('snapshot_storage', 'Čtený blok ABRA Flexi nelze bezpečně uložit.');
        @chmod($tmp, 0600);
        if (!@rename($tmp, $dir . DIRECTORY_SEPARATOR . $name)) {
            @unlink($tmp);
            throw new AbraException('snapshot_storage', 'Čtený blok ABRA Flexi nelze bezpečně uložit.');
        }
    }

    /** @param array<string,mixed> $snapshot */
    public function write(int $supplierId, int $jobId, array $snapshot): void
    {
        $dir = $this->dir($supplierId, $jobId);
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new AbraException('snapshot_storage', 'Dočasný snímek ABRA Flexi nelze bezpečně uložit.');
        }
        $json = json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $tmp = $dir . DIRECTORY_SEPARATOR . 'snapshot.' . bin2hex(random_bytes(6)) . '.tmp';
        if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
            @unlink($tmp);
            throw new AbraException('snapshot_storage', 'Dočasný snímek ABRA Flexi nelze bezpečně uložit.');
        }
        @chmod($tmp, 0600);
        if (!@rename($tmp, $this->path($supplierId, $jobId))) {
            @unlink($tmp);
            throw new AbraException('snapshot_storage', 'Dočasný snímek ABRA Flexi nelze bezpečně uložit.');
        }
    }

    /** @return array<string,mixed> */
    public function read(int $supplierId, int $jobId): array
    {
        $json = @file_get_contents($this->path($supplierId, $jobId));
        if (!is_string($json)) {
            throw new AbraException('snapshot_missing', 'Dočasný snímek ABRA Flexi není dostupný.');
        }
        try {
            $snapshot = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            throw new AbraException('snapshot_invalid', 'Dočasný snímek ABRA Flexi je poškozený.');
        }
        if (!is_array($snapshot)) {
            throw new AbraException('snapshot_invalid', 'Dočasný snímek ABRA Flexi je poškozený.');
        }
        return $snapshot;
    }

    public function purge(int $supplierId, int $jobId): void
    {
        $dir = $this->dir($supplierId, $jobId);
        $real = realpath($dir);
        $base = realpath($this->supplierDir($supplierId));
        if ($real === false || $base === false
            || !str_starts_with(strtolower($real), strtolower($base) . DIRECTORY_SEPARATOR)) {
            return;
        }
        foreach (new \FilesystemIterator($real, \FilesystemIterator::SKIP_DOTS) as $file) {
            if ($file->isFile() && !$file->isLink()) {
                @unlink($file->getPathname());
            }
        }
        @rmdir($real);
        @rmdir($base);
    }

    public function pruneStale(int $supplierId, int $maxAgeSeconds = self::FAILED_RETENTION_SECONDS): void
    {
        self::positive($supplierId);
        if ($maxAgeSeconds < 0) {
            throw new \InvalidArgumentException('Snapshot retention must not be negative.');
        }
        $base = $this->supplierDir($supplierId);
        if (!is_dir($base)) {
            return;
        }
        $cutoff = time() - $maxAgeSeconds;
        foreach (new \FilesystemIterator($base, \FilesystemIterator::SKIP_DOTS) as $dir) {
            if (!$dir->isDir() || $dir->isLink()
                || preg_match('/^job-([1-9]\d*)$/D', $dir->getFilename(), $match) !== 1) {
                continue;
            }
            $snapshot = $dir->getPathname() . DIRECTORY_SEPARATOR . 'snapshot.json';
            $modified = @filemtime($snapshot);
            if ($modified === false) $modified = @filemtime($dir->getPathname());
            if ($modified !== false && $modified <= $cutoff) {
                $this->purge($supplierId, (int) $match[1]);
            }
        }
    }

    public function path(int $supplierId, int $jobId): string
    {
        return $this->dir($supplierId, $jobId) . DIRECTORY_SEPARATOR . 'snapshot.json';
    }

    public function pruneAll(): int
    {
        $base = RuntimePaths::storage('abra-flexi');
        if (!is_dir($base)) return 0;
        $processed = 0;
        foreach (new \FilesystemIterator($base, \FilesystemIterator::SKIP_DOTS) as $dir) {
            if (!$dir->isDir() || $dir->isLink()
                || preg_match('/^sup-([1-9]\d*)$/D', $dir->getFilename(), $match) !== 1) continue;
            $this->pruneStale((int) $match[1]);
            $processed++;
        }
        return $processed;
    }

    private function dir(int $supplierId, int $jobId): string
    {
        self::positive($supplierId);
        self::positive($jobId);
        return $this->supplierDir($supplierId) . DIRECTORY_SEPARATOR . 'job-' . $jobId;
    }

    private function supplierDir(int $supplierId): string
    {
        self::positive($supplierId);
        return RuntimePaths::storage('abra-flexi' . DIRECTORY_SEPARATOR . 'sup-' . $supplierId);
    }

    private static function positive(int $value): void
    {
        if ($value <= 0) {
            throw new \InvalidArgumentException('Tenant and job identifiers must be positive.');
        }
    }
}
