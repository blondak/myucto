<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Výjimky mapování účtů do výkazů pro konkrétní firmu (`statement_account_overrides`).
 *
 * Každý dotaz je vázaný na `supplier_id` — výjimka jedné firmy se do výkazu jiné firmy
 * nesmí dostat ani omylem (multi-tenant izolace). Validaci obsahu dělá
 * {@see \MyInvoice\Service\Accounting\Reports\StatementOverrideService}, tady se jen čte
 * a zapisuje.
 */
final class StatementOverrideRepository
{
    public function __construct(private readonly Connection $db) {}

    /**
     * Výjimky firmy pro verzi výkazu ve tvaru {@see StatementDefinitionRepository::accountMap()},
     * aby se daly slít s globální mapou.
     *
     * @return list<array<string,mixed>>
     */
    public function forVersion(int $supplierId, int $versionId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, version_id, account_prefix, row_code, target, follows_prefix, balance_condition, sign, note,
                    valid_from_year, valid_to_year, created_by, created_at, updated_at
               FROM statement_account_overrides
              WHERE supplier_id = ? AND version_id = ?
              ORDER BY account_prefix, balance_condition, valid_from_year'
        );
        $stmt->execute([$supplierId, $versionId]);

        return array_map(self::normalize(...), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return array<string,mixed>|null */
    public function find(int $supplierId, int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, version_id, account_prefix, row_code, target, follows_prefix, balance_condition, sign, note,
                    valid_from_year, valid_to_year, created_by, created_at, updated_at
               FROM statement_account_overrides
              WHERE supplier_id = ? AND id = ?'
        );
        $stmt->execute([$supplierId, $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : self::normalize($row);
    }

    /**
     * @param array{account_prefix:string,row_code:string,target:string,balance_condition:string,sign:int,note:?string,valid_from_year?:?int,valid_to_year?:?int,follows_prefix?:?string} $data
     */
    public function create(int $supplierId, int $versionId, array $data, ?int $userId): int
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO statement_account_overrides
                (supplier_id, version_id, account_prefix, row_code, target, follows_prefix, balance_condition, sign, note,
                 valid_from_year, valid_to_year, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $supplierId,
            $versionId,
            $data['account_prefix'],
            $data['row_code'],
            $data['target'],
            $data['follows_prefix'] ?? null,
            $data['balance_condition'],
            $data['sign'],
            $data['note'],
            $data['valid_from_year'] ?? null,
            $data['valid_to_year'] ?? null,
            $userId,
        ]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    /**
     * @param array{account_prefix:string,row_code:string,target:string,balance_condition:string,sign:int,note:?string,valid_from_year?:?int,valid_to_year?:?int,follows_prefix?:?string} $data
     */
    public function update(int $supplierId, int $id, array $data): void
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE statement_account_overrides
                SET account_prefix = ?, row_code = ?, target = ?, follows_prefix = ?, balance_condition = ?, sign = ?, note = ?,
                    valid_from_year = ?, valid_to_year = ?
              WHERE supplier_id = ? AND id = ?'
        );
        $stmt->execute([
            $data['account_prefix'],
            $data['row_code'],
            $data['target'],
            $data['follows_prefix'] ?? null,
            $data['balance_condition'],
            $data['sign'],
            $data['note'],
            $data['valid_from_year'] ?? null,
            $data['valid_to_year'] ?? null,
            $supplierId,
            $id,
        ]);
    }

    public function delete(int $supplierId, int $id): void
    {
        $stmt = $this->db->pdo()->prepare('DELETE FROM statement_account_overrides WHERE supplier_id = ? AND id = ?');
        $stmt->execute([$supplierId, $id]);
    }

    /**
     * Nahradí celou sadu výjimek firmy pro verzi výkazu (jedno společné Uložit editoru).
     * Běží v transakci, takže se neuloží polovina sady; když volající transakci už drží,
     * připojí se k ní.
     *
     * @param list<array{account_prefix:string,row_code:string,target:string,balance_condition:string,sign:int,note:?string}> $rows
     */
    public function replaceForVersion(int $supplierId, int $versionId, array $rows, ?int $userId): void
    {
        $pdo = $this->db->pdo();
        $own = !$pdo->inTransaction();
        if ($own) {
            $pdo->beginTransaction();
        }
        try {
            $pdo->prepare('DELETE FROM statement_account_overrides WHERE supplier_id = ? AND version_id = ?')
                ->execute([$supplierId, $versionId]);
            foreach ($rows as $row) {
                $this->create($supplierId, $versionId, $row, $userId);
            }
            if ($own) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($own && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function normalize(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['version_id'] = (int) $row['version_id'];
        $row['account_prefix'] = (string) $row['account_prefix'];
        $row['row_code'] = (string) $row['row_code'];
        $row['sign'] = (int) $row['sign'];
        $row['valid_from_year'] = $row['valid_from_year'] === null ? null : (int) $row['valid_from_year'];
        $row['valid_to_year'] = $row['valid_to_year'] === null ? null : (int) $row['valid_to_year'];
        $row['created_by'] = $row['created_by'] === null ? null : (int) $row['created_by'];

        return $row;
    }
}
