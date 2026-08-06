<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Ruleset;

enum PayrollRulesetDomain: string
{
    case IncomeTax = 'income_tax';
    case SocialInsurance = 'social_insurance';
    case HealthInsurance = 'health_insurance';
    case EmploymentThresholds = 'employment_thresholds';
    case CompensationAverages = 'compensation_averages';
    case TravelAllowances = 'travel_allowances';
    case EnforcementDeductions = 'enforcement_deductions';
    case Deadlines = 'deadlines';
    case Codebooks = 'codebooks';
    case Submissions = 'submissions';
}
