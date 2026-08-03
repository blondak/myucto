<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\IncomeTax;

use InvalidArgumentException;

final readonly class TaxResidenceEvidence
{
    public function __construct(
        public TaxResidence $residence,
        public ?string $effectiveFrom = null,
        public ?string $effectiveTo = null,
        public ?string $evidenceReference = null,
    ) {
        if (
            $residence !== TaxResidence::Unverified
            && trim((string) $evidenceReference) === ''
        ) {
            throw new InvalidArgumentException(
                'Verified tax residence requires an evidence reference.',
            );
        }
        if ($residence !== TaxResidence::Unverified && $effectiveFrom === null) {
            throw new InvalidArgumentException(
                'Verified tax residence requires an effective interval.',
            );
        }
        if ($effectiveFrom !== null) {
            EvidenceInterval::assertValid(
                $effectiveFrom,
                $effectiveTo,
                $residence === TaxResidence::Unverified
                    ? TaxEvidenceStatus::Unverified
                    : TaxEvidenceStatus::Verified,
                $evidenceReference,
            );
        }
    }

    public function isEffective(string $calculationDate): bool
    {
        return $this->effectiveFrom !== null
            && EvidenceInterval::includesMonthStart(
                $this->effectiveFrom,
                $this->effectiveTo,
                $calculationDate,
            );
    }
}
