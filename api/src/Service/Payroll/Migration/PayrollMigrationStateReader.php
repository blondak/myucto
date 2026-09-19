<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Migration;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Odpověď na jedinou otázku: převzala tahle firma něco z jiného mzdového
 * programu?
 *
 * Agenda přechodu (převzaté mzdy, kontrola přepočtu, kontace z převzatého
 * zaúčtování) je jednorázová. Firmě, která mzdy od začátku počítá v MyÚčtu, se
 * nemá co nabízet — a nabízet jí to nešlo poznat z ročního přehledu: ten je
 * vázaný na rok a mlčel by i tam, kde převzetí je, jen v jiném roce. Proto se
 * ptáme napříč roky a rovnou obou stran přechodu.
 *
 * Chybějící tabulka = firma běží na instalaci před migracemi 1849/1852; to není
 * chyba, jen „nic převzatého není".
 */
final class PayrollMigrationStateReader
{
    public function __construct(private readonly Connection $db) {}

    /**
     * @return array{
     *     has_takeover_wages:bool,
     *     has_posting_map:bool,
     *     takeover_years:list<int>,
     *     latest_takeover_year:int|null
     * }
     */
    public function forSupplier(int $supplierId): array
    {
        $years = $this->takeoverYears($supplierId);

        return [
            'has_takeover_wages' => $years !== [],
            'has_posting_map' => $this->hasPostingMap($supplierId),
            'takeover_years' => $years,
            'latest_takeover_year' => $years === [] ? null : $years[count($years) - 1],
        ];
    }

    /** @return list<int> vzestupně; prázdné pole znamená „nic převzatého". */
    private function takeoverYears(int $supplierId): array
    {
        if (!$this->db->hasTable('payroll_migration_reference_totals')) {
            return [];
        }
        $statement = $this->db->pdo()->prepare(
            'SELECT DISTINCT YEAR(period_start) AS takeover_year
               FROM payroll_migration_reference_totals
              WHERE supplier_id = ?
              ORDER BY takeover_year',
        );
        $statement->execute([$supplierId]);

        return array_values(array_map(
            static fn (mixed $year): int => (int) $year,
            $statement->fetchAll(PDO::FETCH_COLUMN, 0),
        ));
    }

    private function hasPostingMap(int $supplierId): bool
    {
        if (!$this->db->hasTable('payroll_posting_map_proposals')) {
            return false;
        }
        $statement = $this->db->pdo()->prepare(
            'SELECT 1 FROM payroll_posting_map_proposals WHERE supplier_id = ? LIMIT 1',
        );
        $statement->execute([$supplierId]);

        return $statement->fetchColumn() !== false;
    }
}
