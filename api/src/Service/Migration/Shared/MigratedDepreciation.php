<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Shared;

use MyInvoice\Repository\DepreciationEntryRepository;

/**
 * Převzaté odpisy dlouhodobého majetku: řádky odpisů převedených let a počáteční stavy
 * účetních odpisů z měsíčního plánu zdroje.
 *
 * Karta je v MyÚčtu vedená, jako by v něm vznikla: účetní odpis převedeného roku je řádek
 * zaúčtovaný převzatým deníkem ({@see DepreciationEntryRepository::MIGRATED_JOURNAL},
 * hromadné účtování odpisů ho znovu neúčtuje), daňový odpis je potvrzený řádek.
 */
final class MigratedDepreciation
{
    /** Přepsat existující řádek roku: vždy (když se liší). */
    public const OVERWRITE_ALWAYS = 'always';

    /**
     * Přepsat jen řádek, který zaúčtoval převzatý deník (účetní řádek s MIGRATED_JOURNAL);
     * řádek zaúčtovaný nebo potvrzený v MyÚčtu zůstává.
     */
    public const OVERWRITE_OWN = 'own';

    public function __construct(private readonly DepreciationEntryRepository $entries) {}

    /**
     * Zapíše odpis roku, pokud řádek roku chybí, nebo se liší a smí se přepsat.
     *
     * @param self::OVERWRITE_* $overwrite
     * @param bool $compareResidual shodný řádek = shodná částka I zůstatková cena (false = jen částka)
     * @param bool $clampResidual zapsat zápornou zůstatkovou cenu jako 0 (porovnává se nezkrácená, Money S3)
     * @param string $program název zdroje do detailu účetního řádku („Money S3", „PREMIER")
     * @return array{written:bool,previous:?array<string,mixed>} previous = přepsaný řádek
     */
    public function confirm(
        int $supplierId,
        int $assetId,
        string $kind,
        int $year,
        float $amount,
        float $full,
        float $residual,
        bool $paused,
        bool $half,
        ?int $months,
        string $program,
        string $status,
        string $overwrite,
        bool $compareResidual,
        bool $clampResidual = false,
    ): array {
        $existing = $this->entries->findYear($assetId, $kind, $year);
        if ($existing !== null) {
            $own = $overwrite === self::OVERWRITE_ALWAYS || $kind !== 'accounting' || DepreciationEntryRepository::isBookedByMigratedJournal($existing);
            $same = ReconciliationTolerance::sameCent((float) $existing['amount'], $amount)
                && (!$compareResidual || ReconciliationTolerance::sameCent((float) $existing['residual_value_end'], $residual));
            if (!$own || $same) {
                return ['written' => false, 'previous' => null];
            }
        }
        $this->entries->upsert([
            'supplier_id' => $supplierId,
            'asset_id' => $assetId,
            'kind' => $kind,
            'fiscal_year' => $year,
            'amount' => $amount,
            'full_amount' => $full,
            'residual_value_end' => $clampResidual ? max(0.0, $residual) : $residual,
            'is_paused' => $paused,
            'is_half' => $half,
            'months_count' => $months,
            'detail' => $kind === 'accounting' ? json_encode(['journal' => DepreciationEntryRepository::MIGRATED_JOURNAL, 'program' => $program]) : null,
            'status' => $status,
        ]);
        return ['written' => true, 'previous' => $existing];
    }

    /** Počet měsíců od `$from` (bez něj) do `$to` včetně, oba `Y-m` (nebo datum). */
    public static function monthsBetween(string $from, string $to): int
    {
        return ((int) substr($to, 0, 4) - (int) substr($from, 0, 4)) * 12 + (int) substr($to, 5, 2) - (int) substr($from, 5, 2);
    }

    /**
     * Počáteční stavy účetních odpisů z měsíčního plánu zdroje: měsíce od zařazení do
     * posledního zaúčtovaného (zdroj odpisuje od měsíce zařazení, MyÚčto od následujícího,
     * počítá se proto kalendářně od zařazení), jejich součet podle plánu a doba odpisování.
     *
     * @param array<string,float> $monthPlan `Y-m` → odpis, seřazené
     * @param string|null $bookedThrough poslední zaúčtovaný měsíc `Y-m`; null = žádný
     * @return array{0:int,1:float,2:?int} měsíce, částka (zaokrouhlená), doba odpisování v měsících (null bez plánu)
     */
    public static function accountingOpening(string $inUse, array $monthPlan, ?string $bookedThrough): array
    {
        if ($monthPlan === []) {
            return [0, 0.0, null];
        }
        $start = substr($inUse, 0, 7);
        $last = (string) array_key_last($monthPlan);
        $cutoff = $bookedThrough !== null ? min($bookedThrough, $last) : null;
        $amount = 0.0;
        if ($cutoff !== null) {
            foreach ($monthPlan as $month => $value) {
                if ($month <= $cutoff) {
                    $amount += $value;
                }
            }
        }
        return [
            $cutoff !== null ? max(0, self::monthsBetween($start, $cutoff)) : 0,
            round($amount, 2),
            max(1, self::monthsBetween($start, $last)),
        ];
    }
}
