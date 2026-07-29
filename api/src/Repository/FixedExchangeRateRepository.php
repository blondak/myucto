<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Repository pevných kurzů (§24/7 ZoÚ — Fáze F). Per firma × měna × rok × měsíc
 * (month=0 = roční pevný kurz platný pro celý rok, 1..12 = měsíční). UNIQUE
 * (supplier_id, currency_code, fiscal_year, month).
 */
final class FixedExchangeRateRepository
{
    public function __construct(private readonly Connection $db) {}

    /**
     * Pevný kurz pro dané období, nebo NULL. `month` 0 = roční, 1..12 = měsíční.
     *
     * @return array{rate: float, source: string}|null
     */
    public function find(int $supplierId, string $currencyCode, int $fiscalYear, int $month): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT rate, source FROM accounting_fixed_exchange_rates
              WHERE supplier_id = ? AND currency_code = ? AND fiscal_year = ? AND month = ?'
        );
        $stmt->execute([$supplierId, strtoupper($currencyCode), $fiscalYear, $month]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        return ['rate' => (float) $row['rate'], 'source' => (string) $row['source']];
    }

    /**
     * Seznam pevných kurzů firmy (volitelně filtr na rok) — pro nastavení.
     *
     * @return list<array<string,mixed>>
     */
    public function list(int $supplierId, ?int $fiscalYear = null): array
    {
        $sql = 'SELECT id, currency_code, fiscal_year, month, rate, source, updated_at
                  FROM accounting_fixed_exchange_rates
                 WHERE supplier_id = ?';
        $params = [$supplierId];
        if ($fiscalYear !== null) {
            $sql .= ' AND fiscal_year = ?';
            $params[] = $fiscalYear;
        }
        $sql .= ' ORDER BY fiscal_year DESC, currency_code, month';
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        return array_map(static function (array $r): array {
            return [
                'id'            => (int) $r['id'],
                'currency_code' => (string) $r['currency_code'],
                'fiscal_year'   => (int) $r['fiscal_year'],
                'month'         => (int) $r['month'],
                'rate'          => (float) $r['rate'],
                'source'        => (string) $r['source'],
                'updated_at'    => (string) $r['updated_at'],
            ];
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function upsert(int $supplierId, string $currencyCode, int $fiscalYear, int $month, float $rate, string $source): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO accounting_fixed_exchange_rates
                (supplier_id, currency_code, fiscal_year, month, rate, source)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE rate = VALUES(rate), source = VALUES(source)'
        )->execute([$supplierId, strtoupper($currencyCode), $fiscalYear, $month, $rate, $source]);
    }

    public function delete(int $supplierId, int $id): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'DELETE FROM accounting_fixed_exchange_rates WHERE id = ? AND supplier_id = ?'
        );
        $stmt->execute([$id, $supplierId]);
        return $stmt->rowCount() > 0;
    }
}
