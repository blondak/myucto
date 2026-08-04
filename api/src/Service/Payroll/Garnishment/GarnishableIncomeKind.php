<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Garnishment;

enum GarnishableIncomeKind: string
{
    case Wage = 'wage';
    case AgreementRemuneration = 'agreement_remuneration';
    case StandbyRemuneration = 'standby_remuneration';
    case WageCompensation = 'wage_compensation';
    case SicknessBenefit = 'sickness_benefit';
    case MaternityBenefit = 'maternity_benefit';
    case Pension = 'pension';
    case Severance = 'severance';
    case LoyaltyBenefit = 'loyalty_benefit';
    case TravelReimbursement = 'travel_reimbursement';
    case Unknown = 'unknown';

    public function isGarnishable(): ?bool
    {
        return match ($this) {
            self::TravelReimbursement => false,
            self::Unknown => null,
            default => true,
        };
    }
}
