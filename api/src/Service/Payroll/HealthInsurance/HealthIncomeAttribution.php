<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\HealthInsurance;

enum HealthIncomeAttribution: string
{
    case CurrentEmploymentMonth = 'current_employment_month';
    case PostTerminationEndMonthVerified = 'post_termination_end_month_verified';
    case PostTerminationPaymentMonthVerified = 'post_termination_payment_month_verified';
    case Unverified = 'unverified';
}
