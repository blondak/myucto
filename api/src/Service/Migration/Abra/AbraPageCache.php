<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Abra;

use MyInvoice\Infrastructure\Config\RuntimePaths;

final class AbraPageCache
{
    private readonly string $directory;

    public function __construct(int $supplierId, int $connectionVersion, ?string $baseDirectory = null)
    {
        if ($supplierId < 1 || $connectionVersion < 1) {
            throw new \InvalidArgumentException('Invalid cache context.');
        }
        $base = $baseDirectory ?? RuntimePaths::storage('abra-flexi-cache');
        $this->directory = $base . DIRECTORY_SEPARATOR . 'sup-' . $supplierId
            . DIRECTORY_SEPARATOR . 'version-' . $connectionVersion;
    }

    public function has(string $evidence, array $query, int $offset): bool
    {
        return is_file($this->path($evidence, $query, $offset));
    }

    public function matching(string $evidence, array $query, int $offset, array $metadata): ?array
    {
        $path = $this->path($evidence, $query, $offset);
        $bytes = @file_get_contents($path);
        if (!is_string($bytes)) return null;
        try {
            $cached = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return null;
        }
        $old = $cached['winstrom'][$evidence] ?? null;
        $fresh = $metadata['winstrom'][$evidence] ?? null;
        if (!is_array($old) || !array_is_list($old) || !is_array($fresh) || !array_is_list($fresh)
            || count($old) !== count($fresh) || !isset($metadata['winstrom']['@rowCount'])) {
            return null;
        }
        foreach ($fresh as $i => $row) {
            if (!is_array($row) || !is_array($old[$i] ?? null)
                || (string) ($row['lastUpdate'] ?? '') === ''
                || (string) ($row['lastUpdate'] ?? '') !== (string) ($old[$i]['lastUpdate'] ?? '')
                || AbraSource::sourceKey($row) === ''
                || AbraSource::sourceKey($row) !== AbraSource::sourceKey($old[$i])) {
                return null;
            }
            foreach ($row as $field => $value) {
                if (!array_key_exists($field, $old[$i]) || $old[$i][$field] !== $value) {
                    return null;
                }
            }
        }
        $cached['winstrom']['@rowCount'] = $metadata['winstrom']['@rowCount'];
        return $cached;
    }

    public function save(string $evidence, array $query, int $offset, array $payload): void
    {
        $path = $this->path($evidence, $query, $offset);
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new AbraException('cache_storage', 'Čtený blok ABRA Flexi nelze bezpečně uložit.');
        }
        $tmp = $dir . DIRECTORY_SEPARATOR . bin2hex(random_bytes(8)) . '.tmp';
        try {
            $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            if (@file_put_contents($tmp, $encoded, LOCK_EX) === false) throw new \RuntimeException();
            @chmod($tmp, 0600);
            if (!@rename($tmp, $path)) throw new \RuntimeException();
        } catch (\Throwable) {
            @unlink($tmp);
            throw new AbraException('cache_storage', 'Čtený blok ABRA Flexi nelze bezpečně uložit.');
        }
    }

    public static function pruneExpired(?string $baseDirectory = null, int $maxAgeSeconds = 2592000): int
    {
        if ($maxAgeSeconds < 1) throw new \InvalidArgumentException('Invalid cache retention.');
        $base = $baseDirectory ?? RuntimePaths::storage('abra-flexi-cache');
        if (!is_dir($base)) return 0;
        $cutoff = time() - $maxAgeSeconds;
        $removed = 0;
        foreach (new \FilesystemIterator($base, \FilesystemIterator::SKIP_DOTS) as $supplierDir) {
            if (!$supplierDir->isDir() || $supplierDir->isLink()
                || preg_match('/^sup-[1-9]\d*$/D', $supplierDir->getFilename()) !== 1) continue;
            foreach (new \FilesystemIterator($supplierDir->getPathname(), \FilesystemIterator::SKIP_DOTS) as $versionDir) {
                if (!$versionDir->isDir() || $versionDir->isLink()
                    || preg_match('/^version-[1-9]\d*$/D', $versionDir->getFilename()) !== 1) continue;
                foreach (new \FilesystemIterator($versionDir->getPathname(), \FilesystemIterator::SKIP_DOTS) as $page) {
                    if (!$page->isFile() || $page->isLink()
                        || preg_match('/^[a-z][a-z0-9-]*-[a-f0-9]{64}-\d+\.json$/D', $page->getFilename()) !== 1
                        || $page->getMTime() >= $cutoff) continue;
                    if (@unlink($page->getPathname())) $removed++;
                }
                @rmdir($versionDir->getPathname());
            }
            @rmdir($supplierDir->getPathname());
        }
        return $removed;
    }

    private function path(string $evidence, array $query, int $offset): string
    {
        if (preg_match('/^[a-z][a-z0-9-]*$/D', $evidence) !== 1 || $offset < 0) {
            throw new \InvalidArgumentException('Invalid cache page.');
        }
        return $this->directory . DIRECTORY_SEPARATOR . $evidence . '-'
            . hash('sha256', json_encode($query, JSON_THROW_ON_ERROR)) . '-' . $offset . '.json';
    }
}
