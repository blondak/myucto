<?php

declare(strict_types=1);

namespace MyInvoice\Service\System;

use DateTimeImmutable;

/**
 * Jedno měření spotřeby místa instance (H-10) — přesně to, co je v řádku
 * `instance_storage_usage`.
 *
 * ⚠️ VŠECHNY hodnoty jsou nullable ZÁMĚRNĚ. `null` znamená „neměřeno", ne nula.
 * Kdyby se tady kdekoli objevil `(int)` cast nebo `?? 0`, rozdíl mezi prázdnou
 * a nezměřenou instancí by zmizel — a to je přesně ta záměna, kvůli které by
 * se instalace buď zamkla bez důvodu, nebo naopak tvrdila „0 %, vše v pořádku"
 * o instanci, o které nevíme nic.
 *
 * `backupBytes` se do `usageBytes` NEZAPOČÍTÁVÁ. Hosting počítá živá data jako
 * „soubory bez adresáře záloh + databáze" a my musíme počítat stejně; jinak si
 * hlásíme dvě různá čísla a ani jedno neplatí.
 */
final class StorageUsageSnapshot
{
    public function __construct(
        public readonly ?DateTimeImmutable $measuredAt = null,
        public readonly ?int $databaseBytes = null,
        public readonly ?int $filesBytes = null,
        public readonly ?int $usageBytes = null,
        public readonly ?int $backupBytes = null,
        public readonly ?int $fileCount = null,
        public readonly ?int $durationMs = null,
        public readonly bool $truncated = false,
        /** @var array<string,int> rozpad po adresářích prvního patra */
        public readonly array $breakdown = [],
    ) {}

    /**
     * Stav „ještě se neměřilo". Vrací se i tehdy, když je databáze nedostupná
     * nebo tabulka po migraci ještě neexistuje — v obou případech opravdu nic
     * nevíme, takže se to nesmí tvářit jako nula.
     */
    public static function unmeasured(): self
    {
        return new self();
    }

    /**
     * Načtení z databázového řádku.
     *
     * @param array<string,mixed>|null $row
     */
    public static function fromRow(?array $row): self
    {
        if ($row === null) {
            return self::unmeasured();
        }

        $measuredAt = self::nullableDate($row['measured_at'] ?? null);
        $usage      = self::nullableInt($row['usage_bytes'] ?? null);

        // Bez času měření nebo bez čísla je řádek jen prázdný seed z migrace.
        // Půlka údajů není měření a nesmí projít dál jako by bylo.
        if ($measuredAt === null || $usage === null) {
            return self::unmeasured();
        }

        $breakdown = [];
        $raw = $row['breakdown'] ?? null;
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                foreach ($decoded as $key => $value) {
                    if (is_string($key) && (is_int($value) || is_float($value))) {
                        $breakdown[$key] = (int) $value;
                    }
                }
            }
        }

        return new self(
            measuredAt:    $measuredAt,
            databaseBytes: self::nullableInt($row['database_bytes'] ?? null),
            filesBytes:    self::nullableInt($row['files_bytes'] ?? null),
            usageBytes:    $usage,
            backupBytes:   self::nullableInt($row['backup_bytes'] ?? null),
            fileCount:     self::nullableInt($row['file_count'] ?? null),
            durationMs:    self::nullableInt($row['duration_ms'] ?? null),
            truncated:     (int) ($row['truncated'] ?? 0) === 1,
            breakdown:     $breakdown,
        );
    }

    /** Proběhlo měření? Jediná legální otázka na „máme čím počítat". */
    public function isMeasured(): bool
    {
        return $this->measuredAt !== null && $this->usageBytes !== null;
    }

    /** Stáří měření v sekundách, nebo null, když se neměřilo. */
    public function ageSec(?DateTimeImmutable $now = null): ?int
    {
        if ($this->measuredAt === null) {
            return null;
        }

        $now ??= new DateTimeImmutable('now');

        return max(0, $now->getTimestamp() - $this->measuredAt->getTimestamp());
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'measured'       => $this->isMeasured(),
            'measured_at'    => $this->measuredAt?->format(\DateTimeInterface::ATOM),
            'database_bytes' => $this->databaseBytes,
            'files_bytes'    => $this->filesBytes,
            'usage_bytes'    => $this->usageBytes,
            'backup_bytes'   => $this->backupBytes,
            'file_count'     => $this->fileCount,
            'duration_ms'    => $this->durationMs,
            'truncated'      => $this->truncated,
        ];
    }

    private static function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '' || $value === false) {
            return null;
        }
        if (is_int($value)) {
            return $value;
        }
        if (is_float($value)) {
            return (int) $value;
        }
        if (is_string($value) && preg_match('/^\d+$/', $value) === 1) {
            return (int) $value;
        }

        return null;
    }

    private static function nullableDate(mixed $value): ?DateTimeImmutable
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }
        // '0000-00-00 00:00:00' ze staré instalace není datum, je to prázdno.
        if (str_starts_with($value, '0000-')) {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }
}
