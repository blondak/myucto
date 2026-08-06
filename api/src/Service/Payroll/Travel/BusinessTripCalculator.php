<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Travel;

use MyInvoice\Service\Payroll\Calculation\CalculationStep;
use MyInvoice\Service\Payroll\Calculation\DecimalRate;
use MyInvoice\Service\Payroll\Calculation\PayrollRounding;
use MyInvoice\Service\Payroll\Calculation\RoundingMode;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetDomain;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetException;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetProvider;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetVersion;

/**
 * Deterministický výpočet tuzemských cestovních náhrad (zákoník práce § 156 a násl.).
 *
 * Všechny peněžní hodnoty jsou celé haléře, sazby jdou přes DecimalRate a každý
 * krok se sazbou je zaznamenaný jako CalculationStep. Chybějící účinná sazba,
 * zahraniční cesta nebo neznámá zaokrouhlovací politika končí fail-closed
 * výsledkem `manual_review`, nikdy odhadem.
 */
final class BusinessTripCalculator
{
    private const ROUNDING_POLICY = 'ceil-to-1-czk';

    public function __construct(private readonly PayrollRulesetProvider $rulesets) {}

    public function calculate(BusinessTrip $trip): BusinessTripCalculation
    {
        if (!$trip->isDomestic()) {
            return BusinessTripCalculation::blocked([
                'foreign_business_trip_not_supported',
            ]);
        }

        $blockers = [];
        $rulesetIds = [];
        $steps = [];

        try {
            $departureRuleset = $this->ruleset($trip->departureAt->format('Y-m-d'));
            $this->assertRoundingPolicy($departureRuleset);
        } catch (PayrollRulesetException $e) {
            return BusinessTripCalculation::blocked([$e->getMessage()]);
        }

        $segments = self::daySegments($trip);
        $mealDays = $this->mealDays($trip, $segments, $rulesetIds, $steps, $blockers);
        if ($blockers !== []) {
            return BusinessTripCalculation::blocked($blockers);
        }

        $items = $this->items($trip, $rulesetIds, $steps, $blockers);
        if ($blockers !== []) {
            return BusinessTripCalculation::blocked($blockers);
        }

        $entitlement = 0;
        $exempt = 0;
        foreach ([$mealDays, $items] as $group) {
            foreach ($group as $row) {
                $entitlement += (int) $row['entitlement_minor'];
                $exempt += (int) $row['exempt_minor'];
            }
        }

        $entitlementTotal = PayrollRounding::ceilToCzk($entitlement);
        $exemptTotal = PayrollRounding::ceilToCzk($exempt);
        $taxableTotal = $entitlementTotal - $exemptTotal;

        $ids = array_values(array_unique($rulesetIds));
        sort($ids, SORT_STRING);

        return new BusinessTripCalculation(
            BusinessTripCalculation::STATUS_SUPPORTED,
            [],
            $ids,
            $mealDays,
            $items,
            $entitlementTotal,
            $exemptTotal,
            $taxableTotal,
            $trip->advanceMinor,
            $entitlementTotal - $trip->advanceMinor,
            $steps,
        );
    }

    /**
     * Kalendářní dny pracovní cesty a počet minut, které do nich spadají.
     *
     * @return array<string,int>
     */
    public static function daySegments(BusinessTrip $trip): array
    {
        $segments = [];
        $cursor = $trip->departureAt;
        while ($cursor < $trip->arrivalAt) {
            $nextMidnight = $cursor->modify('+1 day')->setTime(0, 0);
            $end = $nextMidnight < $trip->arrivalAt ? $nextMidnight : $trip->arrivalAt;
            $segments[$cursor->format('Y-m-d')] =
                intdiv($end->getTimestamp() - $cursor->getTimestamp(), 60);
            $cursor = $end;
        }

        return $segments;
    }

    /**
     * @param array<string,int> $segments
     * @param list<string> $rulesetIds
     * @param list<CalculationStep> $steps
     * @param list<string> $blockers
     * @return list<array<string,mixed>>
     */
    private function mealDays(
        BusinessTrip $trip,
        array $segments,
        array &$rulesetIds,
        array &$steps,
        array &$blockers,
    ): array {
        $separate = [];
        $separateSteps = [];
        $separateEntitlement = 0;
        foreach ($segments as $date => $minutes) {
            try {
                $ruleset = $this->ruleset($date);
            } catch (PayrollRulesetException $e) {
                $blockers[] = $e->getMessage();
                return [];
            }
            $rulesetIds[] = $ruleset->id;
            try {
                $row = $this->mealDay(
                    $trip,
                    $ruleset,
                    $date,
                    $minutes,
                    $trip->freeMealCount($date),
                    $separateSteps,
                );
            } catch (PayrollRulesetException|\DomainException $e) {
                $blockers[] = $e->getMessage();
                return [];
            }
            $separate[] = $row;
            $separateEntitlement += (int) $row['entitlement_minor'];
        }

        // § 163 odst. 3 ZP — cesta spadající do 2 kalendářních dnů se posuzuje
        // jako jedna, je-li to pro zaměstnance výhodnější.
        if (count($segments) === 2) {
            $dates = array_keys($segments);
            $mergedSteps = [];
            try {
                $ruleset = $this->ruleset($dates[0]);
                $merged = $this->mealDay(
                    $trip,
                    $ruleset,
                    $dates[0],
                    array_sum($segments),
                    array_sum(array_map(
                        static fn (string $date): int => $trip->freeMealCount($date),
                        $dates,
                    )),
                    $mergedSteps,
                );
            } catch (PayrollRulesetException|\DomainException $e) {
                $blockers[] = $e->getMessage();
                return [];
            }
            if ((int) $merged['entitlement_minor'] > $separateEntitlement) {
                $merged['merged_dates'] = $dates;
                $merged['rule'] = 'two-calendar-day-merge';
                foreach ($mergedSteps as $step) {
                    $steps[] = $step;
                }
                return [$merged];
            }
        }

        foreach ($separateSteps as $step) {
            $steps[] = $step;
        }

        return $separate;
    }

    /**
     * @param list<CalculationStep> $steps
     * @return array<string,mixed>
     */
    private function mealDay(
        BusinessTrip $trip,
        PayrollRulesetVersion $ruleset,
        string $date,
        int $minutes,
        int $freeMeals,
        array &$steps,
    ): array {
        $band = $this->band($ruleset, $minutes);
        if ($band === 0) {
            return [
                'kind' => 'meal_allowance',
                'date' => $date,
                'minutes' => $minutes,
                'band' => 0,
                'free_meals' => $freeMeals,
                'base_rate_minor' => 0,
                'statutory_minimum_minor' => 0,
                'tax_exempt_maximum_minor' => 0,
                'entitlement_minor' => 0,
                'exempt_minor' => 0,
                'taxable_minor' => 0,
                'note' => 'below_minimum_duration',
                'ruleset_id' => $ruleset->id,
            ];
        }

        $minimum = $this->money($ruleset, "meal_allowance.band_{$band}.minimum");
        $ceiling = $this->money($ruleset, "meal_allowance.band_{$band}.tax_exempt_maximum");
        $base = $trip->mealRateMinor($band) ?? $minimum;
        if ($base < $minimum) {
            throw new \DomainException(
                "Sazba stravného pásma {$band} je nižší než zákonné minimum podle § 163 odst. 1 ZP.",
            );
        }

        $reductionRate = DecimalRate::fromString(
            (string) $ruleset->parameter("meal_allowance.band_{$band}.free_meal_reduction_rate")->value,
        );
        $entitlement = $base;
        $limit = $ceiling;
        for ($meal = 1; $meal <= $freeMeals; $meal++) {
            $step = CalculationStep::calculate(
                "travel.meal_allowance.{$date}.free_meal_{$meal}.reduction",
                $base,
                $reductionRate,
                RoundingMode::HalfUp,
            );
            $steps[] = $step;
            $entitlement -= $step->outputMinorUnits;
            $limitStep = CalculationStep::calculate(
                "travel.meal_allowance.{$date}.free_meal_{$meal}.limit_reduction",
                $ceiling,
                $reductionRate,
                RoundingMode::HalfUp,
            );
            $steps[] = $limitStep;
            $limit -= $limitStep->outputMinorUnits;
        }
        $entitlement = max(0, $entitlement);
        $limit = max(0, min($entitlement, $limit));

        return [
            'kind' => 'meal_allowance',
            'date' => $date,
            'minutes' => $minutes,
            'band' => $band,
            'free_meals' => $freeMeals,
            'base_rate_minor' => $base,
            'statutory_minimum_minor' => $minimum,
            'tax_exempt_maximum_minor' => $ceiling,
            'entitlement_minor' => $entitlement,
            'exempt_minor' => $limit,
            'taxable_minor' => $entitlement - $limit,
            'ruleset_id' => $ruleset->id,
        ];
    }

    /**
     * @param list<string> $rulesetIds
     * @param list<CalculationStep> $steps
     * @param list<string> $blockers
     * @return list<array<string,mixed>>
     */
    private function items(
        BusinessTrip $trip,
        array &$rulesetIds,
        array &$steps,
        array &$blockers,
    ): array {
        $rows = [];
        foreach ($trip->items as $index => $item) {
            if ($item->kind !== TravelExpenseItemKind::PRIVATE_VEHICLE) {
                $amount = (int) $item->amountMinor;
                $rows[] = [
                    'kind' => $item->kind->value,
                    'item_id' => $item->id,
                    'date' => $item->spentOn,
                    'description' => $item->description,
                    'documented' => $item->documented,
                    'entitlement_minor' => $amount,
                    'exempt_minor' => $item->documented ? $amount : 0,
                    'taxable_minor' => $item->documented ? 0 : $amount,
                    'ruleset_id' => null,
                ];
                continue;
            }

            try {
                $ruleset = $this->ruleset($item->spentOn);
                $this->assertRoundingPolicy($ruleset);
                $rows[] = $this->privateVehicleItem($ruleset, $item, $index, $steps);
                $rulesetIds[] = $ruleset->id;
            } catch (PayrollRulesetException $e) {
                $blockers[] = $e->getMessage();
                return [];
            }
        }

        return $rows;
    }

    /**
     * Náhrada jízdních výdajů za soukromé vozidlo = základní náhrada za km
     * + náhrada za spotřebované pohonné hmoty (§ 157 odst. 3 a § 158 ZP).
     *
     * @param list<CalculationStep> $steps
     * @return array<string,mixed>
     */
    private function privateVehicleItem(
        PayrollRulesetVersion $ruleset,
        TravelExpenseItem $item,
        int $index,
        array &$steps,
    ): array {
        $vehicleKind = $item->vehicleKind ?? throw new PayrollRulesetException(
            'Jízda soukromým vozidlem nemá určený druh vozidla.',
        );
        $fuelKind = $item->fuelKind ?? throw new PayrollRulesetException(
            'Jízda soukromým vozidlem nemá určený druh paliva.',
        );
        $distance = (int) $item->distanceMetres;
        $consumption = (int) $item->consumptionMlPer100Km;

        $perKm = $this->money($ruleset, $vehicleKind->basicCompensationParameter());
        $basicStep = CalculationStep::calculate(
            "travel.vehicle.{$index}.basic_compensation",
            $distance,
            self::perThousandRate($perKm),
            RoundingMode::HalfUp,
        );
        $steps[] = $basicStep;

        $volumeStep = CalculationStep::calculate(
            "travel.vehicle.{$index}.fuel_volume",
            $distance,
            DecimalRate::fromString(self::decimal($consumption, 5)),
            RoundingMode::HalfUp,
        );
        $steps[] = $volumeStep;

        $priceMinor = $item->documentedFuelPriceMinor
            ?? $this->money($ruleset, $fuelKind->averagePriceParameter());
        $fuelStep = CalculationStep::calculate(
            "travel.vehicle.{$index}.fuel_cost",
            $volumeStep->outputMinorUnits,
            self::perThousandRate($priceMinor),
            RoundingMode::HalfUp,
        );
        $steps[] = $fuelStep;

        $entitlement = $basicStep->outputMinorUnits + $fuelStep->outputMinorUnits;

        return [
            'kind' => TravelExpenseItemKind::PRIVATE_VEHICLE->value,
            'item_id' => $item->id,
            'date' => $item->spentOn,
            'description' => $item->description,
            'documented' => $item->documented,
            'vehicle_kind' => $vehicleKind->value,
            'fuel_kind' => $fuelKind->value,
            'distance_m' => $distance,
            'consumption_ml_per_100km' => $consumption,
            'basic_compensation_per_km_minor' => $perKm,
            'basic_compensation_minor' => $basicStep->outputMinorUnits,
            'fuel_volume_ml' => $volumeStep->outputMinorUnits,
            'fuel_price_per_unit_minor' => $priceMinor,
            'fuel_price_documented' => $item->documentedFuelPriceMinor !== null,
            'fuel_cost_minor' => $fuelStep->outputMinorUnits,
            'entitlement_minor' => $entitlement,
            'exempt_minor' => $entitlement,
            'taxable_minor' => 0,
            'ruleset_id' => $ruleset->id,
        ];
    }

    private function band(PayrollRulesetVersion $ruleset, int $minutes): int
    {
        if ($minutes < $this->integer($ruleset, 'meal_allowance.from_minutes')) {
            return 0;
        }
        if ($minutes <= $this->integer($ruleset, 'meal_allowance.band_1.to_minutes')) {
            return 1;
        }
        if ($minutes <= $this->integer($ruleset, 'meal_allowance.band_2.to_minutes')) {
            return 2;
        }

        return 3;
    }

    private function ruleset(string $date): PayrollRulesetVersion
    {
        return $this->rulesets->forDate(PayrollRulesetDomain::TravelAllowances, $date);
    }

    private function assertRoundingPolicy(PayrollRulesetVersion $ruleset): void
    {
        $policy = $ruleset->parameter('rounding.entitlement')->value;
        if ($policy !== self::ROUNDING_POLICY) {
            throw new PayrollRulesetException(
                "Zaokrouhlovací politika cestovních náhrad {$policy} není implementovaná.",
            );
        }
    }

    private function money(PayrollRulesetVersion $ruleset, string $key): int
    {
        $value = $ruleset->parameter($key)->value;
        if (!is_int($value)) {
            throw new PayrollRulesetException("Parametr rulesetu {$key} není částka v haléřích.");
        }

        return $value;
    }

    private function integer(PayrollRulesetVersion $ruleset, string $key): int
    {
        $value = $ruleset->parameter($key)->value;
        if (!is_int($value)) {
            throw new PayrollRulesetException("Parametr rulesetu {$key} není celé číslo.");
        }

        return $value;
    }

    /** Sazba „za jednotku" přepočtená na tisícinu (metry → km, mililitry → litry). */
    private static function perThousandRate(int $perUnitMinor): DecimalRate
    {
        return DecimalRate::fromString(self::decimal($perUnitMinor, 3));
    }

    private static function decimal(int $value, int $scale): string
    {
        $divisor = 10 ** $scale;

        return sprintf('%d.%0' . $scale . 'd', intdiv($value, $divisor), $value % $divisor);
    }
}
