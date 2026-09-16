<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Pohoda;

use MyInvoice\Infrastructure\Config\RuntimePaths;
use Psr\Http\Message\StreamInterface;

/**
 * Nahrané exporty z POHODY čekající na převod: `storage/pohoda/<firma>/<token>/`.
 *
 * Stejné uspořádání jako u Money S3 ({@see \MyInvoice\Service\Migration\MoneyS3\MoneyS3Uploads}):
 * ZIP exportu přichází po částech do `export.zip.part` (stav v `upload.json`), job na pozadí
 * ho rozbalí do `export/` a zapíše `meta.json`. Token je náhodný a adresář je pod firmou,
 * cizí firma na nahraný export nedosáhne. Po úspěšném ostrém převodu se adresář smaže,
 * jinak ho denní úklid smaže po týdnu bez práce s ním.
 */
final class PohodaUploads
{
    public const TOKEN_PATTERN = '/^[a-f0-9]{16}$/';
    public const STATUS_UPLOADING = 'uploading';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_READY = 'ready';
    public const STATUS_FAILED = 'failed';
    private const STALE_DAYS = 7;
    private const STATE_FILE = 'upload.json';
    private const PART_FILE = 'export.zip.part';
    private const UPLOAD_LOCK_FILE = 'upload.lock';
    private const JOB_LOCK_FILE = 'job.lock';

    public static function base(int $supplierId): string
    {
        return RuntimePaths::storage('pohoda/' . $supplierId);
    }

    public static function newToken(): string
    {
        return bin2hex(random_bytes(8));
    }

    public static function dir(int $supplierId, string $token): string
    {
        if ($supplierId <= 0 || preg_match(self::TOKEN_PATTERN, $token) !== 1) {
            throw new PohodaException('upload_not_found', 'Nahraný export nebyl nalezen.', [], 404);
        }
        return self::base($supplierId) . '/' . $token;
    }

    public static function exportDir(int $supplierId, string $token): string
    {
        return self::dir($supplierId, $token) . '/export';
    }

    /** @return array<string,mixed> */
    public static function meta(int $supplierId, string $token): array
    {
        $path = self::dir($supplierId, $token) . '/meta.json';
        if (!is_file($path)) {
            throw new PohodaException('upload_not_found', 'Nahraný export nebyl nalezen (mohl být už uklizen).', [], 404);
        }
        $meta = json_decode((string) file_get_contents($path), true);
        return is_array($meta) ? $meta : [];
    }

    /** @param array<string,mixed> $meta */
    public static function writeMeta(int $supplierId, string $token, array $meta): void
    {
        self::writeJson(self::dir($supplierId, $token) . '/meta.json', $meta);
    }

    public static function hasMeta(int $supplierId, string $token): bool
    {
        return is_file(self::dir($supplierId, $token) . '/meta.json');
    }

    /** @return array<string,mixed>|null */
    public static function state(int $supplierId, string $token): ?array
    {
        $path = self::dir($supplierId, $token) . '/' . self::STATE_FILE;
        if (!is_file($path)) {
            return null;
        }
        $state = json_decode((string) file_get_contents($path), true);
        return is_array($state) ? $state : null;
    }

    /** @param array<string,mixed> $state */
    public static function writeState(int $supplierId, string $token, array $state): void
    {
        self::writeJson(self::dir($supplierId, $token) . '/' . self::STATE_FILE, $state);
    }

    /**
     * @param array<string,mixed> $changes
     * @return array<string,mixed>
     */
    public static function updateState(int $supplierId, string $token, array $changes): array
    {
        $state = array_merge(self::state($supplierId, $token) ?? [], $changes);
        self::writeState($supplierId, $token, $state);
        return $state;
    }

    public static function partPath(int $supplierId, string $token): string
    {
        return self::dir($supplierId, $token) . '/' . self::PART_FILE;
    }

    public static function partSize(int $supplierId, string $token): int
    {
        $path = self::partPath($supplierId, $token);
        clearstatcache(true, $path);
        return is_file($path) ? (int) filesize($path) : 0;
    }

    /**
     * Připojí část exportu na konec `export.zip.part`. Přijme ji jen tehdy, když navazuje
     * přesně na to, co už server má - jinak 409 s počtem bajtů, podle kterého klient naváže.
     */
    public static function appendChunk(int $supplierId, string $token, int $offset, StreamInterface $chunk, int $maxChunkBytes): int
    {
        return self::withUploadLock($supplierId, $token, static function () use ($supplierId, $token, $offset, $chunk, $maxChunkBytes): int {
            $state = self::state($supplierId, $token);
            if ($state === null) {
                throw new PohodaException('upload_not_found', 'Nahrávaný export nebyl nalezen.', [], 404);
            }
            $size = (int) ($state['size'] ?? 0);
            $current = self::partSize($supplierId, $token);
            if (($state['status'] ?? '') !== self::STATUS_UPLOADING) {
                throw new PohodaException('upload_not_uploading', 'Export už je nahraný celý.', ['received' => $current], 409);
            }
            if ($offset !== $current) {
                throw new PohodaException('chunk_offset_mismatch', 'Část exportu nenavazuje na už nahraná data.', ['received' => $current], 409);
            }
            $out = @fopen(self::partPath($supplierId, $token), 'ab');
            if ($out === false) {
                throw new PohodaException('storage_not_writable', 'Úložiště pro exporty není zapisovatelné.', [], 500);
            }
            $written = 0;
            try {
                if ($chunk->isSeekable()) {
                    $chunk->rewind();
                }
                while (!$chunk->eof()) {
                    $buffer = $chunk->read(1024 * 1024);
                    if ($buffer === '') {
                        break;
                    }
                    $length = strlen($buffer);
                    if ($written + $length > $maxChunkBytes) {
                        throw new PohodaException('chunk_too_large', 'Část exportu je větší, než server přijme.', ['received' => $current], 413);
                    }
                    if ($current + $written + $length > $size) {
                        throw new PohodaException('chunk_exceeds_size', 'Nahrávaná data jsou delší než ohlášená velikost exportu.', ['received' => $current], 422);
                    }
                    if (fwrite($out, $buffer) !== $length) {
                        throw new PohodaException('storage_not_writable', 'Část exportu se nepodařilo uložit.', ['received' => $current], 500);
                    }
                    $written += $length;
                }
                fflush($out);
            } catch (\Throwable $e) {
                ftruncate($out, $current);
                throw $e;
            } finally {
                fclose($out);
            }
            if ($written === 0) {
                throw new PohodaException('chunk_empty', 'Část exportu je prázdná.', ['received' => $current], 400);
            }
            $received = $current + $written;
            self::updateState($supplierId, $token, ['received' => $received]);
            return $received;
        });
    }

    /**
     * @template T
     * @param callable():T $fn
     * @return T
     */
    public static function withUploadLock(int $supplierId, string $token, callable $fn): mixed
    {
        $handle = @fopen(self::dir($supplierId, $token) . '/' . self::UPLOAD_LOCK_FILE, 'c');
        if ($handle === false) {
            throw new PohodaException('upload_not_found', 'Nahrávaný export nebyl nalezen.', [], 404);
        }
        try {
            flock($handle, LOCK_EX);
            return $fn();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /** @return resource|null null = export už zpracovává jiný proces */
    public static function acquireJobLock(int $supplierId, string $token)
    {
        $handle = @fopen(self::dir($supplierId, $token) . '/' . self::JOB_LOCK_FILE, 'c');
        if ($handle === false) {
            return null;
        }
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            return null;
        }
        return $handle;
    }

    /** @param resource $handle */
    public static function releaseJobLock($handle): void
    {
        flock($handle, LOCK_UN);
        fclose($handle);
    }

    /** Zahodí data nepovedeného zpracování, stav s chybou nechá pro průvodce. */
    public static function discardData(int $supplierId, string $token): void
    {
        try {
            $dir = self::dir($supplierId, $token);
        } catch (PohodaException) {
            return;
        }
        if (is_dir($dir . '/export')) {
            self::removeTree($dir . '/export', $supplierId);
        }
        @unlink($dir . '/' . self::PART_FILE);
        @unlink($dir . '/meta.json');
    }

    public static function purge(int $supplierId, string $token): void
    {
        try {
            $dir = self::dir($supplierId, $token);
        } catch (PohodaException) {
            return;
        }
        self::removeTree($dir, $supplierId);
    }

    /** @return int kolik nahraných exportů firmy se smazalo */
    public static function purgeStale(int $supplierId): int
    {
        $limit = time() - self::STALE_DAYS * 86400;
        $removed = 0;
        foreach (glob(self::base($supplierId) . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
            if (preg_match(self::TOKEN_PATTERN, basename($dir)) === 1 && self::lastActivity($dir) < $limit && !self::isBusy($dir)) {
                self::removeTree($dir, $supplierId);
                $removed++;
            }
        }
        return $removed;
    }

    /**
     * Nové nahrání uvolní místo: z nečinných exportů firmy nechá jen naposledy použité tak,
     * aby jich i s novým bylo nejvýš `$max`, starší smaže. Export, se kterým právě pracuje
     * job, zůstává. False = místo není, všechny exporty se zpracovávají.
     */
    public static function makeRoom(int $supplierId, int $max): bool
    {
        $idle = [];
        $busy = 0;
        foreach (glob(self::base($supplierId) . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
            if (preg_match(self::TOKEN_PATTERN, basename($dir)) !== 1) {
                continue;
            }
            if (self::isBusy($dir)) {
                $busy++;
            } else {
                $idle[$dir] = self::lastActivity($dir);
            }
        }
        arsort($idle);
        $keep = max(0, $max - 1 - $busy);
        foreach (array_keys($idle) as $dir) {
            if ($keep > 0) {
                $keep--;
                continue;
            }
            self::removeTree($dir, $supplierId);
        }
        return $busy < $max;
    }

    /** Průvodce s exportem pracuje (spouští převod) - denní úklid ho zatím nesmaže. */
    public static function touch(int $supplierId, string $token): void
    {
        $path = self::dir($supplierId, $token) . '/meta.json';
        if (is_file($path)) {
            @touch($path);
        }
    }

    /** Denní úklid ({@see api/bin/cron-cleanup.php}) nahraných exportů všech firem. */
    public static function purgeStaleAll(): int
    {
        $removed = 0;
        foreach (glob(RuntimePaths::storage('pohoda') . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
            if (preg_match('/^[1-9]\d*$/', basename($dir)) === 1) {
                $removed += self::purgeStale((int) basename($dir));
            }
        }
        return $removed;
    }

    private static function lastActivity(string $dir): int
    {
        $latest = 0;
        foreach ([$dir . '/meta.json', $dir . '/' . self::STATE_FILE, $dir . '/' . self::PART_FILE] as $file) {
            clearstatcache(true, $file);
            $latest = max($latest, is_file($file) ? (int) filemtime($file) : 0);
        }
        return $latest > 0 ? $latest : (int) filemtime($dir);
    }

    private static function isBusy(string $dir): bool
    {
        $path = $dir . '/' . self::JOB_LOCK_FILE;
        if (!is_file($path)) {
            return false;
        }
        $handle = @fopen($path, 'c');
        if ($handle === false) {
            return true;
        }
        $free = flock($handle, LOCK_EX | LOCK_NB);
        if ($free) {
            flock($handle, LOCK_UN);
        }
        fclose($handle);
        return !$free;
    }

    /** @param array<string,mixed> $data */
    private static function writeJson(string $path, array $data): void
    {
        $tmp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
        file_put_contents($tmp, (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            file_put_contents($path, (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        }
    }

    /** Mazání jen pod adresářem firmy; casing cest na Windows se porovnává malými písmeny. */
    private static function removeTree(string $dir, int $supplierId): void
    {
        $real = realpath($dir);
        $base = realpath(self::base($supplierId));
        if ($real === false || $base === false
            || !str_starts_with(strtolower($real), strtolower($base) . DIRECTORY_SEPARATOR)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($real, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($real);
    }
}
