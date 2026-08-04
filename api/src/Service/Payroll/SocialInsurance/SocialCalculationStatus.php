<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\SocialInsurance;

enum SocialCalculationStatus: string
{
    case Calculated = 'calculated';
    case ManualReview = 'manual_review';
}
