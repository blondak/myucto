<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Component;

/**
 * Rozpad jednoho benefitního plnění na osvobozenou a nadlimitní část.
 *
 * `usedBeforeMinor` je úhrn koše PŘED tímhle plněním, ne po něm — pořadí čerpání
 * je dané pořadím schválení a rozpad se proto zmrazuje, ne dopočítává.
 */
final readonly class PayrollBenefitBasketSplit implements \JsonSerializable
{
    public function __construct(
        public PayrollBenefitExemptionBasket $basket,
        public int $limitMinor,
        public int $usedBeforeMinor,
        public int $amountMinor,
        public int $exemptMinor,
        public int $taxableMinor,
    ) {}

    public function usedAfterMinor(): int
    {
        return $this->usedBeforeMinor + $this->amountMinor;
    }

    public function remainingMinor(): int
    {
        return max(0, $this->limitMinor - $this->usedAfterMinor());
    }

    public function exceedsLimit(): bool
    {
        return $this->taxableMinor > 0;
    }

    /** @return array<string,int|string|bool> */
    public function jsonSerialize(): array
    {
        return [
            'basket' => $this->basket->value,
            'statute' => $this->basket->statute(),
            'limit_minor' => $this->limitMinor,
            'used_before_minor' => $this->usedBeforeMinor,
            'used_after_minor' => $this->usedAfterMinor(),
            'remaining_minor' => $this->remainingMinor(),
            'exempt_minor' => $this->exemptMinor,
            'taxable_minor' => $this->taxableMinor,
            'limit_exceeded' => $this->exceedsLimit(),
        ];
    }
}
