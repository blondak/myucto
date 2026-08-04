<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Payment;

final readonly class PayrollPaymentReconciliationCommand
{
    public function __construct(
        public int $supplierId,
        public int $allocationId,
        public int $amountMinor,
        public PayrollPaymentEvidenceReference $evidence,
        public string $idempotencyKey,
        public ?int $matchedBy,
    ) {}
}
