<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\HealthInsurance;

enum HealthEmploymentKind: string
{
    case Employment = 'employment';
    case Dpp = 'dpp';
    case Dpc = 'dpc';
    case CorporateBody = 'corporate_body';
    case FosterReward = 'foster_reward';
}
