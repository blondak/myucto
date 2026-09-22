<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Pohoda\Payroll;

use MyInvoice\Repository\PohodaImportRepository;
use MyInvoice\Service\Payroll\Migration\PayrollTakeoverDeductionMap;

/** Srážky převodu z PAMICA v mapě převodu POHODA ({@see PohodaImportRepository::KIND_PAYROLL_DEDUCTION}). */
final class PohodaPayrollDeductionMap implements PayrollTakeoverDeductionMap
{
    public function __construct(private readonly PohodaImportRepository $map) {}

    public function all(int $supplierId): array
    {
        return $this->map->all($supplierId, PohodaImportRepository::KIND_PAYROLL_DEDUCTION);
    }

    public function put(int $supplierId, string $reference, int $targetId, ?int $runId): void
    {
        $this->map->put($supplierId, PohodaImportRepository::KIND_PAYROLL_DEDUCTION, $reference, $targetId, $runId);
    }
}
