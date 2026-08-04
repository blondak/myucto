<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\HealthInsurance;

use UnexpectedValueException;

final class HealthRelationshipKindMapper
{
    public function fromDatabaseRelationType(string $relationType): HealthEmploymentKind
    {
        return match ($relationType) {
            'employment', 'small_scale_employment' => HealthEmploymentKind::Employment,
            'dpp' => HealthEmploymentKind::Dpp,
            'dpc' => HealthEmploymentKind::Dpc,
            'partner_dependent', 'statutory_body' => HealthEmploymentKind::CorporateBody,
            default => throw new UnexpectedValueException(
                "Unsupported payroll relation type {$relationType}.",
            ),
        };
    }
}
