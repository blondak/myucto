<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll;

enum InstitutionAccountType: string
{
    case SOCIAL_SECURITY = 'social_security';
    case TAX_OFFICE = 'tax_office';
    case HEALTH_INSURER = 'health_insurer';
    case STATUTORY_INSURANCE = 'statutory_insurance';
    case OTHER_RECIPIENT = 'other_recipient';
}
