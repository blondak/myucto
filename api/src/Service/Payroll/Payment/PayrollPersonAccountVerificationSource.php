<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Payment;

enum PayrollPersonAccountVerificationSource: string
{
    case EmployeeConfirmation = 'employee_confirmation';
    case BankDocument = 'bank_document';
    case UserVerified = 'user_verified';
}
