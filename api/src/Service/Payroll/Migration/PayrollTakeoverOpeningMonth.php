<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Migration;

/**
 * Úhrny jednoho zpracovaného měsíce pro počáteční stavy ročních kumulací, v haléřích.
 *
 * Zdravotní pojištění je nepovinné: zdroj, který ho po měsících nenese, nechá `null`
 * a řádek ho vůbec neobsahuje (kumulace zdravotního pojištění pak zůstane bez
 * počátečního stavu, jako dosud u PAMICA).
 */
final readonly class PayrollTakeoverOpeningMonth
{
    public function __construct(
        public int $month,
        public int $socialBase,
        public int $advanceBase,
        public int $advanceTax,
        public int $withholdingBase,
        public int $withholdingTax,
        public int $nonRefundableCredits,
        public int $childCredit,
        public int $taxBonus,
        public ?int $healthBase = null,
        public ?int $healthEmployee = null,
        public ?int $healthEmployer = null,
        public ?int $healthTopUp = null,
    ) {}

    /**
     * Řádek pro {@see \MyInvoice\Service\Payroll\PayrollOpeningBalanceService::save()}.
     * Pořadí klíčů je stabilní (zdravotní pojištění hned za sociálním), protože se
     * z něj skládá uložený JSON.
     *
     * @return array<string,int>
     */
    public function toRow(): array
    {
        $row = [
            'month' => $this->month,
            'social_assessment_base_minor_units' => $this->socialBase,
        ];
        if ($this->healthBase !== null) {
            $row['health_assessment_base_minor_units'] = $this->healthBase;
            $row['health_employee_contribution_minor_units'] = (int) $this->healthEmployee;
            $row['health_employer_contribution_minor_units'] = (int) $this->healthEmployer;
            $row['health_minimum_top_up_minor_units'] = (int) $this->healthTopUp;
        }
        return $row + [
            'advance_base_minor_units' => $this->advanceBase,
            'advance_tax_minor_units' => $this->advanceTax,
            'withholding_base_minor_units' => $this->withholdingBase,
            'withholding_tax_minor_units' => $this->withholdingTax,
            'applied_non_refundable_credits_minor_units' => $this->nonRefundableCredits,
            'applied_child_credit_minor_units' => $this->childCredit,
            'tax_bonus_minor_units' => $this->taxBonus,
            'bonus_qualifying_income_minor_units' => $this->advanceBase,
        ];
    }
}
