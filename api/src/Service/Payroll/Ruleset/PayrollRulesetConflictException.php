<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Ruleset;

use RuntimeException;

final class PayrollRulesetConflictException extends RuntimeException
{
    public function __construct(public readonly int $currentVersion)
    {
        parent::__construct(
            'Verze rulesetu byla mezitím změněna jiným uživatelem.',
        );
    }
}
