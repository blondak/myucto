<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Document;

final class EmploymentExitRelationshipPolicy
{
    public static function documentKind(string $relationType): string
    {
        return match ($relationType) {
            'employment', 'small_scale_employment' => 'employment',
            'dpc' => 'dpc',
            'dpp' => 'dpp',
            default => throw new EmploymentExitReadinessException(
                'relationship_kind_not_supported',
                'Druh pracovněprávního vztahu zatím nelze bezpečně vytisknout.',
            ),
        };
    }
}
