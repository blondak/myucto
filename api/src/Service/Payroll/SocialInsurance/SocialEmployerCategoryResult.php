<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\SocialInsurance;

use JsonSerializable;
use MyInvoice\Service\Payroll\Calculation\CalculationStep;

/**
 * Jeden vyměřovací základ zaměstnavatele podle § 5a odst. 1 a pojistné z něj
 * podle § 7 odst. 1.
 *
 * Zaokrouhluje se PO KATEGORII, ne až ze součtu: § 7 odst. 1 přiřazuje každému
 * ze tří základů vlastní sazbu a § 7 odst. 3 pak zaokrouhluje pojistné nahoru.
 * Že je to takhle a ne jednou za firmu, potvrzují i kontroly ČSSZ nad JMHZ —
 * 10024 = sazba a) ze základu 10023, 10026 = sazba b) ze základu 10025,
 * 10484 = sazba c) ze základu 10483 a teprve 10027 je jejich součet.
 * Zaokrouhlení až ze součtu by u dvou kategorií dalo o korunu jiné číslo,
 * než ČSSZ v podání očekává.
 */
final readonly class SocialEmployerCategoryResult implements JsonSerializable
{
    public function __construct(
        public SocialEmployerRateCategory $category,
        public int $assessmentBaseMinorUnits,
        public int $contributionMinorUnits,
        public CalculationStep $contributionStep,
    ) {}

    /** @return array<string,mixed> */
    public function jsonSerialize(): array
    {
        return [
            'category' => $this->category->value,
            'paragraph5a_letter' => $this->category->paragraph5aLetter(),
            'assessment_base_minor_units' => $this->assessmentBaseMinorUnits,
            'contribution_minor_units' => $this->contributionMinorUnits,
            'contribution_step' => $this->contributionStep->jsonSerialize(),
        ];
    }
}
