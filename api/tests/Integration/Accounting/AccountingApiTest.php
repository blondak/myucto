<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Action\Accounting\AccountingPeriodAction;
use MyInvoice\Action\Accounting\ChartOfAccountsAction;
use MyInvoice\Action\Accounting\JournalAction;
use MyInvoice\Action\Accounting\PostingRuleAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

/**
 * Integrační testy REST API podvojného účetnictví (Epic F1 — API vrstva).
 *
 * Volají Action třídy přímo (z DI kontejneru) s Requestem nesoucím ATTR_USER /
 * ATTR_CURRENT_ID — testuje se tak i action-level RBAC guard (defense-in-depth
 * proti RoleMiddleware). Vše běží v jedné transakci, kterou tearDown rollbackne.
 * Soft-skip bez cfg.php.
 */
#[Group('integration')]
final class AccountingApiTest extends TestCase
{
    private const YEAR = 2099;

    private Connection $db;
    private ChartOfAccountsAction $accountsAction;
    private AccountingPeriodAction $periodsAction;
    private JournalAction $journalAction;
    private PostingRuleAction $rulesAction;
    private AccountingPeriodRepository $periods;

    private int $supplierId = 0;
    private int $currencyId = 0;
    private int $vatRateId = 0;
    private int $userId = 0;
    private int $czId = 0;
    private int $periodId = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $container = Bootstrap::buildApp()->getContainer();
            $this->db             = $container->get(Connection::class);
            $this->accountsAction = $container->get(ChartOfAccountsAction::class);
            $this->periodsAction  = $container->get(AccountingPeriodAction::class);
            $this->journalAction  = $container->get(JournalAction::class);
            $this->rulesAction    = $container->get(PostingRuleAction::class);
            $this->periods        = $container->get(AccountingPeriodRepository::class);
            $seeder               = $container->get(ChartOfAccountsSeeder::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $this->supplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->currencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->vatRateId  = (int) ($pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId     = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->czId       = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);

        if ($this->supplierId === 0 || $this->currencyId === 0 || $this->vatRateId === 0 || $this->userId === 0 || $this->czId === 0) {
            $this->markTestSkipped('Chybí základní data (supplier/currency/vat_rate/user/country) v DB.');
        }

        $pdo->beginTransaction();
        $this->inTx = true;

        $seeder->seedForSupplier($this->supplierId);
        $this->periodId = $this->periods->create($this->supplierId, self::YEAR, self::YEAR . '-01-01', self::YEAR . '-12-31');
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

    // ── Účtová osnova ──────────────────────────────────────────────────────

    public function testListAccountsReturnsSeededChart(): void
    {
        $res = $this->call($this->accountsAction, 'list', 'GET', 'readonly');
        self::assertSame(200, $res['status']);
        self::assertNotEmpty($res['body']);
    }

    public function testCreateAnalyticAccountAsAccountant(): void
    {
        $parentId = (int) $this->db->pdo()->query(
            "SELECT id FROM chart_of_accounts WHERE supplier_id = {$this->supplierId} AND account_code = '518'"
        )->fetchColumn();

        $res = $this->call($this->accountsAction, 'create', 'POST', 'accountant', [], [
            'parent_id' => $parentId, 'account_code' => '518001', 'name' => 'Software SaaS',
        ]);
        self::assertSame(201, $res['status'], 'Účetní smí založit analytiku.');
        // Tečkovaný tvar je od migrace 1322 jediný správný zápis analytiky a kód účtu
        // už později přejmenovat nejde — zadání bez tečky se proto normalizuje.
        self::assertSame('518.001', $res['body']['account_code']);
        self::assertFalse((bool) $res['body']['is_synthetic']);
        self::assertSame($parentId, (int) $res['body']['parent_id']);
    }

    public function testAnalyticCodeKeepsOwnNotationWhenNotPlainDigits(): void
    {
        $parentId = (int) $this->db->pdo()->query(
            "SELECT id FROM chart_of_accounts WHERE supplier_id = {$this->supplierId} AND account_code = '518'"
        )->fetchColumn();

        // Vlastní systém značení (písmena, jiná syntetika, už tečkovaný tvar) se nepřepisuje.
        foreach (['518.900', '518A01', '648001'] as $code) {
            $res = $this->call($this->accountsAction, 'create', 'POST', 'accountant', [], [
                'parent_id' => $parentId, 'account_code' => $code, 'name' => 'Vlastní ' . $code,
            ]);
            self::assertSame(201, $res['status'], $code);
            self::assertSame($code, $res['body']['account_code'], 'Kód mimo tvar „syntetika + číslice" se nechává být.');
        }
    }

    public function testAnalyticCodeTooLongAfterDotIsRejected(): void
    {
        $parentId = (int) $this->db->pdo()->query(
            "SELECT id FROM chart_of_accounts WHERE supplier_id = {$this->supplierId} AND account_code = '518'"
        )->fetchColumn();

        // 10 znaků projde délkovou validací, s tečkou by jich bylo 11 → sloupec varchar(10)
        // by je tiše ořízl a vznikl by jiný účet, než uživatel zadal.
        $res = $this->call($this->accountsAction, 'create', 'POST', 'accountant', [], [
            'parent_id' => $parentId, 'account_code' => '5181234567', 'name' => 'Dlouhá',
        ]);
        self::assertSame(422, $res['status']);

        $stmt = $this->db->pdo()->prepare('SELECT COUNT(*) FROM chart_of_accounts WHERE supplier_id = ? AND account_code LIKE ?');
        $stmt->execute([$this->supplierId, '518.123%']);
        self::assertSame(0, (int) $stmt->fetchColumn(), 'Oříznutý kód nesmí v osnově vzniknout.');
    }

    public function testAnalyticWithoutMovementsCanBeDeleted(): void
    {
        $parentId = (int) $this->db->pdo()->query(
            "SELECT id FROM chart_of_accounts WHERE supplier_id = {$this->supplierId} AND account_code = '518'"
        )->fetchColumn();
        $created = $this->call($this->accountsAction, 'create', 'POST', 'accountant', [], [
            'parent_id' => $parentId, 'account_code' => '518005', 'name' => 'Překlep',
        ]);
        $id = (int) $created['body']['id'];

        $res = $this->call($this->accountsAction, 'delete', 'DELETE', 'accountant', ['id' => (string) $id]);
        self::assertSame(200, $res['status'], 'Analytika bez pohybů jde smazat — jinak by překlep v kódu zůstal navždy.');

        $stmt = $this->db->pdo()->prepare('SELECT COUNT(*) FROM chart_of_accounts WHERE id = ?');
        $stmt->execute([$id]);
        self::assertSame(0, (int) $stmt->fetchColumn());
    }

    public function testAnalyticReferencedByPostingRuleCannotBeDeleted(): void
    {
        $parentId = (int) $this->db->pdo()->query(
            "SELECT id FROM chart_of_accounts WHERE supplier_id = {$this->supplierId} AND account_code = '518'"
        )->fetchColumn();
        $created = $this->call($this->accountsAction, 'create', 'POST', 'accountant', [], [
            'parent_id' => $parentId, 'account_code' => '518006', 'name' => 'Používaná',
        ]);
        $id = (int) $created['body']['id'];

        // Kontace drží kód jako TEXT, ne přes FK — právě proto se použití hlídá zvlášť.
        $this->db->pdo()->prepare(
            'INSERT INTO posting_rules (supplier_id, rule_key, description, debit_account_code, credit_account_code)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([$this->supplierId, 'test.delete.guard', 'Test guardu mazání', '518.006', '321']);

        $res = $this->call($this->accountsAction, 'delete', 'DELETE', 'accountant', ['id' => (string) $id]);
        self::assertSame(409, $res['status']);
        self::assertStringContainsString('kontace', (string) $res['body']['error']['message']);
    }

    public function testSyntheticAccountCannotBeDeleted(): void
    {
        $id = (int) $this->db->pdo()->query(
            "SELECT id FROM chart_of_accounts WHERE supplier_id = {$this->supplierId} AND account_code = '518'"
        )->fetchColumn();

        $res = $this->call($this->accountsAction, 'delete', 'DELETE', 'accountant', ['id' => (string) $id]);
        self::assertSame(422, $res['status'], 'Syntetika z šablony osnovy se nemaže.');
    }

    public function testReadonlyCannotCreateAccount(): void
    {
        $parentId = (int) $this->db->pdo()->query(
            "SELECT id FROM chart_of_accounts WHERE supplier_id = {$this->supplierId} AND account_code = '518'"
        )->fetchColumn();
        $res = $this->call($this->accountsAction, 'create', 'POST', 'readonly', [], [
            'parent_id' => $parentId, 'account_code' => '518002', 'name' => 'X',
        ]);
        self::assertSame(403, $res['status']);
    }

    // ── Účetní období ──────────────────────────────────────────────────────

    public function testCreatePeriod(): void
    {
        $year = self::YEAR + 5;
        $res = $this->call($this->periodsAction, 'create', 'POST', 'accountant', [], [
            'fiscal_year' => $year, 'starts_on' => $year . '-01-01', 'ends_on' => $year . '-12-31',
        ]);
        self::assertSame(201, $res['status']);
        self::assertSame($year, (int) $res['body']['fiscal_year']);
    }

    public function testPeriodReopenIsAdminOnlyAndAudited(): void
    {
        $this->periods->setStatus($this->periodId, $this->supplierId, 'closed');

        // Účetní znovuotevření nesmí.
        $denied = $this->call($this->periodsAction, 'status', 'POST', 'accountant',
            ['id' => (string) $this->periodId], ['status' => 'open']);
        self::assertSame(403, $denied['status'], 'Znovuotevření období smí jen admin.');

        // Admin smí (s row_version + reason dle F4 §2.4) + zapíše se audit accounting.period_reopened.
        $rowVersion = (int) $this->db->pdo()->query(
            "SELECT row_version FROM accounting_periods WHERE id = {$this->periodId}"
        )->fetchColumn();
        $ok = $this->call($this->periodsAction, 'status', 'POST', 'admin',
            ['id' => (string) $this->periodId],
            ['status' => 'open', 'row_version' => $rowVersion, 'reason' => 'Oprava chybného zaúčtování před schválením.']);
        self::assertSame(200, $ok['status']);
        self::assertSame('open', $ok['body']['status']);

        $logged = (int) $this->db->pdo()->query(
            "SELECT COUNT(*) FROM activity_log
              WHERE supplier_id = {$this->supplierId}
                AND action = 'accounting.period_reopened'
                AND entity_id = {$this->periodId}"
        )->fetchColumn();
        self::assertSame(1, $logged, 'Znovuotevření je auditované (accounting.period_reopened).');
    }

    // ── Deník ──────────────────────────────────────────────────────────────

    public function testManualEntryHappyPath(): void
    {
        $res = $this->call($this->journalAction, 'create', 'POST', 'accountant', [], [
            'entry_date' => self::YEAR . '-06-15',
            'description' => 'Tržba v hotovosti',
            'lines' => [
                ['account_code' => '211', 'side' => 'debit', 'amount' => 1000.00],
                ['account_code' => '602', 'side' => 'credit', 'amount' => 1000.00],
            ],
        ]);
        self::assertSame(201, $res['status']);
        self::assertSame('manual', $res['body']['source_type']);
        self::assertNotNull($res['body']['posted_at']);
        self::assertCount(2, $res['body']['lines']);
    }

    public function testUnbalancedManualEntryReturns422(): void
    {
        $res = $this->call($this->journalAction, 'create', 'POST', 'accountant', [], [
            'entry_date' => self::YEAR . '-06-15',
            'lines' => [
                ['account_code' => '211', 'side' => 'debit', 'amount' => 1000.00],
                ['account_code' => '602', 'side' => 'credit', 'amount' => 900.00],
            ],
        ]);
        self::assertSame(422, $res['status']);
        self::assertSame('unbalanced_entry', $res['body']['error']['code']);
    }

    public function testReadonlyCannotPostManualEntry(): void
    {
        $res = $this->call($this->journalAction, 'create', 'POST', 'readonly', [], [
            'entry_date' => self::YEAR . '-06-15',
            'lines' => [
                ['account_code' => '211', 'side' => 'debit', 'amount' => 100.00],
                ['account_code' => '602', 'side' => 'credit', 'amount' => 100.00],
            ],
        ]);
        self::assertSame(403, $res['status']);
    }

    public function testCrossTenantJournalReadReturns404(): void
    {
        $create = $this->call($this->journalAction, 'create', 'POST', 'accountant', [], [
            'entry_date' => self::YEAR . '-06-15',
            'lines' => [
                ['account_code' => '211', 'side' => 'debit', 'amount' => 500.00],
                ['account_code' => '602', 'side' => 'credit', 'amount' => 500.00],
            ],
        ]);
        $entryId = (int) $create['body']['id'];

        // Skutečný (nikoli neexistující) druhý double_entry supplier — od G6 gate
        // (accounting_mode) 403uje neexistující/tax_evidence tenanta dřív, než se
        // stihne vyhodnotit ownership 404 (viz Stock modul precedent, GuardsStockEnabled).
        $foreignSupplierId = $this->cloneSupplier('double_entry');

        $req = (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/accounting/journal/' . $entryId)
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $foreignSupplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'accountant']);
        $resp = $this->journalAction->get($req, new Psr7Response(), ['id' => (string) $entryId]);
        self::assertSame(404, $resp->getStatusCode(), 'Cizí tenant nevidí zápis.');
    }

    public function testPostInvoiceIdempotentViaEndpoint(): void
    {
        $client    = $this->client('Odběratel s.r.o.');
        $invoiceId = $this->sale('FV-2099-API-1', $client, '1', 1000.00, 210.00, 21.00);

        $first  = $this->call($this->journalAction, 'postInvoice', 'POST', 'accountant', ['id' => (string) $invoiceId]);
        $second = $this->call($this->journalAction, 'postInvoice', 'POST', 'accountant', ['id' => (string) $invoiceId]);

        self::assertSame(200, $first['status']);
        self::assertSame(200, $second['status']);
        self::assertSame((int) $first['body']['id'], (int) $second['body']['id'], 'Idempotence — týž zápis.');

        $count = (int) $this->db->pdo()->query(
            "SELECT COUNT(*) FROM journal_entries WHERE supplier_id = {$this->supplierId}
              AND source_type = 'invoice' AND source_id = {$invoiceId}"
        )->fetchColumn();
        self::assertSame(1, $count);
    }

    public function testPostInvoiceWarnsWhenEntryDateIsInDifferentYear(): void
    {
        $nextYear = self::YEAR + 1;
        $this->periods->create($this->supplierId, $nextYear, $nextYear . '-01-01', $nextYear . '-12-31');
        $client = $this->client('Odběratel s.r.o.');
        $invoiceId = $this->sale('FV-2099-L34', $client, '1', 1000.00, 210.00, 21.00);

        $result = $this->call(
            $this->journalAction,
            'postInvoice',
            'POST',
            'accountant',
            ['id' => (string) $invoiceId],
            ['entry_date' => $nextYear . '-01-05'],
        );

        self::assertSame(200, $result['status']);
        self::assertSame(
            ['entry_date_outside_document_year'],
            $result['body']['_warnings'] ?? [],
            'Přesun zápisu mimo rok DUZP je povolený, ale hlasitě varuje.',
        );
    }

    public function testJournalCanFilterByPartialDocumentNumber(): void
    {
        $client = $this->client('Odběratel s.r.o.');
        $wantedId = $this->sale('FV-2099-SEARCH-ABC', $client, '1', 1000.00, 210.00, 21.00);
        $otherId = $this->sale('FV-2099-OTHER', $client, '1', 500.00, 105.00, 21.00);
        $this->call($this->journalAction, 'postInvoice', 'POST', 'accountant', ['id' => (string) $wantedId]);
        $this->call($this->journalAction, 'postInvoice', 'POST', 'accountant', ['id' => (string) $otherId]);

        $result = $this->call(
            $this->journalAction,
            'list',
            'GET',
            'accountant',
            [],
            [],
            ['document_no' => 'SEARCH-ABC'],
        );

        self::assertSame(200, $result['status']);
        self::assertSame(1, $result['body']['total']);
        self::assertSame('FV-2099-SEARCH-ABC', $result['body']['items'][0]['document_no']);
    }

    public function testReverseViaEndpointAndReadonlyDenied(): void
    {
        $client    = $this->client('Odběratel s.r.o.');
        $invoiceId = $this->sale('FV-2099-API-2', $client, '1', 1000.00, 210.00, 21.00);
        $posted    = $this->call($this->journalAction, 'postInvoice', 'POST', 'accountant', ['id' => (string) $invoiceId]);
        $entryId   = (int) $posted['body']['id'];

        // readonly nesmí stornovat
        $denied = $this->call($this->journalAction, 'reverse', 'POST', 'readonly', ['id' => (string) $entryId]);
        self::assertSame(403, $denied['status']);

        $ok = $this->call($this->journalAction, 'reverse', 'POST', 'accountant',
            ['id' => (string) $entryId], ['entry_date' => self::YEAR . '-06-30']);
        self::assertSame(201, $ok['status']);
        self::assertNotSame($entryId, (int) $ok['body']['id']);
    }

    public function testDeletePurchaseEntryInOpenPeriodUnbooksDocument(): void
    {
        $vendorId = $this->client('Dodavatel a.s.');
        $purchaseId = $this->purchase('PF-2099-DEL-1', $vendorId, 2000.00, 420.00, 21.00);
        $posted = $this->call($this->journalAction, 'postPurchase', 'POST', 'accountant', ['id' => (string) $purchaseId]);
        $entryId = (int) $posted['body']['id'];
        $this->db->pdo()->prepare("UPDATE purchase_invoices SET status = 'booked' WHERE id = ?")
            ->execute([$purchaseId]);

        $denied = $this->call($this->journalAction, 'delete', 'DELETE', 'readonly', ['id' => (string) $entryId]);
        self::assertSame(403, $denied['status']);

        $deleted = $this->call($this->journalAction, 'delete', 'DELETE', 'accountant', ['id' => (string) $entryId]);
        self::assertSame(200, $deleted['status']);
        self::assertTrue((bool) ($deleted['body']['ok'] ?? false));
        self::assertSame(0, (int) $this->db->pdo()->query(
            "SELECT COUNT(*) FROM journal_entries WHERE id = {$entryId}"
        )->fetchColumn());

        $document = $this->db->pdo()->query(
            "SELECT status, booked_at, booked_by FROM purchase_invoices WHERE id = {$purchaseId}"
        )->fetch(PDO::FETCH_ASSOC);
        self::assertSame('received', $document['status']);
        self::assertNull($document['booked_at']);
        self::assertNull($document['booked_by']);

        self::assertSame(1, (int) $this->db->pdo()->query(
            "SELECT COUNT(*) FROM activity_log
              WHERE supplier_id = {$this->supplierId}
                AND action = 'accounting.entry_deleted' AND entity_id = {$entryId}"
        )->fetchColumn());
    }

    public function testDeleteManualEntryInOpenPeriod(): void
    {
        $created = $this->call($this->journalAction, 'create', 'POST', 'accountant', [], [
            'entry_date' => self::YEAR . '-06-15',
            'description' => 'Chybný ruční zápis',
            'lines' => [
                ['account_code' => '211', 'side' => 'debit', 'amount' => 500.00],
                ['account_code' => '602', 'side' => 'credit', 'amount' => 500.00],
            ],
        ]);
        $entryId = (int) $created['body']['id'];

        $deleted = $this->call($this->journalAction, 'delete', 'DELETE', 'accountant', [
            'id' => (string) $entryId,
        ]);

        self::assertSame(200, $deleted['status']);
        self::assertTrue((bool) ($deleted['body']['ok'] ?? false));
        self::assertSame(0, (int) $this->db->pdo()->query(
            "SELECT COUNT(*) FROM journal_entries WHERE id = {$entryId}"
        )->fetchColumn());
        self::assertSame(1, (int) $this->db->pdo()->query(
            "SELECT COUNT(*) FROM activity_log
              WHERE supplier_id = {$this->supplierId}
                AND action = 'accounting.entry_deleted' AND entity_id = {$entryId}"
        )->fetchColumn());
    }

    public function testDeleteEntryOutsideOpenPeriodIsRejected(): void
    {
        $client = $this->client('Odběratel s.r.o.');
        $invoiceId = $this->sale('FV-2099-DEL-2', $client, '1', 1000.00, 210.00, 21.00);
        $posted = $this->call($this->journalAction, 'postInvoice', 'POST', 'accountant', ['id' => (string) $invoiceId]);
        $entryId = (int) $posted['body']['id'];
        $this->periods->setStatus($this->periodId, $this->supplierId, 'closed');

        $result = $this->call($this->journalAction, 'delete', 'DELETE', 'accountant', ['id' => (string) $entryId]);
        self::assertSame(409, $result['status']);
        self::assertSame('period_not_open', $result['body']['error']['code']);
        self::assertSame(1, (int) $this->db->pdo()->query(
            "SELECT COUNT(*) FROM journal_entries WHERE id = {$entryId}"
        )->fetchColumn());
        self::assertNotNull($this->db->pdo()->query(
            "SELECT booked_at FROM invoices WHERE id = {$invoiceId}"
        )->fetchColumn());
    }

    // ── Kontační pravidla ──────────────────────────────────────────────────

    public function testPostingRulesEffectiveMapAndOverride(): void
    {
        $list = $this->call($this->rulesAction, 'list', 'GET', 'readonly');
        self::assertSame(200, $list['status']);
        self::assertArrayHasKey('invoice.services.issued', $list['body']);

        $put = $this->call($this->rulesAction, 'put', 'PUT', 'accountant',
            ['rule_key' => 'invoice.services.issued'],
            ['debit_account_code' => '311', 'credit_account_code' => '601']);
        self::assertSame(200, $put['status']);
        self::assertSame('601', $put['body']['credit_account_code']);
        self::assertSame($this->supplierId, (int) $put['body']['supplier_id'], 'Override je per-tenant.');
    }

    public function testPostingRuleOverrideUnknownAccountRejected(): void
    {
        $res = $this->call($this->rulesAction, 'put', 'PUT', 'accountant',
            ['rule_key' => 'invoice.services.issued'],
            ['debit_account_code' => 'ZZZ']);
        self::assertSame(422, $res['status']);
        self::assertSame('unknown_account', $res['body']['error']['code']);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /**
     * @param array<string,string> $args
     * @param array<string,mixed>  $body
     * @param array<string,mixed>  $query
     * @return array{status:int, body:array<string,mixed>}
     */
    private function call(
        object $action,
        string $method,
        string $httpMethod,
        string $role,
        array $args = [],
        array $body = [],
        array $query = [],
    ): array
    {
        $req = (new ServerRequestFactory())
            ->createServerRequest($httpMethod, '/api/accounting')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => $role]);
        if ($body !== []) {
            $req = $req->withParsedBody($body);
        }
        if ($query !== []) {
            $req = $req->withQueryParams($query);
        }
        $resp = $args === []
            ? $action->{$method}($req, new Psr7Response())
            : $action->{$method}($req, new Psr7Response(), $args);
        $resp->getBody()->rewind();
        $decoded = json_decode((string) $resp->getBody(), true);
        return ['status' => $resp->getStatusCode(), 'body' => is_array($decoded) ? $decoded : []];
    }

    /** Skutečný druhý throwaway supplier (kopie FK z hlavního) pro cross-tenant testy. */
    private function cloneSupplier(string $accountingMode): int
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO supplier (company_name, street, city, zip, country_id, email,
                default_currency_id, default_vat_rate_id, accounting_mode)
             VALUES (?, "Testovací 1", "Praha", "11000", ?, ?, ?, ?, ?)'
        );
        $stmt->execute(['Cizí tenant s.r.o.', $this->czId, 'foreign-' . uniqid() . '@example.com',
            $this->currencyId, $this->vatRateId, $accountingMode]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    private function client(string $name): int
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO clients
                (supplier_id, company_name, street, city, zip, country_id, dic, main_email,
                 language, currency_default_id, is_customer, is_vendor)
             VALUES (?, ?, "Test 1", "Praha", "11000", ?, "CZ12345678", "test@example.com", "cs", ?, 1, 0)'
        );
        $stmt->execute([$this->supplierId, $name, $this->czId, $this->currencyId]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    private function sale(string $varsymbol, int $clientId, string $code, float $base, float $vat, float $rate): int
    {
        $with = $base + $vat;
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO invoices
                (supplier_id, varsymbol, invoice_type, client_id, issue_date, tax_date, due_date,
                 currency_id, reverse_charge, total_without_vat, total_vat, total_with_vat,
                 status, vat_classification_code, created_by)
             VALUES (?, ?, "invoice", ?, ?, ?, ?, ?, 0, ?, ?, ?, "issued", ?, ?)'
        );
        $issue = self::YEAR . '-06-15';
        $stmt->execute([$this->supplierId, $varsymbol, $clientId, $issue, $issue, $issue, $this->currencyId, $base, $vat, $with, $code, $this->userId]);
        $id = (int) $this->db->pdo()->lastInsertId();
        $itemStmt = $this->db->pdo()->prepare(
            'INSERT INTO invoice_items
                (invoice_id, description, quantity, unit, unit_price_without_vat, vat_rate_id,
                 vat_rate_snapshot, total_without_vat, total_vat, total_with_vat, order_index)
             VALUES (?, "Test položka", 1, "ks", ?, ?, ?, ?, ?, ?, 0)'
        );
        $itemStmt->execute([$id, $base, $this->vatRateId, $rate, $base, $vat, $with]);
        return $id;
    }

    private function purchase(string $number, int $vendorId, float $base, float $vat, float $rate): int
    {
        $with = $base + $vat;
        $issue = self::YEAR . '-06-20';
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO purchase_invoices
                (supplier_id, vendor_id, vendor_invoice_number, document_kind, issue_date, tax_date,
                 due_date, received_at, currency_id, reverse_charge, vendor_snapshot,
                 total_without_vat, total_vat, total_with_vat, status, vat_classification_code,
                 vat_deduction, created_by)
             VALUES (?, ?, ?, "invoice", ?, ?, ?, ?, ?, 0, "{}", ?, ?, ?, "received", "40", "full", ?)'
        );
        $stmt->execute([
            $this->supplierId, $vendorId, $number, $issue, $issue, $issue, $issue,
            $this->currencyId, $base, $vat, $with, $this->userId,
        ]);
        $id = (int) $this->db->pdo()->lastInsertId();
        $item = $this->db->pdo()->prepare(
            'INSERT INTO purchase_invoice_items
                (purchase_invoice_id, description, quantity, unit, unit_price_without_vat,
                 vat_rate_id, vat_rate_snapshot, total_without_vat, total_vat, total_with_vat, order_index)
             VALUES (?, "Test položka", 1, "ks", ?, ?, ?, ?, ?, ?, 0)'
        );
        $item->execute([$id, $base, $this->vatRateId, $rate, $base, $vat, $with]);
        return $id;
    }
}
