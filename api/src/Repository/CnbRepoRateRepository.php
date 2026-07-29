<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use DateTimeImmutable;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Penalty\RepoRateProvider;
use PDO;

/**
 * Číselník 2týdenní repo sazby ČNB (globální, admin editovatelný).
 *
 * Rozhodná pro zákonný úrok z prodlení dle NV č. 351/2013 Sb.: bere se sazba
 * platná k 1. dni kalendářního pololetí. Lookup {@see rateOn} vrací poslední
 * sazbu s valid_from <= dotazované datum.
 */
final class CnbRepoRateRepository implements RepoRateProvider
{
    public function __construct(private readonly Connection $db) {}

    /** @return list<array{valid_from:string, rate:float, note:?string, updated_at:string}> */
    public function list(): array
    {
        $stmt = $this->db->pdo()->query(
            'SELECT valid_from, rate, note, updated_at FROM cnb_repo_rates ORDER BY valid_from DESC'
        );
        return array_map(static fn (array $r): array => [
            'valid_from' => (string) $r['valid_from'],
            'rate'       => (float) $r['rate'],
            'note'       => $r['note'] !== null ? (string) $r['note'] : null,
            'updated_at' => (string) $r['updated_at'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Repo sazba platná k danému dni (poslední valid_from <= $date). NULL když
     * pro dané datum není nastavena žádná sazba (starší než nejstarší záznam).
     */
    public function rateOn(DateTimeImmutable $date): ?float
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT rate FROM cnb_repo_rates WHERE valid_from <= ? ORDER BY valid_from DESC LIMIT 1'
        );
        $stmt->execute([$date->format('Y-m-d')]);
        $rate = $stmt->fetchColumn();
        return $rate === false ? null : (float) $rate;
    }

    public function upsert(string $validFrom, float $rate, ?string $note): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO cnb_repo_rates (valid_from, rate, note)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE rate = VALUES(rate), note = VALUES(note)'
        )->execute([$validFrom, $rate, $note]);
    }

    public function delete(string $validFrom): bool
    {
        $stmt = $this->db->pdo()->prepare('DELETE FROM cnb_repo_rates WHERE valid_from = ?');
        $stmt->execute([$validFrom]);
        return $stmt->rowCount() > 0;
    }
}
