<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\SocialInsurance;

enum SocialEmploymentKind: string
{
    case Employment = 'employment';
    case Dpc = 'dpc';
    case Dpp = 'dpp';
    case CorporateBody = 'corporate_body';
}
