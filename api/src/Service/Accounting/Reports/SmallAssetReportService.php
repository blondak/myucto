<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Reports;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\SmallAssetRepository;
use MyInvoice\Service\Accounting\Expense\ExpenseKind;
use PDO;

/**
 * Sestavy drobného majetku (§DM „Sestavy").
 *
 * Tři sestavy, tři různé zdroje — a ten rozdíl je podstatný:
 *   • soupis k datu a přírůstky/úbytky čtou KARTY (small_assets) — to je evidence věcí
 *     dle §28/5 ZoÚ, kterou účetní podepisuje k inventarizaci;
 *   • rozpis 501 čte ŘÁDKY PŘIJATÝCH FAKTUR (purchase_invoice_items.expense_kind) — to
 *     je účetní pohled, který musí sedět na hlavní knihu.
 * Míchat je dohromady by byla chyba: karta může existovat bez faktury (ruční, pokladna)
 * a řádek faktury bez karty (uživatel ji ještě nevygeneroval). Rozdíl mezi oběma je
 * užitečná informace, ne vada — proto se ani jedna sestava nedopočítává z té druhé.
 *
 * Bez období (period_id) záměrně: „soupis k datu" se váže k rozhodnému DNI inventarizace,
 * ne k účetnímu období, a §DM ho chce právě takhle. Ostatní sestavy F2 period_id vyžadují,
 * protože počítají PS/KS — tady se nic nepřenáší.
 */
final class SmallAssetReportService
{
    public function __construct(
        private readonly Connection $db,
        private readonly SmallAssetRepository $cards,
    ) {}

    /**
     * Soupis drobného majetku k datu — inventurní sestava (§DM Sestavy 1).
     *
     * @return array<string,mixed>
     */
    public function inventory(int $supplierId, string $asOf): array
    {
        $rows = $this->cards->inventoryAsOf($supplierId, $asOf);

        $byLocation = [];
        $total = 0.0;
        foreach ($rows as $row) {
            $location = ($row['location'] ?? null) !== null && $row['location'] !== ''
                ? (string) $row['location']
                : null;
            $key = $location ?? '';
            if (!isset($byLocation[$key])) {
                $byLocation[$key] = ['location' => $location, 'rows' => [], 'total' => 0.0];
            }
            $byLocation[$key]['rows'][] = $this->presentCard($row);
            $byLocation[$key]['total'] = round($byLocation[$key]['total'] + (float) $row['price'], 2);
            $total = round($total + (float) $row['price'], 2);
        }

        return [
            'as_of' => $asOf,
            'entity' => $this->entity($supplierId),
            'groups' => array_values($byLocation),
            'count' => count($rows),
            'total' => $total,
        ];
    }

    /**
     * Přírůstky a úbytky za období (§DM Sestavy 2).
     *
     * @return array<string,mixed>
     */
    public function movements(int $supplierId, string $from, string $to): array
    {
        $additions = array_map(fn (array $r): array => $this->presentCard($r), $this->cards->additionsBetween($supplierId, $from, $to));
        $disposals = array_map(fn (array $r): array => $this->presentCard($r), $this->cards->disposalsBetween($supplierId, $from, $to));

        return [
            'from' => $from,
            'to' => $to,
            'entity' => $this->entity($supplierId),
            'additions' => $additions,
            'disposals' => $disposals,
            'additions_total' => $this->sum($additions),
            'disposals_total' => $this->sum($disposals),
            'additions_count' => count($additions),
            'disposals_count' => count($disposals),
        ];
    }

    /**
     * Rozpis 501 za období rozpadlý dle druhu výdaje (§DM Sestavy 3) — porovnatelný
     * s analytikami účetní 501.100 (materiál vč. PHM) × 501.200 (drobný majetek).
     *
     * ZDROJ JE ŘÁDEK FAKTURY, NE KARTA — sestava má sedět na hlavní knihu, a tam náklad
     * dostal PostingService z `expense_kind` (1092), ne z evidence. Kdyby se počítala
     * z karet, chyběly by v ní řádky, ke kterým uživatel kartu ještě nevygeneroval, a
     * číslo by se s 501 rozešlo.
     *
     * ROK BERE Z DATA PLNĚNÍ (fallback vystavení), shodně s ExpenseClassificationService
     * a se zaúčtováním — doklad se běžně účtuje za loňsko.
     *
     * STORNOVANÉ DOKLADY VEN: `status='cancelled'` se neúčtuje, takže do rozpisu 501
     * nepatří — jinak by sestava ukazovala náklad, který v hlavní knize není.
     *
     * @return array<string,mixed>
     */
    public function expenseBreakdown(int $supplierId, string $from, string $to): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT pii.expense_kind,
                    COALESCE(pi.tax_date, pi.issue_date) AS doc_date,
                    pi.id AS purchase_invoice_id,
                    pi.vendor_invoice_number,
                    pi.status,
                    c.company_name AS vendor_name,
                    pii.description,
                    pii.quantity,
                    pii.total_without_vat
               FROM purchase_invoice_items pii
               JOIN purchase_invoices pi ON pi.id = pii.purchase_invoice_id
               LEFT JOIN clients c ON c.id = pi.vendor_id AND c.supplier_id = pi.supplier_id
              WHERE pi.supplier_id = ?
                AND pii.expense_kind IN (?, ?)
                AND pi.status <> \'cancelled\'
                AND COALESCE(pi.tax_date, pi.issue_date) BETWEEN ? AND ?
              ORDER BY pii.expense_kind, doc_date, pi.id, pii.order_index'
        );
        $stmt->execute([
            $supplierId,
            ExpenseKind::Material->value,
            ExpenseKind::SmallAsset->value,
            $from,
            $to,
        ]);

        $groups = [
            ExpenseKind::Material->value => ['expense_kind' => ExpenseKind::Material->value, 'rows' => [], 'total' => 0.0, 'document_ids' => []],
            ExpenseKind::SmallAsset->value => ['expense_kind' => ExpenseKind::SmallAsset->value, 'rows' => [], 'total' => 0.0, 'document_ids' => []],
        ];
        $total = 0.0;
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $kind = (string) $row['expense_kind'];
            $amount = round((float) $row['total_without_vat'], 2);
            $groups[$kind]['rows'][] = [
                'doc_date' => (string) $row['doc_date'],
                'purchase_invoice_id' => (int) $row['purchase_invoice_id'],
                'document_ref' => $row['vendor_invoice_number'] !== null ? (string) $row['vendor_invoice_number'] : null,
                'vendor_name' => $row['vendor_name'] !== null ? (string) $row['vendor_name'] : null,
                'description' => (string) $row['description'],
                'quantity' => (float) $row['quantity'],
                'amount' => $amount,
            ];
            $groups[$kind]['total'] = round($groups[$kind]['total'] + $amount, 2);
            $groups[$kind]['document_ids'][(int) $row['purchase_invoice_id']] = true;
            $total = round($total + $amount, 2);
        }

        $out = [];
        foreach ($groups as $group) {
            $group['document_count'] = count($group['document_ids']);
            unset($group['document_ids']);
            $out[] = $group;
        }

        return [
            'from' => $from,
            'to' => $to,
            'entity' => $this->entity($supplierId),
            'groups' => $out,
            'total' => $total,
        ];
    }

    /** @param list<array<string,mixed>> $rows */
    private function sum(array $rows): float
    {
        $total = 0.0;
        foreach ($rows as $row) {
            $total = round($total + (float) $row['price'], 2);
        }
        return $total;
    }

    /**
     * Zobrazovací tvar karty — `document_ref` je snapshot, takže soupis funguje i po
     * smazání či editaci zdrojového dokladu (viz 1094).
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function presentCard(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'inventory_number' => $row['inventory_number'] !== null ? (string) $row['inventory_number'] : null,
            'document_ref' => $row['document_ref'] !== null ? (string) $row['document_ref'] : null,
            'purchase_invoice_id' => $row['purchase_invoice_id'] !== null ? (int) $row['purchase_invoice_id'] : null,
            'cash_document_id' => $row['cash_document_id'] !== null ? (int) $row['cash_document_id'] : null,
            'vendor_name' => $this->vendorLabel($row),
            'acquisition_date' => (string) $row['acquisition_date'],
            'put_into_use_date' => $row['put_into_use_date'] !== null ? (string) $row['put_into_use_date'] : null,
            'quantity' => (float) $row['quantity'],
            'price' => (float) $row['price'],
            'location' => $row['location'] !== null ? (string) $row['location'] : null,
            'responsible_person' => $row['responsible_person'] !== null ? (string) $row['responsible_person'] : null,
            'status' => (string) $row['status'],
            'disposed_at' => $row['disposed_at'] !== null ? (string) $row['disposed_at'] : null,
            'disposal_reason' => $row['disposal_reason'] !== null ? (string) $row['disposal_reason'] : null,
        ];
    }

    /**
     * Jméno z číselníku má přednost (přejmenovaný dodavatel se propíše), snapshot je
     * fallback pro karty bez FK — u pokladního dokladu bývá jen volný text.
     *
     * @param array<string,mixed> $row
     */
    private function vendorLabel(array $row): ?string
    {
        $fromCodebook = $row['vendor_client_name'] ?? null;
        if ($fromCodebook !== null && (string) $fromCodebook !== '') {
            return (string) $fromCodebook;
        }
        return $row['vendor_name'] !== null && (string) $row['vendor_name'] !== '' ? (string) $row['vendor_name'] : null;
    }

    /**
     * Hlavička účetní jednotky pro export — shodný tvar i zdroj jako
     * JournalExportService::loadEntity(), protože ho čte tentýž
     * ReportXlsxExporter::entityHeader().
     *
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
