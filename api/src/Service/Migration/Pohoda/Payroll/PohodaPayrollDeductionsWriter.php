<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Pohoda\Payroll;

use MyInvoice\Repository\PohodaImportRepository;
use MyInvoice\Service\Migration\MoneyS3\ImportProtocol;
use MyInvoice\Service\Payroll\Migration\PayrollTakeoverDeductionsWriter;

/**
 * Zápis srážek, exekucí a insolvencí z PAMICA ({@see PohodaPayrollDeductions}).
 *
 * Zápis je společný pro převody mezd ({@see PayrollTakeoverDeductionsWriter}); tady se
 * k němu přidávají jen pravidla a texty PAMICA ({@see PohodaPayrollTakeover::policy()})
 * a mapa převodu POHODA, která nese idempotenci srážek
 * ({@see PohodaImportRepository::KIND_PAYROLL_DEDUCTION}).
 */
final class PohodaPayrollDeductionsWriter
{
    public function __construct(
        private readonly PayrollTakeoverDeductionsWriter $writer,
        private readonly PohodaImportRepository $map,
    ) {}

    /**
     * @param array{deductions:list<array<string,mixed>>,protected_amount_inputs:int} $result
     */
    public function write(int $supplierId, ?int $userId, array $result, int $year, ImportProtocol $protocol, string $step, ?int $runId = null): void
    {
        $this->writer->write($supplierId, $userId, $result, $year, $protocol, $step, PohodaPayrollTakeover::policy(), new PohodaPayrollDeductionMap($this->map), $runId);
    }
}
