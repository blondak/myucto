<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Action\Document\DocumentsAction;
use MyInvoice\Action\Document\LinkSearchAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\DocumentLinkRepository;
use MyInvoice\Repository\DocumentRepository;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\EffectiveRole;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

#[Group('integration')]
final class OtherItemDocumentAttachmentTest extends TestCase
{
    private Connection $db;
    private DocumentsAction $documentsAction;
    private LinkSearchAction $linkSearch;
    private DocumentLinkRepository $links;
    private DocumentRepository $documents;
    private int $supplierA;
    private int $supplierB;
    private int $itemA;
    private int $itemB;
    private int $documentA;
    private int $userId;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('Test vyžaduje místní databázi.');
        }
        $container = Bootstrap::buildApp()->getContainer();
        $this->db = $container->get(Connection::class);
        $this->documentsAction = $container->get(DocumentsAction::class);
        $this->linkSearch = $container->get(LinkSearchAction::class);
        $this->links = $container->get(DocumentLinkRepository::class);
        $this->documents = $container->get(DocumentRepository::class);
        $pdo = $this->db->pdo();
        $currencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE code = 'CZK' LIMIT 1")->fetchColumn() ?: 0);
        $vatRateId = (int) ($pdo->query('SELECT id FROM vat_rates LIMIT 1')->fetchColumn() ?: 0);
        $countryId = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT id FROM users LIMIT 1')->fetchColumn() ?: 0);
        if ($currencyId === 0 || $vatRateId === 0 || $countryId === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí referenční data.');
        }
        $pdo->beginTransaction();
        $supplier = $pdo->prepare(
            "INSERT INTO supplier (company_name, street, city, zip, country_id, email, default_currency_id, default_vat_rate_id)
             VALUES (?, 'Testovací 1', 'Praha', '11000', ?, 'other-item-docs@example.invalid', ?, ?)"
        );
        $supplier->execute(['Dokumenty ostatní A', $countryId, $currencyId, $vatRateId]);
        $this->supplierA = (int) $pdo->lastInsertId();
        $supplier->execute(['Dokumenty ostatní B', $countryId, $currencyId, $vatRateId]);
        $this->supplierB = (int) $pdo->lastInsertId();
        $insert = $pdo->prepare(
            "INSERT INTO other_items (supplier_id, side, kind, title, issued_on, due_on, amount)
             VALUES (?, 'payable', 'other', ?, '2093-03-15', '2093-04-15', 500)"
        );
        $insert->execute([$this->supplierA, 'Syntetický nájem A']);
        $this->itemA = (int) $pdo->lastInsertId();
        $insert->execute([$this->supplierB, 'Syntetický nájem B']);
        $this->itemB = (int) $pdo->lastInsertId();
        $this->documentA = $this->documents->insert([
            'supplier_id' => $this->supplierA,
            'folder_id' => null,
            'title' => 'Syntetická smlouva',
            'description' => null,
            'original_name' => 'smlouva.pdf',
            'filename' => str_repeat('e', 64),
            'sha256' => str_repeat('e', 64),
            'mime_type' => 'application/pdf',
            'size_bytes' => 100,
            'doc_type' => 'pdf',
            'uploaded_by' => $this->userId,
            'scope' => 'company',
            'owner_user_id' => null,
        ]);
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $pdo = $this->db->pdo();
            if ($pdo->inTransaction()) $pdo->rollBack();
            $this->db->close();
        }
    }

    public function testTenantGuardAndPermissionsAcrossDocumentEndpoints(): void
    {
        $write = ['documents' => 2, 'documents.move' => 2, 'other_items' => 2];
        $read = ['documents' => 1, 'other_items' => 1];
        $noItems = ['documents' => 2, 'documents.move' => 2];

        self::assertTrue($this->links->entityBelongsToSupplier('other_item', $this->itemA, $this->supplierA));
        self::assertFalse($this->links->entityBelongsToSupplier('other_item', $this->itemB, $this->supplierA));
        self::assertSame(404, $this->call('addLink', 'POST', $write, ['id' => $this->documentA],
            ['entity_type' => 'other_item', 'entity_id' => $this->itemB])->getStatusCode());
        self::assertSame(403, $this->call('addLink', 'POST', $read, ['id' => $this->documentA],
            ['entity_type' => 'other_item', 'entity_id' => $this->itemA])->getStatusCode());

        $added = $this->call('addLink', 'POST', $write, ['id' => $this->documentA],
            ['entity_type' => 'other_item', 'entity_id' => $this->itemA]);
        self::assertSame(200, $added->getStatusCode());
        self::assertSame('Syntetický nájem A', $this->data($added)['links'][0]['label']);
        self::assertSame([], $this->data($this->call('get', 'GET', $noItems, ['id' => $this->documentA]))['links']);
        self::assertSame(403, $this->call('byEntity', 'GET', $noItems,
            ['type' => 'other_item', 'id' => $this->itemA])->getStatusCode());
        self::assertSame(403, $this->call('removeLink', 'DELETE', $noItems, ['id' => $this->documentA],
            ['entity_type' => 'other_item', 'entity_id' => $this->itemA])->getStatusCode());
        self::assertCount(1, $this->data($this->call('byEntity', 'GET', $read,
            ['type' => 'other_item', 'id' => $this->itemA]))['documents']);

        $query = ['q' => 'Syntetický nájem', 'types' => 'other_item'];
        self::assertSame([], $this->data(($this->linkSearch)($this->request('GET', $noItems)->withQueryParams($query), new Response()))['results']);
        $results = $this->data(($this->linkSearch)($this->request('GET', $read)->withQueryParams($query), new Response()))['results'];
        self::assertSame([$this->itemA], array_column($results, 'entity_id'));

        $this->db->pdo()->prepare('UPDATE other_items SET deleted_at = NOW() WHERE id = ?')->execute([$this->itemA]);
        self::assertSame([], $this->links->linksForDocument($this->documentA, $this->supplierA));
    }

    public function testDatabaseRejectsForeignOtherItemLink(): void
    {
        $insert = $this->db->pdo()->prepare(
            'INSERT INTO document_links (document_id, supplier_id, entity_type, entity_id) VALUES (?, ?, ?, ?)'
        );
        try {
            $insert->execute([$this->documentA, $this->supplierA, 'other_item', $this->itemB]);
            self::fail('Trigger musí odmítnout cizí položku.');
        } catch (\PDOException $e) {
            self::assertSame('45000', $e->errorInfo[0] ?? null);
        }
    }

    private function request(string $method, array $permissions): ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest($method, '/api/documents')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierA)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'accountant'])
            ->withAttribute('auth.effective_role', new EffectiveRole(0, 'Test', 'staff', true, $permissions, 'custom'));
    }

    private function call(string $method, string $http, array $permissions, array $args, array $body = []): ResponseInterface
    {
        return $this->documentsAction->{$method}($this->request($http, $permissions)->withParsedBody($body), new Response(), $args);
    }

    private function data(ResponseInterface $response): array
    {
        $response->getBody()->rewind();
        $body = json_decode((string) $response->getBody(), true);
        self::assertIsArray($body);
        return $body['data'] ?? $body;
    }
}
