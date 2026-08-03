<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\IncomeTax;

final readonly class TaxDeclarationEvidence
{
    public function __construct(
        public TaxDeclarationStatus $status,
        public string $effectiveFrom,
        public ?string $effectiveTo = null,
        public ?string $evidenceReference = null,
    ) {
        EvidenceInterval::assertValid(
            $effectiveFrom,
            $effectiveTo,
            $status === TaxDeclarationStatus::Unverified
                ? TaxEvidenceStatus::Unverified
                : TaxEvidenceStatus::Verified,
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
