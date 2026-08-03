<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\IncomeTax;

use InvalidArgumentException;

final readonly class EmploymentRelationshipTaxInput
{
    /**
     * @param list<IncomeTaxComponent> $components
     */
    public function __construct(
        public string $relationshipReference,
        public string $payerReference,
        public EmploymentRelationshipKind $kind,
        public array $components,
        public OtherWithholdingEligibility $otherWithholdingEligibility = OtherWithholdingEligibility::Automatic,
        public ?string $classificationEvidenceReference = null,
    ) {
        if (trim($relationshipReference) === '') {
            throw new InvalidArgumentException('Employment relationship reference must not be empty.');
        }
        if (trim($payerReference) === '') {
            throw new InvalidArgumentException('Employment payer reference must not be empty.');
        }
        if (
            in_array($otherWithholdingEligibility, [
                OtherWithholdingEligibility::EligibleVerified,
                OtherWithholdingEligibility::IneligibleVerified,
            ], true)
            && trim((string) $classificationEvidenceReference) === ''
        ) {
            throw new InvalidArgumentException(
                'Verified other-withholding eligibility requires a classification evidence reference.',
            );
        }
    }

    public function includedBaseMinorUnits(): int
    {
        $total = 0;
        foreach ($this->components as $component) {
            if ($component->treatment === IncomeTaxComponentTreatment::Included) {
                $total = TaxIntegerMath::add($total, $component->amountMinorUnits);
            }
        }

        return $total;
    }
}
