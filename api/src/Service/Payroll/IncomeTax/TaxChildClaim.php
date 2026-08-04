<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\IncomeTax;

use InvalidArgumentException;

final readonly class TaxChildClaim
{
    public function __construct(
        public string $childReference,
        public int $order,
        public bool $ztpP,
        public string $effectiveFrom,
        public ?string $effectiveTo,
        public TaxEvidenceStatus $evidenceStatus,
        public bool $sharedHouseholdConfirmed,
        public bool $otherClaimantExcluded,
        public ?string $evidenceReference = null,
    ) {
        if (trim($childReference) === '') {
            throw new InvalidArgumentException('Tax child reference must not be empty.');
        }
        if ($order < 1) {
            throw new InvalidArgumentException('Tax child order must be positive.');
        }
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
