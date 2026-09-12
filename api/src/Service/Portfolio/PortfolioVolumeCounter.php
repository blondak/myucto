<?php

declare(strict_types=1);

namespace MyInvoice\Service\Portfolio;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\BankStatementOwnershipResolver;
use MyInvoice\Service\Crm\CrmAggregationService;

/**
 * Objem dat firem v přehledu firem: kolik dokladů a zápisů v nich leží.
 *
 * Každý typ je JEDEN agregační dotaz s GROUP BY přes celý seznam firem, které
 * přehled zobrazuje (žádný dotaz na firmu v cyklu). Viditelnost firem řeší volající
 * ({@see PortfolioAggregationService::allowedSupplierIds()}), sem přichází už
 * odfiltrovaný seznam, takže cizí firma se do počtů dostat nemůže.
 *
 * Rozsah počtů:
 *   - vydané faktury: stejná definice jako „počet faktur" v CRM/Tržbách
 *     ({@see CrmAggregationService::REV_TYPES}, {@see CrmAggregationService::REV_STATUS}),
 *     tj. faktury, dobropisy a daňové doklady k přijaté platbě bez konceptů a stornovaných.
 *     Proforma není daňový doklad a po úhradě z ní vzniká finální faktura, takže by se
 *     tentýž obchod počítal dvakrát;
 *   - přijaté faktury: stavy nákladů v CRM ({@see CrmAggregationService::COST_STATUS})
 *     bez zálohových faktur, symetricky s vynechanou proformou na straně vydaných.
 *     Koncepty ukazuje přehled zvlášť (`purchase_drafts`);
 *   - bankovní výpisy: vlastnictví přes {@see BankStatementOwnershipResolver} jako
 *     `unmatched_bank_transactions` a `last_bank_import_at`, včetně legacy výpisů bez
 *     `supplier_id`. Pohyby se počítají přes vlastní výpis transakce, takže pohyb
 *     doložený ve více výpisech (bank_transaction_imports) se nezapočte dvakrát;
 *   - pokladní doklady: zaúčtované i stornované, bez konceptů;
 *   - účetní deník: všechny zápisy včetně storen, stejně jako je ukazuje deník.
 */
final class PortfolioVolumeCounter
{
    public const KEYS = [
        'issued_invoices',
        'purchase_invoices',
        'bank_statements',
        'bank_transactions',
        'cash_documents',
        'journal_entries',
    ];

    public function __construct(private readonly Connection $db) {}

    /**
     * @param list<int> $supplierIds
     * @return array<int, array<string,int>> supplier_id → počty dle {@see KEYS}
     */
    public function countsFor(array $supplierIds): array
    {
        $supplierIds = array_values(array_unique(array_filter(array_map('intval', $supplierIds), static fn (int $id): bool => $id > 0)));
        if ($supplierIds === []) {
            return [];
        }

        $out = [];
        foreach ($supplierIds as $sid) {
            $out[$sid] = array_fill_keys(self::KEYS, 0);
        }

        $in = implode(',', array_fill(0, count($supplierIds), '?'));

        $this->merge($out, 'issued_invoices',
            "SELECT supplier_id, COUNT(*) AS cnt FROM invoices
              WHERE supplier_id IN ($in)
                AND status IN " . CrmAggregationService::REV_STATUS . "
                AND invoice_type IN " . CrmAggregationService::REV_TYPES . "
              GROUP BY supplier_id",
            $supplierIds);

        $this->merge($out, 'purchase_invoices',
            "SELECT supplier_id, COUNT(*) AS cnt FROM purchase_invoices
              WHERE supplier_id IN ($in)
                AND status IN " . CrmAggregationService::COST_STATUS . "
                AND COALESCE(document_kind, 'invoice') <> 'advance'
              GROUP BY supplier_id",
            $supplierIds);

        // Podmínka `bs.supplier_id = s.id OR bs.supplier_id IS NULL` je logický důsledek
        // predikátu vlastnictví; stojí tu kvůli přístupu ref_or_null přes idx_bs_supplier,
        // jinak by se predikát (CASE) vyhodnocoval nad všemi výpisy pro každou firmu.
        $stmt = $this->db->pdo()->prepare(
            "SELECT s.id AS supplier_id, COUNT(DISTINCT bs.id) AS statements, COUNT(bt.id) AS transactions
               FROM supplier s
               JOIN bank_statements bs
                 ON (bs.supplier_id = s.id OR bs.supplier_id IS NULL)
                AND " . BankStatementOwnershipResolver::sqlForColumn('s.id') . "
               LEFT JOIN bank_transactions bt ON bt.statement_id = bs.id
              WHERE s.id IN ($in)
              GROUP BY s.id"
        );
        $stmt->execute($supplierIds);
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $sid = (int) $r['supplier_id'];
            if (isset($out[$sid])) {
                $out[$sid]['bank_statements'] = (int) $r['statements'];
                $out[$sid]['bank_transactions'] = (int) $r['transactions'];
            }
        }

        $this->merge($out, 'cash_documents',
            "SELECT supplier_id, COUNT(*) AS cnt FROM cash_documents
              WHERE supplier_id IN ($in)
                AND status <> 'draft'
              GROUP BY supplier_id",
            $supplierIds);

        $this->merge($out, 'journal_entries',
            "SELECT supplier_id, COUNT(*) AS cnt FROM journal_entries
              WHERE supplier_id IN ($in)
              GROUP BY supplier_id",
            $supplierIds);

        return $out;
    }

    /**
     * @param array<int, array<string,int>> $out
     * @param list<int> $params
     */
    private function merge(array &$out, string $key, string $sql, array $params): void
    {
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $sid = (int) $r['supplier_id'];
            if (isset($out[$sid])) {
                $out[$sid][$key] = (int) $r['cnt'];
            }
        }
    }
}
