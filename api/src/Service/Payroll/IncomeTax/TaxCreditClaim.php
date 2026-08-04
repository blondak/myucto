<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\IncomeTax;

final readonly class TaxCreditClaim
{
    public function __construct(
        public TaxCreditKind $kind,
        public string $effectiveFrom,
        public ?string $effectiveTo,
        public TaxEvidenceStatus $evidenceStatus,
        public ?string $evidenceReference = null,
    ) {
        EvidenceInterval::assertValid(
            $effectiveFrom,
            $effectiveTo,
            $evidenceStatus,
            $evidenceReference,
        );
    }

    public function isEffective(string $calculationDate): bool
    {
        return EvidenceInterval::includesMonthStart(
            $this->effectiveFrom,
            $this->effectiveTo,
            $calculationDate,
        );
    }
}
