<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Support;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\DimensionAssignmentRepository;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Repository\JournalEntryRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\Dimension\DimensionService;
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Service\Invoice\FinalFromProformaCreator;
use PDO;
use Psr\Container\ContainerInterface;

/**
 * Doklady pro regresní test „bez nastavení produktu se účtuje bajtově stejně"
 * (Účtování podle dimenzí, F1). Každý scénář vrací řádky z builderu a u dimenzí
 * i dimenze zaúčtovaných řádků v podobě nezávislé na id (kódy účtů, typů a hodnot).
 *
 * Používá jen API, které existovalo už před F1 (master 307179b4e) — očekávané výstupy
 * v testu byly spočítány touto třídou nad tehdejším kódem. Položky jsou navázané na
 * skladovou kartu a kategorii BEZ účtu a dimenzí: vazba sama nesmí nic změnit.
 *
 * Volající drží transakci a po doběhu ji vrátí.
 */
final class PostingRegressionScenarios
{
    public const YEAR = 2094;

    private Connection $db;
    private PostingService $posting;
    private int $supplierId = 0;
    private int $currencyId = 0;
    private int $vatRateId = 0;
    private int $userId = 0;
    private int $czId = 0;
    private int $product = 0;

    public function __construct(private readonly ContainerInterface $container)
    {
        $this->db = $container->get(Connection::class);
        $this->posting = $container->get(PostingService::class);
    }

    /** @return array<string,mixed> scénář => výstup */
    public function run(): array
    {
        $pdo = $this->db->pdo();
        $this->supplierId = (int) $pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn();
        $this->currencyId = (int) $pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn();
        $this->vatRateId = (int) $pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn();
        $this->userId = (int) $pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
        $this->czId = (int) $pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn();
        $pdo->prepare("UPDATE supplier SET accounting_mode = 'double_entry' WHERE id = ?")->execute([$this->supplierId]);
        $this->container->get(ChartOfAccountsSeeder::class)->seedForSupplier($this->supplierId);
        $this->container->get(AccountingPeriodRepository::class)
            ->create($this->supplierId, self::YEAR, self::YEAR . '-01-01', self::YEAR . '-12-31');

        $pdo->prepare("INSERT INTO stock_categories (supplier_id, code, name, path, depth) VALUES (?, 'REGR-CAT', 'Regrese', '/', 0)")
            ->execute([$this->supplierId]);
        $category = (int) $pdo->lastInsertId();
        $pdo->prepare('UPDATE stock_categories SET path = ? WHERE id = ?')->execute(['/' . $category . '/', $category]);
        $pdo->prepare("INSERT INTO stock_items (supplier_id, sku, name) VALUES (?, 'REGR-SKU', 'Regresní produkt')")
            ->execute([$this->supplierId]);
        $this->product = (int) $pdo->lastInsertId();
        $pdo->prepare('INSERT INTO stock_item_categories (supplier_id, stock_item_id, category_id, is_primary) VALUES (?, ?, ?, 1)')
            ->execute([$this->supplierId, $this->product, $category]);

        $client = $this->client();
        // Dimenze se zapínají PŘED prvním zaúčtováním — razítko si stav firmy pamatuje.
        $this->container->get(DimensionService::class)->setEnabled($this->supplierId, true);
        $out = [];

        $out['invoice'] = $this->issued($this->invoice($client, 'invoice', [[1000.00, true], [333.33, false]]));
        $out['credit_note'] = $this->issued($this->invoice($client, 'credit_note', [[-800.00, true], [-200.00, false]]));
        $eur = (int) ($pdo->query("SELECT id FROM currencies WHERE code = 'EUR' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        if ($eur > 0) {
            $out['foreign_currency'] = $this->issued($this->invoice($client, 'invoice', [[100.00, true], [55.55, false]], $eur, 25.125));
        }
        $out['header_discount'] = $this->issued($this->discounted($client));
        $out['asset_item'] = $this->issued($this->assetInvoice($client));
        $out['proforma_final'] = $this->issued($this->finalFromProforma($client));

        $out['purchase_expense_kind'] = $this->received($this->purchase($client, [[1000.00, 'material'], [250.00, null]], []));
        $out['purchase_fixed_asset'] = $this->received($this->purchase($client, [[50000.00, null]], ['is_fixed_asset' => 1]));
        $out['purchase_non_deductible'] = $this->received($this->purchase($client, [[400.00, null], [100.00, 'service']], ['tax_deductible' => 0]));

        $out['dimensions'] = $this->stamped($client);
        return $out;
    }

    /** @return list<array<string,mixed>> */
    private function issued(int $invoiceId): array
    {
        return $this->posting->buildFromInvoice($this->supplierId, $invoiceId);
    }

    /** @return list<array<string,mixed>> */
    private function received(int $purchaseId): array
    {
        return $this->posting->buildFromPurchaseInvoice($this->supplierId, $purchaseId);
    }

    private function client(): int
    {
        $this->db->pdo()->prepare(
            'INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id, dic, main_email,
                                  language, currency_default_id, is_customer, is_vendor)
             VALUES (?, "Regresní klient", "Test 1", "Praha", "11000", ?, "CZ12345678", "regrese@example.invalid", "cs", ?, 1, 1)'
        )->execute([$this->supplierId, $this->czId, $this->currencyId]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    /** @param list<array{0:float,1:bool}> $items základ, navázat na produkt */
    private function invoice(int $client, string $type, array $items, ?int $currency = null, ?float $rate = null, string $status = 'issued'): int
    {
        $pdo = $this->db->pdo();
        $base = round(array_sum(array_column($items, 0)), 2);
        $vat = round(array_sum(array_map(static fn (array $i): float => round($i[0] * 0.21, 2), $items)), 2);
        $date = self::YEAR . '-06-15';
        $pdo->prepare(
            'INSERT INTO invoices
                (supplier_id, varsymbol, invoice_type, client_id, issue_date, tax_date, due_date, currency_id, exchange_rate,
                 reverse_charge, total_without_vat, total_vat, total_with_vat, status, vat_classification_code, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?, ?, "1", ?)'
        )->execute([
            $this->supplierId, 'REGR' . random_int(100000, 999999), $type, $client, $date, $date, $date,
            $currency ?? $this->currencyId, $rate, $base, $vat, round($base + $vat, 2), $status, $this->userId,
        ]);
        $id = (int) $pdo->lastInsertId();
        foreach ($items as $i => [$net, $linked]) {
            $this->item($id, $net, $i, $linked ? $this->product : null);
        }
        return $id;
    }

    private function item(int $invoiceId, float $net, int $order, ?int $product, ?int $smallAsset = null): void
    {
        $itemVat = round($net * 0.21, 2);
        $this->db->pdo()->prepare(
            'INSERT INTO invoice_items
                (invoice_id, description, quantity, unit, unit_price_without_vat, vat_rate_id, vat_rate_snapshot,
                 total_without_vat, total_vat, total_with_vat, order_index, vat_classification_code, stock_item_id, small_asset_id)
             VALUES (?, "Regresní položka", 1, "ks", ?, ?, 21.00, ?, ?, ?, ?, "1", ?, ?)'
        )->execute([$invoiceId, $net, $this->vatRateId, $net, $itemVat, round($net + $itemVat, 2), $order, $product, $smallAsset]);
    }

    private function syncTotals(int $invoiceId): void
    {
        $this->db->pdo()->prepare(
            'UPDATE invoices i
                JOIN (SELECT invoice_id, SUM(total_without_vat) b, SUM(total_vat) v, SUM(total_with_vat) w
                        FROM invoice_items WHERE invoice_id = ? GROUP BY invoice_id) s ON s.invoice_id = i.id
                SET i.total_without_vat = s.b, i.total_vat = s.v, i.total_with_vat = s.w'
        )->execute([$invoiceId]);
    }

    private function discounted(int $client): int
    {
        $id = $this->invoice($client, 'invoice', [[1.00, false]]);
        $this->db->pdo()->prepare('UPDATE invoices SET discount_percent = 10 WHERE id = ?')->execute([$id]);
        $item = fn (float $net, ?int $product): array => [
            'description' => 'Regresní položka', 'quantity' => 1, 'unit' => 'ks', 'unit_price_without_vat' => $net,
            'vat_rate_id' => $this->vatRateId, 'stock_item_id' => $product,
        ];
        $this->container->get(InvoiceRepository::class)->replaceItems($id, [$item(1000.00, $this->product), $item(505.05, null)]);
        $this->container->get(\MyInvoice\Service\Invoice\InvoiceCalculator::class)->recompute($id);
        return $id;
    }

    private function assetInvoice(int $client): int
    {
        $this->db->pdo()->prepare(
            'INSERT INTO small_assets (supplier_id, name, acquisition_date, quantity, unit_price, price, status)
             VALUES (?, "Regresní majetek", ?, 1, 5000, 5000, "in_use")'
        )->execute([$this->supplierId, self::YEAR . '-01-15']);
        $card = (int) $this->db->pdo()->lastInsertId();
        $id = $this->invoice($client, 'invoice', [[700.00, true]]);
        $this->item($id, 3000.00, 1, null, $card);
        $this->syncTotals($id);
        return $id;
    }

    private function finalFromProforma(int $client): int
    {
        $pdo = $this->db->pdo();
        $proforma = $this->invoice($client, 'proforma', [[1000.00, true], [500.00, false]]);
        $taxDoc = $this->invoice($client, 'tax_document', [[600.00, false]]);
        $pdo->prepare('UPDATE invoices SET parent_invoice_id = ? WHERE id = ?')->execute([$proforma, $taxDoc]);
        // Přijatá záloha (pokladna) a zaúčtovaný daňový doklad k ní — bez nich vyúčtování
        // zálohy nejde zaúčtovat.
        $pdo->prepare('INSERT INTO cash_registers (supplier_id, name, account_code, is_default) VALUES (?, "Regresní pokladna", "211", 0)')
            ->execute([$this->supplierId]);
        $register = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO cash_documents (supplier_id, register_id, doc_type, purpose, doc_number, issue_date, tax_date,
                                         description, vat_mode, total_amount, status, invoice_id, created_by)
             VALUES (?, ?, "in", "invoice_payment", "REGR-P1", ?, ?, "Záloha", "none", 726.00, "posted", ?, ?)'
        )->execute([$this->supplierId, $register, self::YEAR . '-06-10', self::YEAR . '-06-10', $proforma, $this->userId]);
        $cash = (int) $pdo->lastInsertId();
        $this->posting->postDocument($this->supplierId, 'cash', $cash, [
            ['account_code' => '211', 'side' => 'debit', 'amount' => 726.00],
            ['account_code' => '324', 'side' => 'credit', 'amount' => 726.00],
        ], ['entry_date' => self::YEAR . '-06-10', 'posted_by' => $this->userId]);
        $this->posting->postDocument(
            $this->supplierId,
            'invoice',
            $taxDoc,
            $this->posting->buildFromInvoice($this->supplierId, $taxDoc),
            ['entry_date' => self::YEAR . '-06-15', 'posted_by' => $this->userId],
        );
        $final = $this->container->get(FinalFromProformaCreator::class)
            ->create($proforma, $this->userId, self::YEAR . '-06-20', self::YEAR . '-06-30');
        $this->container->get(\MyInvoice\Service\Invoice\InvoiceCalculator::class)->recompute($final);
        $pdo->prepare("UPDATE invoices SET status = 'issued' WHERE id = ?")->execute([$final]);
        return $final;
    }

    /**
     * @param list<array{0:float,1:?string}> $items základ, druh výdaje
     * @param array<string,int> $header
     */
    private function purchase(int $vendor, array $items, array $header): int
    {
        $pdo = $this->db->pdo();
        $base = round(array_sum(array_column($items, 0)), 2);
        $vat = round(array_sum(array_map(static fn (array $i): float => round($i[0] * 0.21, 2), $items)), 2);
        $date = self::YEAR . '-06-15';
        $pdo->prepare(
            'INSERT INTO purchase_invoices
                (supplier_id, vendor_id, vendor_invoice_number, document_kind, issue_date, tax_date, due_date, received_at,
                 currency_id, reverse_charge, vendor_snapshot, total_without_vat, total_vat, total_with_vat, status,
                 vat_classification_code, vat_deduction, is_fixed_asset, tax_deductible, created_by)
             VALUES (?, ?, ?, "invoice", ?, ?, ?, ?, ?, 0, "{}", ?, ?, ?, "received", "40", "full", ?, ?, ?)'
        )->execute([
            $this->supplierId, $vendor, 'REGR-PF' . random_int(100000, 999999), $date, $date, $date, $date,
            $this->currencyId, $base, $vat, round($base + $vat, 2),
            $header['is_fixed_asset'] ?? 0, $header['tax_deductible'] ?? 1, $this->userId,
        ]);
        $id = (int) $pdo->lastInsertId();
        foreach ($items as $i => [$net, $kind]) {
            $itemVat = round($net * 0.21, 2);
            $pdo->prepare(
                'INSERT INTO purchase_invoice_items
                    (purchase_invoice_id, description, quantity, unit, unit_price_without_vat, vat_rate_id, vat_rate_snapshot,
                     total_without_vat, total_vat, total_with_vat, order_index, stock_item_id, expense_kind, expense_classification_source)
                 VALUES (?, "Regresní nákup", 1, "ks", ?, ?, 21.00, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([$id, $net, $this->vatRateId, $net, $itemVat, round($net + $itemVat, 2), $i, $this->product, $kind, $kind !== null ? 'rule' : null]);
        }
        return $id;
    }

    /** @return list<array{account:string, side:string, amount:string, dims:array<string,string>}> */
    private function stamped(int $client): array
    {
        $dimensions = $this->container->get(DimensionService::class);
        $dimensions->setEnabled($this->supplierId, true);
        $types = $dimensions->ensureDefaultTypes($this->supplierId, ['projekt', 'stredisko']);
        $value = fn (int $type, string $code): int => (int) $dimensions->createValue($this->supplierId, $type, ['code' => $code, 'name' => $code])['id'];
        $center = $value($types['cost_center'], 'REGR-C1');
        $center2 = $value($types['cost_center'], 'REGR-C2');
        $project = $value($types['project'], 'REGR-P1');
        $dimensions->saveEntityDefaults($this->supplierId, 'client', $client, [$types['cost_center'] => $center]);

        $id = $this->invoice($client, 'invoice', [[600.00, true], [400.00, false], [0.01, true]]);
        $dimensions->saveDocument($this->supplierId, 'invoice', $id, [$types['project'] => $project], [2 => [$types['cost_center'] => $center2]]);
        $entryId = $this->posting->postDocument(
            $this->supplierId,
            'invoice',
            $id,
            $this->posting->buildFromInvoice($this->supplierId, $id),
            ['entry_date' => self::YEAR . '-06-15', 'posted_by' => $this->userId],
        );

        $pdo = $this->db->pdo();
        $codes = static function (PDO $pdo, string $sql): array {
            return array_map('strval', $pdo->query($sql)->fetchAll(PDO::FETCH_KEY_PAIR));
        };
        $typeCodes = $codes($pdo, 'SELECT id, code FROM dimension_types WHERE supplier_id = ' . $this->supplierId);
        $valueCodes = $codes($pdo, 'SELECT id, code FROM dimension_values WHERE supplier_id = ' . $this->supplierId);
        $accountCodes = $codes($pdo, 'SELECT id, account_code FROM chart_of_accounts WHERE supplier_id = ' . $this->supplierId);
        $dims = $this->container->get(DimensionAssignmentRepository::class)->entryLineDimensions($this->supplierId, $entryId);
        $out = [];
        foreach ($this->container->get(JournalEntryRepository::class)->linesForEntry($entryId, $this->supplierId) as $line) {
            $named = [];
            foreach ($dims[(int) $line['id']] ?? [] as $typeId => $valueId) {
                $named[$typeCodes[$typeId] ?? (string) $typeId] = $valueCodes[$valueId] ?? (string) $valueId;
            }
            ksort($named);
            $out[] = [
                'account' => $accountCodes[(int) $line['account_id']] ?? '?',
                'side' => (string) $line['side'],
                'amount' => number_format((float) $line['amount'], 2, '.', ''),
                'dims' => $named,
            ];
        }
        return $out;
    }
}
