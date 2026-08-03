<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\SocialInsurance;

enum SocialComponentTreatment: string
{
    case Included = 'included';
    case Excluded = 'excluded';
    case ManualReview = 'manual_review';
}
