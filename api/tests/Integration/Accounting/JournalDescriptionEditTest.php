<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Action\Accounting\JournalAction;
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
 * F7 §35 — inline editace narativního `description` na zaúčtovaném zápisu přes
 * PATCH /api/accounting/journal/{id}/description (mimo PostingService).
 *
 * Ověřuje POVINNÉ §35 guardy (zavřené období → 409, stornovaný → 409, source_type
 * gate §5.4 → 409) a happy path (row_version +1 + neměnný before/after audit).
 * Vše v jedné transakci, tearDown rollbackne. Soft-skip bez cfg.php.
 */
#[Group('integration')]
final class JournalDescriptionEditTest extends TestCase
{
    private const YEAR = 2099;

    private Connection $db;
    private JournalAction $journalAction;
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
            $this->db            = $container->get(Connection::class);
            $this->journalAction = $container->get(JournalAction::class);
            $this->periods       = $container->get(AccountingPeriodRepository::class);
            $seeder              = $container->get(ChartOfAccountsSeeder::class);
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

    public function testEditDescriptionOnPostedManualEntryBumpsVersionAndAudits(): void
    {
        $entryId = $this->manualEntry('Původní popis');
        $before = $this->call('updateDescription', 'PATCH', 'accountant', ['id' => (string) $entryId], ['description' => 'Nový popis §35']);

        self::assertSame(200, $before['status']);
        self::assertSame('Nový popis §35', $before['body']['description']);
        self::assertSame(2, (int) $before['body']['row_version'], 'Editace popisu bumpne row_version (1 → 2).');
        self::assertNotNull($before['body']['posted_at'], 'Editace popisu na ZAÚČTOVANÉM zápisu je legální (§35).');

        // Neměnný before/after audit.
        $row = $this->db->pdo()->query(
            "SELECT payload FROM activity_log
              WHERE supplier_id = {$this->supplierId} AND action = 'accounting.description_edited'
                AND entity_type = 'journal_entry' AND entity_id = {$entryId}
              ORDER BY id DESC LIMIT 1"
        )->fetch(PDO::FETCH_ASSOC);
        self::assertNotFalse($row, 'Editace popisu je auditovaná (accounting.description_edited).');
        $payload = json_decode((string) $row['payload'], true);
        self::assertSame('Původní popis', $payload['before']);
        self::assertSame('Nový popis §35', $payload['after']);
        self::assertTrue($payload['posted']);
    }

    public function testClosedPeriodRejectsDescriptionEdit(): void
    {
        $entryId = $this->manualEntry('X');
        $this->periods->setStatus($this->periodId, $this->supplierId, 'closed');

        $res = $this->call('updateDescription', 'PATCH', 'accountant', ['id' => (string) $entryId], ['description' => 'pokus']);
        self::assertSame(409, $res['status']);
        self::assertSame('period_not_open', $res['body']['error']['code']);
    }

    public function testClosingPeriodRejectsManualDescriptionEdit(): void
    {
        // MED-1 — guard je conditional na source_type (mirror PostingService::rewriteExisting):
        // manual zápis smí editovat popis JEN v období 'open'; v 'closing' (jen pro source_type
        // 'closing' / závěrkový krok) musí být manual odmítnut → 409 period_not_open.
        $entryId = $this->manualEntry('X');
        $this->periods->setStatus($this->periodId, $this->supplierId, 'closing');

        $res = $this->call('updateDescription', 'PATCH', 'accountant', ['id' => (string) $entryId], ['description' => 'pokus']);
        self::assertSame(409, $res['status']);
        self::assertSame('period_not_open', $res['body']['error']['code']);
    }

    public function testNoOpEditDoesNotBumpVersionOrAudit(): void
    {
        // LOW-7 — editace na stejný text je no-op: row_version se nezvýší a nevznikne audit.
        $entryId = $this->manualEntry('Původní popis');
        $res = $this->call('updateDescription', 'PATCH', 'accountant', ['id' => (string) $entryId], ['description' => 'Původní popis']);

        self::assertSame(200, $res['status']);
        self::assertSame(1, (int) $res['body']['row_version'], 'Stejný text → row_version zůstává 1.');

        $audits = (int) $this->db->pdo()->query(
            "SELECT COUNT(*) FROM activity_log
              WHERE supplier_id = {$this->supplierId} AND action = 'accounting.description_edited'
                AND entity_type = 'journal_entry' AND entity_id = {$entryId}"
        )->fetchColumn();
        self::assertSame(0, $audits, 'No-op editace nepíše §35 audit.');
    }

    public function testReversedEntryRejectsDescriptionEdit(): void
    {
        // Manuální zápis lze stornovat; stornovaný original je pro editaci popisu neměnný (§35).
        $entryId = $this->manualEntry('X');
        $rev = $this->call('reverse', 'POST', 'accountant', ['id' => (string) $entryId], ['entry_date' => self::YEAR . '-06-30']);
        self::assertSame(201, $rev['status']);

        $res = $this->call('updateDescription', 'PATCH', 'accountant', ['id' => (string) $entryId], ['description' => 'pokus']);
        self::assertSame(409, $res['status']);
        self::assertSame('entry_reversed', $res['body']['error']['code']);
    }

    public function testSourceManagedEntryRejectsDescriptionEdit(): void
    {
        // Zápis odvozený ze zdrojového dokladu (invoice) — popis řídí zdroj (§5.4 re-post clobber gate).
        $clientId  = $this->client('Odběratel s.r.o.');
        $invoiceId = $this->sale('FV-2099-DESC-1', $clientId, '1', 1000.00, 210.00, 21.00);
        $posted    = $this->call('postInvoice', 'POST', 'accountant', ['id' => (string) $invoiceId]);
        $entryId   = (int) $posted['body']['id'];

        $res = $this->call('updateDescription', 'PATCH', 'accountant', ['id' => (string) $entryId], ['description' => 'pokus']);
        self::assertSame(409, $res['status']);
        self::assertSame('description_managed_by_source', $res['body']['error']['code']);
    }

    public function testReadonlyCannotEditDescription(): void
    {
        $entryId = $this->manualEntry('X');
        $res = $this->call('updateDescription', 'PATCH', 'readonly', ['id' => (string) $entryId], ['description' => 'pokus']);
        self::assertSame(403, $res['status']);
    }

    // ── Issue #15 část B: ETag / If-Match optimistická konkurence ──────────────

    public function testGetEntryExposesEtagWithRowVersion(): void
    {
        $entryId = $this->manualEntry('X');
        $res = $this->call('get', 'GET', 'accountant', ['id' => (string) $entryId]);
        self::assertSame(200, $res['status']);
        self::assertSame('"1"', $res['etag'], 'GET vrací ETag = row_version jako validátor pro If-Match.');
    }

    public function testStaleIfMatchReturnsVersionConflict(): void
    {
        $entryId = $this->manualEntry('Původní popis');
        // Bump na verzi 2 (bez CAS).
        $ok = $this->call('updateDescription', 'PATCH', 'accountant', ['id' => (string) $entryId], ['description' => 'v2']);
        self::assertSame(200, $ok['status']);
        self::assertSame(2, (int) $ok['body']['row_version']);
        self::assertSame('"2"', $ok['etag'], 'Úspěšná editace vrací nový ETag.');

        // Zastaralý If-Match = "1" → 409 version_conflict + aktuální ETag pro resync.
        $res = $this->call('updateDescription', 'PATCH', 'accountant', ['id' => (string) $entryId], ['description' => 'v3'], ['If-Match' => '"1"']);
        self::assertSame(409, $res['status']);
        self::assertSame('version_conflict', $res['body']['error']['code']);
        self::assertSame('"2"', $res['etag'], '409 nese aktuální ETag, aby se klient sesynchronizoval.');

        // Zápis zůstal beze změny (konflikt nic nepřepsal).
        $detail = $this->call('get', 'GET', 'accountant', ['id' => (string) $entryId]);
        self::assertSame('v2', $detail['body']['description']);
        self::assertSame(2, (int) $detail['body']['row_version']);
    }

    public function testCurrentIfMatchSucceedsAndBumps(): void
    {
        $entryId = $this->manualEntry('Původní popis'); // row_version = 1
        $res = $this->call('updateDescription', 'PATCH', 'accountant', ['id' => (string) $entryId], ['description' => 'nový'], ['If-Match' => '"1"']);
        self::assertSame(200, $res['status']);
        self::assertSame('nový', $res['body']['description']);
        self::assertSame(2, (int) $res['body']['row_version']);
        self::assertSame('"2"', $res['etag']);
    }

    public function testBodyRowVersionFallbackConflict(): void
    {
        // Klient bez možnosti posílat hlavičku → row_version v body funguje jako fallback.
        $entryId = $this->manualEntry('Původní popis');
        $this->call('updateDescription', 'PATCH', 'accountant', ['id' => (string) $entryId], ['description' => 'v2']); // → v2
        $res = $this->call('updateDescription', 'PATCH', 'accountant', ['id' => (string) $entryId], ['description' => 'v3', 'row_version' => 1]);
        self::assertSame(409, $res['status']);
        self::assertSame('version_conflict', $res['body']['error']['code']);
    }

    public function testMalformedIfMatchFailsClosed(): void
    {
        // Přítomný, ale nečitelný If-Match nesmí tiše vypnout CAS → 400 invalid_if_match.
        $entryId = $this->manualEntry('Původní popis');
        foreach (['"abc"', 'garbage', '"1", "2"'] as $bad) {
            $res = $this->call('updateDescription', 'PATCH', 'accountant', ['id' => (string) $entryId], ['description' => 'pokus'], ['If-Match' => $bad]);
            self::assertSame(400, $res['status'], "If-Match '{$bad}' → 400.");
            self::assertSame('invalid_if_match', $res['body']['error']['code']);
        }
        // Popis se nezměnil (fail-closed nic nezapsal).
        $detail = $this->call('get', 'GET', 'accountant', ['id' => (string) $entryId]);
        self::assertSame('Původní popis', $detail['body']['description']);
    }

    public function testWildcardIfMatchSkipsCas(): void
    {
        // If-Match: * = „libovolná verze" → bez CAS (proběhne, protože zápis existuje).
        $entryId = $this->manualEntry('Původní popis');
        $res = $this->call('updateDescription', 'PATCH', 'accountant', ['id' => (string) $entryId], ['description' => 'hvězda'], ['If-Match' => '*']);
        self::assertSame(200, $res['status']);
        self::assertSame('hvězda', $res['body']['description']);
    }

    public function testNoIfMatchIsBackwardCompatible(): void
    {
        // Bez If-Match i bez body row_version = původní chování (last-writer-wins, žádný 409).
        $entryId = $this->manualEntry('Původní popis');
        $v2 = $this->call('updateDescription', 'PATCH', 'accountant', ['id' => (string) $entryId], ['description' => 'v2']);
        self::assertSame(200, $v2['status']);
        $v3 = $this->call('updateDescription', 'PATCH', 'accountant', ['id' => (string) $entryId], ['description' => 'v3']);
        self::assertSame(200, $v3['status']);
        self::assertSame('v3', $v3['body']['description']);
        self::assertSame(3, (int) $v3['body']['row_version']);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function manualEntry(string $description): int
    {
        $res = $this->call('create', 'POST', 'accountant', [], [
            'entry_date'  => self::YEAR . '-06-15',
            'description' => $description,
            'lines' => [
                ['account_code' => '211', 'side' => 'debit', 'amount' => 1000.00],
                ['account_code' => '602', 'side' => 'credit', 'amount' => 1000.00],
            ],
        ]);
        self::assertSame(201, $res['status'], 'Fixture: manuální zápis se založí.');
        return (int) $res['body']['id'];
    }

    /**
     * @param array<string,string> $args
     * @param array<string,mixed>  $body
     * @param array<string,string> $headers
     * @return array{status:int, body:array<string,mixed>, etag:string}
     */
    private function call(string $method, string $httpMethod, string $role, array $args = [], array $body = [], array $headers = []): array
    {
        $req = (new ServerRequestFactory())
            ->createServerRequest($httpMethod, '/api/accounting')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => $role]);
        if ($body !== []) {
            $req = $req->withParsedBody($body);
        }
        foreach ($headers as $name => $value) {
            $req = $req->withHeader($name, $value);
        }
        $resp = $args === []
            ? $this->journalAction->{$method}($req, new Psr7Response())
            : $this->journalAction->{$method}($req, new Psr7Response(), $args);
        $resp->getBody()->rewind();
        $decoded = json_decode((string) $resp->getBody(), true);
        return [
            'status' => $resp->getStatusCode(),
            'body'   => is_array($decoded) ? $decoded : [],
            'etag'   => $resp->getHeaderLine('ETag'),
        ];
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
}
