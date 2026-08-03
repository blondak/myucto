<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Security;

enum PayrollSensitiveField: string
{
    case PERSONAL_IDENTIFIER = 'personal_identifier';
    case FOREIGN_TAX_IDENTIFIER = 'foreign_tax_identifier';
    case BANK_ACCOUNT = 'bank_account';
}
