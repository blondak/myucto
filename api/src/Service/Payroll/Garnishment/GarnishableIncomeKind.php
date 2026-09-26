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
    /**
     * Jeden násobek průměrného výdělku, ze kterého je odvozeno odstupné.
     * § 299 odst. 4 o. s. ř.: „Z odstupného se srážky vypočítávají zvlášť
     * z každého násobku průměrného výdělku" — každý násobek je samostatný
     * měsíční příjem s vlastní nezabavitelnou částkou. Plátce odstupného
     * nemá nárok na paušální náhradu nákladů (§ 301 odst. 2 o. s. ř.).
     */
    case SeveranceMultiple = 'severance_multiple';
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
