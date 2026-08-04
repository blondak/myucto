<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

final class PayrollModuleStateRepository
{
    public function __construct(private readonly Connection $db) {}

    /**
     * @return array{
     *   supplier_id:int,status:string,start_period:?string,row_version:int,
     *   activated_at:?string,suspended_at:?string,created_at:?string,updated_at:?string
     * }
     */
    public function get(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT supplier_id, status, start_period, row_version, activated_at,
                    suspended_at, created_at, updated_at
               FROM payroll_module_state
              WHERE supplier_id = ?'
        );
        $stmt->execute([$supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            return [
                'supplier_id' => $supplierId,
                'status' => 'disabled',
                'start_period' => null,
                'row_version' => 0,
                'activated_at' => null,
                'suspended_at' => null,
                'created_at' => null,
                'updated_at' => null,
            ];
        }

        return $this->cast($row);
    }

    /**
     * @return array{
     *   supplier_id:int,status:string,start_period:?string,row_version:int,
     *   activated_at:?string,suspended_at:?string,created_at:?string,updated_at:?string
     * }
     */
    public function setActivation(
        int $supplierId,
        bool $enabled,
        ?string $startPeriod,
        int $expectedVersion,
        ?int $userId,
    ): array {
        $pdo = $this->db->pdo();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        try {
            $select = $pdo->prepare(
                'SELECT status, start_period, row_version
                   FROM payroll_module_state
                  WHERE supplier_id = ?
                  FOR UPDATE'
            );
            $select->execute([$supplierId]);
            $current = $select->fetch(PDO::FETCH_ASSOC);
            $currentVersion = is_array($current) ? (int) $current['row_version'] : 0;
            if ($currentVersion !== $expectedVersion) {
                throw new PayrollStateConflictException($currentVersion);
            }
            if (!$enabled && is_array($current) && in_array($current['status'], ['active', 'suspended'], true)) {
                throw new PayrollStateLockedException();
            }

            if (!is_array($current)) {
                $insert = $pdo->prepare(
                    'INSERT INTO payroll_module_state
                        (supplier_id, status, start_period, row_version, activated_by, activated_at)
                     VALUES (?, ?, ?, 1, ?, ?)'
                );
                $insert->execute([
                    $supplierId,
                    $enabled ? 'setup' : 'disabled',
                    $enabled ? $startPeriod : null,
                    $enabled ? $userId : null,
                    $enabled ? date('Y-m-d H:i:s') : null,
                ]);
            } else {
                $update = $pdo->prepare(
                    'UPDATE payroll_module_state
                        SET status = ?,
                            start_period = ?,
                            row_version = row_version + 1,
                            activated_by = CASE WHEN ? = 1 THEN ? ELSE activated_by END,
                            activated_at = CASE WHEN ? = 1 THEN COALESCE(activated_at, NOW()) ELSE activated_at END
                      WHERE supplier_id = ? AND row_version = ?'
                );
                $update->execute([
                    $enabled ? 'setup' : 'disabled',
                    $enabled ? $startPeriod : null,
                    $enabled ? 1 : 0,
                    $userId,
                    $enabled ? 1 : 0,
                    $supplierId,
                    $expectedVersion,
                ]);
                if ($update->rowCount() !== 1) {
                    throw new PayrollStateConflictException($expectedVersion);
                }
            }

            if ($ownsTransaction) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return $this->get($supplierId);
    }

    /** @param array<string,mixed> $row */
    private function cast(array $row): array
    {
        return [
            'supplier_id' => (int) $row['supplier_id'],
            'status' => (string) $row['status'],
            'start_period' => $row['start_period'] === null ? null : substr((string) $row['start_period'], 0, 7),
            'row_version' => (int) $row['row_version'],
            'activated_at' => $row['activated_at'] === null ? null : (string) $row['activated_at'],
            'suspended_at' => $row['suspended_at'] === null ? null : (string) $row['suspended_at'],
            'created_at' => $row['created_at'] === null ? null : (string) $row['created_at'],
            'updated_at' => $row['updated_at'] === null ? null : (string) $row['updated_at'],
        ];
    }
}
