<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\StereoNx;

use MyInvoice\Infrastructure\Config\RuntimePaths;
use MyInvoice\Service\Migration\Shared\MigrationUploadLimits;
use Psr\Http\Message\StreamInterface;

/** Přechodné šifrované archivy jsou oddělené podle cílové firmy a náhodného tokenu. */
final class StereoNxUploads
{
    public const CHUNK_BYTES = MigrationUploadLimits::CHUNK_BYTES;
    public const MAX_BYTES = MigrationUploadLimits::STEREO_NX_MAX_BYTES;
    private const MAX_ACTIVE = MigrationUploadLimits::MAX_ACTIVE_UPLOADS;
    private const RESULT_PATTERN = '/^result-[1-9]\d*\.json(\.tmp)?$/D';

    public static function token(): string
    {
        return bin2hex(random_bytes(16));
    }

    public static function dir(int $supplierId, string $token): string
    {
        if ($supplierId < 1 || preg_match('/^[a-f0-9]{32}$/D', $token) !== 1) {
            throw new StereoNxException('upload_not_found', 'Nahraná záloha nebyla nalezena.');
        }
        return RuntimePaths::storage('stereo-nx/' . $supplierId . '/' . $token);
    }

    public static function archive(int $supplierId, string $token): string
    {
        return self::dir($supplierId, $token) . '/backup.zip';
    }

    /** @return list<array{token:string,filename:string,size:int,received:int,complete:bool,created_at:int}> */
    public static function listForUser(int $supplierId, int $userId): array
    {
        if ($supplierId < 1 || $userId < 1) return [];
        $root = RuntimePaths::storage();
        $uploadsRoot = $root . '/stereo-nx';
        $base = $uploadsRoot . '/' . $supplierId;
        if (is_link($root) || is_link($uploadsRoot) || is_link($base) || !is_dir($base)) return [];

        $uploads = [];
        foreach (scandir($base) ?: [] as $token) {
            if (preg_match('/^[a-f0-9]{32}$/D', $token) !== 1) continue;
            $dir = $base . '/' . $token;
            $statePath = $dir . '/state.json';
            $archive = $dir . '/backup.zip';
            if (is_link($dir) || !is_dir($dir) || is_link($statePath) || !is_file($statePath)
                || is_link($archive) || filesize($statePath) > 65536) continue;
            $state = json_decode((string) file_get_contents($statePath), true);
            if (!is_array($state) || (int) ($state['uploaded_by'] ?? 0) !== $userId
                || !in_array($state['status'] ?? null, ['uploading', 'ready'], true)) continue;
            $size = (int) ($state['size'] ?? 0);
            if ($size < 1 || $size > self::MAX_BYTES) continue;
            $uploads[] = [
                'token' => $token,
                'filename' => basename(str_replace('\\', '/', (string) ($state['file_name'] ?? ''))),
                'size' => $size,
                'received' => max(0, min($size, (int) ($state['received'] ?? 0))),
                'complete' => ($state['status'] === 'ready' && is_file($archive) && filesize($archive) === $size),
                'created_at' => max(0, (int) ($state['created_at'] ?? 0)),
            ];
        }
        usort($uploads, static fn (array $a, array $b): int => $b['created_at'] <=> $a['created_at'] ?: strcmp($b['token'], $a['token']));
        return $uploads;
    }

    public static function prepareRoom(int $supplierId): bool
    {
        $base = RuntimePaths::storage('stereo-nx/' . $supplierId);
        $active = 0;
        foreach (glob($base . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $token = basename($dir);
            if (preg_match('/^[a-f0-9]{32}$/D', $token) !== 1) continue;
            $statePath = $dir . '/state.json';
            $state = is_file($statePath) ? json_decode((string) file_get_contents($statePath), true) : null;
            if (!is_array($state) || (int) ($state['created_at'] ?? 0) < time() - 7 * 86400) {
                $handle = @fopen($dir . '/lock', 'c');
                if ($handle !== false && flock($handle, LOCK_EX | LOCK_NB)) {
                    if (is_array($state)) {
                        $state['status'] = 'deleting';
                        self::save($supplierId, $token, $state);
                    }
                    flock($handle, LOCK_UN);
                    fclose($handle);
                    self::delete($supplierId, $token);
                } else {
                    if ($handle !== false) fclose($handle);
                    $active++;
                }
            } else {
                $active++;
            }
        }
        return $active < self::MAX_ACTIVE;
    }

    public static function delete(int $supplierId, string $token): void
    {
        $dir = self::dir($supplierId, $token);
        if (!is_dir($dir)) return;
        $names = ['backup.zip', 'state.json', 'state.json.tmp', 'lock'];
        foreach (scandir($dir) ?: [] as $name) {
            if (preg_match(self::RESULT_PATTERN, $name) === 1) $names[] = $name;
        }
        foreach ($names as $name) {
            $path = $dir . '/' . $name;
            if (is_file($path)) @unlink($path);
        }
        @rmdir($dir);
    }

    /**
     * Výsledek (report) převodu, který běžel jako job na pozadí. Leží u zálohy, dokud
     * ji uživatel nebo úklid nesmaže; průvodce si ho po doběhnutí jobu stáhne.
     *
     * @param array<string,mixed> $report
     */
    public static function saveResult(int $supplierId, string $token, int $jobId, array $report): void
    {
        $path = self::dir($supplierId, $token) . '/result-' . $jobId . '.json';
        $temp = $path . '.tmp';
        $json = json_encode($report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
        if ($json === false || file_put_contents($temp, $json, LOCK_EX) === false || !rename($temp, $path)) {
            @unlink($temp);
            throw new StereoNxException('storage_error', 'Výsledek převodu nelze uložit.');
        }
    }

    /** @return array<string,mixed>|null null = job ještě nedoběhl nebo výsledek neuložil */
    public static function result(int $supplierId, string $token, int $jobId): ?array
    {
        $path = self::dir($supplierId, $token) . '/result-' . $jobId . '.json';
        if ($jobId < 1 || !is_file($path) || is_link($path)) return null;
        $report = json_decode((string) file_get_contents($path), true);
        return is_array($report) ? $report : null;
    }

    /** @return array<string,mixed> */
    public static function state(int $supplierId, string $token): array
    {
        $path = self::dir($supplierId, $token) . '/state.json';
        if (!is_file($path)) {
            throw new StereoNxException('upload_not_found', 'Nahraná záloha nebyla nalezena.');
        }
        $state = json_decode((string) file_get_contents($path), true);
        if (!is_array($state)) {
            throw new StereoNxException('upload_corrupt', 'Stav zálohy nelze načíst.');
        }
        return $state;
    }

    /** @param array<string,mixed> $state */
    public static function save(int $supplierId, string $token, array $state): void
    {
        $path = self::dir($supplierId, $token) . '/state.json';
        $temp = $path . '.tmp';
        if (file_put_contents($temp, json_encode($state, JSON_THROW_ON_ERROR), LOCK_EX) === false || !rename($temp, $path)) {
            throw new StereoNxException('storage_error', 'Stav zálohy nelze uložit.');
        }
    }

    /** @template T @param callable(array<string,mixed>):T $callback @return T */
    public static function locked(int $supplierId, string $token, callable $callback): mixed
    {
        $handle = @fopen(self::dir($supplierId, $token) . '/lock', 'c');
        if ($handle === false) {
            throw new StereoNxException('upload_not_found', 'Nahraná záloha nebyla nalezena.');
        }
        try {
            flock($handle, LOCK_EX);
            return $callback(self::state($supplierId, $token));
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    public static function append(int $supplierId, string $token, int $offset, StreamInterface $stream): int
    {
        return self::locked($supplierId, $token, static function (array $state) use ($supplierId, $token, $offset, $stream): int {
            if (($state['status'] ?? '') !== 'uploading') {
                throw new StereoNxException('upload_finished', 'Záloha už byla nahrána.');
            }
            $path = self::archive($supplierId, $token);
            clearstatcache(true, $path);
            $current = is_file($path) ? (int) filesize($path) : 0;
            if ($offset !== $current) {
                throw new StereoNxException('chunk_offset_mismatch', 'Část zálohy nenavazuje na nahraná data.');
            }
            $out = @fopen($path, 'ab');
            if ($out === false) {
                throw new StereoNxException('storage_error', 'Zálohu nelze uložit.');
            }
            $written = 0;
            try {
                if ($stream->isSeekable()) $stream->rewind();
                while (!$stream->eof()) {
                    $part = $stream->read(65536);
                    if ($part === '') break;
                    $length = strlen($part);
                    if ($written + $length > self::CHUNK_BYTES || $current + $written + $length > (int) $state['size']) {
                        throw new StereoNxException('chunk_too_large', 'Část zálohy překračuje povolenou velikost.');
                    }
                    if (fwrite($out, $part) !== $length) {
                        throw new StereoNxException('storage_error', 'Zálohu nelze uložit.');
                    }
                    $written += $length;
                }
                if ($written === 0) throw new StereoNxException('chunk_empty', 'Část zálohy je prázdná.');
                fflush($out);
            } catch (\Throwable $e) {
                ftruncate($out, $current);
                throw $e;
            } finally {
                fclose($out);
            }
            $state['received'] = $current + $written;
            self::save($supplierId, $token, $state);
            return $state['received'];
        });
    }
}
