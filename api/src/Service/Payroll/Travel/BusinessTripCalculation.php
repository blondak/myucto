<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Travel;

use JsonSerializable;
use MyInvoice\Service\Payroll\Calculation\CalculationStep;

/**
 * Výsledek výpočtu cestovních náhrad včetně rozpadu na nezdaňovanou část do
 * zákonného limitu a nadlimitní část, která vstupuje do mzdy jako zdanitelný
 * příjem i do vyměřovacích základů.
 */
final readonly class BusinessTripCalculation implements JsonSerializable
{
    public const STATUS_SUPPORTED = 'supported';
    public const STATUS_MANUAL_REVIEW = 'manual_review';

    /**
     * @param list<string> $blockers
     * @param list<string> $rulesetIds
     * @param list<array<string,mixed>> $mealDays
     * @param list<array<string,mixed>> $items
     * @param list<CalculationStep> $steps
     */
    public function __construct(
        public string $status,
        public array $blockers,
        public array $rulesetIds,
        public array $mealDays,
        public array $items,
        public int $entitlementTotalMinor,
        public int $exemptTotalMinor,
        public int $taxableTotalMinor,
        public int $advanceMinor,
        public int $settlementDifferenceMinor,
        public array $steps,
    ) {}

    /** @param list<string> $blockers */
    public static function blocked(array $blockers): self
    {
        return new self(
            self::STATUS_MANUAL_REVIEW,
            $blockers,
            [],
            [],
            [],
            0,
            0,
            0,
            0,
            0,
            [],
        );
    }

    public function isSupported(): bool
    {
        return $this->status === self::STATUS_SUPPORTED;
    }

    /** @return array<string,mixed> */
    public function jsonSerialize(): array
    {
        return [
            'advance_minor' => $this->advanceMinor,
            'blockers' => $this->blockers,
            'entitlement_total_minor' => $this->entitlementTotalMinor,
            'exempt_total_minor' => $this->exemptTotalMinor,
            'items' => $this->items,
            'meal_days' => $this->mealDays,
            'ruleset_ids' => $this->rulesetIds,
            'settlement_difference_minor' => $this->settlementDifferenceMinor,
            'status' => $this->status,
            'steps' => array_map(
                static fn (CalculationStep $step): array => $step->jsonSerialize(),
                $this->steps,
            ),
            'taxable_total_minor' => $this->taxableTotalMinor,
        ];
    }
}
