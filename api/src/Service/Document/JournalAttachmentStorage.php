<?php

declare(strict_types=1);

namespace MyInvoice\Service\Document;

use MyInvoice\Infrastructure\Config\RuntimePaths;
use MyInvoice\Repository\JournalEntryAttachmentRepository;

/**
 * Bezpečné ukládání příloh účetního deníku (§33a) do VLASTNÍHO disk namespace
 * `storage/journal/sup-{id}/{sha[0:2]}/{sha256}` (content-addressed, mirror DocumentStorage)
 * — oddělené od DMS sha stromu (`storage/documents`), aby se ref-counting nekřížil se
 * subsystémem A. Původní název souboru žije jen v DB (original_name).
 *
 * Hardening je sdílený s {@see DocumentStorage} (jediný zdroj pravdy pro blocklist
 * a MIME/sanitizaci): detekce MIME z obsahu (finfo), DANGEROUS_EXT/MIME blocklist
 * (přes DocumentStorage::classify), sanitizace názvu, content-addressed dedup a
 * path-traversal guard (assertInsideBase). deleteIfOrphan počítá reference JEN
 * v `journal_entry_attachments` — NIKDY nesahá do DMS union (§4.4/§5.2).
 */
final class JournalAttachmentStorage
{
    /** Per-soubor strop (§5.2). */
    public const MAX_FILE_BYTES = 20 * 1024 * 1024;
    /** Per-zápis strop (součet všech příloh jednoho zápisu). */
    public const MAX_ENTRY_BYTES = 100 * 1024 * 1024;

    public function __construct(private readonly DocumentStorage $docs) {}

    public function maxFileBytes(): int { return self::MAX_FILE_BYTES; }

    public function maxEntryBytes(): int { return self::MAX_ENTRY_BYTES; }

    public static function baseDir(int $supplierId): string
    {
        return RuntimePaths::storage('journal') . '/sup-' . $supplierId;
    }

    public function dirFor(int $supplierId, string $sha256): string
    {
        return self::baseDir($supplierId) . '/' . substr($sha256, 0, 2);
    }

    public function pathFor(int $supplierId, string $sha256, string $filename): string
    {
        return $this->dirFor($supplierId, $sha256) . '/' . $filename;
    }

    /** Zapisovatelná dočasná cesta uvnitř journal sup-{id} kořene (vzor DocumentStorage). */
    public function tmpPath(int $supplierId): string
    {
        $base = self::baseDir($supplierId);
        if (!is_dir($base) && !@mkdir($base, 0755, true) && !is_dir($base)) {
            throw new DocumentException('storage_not_writable', 'Úložiště příloh deníku není zapisovatelné.', 500);
        }
        return $base . '/.tmp-' . bin2hex(random_bytes(8));
    }

    /**
     * Uloží soubor z dočasné cesty (přesun/kopie) do journal namespace, validuje politiku.
     *
     * @return array{sha256:string,filename:string,size_bytes:int,mime_type:string,doc_type:string,abs_path:string,ext:string}
     */
    public function storeFromTemp(string $tmpPath, int $supplierId, string $originalName): array
    {
        if (!is_file($tmpPath)) {
            throw new DocumentException('move_failed', 'Dočasný soubor nenalezen.', 500);
        }
        $size = (int) filesize($tmpPath);
        if ($size <= 0) {
            @unlink($tmpPath);
            throw new DocumentException('empty_file', 'Soubor je prázdný.', 400);
        }
        if ($size > self::MAX_FILE_BYTES) {
            @unlink($tmpPath);
            throw new DocumentException('file_too_large',
                'Soubor je příliš velký (max ' . (int) (self::MAX_FILE_BYTES / 1024 / 1024) . ' MiB).', 413);
        }

        // MIME z OBSAHU (klient-side Content-Type je nedůvěryhodný) + blocklist.
        $detectedMime = $this->docs->detectMime($tmpPath);
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        try {
            // Vynutí DANGEROUS_EXT/MIME blocklist (vyhodí DocumentException na spustitelné/aktivní obsahy).
            $this->docs->classify($ext, $detectedMime);
        } catch (DocumentException $e) {
            @unlink($tmpPath);
            throw $e;
        }
        $docType = $this->journalDocType($ext);

        $sha256 = hash_file('sha256', $tmpPath);
        if ($sha256 === false) {
            @unlink($tmpPath);
            throw new DocumentException('hash_failed', 'Nepodařilo se spočítat hash souboru.', 500);
        }

        // Content-addressed: na disk jen hash (dedup + countBySha konzistentní, žádný
        // uživatelský vstup v cestě — mirror DocumentStorage). Původní název žije v DB
        // jako original_name a použije se jako Content-Disposition při stažení.
        $diskName = $sha256;

        $dir = $this->dirFor($supplierId, $sha256);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            @unlink($tmpPath);
            throw new DocumentException('storage_not_writable', 'Úložiště příloh deníku není zapisovatelné.', 500);
        }
        $diskPath = $dir . '/' . $diskName;
        $this->assertInsideBase($supplierId, $diskPath);

        if (is_file($diskPath)) {
            // Stejný obsah už na disku je (dedup) — zahoď temp.
            @unlink($tmpPath);
        } elseif (!@rename($tmpPath, $diskPath)) {
            if (!@copy($tmpPath, $diskPath)) {
                @unlink($tmpPath);
                throw new DocumentException('store_failed', 'Nepodařilo se uložit soubor na disk.', 500);
            }
            @unlink($tmpPath);
        }

        return [
            'sha256'     => $sha256,
            'filename'   => $diskName,
            'size_bytes' => $size,
            'mime_type'  => $detectedMime !== '' ? $detectedMime : 'application/octet-stream',
            'doc_type'   => $docType,
            'abs_path'   => $diskPath,
            'ext'        => $ext,
        ];
    }

    /**
     * Uloží soubor ze surových bytů (test / programový vstup).
     * @return array{sha256:string,filename:string,size_bytes:int,mime_type:string,doc_type:string,abs_path:string,ext:string}
     */
    public function storeFromBytes(string $bytes, int $supplierId, string $originalName): array
    {
        $tmp = $this->tmpPath($supplierId);
        if (@file_put_contents($tmp, $bytes) === false) {
            throw new DocumentException('store_failed', 'Nepodařilo se zapsat dočasný soubor.', 500);
        }
        return $this->storeFromTemp($tmp, $supplierId, $originalName);
    }

    /**
     * Dedup-aware smazání bajtu — odpojí JEN když na sha256 neukazuje žádná jiná
     * příloha téhož dodavatele. Ref-counting je omezen VÝHRADNĚ na journal_entry_attachments
     * (vlastní namespace) — NIKDY se nekříží s DMS union (§4.4). $excludeId = 0 předpokládá,
     * že mazaný řádek už byl z DB odstraněn; jinak ho z počtu vyloučí.
     */
    public function deleteIfOrphan(
        int $supplierId,
        string $sha256,
        string $filename,
        JournalEntryAttachmentRepository $repo,
        int $excludeId = 0,
    ): void {
        if ($repo->countBySha($supplierId, $sha256, $excludeId) === 0) {
            $path = $this->pathFor($supplierId, $sha256, $filename);
            // LOW-3 — defense-in-depth: nikdy neunlinkuj mimo journal sup-{id} kořen (mirror Download).
            $this->assertInsideBase($supplierId, $path);
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }

    /** Mapa přípony → doc_type enum journal_entry_attachments (pdf/image/xml/isdoc/zfo/other). */
    private function journalDocType(string $ext): string
    {
        $ext = strtolower($ext);
        return match (true) {
            $ext === 'pdf'                                                                          => 'pdf',
            in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'heic', 'heif', 'bmp', 'tif', 'tiff'], true) => 'image',
            $ext === 'isdoc'                                                                        => 'isdoc',
            in_array($ext, ['xml', 'isdocx'], true)                                                 => 'xml',
            $ext === 'zfo'                                                                          => 'zfo',
            default                                                                                 => 'other',
        };
    }

    /**
     * Path-traversal guard: cílová cesta musí ležet uvnitř journal sup-{id} kořene.
     * Na Windows porovnáváme case-insensitive (parita s DocumentStorage::assertInsideBase).
     */
    private function assertInsideBase(int $supplierId, string $target): void
    {
        $base = self::baseDir($supplierId);
        $baseReal = realpath($base) ?: $base;
        $targetReal = realpath(dirname($target)) ?: dirname($target);
        $b = rtrim(str_replace('\\', '/', $baseReal), '/');
        $t = rtrim(str_replace('\\', '/', $targetReal), '/');
        if (DIRECTORY_SEPARATOR === '\\') {
            $b = strtolower($b);
            $t = strtolower($t);
        }
        if ($t !== $b && !str_starts_with($t . '/', $b . '/')) {
            throw new DocumentException('path_traversal', 'Neplatná cílová cesta.', 400);
        }
    }
}
