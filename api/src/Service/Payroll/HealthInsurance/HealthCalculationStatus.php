<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\HealthInsurance;

enum HealthCalculationStatus: string
{
    case Calculated = 'calculated';
    case ManualReview = 'manual_review';
}
