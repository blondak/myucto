<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\ControlTotals;

use JsonSerializable;

final readonly class PayrollControlTotals implements JsonSerializable
{
    /**
     * @param list<array{
     *   employee_id:int,
     *   employment_id:int,
     *   office_id:int,
     *   totals:array<string,int>
     * }> $relationships
     * @param list<array{
     *   employee_id:int,
     *   totals:array<string,int>
     * }> $people
     * @param list<array{
     *   office_id:int,
     *   totals:array<string,int>
     * }> $offices
     * @param array<string,int> $company
     * @param list<array{
     *   liability_kind:string,
     *   direction:string,
     *   amount_minor:int
     * }> $liabilities
     * @param list<array{
     *   debit_code:string,
     *   credit_code:string,
     *   amount_minor:int
     * }> $accountingDimensions
     */
    public function __construct(
        public int $supplierId,
        public int $revisionId,
        public string $sourceResultHash,
        public array $relationships,
        public array $people,
        public array $offices,
        public array $company,
        public array $liabilities,
        public array $accountingDimensions,
        public string $controlHash,
    ) {}

    /** @return array<string,mixed> */
    public function jsonSerialize(): array
    {
        return [
            'schema_version' => 'payroll-control-totals.v1',
            'supplier_id' => $this->supplierId,
            'revision_id' => $this->revisionId,
            'source_result_hash' => $this->sourceResultHash,
            'relationships' => $this->relationships,
            'people' => $this->people,
            'offices' => $this->offices,
            'company' => $this->company,
            'liabilities' => $this->liabilities,
            'accounting_dimensions' => $this->accountingDimensions,
            'control_hash' => $this->controlHash,
        ];
    }
}
