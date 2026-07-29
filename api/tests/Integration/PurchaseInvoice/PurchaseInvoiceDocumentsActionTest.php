<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\PurchaseInvoice;

use MyInvoice\Action\PurchaseInvoice\PurchaseInvoiceDocumentsAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\DocumentRepository;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

/**
 * Epic F7 §6 — PF ↔ DMS provázání (PurchaseInvoiceDocumentsAction). Ověřuje
 * list/link/unlink happy path přes document_links(entity_type='purchase_invoice')
 * a cross-tenant izolaci (cizí tenant → 404, žádná vazba se nezaloží).
 *
 * Volá Action přímo z DI. Používá existující PF tenanta (skip, když žádná není);
 * vytvořený DMS doklad + document_links řádky se uklidí. Soft-skip bez cfg.php.
 */
#[Group('integration')]
final class PurchaseInvoiceDocumentsActionTest extends TestCase
{
    private Connection $db;
    private PurchaseInvoiceDocumentsAction $action;
    private DocumentRepository $docs;

    private int $supplierId = 0;
    private int $userId = 0;
    private int $pfId = 0;
    private int $docId = 0;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $c = Bootstrap::buildApp()->getContainer();
            $this->db     = $c->get(Connection::class);
            $this->action = $c->get(PurchaseInvoiceDocumentsAction::class);
            $this->docs   = $c->get(DocumentRepository::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }
        $pdo = $this->db->pdo();
        $this->supplierId = (int) ($pdo->query('SELECT MIN(id) FROM supplier')->fetchColumn() ?: 0);
        $this->userId     = (int) ($pdo->query('SELECT MIN(id) FROM users')->fetchColumn() ?: 0);
        if ($this->supplierId === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí supplier/user.');
        }
        $stmt = $pdo->prepare('SELECT id FROM purchase_invoices WHERE supplier_id = ? ORDER BY id LIMIT 1');
        $stmt->execute([$this->supplierId]);
        $this->pfId = (int) ($stmt->fetchColumn() ?: 0);
        if ($this->pfId === 0) {
            $this->markTestSkipped('Žádná přijatá faktura v DB — test PF↔DMS přeskočen.');
        }

        // DMS doklad k přivěšení (bez fyzického souboru — link/list nesahá na disk).
        $this->docId = $this->docs->insert([
            'supplier_id'   => $this->supplierId,
            'folder_id'     => null,
            'title'         => 'F7PFDMSDOC',
            'description'   => null,
            'original_name' => 'x.pdf',
            'filename'      => str_repeat('e', 64),
            'sha256'        => str_repeat('e', 64),
            'mime_type'     => 'application/pdf',
            'size_bytes'    => 100,
            'doc_type'      => 'pdf',
            'uploaded_by'   => $this->userId,
            'scope'         => 'company',
            'owner_user_id' => null,
        ]);
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $pdo = $this->db->pdo();
            if ($this->docId > 0) {
                $pdo->prepare('DELETE FROM document_links WHERE document_id = ?')->execute([$this->docId]);
                $pdo->prepare('DELETE FROM documents WHERE id = ?')->execute([$this->docId]);
            }
            $this->db->close();
        }
    }

    public function testLinkListUnlink(): void
    {
        // Zpočátku žádná vazba.
        $empty = $this->call('list', 'GET', $this->supplierId, ['id' => (string) $this->pfId]);
        self::assertSame(200, $empty['status']);
        self::assertNotContains($this->docId, $this->docIds($empty['body']));

        // Link.
        $linked = $this->call('link', 'POST', $this->supplierId, ['id' => (string) $this->pfId],
            ['document_id' => $this->docId]);
        self::assertSame(200, $linked['status']);
        self::assertContains($this->docId, $this->docIds($linked['body']));

        // List znovu → obsahuje.
        $list = $this->call('list', 'GET', $this->supplierId, ['id' => (string) $this->pfId]);
        self::assertContains($this->docId, $this->docIds($list['body']));

        // Unlink.
        $unlinked = $this->call('unlink', 'DELETE', $this->supplierId, ['id' => (string) $this->pfId],
            ['document_id' => $this->docId]);
        self::assertSame(200, $unlinked['status']);
        self::assertNotContains($this->docId, $this->docIds($unlinked['body']));
    }

    public function testCrossTenantIsolation(): void
    {
        // Cizí tenant → PF „nepatří" → 404 na list i link (žádná vazba se nezaloží).
        $foreign = $this->supplierId + 99999;
        $list = $this->call('list', 'GET', $foreign, ['id' => (string) $this->pfId]);
        self::assertSame(404, $list['status']);

        $link = $this->call('link', 'POST', $foreign, ['id' => (string) $this->pfId], ['document_id' => $this->docId]);
        self::assertSame(404, $link['status']);

        $cnt = (int) ($this->db->pdo()->query(
            "SELECT COUNT(*) FROM document_links WHERE document_id = {$this->docId}
              AND entity_type = 'purchase_invoice' AND entity_id = {$this->pfId}"
        )->fetchColumn());
        self::assertSame(0, $cnt, 'cizí tenant nezaložil vazbu');
    }

    public function testLinkRejectsForeignDocument(): void
    {
        // document_id neexistující/neviditelný → 404 document_not_found.
        $res = $this->call('link', 'POST', $this->supplierId, ['id' => (string) $this->pfId],
            ['document_id' => 999999999]);
        self::assertSame(404, $res['status']);
        self::assertSame('document_not_found', $res['body']['error']['code'] ?? null);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /** @return list<int> */
    private function docIds(array $body): array
    {
        return array_map(static fn($d) => (int) $d['id'], $body['documents'] ?? []);
    }

    /**
     * @param array<string,string> $args
     * @param array<string,mixed>  $body
     * @return array{status:int, body:array<string,mixed>}
     */
    private function call(string $method, string $http, int $sid, array $args, array $body = []): array
    {
        $req = (new ServerRequestFactory())
            ->createServerRequest($http, '/api/purchase-invoices')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $sid)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'accountant']);
        if ($body !== []) $req = $req->withParsedBody($body);
        $resp = $this->action->{$method}($req, new Psr7Response(), $args);
        $resp->getBody()->rewind();
        $decoded = json_decode((string) $resp->getBody(), true);
        return ['status' => $resp->getStatusCode(), 'body' => is_array($decoded) ? $decoded : []];
    }
}
