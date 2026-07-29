<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Action\Portal\PortalDocumentRequestAction;
use MyInvoice\Repository\DocumentRequestRepository;
use MyInvoice\Repository\PurchaseInvoiceRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Import\AiPdfExtractor;
use MyInvoice\Service\IpMatcher;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\UploadedFile;

/**
 * Vyžádání chybějících dokladů od klienta (Fáze F, audit 2026-07, nález
 * „Vyžádání chybějících dokladů od klienta (urgence přes portál)").
 *
 * Ověřuje:
 *  - založení požadavku (ručně i s vazbou na bankovní transakci),
 *  - přechod requested → uploaded skrz PortalDocumentRequestAction::upload()
 *    (reuse AI extrakce mockem — status/vazba se nastaví, i když AI je mockovaná),
 *  - resolve/reopen přechody,
 *  - KRITICKÉ: tenant izolace — listForSupplier i upload() nikdy nevidí/neovlivní
 *    požadavek jiné firmy (fail-closed 404, ne 403 — neprozrazuje existenci),
 *  - badge počty (openCounts) pro dashboard obou stran.
 *
 * Vše v jedné transakci, tearDown rollbackne. Soft-skip bez cfg.php.
 */
#[Group('integration')]
#[AllowMockObjectsWithoutExpectations]
final class DocumentRequestTest extends TestCase
{
    private Connection $db;
    private DocumentRequestRepository $repo;
    private PurchaseInvoiceRepository $purchaseRepo;
    private ActivityLogger $activity;
    private IpMatcher $ipMatcher;

    private int $supplierA = 0;
    private int $supplierB = 0;
    private int $userId = 0;
    private int $currencyId = 0;
    private int $czId = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 3);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $container = Bootstrap::buildApp()->getContainer();
            $this->db           = $container->get(Connection::class);
            $this->repo          = $container->get(DocumentRequestRepository::class);
            $this->purchaseRepo  = $container->get(PurchaseInvoiceRepository::class);
            $this->activity      = $container->get(ActivityLogger::class);
            $this->ipMatcher     = $container->get(IpMatcher::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $baseSupplier = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId     = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->currencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE code='CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->czId       = (int) ($pdo->query("SELECT id FROM countries WHERE iso2='CZ' LIMIT 1")->fetchColumn() ?: 0);
        if ($baseSupplier === 0 || $this->userId === 0 || $this->currencyId === 0 || $this->czId === 0) {
            $this->markTestSkipped('Chybí základní data v DB.');
        }

        $pdo->beginTransaction();
        $this->inTx = true;

        $mk = static function (string $name) use ($pdo, $baseSupplier): int {
            $stmt = $pdo->prepare(
                'INSERT INTO supplier (company_name, street, city, zip, country_id, email, default_currency_id, default_vat_rate_id)
                 SELECT ?, "Testovací", "Praha", "11000", country_id, ?, default_currency_id, default_vat_rate_id
                   FROM supplier WHERE id = ?'
            );
            $stmt->execute([$name, strtolower(str_replace(' ', '', $name)) . '@example.com', $baseSupplier]);
            return (int) $pdo->lastInsertId();
        };
        $this->supplierA = $mk('DocReq Firma A');
        $this->supplierB = $mk('DocReq Firma B');
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

    public function testCreateStoresFieldsAndIsFindable(): void
    {
        $id = $this->repo->create($this->supplierA, [
            'description'  => 'Chybí doklad k platbě 4 520 Kč z 12. 6.',
            'amount'       => 4520.0,
            'context_date' => '2099-06-12',
            'deadline'     => '2099-06-30',
            'bank_transaction_id' => null,
        ], $this->userId);

        $found = $this->repo->find($id, $this->supplierA);
        self::assertNotNull($found);
        self::assertSame('requested', $found['status']);
        self::assertSame(4520.0, (float) $found['amount']);
        self::assertSame($this->userId, (int) $found['created_by']);
        self::assertNull($found['purchase_invoice_id']);
    }

    public function testUploadTransitionsRequestedToUploadedAndLinksDocument(): void
    {
        $id = $this->repo->create($this->supplierA, [
            'description' => 'Chybí doklad k platbě', 'amount' => null,
            'context_date' => null, 'deadline' => null, 'bank_transaction_id' => null,
        ], $this->userId);

        $piId = $this->createPurchaseInvoiceDraft($this->supplierA, 'DOCREQ-UP-1');

        $action = $this->buildPortalAction($piId);
        $request = $this->uploadRequest($this->supplierA, $id);
        $response = $action->upload($request, (new ResponseFactory())->createResponse(), ['id' => (string) $id]);

        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());

        $reloaded = $this->repo->find($id, $this->supplierA);
        self::assertNotNull($reloaded);
        self::assertSame('uploaded', $reloaded['status'], 'Upload musí přepnout stav requested → uploaded.');
        self::assertSame($piId, (int) $reloaded['purchase_invoice_id'], 'Vazba na vzniklý doklad se musí uložit.');
    }

    public function testUploadOnRequestOfOtherSupplierIsNotFound(): void
    {
        // Požadavek patří firmě B; klient přihlášený jako firma A ho nesmí ani vidět, ani ovlivnit.
        $idB = $this->repo->create($this->supplierB, [
            'description' => 'Cizí požadavek', 'amount' => null,
            'context_date' => null, 'deadline' => null, 'bank_transaction_id' => null,
        ], $this->userId);

        $piId = $this->createPurchaseInvoiceDraft($this->supplierA, 'DOCREQ-XT-1');
        $action = $this->buildPortalAction($piId);
        $request = $this->uploadRequest($this->supplierA, $idB);
        $response = $action->upload($request, (new ResponseFactory())->createResponse(), ['id' => (string) $idB]);

        self::assertSame(404, $response->getStatusCode(), 'Cizí požadavek musí vrátit 404 (fail-closed, ne 403 — neprozrazuje existenci).');

        // Ověř, že se přes cizí upload nic nezměnilo.
        $stillRequested = $this->repo->find($idB, $this->supplierB);
        self::assertSame('requested', $stillRequested['status']);
    }

    public function testListForSupplierOnlyReturnsOwnRequestsTenantIsolation(): void
    {
        $idA = $this->repo->create($this->supplierA, [
            'description' => 'Firma A doklad', 'amount' => null,
            'context_date' => null, 'deadline' => null, 'bank_transaction_id' => null,
        ], $this->userId);
        $idB = $this->repo->create($this->supplierB, [
            'description' => 'Firma B doklad', 'amount' => null,
            'context_date' => null, 'deadline' => null, 'bank_transaction_id' => null,
        ], $this->userId);

        $listA = $this->repo->listForSupplier($this->supplierA);
        $listB = $this->repo->listForSupplier($this->supplierB);

        $idsA = array_column($listA, 'id');
        $idsB = array_column($listB, 'id');
        self::assertContains($idA, $idsA);
        self::assertNotContains($idB, $idsA, 'Firma A nesmí vidět požadavky firmy B.');
        self::assertContains($idB, $idsB);
        self::assertNotContains($idA, $idsB, 'Firma B nesmí vidět požadavky firmy A.');

        // find() musí být stejně tenant-scoped.
        self::assertNull($this->repo->find($idB, $this->supplierA));
        self::assertNull($this->repo->find($idA, $this->supplierB));
    }

    public function testResolveAndReopenTransitions(): void
    {
        $id = $this->repo->create($this->supplierA, [
            'description' => 'K vyřízení', 'amount' => null,
            'context_date' => null, 'deadline' => null, 'bank_transaction_id' => null,
        ], $this->userId);

        self::assertTrue($this->repo->resolve($id, $this->supplierA, $this->userId));
        $resolved = $this->repo->find($id, $this->supplierA);
        self::assertSame('resolved', $resolved['status']);
        self::assertNotNull($resolved['resolved_at']);
        self::assertSame($this->userId, (int) $resolved['resolved_by']);

        // Druhé resolve je no-op (žádný řádek ve stavu != resolved).
        self::assertFalse($this->repo->resolve($id, $this->supplierA, $this->userId));

        self::assertTrue($this->repo->reopen($id, $this->supplierA));
        $reopened = $this->repo->find($id, $this->supplierA);
        self::assertSame('requested', $reopened['status']);
        self::assertNull($reopened['resolved_at']);

        // Cizí firma nesmí resolve/reopen ovlivnit vůbec (tenant scope v UPDATE WHERE).
        self::assertFalse($this->repo->resolve($id, $this->supplierB, $this->userId));
    }

    public function testOpenCountsBadgeForDashboards(): void
    {
        $this->repo->create($this->supplierA, [
            'description' => 'Bez termínu', 'amount' => null,
            'context_date' => null, 'deadline' => null, 'bank_transaction_id' => null,
        ], $this->userId);
        $overdueId = $this->repo->create($this->supplierA, [
            'description' => 'Po termínu', 'amount' => null,
            'context_date' => null, 'deadline' => '2000-01-01', 'bank_transaction_id' => null,
        ], $this->userId);
        $resolvedId = $this->repo->create($this->supplierA, [
            'description' => 'Vyřízeno', 'amount' => null,
            'context_date' => null, 'deadline' => null, 'bank_transaction_id' => null,
        ], $this->userId);
        $this->repo->resolve($resolvedId, $this->supplierA, $this->userId);

        $counts = $this->repo->openCounts($this->supplierA);
        self::assertSame(2, $counts['open'], 'Vyřízený požadavek se do open nepočítá.');
        self::assertSame(1, $counts['overdue']);

        // Jiná firma vidí nulu — badge nesmí prosakovat mezi tenanty.
        $countsB = $this->repo->openCounts($this->supplierB);
        self::assertSame(0, $countsB['open']);
        self::assertSame(0, $countsB['overdue']);
    }

    public function testDeleteRemovesRequestTenantScoped(): void
    {
        $id = $this->repo->create($this->supplierA, [
            'description' => 'Ke smazání', 'amount' => null,
            'context_date' => null, 'deadline' => null, 'bank_transaction_id' => null,
        ], $this->userId);

        self::assertFalse($this->repo->delete($id, $this->supplierB), 'Cizí firma nesmí smazat požadavek jiné firmy.');
        self::assertNotNull($this->repo->find($id, $this->supplierA));

        self::assertTrue($this->repo->delete($id, $this->supplierA));
        self::assertNull($this->repo->find($id, $this->supplierA));
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function buildPortalAction(int $extractedPurchaseInvoiceId): PortalDocumentRequestAction
    {
        $extractor = $this->createMock(AiPdfExtractor::class);
        $extractor->method('extractAndCreate')->willReturn([
            'ok' => true,
            'purchase_invoice_id' => $extractedPurchaseInvoiceId,
            'source' => 'ai',
        ]);
        return new PortalDocumentRequestAction($this->repo, $extractor, $this->activity, $this->ipMatcher);
    }

    private function uploadRequest(int $supplierId, int $requestId): \Psr\Http\Message\ServerRequestInterface
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'docreq');
        file_put_contents($tmpFile, '%PDF-1.4 test');
        $uploaded = new UploadedFile($tmpFile, 'doklad.pdf', 'application/pdf', (int) filesize($tmpFile), UPLOAD_ERR_OK);

        return (new ServerRequestFactory())
            ->createServerRequest('POST', "/api/portal/document-requests/{$requestId}/upload")
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'client'])
            ->withUploadedFiles(['file' => $uploaded]);
    }

    private function createPurchaseInvoiceDraft(int $supplierId, string $vendorInvoiceNumber): int
    {
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare(
            'INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id,
                                  main_email, language, currency_default_id, is_customer, is_vendor)
             VALUES (?, ?, "Test 1", "Praha", "11000", ?, "vendor@example.com", "cs", ?, 0, 1)'
        );
        $stmt->execute([$supplierId, 'DocReq Vendor', $this->czId, $this->currencyId]);
        $vendorId = (int) $pdo->lastInsertId();

        return $this->purchaseRepo->createDraft([
            'vendor_id'             => $vendorId,
            'vendor_invoice_number' => $vendorInvoiceNumber,
            'document_kind'         => 'invoice',
            'issue_date'            => '2099-06-10',
            'tax_date'              => '2099-06-10',
            'due_date'              => '2099-06-24',
            'currency_id'           => $this->currencyId,
        ], $this->userId, $supplierId);
    }
}
