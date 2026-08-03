<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Garnishment;

enum GarnishmentStatus: string
{
    case Supported = 'supported';
    case ManualReview = 'manual_review';
}
