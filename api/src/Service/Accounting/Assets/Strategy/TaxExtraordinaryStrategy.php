<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Assets\Strategy;

use MyInvoice\Repository\TaxConstantsRepository;
use MyInvoice\Service\Accounting\Assets\DepreciationContext;

/**
 * Mimořádné odpisy §30a ZDP — bezemisní vozidla pořízená 2024–2028 (Epic F3,
 * spec §2.5). 24 měsíců bez přerušení od měsíce následujícího po zařazení;
 * fáze 1 (měs. 1–12) = ceil(60 % VC), fáze 2 = zbytek do 100 % VC. Měsíční
 * částka fáze = ceil(úhrn/12), poslední měsíc fáze dorovnává (R7). Vyřazení:
 * odpisy naposledy za měsíc předcházející měsíci vyřazení, bez půlodpisu (R6).
 * TZ VC nezvyšuje (§30a/5) — improvements se ignorují (validuje AssetService).
 * §30e: bezemisní M1 s VC > 2 mil. → koeficient na měsíční `amount`, Σ cap 2 mil.
 */
final class TaxExtraordinaryStrategy implements TaxDepreciationStrategyInterface
{
    public function __construct(private readonly ?TaxConstantsRepository $constants = null) {}

    public function plan(DepreciationContext $ctx): array
    {
        return $this->buildPlan($ctx, null);
    }

    public function yearRow(DepreciationContext $ctx, int $fiscalYear): ?array
    {
        foreach ($this->buildPlan($ctx, $fiscalYear) as $row) {
            if ($row['fiscal_year'] === $fiscalYear) {
                return $row;
            }
        }
        return null;
    }

    /** @return list<array<string,mixed>> */
    private function buildPlan(DepreciationContext $ctx, ?int $forceComputeYear): array
    {
        if ($ctx->putIntoUseDate === null || $ctx->inputPrice <= 0) {
            return [];
        }
        $acquisitionYear = $ctx->calendar->fiscalYearOfDate($ctx->putIntoUseDate);
        $yearConstants = $this->constants?->forYear($acquisitionYear) ?? [];
        $rules = (array) ($yearConstants['extraordinary_depreciation'] ?? []);
        $totalMonths = (int) ($rules['total_months'] ?? 24);
        $phase1Months = (int) ($rules['phase1_months'] ?? 12);
        $phase1Share = (float) ($rules['phase1_share'] ?? 0.60);
        $vc = $ctx->inputPrice;
        $startIdx = self::monthIndex($ctx->putIntoUseDate) + 1; // od měsíce následujícího po zařazení
        $disposalIdx = $ctx->disposalDate !== null ? self::monthIndex($ctx->disposalDate) : null;

        $phase1 = (float) ceil(round($phase1Share * $vc, 6));
        $phase2 = $vc - $phase1; // Σ obou fází = přesně VC (R7)
        $phase2Months = $totalMonths - $phase1Months;
        if ($totalMonths <= 0 || $phase1Months <= 0 || $phase2Months <= 0) {
            return [];
        }
        $monthly1 = (float) ceil(round($phase1 / $phase1Months, 6));
        $monthly2 = (float) ceil(round($phase2 / $phase2Months, 6));

        // rozvrh 24 měsíců (plné částky), oříznutý o měsíce od vyřazení
        $schedule = [];
        for ($p = 1; $p <= $totalMonths; $p++) {
            $idx = $startIdx + $p - 1;
            if ($disposalIdx !== null && $idx >= $disposalIdx) {
                break; // naposledy měsíc předcházející měsíci vyřazení
            }
            $schedule[$idx] = match (true) {
                $p < $phase1Months => $monthly1,
                $p === $phase1Months => $phase1 - ($phase1Months - 1) * $monthly1,
                $p < $totalMonths => $monthly2,
                default => $phase2 - ($phase2Months - 1) * $monthly2,
            };
        }
        if ($schedule === []) {
            return [];
        }

        $byYear = [];
        foreach ($schedule as $idx => $full) {
            $byYear[$ctx->calendar->fiscalYearOfMonthIndex($idx)][$idx] = $full;
        }
        ksort($byYear);

        $confirmed = [];
        foreach ($ctx->confirmedEntries as $entry) {
            if (($entry['kind'] ?? '') === 'tax') {
                $confirmed[(int) $entry['fiscal_year']] = $entry;
            }
        }

        $y1 = $acquisitionYear;
        $m1Limit = (float) ($yearConstants['m1_depreciation_limit'] ?? 2000000.0);
        $m1Active = $ctx->isM1Vehicle && !$ctx->m1LimitException && $vc > $m1Limit;
        $k = $m1Active ? $m1Limit / $vc : 1.0;

        $rows = [];
        $sumFull = 0.0;
        $sumClaimed = 0.0;

        foreach ($byYear as $y => $monthsFull) {
            $zcStart = $vc - $ctx->openingTaxAmount - $sumFull;

            if ($y < $y1 + $ctx->openingTaxYears) {
                // roky kryté opening stavy (R23) — bez řádku, jen posun čerpání limitu §30e
                foreach ($monthsFull as $full) {
                    $claimed = $m1Active
                        ? min((float) ceil(round($full * $k, 6)), max(0.0, $m1Limit - $sumClaimed))
                        : $full;
                    $sumClaimed += $claimed;
                }
                continue;
            }

            $entry = $confirmed[$y] ?? null;
            if ($forceComputeYear === $y && $entry !== null) {
                $entry = null;
            }

            if ($entry !== null) {
                $full = (float) $entry['full_amount'];
                $amount = (float) $entry['amount'];
                $rows[] = [
                    'fiscal_year' => $y,
                    'amount' => $amount,
                    'full_amount' => $full,
                    'residual_start' => $zcStart,
                    'residual_end' => $zcStart - $full,
                    'is_half' => false,
                    'is_paused' => false,
                    'months_count' => count($monthsFull),
                    'months' => null,
                    'source' => 'confirmed',
                    'note' => null,
                ];
                $sumFull += $full;
                $sumClaimed += $amount;
                continue;
            }

            $months = [];
            $yearFull = 0.0;
            $yearAmount = 0.0;
            foreach ($monthsFull as $idx => $full) {
                $claimed = $m1Active
                    ? min((float) ceil(round($full * $k, 6)), max(0.0, $m1Limit - $sumClaimed))
                    : $full;
                $months[] = ['month' => self::monthKey($idx), 'amount' => $claimed];
                $yearFull += $full;
                $yearAmount += $claimed;
                $sumClaimed += $claimed;
            }
            $rows[] = [
                'fiscal_year' => $y,
                'amount' => $yearAmount,
                'full_amount' => $yearFull,
                'residual_start' => $zcStart,
                'residual_end' => $zcStart - $yearFull,
                'is_half' => false,
                'is_paused' => false,
                'months_count' => count($months),
                'months' => $months,
                'source' => 'computed',
                'note' => $m1Active ? sprintf('§30e — uplatněná část odpisu koeficientem %.0f / VC.', $m1Limit) : null,
            ];
            $sumFull += $yearFull;
        }

        return $rows;
    }

    private static function monthIndex(string $date): int
    {
        return ((int) substr($date, 0, 4)) * 12 + ((int) substr($date, 5, 2)) - 1;
    }

    private static function monthKey(int $idx): string
    {
        return sprintf('%04d-%02d', intdiv($idx, 12), $idx % 12 + 1);
    }
}
