<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\IncomeTax;

use InvalidArgumentException;

final readonly class IncomeTaxComponent
{
    public function __construct(
        public string $code,
        public int $amountMinorUnits,
        public IncomeTaxComponentTreatment $treatment = IncomeTaxComponentTreatment::Included,
        public TaxCorrectionTreatment $correctionTreatment = TaxCorrectionTreatment::CurrentMonth,
        public TaxEvidenceStatus $treatmentEvidenceStatus = TaxEvidenceStatus::Unverified,
        public ?string $treatmentEvidenceFrom = null,
        public ?string $treatmentEvidenceTo = null,
        public ?string $treatmentEvidenceReference = null,
    ) {
        if (trim($code) === '') {
            throw new InvalidArgumentException('Income tax component code must not be empty.');
        }
        if ($treatmentEvidenceFrom !== null) {
            EvidenceInterval::assertValid(
                $treatmentEvidenceFrom,
                $treatmentEvidenceTo,
                $treatmentEvidenceStatus,
                $treatmentEvidenceReference,
            );
        }
    }

    public function hasVerifiedTreatmentEvidence(string $calculationDate): bool
    {
        return $this->treatmentEvidenceStatus === TaxEvidenceStatus::Verified
            && $this->treatmentEvidenceFrom !== null
            && trim((string) $this->treatmentEvidenceReference) !== ''
            && EvidenceInterval::includesMonthStart(
                $this->treatmentEvidenceFrom,
                $this->treatmentEvidenceTo,
                $calculationDate,
            );
    }
}
