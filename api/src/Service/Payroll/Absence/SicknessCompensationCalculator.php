<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Absence;

use InvalidArgumentException;
use MyInvoice\Service\Payroll\Calculation\RoundingMode;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetProvider;

/**
 * Provider je povinná závislost: jako volitelný class-param by ho PHP-DI
 * nevyplnil a kalkulátor by za běhu tiše četl default z kódu místo rulesetu
 * účinného podle administrace.
 */
final class SicknessCompensationCalculator
{
    public function __construct(private readonly PayrollRulesetProvider $rulesets) {}

    /**
     * @param list<array{shift_id:?int,local_date:string,planned_minutes:int,eligible_minutes:int}> $segments
     */
    public function calculate(
        string $date,
        int $averageHourlyMinor,
        array $segments,
    ): SicknessCompensationResult {
        if ($averageHourlyMinor <= 0) {
            throw new InvalidArgumentException('DPN náhrada vyžaduje kladný hodinový průměr.');
        }
        if ($segments === []) {
            throw new InvalidArgumentException('DPN náhrada vyžaduje alespoň jednu publikovanou směnu.');
        }

        $rules = AbsenceRuleset::forDate($this->rulesets, $date);
        $boundary1 = $rules->hourlyBoundaryMinor(1);
        $boundary2 = $rules->hourlyBoundaryMinor(2);
        $boundary3 = $rules->hourlyBoundaryMinor(3);
        $bandRate1 = $rules->reductionBandBasisPoints(1);
        $bandRate2 = $rules->reductionBandBasisPoints(2);
        $bandRate3 = $rules->reductionBandBasisPoints(3);
        $compensationRate = $rules->compensationRateBasisPoints();

        $band1 = min($averageHourlyMinor, $boundary1);
        $band2 = min(max($averageHourlyMinor - $boundary1, 0), $boundary2 - $boundary1);
        $band3 = min(max($averageHourlyMinor - $boundary2, 0), $boundary3 - $boundary2);
        $reducedHourlyMinor = RoundingMode::HalfUp->roundFraction(
            $band1 * $bandRate1 + $band2 * $bandRate2 + $band3 * $bandRate3,
            10_000,
        );

        $calculatedSegments = [];
        $totalMinor = 0;
        foreach ($segments as $segment) {
            $planned = $segment['planned_minutes'];
            $eligible = $segment['eligible_minutes'];
            if ($planned <= 0 || $eligible <= 0 || $eligible > $planned) {
                throw new InvalidArgumentException('Minuty DPN směny nejsou platné.');
            }
            $amountMinor = RoundingMode::HalfUp->roundFraction(
                $reducedHourlyMinor * $compensationRate * $eligible,
                10_000 * 60,
            );
            $totalMinor += $amountMinor;
            $calculatedSegments[] = [
                'shift_id' => $segment['shift_id'],
                'local_date' => $segment['local_date'],
                'planned_minutes' => $planned,
                'eligible_minutes' => $eligible,
                'hourly_average_minor' => $averageHourlyMinor,
                'reduced_hourly_minor' => $reducedHourlyMinor,
                'compensation_minor' => $amountMinor,
                'rounding' => 'half-up-to-minor-unit',
            ];
        }

        return new SicknessCompensationResult(
            $reducedHourlyMinor,
            $totalMinor,
            'manual_review',
            $rules->version->id,
            $rules->version->canonicalHash,
            $calculatedSegments,
            [
                'average_hourly_minor' => $averageHourlyMinor,
                'hourly_boundary_1_minor' => $boundary1,
                'hourly_boundary_2_minor' => $boundary2,
                'hourly_boundary_3_minor' => $boundary3,
                'band_1_basis_points' => $bandRate1,
                'band_2_basis_points' => $bandRate2,
                'band_3_basis_points' => $bandRate3,
                'compensation_basis_points' => $compensationRate,
                'window_calendar_days' => $rules->sicknessWindowCalendarDays(),
                'segment_count' => count($calculatedSegments),
                'compensation_minor' => $totalMinor,
                'support_status' => 'manual_review',
            ],
        );
    }
}
