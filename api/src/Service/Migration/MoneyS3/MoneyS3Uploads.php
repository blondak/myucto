<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\MoneyS3;

use MyInvoice\Infrastructure\Config\RuntimePaths;
use Psr\Http\Message\StreamInterface;

/**
 * Nahrané zálohy agend čekající na převod: `storage/money-s3/<firma>/<token>/`.
 *
 * Záloha se nahraje jednou, rozbalí se a průvodce nad ní pouští náhled, zkoušku
 * nanečisto i ostrý převod — worker běží až po skončení requestu, takže data musí
 * ležet na disku. Token je náhodný a adresář je pod firmou: cizí firma na nahranou
 * zálohu nedosáhne ani se znalostí tokenu. Po úspěšném ostrém převodu se adresář
 * smaže. Záloha po zkoušce nanečisto nebo po neúspěšném převodu zůstává pro další běh
 * a denní úklid ji smaže po týdnu bez práce s ní ({@see purgeStaleAll()}).
 *
 * Velká záloha přichází po částech: `upload.json` drží stav nahrávání a zpracování,
 * části se připojují do `backup.lz.part` a job na pozadí z ní po dokončení rozbalí
 * agendu a zapíše `meta.json`. Po dobu zpracování drží job zámek `job.lock` a úklid
 * takový adresář nesmaže.
 */
final class MoneyS3Uploads
{
    public const TOKEN_PATTERN = '/^[a-f0-9]{16}$/';
    public const STATUS_UPLOADING = 'uploading';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_READY = 'ready';
    public const STATUS_FAILED = 'failed';
    private const STALE_DAYS = 7;
    private const STATE_FILE = 'upload.json';
    private const PART_FILE = 'backup.lz.part';
    private const UPLOAD_LOCK_FILE = 'upload.lock';
    private const JOB_LOCK_FILE = 'job.lock';

    public static function base(int $supplierId): string
    {
        return RuntimePaths::storage('money-s3/' . $supplierId);
    }

    public static function newToken(): string
    {
        return bin2hex(random_bytes(8));
    }

    public static function dir(int $supplierId, string $token): string
    {
        if ($supplierId <= 0 || preg_match(self::TOKEN_PATTERN, $token) !== 1) {
            throw new MoneyS3Exception('upload_not_found', 'Nahraná záloha nebyla nalezena.', [], 404);
        }
        return self::base($supplierId) . '/' . $token;
    }

    public static function agendaDir(int $supplierId, string $token): string
    {
        return self::dir($supplierId, $token) . '/agenda';
    }

    /** @return array<string,mixed> */
    public static function meta(int $supplierId, string $token): array
    {
        $path = self::dir($supplierId, $token) . '/meta.json';
        if (!is_file($path)) {
            throw new MoneyS3Exception('upload_not_found', 'Nahraná záloha nebyla nalezena (mohla být už uklizena).', [], 404);
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

    /**
     * Stav nahrávání po částech a zpracování na pozadí, nebo null u zálohy nahrané
     * jedním požadavkem (ta má rovnou `meta.json`).
     *
     * @return array<string,mixed>|null
     */
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
     * Připojí část zálohy na konec `backup.lz.part`. Přijme ji jen tehdy, když navazuje
     * přesně na to, co už server má — opakovaná nebo předbíhající část vrátí 409
     * s tím, kolik bajtů server drží, a klient podle toho naváže.
     *
     * @return int kolik bajtů zálohy server po připojení drží
     */
    public static function appendChunk(int $supplierId, string $token, int $offset, StreamInterface $chunk, int $maxChunkBytes): int
    {
        return self::withUploadLock($supplierId, $token, static function () use ($supplierId, $token, $offset, $chunk, $maxChunkBytes): int {
            $state = self::state($supplierId, $token);
            if ($state === null) {
                throw new MoneyS3Exception('upload_not_found', 'Nahrávaná záloha nebyla nalezena.', [], 404);
            }
            $size = (int) ($state['size'] ?? 0);
            $current = self::partSize($supplierId, $token);
            if (($state['status'] ?? '') !== self::STATUS_UPLOADING) {
                throw new MoneyS3Exception('upload_not_uploading', 'Záloha už je nahraná celá.', ['received' => $current], 409);
            }
            if ($offset !== $current) {
                throw new MoneyS3Exception('chunk_offset_mismatch', 'Část zálohy nenavazuje na už nahraná data.', ['received' => $current], 409);
            }
            $out = @fopen(self::partPath($supplierId, $token), 'ab');
            if ($out === false) {
                throw new MoneyS3Exception('storage_not_writable', 'Úložiště pro zálohy není zapisovatelné.', [], 500);
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
                        throw new MoneyS3Exception('chunk_too_large', 'Část zálohy je větší, než server přijme.', ['received' => $current], 413);
                    }
                    if ($current + $written + $length > $size) {
                        throw new MoneyS3Exception('chunk_exceeds_size', 'Nahrávaná data jsou delší než ohlášená velikost zálohy.', ['received' => $current], 422);
                    }
                    if (fwrite($out, $buffer) !== $length) {
                        throw new MoneyS3Exception('storage_not_writable', 'Část zálohy se nepodařilo uložit.', ['received' => $current], 500);
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
                throw new MoneyS3Exception('chunk_empty', 'Část zálohy je prázdná.', ['received' => $current], 400);
            }
            $received = $current + $written;
            self::updateState($supplierId, $token, ['received' => $received]);
            return $received;
        });
    }

    /**
     * Stav nahrávání mění souběžné požadavky (opakovaná část, dvojí dokončení) — každá
     * změna proto běží pod výhradním zámkem tokenu.
     *
     * @template T
     * @param callable():T $fn
     * @return T
     */
    public static function withUploadLock(int $supplierId, string $token, callable $fn): mixed
    {
        $handle = @fopen(self::dir($supplierId, $token) . '/' . self::UPLOAD_LOCK_FILE, 'c');
        if ($handle === false) {
            throw new MoneyS3Exception('upload_not_found', 'Nahrávaná záloha nebyla nalezena.', [], 404);
        }
        try {
            flock($handle, LOCK_EX);
            return $fn();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * Zámek, který job drží po celé zpracování zálohy. Úklid adresář se zámkem
     * nesmaže ({@see purgeStale()}).
     *
     * @return resource|null null = zálohu už zpracovává jiný proces
     */
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

    /**
     * Zahodí data nepovedeného zpracování (rozbalenou agendu a nahranou zálohu), stav
     * s chybou ale nechá — průvodce ji ukáže a denní úklid adresář smaže později.
     */
    public static function discardData(int $supplierId, string $token): void
    {
        try {
            $dir = self::dir($supplierId, $token);
        } catch (MoneyS3Exception) {
            return;
        }
        if (is_dir($dir . '/agenda')) {
            self::removeTree($dir . '/agenda', $supplierId);
        }
        @unlink($dir . '/' . self::PART_FILE);
        @unlink($dir . '/meta.json');
    }

    public static function reportPath(int $supplierId, string $token, int $year): string
    {
        return self::dir($supplierId, $token) . '/reports/' . $year . '.csv';
    }

    /** @return array<int,string> rok => cesta k předvaze z Money */
    public static function reports(int $supplierId, string $token): array
    {
        $out = [];
        foreach (glob(self::dir($supplierId, $token) . '/reports/*.csv') ?: [] as $path) {
            $year = (int) basename($path, '.csv');
            if ($year >= 1990 && $year <= 2100) {
                $out[$year] = $path;
            }
        }
        ksort($out);
        return $out;
    }

    public static function purge(int $supplierId, string $token): void
    {
        try {
            $dir = self::dir($supplierId, $token);
        } catch (MoneyS3Exception) {
            return;
        }
        self::removeTree($dir, $supplierId);
    }

    /** @return int kolik nahraných záloh firmy se smazalo */
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
     * Denní úklid ({@see api/bin/cron-cleanup.php}) nahraných záloh všech firem.
     * Rozbalená agenda je celé účetnictví firmy — nesmí na disku čekat, až ji uklidí
     * další upload téže firmy, který nemusí přijít nikdy.
     */
    public static function purgeStaleAll(): int
    {
        $removed = 0;
        foreach (glob(RuntimePaths::storage('money-s3') . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
            if (preg_match('/^[1-9]\d*$/', basename($dir)) === 1) {
                $removed += self::purgeStale((int) basename($dir));
            }
        }
        return $removed;
    }

    /**
     * Poslední práce se zálohou: nahrání (meta.json), stav nahrávání po částech,
     * poslední přijatá část nebo připojená sestava z Money. Čas adresáře nestačí —
     * Windows ho u adresářů změnou obsahu podadresářů neposouvá.
     */
    private static function lastActivity(string $dir): int
    {
        $latest = 0;
        $files = array_merge(
            [$dir . '/meta.json', $dir . '/' . self::STATE_FILE, $dir . '/' . self::PART_FILE],
            glob($dir . '/reports/*.csv') ?: [],
        );
        foreach ($files as $file) {
            clearstatcache(true, $file);
            $latest = max($latest, is_file($file) ? (int) filemtime($file) : 0);
        }
        return $latest > 0 ? $latest : (int) filemtime($dir);
    }

    /** Drží zámek zálohy běžící job zpracování? */
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

    /** Zápis přes dočasný soubor — souběžné čtení nikdy nedostane napůl zapsaný JSON. */
    private static function writeJson(string $path, array $data): void
    {
        $tmp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
        file_put_contents($tmp, (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            file_put_contents($path, (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        }
    }

    /**
     * Mazání jen pod adresářem firmy. Casing cest na Windows nesedí spolehlivě,
     * proto se obě strany porovnávají malými písmeny.
     */
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
