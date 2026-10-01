<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Support;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\DimensionAssignmentRepository;
use MyInvoice\Repository\JournalEntryRepository;
use MyInvoice\Service\Accounting\Assets\AssetService;
use MyInvoice\Service\Accounting\Assets\DepreciationPostingService;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\Dimension\DimensionService;
use MyInvoice\Service\Accounting\InvoiceSettlementService;
use MyInvoice\Service\Accounting\OffsetService;
use MyInvoice\Service\Accounting\OtherItemService;
use MyInvoice\Service\Accounting\PostingService;
use PDO;
use Psr\Container\ContainerInterface;

/**
 * Doklady mimo faktury, banku a pokladnu pro regresní test „bez nastavení se účtuje
 * bajtově stejně" (Účtování podle dimenzí, F4): ostatní pohledávka a závazek, vzájemný
 * zápočet, zápočet proti účtu a životní cyklus majetku (zařazení, odpis, vyřazení).
 *
 * Firma má dimenze zapnuté a založené typy i hodnoty, ale doklady, karty ani klienti
 * žádné dimenze nenesou. Výstup jsou řádky zaúčtovaných zápisů s dimenzemi v podobě
 * nezávislé na id. Používá jen API, které existovalo už před F4 (master c844a173a) —
 * očekávaný výstup testu byl spočítán touto třídou nad tehdejším kódem.
 *
 * `$prepare` (volitelně) dostane kontext před zaúčtováním — testy funkce F4 jím
 * nastaví dimenze dokladů a karet a ověří, že se promítnou.
 *
 * Volající drží transakci a po doběhu ji vrátí.
 */
final class OtherDocumentsPostingScenarios
{
    use IsolatedSupplierTrait;

    public const YEAR = 2091;

    private Connection $db;
    private PostingService $posting;
    public int $supplierId = 0;
    private int $currencyId = 0;
    private int $userId = 0;
    private int $czId = 0;

    public function __construct(private readonly ContainerInterface $container)
    {
        $this->db = $container->get(Connection::class);
        $this->posting = $container->get(PostingService::class);
    }

    /**
     * @param (callable(string $scenario, int $docId, self $ctx): void)|null $prepare
     * @return array<string,mixed> scénář => řádky zápisů
     */
    public function run(?callable $prepare = null): array
    {
        $this->setUp();
        $prepare ??= static function (): void {};
        $out = [];

        $client = $this->client('Regresní partner');
        $other = $this->container->get(OtherItemService::class);
        $receivable = $other->create($this->supplierId, [
            'side' => 'receivable', 'kind' => 'claim', 'title' => 'Regresní pohledávka', 'partner_id' => $client,
            'issued_on' => self::YEAR . '-02-01', 'due_on' => self::YEAR . '-02-28', 'currency' => 'CZK', 'amount' => 1000,
            'posting_lines' => [['account_code' => '602', 'amount' => 600], ['account_code' => '648', 'amount' => 400]],
        ], $this->userId);
        $prepare('other_receivable', (int) $receivable['id'], $this);
        $out['other_receivable'] = $this->entryLines((int) $other->post($this->supplierId, (int) $receivable['id'], $this->userId)['journal_entry_id']);

        $payable = $other->create($this->supplierId, [
            'side' => 'payable', 'kind' => 'rent', 'title' => 'Regresní nájem', 'partner_id' => $client,
            'issued_on' => self::YEAR . '-02-01', 'due_on' => self::YEAR . '-02-20', 'currency' => 'CZK', 'amount' => 1200,
            'counter_account_code' => '518',
        ], $this->userId);
        $prepare('other_payable', (int) $payable['id'], $this);
        $out['other_payable'] = $this->entryLines((int) $other->post($this->supplierId, (int) $payable['id'], $this->userId)['journal_entry_id']);

        $fv = $this->invoice($client, 10000.00);
        $pf = $this->purchaseInvoice($client, 6000.00);
        $offsets = $this->container->get(OffsetService::class);
        $agreement = $offsets->create($this->supplierId, $client, self::YEAR . '-04-01', [
            ['doc_type' => 'invoice', 'doc_id' => $fv, 'amount' => 6000.00],
            ['doc_type' => 'purchase_invoice', 'doc_id' => $pf, 'amount' => 6000.00],
        ], 'Regresní zápočet', $this->userId);
        $agreementId = (int) $agreement['agreement']['id'];
        $prepare('offset', $agreementId, $this);
        $offsets->confirm($this->supplierId, $agreementId, ['posted_by' => $this->userId, 'user_id' => $this->userId]);
        $out['offset'] = $this->sourceLines('offset', $agreementId);

        $settled = $this->invoice($client, 2420.00);
        $prepare('settlement', $settled, $this);
        $settlement = $this->container->get(InvoiceSettlementService::class)->create($this->supplierId, 'invoice', $settled, [
            'settled_on' => self::YEAR . '-05-31', 'amount' => 2420.00, 'account_id' => $this->accountId('355'),
        ], $this->userId);
        $out['settlement'] = $this->entryLines((int) $settlement['journal_entry_id']);

        $assets = $this->container->get(AssetService::class);
        $asset = (int) $assets->create($this->supplierId, [
            'inventory_number' => 'REGR-M1', 'name' => 'Regresní stroj', 'input_price' => 120000.00,
            'acquisition_date' => self::YEAR . '-01-15', 'tax_method' => 'straight', 'tax_group' => 2,
            'acc_useful_life_months' => 60,
        ], ['user_id' => $this->userId])['asset']['id'];
        $prepare('asset', $asset, $this);
        $meta = ['user_id' => $this->userId, 'posted_by' => $this->userId];
        $assets->putIntoUse($this->supplierId, $asset, self::YEAR . '-01-15', true, $meta);
        $out['asset_in_use'] = $this->sourceLines('asset', $asset);
        $this->container->get(DepreciationPostingService::class)->bookYear($this->supplierId, self::YEAR, $meta);
        $depreciation = (int) $this->db->pdo()->query(
            "SELECT id FROM depreciation_entries WHERE asset_id = {$asset} AND kind = 'accounting' AND fiscal_year = " . self::YEAR
        )->fetchColumn();
        $out['depreciation'] = $this->sourceLines('depreciation', $depreciation);
        $assets->dispose($this->supplierId, $asset, ['date' => (self::YEAR + 1) . '-03-10', 'type' => 'liquidated'], $meta);
        $out['depreciation_disposal_year'] = $this->sourceLines('depreciation', (int) $this->db->pdo()->query(
            "SELECT id FROM depreciation_entries WHERE asset_id = {$asset} AND kind = 'accounting' AND fiscal_year = " . (self::YEAR + 1)
        )->fetchColumn());
        $out['asset_disposal'] = $this->sourceLines('asset_disposal', $asset);
        return $out;
    }

    /** @return array<string,int> kód typu => id typu */
    public function types(): array
    {
        return $this->container->get(DimensionService::class)->ensureDefaultTypes($this->supplierId, ['projekt', 'stredisko']);
    }

    public function value(int $typeId, string $code): int
    {
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare('SELECT id FROM dimension_values WHERE type_id = ? AND code = ?');
        $stmt->execute([$typeId, $code]);
        $id = $stmt->fetchColumn();
        if ($id !== false) {
            return (int) $id;
        }
        return (int) $this->container->get(DimensionService::class)
            ->createValue($this->supplierId, $typeId, ['code' => $code, 'name' => $code])['id'];
    }

    private function setUp(): void
    {
        $pdo = $this->db->pdo();
        $source = (int) $pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $source);
        $this->currencyId = (int) $pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn();
        $this->userId = (int) $pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
        $this->czId = (int) $pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn();
        $pdo->prepare("UPDATE supplier SET accounting_mode = 'double_entry', supplier_group_id = NULL WHERE id = ?")
            ->execute([$this->supplierId]);
        $this->container->get(ChartOfAccountsSeeder::class)->seedForSupplier($this->supplierId);
        $periods = $this->container->get(AccountingPeriodRepository::class);
        $periods->create($this->supplierId, self::YEAR, self::YEAR . '-01-01', self::YEAR . '-12-31');
        $periods->create($this->supplierId, self::YEAR + 1, (self::YEAR + 1) . '-01-01', (self::YEAR + 1) . '-12-31');
        // Dimenze zapnuté a založené PŘED prvním zaúčtováním, jen žádný doklad je nenese.
        $this->container->get(DimensionService::class)->setEnabled($this->supplierId, true);
        $types = $this->types();
        $this->value($types['cost_center'], 'REGR-C1');
        $this->value($types['project'], 'REGR-P1');
    }

    private function client(string $name): int
    {
        $this->db->pdo()->prepare(
            'INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id, dic, main_email,
                                  language, currency_default_id, is_customer, is_vendor)
             VALUES (?, ?, "Test 1", "Praha", "11000", ?, "CZ12345678", "regrese@example.invalid", "cs", ?, 1, 1)'
        )->execute([$this->supplierId, $name, $this->czId, $this->currencyId]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    private function invoice(int $client, float $total): int
    {
        $pdo = $this->db->pdo();
        $date = self::YEAR . '-03-10';
        $pdo->prepare(
            'INSERT INTO invoices
                (supplier_id, varsymbol, invoice_type, client_id, issue_date, tax_date, due_date,
                 currency_id, reverse_charge, total_without_vat, total_vat, total_with_vat,
                 paid_total, status, vat_classification_code, created_by)
             VALUES (?, ?, "invoice", ?, ?, ?, ?, ?, 0, ?, 0, ?, 0, "issued", "1", ?)'
        )->execute([$this->supplierId, (string) random_int(1000000000, 1999999999), $client, $date, $date, $date,
            $this->currencyId, $total, $total, $this->userId]);
        $id = (int) $pdo->lastInsertId();
        $this->posting->postDocument($this->supplierId, 'invoice', $id, [
            ['account_code' => '311', 'side' => 'debit', 'amount' => $total],
            ['account_code' => '602', 'side' => 'credit', 'amount' => $total],
        ], ['entry_date' => $date, 'posted_by' => $this->userId]);
        return $id;
    }

    private function purchaseInvoice(int $vendor, float $total): int
    {
        $pdo = $this->db->pdo();
        $date = self::YEAR . '-03-11';
        $vs = 'PF-' . random_int(1000000000, 1999999999);
        $pdo->prepare(
            'INSERT INTO purchase_invoices
                (supplier_id, varsymbol, vendor_id, vendor_invoice_number, document_kind,
                 issue_date, due_date, received_at, currency_id, vendor_snapshot, total_with_vat, status, created_by)
             VALUES (?, ?, ?, ?, "invoice", ?, ?, ?, ?, "{}", ?, "received", ?)'
        )->execute([$this->supplierId, $vs, $vendor, $vs, $date, $date, $date, $this->currencyId, $total, $this->userId]);
        $id = (int) $pdo->lastInsertId();
        $this->posting->postDocument($this->supplierId, 'purchase_invoice', $id, [
            ['account_code' => '518', 'side' => 'debit', 'amount' => $total],
            ['account_code' => '321', 'side' => 'credit', 'amount' => $total],
        ], ['entry_date' => $date, 'posted_by' => $this->userId]);
        return $id;
    }

    private function accountId(string $code): int
    {
        $stmt = $this->db->pdo()->prepare('SELECT id FROM chart_of_accounts WHERE supplier_id = ? AND account_code = ?');
        $stmt->execute([$this->supplierId, $code]);
        return (int) $stmt->fetchColumn();
    }

    /** @return list<array{account:string, side:string, amount:string, dims:array<string,string>, splits:array<string,array<string,float>>}> */
    private function sourceLines(string $sourceType, int $sourceId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id FROM journal_entries WHERE supplier_id = ? AND source_type = ? AND source_id = ? AND reversed_by IS NULL ORDER BY id'
        );
        $stmt->execute([$this->supplierId, $sourceType, $sourceId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $entryId) {
            array_push($out, ...$this->entryLines((int) $entryId));
        }
        return $out;
    }

    /** @return list<array{account:string, side:string, amount:string, dims:array<string,string>, splits:array<string,array<string,float>>}> */
    public function entryLines(int $entryId): array
    {
        $pdo = $this->db->pdo();
        $codes = static fn (string $sql): array => array_map('strval', $pdo->query($sql)->fetchAll(PDO::FETCH_KEY_PAIR));
        $typeCodes = $codes('SELECT id, code FROM dimension_types WHERE supplier_id = ' . $this->supplierId);
        $valueCodes = $codes('SELECT id, code FROM dimension_values WHERE supplier_id = ' . $this->supplierId);
        $accountCodes = $codes('SELECT id, account_code FROM chart_of_accounts WHERE supplier_id = ' . $this->supplierId);
        $assignments = $this->container->get(DimensionAssignmentRepository::class);
        $dims = $assignments->entryLineDimensions($this->supplierId, $entryId);
        $splits = $assignments->entryLineSplits($this->supplierId, $entryId);
        $out = [];
        foreach ($this->container->get(JournalEntryRepository::class)->linesForEntry($entryId, $this->supplierId) as $line) {
            $named = [];
            foreach ($dims[(int) $line['id']] ?? [] as $typeId => $valueId) {
                $named[$typeCodes[$typeId] ?? (string) $typeId] = $valueCodes[$valueId] ?? (string) $valueId;
            }
            ksort($named);
            $namedSplits = [];
            foreach ($splits[(int) $line['id']] ?? [] as $typeId => $shares) {
                foreach ($shares as $valueId => $share) {
                    $namedSplits[$typeCodes[$typeId] ?? (string) $typeId][$valueCodes[$valueId] ?? (string) $valueId] = round((float) $share, 6);
                }
            }
            ksort($namedSplits);
            $out[] = [
                'account' => $accountCodes[(int) $line['account_id']] ?? '?',
                'side' => (string) $line['side'],
                'amount' => number_format((float) $line['amount'], 2, '.', ''),
                'dims' => $named,
                'splits' => $namedSplits,
            ];
        }
        return $out;
    }
}
