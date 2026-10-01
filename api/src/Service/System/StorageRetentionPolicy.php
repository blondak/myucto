<?php

declare(strict_types=1);

namespace MyInvoice\Service\System;

use MyInvoice\Infrastructure\Config\Config;

/**
 * Limity úklidu `cron-retention`: kolik záloh, logů a dočasných souborů držet.
 *
 * Výchozí hodnoty míří na spravovaný provoz: zálohy si tam dělá hosting a naše
 * kopie je jen rychlý rollback po vlastní chybě (migrace, hromadná operace).
 * Na self-hostu se úloha zapíná vědomě (`cron.retention.enabled`) a limity si
 * provozovatel může v cfg zvednout.
 *
 * ⚠️ Nula (nebo záporné číslo) znamená „tuhle kategorii NEUKLÍZET", ne „nedrž
 * nic". U záloh by opačný význam smazal všechno a {@see \MyInvoice\Service\Backup\BackupRetentionPolicy}
 * má nulu obráceně a tady se to plést nesmí.
 */
final class StorageRetentionPolicy
{
    public const DEFAULTS = [
        // Dumpy databáze: posledních 48 h všechny (při 4×/den 8 kusů), starší do
        // 7 dnů jen poslední dump každého dne. Celkem kolem 14 souborů místo 28.
        'db_days'           => 7,
        'db_keep_all_hours' => 48,
        // PDF, Dokumenty a Mzdy jsou denní PLNÉ snímky, ne přírůstky, každý
        // soubor obsahuje všechno. Stačí poslední tři od každého druhu.
        'snapshot_copies'   => 3,
        // Aplikační a cron logy podle stáří; log, který se nerotuje po dnech
        // (Docker wrapper píše do jednoho souboru), se nad limit zkrátí na konec.
        'log_days'          => 14,
        'log_max_mb'        => 20,
        // Pozůstatky dočasných souborů (storage/tmp, rozdělané dumpy, support
        // balíčky, mPDF temp). Běžně je po sobě uklidí operace sama.
        'tmp_hours'         => 48,
        // Zkompilované Twig šablony PDF. Cache je obsahově adresovaná, po každé
        // aktualizaci v ní zůstanou verze, které už nic nenačte. Smazaná šablona
        // se při dalším renderu jen znovu zkompiluje.
        'twig_cache_days'   => 30,
    ];

    private function __construct(
        public readonly int $dbDays,
        public readonly int $dbKeepAllHours,
        public readonly int $snapshotCopies,
        public readonly int $logDays,
        public readonly int $logMaxMb,
        public readonly int $tmpHours,
        public readonly int $twigCacheDays,
    ) {}

    /** @param array<string,mixed> $values */
    public static function fromArray(array $values): self
    {
        $v = static function (string $key) use ($values): int {
            $raw = $values[$key] ?? null;
            return is_numeric($raw) ? max(0, (int) $raw) : self::DEFAULTS[$key];
        };

        return new self(
            $v('db_days'),
            $v('db_keep_all_hours'),
            $v('snapshot_copies'),
            $v('log_days'),
            $v('log_max_mb'),
            $v('tmp_hours'),
            $v('twig_cache_days'),
        );
    }

    public static function fromConfig(Config $config): self
    {
        $raw = $config->get('cron.retention', []);

        return self::fromArray(is_array($raw) ? $raw : []);
    }

    /** Věta do logu běhu, ať je vidět, co se skutečně uplatnilo. */
    public function describe(): string
    {
        $off = static fn (int $n, string $unit): string => $n > 0 ? $n . ' ' . $unit : 'neuklízí se';

        return sprintf(
            'DB %s (posledních %s vše), PDF/Dokumenty/Mzdy %s, logy %s (max %s), dočasné %s, Twig cache %s',
            $off($this->dbDays, 'dnů'),
            $off($this->dbKeepAllHours, 'h'),
            $off($this->snapshotCopies, 'ks'),
            $off($this->logDays, 'dnů'),
            $off($this->logMaxMb, 'MB'),
            $off($this->tmpHours, 'h'),
            $off($this->twigCacheDays, 'dnů'),
        );
    }
}
