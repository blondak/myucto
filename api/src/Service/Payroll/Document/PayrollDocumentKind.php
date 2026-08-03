<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Document;

enum PayrollDocumentKind: string
{
    case Payslip = 'payslip';
    case PayrollSheet = 'payroll_sheet';
    case TaxableIncomeCertificate = 'taxable_income_certificate';
    case EmploymentCertificate = 'employment_certificate';
    case AverageEarningsCertificate = 'average_earnings_certificate';
    case MonthlyBundle = 'monthly_bundle';
}
