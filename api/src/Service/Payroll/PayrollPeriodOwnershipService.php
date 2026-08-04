<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use PDOException;

final class PayrollPeriodOwnershipService
{
    public function __construct(private readonly Connection $db) {}

    public function claimLegacy(
        int $supplierId,
        int $year,
        int $month,
        int $sourceId,
        ?int $userId,
    ): void {
        $this->claim($supplierId, $year, $month, 'legacy', 'accounting_payroll', $sourceId, $userId);
    }

    public function claimPayroll(
        int $supplierId,
        int $year,
        int $month,
        string $sourceType,
        ?int $sourceId,
        ?int $userId,
    ): void {
        $this->claim($supplierId, $year, $month, 'payroll', $sourceType, $sourceId, $userId);
    }

    private function claim(
        int $supplierId,
        int $year,
        int $month,
        string $processor,
        string $sourceType,
        ?int $sourceId,
        ?int $userId,
    ): void {
        if (!checkdate($month, 1, $year)) {
            throw new \InvalidArgumentException('Neplatné mzdové období.');
        }
        $period = sprintf('%04d-%02d-01', $year, $month);
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO payroll_period_ownership
                (supplier_id, period_start, processor, source_type, source_id, claimed_by)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                source_type = IF(processor = VALUES(processor), VALUES(source_type), source_type),
                source_id = IF(processor = VALUES(processor), VALUES(source_id), source_id),
                claimed_by = IF(processor = VALUES(processor), VALUES(claimed_by), claimed_by),
                updated_at = IF(processor = VALUES(processor), CURRENT_TIMESTAMP, updated_at)'
        );
        try {
            $stmt->execute([$supplierId, $period, $processor, $sourceType, $sourceId, $userId]);
        } catch (PDOException $e) {
            throw new PayrollPeriodOwnedException('Mzdové období nelze rezervovat.', previous: $e);
        }

        $check = $this->db->pdo()->prepare(
            'SELECT processor FROM payroll_period_ownership WHERE supplier_id = ? AND period_start = ?'
        );
        $check->execute([$supplierId, $period]);
        $owner = $check->fetchColumn();
        if ($owner !== $processor) {
            throw new PayrollPeriodOwnedException(sprintf(
                'Období %04d-%02d už zpracovává %s mzdový modul.',
                $year,
                $month,
                $owner === 'legacy' ? 'původní' : 'nový',
            ));
        }
    }
}
