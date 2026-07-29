<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\TaxEvidence;

use MyInvoice\Action\Accounting\Cash\CashBookAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Service\Accounting\Cash\CashDocumentService;
use MyInvoice\Service\Accounting\Cash\CashRegisterService;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

/**
 * Audit 2026-07 / Fáze G3 (HIGH nálezy „de-mode"): pokladna v daňové evidenci
 * (accounting_mode='tax_evidence', §6 — bez COA/journal) musí mít:
 *  (a) zůstatek registru = Σ dokladů kasové báze, ne 0 z nedostupného ledgeru
 *      (CashRegisterService::decorate/balance),
 *  (b) funkční pokladní knihu GET /accounting/cash-registers/{id}/book s běžícím
 *      zůstatkem přímo nad cash_documents, ne pád `account_invalid`
 *      (CashBookAction::get → buildTaxEvidenceBook).
 * Double_entry regrese (c): stejný endpoint musí dál fungovat beze změny přes
 * AccountStatementService (ledger).
 */
#[Group('integration')]
final class CashRegisterDeBookTest extends TestCase
{
    private const YEAR = 2099;

    private Connection $db;
    private CashRegisterService $registers;
    private CashDocumentService $documents;
    private CashBookAction $bookAction;
    private AccountingPeriodRepository $periods;

    private int $currencyId = 0;
    private int $userId = 0;
    private int $countryId = 0;
    private int $vatRateId = 0;

    private int $teSupplierId = 0;
    private int $deSupplierId = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $container = Bootstrap::buildApp()->getContainer();
            $this->db         = $container->get(Connection::class);
            $this->registers  = $container->get(CashRegisterService::class);
            $this->documents  = $container->get(CashDocumentService::class);
            $this->bookAction = $container->get(CashBookAction::class);
            $this->periods    = $container->get(AccountingPeriodRepository::class);
            $seeder            = $container->get(ChartOfAccountsSeeder::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $this->currencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->userId     = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->countryId  = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        $this->vatRateId  = (int) ($pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($this->currencyId === 0 || $this->userId === 0 || $this->countryId === 0 || $this->vatRateId === 0) {
            $this->markTestSkipped('Chybí základní data (currency/user/country/vat_rate) v DB.');
        }

        $pdo->beginTransaction();
        $this->inTx = true;

        $this->teSupplierId = $this->makeSupplier('tax_evidence');
        $this->deSupplierId = $this->makeSupplier('double_entry');
        // COA se seeduje jen pro double_entry — tax_evidence tenant osnovu nemá (R6),
        // stejně jako CashDocumentNoJournalTest ověřuje reálnou dosažitelnost no-journal cesty.
        $seeder->seedForSupplier($this->deSupplierId);
        $this->periods->create($this->deSupplierId, self::YEAR, self::YEAR . '-01-01', self::YEAR . '-12-31');
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

    /** (a) Zůstatek registru v tax_evidence = Σ signed amounts posted dokladů, ne 0. */
    public function testTaxEvidenceRegisterBalanceIsDocumentsSignedTotal(): void
    {
        $regId = $this->registers->create($this->teSupplierId, ['name' => 'Pokladna', 'account_code' => '211', 'is_default' => true]);

        $this->postSale($this->teSupplierId, $regId, 1500.00);
        $this->postSale($this->teSupplierId, $regId, 300.00);
        $this->postPurchase($this->teSupplierId, $regId, 200.00);

        // postSale/postPurchase bez explicitního data = dnešek (viz helper), takže i list()
        // (asOf vždy dnešek, bez date parametru) pokryje stejné doklady jako get().
        $detail = $this->registers->get($this->teSupplierId, $regId);
        self::assertNull($detail['account_id'], 'Předpoklad: DE pokladna nemá COA analytiku.');
        self::assertEqualsWithDelta(1600.00, $detail['balance'], 0.001, 'Balance musí být Σ dokladů (1500+300-200), ne 0 z nedostupného ledgeru.');

        // list() musí vracet stejnou hodnotu (regrese N+1 refaktoru decorate()).
        $list = $this->registers->list($this->teSupplierId);
        self::assertCount(1, $list);
        self::assertEqualsWithDelta(1600.00, $list[0]['balance'], 0.001);
    }

    /** (b) Pokladní kniha v tax_evidence nepadá a vrací běžící zůstatek přímo nad cash_documents. */
    public function testTaxEvidenceCashBookReturnsRunningBalance(): void
    {
        $regId = $this->registers->create($this->teSupplierId, ['name' => 'Pokladna', 'account_code' => '211', 'is_default' => true]);

        $this->postSale($this->teSupplierId, $regId, 1000.00, self::YEAR . '-06-10');
        $this->postPurchase($this->teSupplierId, $regId, 400.00, self::YEAR . '-06-15');
        $this->postSale($this->teSupplierId, $regId, 250.00, self::YEAR . '-06-20');

        $res = $this->call($this->teSupplierId, $regId, self::YEAR . '-01-01', self::YEAR . '-12-31');
        self::assertSame(200, $res['status'], 'GET /book nesmí padat na account_invalid v tax_evidence.');

        $body = $res['body'];
        self::assertEqualsWithDelta(0.0, (float) $body['opening_balance'], 0.001);
        self::assertCount(3, $body['items']);
        self::assertEqualsWithDelta(1000.00, $body['items'][0]['balance'], 0.001);
        self::assertEqualsWithDelta(600.00, $body['items'][1]['balance'], 0.001, '1000 - 400 = 600.');
        self::assertEqualsWithDelta(850.00, $body['items'][2]['balance'], 0.001, '600 + 250 = 850.');
        self::assertEqualsWithDelta(850.00, $body['closing_balance'], 0.001);
        self::assertEqualsWithDelta(1250.00, $body['income_total'], 0.001);
        self::assertEqualsWithDelta(400.00, $body['expense_total'], 0.001);
        self::assertFalse($body['balance_negative']);
    }

    /** (c) Regrese: double_entry endpoint dál funguje přes AccountStatementService (ledger). */
    public function testDoubleEntryCashBookStillUsesLedger(): void
    {
        $regId = $this->registers->create($this->deSupplierId, ['name' => 'Pokladna', 'account_code' => '211', 'is_default' => true]);
        $this->postSale($this->deSupplierId, $regId, 700.00, self::YEAR . '-06-10');

        $res = $this->call($this->deSupplierId, $regId, self::YEAR . '-01-01', self::YEAR . '-12-31');
        self::assertSame(200, $res['status']);
        self::assertEqualsWithDelta(700.00, $res['body']['closing_balance'], 0.001);
        self::assertCount(1, $res['body']['items']);

        $detail = $this->registers->get($this->deSupplierId, $regId, self::YEAR . '-12-31');
        self::assertNotNull($detail['account_id'], 'Double_entry pokladna má COA analytiku (beze změny).');
        self::assertEqualsWithDelta(700.00, $detail['balance'], 0.001);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function makeSupplier(string $mode): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO supplier
                (company_name, street, city, zip, country_id, email,
                 default_currency_id, default_vat_rate_id, accounting_mode)
             VALUES (?, "Testovací 1", "Praha", "11000", ?, "de-book-test@example.com", ?, ?, ?)'
        )->execute(['DE Book Test ' . $mode, $this->countryId, $this->currencyId, $this->vatRateId, $mode]);
        return (int) $pdo->lastInsertId();
    }

    /** Default issue_date = dnešek (žádná period pro tax_evidence, R14) — pokrývá i list()/get() bez explicitního $date. */
    private function postSale(int $supplierId, int $registerId, float $total, ?string $date = null): void
    {
        $this->documents->create($supplierId, [
            'register_id'  => $registerId,
            'issue_date'   => $date ?? date('Y-m-d'),
            'description'  => 'Pokladní tržba',
            'purpose'      => 'sale',
            'doc_type'     => 'in',
            'total_amount' => $total,
            'post'         => true,
        ], $this->userId);
    }

    private function postPurchase(int $supplierId, int $registerId, float $total, ?string $date = null): void
    {
        $this->documents->create($supplierId, [
            'register_id'  => $registerId,
            'issue_date'   => $date ?? date('Y-m-d'),
            'description'  => 'Nákup materiálu',
            'purpose'      => 'purchase',
            'doc_type'     => 'out',
            'total_amount' => $total,
            'post'         => true,
        ], $this->userId);
    }

    /** @return array{status:int, body:array<string,mixed>} */
    private function call(int $supplierId, int $registerId, string $from, string $to): array
    {
        $req = (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/accounting/cash-registers/' . $registerId . '/book')
            ->withQueryParams(['from' => $from, 'to' => $to])
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'accountant']);

        /** @var ResponseInterface $resp */
        $resp = $this->bookAction->get($req, new Psr7Response(), ['id' => (string) $registerId]);
        $resp->getBody()->rewind();
        $decoded = json_decode((string) $resp->getBody(), true);
        return ['status' => $resp->getStatusCode(), 'body' => is_array($decoded) ? $decoded : []];
    }
}
