<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Action\Invoice\DeleteInvoiceAction;
use MyInvoice\Action\Invoice\UpdateInvoiceAction;
use MyInvoice\Action\PurchaseInvoice\DeletePurchaseInvoiceAction;
use MyInvoice\Action\PurchaseInvoice\SetPurchaseInvoiceExpenseKindsAction;
use MyInvoice\Action\PurchaseInvoice\TransitionPurchaseInvoiceStatusAction;
use MyInvoice\Action\PurchaseInvoice\UpdatePurchaseInvoiceAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\JournalEntryRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\PostingService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

/**
 * Účetní vrstva je pro API token jen ke čtení. ApiScopeMiddleware blokuje celé cesty
 * (/api/accounting/*, /api/invoices/{id}/book …), jenže ruční účetní úkon jde provést
 * i přes akce mimo ně: přechod přijaté faktury na `booked`, druh nákladu u zaúčtovaného
 * dokladu (přeúčtuje deník), vynucená úprava či smazání vystaveného/zaúčtovaného dokladu
 * a přebití zámku uzavřeného období přes `?force=1`.
 *
 * Každý test ověřuje obě strany: token dostane 403 `token_write_forbidden` a doklad ani
 * deník se nezmění; přihlášená relace týž krok dál provede.
 *
 * Soft-skip bez cfg.php; vše v transakci s rollbackem, data jsou syntetická.
 */
#[Group('integration')]
final class TokenAccountingActGuardTest extends TestCase
{
    private const AMOUNT = 1000.0;
    private const CLOSED_YEAR = 2095;

    private ContainerInterface $container;
    private Connection $db;
    private PostingService $posting;
    private JournalEntryRepository $journal;

    private int $supplierId = 0;
    private int $userId = 0;
    private int $vatRateId = 0;
    private int $czkId = 0;
    private int $vendorId = 0;
    private string $date = '';
    private bool $inTx = false;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje, test vyžaduje DB connection.');
        }
        try {
            $this->container = Bootstrap::buildContainer();
            $this->db      = $this->container->get(Connection::class);
            $this->posting = $this->container->get(PostingService::class);
            $this->journal = $this->container->get(JournalEntryRepository::class);
            $periods       = $this->container->get(AccountingPeriodRepository::class);
            $seeder        = $this->container->get(ChartOfAccountsSeeder::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $this->supplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId     = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->vatRateId  = (int) ($pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $czId             = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        if ($this->supplierId === 0 || $this->userId === 0 || $this->vatRateId === 0 || $czId === 0) {
            $this->markTestSkipped('Chybí základní data v DB.');
        }
        $this->czkId = (int) ($pdo->query(
            "SELECT id FROM currencies WHERE supplier_id = {$this->supplierId} AND code = 'CZK' LIMIT 1"
        )->fetchColumn() ?: 0);
        if ($this->czkId === 0) {
            $this->markTestSkipped('Dodavatel nemá měnu CZK.');
        }

        $year = (int) date('Y');
        $this->date = $year . '-03-10';

        $pdo->beginTransaction();
        $this->inTx = true;

        $seeder->seedForSupplier($this->supplierId);
        if ($periods->findByYear($this->supplierId, $year) === null) {
            $periods->create($this->supplierId, $year, $year . '-01-01', $year . '-12-31');
        }
        $pdo->prepare('UPDATE accounting_supplier_settings SET locked_until = NULL WHERE supplier_id = ?')
            ->execute([$this->supplierId]);

        $pdo->prepare(
            'INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id,
                                  main_email, language, currency_default_id, is_vendor, is_customer, is_vat_payer)
             VALUES (?, "Token guard partner s.r.o. (PHPUnit)", "Testovaci 1", "Praha", "11000", ?,
                     "token-guard@example.test", "cs", ?, 1, 1, 1)'
        )->execute([$this->supplierId, $czId, $this->czkId]);
        $this->vendorId = (int) $pdo->lastInsertId();
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

    public function testManualBookingOfPurchaseInvoiceIsSessionOnly(): void
    {
        $id = $this->purchase('received', posted: false);

        $res = $this->call(TransitionPurchaseInvoiceStatusAction::class, 'POST', $id, ['target' => 'booked'], 'bearer');
        $this->assertTokenForbidden($res);
        $row = $this->purchaseRow($id);
        self::assertSame('received', $row['status']);
        self::assertNull($row['booked_at']);

        $res = $this->call(TransitionPurchaseInvoiceStatusAction::class, 'POST', $id, ['target' => 'booked'], 'session');
        self::assertSame(200, $res['status'], json_encode($res['body'], JSON_UNESCAPED_UNICODE));
        self::assertSame('booked', $this->purchaseRow($id)['status']);
    }

    /** Běžné workflow (přijetí, úhrada) token dál smí, blokuje se jen ruční zaúčtování. */
    public function testOrdinaryPurchaseTransitionsStayAvailableToToken(): void
    {
        $id = $this->purchase('received', posted: false);

        $res = $this->call(TransitionPurchaseInvoiceStatusAction::class, 'POST', $id, ['target' => 'paid'], 'bearer');
        self::assertSame(200, $res['status'], json_encode($res['body'], JSON_UNESCAPED_UNICODE));
        self::assertSame('paid', $this->purchaseRow($id)['status']);
    }

    public function testExpenseKindsOnPostedPurchaseAreSessionOnly(): void
    {
        $id = $this->purchase('received', posted: true);
        $entryBefore = $this->journal->findBySource($this->supplierId, 'purchase_invoice', $id);
        self::assertNotNull($entryBefore, 'Doklad musí být zaúčtovaný, jinak test netestuje nic.');
        $itemId = $this->firstItemId($id);
        $body = ['items' => [['id' => $itemId, 'expense_kind' => 'service']]];

        $res = $this->call(SetPurchaseInvoiceExpenseKindsAction::class, 'PUT', $id, $body, 'bearer');
        $this->assertTokenForbidden($res);
        self::assertNull($this->itemKind($itemId), 'Druh nákladu se přes token nesmí uložit.');
        $this->assertJournalUnchanged($id, (int) $entryBefore['id']);

        $res = $this->call(SetPurchaseInvoiceExpenseKindsAction::class, 'PUT', $id, $body, 'session');
        self::assertSame(200, $res['status'], json_encode($res['body'], JSON_UNESCAPED_UNICODE));
        self::assertSame('service', $this->itemKind($itemId));
        self::assertArrayHasKey('_repost', $res['body'], 'Relace zaúčtovaný doklad dál přeúčtuje.');
    }

    /** Nezaúčtovaný doklad (typicky kontrola po AI importu) token klasifikovat smí. */
    public function testExpenseKindsOnUnpostedPurchaseStayAvailableToToken(): void
    {
        $id = $this->purchase('received', posted: false);
        $itemId = $this->firstItemId($id);

        $res = $this->call(SetPurchaseInvoiceExpenseKindsAction::class, 'PUT', $id,
            ['items' => [['id' => $itemId, 'expense_kind' => 'service']]], 'bearer');
        self::assertSame(200, $res['status'], json_encode($res['body'], JSON_UNESCAPED_UNICODE));
        self::assertSame('service', $this->itemKind($itemId));
    }

    public function testForceEditOfPostedPurchaseIsSessionOnly(): void
    {
        $id = $this->purchase('booked', posted: true);
        $entryBefore = $this->journal->findBySource($this->supplierId, 'purchase_invoice', $id);
        self::assertNotNull($entryBefore);
        $body = $this->purchaseBody($id, 'TOKEN-GUARD-CHANGED', self::AMOUNT * 2);

        $res = $this->call(UpdatePurchaseInvoiceAction::class, 'PUT', $id, $body, 'bearer', ['force' => '1']);
        $this->assertTokenForbidden($res);
        self::assertNotSame('TOKEN-GUARD-CHANGED', $this->purchaseRow($id)['vendor_invoice_number']);
        $this->assertJournalUnchanged($id, (int) $entryBefore['id']);

        $res = $this->call(UpdatePurchaseInvoiceAction::class, 'PUT', $id, $body, 'session', ['force' => '1']);
        self::assertSame(200, $res['status'], json_encode($res['body'], JSON_UNESCAPED_UNICODE));
        self::assertSame('TOKEN-GUARD-CHANGED', $this->purchaseRow($id)['vendor_invoice_number']);
        self::assertArrayHasKey('_repost', $res['body']);
    }

    public function testForceEditOfIssuedInvoiceIsSessionOnly(): void
    {
        $id = $this->issuedInvoice($this->date);
        $body = $this->invoiceBody($this->date) + ['note_below_items' => 'Token guard (PHPUnit)'];

        $res = $this->call(UpdateInvoiceAction::class, 'PUT', $id, $body, 'bearer', ['force' => '1']);
        $this->assertTokenForbidden($res);
        self::assertNull($this->invoiceNote($id));

        $res = $this->call(UpdateInvoiceAction::class, 'PUT', $id, $body, 'session', ['force' => '1']);
        self::assertSame(200, $res['status'], json_encode($res['body'], JSON_UNESCAPED_UNICODE));
        self::assertSame('Token guard (PHPUnit)', $this->invoiceNote($id));
    }

    public function testForceDeleteOfBookedPurchaseIsSessionOnly(): void
    {
        $id = $this->purchase('booked', posted: true);
        $entryBefore = $this->journal->findBySource($this->supplierId, 'purchase_invoice', $id);
        self::assertNotNull($entryBefore);

        $res = $this->call(DeletePurchaseInvoiceAction::class, 'DELETE', $id, [], 'bearer', ['force' => '1']);
        $this->assertTokenForbidden($res);
        self::assertNotSame([], $this->purchaseRow($id), 'Doklad musí zůstat.');
        $this->assertJournalUnchanged($id, (int) $entryBefore['id']);

        $res = $this->call(DeletePurchaseInvoiceAction::class, 'DELETE', $id, [], 'session', ['force' => '1']);
        self::assertSame(200, $res['status'], json_encode($res['body'], JSON_UNESCAPED_UNICODE));
        self::assertSame([], $this->purchaseRow($id));
    }

    public function testForceDeleteOfIssuedInvoiceIsForbiddenToToken(): void
    {
        $id = $this->issuedInvoice($this->date);

        $res = $this->call(DeleteInvoiceAction::class, 'DELETE', $id, [], 'bearer', ['force' => '1']);
        $this->assertTokenForbidden($res);
        self::assertSame('issued', $this->db->pdo()->query("SELECT status FROM invoices WHERE id = $id")->fetchColumn());
    }

    /** Přebití zámku uzavřeného období (`?force=1`) je účetní úkon i u konceptu. */
    public function testClosedPeriodOverrideIsSessionOnly(): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            "INSERT INTO accounting_periods (supplier_id, fiscal_year, starts_on, ends_on, status)
             VALUES (?, ?, ?, ?, 'closed')"
        )->execute([$this->supplierId, self::CLOSED_YEAR, self::CLOSED_YEAR . '-01-01', self::CLOSED_YEAR . '-12-31']);
        $date = self::CLOSED_YEAR . '-06-15';
        $id = $this->invoice('draft', $date);
        $body = $this->invoiceBody($date) + ['note_below_items' => 'Uzavřené období (PHPUnit)'];

        $res = $this->call(UpdateInvoiceAction::class, 'PUT', $id, $body, 'bearer');
        self::assertSame(409, $res['status'], 'Bez force platí zámek období beze změny.');

        $res = $this->call(UpdateInvoiceAction::class, 'PUT', $id, $body, 'bearer', ['force' => '1']);
        $this->assertTokenForbidden($res);
        self::assertNull($this->invoiceNote($id));
        $cnt = $pdo->prepare(
            "SELECT COUNT(*) FROM activity_log WHERE action = 'document_lock.force_override' AND entity_id = ?"
        );
        $cnt->execute([$id]);
        self::assertSame(0, (int) $cnt->fetchColumn(), 'Odmítnutý token nesmí po sobě nechat ani audit přebití.');

        $res = $this->call(UpdateInvoiceAction::class, 'PUT', $id, $body, 'session', ['force' => '1']);
        self::assertSame(200, $res['status'], json_encode($res['body'], JSON_UNESCAPED_UNICODE));
        self::assertSame('Uzavřené období (PHPUnit)', $this->invoiceNote($id));
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /** @param array{status:int, body:array<string,mixed>} $res */
    private function assertTokenForbidden(array $res): void
    {
        self::assertSame(403, $res['status'], json_encode($res['body'], JSON_UNESCAPED_UNICODE));
        self::assertSame('token_write_forbidden', $res['body']['error']['code'] ?? null);
        self::assertStringContainsString('v aplikaci', (string) ($res['body']['error']['message'] ?? ''));
    }

    private function assertJournalUnchanged(int $purchaseId, int $entryId): void
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT COUNT(*) FROM journal_entries
              WHERE supplier_id = ? AND source_type = 'purchase_invoice' AND source_id = ?"
        );
        $stmt->execute([$this->supplierId, $purchaseId]);
        self::assertSame(1, (int) $stmt->fetchColumn(), 'Token nesmí v deníku nic stornovat ani přidat.');
        $live = $this->journal->findBySource($this->supplierId, 'purchase_invoice', $purchaseId);
        self::assertSame($entryId, (int) ($live['id'] ?? 0));
        self::assertNull($live['reversed_by'] ?? null);
    }

    private function purchase(string $status, bool $posted): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO purchase_invoices
                (supplier_id, vendor_id, vendor_is_vat_payer, vendor_invoice_number, document_kind,
                 issue_date, tax_date, due_date, received_at, currency_id, reverse_charge, vendor_snapshot,
                 total_without_vat, total_vat, total_with_vat, status, booked_at,
                 vat_classification_code, vat_deduction, created_by)
             VALUES (?, ?, 1, ?, "invoice", ?, ?, ?, ?, ?, 0, "{}", ?, 0, ?, ?, ?, "40", "full", ?)'
        )->execute([
            $this->supplierId, $this->vendorId, 'TOKEN-GUARD-' . bin2hex(random_bytes(3)),
            $this->date, $this->date, $this->date, $this->date, $this->czkId,
            self::AMOUNT, self::AMOUNT, $status, $status === 'booked' ? $this->date . ' 10:00:00' : null,
            $this->userId,
        ]);
        $id = (int) $pdo->lastInsertId();

        $pdo->prepare(
            'INSERT INTO purchase_invoice_items
                (purchase_invoice_id, description, quantity, unit, unit_price_without_vat, vat_rate_id,
                 vat_rate_snapshot, total_without_vat, total_vat, total_with_vat, order_index,
                 vat_classification_code)
             VALUES (?, "Služba (PHPUnit)", 1, "ks", ?, ?, 0, ?, 0, ?, 0, "40")'
        )->execute([$id, self::AMOUNT, $this->vatRateId, self::AMOUNT, self::AMOUNT]);

        if ($posted) {
            $lines = $this->posting->buildFromPurchaseInvoice($this->supplierId, $id);
            $this->posting->postDocument($this->supplierId, 'purchase_invoice', $id, $lines, [
                'entry_date' => $this->date,
                'document_date' => $this->date,
                'posted_by' => $this->userId,
                'user_id' => $this->userId,
            ]);
        }

        return $id;
    }

    /** @return array<string,mixed> */
    private function purchaseBody(int $id, string $vendorInvoiceNumber, float $price): array
    {
        $row = $this->purchaseRow($id);

        return [
            'vendor_id' => $this->vendorId,
            'vendor_invoice_number' => $vendorInvoiceNumber,
            'document_kind' => 'invoice',
            'issue_date' => $row['issue_date'],
            'tax_date' => $row['tax_date'],
            'due_date' => $row['due_date'],
            'received_at' => $row['received_at'],
            'currency_id' => $this->czkId,
            'vat_classification_code' => '40',
            'items' => [[
                'description' => 'Služba (PHPUnit)', 'quantity' => 1, 'unit' => 'ks',
                'unit_price_without_vat' => $price, 'vat_rate_id' => $this->vatRateId,
                'vat_classification_code' => '40',
            ]],
        ];
    }

    private function issuedInvoice(string $date): int
    {
        return $this->invoice('issued', $date, 'TG' . random_int(10000000, 99999999));
    }

    private function invoice(string $status, string $date, ?string $varsymbol = null): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO invoices (supplier_id, invoice_type, client_id, varsymbol, issue_date, tax_date,
                                   due_date, currency_id, status, total_with_vat, created_by)
             VALUES (?, "invoice", ?, ?, ?, ?, DATE_ADD(?, INTERVAL 14 DAY), ?, ?, 0, ?)'
        )->execute([
            $this->supplierId, $this->vendorId, $varsymbol, $date, $date, $date, $this->czkId, $status, $this->userId,
        ]);

        return (int) $pdo->lastInsertId();
    }

    /** @return array<string,mixed> */
    private function invoiceBody(string $date): array
    {
        return [
            'client_id' => $this->vendorId,
            'invoice_type' => 'invoice',
            'issue_date' => $date,
            'tax_date' => $date,
            'due_date' => $date,
            'currency_id' => $this->czkId,
            'items' => [],
        ];
    }

    private function invoiceNote(int $id): ?string
    {
        $note = $this->db->pdo()->query("SELECT note_below_items FROM invoices WHERE id = $id")->fetchColumn();

        return ($note === false || $note === null || $note === '') ? null : (string) $note;
    }

    /** @return array<string,mixed> */
    private function purchaseRow(int $id): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM purchase_invoices WHERE id = ? AND supplier_id = ?');
        $stmt->execute([$id, $this->supplierId]);

        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
    }

    private function firstItemId(int $purchaseId): int
    {
        return (int) $this->db->pdo()
            ->query("SELECT id FROM purchase_invoice_items WHERE purchase_invoice_id = $purchaseId ORDER BY id LIMIT 1")
            ->fetchColumn();
    }

    private function itemKind(int $itemId): ?string
    {
        $kind = $this->db->pdo()->query("SELECT expense_kind FROM purchase_invoice_items WHERE id = $itemId")->fetchColumn();

        return ($kind === false || $kind === null) ? null : (string) $kind;
    }

    /**
     * @param class-string          $actionClass
     * @param array<string,mixed>   $body
     * @param array<string,string>  $query
     * @return array{status:int, body:array<string,mixed>}
     */
    private function call(
        string $actionClass,
        string $method,
        int $id,
        array $body,
        string $authMethod,
        array $query = [],
    ): array {
        $action = $this->container->get($actionClass);
        $req = (new ServerRequestFactory())
            ->createServerRequest($method, '/api/test/' . $id)
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin'])
            ->withAttribute(AuthMiddleware::ATTR_METHOD, $authMethod)
            ->withQueryParams($query)
            ->withParsedBody($body);

        return self::decode($action($req, new Psr7Response(), ['id' => (string) $id]));
    }

    /** @return array{status:int, body:array<string,mixed>} */
    private static function decode(ResponseInterface $response): array
    {
        $response->getBody()->rewind();
        $decoded = json_decode((string) $response->getBody(), true);

        return ['status' => $response->getStatusCode(), 'body' => is_array($decoded) ? $decoded : []];
    }
}
