<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Action\Accounting\JournalAction;
use MyInvoice\Action\Bank\BankStatementAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\DimensionAssignmentRepository;
use MyInvoice\Repository\JournalEntryRepository;
use MyInvoice\Service\Accounting\Bank\BankPostingService;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\Dimension\DimensionService;
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Service\Accounting\Reports\JournalExportService;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

/**
 * Dimenze v účetním deníku a na bankovních pohybech (Firma → Dimenze).
 *
 *  • Filtr deníku na hodnotu dimenze: zápis projde, nese-li hodnotu (nebo podřízenou)
 *    aspoň jeden jeho řádek; stejné parametry i sémantika jako filtr sestav, platí
 *    i pro export.
 *  • Bankovní pohyb: dimenze jdou uložit i u výpisu, který firmě patří přes číslo
 *    účtu (bez supplier_id), zaúčtování pohybu je orazítkuje do řádků deníku a detail
 *    výpisu je vrací u každého pohybu.
 *
 * Vše v jedné transakci nad izolovanou firmou, tearDown rollbackne.
 */
#[Group('integration')]
final class DimensionJournalBankTest extends TestCase
{
    use IsolatedSupplierTrait;

    private const YEAR = 2096;
    private const ACCOUNT = '3000000004';

    private Connection $db;
    private JournalAction $journalAction;
    private BankStatementAction $statementAction;
    private JournalEntryRepository $journal;
    private JournalExportService $export;
    private PostingService $posting;
    private DimensionService $dimensions;
    private DimensionAssignmentRepository $assignments;
    private BankPostingService $bankPosting;

    private int $supplierId = 0;
    private int $userId = 0;
    private int $projectType = 0;
    private int $centerType = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $container = Bootstrap::buildApp()->getContainer();
            $this->db = $container->get(Connection::class);
            $this->journalAction = $container->get(JournalAction::class);
            $this->statementAction = $container->get(BankStatementAction::class);
            $this->journal = $container->get(JournalEntryRepository::class);
            $this->export = $container->get(JournalExportService::class);
            $this->posting = $container->get(PostingService::class);
            $this->dimensions = $container->get(DimensionService::class);
            $this->assignments = $container->get(DimensionAssignmentRepository::class);
            $this->bankPosting = $container->get(BankPostingService::class);
            $periods = $container->get(AccountingPeriodRepository::class);
            $seeder = $container->get(ChartOfAccountsSeeder::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $sourceSupplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($sourceSupplierId === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí základní data (supplier/user) v DB.');
        }

        $pdo->beginTransaction();
        $this->inTx = true;
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $pdo->prepare("UPDATE supplier SET accounting_mode = 'double_entry', supplier_group_id = NULL WHERE id = ?")
            ->execute([$this->supplierId]);
        $seeder->seedForSupplier($this->supplierId);
        $periods->create($this->supplierId, self::YEAR, self::YEAR . '-01-01', self::YEAR . '-12-31');
        $this->dimensions->setEnabled($this->supplierId, true);
        $types = $this->dimensions->ensureDefaultTypes($this->supplierId, ['projekt', 'stredisko']);
        $this->projectType = $types['project'];
        $this->centerType = $types['cost_center'];
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->inTx) {
            $pdo = $this->db->pdo();
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $this->db->close();
        }
    }

    // ── deník ────────────────────────────────────────────────────────────────

    public function testJournalListFiltersByDimensionValueIncludingDescendants(): void
    {
        $parent = $this->value($this->projectType, 'J-ROOT');
        $child = $this->value($this->projectType, 'J-CHILD', $parent);
        $other = $this->value($this->projectType, 'J-OTHER');
        $onParent = $this->entryWithExpenseDimension([$this->projectType => $parent], 100.00);
        $onChild = $this->entryWithExpenseDimension([$this->projectType => $child], 200.00);
        $onOther = $this->entryWithExpenseDimension([$this->projectType => $other], 300.00);
        $plain = $this->entryWithExpenseDimension([], 400.00);

        $all = $this->listIds([]);
        foreach ([$onParent, $onChild, $onOther, $plain] as $id) {
            self::assertContains($id, $all, 'Bez filtru je v deníku každý zápis.');
        }

        $branch = $this->call('list', ['dimension_value_id' => (string) $parent]);
        self::assertSame(200, $branch['status']);
        self::assertEqualsCanonicalizing([$onParent, $onChild], array_column($branch['body']['items'], 'id'),
            'Nadřízená hodnota bere celou větev, ne cizí projekt ani zápis bez dimenze.');
        self::assertSame(2, $branch['body']['total']);
        $entry = $this->findItem($branch['body']['items'], $onChild);
        self::assertEqualsWithDelta(200.00, (float) $entry['amount'], 0.001, 'Filtr zobrazí celý zápis, ne jen řádek s dimenzí.');

        self::assertEqualsCanonicalizing([$onParent], $this->listIds([
            'dimension_value_id' => (string) $parent,
            'dimension_descendants' => '0',
        ]), 'Bez podřízených jen hodnota sama.');
        self::assertEqualsCanonicalizing([$onOther], $this->listIds(['dimension_value_id' => (string) $other]));
    }

    public function testJournalFilterCombinesWithOtherFiltersAndExport(): void
    {
        $project = $this->value($this->projectType, 'J-EXP');
        $march = $this->entryWithExpenseDimension([$this->projectType => $project], 150.00, self::YEAR . '-03-10');
        $june = $this->entryWithExpenseDimension([$this->projectType => $project], 250.00, self::YEAR . '-06-10');
        $this->entryWithExpenseDimension([], 350.00, self::YEAR . '-06-11');

        self::assertEqualsCanonicalizing([$june], $this->listIds([
            'dimension_value_id' => (string) $project,
            'date_from' => self::YEAR . '-06-01',
        ]), 'Dimenze se sčítá s ostatními filtry deníku.');

        $filter = $this->dimensions->filter($this->supplierId, $project);
        $data = $this->export->build($this->supplierId, ['dimension' => $filter]);
        self::assertEqualsCanonicalizing([$march, $june], array_column($data['entries'], 'id'), 'Export respektuje filtr dimenze.');
    }

    public function testJournalFilterCountsCostCentreTextOnLinesWithoutDimension(): void
    {
        $center = $this->dimensions->createValue($this->supplierId, $this->centerType, ['code' => 'J-REZ', 'name' => 'Režie']);
        $payroll = (int) $this->posting->postDocument($this->supplierId, 'manual', null, [
            ['account_code' => '521', 'side' => 'debit', 'amount' => 1_000.00, 'cost_center' => 'J-REZ'],
            ['account_code' => '331', 'side' => 'credit', 'amount' => 1_000.00],
        ], ['entry_date' => self::YEAR . '-04-30', 'posted_by' => $this->userId]);
        $this->entryWithExpenseDimension([], 50.00);

        self::assertSame([$payroll], $this->listIds(['dimension_value_id' => (string) $center['id']]),
            'Mzdový řádek s textovým kódem střediska patří pod navázanou hodnotu jako v sestavách.');
    }

    public function testJournalFilterRejectsForeignOrUnknownValue(): void
    {
        $res = $this->call('list', ['dimension_value_id' => '999999999']);
        self::assertSame(404, $res['status'], 'Neznámá hodnota je chyba, ne tiše nefiltrovaný deník.');
    }

    // ── banka ────────────────────────────────────────────────────────────────

    public function testBankTransactionDimensionsSaveOnAccountOwnedStatementAndStampPosting(): void
    {
        $project = $this->value($this->projectType, 'B-PRJ');
        $moved = $this->value($this->projectType, 'B-MOVED');
        [$statementId, $txId] = $this->accountOwnedTransaction(-1_210.00);

        // Výpis nemá supplier_id, firmě patří přes číslo účtu — jako v detailu výpisu.
        $saved = $this->dimensions->saveDocument($this->supplierId, 'bank_transaction', $txId, [$this->projectType => $project], []);
        self::assertSame([$this->projectType => $project], $saved['header']);

        $entryId = $this->posting->postDocument($this->supplierId, 'bank', $txId, [
            ['account_code' => '518', 'side' => 'debit', 'amount' => 1_210.00],
            ['account_code' => '221', 'side' => 'credit', 'amount' => 1_210.00],
        ], ['entry_date' => self::YEAR . '-05-05', 'posted_by' => $this->userId]);
        $lineDims = $this->assignments->entryLineDimensions($this->supplierId, $entryId);
        $lines = $this->journal->linesForEntry($entryId, $this->supplierId);
        self::assertCount(2, $lines);
        foreach ($lines as $line) {
            self::assertSame([$this->projectType => $project], $lineDims[$line['id']] ?? [], 'Zaúčtování pohybu nese jeho dimenzi.');
        }
        self::assertSame([$entryId], $this->listIds(['dimension_value_id' => (string) $project]));

        $restamped = $this->dimensions->saveDocument($this->supplierId, 'bank_transaction', $txId, [$this->projectType => $moved], []);
        self::assertSame(2, $restamped['restamp']['lines'], 'Změna dimenze pohybu přerazítkuje už zaúčtované řádky.');
        self::assertSame([$entryId], $this->listIds(['dimension_value_id' => (string) $moved]));
        self::assertSame([], $this->listIds(['dimension_value_id' => (string) $project]));

        $detail = $this->statementDetail($statementId);
        self::assertSame(200, $detail['status']);
        $tx = $this->findItem($detail['body']['transactions'], $txId);
        self::assertNotNull($tx);
        self::assertSame([(string) $this->projectType => $moved], $tx['dimensions'], 'Detail výpisu vrací dimenze pohybu pro štítky v řádku.');
    }

    public function testPaymentFollowsLaterDimensionChangeOfPaidInvoice(): void
    {
        $first = $this->value($this->centerType, 'U-FIRST');
        $moved = $this->value($this->centerType, 'U-MOVED');
        $purchase = $this->advancePurchase('U-PF', 1_000.00);
        $this->dimensions->saveDocument($this->supplierId, 'purchase_invoice', $purchase, [$this->centerType => $first], []);
        [, $txId] = $this->accountOwnedTransaction(-1_000.00);
        $this->match($txId, [$purchase => 1_000.00]);
        $entryId = $this->postPayment($txId, [1_000.00]);
        self::assertSame([$first], $this->centersOf($entryId), 'Úhrada převezme středisko placené faktury.');

        $saved = $this->dimensions->saveDocument($this->supplierId, 'purchase_invoice', $purchase, [$this->centerType => $moved], []);
        self::assertSame([$moved], $this->centersOf($entryId), 'Změna střediska faktury se promítne i do zaúčtované úhrady.');
        self::assertSame(2, $saved['restamp']['lines']);
    }

    public function testRematchToOtherInvoiceWithSameAmountRestampsPayment(): void
    {
        $first = $this->value($this->centerType, 'R-FIRST');
        $other = $this->value($this->centerType, 'R-OTHER');
        $a = $this->advancePurchase('R-PF-A', 800.00);
        $b = $this->advancePurchase('R-PF-B', 800.00);
        $this->dimensions->saveDocument($this->supplierId, 'purchase_invoice', $a, [$this->centerType => $first], []);
        $this->dimensions->saveDocument($this->supplierId, 'purchase_invoice', $b, [$this->centerType => $other], []);
        [, $txId] = $this->accountOwnedTransaction(-800.00);
        $this->match($txId, [$a => 800.00]);
        $this->db->pdo()->prepare(
            "INSERT INTO auto_posting_policy (supplier_id, operation_type, level, updated_by)
             VALUES (?, 'bank.payment.matched', 'auto', ?)
             ON DUPLICATE KEY UPDATE level = 'auto'"
        )->execute([$this->supplierId, $this->userId]);
        $entryId = (int) $this->bankPosting->postMatched($this->supplierId, $txId, $this->userId);
        self::assertGreaterThan(0, $entryId);
        self::assertSame([$first], $this->centersOf($entryId));

        $this->db->pdo()->prepare('DELETE FROM payment_matches WHERE bank_transaction_id = ?')->execute([$txId]);
        $this->match($txId, [$b => 800.00]);
        self::assertSame($entryId, (int) $this->bankPosting->postMatched($this->supplierId, $txId, $this->userId), 'Stejné účty a částka = tentýž zápis.');
        self::assertSame([$other], $this->centersOf($entryId), 'Přepárování na jinou fakturu přenese její středisko.');
    }

    public function testPaymentOfSeveralInvoicesCarriesEachInvoiceDimension(): void
    {
        $project = $this->value($this->projectType, 'M-PRJ');
        $a = $this->value($this->centerType, 'M-A');
        $b = $this->value($this->centerType, 'M-B');
        $c = $this->value($this->centerType, 'M-C');
        $pfA = $this->advancePurchase('M-PF-A', 600.00);
        $pfB = $this->advancePurchase('M-PF-B', 400.00);
        $this->dimensions->saveDocument($this->supplierId, 'purchase_invoice', $pfA, [$this->centerType => $a, $this->projectType => $project], []);
        $this->dimensions->saveDocument($this->supplierId, 'purchase_invoice', $pfB, [$this->centerType => $b, $this->projectType => $project], []);
        [, $txId] = $this->accountOwnedTransaction(-1_000.00);
        $this->match($txId, [$pfA => 600.00, $pfB => 400.00]);
        $entryId = $this->postPayment($txId, [600.00, 400.00]);

        $lines = $this->linesByAmount($entryId);
        self::assertEquals([$this->projectType => $project, $this->centerType => $a], $lines['debit|600.00']['dims']);
        self::assertEquals([$this->projectType => $project, $this->centerType => $b], $lines['debit|400.00']['dims']);
        self::assertSame([$this->projectType => $project], $lines['credit|1000.00']['dims'], 'Společný projekt nese i banka.');
        self::assertEqualsWithDelta([$a => 0.6, $b => 0.4], $lines['credit|1000.00']['splits'][$this->centerType] ?? [], 1e-9,
            'Banka nese středisko jako rozpad v poměru alokací.');

        $this->dimensions->saveDocument($this->supplierId, 'purchase_invoice', $pfA, [$this->centerType => $c, $this->projectType => $project], []);
        $lines = $this->linesByAmount($entryId);
        self::assertEquals([$this->projectType => $project, $this->centerType => $c], $lines['debit|600.00']['dims']);
        self::assertEquals([$this->projectType => $project, $this->centerType => $b], $lines['debit|400.00']['dims']);
        self::assertEqualsWithDelta([$c => 0.6, $b => 0.4], $lines['credit|1000.00']['splits'][$this->centerType] ?? [], 1e-9);
    }

    public function testStatementDetailOmitsDimensionsWhenDisabled(): void
    {
        [$statementId, $txId] = $this->accountOwnedTransaction(500.00);
        $this->dimensions->setEnabled($this->supplierId, false);

        $detail = $this->statementDetail($statementId);
        self::assertSame(200, $detail['status']);
        $tx = $this->findItem($detail['body']['transactions'], $txId);
        self::assertNotNull($tx);
        self::assertArrayNotHasKey('dimensions', $tx, 'Vypnuté dimenze se v seznamu neposílají.');
    }

    // ── pomocné ──────────────────────────────────────────────────────────────

    private function value(int $typeId, string $code, ?int $parentId = null): int
    {
        return (int) $this->dimensions->createValue($this->supplierId, $typeId, [
            'code' => $code, 'name' => 'Hodnota ' . $code, 'parent_id' => $parentId,
        ])['id'];
    }

    /** @param array<int,int> $dims dimenze nákladového řádku (protistrana 211 je bez dimenze) */
    private function entryWithExpenseDimension(array $dims, float $amount, string $date = self::YEAR . '-05-15'): int
    {
        $expense = ['account_code' => '518', 'side' => 'debit', 'amount' => $amount];
        if ($dims !== []) {
            $expense['dimensions'] = $this->dimensions->normalize($this->supplierId, $dims);
        }
        return (int) $this->posting->postDocument($this->supplierId, 'manual', null, [
            $expense,
            ['account_code' => '211', 'side' => 'credit', 'amount' => $amount],
        ], ['entry_date' => $date, 'posted_by' => $this->userId]);
    }

    /** @return array{0:int,1:int} výpis a pohyb */
    private function accountOwnedTransaction(float $amount): array
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            "INSERT INTO currencies
                (supplier_id, code, label, symbol, name_cs, name_en, decimals, is_active, is_default, account_number, bank_code)
             VALUES (?, 'CZK', 'Běžný účet', 'Kč', 'Česká koruna', 'Czech koruna', 2, 1, 1, ?, '0100')"
        )->execute([$this->supplierId, self::ACCOUNT]);
        $pdo->prepare(
            "INSERT INTO bank_statements
                (file_name, file_hash, account_number, bank_code, currency, statement_date, transaction_count, matched_count)
             VALUES (?, ?, ?, '0100', 'CZK', ?, 1, 0)"
        )->execute(['dim-' . uniqid() . '.gpc', sha1(uniqid('', true)), self::ACCOUNT, self::YEAR . '-05-05']);
        $statementId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            "INSERT INTO bank_transactions (statement_id, posted_at, amount, currency, description)
             VALUES (?, ?, ?, 'CZK', 'Pohyb s dimenzí')"
        )->execute([$statementId, self::YEAR . '-05-05', $amount]);
        return [$statementId, (int) $pdo->lastInsertId()];
    }

    /** Zálohová přijatá faktura: úhrada jde na 314 bez zaúčtovaného předpisu. */
    private function advancePurchase(string $number, float $total): int
    {
        $pdo = $this->db->pdo();
        $currencyId = (int) $pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn();
        $countryId = (int) $pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn();
        $pdo->prepare(
            'INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id, dic, main_email,
                                  language, currency_default_id, is_customer, is_vendor)
             VALUES (?, ?, "Test 1", "Praha", "11000", ?, "CZ12345678", "dodavatel@example.invalid", "cs", ?, 0, 1)'
        )->execute([$this->supplierId, 'Dodavatel ' . $number, $countryId, $currencyId]);
        $vendorId = (int) $pdo->lastInsertId();
        $date = self::YEAR . '-05-01';
        $pdo->prepare(
            'INSERT INTO purchase_invoices
                (supplier_id, vendor_id, vendor_invoice_number, document_kind, issue_date, tax_date, due_date,
                 received_at, currency_id, reverse_charge, vendor_snapshot, total_without_vat, total_vat,
                 total_with_vat, status, created_by)
             VALUES (?, ?, ?, "advance", ?, ?, ?, ?, ?, 0, "{}", ?, 0, ?, "received", ?)'
        )->execute([$this->supplierId, $vendorId, $number, $date, $date, $date, $date, $currencyId, $total, $total, $this->userId]);
        return (int) $pdo->lastInsertId();
    }

    /** @param array<int,float> $allocations přijatá faktura => částka */
    private function match(int $txId, array $allocations): void
    {
        $stmt = $this->db->pdo()->prepare(
            "INSERT INTO payment_matches (supplier_id, bank_transaction_id, purchase_invoice_id, amount, match_type)
             VALUES (?, ?, ?, ?, 'manual')"
        );
        foreach ($allocations as $purchaseId => $amount) {
            $stmt->execute([$this->supplierId, $txId, $purchaseId, $amount]);
        }
        $this->db->pdo()->prepare("UPDATE bank_transactions SET match_status = 'manual' WHERE id = ?")->execute([$txId]);
    }

    /** @param list<float> $allocations řádky 314 MD, banka D za součet */
    private function postPayment(int $txId, array $allocations): int
    {
        $lines = array_map(static fn (float $a): array => ['account_code' => '314', 'side' => 'debit', 'amount' => $a], $allocations);
        $lines[] = ['account_code' => '221', 'side' => 'credit', 'amount' => array_sum($allocations)];
        return (int) $this->posting->postDocument($this->supplierId, 'bank', $txId, $lines,
            ['entry_date' => self::YEAR . '-05-05', 'posted_by' => $this->userId]);
    }

    /** @return list<int> hodnoty střediska na řádcích zápisu (unikátní) */
    private function centersOf(int $entryId): array
    {
        $dims = $this->assignments->entryLineDimensions($this->supplierId, $entryId);
        $out = [];
        foreach ($this->journal->linesForEntry($entryId, $this->supplierId) as $line) {
            $out[] = $dims[$line['id']][$this->centerType] ?? null;
        }
        return array_values(array_unique($out, SORT_REGULAR));
    }

    /** @return array<string,array{dims:array<int,int>, splits:array<int,array<int,float>>}> strana|částka => dimenze */
    private function linesByAmount(int $entryId): array
    {
        $dims = $this->assignments->entryLineDimensions($this->supplierId, $entryId);
        $splits = $this->assignments->entryLineSplits($this->supplierId, $entryId);
        $out = [];
        foreach ($this->journal->linesForEntry($entryId, $this->supplierId) as $line) {
            $d = $dims[$line['id']] ?? [];
            ksort($d);
            $out[$line['side'] . '|' . number_format((float) $line['amount'], 2, '.', '')] = [
                'dims' => $d,
                'splits' => $splits[$line['id']] ?? [],
            ];
        }
        return $out;
    }

    /**
     * @param array<string,string> $query
     * @return list<int>
     */
    private function listIds(array $query): array
    {
        $res = $this->call('list', $query + ['per_page' => '200']);
        self::assertSame(200, $res['status']);
        return array_map('intval', array_column($res['body']['items'], 'id'));
    }

    /** @return array<string,mixed>|null */
    private function findItem(array $items, int $id): ?array
    {
        foreach ($items as $item) {
            if ((int) $item['id'] === $id) {
                return $item;
            }
        }
        return null;
    }

    /**
     * @param array<string,string> $query
     * @return array{status:int, body:array<string,mixed>}
     */
    private function call(string $method, array $query): array
    {
        return $this->decode($this->journalAction->{$method}($this->request($query), new Psr7Response()));
    }

    /** @return array{status:int, body:array<string,mixed>} */
    private function statementDetail(int $statementId): array
    {
        return $this->decode($this->statementAction->detail($this->request([]), new Psr7Response(), ['id' => (string) $statementId]));
    }

    /** @param array<string,string> $query */
    private function request(array $query): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/test')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'accountant'])
            ->withQueryParams($query);
    }

    /** @return array{status:int, body:array<string,mixed>} */
    private function decode(ResponseInterface $resp): array
    {
        $resp->getBody()->rewind();
        $decoded = json_decode((string) $resp->getBody(), true);
        return ['status' => $resp->getStatusCode(), 'body' => is_array($decoded) ? $decoded : []];
    }
}
