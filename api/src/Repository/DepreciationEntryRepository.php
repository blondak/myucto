<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Repository pro depreciation_entries — uplatněné/zaúčtované odpisy rok × druh
 * (Epic F3). Materializuje se JEN skutečnost (R11), unikát (asset_id, kind,
 * fiscal_year) drží idempotenci upsertu.
 */
final class DepreciationEntryRepository
{
    public function __construct(private readonly Connection $db) {}

    /**
     * Všechny řádky karty (tvar pro DepreciationContext::confirmedEntries).
     *
     * @return list<array<string,mixed>>
     */
    public function forAsset(int $assetId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, supplier_id, asset_id, kind, fiscal_year, amount, full_amount,
                    residual_value_end, is_paused, is_half, months_count, detail, status,
                    created_at, updated_at
               FROM depreciation_entries
              WHERE asset_id = ?
              ORDER BY kind, fiscal_year'
        );
        $stmt->execute([$assetId]);
        return array_map(fn ($r) => $this->cast($r), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function find(int $supplierId, int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, supplier_id, asset_id, kind, fiscal_year, amount, full_amount,
                    residual_value_end, is_paused, is_half, months_count, detail, status,
                    created_at, updated_at
               FROM depreciation_entries
              WHERE supplier_id = ? AND id = ?'
        );
        $stmt->execute([$supplierId, $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->cast($row);
    }

    public function findYear(int $assetId, string $kind, int $fiscalYear): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, supplier_id, asset_id, kind, fiscal_year, amount, full_amount,
                    residual_value_end, is_paused, is_half, months_count, detail, status,
                    created_at, updated_at
               FROM depreciation_entries
              WHERE asset_id = ? AND kind = ? AND fiscal_year = ?'
        );
        $stmt->execute([$assetId, $kind, $fiscalYear]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->cast($row);
    }

    /**
     * INSERT … ON DUPLICATE KEY UPDATE přes uq_de_asset_kind_year — idempotentní
     * re-book přepisuje in-place (R12). Vrací id řádku (existující při update).
     *
     * @param array{
     *     supplier_id:int, asset_id:int, kind:'tax'|'accounting', fiscal_year:int,
     *     amount:float, full_amount:float, residual_value_end:float,
     *     is_paused?:bool|int, is_half?:bool|int, months_count?:?int, detail?:?string,
     *     status?:'confirmed'|'posted'
     * } $row
     */
    public function upsert(array $row): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO depreciation_entries
                (supplier_id, asset_id, kind, fiscal_year, amount, full_amount,
                 residual_value_end, is_paused, is_half, months_count, detail, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                amount = VALUES(amount),
                full_amount = VALUES(full_amount),
                residual_value_end = VALUES(residual_value_end),
                is_paused = VALUES(is_paused),
                is_half = VALUES(is_half),
                months_count = VALUES(months_count),
                detail = VALUES(detail),
                status = VALUES(status),
                id = LAST_INSERT_ID(id)'
        )->execute([
            (int) $row['supplier_id'],
            (int) $row['asset_id'],
            (string) $row['kind'],
            (int) $row['fiscal_year'],
            round((float) $row['amount'], 2),
            round((float) $row['full_amount'], 2),
            round((float) $row['residual_value_end'], 2),
            (int) (bool) ($row['is_paused'] ?? false),
            (int) (bool) ($row['is_half'] ?? false),
            $row['months_count'] ?? null,
            $row['detail'] ?? null,
            (string) ($row['status'] ?? 'confirmed'),
        ]);
        return (int) $pdo->lastInsertId();
    }

    /** Smaže oba druhy řádku daného roku (revert vyřazení R24). */
    public function deleteYear(int $assetId, int $fiscalYear): void
    {
        $this->db->pdo()->prepare(
            'DELETE FROM depreciation_entries WHERE asset_id = ? AND fiscal_year = ?'
        )->execute([$assetId, $fiscalYear]);
    }

    /** Smaže jeden řádek (kind, rok) — zrušení pauzy §26/8 bez dotčení účetního řádku. */
    public function deleteOne(int $assetId, string $kind, int $fiscalYear): void
    {
        $this->db->pdo()->prepare(
            'DELETE FROM depreciation_entries WHERE asset_id = ? AND kind = ? AND fiscal_year = ?'
        )->execute([$assetId, $kind, $fiscalYear]);
    }

    public function lastConfirmedYear(int $assetId, string $kind): ?int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT MAX(fiscal_year) FROM depreciation_entries WHERE asset_id = ? AND kind = ?'
        );
        $stmt->execute([$assetId, $kind]);
        $max = $stmt->fetchColumn();
        return $max === null || $max === false ? null : (int) $max;
    }

    /** Existuje potvrzený daňový řádek? → zámek tax_* parametrů karty (R13). */
    public function existsAnyTax(int $assetId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT 1 FROM depreciation_entries WHERE asset_id = ? AND kind = 'tax' LIMIT 1"
        );
        $stmt->execute([$assetId]);
        return $stmt->fetchColumn() !== false;
    }

    /**
     * Souhrn roku pro výsledek hromadného zaúčtování (bookYear §3.4).
     *
     * @return array<string, array{count:int, amount:float, full_amount:float}> klíč = kind
     */
    public function yearSummary(int $supplierId, int $fiscalYear): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT kind, COUNT(*) AS cnt, SUM(amount) AS amount_sum, SUM(full_amount) AS full_sum
               FROM depreciation_entries
              WHERE supplier_id = ? AND fiscal_year = ?
              GROUP BY kind'
        );
        $stmt->execute([$supplierId, $fiscalYear]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(string) $r['kind']] = [
                'count' => (int) $r['cnt'],
                'amount' => round((float) $r['amount_sum'], 2),
                'full_amount' => round((float) $r['full_sum'], 2),
            ];
        }
        return $out;
    }

    private function cast(array $r): array
    {
        $r['id'] = (int) $r['id'];
        $r['supplier_id'] = (int) $r['supplier_id'];
        $r['asset_id'] = (int) $r['asset_id'];
        $r['fiscal_year'] = (int) $r['fiscal_year'];
        $r['amount'] = (float) $r['amount'];
        $r['full_amount'] = (float) $r['full_amount'];
        $r['residual_value_end'] = (float) $r['residual_value_end'];
        $r['is_paused'] = (bool) $r['is_paused'];
        $r['is_half'] = (bool) $r['is_half'];
        $r['months_count'] = $r['months_count'] === null ? null : (int) $r['months_count'];
        return $r;
    }
}
