<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Document;

enum PayrollDocumentKind: string
{
    case Payslip = 'payslip';
    case PayrollSheet = 'payroll_sheet';
    case TaxableIncomeAdvanceCertificate = 'taxable_income_advance_certificate';
    case TaxableIncomeWithholdingCertificate = 'taxable_income_withholding_certificate';
    case EmploymentCertificate = 'employment_certificate';
    case AverageEarningsCertificate = 'average_earnings_certificate';
    /** § 38ch ZDP — výpočet daně a roční zúčtování záloh a daňového zvýhodnění. */
    case AnnualSettlementResult = 'annual_settlement_result';
    case MonthlyBundle = 'monthly_bundle';
}
