<?php

declare(strict_types=1);

namespace MyInvoice\Service\System;

use DateTimeImmutable;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Úklid toho, co jinak roste donekonečna: zálohy, logy a dočasné soubory.
 *
 * Zálohovací crony mají vlastní retenci (30 dnů + měsíční rok), která dává smysl
 * na self-hostu, kde jsou jedinou zálohou zákazníka. Tahle služba ji zpřísňuje
 * podle {@see StorageRetentionPolicy} a přidává věci, které neuklízí nikdo:
 * `log/cron/`, nerotovaný Docker log, pozůstatky ve `storage/tmp`.
 *
 * Co se NIKDY nemaže: doklady, dokumenty, mzdové podklady, účetní archivy,
 * uzávěrkové balíčky, zálohy cizí databáze ve sdíleném adresáři ani soubor
 * s jiným názvem, než jaký vyrábí naše crony. Nejnovější záloha každého druhu
 * zůstává vždy, i kdyby byla starší než limit. Po výpadku zálohování nesmí
 * úklid smazat poslední kopii, která existuje.
 */
final class StorageRetentionSweeper
{
    private const SNAPSHOT_KINDS = ['pdf', 'documents', 'payroll'];

    /** @var array<string,int> */
    private array $report = [];

    /** @var list<string> */
    private array $failed = [];

    /** @var list<string> */
    private array $deleted = [];

    private int $freedBytes = 0;

    /**
     * @param list<string> $logDirs adresáře s logy (bez rekurze)
     */
    public function __construct(
        private readonly StorageRetentionPolicy $policy,
        private readonly string $backupDir,
        private readonly string $dbName,
        private readonly array $logDirs,
        private readonly string $storageDir,
        private readonly DateTimeImmutable $now,
        private readonly bool $dryRun = false,
    ) {}

    /** @return array<string,mixed> */
    public function run(): array
    {
        $this->report = [];
        $this->failed = [];
        $this->deleted = [];
        $this->freedBytes = 0;

        $this->sweepBackups();
        $this->sweepLogs();
        $this->sweepTemporary();
        $this->sweepTwigCache();

        return $this->report + [
            'freed_mb' => round($this->freedBytes / 1048576, 1),
            'failed'   => count($this->failed),
        ];
    }

    /** @return list<string> smazané soubory (v režimu nanečisto ty, které by se smazaly) */
    public function deletedPaths(): array
    {
        return $this->deleted;
    }

    /** @return list<string> */
    public function failedPaths(): array
    {
        return $this->failed;
    }

    /**
     * Dumpy databáze: v okně `$keepAllHours` všechny, starší do `$days` jen
     * nejnovější dump každého dne, ještě starší pryč. Nejnovější vždy zůstává.
     *
     * @param array<string,DateTimeImmutable> $files cesta => čas zálohy z názvu
     * @return list<string>
     */
    public static function selectDatabasePurge(
        array $files,
        DateTimeImmutable $now,
        int $days,
        int $keepAllHours,
    ): array {
        if ($days <= 0 || $files === []) {
            return [];
        }
        uasort($files, static fn (DateTimeImmutable $a, DateTimeImmutable $b): int => $b <=> $a);

        $cutoff = $now->modify('-' . $days . ' days');
        $keepAllFrom = $now->modify('-' . max(0, $keepAllHours) . ' hours');
        $daysKept = [];
        $purge = [];
        $first = true;
        foreach ($files as $path => $at) {
            $day = $at->format('Y-m-d');
            if ($first) {
                $first = false;
                $daysKept[$day] = true;
                continue;
            }
            if ($keepAllHours > 0 && $at >= $keepAllFrom) {
                $daysKept[$day] = true;
                continue;
            }
            if ($at < $cutoff || isset($daysKept[$day])) {
                $purge[] = $path;
                continue;
            }
            $daysKept[$day] = true;
        }

        return $purge;
    }

    /**
     * Plné snímky (PDF, Dokumenty, Mzdy): drž `$copies` nejnovějších.
     *
     * @param array<string,DateTimeImmutable> $files
     * @return list<string>
     */
    public static function selectSnapshotPurge(array $files, int $copies): array
    {
        if ($copies <= 0 || $files === []) {
            return [];
        }
        uasort($files, static fn (DateTimeImmutable $a, DateTimeImmutable $b): int => $b <=> $a);

        return array_values(array_slice(array_keys($files), $copies));
    }

    private function sweepBackups(): void
    {
        if (!is_dir($this->backupDir)) {
            return;
        }

        // Jen soubory s názvem, jaký vyrábí naše crony, a jen pro AKTUÁLNÍ
        // databázi, protože ve sdíleném adresáři můžou ležet zálohy jiné instalace.
        // Tvar názvu odpovídá {@see \MyInvoice\Service\Backup\BackupArchiveCatalog}.
        $db = preg_quote($this->dbName, '/');
        $namePattern = '/^' . $db . '-(?:(pdf|documents|payroll)-)?(\d{4}-\d{2}-\d{2})(?:_(\d{2})-(\d{2}))?\.(?:zip|sql\.gz)$/i';
        $tempPattern = '/^\.' . $db . '-\d{4}-\d{2}-\d{2}(?:_\d{2}-\d{2})?\.sql$/i';

        $byKind = ['database' => []];
        foreach (self::SNAPSHOT_KINDS as $kind) {
            $byKind[$kind] = [];
        }
        $temp = [];

        foreach (new FilesystemIterator($this->backupDir, FilesystemIterator::SKIP_DOTS) as $entry) {
            /** @var SplFileInfo $entry */
            if (!$entry->isFile()) {
                continue;
            }
            $name = $entry->getFilename();
            if (preg_match($namePattern, $name, $m) === 1) {
                $at = DateTimeImmutable::createFromFormat(
                    '!Y-m-d H:i',
                    $m[2] . ' ' . (($m[3] ?? '') !== '' ? $m[3] : '00') . ':' . (($m[4] ?? '') !== '' ? $m[4] : '00'),
                );
                if ($at === false) {
                    continue;
                }
                $kind = $m[1] !== '' ? strtolower($m[1]) : 'database';
                $byKind[$kind][$entry->getPathname()] = $at;
            } elseif (preg_match($tempPattern, $name) === 1) {
                $temp[] = $entry;
            }
        }

        $this->deleteAll('backup_database', self::selectDatabasePurge(
            $byKind['database'],
            $this->now,
            $this->policy->dbDays,
            $this->policy->dbKeepAllHours,
        ));
        foreach (self::SNAPSHOT_KINDS as $kind) {
            $this->deleteAll('backup_' . $kind, self::selectSnapshotPurge($byKind[$kind], $this->policy->snapshotCopies));
        }

        // Rozdělaný dump po spadlém běhu; nový běh si založí vlastní soubor.
        if ($this->policy->tmpHours > 0) {
            $limit = $this->now->getTimestamp() - $this->policy->tmpHours * 3600;
            $stale = [];
            foreach ($temp as $entry) {
                if ($entry->getMTime() < $limit) {
                    $stale[] = $entry->getPathname();
                }
            }
            $this->deleteAll('backup_temp', $stale);
        }
    }

    private function sweepLogs(): void
    {
        $limit = $this->now->getTimestamp() - $this->policy->logDays * 86400;
        $maxBytes = $this->policy->logMaxMb * 1048576;
        $expired = [];
        $trimmed = 0;

        foreach (array_unique($this->logDirs) as $dir) {
            if (!is_dir($dir)) {
                continue;
            }
            foreach (new FilesystemIterator($dir, FilesystemIterator::SKIP_DOTS) as $entry) {
                /** @var SplFileInfo $entry */
                if (!$entry->isFile() || strtolower($entry->getExtension()) !== 'log') {
                    continue;
                }
                if ($this->policy->logDays > 0 && $entry->getMTime() < $limit) {
                    $expired[] = $entry->getPathname();
                    continue;
                }
                if ($maxBytes > 0 && $entry->getSize() > $maxBytes && $this->trimLog($entry->getPathname(), $maxBytes)) {
                    $trimmed++;
                }
            }
        }

        $this->deleteAll('logs', $expired);
        $this->report['logs_trimmed'] = $trimmed;
    }

    /**
     * Log, do kterého se pořád zapisuje (Docker `log/cron/<skript>.log`), nejde
     * smazat podle stáří. Zkrátí se na poslední desetinu limitu od začátku řádku.
     */
    private function trimLog(string $path, int $maxBytes): bool
    {
        $size = (int) filesize($path);
        $keep = max(1, intdiv($maxBytes, 10));
        if ($this->dryRun) {
            $this->freedBytes += max(0, $size - $keep);
            return true;
        }

        $fh = @fopen($path, 'c+b');
        if ($fh === false) {
            $this->failed[] = $path;
            return false;
        }
        try {
            if (!flock($fh, LOCK_EX | LOCK_NB)) {
                $this->failed[] = $path;
                return false;
            }
            fseek($fh, -$keep, SEEK_END);
            $tail = (string) stream_get_contents($fh);
            $newline = strpos($tail, "\n");
            if ($newline !== false) {
                $tail = substr($tail, $newline + 1);
            }
            $marker = '[' . $this->now->format('Y-m-d H:i:s') . "] cron-retention: log zkrácen na posledních "
                . round(strlen($tail) / 1048576, 1) . " MB\n";
            ftruncate($fh, 0);
            rewind($fh);
            fwrite($fh, $marker . $tail);
            fflush($fh);
            flock($fh, LOCK_UN);
        } finally {
            fclose($fh);
        }
        clearstatcache(true, $path);
        $this->freedBytes += max(0, $size - (int) filesize($path));

        return true;
    }

    private function sweepTemporary(): void
    {
        if ($this->policy->tmpHours <= 0) {
            return;
        }
        $limit = $this->now->getTimestamp() - $this->policy->tmpHours * 3600;

        $tmp = [];
        $tmpDir = $this->storageDir . '/tmp';
        if (is_dir($tmpDir)) {
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($tmpDir, FilesystemIterator::SKIP_DOTS),
            );
            foreach ($it as $entry) {
                /** @var SplFileInfo $entry */
                if ($entry->isFile() && !$entry->isLink() && $entry->getMTime() < $limit) {
                    $tmp[] = $entry->getPathname();
                }
            }
        }
        $this->deleteAll('tmp', $tmp);

        // Support balíčky maže jinak až stavba dalšího balíčku.
        $this->deleteAll('support', $this->staleTopLevel($this->storageDir . '/support', $limit, 'zip'));

        // mPDF si temp uklízí sám, ale jen když se zrovna nějaké PDF renderuje.
        // Podadresáře (cache fontů) nechává být a my také.
        $this->deleteAll('mpdf_temp', array_merge(
            $this->staleTopLevel($this->storageDir . '/cache/mpdf', $limit, null),
            $this->staleTopLevel($this->storageDir . '/mpdf-temp', $limit, null),
        ));
    }

    /**
     * Zkompilované šablony starší než limit pryč; ta, kterou ještě něco používá,
     * se při dalším renderu zkompiluje znovu. Prázdný adresář se maže jen tehdy,
     * když je sám starší než limit: Twig ho zakládá těsně před zápisem šablony
     * a čerstvý adresář by mu tak mohl zmizet pod rukama.
     */
    private function sweepTwigCache(): void
    {
        $dir = $this->storageDir . '/cache/twig';
        if ($this->policy->twigCacheDays <= 0 || !is_dir($dir)) {
            return;
        }
        $limit = $this->now->getTimestamp() - $this->policy->twigCacheDays * 86400;

        $stale = [];
        $dirs = [];
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $entry) {
            /** @var SplFileInfo $entry */
            if ($entry->isLink()) {
                continue;
            }
            if ($entry->isDir()) {
                $dirs[] = $entry;
            } elseif ($entry->getMTime() < $limit && strtolower($entry->getExtension()) === 'php') {
                $stale[] = $entry->getPathname();
            }
        }
        $this->deleteAll('twig_cache', $stale);

        if ($this->dryRun) {
            return;
        }
        foreach ($dirs as $entry) {
            $path = $entry->getPathname();
            clearstatcache(true, $path);
            if ($entry->getMTime() < $limit && (new FilesystemIterator($path))->valid() === false) {
                @rmdir($path);
            }
        }
    }

    /** @return list<string> */
    private function staleTopLevel(string $dir, int $limit, ?string $extension): array
    {
        if (!is_dir($dir)) {
            return [];
        }
        $stale = [];
        foreach (new FilesystemIterator($dir, FilesystemIterator::SKIP_DOTS) as $entry) {
            /** @var SplFileInfo $entry */
            if (!$entry->isFile() || $entry->isLink() || $entry->getMTime() >= $limit) {
                continue;
            }
            if ($extension !== null && strtolower($entry->getExtension()) !== $extension) {
                continue;
            }
            $stale[] = $entry->getPathname();
        }

        return $stale;
    }

    /** @param list<string> $paths */
    private function deleteAll(string $key, array $paths): void
    {
        $deleted = 0;
        foreach ($paths as $path) {
            $size = (int) @filesize($path);
            if ($this->dryRun || @unlink($path)) {
                $deleted++;
                $this->freedBytes += $size;
                $this->deleted[] = $path;
            } else {
                $this->failed[] = $path;
            }
        }
        $this->report[$key] = ($this->report[$key] ?? 0) + $deleted;
    }
}
