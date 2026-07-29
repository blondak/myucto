<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Assets\Strategy;

use MyInvoice\Service\Accounting\Assets\DepreciationContext;

/**
 * Účetní rovnoměrné měsíční odpisy dle ČÚS 013 / §28 ZoÚ (Epic F3, spec §2.7).
 * Start měsícem následujícím po zařazení; měsíční odpis = round((VC − residual)
 * / accUsefulLifeMonths) na celé Kč, průběžný cap, poslední měsíc dorovnání.
 * TZ dokončené v měsíci m → od m+1 prospektivní přepočet round((ZC po TZ −
 * residual) / zbývající měsíce); životnost se nemění (R9). Vyřazení: naposledy
 * měsíc vyřazení včetně. Opening (R23): pokračuje se měsícem opening_acc_months+1
 * se ZC = VC − openingAccAmount. Tvar řádků shodný s daňovými strategiemi,
 * `months` u počítaných roků vždy vyplněné.
 */
final class AccountingStraightLineStrategy implements AccountingDepreciationStrategyInterface
{
    /** @return list<array<string,mixed>> tvar dle TaxDepreciationStrategyInterface::plan() */
    public function plan(DepreciationContext $ctx): array
    {
        return $this->buildPlan($ctx, null);
    }

    /** Řádek jednoho roku (pro book/dispose) — rok se počítá vždy čerstvě. */
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
        if ($ctx->putIntoUseDate === null
            || $ctx->accUsefulLifeMonths === null || $ctx->accUsefulLifeMonths < 1
            || $ctx->inputPrice <= 0
        ) {
            return [];
        }
        $total = $ctx->accUsefulLifeMonths;
        $residualValue = $ctx->accResidualValue;
        $startIdx = self::monthIndex($ctx->putIntoUseDate) + 1 + $ctx->openingAccMonths;
        $disposalIdx = $ctx->disposalDate !== null ? self::monthIndex($ctx->disposalDate) : null;

        $tzByIdx = [];
        foreach ($ctx->improvements as $imp) {
            $idx = self::monthIndex((string) $imp['completed_on']);
            $tzByIdx[$idx] = ($tzByIdx[$idx] ?? 0.0) + (float) $imp['amount'];
        }

        $confirmed = [];
        foreach ($ctx->confirmedEntries as $entry) {
            if (($entry['kind'] ?? '') === 'accounting') {
                $confirmed[(int) $entry['fiscal_year']] = $entry;
            }
        }

        $monthsDone = $ctx->openingAccMonths;
        $accumulated = $ctx->openingAccAmount;
        $rows = [];
        $monthly = 0.0;
        $needRecompute = true;

        $bucketYear = null;
        $bucketMonths = [];
        $bucketAmount = 0.0;
        $bucketResStart = 0.0;
        $bucketLastIdx = 0;

        $flushBucket = function () use (&$rows, &$bucketYear, &$bucketMonths, &$bucketAmount, &$bucketResStart, &$bucketLastIdx, &$accumulated, $ctx, $tzByIdx): void {
            if ($bucketYear === null || $bucketMonths === []) {
                $bucketYear = null;
                $bucketMonths = [];
                $bucketAmount = 0.0;
                return;
            }
            $rows[] = [
                'fiscal_year' => $bucketYear,
                'amount' => $bucketAmount,
                'full_amount' => $bucketAmount,
                'residual_start' => $bucketResStart,
                'residual_end' => self::cost($ctx->inputPrice, $tzByIdx, $bucketLastIdx) - $accumulated,
                'is_half' => false,
                'is_paused' => false,
                'months_count' => count($bucketMonths),
                'months' => $bucketMonths,
                'source' => 'computed',
                'note' => null,
            ];
            $bucketYear = null;
            $bucketMonths = [];
            $bucketAmount = 0.0;
        };

        $m = $startIdx;
        $safety = 0;
        while ($monthsDone < $total && $safety++ < 3000) {
            if ($disposalIdx !== null && $m > $disposalIdx) {
                break;
            }
            $y = $ctx->calendar->fiscalYearOfMonthIndex($m);

            $entry = $confirmed[$y] ?? null;
            if ($forceComputeYear === $y) {
                $entry = null;
            }

            if ($entry !== null) {
                $flushBucket();
                $resStart = self::cost($ctx->inputPrice, $tzByIdx, $m - 1) - $accumulated;
                $last = $ctx->calendar->lastMonthIndex($y);
                if ($disposalIdx !== null) {
                    $last = min($last, $disposalIdx);
                }
                $last = min($last, $m + ($total - $monthsDone) - 1);
                $count = max(0, $last - $m + 1);
                $amount = (float) $entry['amount'];
                $accumulated += $amount;
                $monthsDone += $count;
                $rows[] = [
                    'fiscal_year' => $y,
                    'amount' => $amount,
                    'full_amount' => $amount,
                    'residual_start' => $resStart,
                    'residual_end' => self::cost($ctx->inputPrice, $tzByIdx, $last) - $accumulated,
                    'is_half' => false,
                    'is_paused' => false,
                    'months_count' => $count > 0 ? $count : null,
                    'months' => null,
                    'source' => 'confirmed',
                    'note' => null,
                ];
                $needRecompute = true; // prospektivní pokračování po potvrzeném roce
                if ($disposalIdx !== null && $last >= $disposalIdx) {
                    return $rows;
                }
                $m = $ctx->calendar->firstMonthIndex($y + 1); // první měsíc následujícího období
                continue;
            }

            $applicable = self::cost($ctx->inputPrice, $tzByIdx, $m - 1); // TZ působí od měsíce následujícího
            if ($needRecompute) {
                $remaining = max(1, $total - $monthsDone);
                $monthly = (float) round(($applicable - $residualValue - $accumulated) / $remaining);
                $needRecompute = false;
            }

            if ($bucketYear !== $y) {
                $flushBucket();
                $bucketYear = $y;
                $bucketResStart = $applicable - $accumulated;
            }

            $amount = $monthly;
            if ($monthsDone === $total - 1) {
                $amount = ($applicable - $residualValue) - $accumulated; // poslední měsíc = dorovnání
            }
            $amount = max(0.0, min($amount, ($applicable - $residualValue) - $accumulated)); // průběžný cap

            $bucketMonths[] = ['month' => self::monthKey($m), 'amount' => $amount];
            $bucketAmount += $amount;
            $bucketLastIdx = $m;
            $accumulated += $amount;
            $monthsDone++;

            if (isset($tzByIdx[$m])) {
                $needRecompute = true; // TZ dokončené tento měsíc → od dalšího měsíce nový odpis
            }
            if ($disposalIdx !== null && $m === $disposalIdx) {
                break; // naposledy měsíc vyřazení včetně
            }
            $m++;
        }

        $flushBucket();

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

    /** Pořizovací cena zvýšená o TZ dokončená do měsíce $uptoIdx včetně. */
    private static function cost(float $inputPrice, array $tzByIdx, int $uptoIdx): float
    {
        $cost = $inputPrice;
        foreach ($tzByIdx as $idx => $amount) {
            if ($idx <= $uptoIdx) {
                $cost += $amount;
            }
        }
        return $cost;
    }
}
