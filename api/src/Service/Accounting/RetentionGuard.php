<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\RetentionHoldRepository;

/**
 * Brána proti PŘEDČASNÉMU smazání účetních a daňových záznamů (§ 31, § 32 ZoÚ, § 35a ZDPH).
 *
 * Do jejího doplnění šlo fyzicky smazat zaúčtovaný doklad i archiv účetnictví bez ohledu
 * na stáří, přestože UI i manuál tvrdily, že produkt archivační povinnost naplňuje.
 *
 * Brána NIC nemaže a nic neskartuje — jen odmítne smazání, které by porušilo povinnost
 * uchovávat. Uplynulá lhůta je konec povinnosti, ne příkaz ke skartaci; co s takovým
 * záznamem dál, rozhoduje uživatel.
 *
 * Lhůty drží {@see RetentionPolicy}, prodloužení podle § 32 {@see RetentionHoldRepository}.
 */
final class RetentionGuard
{
    public function __construct(
        private readonly Connection $db,
        private readonly RetentionHoldRepository $holds,
    ) {}

    /**
     * Ověří, že záznamy z daného roku už nejsou chráněné. Vyhodí výjimku s konkrétním
     * datem nebo číslem jednacím — obecné „nelze smazat" by uživatele nechalo hádat.
     *
     * @param string $what popis mazaného objektu do hlášky (např. 'Doklad 2024001')
     */
    public function assertDeletable(int $supplierId, int $periodYear, string $what, ?string $asOf = null): void
    {
        $holds = $this->holds->activeHolds($supplierId, $periodYear);
        if ($holds !== []) {
            $first = $holds[0];
            throw new RetentionViolationException(sprintf(
                '%s nelze smazat: záznamy jsou zadržené podle § 32 ZoÚ (%s, %s, od %s).',
                $what,
                self::reasonLabel((string) $first['reason']),
                (string) $first['description'],
                (new \DateTimeImmutable((string) $first['placed_on']))->format('j. n. Y'),
            ));
        }

        $periodEnd = $this->periodEnd($supplierId, $periodYear);
        $category = RetentionPolicy::FINANCIAL_STATEMENTS;
        $until = RetentionPolicy::longestRetainUntil($periodEnd);

        if (RetentionPolicy::isWithinRetention($category, $periodEnd, $asOf)) {
            throw new RetentionViolationException(sprintf(
                '%s nelze smazat: účetní záznamy období %d se podle § 31 ZoÚ a § 35a ZDPH '
                    . 'uchovávají do %s.',
                $what,
                $periodYear,
                (new \DateTimeImmutable($until))->format('j. n. Y'),
            ));
        }
    }

    /**
     * Do kdy se uchovávají záznamy roku (nejdelší z lhůt) — pro přehled v UI a pro
     * informativní hlášku u objektů, které se mazat smějí.
     */
    public function retainUntil(int $supplierId, int $periodYear): string
    {
        return RetentionPolicy::longestRetainUntil($this->periodEnd($supplierId, $periodYear));
    }

    /**
     * Rozpis stavu retence pro všechna účetní období firmy.
     *
     * @return list<array{year:int, period_end:string, retain_until:string, expired:bool,
     *                    on_hold:bool, schedule:list<array<string,mixed>>}>
     */
    public function overview(int $supplierId, ?string $asOf = null): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT YEAR(ends_on) AS year, MAX(ends_on) AS period_end
               FROM accounting_periods
              WHERE supplier_id = ?
           GROUP BY YEAR(ends_on)
           ORDER BY year DESC'
        );
        $stmt->execute([$supplierId]);

        $out = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $year = (int) $r['year'];
            $periodEnd = (string) $r['period_end'];
            $until = RetentionPolicy::longestRetainUntil($periodEnd);
            $onHold = $this->holds->hasActiveHold($supplierId, $year);
            $out[] = [
                'year'         => $year,
                'period_end'   => $periodEnd,
                'retain_until' => $until,
                // Hold podle § 32 drží záznamy i po uplynutí lhůty podle § 31.
                'expired'      => !$onHold && !RetentionPolicy::isWithinRetention(
                    RetentionPolicy::FINANCIAL_STATEMENTS, $periodEnd, $asOf
                ),
                'on_hold'      => $onHold,
                'schedule'     => RetentionPolicy::scheduleFor($periodEnd, $asOf),
            ];
        }

        return $out;
    }

    /**
     * Konec účetního období daného roku. Firma nemusí mít kalendářní rok (§ 3 odst. 2 ZoÚ),
     * takže se bere skutečný `ends_on` z evidence období; bez záznamu spadneme na
     * 31. 12. daného roku — konzervativní odhad, který lhůtu nezkracuje.
     */
    private function periodEnd(int $supplierId, int $periodYear): string
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT MAX(ends_on) FROM accounting_periods
              WHERE supplier_id = ? AND YEAR(ends_on) = ?'
        );
        $stmt->execute([$supplierId, $periodYear]);
        $end = $stmt->fetchColumn();

        return $end === false || $end === null
            ? sprintf('%04d-12-31', $periodYear)
            : (string) $end;
    }

    private static function reasonLabel(string $reason): string
    {
        return match ($reason) {
            'tax_audit'  => 'daňová kontrola',
            'appeal'     => 'odvolací řízení',
            'litigation' => 'soudní spor',
            default      => 'jiné řízení',
        };
    }
}
