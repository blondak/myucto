<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\IncomeTax;

use UnexpectedValueException;

final class EmploymentRelationshipKindMapper
{
    public function fromDatabaseRelationType(string $relationType): EmploymentRelationshipKind
    {
        return match ($relationType) {
            'employment' => EmploymentRelationshipKind::Employment,
            'small_scale_employment' => EmploymentRelationshipKind::SmallScaleEmployment,
            'dpp' => EmploymentRelationshipKind::Dpp,
            'dpc' => EmploymentRelationshipKind::Dpc,
            'partner_dependent' => EmploymentRelationshipKind::ManagingPartnerDependent,
            'statutory_body' => EmploymentRelationshipKind::StatutoryBody,
            default => throw new UnexpectedValueException(
                "Unsupported payroll relation type {$relationType}.",
            ),
        };
    }
}
