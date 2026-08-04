<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Ruleset;

enum PayrollRulesetCapability: string
{
    case Supported = 'supported';
    case ManualReview = 'manual_review';
}
