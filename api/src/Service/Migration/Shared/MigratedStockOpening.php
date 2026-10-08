<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Shared;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Stock\StockDocumentService;
use PDO;

/**
 * Počáteční stav skladu převzatý z účetního programu: zaúčtovaná příjemka do jednoho
 * skladu, každý řádek s množstvím a hodnotou přesně podle zdroje.
 *
 * Příjemka účtuje řádek jako `round(množství × pořizovací cena, 2) + vedlejší náklady`.
 * Pořizovací cena se proto zaokrouhlí DOLŮ na šest míst a haléřový zbytek do hodnoty
 * zdroje jde do vedlejších nákladů - stav karty pak sedí na haléř. Před zaúčtováním musí
 * být stav dotčených karet ve skladu nulový (počáteční stav se zakládá jednou) a po
 * zaúčtování se množství i hodnota každé karty porovnají se zdrojem.
 *
 * Skladový doklad do deníku neúčtuje (způsob B): hodnota zásob v účetnictví zůstává
 * ze zůstatků převedeného deníku, sklad se do něj promítne až uzávěrkou.
 */
final class MigratedStockOpening
{
    public function __construct(
        private readonly Connection $db,
        private readonly StockDocumentService $documents,
    ) {}

    /**
     * Pořizovací cena a vedlejší náklady řádku, aby hodnota řádku byla přesně `$valueC`.
     *
     * @return array{unit_cost:string,extra_cost:string}
     */
    public static function lineCost(int $qtyT, int $valueC): array
    {
        $qty = $qtyT / 1000;
        $unitCostMicro = $qtyT > 0 && $valueC >= 0 ? (int) floor($valueC * 10000 / $qty + 0.00000001) : 0;
        $lineC = (int) round(round($qty * $unitCostMicro / 1000000, 2) * 100);
        while ($lineC > $valueC && $unitCostMicro > 0) {
            $unitCostMicro--;
            $lineC = (int) round(round($qty * $unitCostMicro / 1000000, 2) * 100);
        }
        return [
            'unit_cost' => number_format($unitCostMicro / 1000000, 6, '.', ''),
            'extra_cost' => number_format(max(0, $valueC - $lineC) / 100, 2, '.', ''),
        ];
    }

    /**
     * Založí a zaúčtuje příjemku a ověří výsledný stav. Volá se uvnitř transakce kroku
     * převodu; chyba ji celou vrátí.
     *
     * @param list<array{stock_item_id:int,qty_t:int,value_c:int}> $lines množství v tisícinách, hodnota v haléřích
     * @return list<int> id řádků příjemky v pořadí `$lines`
     */
    public function post(int $supplierId, ?int $userId, int $warehouseId, string $date, string $description, array $lines): array
    {
        if ($lines === []) {
            return [];
        }
        $pdo = $this->db->pdo();
        $level = $pdo->prepare('SELECT qty, value_total FROM stock_levels WHERE supplier_id = ? AND warehouse_id = ? AND stock_item_id = ?');
        $expected = [];
        foreach ($lines as $line) {
            $level->execute([$supplierId, $warehouseId, $line['stock_item_id']]);
            $before = $level->fetch(PDO::FETCH_ASSOC);
            $level->closeCursor();
            if (isset($expected[$line['stock_item_id']])
                || ($before !== false && ((float) $before['qty'] !== 0.0 || (float) $before['value_total'] !== 0.0))) {
                throw new MigratedInventoryException('stock_target_not_empty', 'Karta už má ve skladu stav, počáteční stav se do ní nepřevádí podruhé.');
            }
            $expected[$line['stock_item_id']] = $line;
        }
        $body = [
            'doc_type' => 'receipt',
            'origin' => 'manual',
            'warehouse_id' => $warehouseId,
            'doc_date' => $date,
            'description' => $description,
            'lines' => array_map(static fn (array $line): array => [
                'stock_item_id' => $line['stock_item_id'],
                'qty' => number_format($line['qty_t'] / 1000, 3, '.', ''),
            ] + self::lineCost($line['qty_t'], $line['value_c']), $lines),
        ];
        $created = $this->documents->create($supplierId, $body, $userId);
        $posted = $this->documents->post($supplierId, (int) $created['id'], $userId);

        $ids = [];
        foreach ($lines as $i => $line) {
            $row = $posted['lines'][$i] ?? null;
            $level->execute([$supplierId, $warehouseId, $line['stock_item_id']]);
            $after = $level->fetch(PDO::FETCH_ASSOC);
            $level->closeCursor();
            if (!is_array($row) || (int) $row['stock_item_id'] !== $line['stock_item_id'] || $after === false
                || (int) round((float) $after['qty'] * 1000) !== $line['qty_t']
                || (int) round((float) $after['value_total'] * 100) !== $line['value_c']) {
                throw new MigratedInventoryException('stock_opening_reconciliation_failed', 'Stav skladu po převodu nesouhlasí se zdrojem.');
            }
            $ids[] = (int) $row['id'];
        }
        return $ids;
    }
}
