<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll;

use MyInvoice\Infrastructure\Database\Connection;

final class PayrollModuleAccess
{
    public function __construct(private readonly Connection $db) {}

    public function isEnabled(int $supplierId): bool
    {
        // Chybějící sloupec = modul vypnutý. Mzdy jsou opt-in (migrace 1290), takže
        // se neotevírají ani schématu, které o přepínači ještě neví — shodně
        // s PayrollPaymentIdentifierResolver::payrollEnabled().
        if (!$this->db->hasColumn('supplier', 'payroll_enabled')) {
            return false;
        }

        $stmt = $this->db->pdo()->prepare(
            'SELECT payroll_enabled FROM supplier WHERE id = ?'
        );
        $stmt->execute([$supplierId]);
        $enabled = $stmt->fetchColumn();

        return $enabled !== false && (int) $enabled === 1;
    }
}
