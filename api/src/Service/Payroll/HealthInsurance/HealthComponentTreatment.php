<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\HealthInsurance;

enum HealthComponentTreatment: string
{
    case Included = 'included';
    case Excluded = 'excluded';
    case ManualReview = 'manual_review';
}
