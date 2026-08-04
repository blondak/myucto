<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\SocialInsurance;

enum SocialIncomeAttribution: string
{
    case CurrentEmploymentMonth = 'current_employment_month';
    case PostTerminationEndMonthVerified = 'post_termination_end_month_verified';
    case Unverified = 'unverified';
}
