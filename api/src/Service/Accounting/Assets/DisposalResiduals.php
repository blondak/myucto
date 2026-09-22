<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Assets;

use MyInvoice\Infrastructure\Database\Connection;

/**
 * Účetní a daňová zůstatková cena majetku vyřazeného v období. Jediné místo, kde se
 * obě ZC k vyřazení určují: čte ho přiznání DPPO ({@see \MyInvoice\Service\Tax\Return\DppoReturnDataProvider})
 * i evidenční podklad úprav základu daně ({@see \MyInvoice\Action\Accounting\Closing\TaxBaseReportAction}),
 * zaúčtování vyřazení v modulu majetku ({@see AssetService::dispose()}) bere účetní ZC
 * z {@see self::bookResidual()}.
 *
 * Účetní ZC:
 *   - karta vyřazená v modulu majetku: debetní nákladové řádky jejího zápisu vyřazení
 *     (`source_type = 'asset_disposal'`), tak jak se skutečně zaúčtovaly;
 *   - karta vyřazená mimo modul (převod z jiného systému, kde vyřazení zaúčtoval převzatý
 *     deník, nebo ruční zápis 54x): z karty, stejným výpočtem jako při vyřazení v modulu
 *     (zvýšená vstupní cena − oprávky). Kontrolou je deník: MD 54x v dokladech ke dni
 *     vyřazení, které připisují na oprávky karty (u neodpisovaného majetku na majetkový
 *     účet). Nesedí-li, přiznání jede z karty a varování nese obě čísla.
 *
 * Daňová ZC:
 *   - poslední daňový řádek karty (`depreciation_entries.kind = 'tax'`);
 *   - bez daňových řádků u karty „daňový = účetní" (§ 24/2/v nehmotný majetek) = účetní ZC;
 *   - neodpisovaný majetek (§ 27, bez oprávkového účtu, typicky pozemek) = vstupní cena
 *     + technická zhodnocení − počáteční daňový stav;
 *   - karta s počátečním daňovým stavem (převzaté roky odpisů) = vstupní cena + TZ − ten stav;
 *   - odpisovaný majetek bez jakékoli daňové historie = NEZNÁMÁ (null). Dosazení celé
 *     vstupní ceny by u plně odepsaného majetku vyrobilo fiktivní daňový výdaj ve výši
 *     vstupní ceny; přiznání proto nic nedopočítá a varuje.
 */
final class DisposalResiduals
{
    public const BOOK_SOURCE_ENTRY = 'disposal_entry';
    public const BOOK_SOURCE_CARD = 'card';

    public const TAX_SOURCE_ENTRIES = 'tax_entries';
    public const TAX_SOURCE_BY_ACCOUNTING = 'by_accounting';
    public const TAX_SOURCE_NON_DEPRECIABLE = 'non_depreciable';
    public const TAX_SOURCE_OPENING = 'opening';
    public const TAX_SOURCE_UNKNOWN = 'unknown';

    /** Účtová skupina nákladů, na kterou převzaté vyřazení účtuje ZC (541, 543, 549). */
    private const JOURNAL_EXPENSE_PREFIX = '54';

    public function __construct(private readonly Connection $db) {}

    /**
     * Účetní ZC ke dni vyřazení: neodpisovaný majetek celou (zvýšenou) vstupní cenou,
     * odpisovaný zvýšenou vstupní cenou po odečtení oprávek, nejméně nula.
     */
    public static function bookResidual(float $increasedPrice, float $accumulatedDepreciation, bool $depreciable): float
    {
        if (!$depreciable) {
            return round($increasedPrice, 2);
        }
        return max(0.0, round($increasedPrice - $accumulatedDepreciation, 2));
    }

    /**
     * @return array{
     *   rows: list<array{
     *     asset_id:int, inventory_number:string, name:string, disposal_date:string,
     *     disposal_type:string, disposal_price:?float,
     *     book_residual_value:float, book_residual_source:string, journal_residual_value:?float,
     *     tax_residual_value:?float, tax_residual_source:string, expense_group:string
     *   }>,
     *   warnings: list<string>
     * }
     */
    public function forPeriod(int $supplierId, string $startsOn, string $endsOn): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT a.id, a.inventory_number, a.name, a.disposal_date, a.disposal_type, a.disposal_price,
                    a.input_price, a.opening_tax_years, a.opening_tax_amount, a.opening_acc_amount,
                    a.tax_method, a.asset_account_code, a.accumulated_account_code,
                    (SELECT COALESCE(SUM(ai.amount), 0) FROM asset_improvements ai
                      WHERE ai.supplier_id = a.supplier_id AND ai.asset_id = a.id
                        AND ai.completed_on <= a.disposal_date) AS improvements_total,
                    (SELECT de.residual_value_end FROM depreciation_entries de
                      WHERE de.supplier_id = a.supplier_id AND de.asset_id = a.id AND de.kind = \'tax\'
                      ORDER BY de.fiscal_year DESC LIMIT 1) AS tax_residual,
                    (SELECT COALESCE(SUM(de.amount), 0) FROM depreciation_entries de
                      WHERE de.supplier_id = a.supplier_id AND de.asset_id = a.id AND de.kind = \'accounting\') AS accounting_total,
                    (SELECT SUM(jl.amount)
                       FROM journal_entries je
                       JOIN journal_entry_lines jl ON jl.entry_id = je.id AND jl.supplier_id = je.supplier_id
                       JOIN chart_of_accounts ca ON ca.id = jl.account_id
                      WHERE je.supplier_id = a.supplier_id AND je.source_type = \'asset_disposal\'
                        AND je.source_id = a.id AND je.posted_at IS NOT NULL AND je.reversed_by IS NULL
                        AND jl.side = \'debit\' AND ca.account_type = \'expense\') AS entry_residual,
                    (SELECT MIN(ca.account_code)
                       FROM journal_entries je
                       JOIN journal_entry_lines jl ON jl.entry_id = je.id AND jl.supplier_id = je.supplier_id
                       JOIN chart_of_accounts ca ON ca.id = jl.account_id
                      WHERE je.supplier_id = a.supplier_id AND je.source_type = \'asset_disposal\'
                        AND je.source_id = a.id AND je.posted_at IS NOT NULL AND je.reversed_by IS NULL
                        AND jl.side = \'debit\' AND ca.account_type = \'expense\') AS entry_expense_account,
                    (SELECT je.id FROM journal_entries je
                      WHERE je.supplier_id = a.supplier_id AND je.source_type = \'asset_disposal\'
                        AND je.source_id = a.id AND je.posted_at IS NOT NULL AND je.reversed_by IS NULL
                      ORDER BY je.id DESC LIMIT 1) AS disposal_entry_id
               FROM assets a
              WHERE a.supplier_id = ? AND a.status = \'disposed\'
                AND a.disposal_date BETWEEN ? AND ?
              ORDER BY a.disposal_date, a.inventory_number'
        );
        $stmt->execute([$supplierId, $startsOn, $endsOn]);

        $rows = [];
        $warnings = [];
        $groups = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $number = (string) $r['inventory_number'];
            $depreciable = $r['accumulated_account_code'] !== null && (string) $r['accumulated_account_code'] !== '';
            $increased = round((float) $r['input_price'] + (float) $r['improvements_total'], 2);

            if ($r['disposal_entry_id'] !== null) {
                $book = round((float) ($r['entry_residual'] ?? 0), 2);
                $bookSource = self::BOOK_SOURCE_ENTRY;
                $expenseAccount = $r['entry_expense_account'] !== null ? (string) $r['entry_expense_account'] : null;
            } else {
                $book = self::bookResidual(
                    $increased,
                    (float) $r['opening_acc_amount'] + (float) $r['accounting_total'],
                    $depreciable,
                );
                $bookSource = self::BOOK_SOURCE_CARD;
                $expenseAccount = null;
                $creditAccount = $depreciable ? (string) $r['accumulated_account_code'] : (string) $r['asset_account_code'];
                $key = (string) $r['disposal_date'] . '|' . $creditAccount;
                $groups[$key]['date'] = (string) $r['disposal_date'];
                $groups[$key]['account'] = $creditAccount;
                $groups[$key]['numbers'][] = $number;
                $groups[$key]['card'] = round(($groups[$key]['card'] ?? 0.0) + $book, 2);
                $groups[$key]['rows'][] = count($rows);
            }

            [$tax, $taxSource] = $this->taxResidual($r, $book, $increased, $depreciable);
            if ($taxSource === self::TAX_SOURCE_UNKNOWN) {
                $warnings[] = 'U majetku ' . $number . ' (' . (string) $r['name'] . ') není známa daňová zůstatková cena: '
                    . 'karta nemá daňové odpisy ani počáteční daňový stav. Rozdíl účetní a daňové ZC se proto '
                    . 'nedopočítal; daňovou ZC ověřte a rozdíl zadejte ruční položkou přiznání.';
            }

            $rows[] = [
                'asset_id' => (int) $r['id'],
                'inventory_number' => $number,
                'name' => (string) $r['name'],
                'disposal_date' => (string) $r['disposal_date'],
                'disposal_type' => (string) $r['disposal_type'],
                'disposal_price' => $r['disposal_price'] !== null ? (float) $r['disposal_price'] : null,
                'book_residual_value' => $book,
                'book_residual_source' => $bookSource,
                'journal_residual_value' => null,
                'tax_residual_value' => $tax,
                'tax_residual_source' => $taxSource,
                'expense_group' => $expenseAccount !== null ? substr($expenseAccount, 0, 2) : self::JOURNAL_EXPENSE_PREFIX,
            ];
        }

        foreach ($groups as $g) {
            $journal = $this->journalResidual($supplierId, $g['date'], $g['account']);
            foreach ($g['rows'] as $i) {
                $rows[$i]['journal_residual_value'] = count($g['rows']) === 1 ? $journal : null;
            }
            if (abs($journal - $g['card']) >= 0.01) {
                $warnings[] = 'Účetní ZC vyřazeného majetku ' . implode(', ', $g['numbers']) . ' ke dni ' . $g['date']
                    . ': podle karty ' . self::money($g['card']) . ', v deníku (MD ' . self::JOURNAL_EXPENSE_PREFIX
                    . 'x proti účtu ' . $g['account'] . ') ' . self::money($journal)
                    . '. Přiznání počítá se ZC podle karty; rozdíl ověřte.';
            }
        }

        return ['rows' => $rows, 'warnings' => $warnings];
    }

    /**
     * @param array<string,mixed> $r
     * @return array{0:?float,1:string}
     */
    private function taxResidual(array $r, float $book, float $increased, bool $depreciable): array
    {
        if ($r['tax_residual'] !== null) {
            return [round((float) $r['tax_residual'], 2), self::TAX_SOURCE_ENTRIES];
        }
        if ((string) $r['tax_method'] === 'by_accounting') {
            return [$book, self::TAX_SOURCE_BY_ACCOUNTING];
        }
        $fromCard = max(0.0, round($increased - (float) $r['opening_tax_amount'], 2));
        if (!$depreciable) {
            return [$fromCard, self::TAX_SOURCE_NON_DEPRECIABLE];
        }
        if ((int) $r['opening_tax_years'] > 0 || (float) $r['opening_tax_amount'] > 0.0) {
            return [$fromCard, self::TAX_SOURCE_OPENING];
        }
        return [null, self::TAX_SOURCE_UNKNOWN];
    }

    /**
     * MD 54x v dokladech ke dni vyřazení, které připisují na daný účet (oprávky, u
     * neodpisovaného majetku majetkový účet) včetně jeho analytik. Zápisy vyřazení
     * z modulu majetku a uzávěrkové zápisy se nepočítají.
     */
    private function journalResidual(int $supplierId, string $date, string $account): float
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COALESCE(SUM(dl.amount), 0)
               FROM journal_entries je
               JOIN journal_entry_lines dl ON dl.entry_id = je.id AND dl.supplier_id = je.supplier_id AND dl.side = \'debit\'
               JOIN chart_of_accounts dca ON dca.id = dl.account_id
              WHERE je.supplier_id = ? AND je.entry_date = ?
                AND je.posted_at IS NOT NULL AND je.reversed_by IS NULL
                AND je.source_type NOT IN (\'asset_disposal\', \'closing\')
                AND dca.account_code LIKE ?
                AND EXISTS (
                    SELECT 1 FROM journal_entry_lines cl
                      JOIN chart_of_accounts cca ON cca.id = cl.account_id
                     WHERE cl.entry_id = je.id AND cl.supplier_id = je.supplier_id AND cl.side = \'credit\'
                       AND (cca.account_code = ? OR cca.account_code LIKE ?)
                )'
        );
        $stmt->execute([$supplierId, $date, self::JOURNAL_EXPENSE_PREFIX . '%', $account, $account . '.%']);
        return round((float) $stmt->fetchColumn(), 2);
    }

    private static function money(float $amount): string
    {
        return number_format($amount, 2, ',', ' ') . ' Kč';
    }
}
