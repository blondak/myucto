<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Payment;

final readonly class PayrollPaymentEvidenceReference
{
    private function __construct(
        public string $kind,
        public ?int $bankStatementId,
        public ?int $bankTransactionId,
        public ?int $cashDocumentId,
    ) {}

    public static function bank(
        int $bankStatementId,
        int $bankTransactionId,
    ): self {
        return new self(
            'bank',
            $bankStatementId,
            $bankTransactionId,
            null,
        );
    }

    public static function cash(int $cashDocumentId): self
    {
        return new self('cash', null, null, $cashDocumentId);
    }
}
