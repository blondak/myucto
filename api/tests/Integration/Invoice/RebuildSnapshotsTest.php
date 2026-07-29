<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Invoice;

use MyInvoice\Action\Invoice\RebuildInvoiceSnapshotsAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

/**
 * POST /api/invoices/{id}/rebuild-snapshots — obnova snapshotů stran u vystaveného
 * dokladu bez odemykání formuláře.
 *
 * Ověřuje to podstatné: snapshot se přepíše ze ŽIVÝCH dat, ale částky, stav ani
 * variabilní symbol se nehnou; koncept a storno se odmítnou; neadmin nesmí.
 *
 * Izolace v roce 2097 pod existujícím supplierem, úklid v tearDown.
 */
#[Group('integration')]
final class RebuildSnapshotsTest extends TestCase
{
    private const YEAR = 2097;

    private Connection $db;
    private RebuildInvoiceSnapshotsAction $action;

    private int $supplierId = 0;
    private int $clientId = 0;
    private int $currencyId = 0;
    private int $userId = 0;
    /** @var int[] */
    private array $created = [];
    private string $origClientName = '';

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $container = Bootstrap::buildApp()->getContainer();
            $this->db = $container->get(Connection::class);
            $this->action = $container->get(RebuildInvoiceSnapshotsAction::class);
        } catch (\Exception $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $this->supplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId     = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $stmt = $pdo->prepare('SELECT id, company_name FROM clients WHERE supplier_id = ? AND archived_at IS NULL ORDER BY id LIMIT 1');
        $stmt->execute([$this->supplierId]);
        $client = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $this->clientId = (int) ($client['id'] ?? 0);
        $this->origClientName = (string) ($client['company_name'] ?? '');
        $curStmt = $pdo->prepare('SELECT id FROM currencies WHERE supplier_id = ? AND is_active = 1 ORDER BY id LIMIT 1');
        $curStmt->execute([$this->supplierId]);
        $this->currencyId = (int) ($curStmt->fetchColumn() ?: 0);

        if ($this->supplierId === 0 || $this->userId === 0 || $this->clientId === 0 || $this->currencyId === 0) {
            $this->markTestSkipped('Chybí základní data (supplier/user/client/currency).');
        }
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) {
            return;
        }
        $pdo = $this->db->pdo();
        if ($this->created !== []) {
            $ph = implode(',', array_fill(0, count($this->created), '?'));
            $pdo->prepare("DELETE FROM invoice_pdfs WHERE invoice_id IN ($ph)")->execute($this->created);
            $pdo->prepare("DELETE FROM invoices WHERE id IN ($ph)")->execute($this->created);
        }
        if ($this->origClientName !== '') {
            $pdo->prepare('UPDATE clients SET company_name = ? WHERE id = ?')
                ->execute([$this->origClientName, $this->clientId]);
        }
        $this->db->close();
    }

    public function testAdminRefreshesSnapshotFromLiveClientWithoutTouchingAmounts(): void
    {
        $id = $this->invoice('issued', '{"company_name":"Zastaralý název s.r.o."}');

        $renamed = 'Nový název po přejmenování s.r.o.';
        $this->db->pdo()->prepare('UPDATE clients SET company_name = ? WHERE id = ?')
            ->execute([$renamed, $this->clientId]);

        $before = $this->row($id);
        $res = $this->call($id, 'admin');

        self::assertSame(200, $res['status'], json_encode($res['body'], JSON_UNESCAPED_UNICODE));

        $after = $this->row($id);
        $snapshot = json_decode((string) $after['client_snapshot'], true);
        self::assertIsArray($snapshot);
        self::assertSame($renamed, $snapshot['company_name'] ?? null, 'Snapshot se má přepsat ze živých dat klienta.');

        // Doklad se jinak NESMÍ hnout — to je celý smysl oproti force-editu.
        foreach (['status', 'varsymbol', 'total_without_vat', 'total_with_vat', 'issue_date', 'due_date'] as $field) {
            self::assertSame($before[$field], $after[$field], "Pole {$field} se nesmí změnit.");
        }
    }

    public function testActionIsAudited(): void
    {
        $id = $this->invoice('issued', '{"company_name":"Před"}');
        $this->db->pdo()->prepare('UPDATE clients SET company_name = ? WHERE id = ?')
            ->execute(['Po přejmenování s.r.o.', $this->clientId]);

        self::assertSame(200, $this->call($id, 'admin')['status']);

        $stmt = $this->db->pdo()->prepare(
            "SELECT payload FROM activity_log
              WHERE action = 'invoice.rebuild_snapshots' AND entity_type = 'invoice' AND entity_id = ?
              ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([$id]);
        $payload = $stmt->fetchColumn();
        self::assertNotFalse($payload, 'Akce musí být v auditní stopě.');

        $decoded = json_decode((string) $payload, true);
        self::assertIsArray($decoded);
        self::assertArrayHasKey('old_snapshot', $decoded, 'Audit nese původní snapshot.');
        self::assertArrayHasKey('new_snapshot', $decoded, 'Audit nese nový snapshot.');
        self::assertContains('client', $decoded['changed'] ?? [], 'Audit má říct, která část se změnila.');
    }

    public function testNonAdminIsRejected(): void
    {
        $id = $this->invoice('issued', '{"company_name":"X"}');
        $res = $this->call($id, 'accountant');

        self::assertSame(403, $res['status']);
        self::assertSame('forbidden', $res['body']['error']['code'] ?? null);
    }

    public function testDraftIsRejectedAsNotApplicable(): void
    {
        $id = $this->invoice('draft', null);
        $res = $this->call($id, 'admin');

        self::assertSame(409, $res['status']);
        self::assertSame('not_applicable', $res['body']['error']['code'] ?? null);
    }

    public function testCancelledStaysImmutable(): void
    {
        $id = $this->invoice('cancelled', '{"company_name":"X"}');
        $res = $this->call($id, 'admin');

        self::assertSame(409, $res['status']);
        self::assertSame('not_editable', $res['body']['error']['code'] ?? null);
    }

    public function testForeignSupplierGetsNotFound(): void
    {
        $id = $this->invoice('issued', '{"company_name":"X"}');

        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/invoices/' . $id . '/rebuild-snapshots')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId + 9999)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin']);
        $response = ($this->action)($request, new Psr7Response(), ['id' => (string) $id]);

        self::assertSame(404, $response->getStatusCode(), 'Cizí firma nesmí doklad ani vidět.');
    }

    /** @return array{status:int, body:array<string,mixed>} */
    private function call(int $id, string $role): array
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/invoices/' . $id . '/rebuild-snapshots')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => $role]);
        $response = ($this->action)($request, new Psr7Response(), ['id' => (string) $id]);
        $response->getBody()->rewind();
        $decoded = json_decode((string) $response->getBody(), true);
        return ['status' => $response->getStatusCode(), 'body' => is_array($decoded) ? $decoded : []];
    }

    /** @return array<string,mixed> */
    private function row(int $id): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT status, varsymbol, total_without_vat, total_with_vat, issue_date, due_date, client_snapshot
               FROM invoices WHERE id = ?'
        );
        $stmt->execute([$id]);
        return (array) $stmt->fetch(PDO::FETCH_ASSOC);
    }

    private function invoice(string $status, ?string $clientSnapshot): int
    {
        $date = sprintf('%04d-05-10', self::YEAR);
        $this->db->pdo()->prepare(
            "INSERT INTO invoices
                (invoice_type, varsymbol, client_id, supplier_id, issue_date, tax_date, due_date,
                 currency_id, status, total_without_vat, total_with_vat, client_snapshot,
                 supplier_snapshot, created_by)
             VALUES ('invoice', ?, ?, ?, ?, ?, ?, ?, ?, 1000, 1210, ?, '{}', ?)"
        )->execute([
            'RS' . self::YEAR . substr((string) microtime(true), -6),
            $this->clientId, $this->supplierId, $date, $date, $date,
            $this->currencyId, $status, $clientSnapshot, $this->userId,
        ]);
        $id = (int) $this->db->pdo()->lastInsertId();
        $this->created[] = $id;
        return $id;
    }
}
