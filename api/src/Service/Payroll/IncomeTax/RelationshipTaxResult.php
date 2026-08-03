<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\IncomeTax;

use JsonSerializable;

final readonly class RelationshipTaxResult implements JsonSerializable
{
    public function __construct(
        public string $relationshipReference,
        public EmploymentRelationshipKind $kind,
        public int $taxableBaseMinorUnits,
        public TaxRegime $regime,
        public ?string $withholdingGroup,
    ) {}

    /** @return array<string,mixed> */
    public function jsonSerialize(): array
    {
        return [
            'relationship_reference' => $this->relationshipReference,
            'kind' => $this->kind->value,
            'taxable_base_minor_units' => $this->taxableBaseMinorUnits,
            'regime' => $this->regime->value,
            'withholding_group' => $this->withholdingGroup,
        ];
    }
}
