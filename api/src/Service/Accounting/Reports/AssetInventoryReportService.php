<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Reports;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\AssetRepository;
use PDO;

/**
 * Inventarizace dlouhodobého majetku k rozvahovému dni (§29–30 ZoÚ) — uzávěrkový
 * balíček #33. Zdrojem je AssetRepository::list(), který už agreguje Σ účetních
 * odpisů (acc_amount_sum) a dopočítává zůstatkovou cenu (acc_residual) —
 * tady se karty jen filtrují na stav k rozvahovému dni a prezentují (VC/oprávky/ZC).
 *
 * Karta je v soupisu, pokud existovala k rozvahovému dni: pořízena nejpozději
 * k tomuto dni a NEvyřazená do tohoto dne včetně (vyřazení v samotný rozvahový
 * den už do majetku firmy nepatří).
 *
 * Drobný majetek (§DM, karty small_assets) NENÍ součástí — má vlastní evidenci
 * a sestavu ({@see SmallAssetReportService::inventory()}), uzávěrkový balíček
 * ji přikládá jako samostatný soubor vedle této sestavy.
 */
final class AssetInventoryReportService
{
    public function __construct(
        private readonly Connection $db,
        private readonly AssetRepository $assets,
        private readonly AccountingPeriodRepository $periods,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function build(int $supplierId, int $periodId): array
    {
        $period = $this->periods->findById($supplierId, $periodId);
        if ($period === null) {
            throw new ReportException('period_not_found', 'Účetní období #' . $periodId . ' neexistuje.', 404);
        }
        $endsOn = (string) $period['ends_on'];

        $rows = [];
        $totals = ['input_price' => 0.0, 'acc_amount' => 0.0, 'net_book_value' => 0.0];
        $page = 1;
        $total = 0;
        do {
            $res = $this->assets->list($supplierId, ['per_page' => 200, 'page' => $page]);
            $total = (int) ($res['total'] ?? 0);
            foreach ($res['items'] as $a) {
                $acqDate = (string) ($a['acquisition_date'] ?? '');
                if ($acqDate === '' || $acqDate > $endsOn) {
                    continue; // pořízeno až po rozvahovém dni
                }
                $disposalDate = $a['disposal_date'] ?? null;
                if ($disposalDate !== null && (string) $disposalDate <= $endsOn) {
                    continue; // vyřazeno do rozvahového dne včetně — už není v soupisu
                }
                $inputPrice = round((float) ($a['increased_input_price'] ?? $a['input_price']), 2);
                $netBookValue = round((float) ($a['acc_residual'] ?? 0), 2);
                $accAmount = round($inputPrice - $netBookValue, 2);
                $rows[] = [
                    'inventory_number'   => $a['inventory_number'] !== null ? (string) $a['inventory_number'] : null,
                    'name'               => (string) $a['name'],
                    'kind'               => (string) ($a['kind'] ?? ''),
                    'acquisition_date'   => $acqDate,
                    'put_into_use_date'  => $a['put_into_use_date'] !== null ? (string) $a['put_into_use_date'] : null,
                    'input_price'        => $inputPrice,
                    'acc_amount'         => $accAmount,
                    'net_book_value'     => $netBookValue,
                    'status'             => (string) $a['status'],
                ];
                $totals['input_price'] += $inputPrice;
                $totals['acc_amount'] += $accAmount;
                $totals['net_book_value'] += $netBookValue;
            }
            $page++;
        } while (($page - 1) * 200 < $total);

        usort($rows, static fn (array $x, array $y): int => strcmp((string) $x['inventory_number'], (string) $y['inventory_number']));

        return [
            'period'  => [
                'id'          => (int) $period['id'],
                'fiscal_year' => (int) $period['fiscal_year'],
                'starts_on'   => (string) $period['starts_on'],
                'ends_on'     => $endsOn,
            ],
            'as_of'   => $endsOn,
            'entity'  => $this->entity($supplierId),
            'rows'    => $rows,
            'count'   => count($rows),
            'totals'  => array_map(static fn (float $v): float => round($v, 2), $totals),
        ];
    }

    /**
     * @return array{name:string, ico:?string, address:string, prepared_at:string}
     */
    private function entity(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT company_name, street, city, zip, ic FROM supplier WHERE id = ?');
        $stmt->execute([$supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $addressParts = array_filter([
            trim((string) ($row['street'] ?? '')),
            trim((string) ($row['zip'] ?? '') . ' ' . (string) ($row['city'] ?? '')),
        ], static fn (string $p): bool => $p !== '');

        return [
            'name'        => (string) ($row['company_name'] ?? ''),
            'ico'         => $row['ic'] !== null && $row['ic'] !== '' ? (string) $row['ic'] : null,
            'address'     => implode(', ', $addressParts),
            'prepared_at' => (new \DateTimeImmutable())->format('d.m.Y H:i'),
        ];
    }
}
