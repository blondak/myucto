<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\IncomeTax;

enum EmploymentRelationshipKind: string
{
    case Employment = 'employment';
    case SmallScaleEmployment = 'small-scale-employment';
    case Dpp = 'dpp';
    case Dpc = 'dpc';
    case ManagingPartnerDependent = 'managing-partner-dependent';
    case StatutoryBody = 'statutory-body';
}
