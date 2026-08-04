<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Ruleset;

enum PayrollRulesetLifecycle: string
{
    case Draft = 'draft';
    case Reviewed = 'reviewed';
    case Approved = 'approved';
    case Active = 'active';
    case Superseded = 'superseded';

    public function canTransitionTo(self $next): bool
    {
        return match ($this) {
            self::Draft => $next === self::Reviewed,
            self::Reviewed => $next === self::Approved,
            self::Approved => $next === self::Active,
            self::Active => $next === self::Superseded,
            self::Superseded => false,
        };
    }
}
