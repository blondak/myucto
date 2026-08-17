<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Action\Portal\PortalDocumentRequestAction;
use MyInvoice\Action\Portal\PortalPurchaseInvoiceSubmissionAction;
use MyInvoice\Action\PurchaseInvoice\PurchaseInvoiceSubmissionFileAction;
use MyInvoice\Repository\DocumentRequestRepository;
use MyInvoice\Repository\DocumentRepository;
use MyInvoice\Repository\PurchaseInvoiceRepository;
use MyInvoice\Repository\PurchaseInvoiceSubmissionRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Document\DocumentStorage;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\PurchaseInvoice\PurchaseInvoiceSubmissionCompletionService;
use MyInvoice\Service\PurchaseInvoice\PurchaseInvoiceSubmissionException;
use MyInvoice\Service\PurchaseInvoice\PurchaseInvoiceSubmissionUploadService;
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
 *  - přechod requested → uploaded skrz PortalDocumentRequestAction::upload(),
 *    přičemž vznikne jen účetně neutrální staging podání a nikoli faktura,
 *  - ruční dokončení staging podání, vazbu originálu a výsledné faktury,
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
    private DocumentRepository $documents;
    private PurchaseInvoiceRepository $purchaseRepo;
    private PurchaseInvoiceSubmissionRepository $submissions;
    private PurchaseInvoiceSubmissionUploadService $upload;
    private PurchaseInvoiceSubmissionCompletionService $completion;
    private DocumentStorage $storage;
    private ActivityLogger $activity;
    private IpMatcher $ipMatcher;

    private int $supplierA = 0;
    private int $supplierB = 0;
    private int $userId = 0;
    private int $currencyId = 0;
    private int $czId = 0;
    private bool $inTx = false;
    /** @var list<string> */
    private array $createdFiles = [];
    /** @var list<string> */
    private array $temporaryFiles = [];

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
            $this->documents     = $container->get(DocumentRepository::class);
            $this->purchaseRepo  = $container->get(PurchaseInvoiceRepository::class);
            $this->submissions   = $container->get(PurchaseInvoiceSubmissionRepository::class);
            $this->upload        = $container->get(PurchaseInvoiceSubmissionUploadService::class);
            $this->completion    = $container->get(PurchaseInvoiceSubmissionCompletionService::class);
            $this->storage       = $container->get(DocumentStorage::class);
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
        if ($pdo->query("SHOW TABLES LIKE 'purchase_invoice_submissions'")->fetchColumn() === false) {
            $this->markTestSkipped('Chybí migrace purchase_invoice_submissions (1402).');
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
        foreach (array_unique($this->createdFiles) as $path) {
            if (is_file($path)) @unlink($path);
            @rmdir(dirname($path));
            @rmdir(dirname(dirname($path)));
        }
        foreach ($this->temporaryFiles as $path) {
            if (is_file($path)) @unlink($path);
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

    public function testUploadCreatesNeutralSubmissionWithoutPurchaseInvoice(): void
    {
        $id = $this->repo->create($this->supplierA, [
            'description' => 'Chybí doklad k platbě', 'amount' => null,
            'context_date' => null, 'deadline' => null, 'bank_transaction_id' => null,
        ], $this->userId);

        $before = (int) $this->db->pdo()->query(
            'SELECT COUNT(*) FROM purchase_invoices WHERE supplier_id = ' . $this->supplierA
        )->fetchColumn();
        $action = $this->buildPortalAction();
        $request = $this->uploadRequest($this->supplierA, $id);
        $response = $action->upload($request, (new ResponseFactory())->createResponse(), ['id' => (string) $id]);

        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());

        $reloaded = $this->repo->find($id, $this->supplierA);
        self::assertNotNull($reloaded);
        self::assertSame('uploaded', $reloaded['status'], 'Upload musí přepnout stav requested → uploaded.');
        self::assertNull($reloaded['purchase_invoice_id'], 'Před kontrolou účetní nesmí vzniknout účetní doklad.');
        self::assertGreaterThan(0, (int) $reloaded['submission_id']);

        $submission = $this->submissions->find((int) $reloaded['submission_id'], $this->supplierA);
        self::assertNotNull($submission);
        self::assertSame('submitted', $submission['status']);
        self::assertSame('not_started', $submission['extraction_status']);
        $documentStmt = $this->db->pdo()->prepare(
            'SELECT text_status, thumb_status FROM documents WHERE id = ? AND supplier_id = ?'
        );
        $documentStmt->execute([(int) $submission['document_id'], $this->supplierA]);
        $documentState = $documentStmt->fetch(\PDO::FETCH_ASSOC);
        self::assertIsArray($documentState);
        self::assertSame('none', $documentState['text_status'],
            'Převzetí originálu nesmí synchronně spouštět paměťově náročný PDF parser.');
        self::assertSame('none', $documentState['thumb_status'],
            'Staging nepotřebuje při uploadu generovat odvozený náhled; servíruje originál.');
        self::assertSame($before, (int) $this->db->pdo()->query(
            'SELECT COUNT(*) FROM purchase_invoices WHERE supplier_id = ' . $this->supplierA
        )->fetchColumn(), 'Nezpracované podání je mimo náklady, cashflow i DPH, protože nevytvoří purchase_invoice.');
        $fileIdStmt = $this->db->pdo()->prepare(
            "SELECT id FROM document_files WHERE document_id = ? AND supplier_id = ? AND role = 'primary' LIMIT 1"
        );
        $fileIdStmt->execute([(int) $submission['document_id'], $this->supplierA]);
        $fileId = (int) ($fileIdStmt->fetchColumn() ?: 0);
        self::assertGreaterThan(0, $fileId);
        self::assertSame(1, $this->documents->countBySha(
            $this->supplierA,
            (string) $submission['document_sha256'],
            [(int) $submission['document_id']],
            [$fileId],
        ), 'Staging reference musí chránit originální bajty i po odpojení DMS primary řádku.');
        $this->trackSubmissionFile($submission);
    }

    public function testManualCompletionLinksOriginalAndRequestedDocument(): void
    {
        $requestId = $this->repo->create($this->supplierA, [
            'description' => 'Doklad k ručnímu přepisu', 'amount' => null,
            'context_date' => null, 'deadline' => null, 'bank_transaction_id' => null,
        ], $this->userId);
        $response = $this->buildPortalAction()->upload(
            $this->uploadRequest(
                $this->supplierA,
                $requestId,
                'uctenka.png',
                "\x89PNG\r\n\x1A\nsynthetic-" . bin2hex(random_bytes(16)),
            ),
            (new ResponseFactory())->createResponse(),
            ['id' => (string) $requestId],
        );
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());

        $requestRow = $this->repo->find($requestId, $this->supplierA);
        $submissionId = (int) ($requestRow['submission_id'] ?? 0);
        $submission = $this->submissions->find($submissionId, $this->supplierA);
        self::assertNotNull($submission);
        $this->trackSubmissionFile($submission);

        $invoiceId = $this->createPurchaseInvoiceDraft($this->supplierA, 'DOCREQ-MANUAL-1');
        self::assertTrue($this->submissions->claimForManual($submissionId, $this->supplierA));
        $this->completion->complete(
            $submissionId,
            $this->supplierA,
            $invoiceId,
            $this->userId,
            'manual',
        );

        $done = $this->submissions->find($submissionId, $this->supplierA);
        self::assertSame('processed', $done['status']);
        self::assertSame($invoiceId, (int) $done['purchase_invoice_id']);
        $requestRow = $this->repo->find($requestId, $this->supplierA);
        self::assertSame($invoiceId, (int) $requestRow['purchase_invoice_id']);

        $link = $this->db->pdo()->prepare(
            "SELECT 1 FROM document_links WHERE document_id = ? AND entity_type = 'purchase_invoice' AND entity_id = ?"
        );
        $link->execute([(int) $done['document_id'], $invoiceId]);
        self::assertNotFalse($link->fetchColumn(), 'Neměnný DMS originál musí zůstat připojený k výsledné faktuře.');
    }

    public function testExactDedupIsPerTenantAndUnchangedReplacementIsRejected(): void
    {
        $bytes = "%PDF-1.4\n% deterministic synthetic duplicate";
        $first = $this->upload->submit(
            $this->uploadedFile('duplicate.pdf', $bytes),
            $this->supplierA,
            $this->userId,
            'portal',
        );
        $duplicate = $this->upload->submit(
            $this->uploadedFile('duplicate-renamed.pdf', $bytes),
            $this->supplierA,
            $this->userId,
            'portal',
        );
        $otherTenant = $this->upload->submit(
            $this->uploadedFile('duplicate.pdf', $bytes),
            $this->supplierB,
            $this->userId,
            'portal',
        );

        self::assertFalse($first['duplicate']);
        self::assertTrue($duplicate['duplicate']);
        self::assertSame((int) $first['submission']['id'], (int) $duplicate['submission']['id']);
        self::assertFalse($otherTenant['duplicate']);
        self::assertNotSame((int) $first['submission']['id'], (int) $otherTenant['submission']['id']);
        self::assertNull($this->submissions->find((int) $first['submission']['id'], $this->supplierB));
        $this->trackSubmissionFile($first['submission']);
        $this->trackSubmissionFile($otherTenant['submission']);

        $firstId = (int) $first['submission']['id'];
        self::assertTrue($this->submissions->needsInformation($firstId, $this->supplierA, 'Nahrajte čitelnější verzi.'));
        try {
            $this->upload->submit(
                $this->uploadedFile('same-again.pdf', $bytes),
                $this->supplierA,
                $this->userId,
                'portal',
                supersedesSubmissionId: $firstId,
            );
            self::fail('Shodný soubor nesmí uzavřít požadavek na náhradu.');
        } catch (PurchaseInvoiceSubmissionException $e) {
            self::assertSame('replacement_unchanged', $e->errorCode);
            self::assertSame(409, $e->httpStatus);
        }
    }

    public function testSubmissionFileEndpointIsTenantAndRoleScoped(): void
    {
        $bytes = "%PDF-1.4\n% tenant scoped synthetic preview";
        $created = $this->upload->submit(
            $this->uploadedFile('tenant-preview.pdf', $bytes),
            $this->supplierA,
            $this->userId,
            'portal',
        );
        $submission = $created['submission'];
        $submissionId = (int) $submission['id'];
        $this->trackSubmissionFile($submission);

        $action = new PurchaseInvoiceSubmissionFileAction($this->submissions, $this->storage);
        $responses = new ResponseFactory();
        $requests = new ServerRequestFactory();
        $portalRequest = $requests
            ->createServerRequest('GET', "/api/portal/purchase-invoice-submissions/{$submissionId}/preview")
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierA)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'client']);

        $preview = $action->portalPreview(
            $portalRequest,
            $responses->createResponse(),
            ['id' => (string) $submissionId],
        );
        self::assertSame(200, $preview->getStatusCode());
        self::assertSame('inline; filename="tenant-preview.pdf"', $preview->getHeaderLine('Content-Disposition'));
        self::assertSame($bytes, (string) $preview->getBody());

        $otherTenant = $action->portalPreview(
            $portalRequest->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierB),
            $responses->createResponse(),
            ['id' => (string) $submissionId],
        );
        self::assertSame(404, $otherTenant->getStatusCode(), 'Cizí tenant nesmí zjistit existenci souboru.');

        $staffAsClient = $action->staffPreview(
            $portalRequest,
            $responses->createResponse(),
            ['id' => (string) $submissionId],
        );
        self::assertSame(403, $staffAsClient->getStatusCode());

        $portalAsAccountant = $action->portalPreview(
            $portalRequest->withAttribute(AuthMiddleware::ATTR_USER, [
                'id' => $this->userId,
                'role' => 'accountant',
            ]),
            $responses->createResponse(),
            ['id' => (string) $submissionId],
        );
        self::assertSame(403, $portalAsAccountant->getStatusCode());
    }

    public function testReplacementRebindsOpenRequestAndCanOnlyBeSubmittedOnce(): void
    {
        $requestId = $this->repo->create($this->supplierA, [
            'description' => 'Nahraďte nečitelný doklad', 'amount' => null,
            'context_date' => null, 'deadline' => null, 'bank_transaction_id' => null,
        ], $this->userId);
        $first = $this->upload->submit(
            $this->uploadedFile('necitelný.pdf', "%PDF-1.4\n% first synthetic original"),
            $this->supplierA,
            $this->userId,
            'document_request',
            'Kontext původního požadavku',
            'invoice',
        );
        $firstId = (int) $first['submission']['id'];
        self::assertTrue($this->repo->markSubmitted($requestId, $this->supplierA, $firstId));
        self::assertTrue($this->submissions->needsInformation(
            $firstId,
            $this->supplierA,
            'Soubor je nečitelný.',
        ));
        $this->trackSubmissionFile($first['submission']);

        $replacement = $this->upload->submit(
            $this->uploadedFile('čitelný.pdf', "%PDF-1.4\n% replacement synthetic original"),
            $this->supplierA,
            $this->userId,
            'portal',
            supersedesSubmissionId: $firstId,
        );
        $replacementId = (int) $replacement['submission']['id'];
        $this->trackSubmissionFile($replacement['submission']);
        self::assertSame('Kontext původního požadavku', $replacement['submission']['note']);
        self::assertSame('invoice', $replacement['submission']['document_kind_hint']);

        $old = $this->submissions->find($firstId, $this->supplierA);
        self::assertSame($replacementId, (int) $old['replacement_submission_id']);
        $request = $this->repo->find($requestId, $this->supplierA);
        self::assertSame($replacementId, (int) $request['submission_id'],
            'Pull požadavek musí po náhradě sledovat nový originál.');

        try {
            $this->upload->submit(
                $this->uploadedFile('další.pdf', "%PDF-1.4\n% second replacement must fail"),
                $this->supplierA,
                $this->userId,
                'portal',
                supersedesSubmissionId: $firstId,
            );
            self::fail('Jedno podání nesmí dostat dvě souběžné náhrady.');
        } catch (PurchaseInvoiceSubmissionException $e) {
            self::assertSame('invalid_replacement', $e->errorCode);
            self::assertSame(409, $e->httpStatus);
        }

        $invoiceId = $this->createPurchaseInvoiceDraft($this->supplierA, 'DOCREQ-REPLACEMENT-1');
        self::assertTrue($this->submissions->claimForManual($replacementId, $this->supplierA));
        $this->completion->complete(
            $replacementId,
            $this->supplierA,
            $invoiceId,
            $this->userId,
            'manual',
        );
        self::assertSame(
            $invoiceId,
            (int) $this->repo->find($requestId, $this->supplierA)['purchase_invoice_id'],
            'Výsledek náhradního souboru se musí propsat zpět do původního požadavku.',
        );
    }

    public function testBatchUploadConfirmsAcceptedFilesEvenWhenAnotherFileIsRejected(): void
    {
        $action = new PortalPurchaseInvoiceSubmissionAction(
            $this->submissions,
            $this->upload,
            $this->activity,
            $this->ipMatcher,
        );
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/portal/purchase-invoice-submissions')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierA)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'client'])
            ->withUploadedFiles(['file' => [
                $this->uploadedFile('prijatý.pdf', "%PDF-1.4\n% accepted in partial batch"),
                $this->uploadedFile('odmítnutý.exe', 'MZ synthetic rejected executable'),
            ]]);

        $response = $action->upload($request, (new ResponseFactory())->createResponse());
        self::assertSame(207, $response->getStatusCode(), (string) $response->getBody());
        $payload = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(1, $payload['created']);
        self::assertSame(0, $payload['duplicates']);
        self::assertCount(1, $payload['items']);
        self::assertCount(1, $payload['errors']);
        self::assertSame('unsupported_format', $payload['errors'][0]['code']);

        $submission = $this->submissions->find((int) $payload['items'][0]['id'], $this->supplierA);
        self::assertNotNull($submission);
        self::assertSame('submitted', $submission['status']);
        $this->trackSubmissionFile($submission);
    }

    public function testUploadOnRequestOfOtherSupplierIsNotFound(): void
    {
        // Požadavek patří firmě B; klient přihlášený jako firma A ho nesmí ani vidět, ani ovlivnit.
        $idB = $this->repo->create($this->supplierB, [
            'description' => 'Cizí požadavek', 'amount' => null,
            'context_date' => null, 'deadline' => null, 'bank_transaction_id' => null,
        ], $this->userId);

        $action = $this->buildPortalAction();
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

    private function buildPortalAction(): PortalDocumentRequestAction
    {
        return new PortalDocumentRequestAction($this->repo, $this->upload, $this->activity, $this->ipMatcher);
    }

    private function uploadRequest(
        int $supplierId,
        int $requestId,
        string $name = 'doklad.pdf',
        ?string $contents = null,
    ): \Psr\Http\Message\ServerRequestInterface
    {
        $uploaded = $this->uploadedFile(
            $name,
            $contents ?? ("%PDF-1.4\n% synthetic " . bin2hex(random_bytes(16))),
        );

        return (new ServerRequestFactory())
            ->createServerRequest('POST', "/api/portal/document-requests/{$requestId}/upload")
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'client'])
            ->withUploadedFiles(['file' => $uploaded]);
    }

    private function uploadedFile(string $name, string $contents): UploadedFile
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'docreq');
        $this->temporaryFiles[] = $tmpFile;
        file_put_contents($tmpFile, $contents);
        return new UploadedFile(
            $tmpFile,
            $name,
            'application/octet-stream',
            (int) filesize($tmpFile),
            UPLOAD_ERR_OK,
        );
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

    /** @param array<string,mixed> $submission */
    private function trackSubmissionFile(array $submission): void
    {
        $this->createdFiles[] = $this->storage->pathFor(
            (int) $submission['supplier_id'],
            (string) $submission['document_sha256'],
            (string) $submission['document_filename'],
        );
    }
}
