<?php

declare(strict_types=1);

namespace MyInvoice\Service\Export\Instance;

use MyInvoice\Service\Backup\BackupZipPermissions;
use MyInvoice\Service\Cron\BackupEncryption;
use ZipArchive;

/**
 * Zapisovač archivu — jediné místo, kudy do exportního ZIPu něco vleze.
 *
 * Tři věci, které tu jsou schválně a bez kterých by export instanci položil:
 *
 * 1. **Průběžný flush.** `ZipArchive::addFromString()` si obsah drží v PAMĚTI až do
 *    `close()`. Export celého účetnictví jich udělá desetitisíce, takže bez flushe
 *    proces sežere paměť dřív, než dojde na PDF. Proto se po každých
 *    {@see FLUSH_ENTRIES} položkách (nebo {@see FLUSH_BYTES}) archiv zavře a znovu
 *    otevře v režimu CREATE — data se zapíšou na disk a paměť se uvolní. Volající to
 *    musí respektovat u `addFile()`: zdrojový soubor smí zmizet až PO flushi
 *    (ZipArchive ho čte teprve při close), na což je {@see flushNow()}.
 *
 * 2. **Kvóta.** Filesystémová kvóta instance je zaplacený objem plus rezerva na dumpy.
 *    Kdyby ji export vyčerpal, instance se uprostřed práce přepne do režimu jen pro
 *    čtení. Proto se u každého flushe kontroluje volné místo i strop archivu a při
 *    překročení se běh ukončí {@see InstanceExportException} — rozdělaný ZIP smaže
 *    volající. Lepší srozumitelná chyba než plný disk.
 *
 * 3. **Kontrolní součty.** Každá položka se hashuje SHA-256 při zápisu (u souborů
 *    přes `hash_file`, u řetězců z bufferu), takže manifest umí říct, co v archivu je,
 *    a `CHECKSUMS.txt` dovolí zákazníkovi po roce ověřit, že se stáhl celý.
 */
final class InstanceExportArchive
{
    /** Po kolika položkách se ZIP zavře a znovu otevře (uvolnění paměti). */
    private const FLUSH_ENTRIES = 200;

    /** Po kolika bajtech nezflushnutého obsahu se ZIP zavře a znovu otevře. */
    private const FLUSH_BYTES = 32 * 1024 * 1024;

    /** Minimum volného místa, které exportu nesmí zůstat pod nohama. */
    private const MIN_FREE_BYTES = 256 * 1024 * 1024;

    private ?ZipArchive $zip = null;

    /** @var array<string, array{sha256:string, size:int}> zip cesta => součet */
    private array $entries = [];

    private int $pendingEntries = 0;
    private int $pendingBytes = 0;
    private int $totalBytes = 0;
    private bool $closed = false;

    /** @var list<string> soubory ke smazání po nejbližším flushi */
    private array $pendingTempFiles = [];

    public function __construct(
        private readonly string $zipPath,
        private readonly string $password,
        private readonly int $maxBytes,
    ) {
        $zip = new ZipArchive();
        if ($zip->open($this->zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new InstanceExportException('zip_open_failed', 'Nelze vytvořit archiv: ' . $this->zipPath);
        }
        $this->zip = $zip;
        $this->assertQuota();
    }

    /** Přidá položku z řetězce (manifest, JSONL malé tabulky, README). */
    public function addString(string $entryName, string $contents): void
    {
        $zip = $this->requireZip();
        if (!$zip->addFromString($entryName, $contents)) {
            throw new InstanceExportException('zip_add_failed', 'Nelze přidat položku archivu: ' . $entryName);
        }
        $this->finishEntry($entryName, hash('sha256', $contents), strlen($contents), strlen($contents));
    }

    /**
     * Přidá položku ze souboru na disku.
     *
     * @param bool $deleteAfterFlush zdroj je dočasný a po zapsání do ZIPu se smaže
     *                               (drží se tím dočasné místo na jedné tabulce, ne na celém exportu)
     */
    public function addFile(string $entryName, string $sourcePath, bool $deleteAfterFlush = false): void
    {
        if (!is_file($sourcePath)) {
            throw new InstanceExportException('source_missing', 'Zdroj položky neexistuje: ' . $sourcePath);
        }
        $zip = $this->requireZip();
        if (!$zip->addFile($sourcePath, $entryName)) {
            throw new InstanceExportException('zip_add_failed', 'Nelze přidat položku archivu: ' . $entryName);
        }
        $size = (int) (filesize($sourcePath) ?: 0);
        $hash = hash_file('sha256', $sourcePath);
        if ($deleteAfterFlush) {
            $this->pendingTempFiles[] = $sourcePath;
        }
        // Soubor čte ZipArchive až při close, takže do "pending" paměti se nepočítá;
        // do rozpočtu flushe ale ano, ať se dočasné soubory uvolní včas.
        $this->finishEntry($entryName, $hash === false ? '' : $hash, $size, $size);
    }

    /** Vynutí zapsání na disk (a úklid dočasných zdrojů) hned teď. */
    public function flushNow(): void
    {
        $zip = $this->requireZip();
        if ($this->pendingEntries === 0) {
            return;
        }
        if (!$zip->close()) {
            $this->zip = null;
            throw new InstanceExportException('zip_close_failed', 'Zápis archivu na disk selhal.');
        }
        foreach ($this->pendingTempFiles as $tmp) {
            @unlink($tmp);
        }
        $this->pendingTempFiles = [];
        $this->pendingEntries = 0;
        $this->pendingBytes = 0;

        $reopened = new ZipArchive();
        if ($reopened->open($this->zipPath, ZipArchive::CREATE) !== true) {
            $this->zip = null;
            throw new InstanceExportException('zip_open_failed', 'Nelze znovu otevřít archiv: ' . $this->zipPath);
        }
        $this->zip = $reopened;
        $this->assertQuota();
    }

    /** Uzavře archiv. Vrací [velikost v bajtech, sha256 celého souboru]. */
    public function finish(): array
    {
        $zip = $this->requireZip();
        if (!$zip->close()) {
            $this->zip = null;
            throw new InstanceExportException('zip_close_failed', 'Zápis archivu na disk selhal.');
        }
        foreach ($this->pendingTempFiles as $tmp) {
            @unlink($tmp);
        }
        $this->pendingTempFiles = [];
        $this->zip = null;
        $this->closed = true;

        $size = (int) (filesize($this->zipPath) ?: 0);
        $sha = hash_file('sha256', $this->zipPath);
        return [$size, $sha === false ? '' : $sha];
    }

    /** Zahodí rozdělaný archiv (chyba, storno). */
    public function discard(): void
    {
        if ($this->zip !== null) {
            @$this->zip->close();
            $this->zip = null;
        }
        foreach ($this->pendingTempFiles as $tmp) {
            @unlink($tmp);
        }
        $this->pendingTempFiles = [];
        @unlink($this->zipPath);
        $this->closed = true;
    }

    /**
     * Kontrolní součty položek pro manifest a CHECKSUMS.txt.
     *
     * @return array<string, array{sha256:string, size:int}>
     */
    public function entries(): array
    {
        return $this->entries;
    }

    public function entryCount(): int
    {
        return count($this->entries);
    }

    /** Součet nekomprimovaných velikostí přidaných položek. */
    public function rawBytes(): int
    {
        return $this->totalBytes;
    }

    // ── interní ───────────────────────────────────────────────────────────────

    private function requireZip(): ZipArchive
    {
        if ($this->zip === null || $this->closed) {
            throw new InstanceExportException('zip_closed', 'Archiv je už uzavřený.');
        }
        return $this->zip;
    }

    private function finishEntry(string $entryName, string $sha256, int $size, int $budget): void
    {
        $zip = $this->requireZip();
        if (!BackupZipPermissions::neutralize($zip, $entryName)) {
            throw new InstanceExportException('zip_perm_failed', 'Nelze sjednotit práva položky: ' . $entryName);
        }
        // Šifrování drží stejnou konvenci jako zálohy (cfg `cron.backup.password`,
        // AES-256). Prázdné heslo = no-op; varování loguje volající jednou za běh.
        if (!BackupEncryption::encryptEntry($zip, $entryName, $this->password)) {
            throw new InstanceExportException('zip_encrypt_failed', 'Nelze zašifrovat položku: ' . $entryName);
        }

        $this->entries[$entryName] = ['sha256' => $sha256, 'size' => $size];
        $this->totalBytes += $size;
        $this->pendingEntries++;
        $this->pendingBytes += $budget;

        if ($this->pendingEntries >= self::FLUSH_ENTRIES || $this->pendingBytes >= self::FLUSH_BYTES) {
            $this->flushNow();
        }
    }

    /**
     * Vejde se export ještě do kvóty? Kontroluje se strop archivu i volné místo —
     * první chrání zákazníka před nekonečným exportem, druhé instanci před tím, aby
     * si zaplněným diskem přepnula do read-only.
     */
    private function assertQuota(): void
    {
        $current = (int) (@filesize($this->zipPath) ?: 0);
        if ($this->maxBytes > 0 && $current > $this->maxBytes) {
            throw new InstanceExportException(
                'quota_exceeded',
                sprintf(
                    'Export překročil povolenou velikost (%s > %s). Zvol užší rozsah, nebo zvyš cfg `export.instance.max_bytes`.',
                    self::human($current),
                    self::human($this->maxBytes),
                ),
            );
        }
        $free = @disk_free_space(dirname($this->zipPath));
        if (is_float($free) && $free < self::MIN_FREE_BYTES) {
            throw new InstanceExportException(
                'disk_full',
                sprintf('Na disku zbývá jen %s — export se zastavil, aby instance nespadla do režimu jen pro čtení.', self::human((int) $free)),
            );
        }
    }

    private static function human(int $bytes): string
    {
        $units = ['B', 'kB', 'MB', 'GB', 'TB'];
        $i = $bytes > 0 ? min((int) floor(log($bytes, 1024)), count($units) - 1) : 0;
        return round($bytes / 1024 ** $i, $i > 0 ? 1 : 0) . ' ' . $units[$i];
    }
}
