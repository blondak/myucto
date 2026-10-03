<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Action\Portal\PortalPurchaseInvoiceSubmissionAction;
use MyInvoice\Action\PurchaseInvoice\GetPurchaseInvoiceAction;
use MyInvoice\Action\PurchaseInvoice\PurchaseInvoiceSubmissionAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\DimensionAssignmentRepository;
use MyInvoice\Repository\PurchaseInvoiceSubmissionRepository;
use MyInvoice\Security\EffectiveRole;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\Dimension\DimensionException;
use MyInvoice\Service\Accounting\Dimension\DimensionRuleService;
use MyInvoice\Service\Accounting\Dimension\DimensionService;
use MyInvoice\Service\PurchaseInvoice\PurchaseInvoiceSubmissionCompletionService;
use MyInvoice\Service\PurchaseInvoice\Review\PurchaseInvoiceReviewNeeds;
use MyInvoice\Service\PurchaseInvoice\Review\RequiredDimensionReviewCheck;
use MyInvoice\Service\PurchaseInvoice\SubmissionDimensions;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * Dimenze přímo při vytěžení (Účtování podle dimenzí, F5):
 *   • návrh hlavičky z historie dodavatele a jeho přednost (ruční > výchozí > historie),
 *   • izolace firem u návrhu z historie,
 *   • okno kontroly se otevře i bez hlášení vytěžení, když chybí povinná dimenze,
 *   • středisko zvolené při nahrání podání se propíše do hlavičky vzniklé faktury,
 *   • portál nabízí a přijímá jen aktivní střediska.
 * Vše v jedné transakci, tearDown rollbackne.
 */
#[Group('integration')]
final class DimensionExtractionReviewTest extends TestCase
{
    private const YEAR = 2096;

    private ContainerInterface $container;
    private Connection $db;
    private DimensionService $dimensions;

    private int $supplierId = 0;
    private int $otherSupplierId = 0;
    private int $currencyId = 0;
    private int $vatRateId = 0;
    private int $userId = 0;
    private int $czId = 0;
    private int $projectType = 0;
    private int $centerType = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $this->container = Bootstrap::buildContainer();
            $this->db = $this->container->get(Connection::class);
            $this->dimensions = $this->container->get(DimensionService::class);
            $seeder = $this->container->get(ChartOfAccountsSeeder::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $suppliers = array_map('intval', $pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 2')->fetchAll(PDO::FETCH_COLUMN));
        $this->supplierId = $suppliers[0] ?? 0;
        $this->otherSupplierId = $suppliers[1] ?? 0;
        $this->currencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->vatRateId = (int) ($pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->czId = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        if ($this->supplierId === 0 || $this->otherSupplierId === 0 || $this->currencyId === 0
            || $this->vatRateId === 0 || $this->userId === 0 || $this->czId === 0) {
            $this->markTestSkipped('Chybí základní data (2 dodavatelé, měna, sazba DPH, uživatel, země) v DB.');
        }

        $pdo->beginTransaction();
        $this->inTx = true;

        $seeder->seedForSupplier($this->supplierId);
        $pdo->prepare("UPDATE supplier SET accounting_mode = 'double_entry', supplier_group_id = NULL WHERE id IN (?, ?)")
            ->execute([$this->supplierId, $this->otherSupplierId]);
        $pdo->prepare('DELETE FROM dimension_account_rules WHERE supplier_id IN (?, ?)')
            ->execute([$this->supplierId, $this->otherSupplierId]);
        $this->dimensions->setEnabled($this->supplierId, true);
        $this->dimensions->setEnabled($this->otherSupplierId, true);
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

    // ── návrh z historie dodavatele ──────────────────────────────────────────

    public function testHistoryProposesHeaderOfLastVendorDocument(): void
    {
        $vendor = $this->client('HIST-VENDOR');
        $older = $this->value($this->centerType, 'HC-OLD');
        $newer = $this->value($this->centerType, 'HC-NEW');
        $project = $this->value($this->projectType, 'HP-NEW');

        $first = $this->purchase('HIST-1', $vendor, self::YEAR . '-03-01');
        $this->dimensions->saveDocument($this->supplierId, 'purchase_invoice', $first, [$this->centerType => $older], []);
        $second = $this->purchase('HIST-2', $vendor, self::YEAR . '-04-01');
        $this->dimensions->saveDocument($this->supplierId, 'purchase_invoice', $second, [$this->centerType => $newer, $this->projectType => $project], []);
        // Novější doklad bez dimenzí návrh nepřebije, stornovaný se nepočítá.
        $this->purchase('HIST-3', $vendor, self::YEAR . '-05-01');
        $cancelled = $this->purchase('HIST-4', $vendor, self::YEAR . '-06-01', 'cancelled');
        $this->dimensions->saveDocument($this->supplierId, 'purchase_invoice', $cancelled, [$this->centerType => $older], []);
        $current = $this->purchase('HIST-NOW', $vendor, self::YEAR . '-07-01', 'draft');

        $result = $this->dimensions->prefill($this->supplierId, $vendor, null, null, null, true, $current);
        self::assertEquals([$this->projectType => $project, $this->centerType => $newer], $result['header']);
        self::assertEquals([$this->projectType => 'history', $this->centerType => 'history'], $result['sources']);

        self::assertSame([], $this->dimensions->prefill($this->supplierId, $vendor, null)['header'], 'Bez žádosti o historii se nic nenavrhuje.');

        // Kontrolovaný doklad sám sobě návrhem není.
        $this->dimensions->saveDocument($this->supplierId, 'purchase_invoice', $current, [$this->centerType => $older], []);
        self::assertSame($newer, $this->dimensions->prefill($this->supplierId, $vendor, null, null, null, true, $current)['header'][$this->centerType]);
    }

    public function testVendorDefaultsWinOverHistory(): void
    {
        $vendor = $this->client('HIST-PRIO');
        $fromHistory = $this->value($this->centerType, 'HC-HIST');
        $fromDefault = $this->value($this->centerType, 'HC-DEF');
        $historyProject = $this->value($this->projectType, 'HP-HIST');
        $past = $this->purchase('PRIO-1', $vendor, self::YEAR . '-02-01');
        $this->dimensions->saveDocument($this->supplierId, 'purchase_invoice', $past, [$this->centerType => $fromHistory, $this->projectType => $historyProject], []);
        $this->dimensions->saveEntityDefaults($this->supplierId, 'client', $vendor, [$this->centerType => $fromDefault]);

        $result = $this->dimensions->prefill($this->supplierId, $vendor, null, null, null, true);
        self::assertSame($fromDefault, $result['header'][$this->centerType]);
        self::assertSame('client', $result['sources'][$this->centerType]);
        self::assertSame($historyProject, $result['header'][$this->projectType], 'Typ bez výchozí hodnoty doplní historie.');
        self::assertSame('history', $result['sources'][$this->projectType]);
    }

    public function testHistoryIsIsolatedPerCompanyAndSkipsClosedValues(): void
    {
        $vendor = $this->client('HIST-TENANT');
        $center = $this->value($this->centerType, 'HC-TENANT');
        $doc = $this->purchase('TEN-1', $vendor, self::YEAR . '-02-01');
        $this->dimensions->saveDocument($this->supplierId, 'purchase_invoice', $doc, [$this->centerType => $center], []);

        self::assertSame([], $this->dimensions->prefill($this->otherSupplierId, $vendor, null, null, null, true)['header'],
            'Cizí firma návrh z dokladů jiné firmy nedostane ani se stejným id dodavatele.');

        $this->dimensions->updateValue($this->supplierId, $center, ['is_active' => false]);
        self::assertSame([], $this->dimensions->prefill($this->supplierId, $vendor, null, null, null, true)['header'], 'Uzavřená hodnota se nenavrhuje.');
    }

    // ── okno kontroly kvůli povinné dimenzi ──────────────────────────────────

    public function testMissingRequiredDimensionFlagsDraftForReview(): void
    {
        $vendor = $this->client('REQ-VENDOR');
        $draft = $this->purchase('REQ-1', $vendor, self::YEAR . '-06-15', 'draft');
        $needs = $this->container->get(PurchaseInvoiceReviewNeeds::class);

        self::assertSame([], $needs->forInvoice($this->supplierId, $this->row($draft))['reasons'], 'Bez pravidla není co kontrolovat.');

        (new DimensionRuleService($this->db))->create($this->supplierId, [
            'dimension_type_id' => $this->centerType, 'account_mask' => '5', 'enforcement' => 'error',
        ]);
        $needs = $this->container->get(PurchaseInvoiceReviewNeeds::class);
        $review = $needs->forInvoice($this->supplierId, $this->row($draft));
        self::assertSame([RequiredDimensionReviewCheck::REASON], $review['reasons']);
        $missing = $review['details'][RequiredDimensionReviewCheck::REASON]['missing_dimensions'];
        self::assertSame($this->centerType, $missing[0]['type_id']);
        self::assertNotSame([], $missing[0]['account_codes']);

        // Detail dokladu nese totéž rozhodnutí, okno kontroly podle něj doklad zařadí.
        $body = $this->getInvoice($draft);
        self::assertSame([RequiredDimensionReviewCheck::REASON], $body['review']['reasons']);
        self::assertNull($body['extraction_warning']);

        // Výchozí středisko dodavatele povinnost splní (doplní ho zaúčtování).
        $default = $this->value($this->centerType, 'RC-DEF');
        $this->dimensions->saveEntityDefaults($this->supplierId, 'client', $vendor, [$this->centerType => $default]);
        self::assertSame([], $this->container->get(PurchaseInvoiceReviewNeeds::class)->forInvoice($this->supplierId, $this->row($draft))['reasons']);
        $this->dimensions->saveEntityDefaults($this->supplierId, 'client', $vendor, []);

        // Středisko v hlavičce dokladu taky.
        $this->dimensions->saveDocument($this->supplierId, 'purchase_invoice', $draft, [$this->centerType => $default], []);
        self::assertSame([], $this->container->get(PurchaseInvoiceReviewNeeds::class)->forInvoice($this->supplierId, $this->row($draft))['reasons']);
    }

    public function testWarningRuleAndCancelledDocumentDoNotFlag(): void
    {
        $vendor = $this->client('REQ-WARN');
        (new DimensionRuleService($this->db))->create($this->supplierId, [
            'dimension_type_id' => $this->centerType, 'account_mask' => '5', 'enforcement' => 'warning',
        ]);
        $draft = $this->purchase('WARN-1', $vendor, self::YEAR . '-06-15', 'draft');
        self::assertSame([], $this->container->get(PurchaseInvoiceReviewNeeds::class)->forInvoice($this->supplierId, $this->row($draft))['reasons']);

        (new DimensionRuleService($this->db))->create($this->supplierId, [
            'dimension_type_id' => $this->projectType, 'account_mask' => '5', 'enforcement' => 'error',
        ]);
        $cancelled = $this->purchase('WARN-2', $vendor, self::YEAR . '-06-15', 'cancelled');
        self::assertSame([], $this->container->get(PurchaseInvoiceReviewNeeds::class)->forInvoice($this->supplierId, $this->row($cancelled))['reasons']);
    }

    public function testAdvanceAndTaxDocumentAreNotFlagged(): void
    {
        $vendor = $this->client('REQ-KIND');
        (new DimensionRuleService($this->db))->create($this->supplierId, [
            'dimension_type_id' => $this->centerType, 'account_mask' => '5', 'enforcement' => 'error',
        ]);
        $needs = $this->container->get(PurchaseInvoiceReviewNeeds::class);
        foreach (['advance', 'tax_document'] as $kind) {
            $doc = $this->purchase('KIND-' . $kind, $vendor, self::YEAR . '-06-15', 'draft', $kind);
            self::assertSame([], $needs->forInvoice($this->supplierId, $this->row($doc))['reasons'], "{$kind} nemá nákladový řádek.");
        }
        $invoice = $this->purchase('KIND-invoice', $vendor, self::YEAR . '-06-15', 'draft');
        self::assertSame([RequiredDimensionReviewCheck::REASON], $needs->forInvoice($this->supplierId, $this->row($invoice))['reasons']);
    }

    public function testListRowsAreEvaluatedInOneBatch(): void
    {
        $vendor = $this->client('REQ-LIST');
        (new DimensionRuleService($this->db))->create($this->supplierId, [
            'dimension_type_id' => $this->centerType, 'account_mask' => '5', 'enforcement' => 'error',
        ]);
        $center = $this->value($this->centerType, 'RC-LIST');
        $missing = $this->purchase('LIST-1', $vendor, self::YEAR . '-06-15', 'draft');
        $filled = $this->purchase('LIST-2', $vendor, self::YEAR . '-06-15', 'draft');
        $this->dimensions->saveDocument($this->supplierId, 'purchase_invoice', $filled, [$this->centerType => $center], []);
        $received = $this->purchase('LIST-3', $vendor, self::YEAR . '-06-15');
        $this->db->pdo()->prepare('UPDATE purchase_invoices SET extraction_warning = ? WHERE id = ?')->execute(['Rozdíl součtů', $received]);

        $result = $this->container->get(PurchaseInvoiceReviewNeeds::class)
            ->forListRows($this->supplierId, [$this->row($missing), $this->row($filled), $this->row($received)]);
        self::assertSame([RequiredDimensionReviewCheck::REASON], $result[$missing]['reasons']);
        self::assertSame([], $result[$filled]['reasons']);
        self::assertSame(['extraction_warning'], $result[$received]['reasons'], 'Přijatý doklad v seznamu jen podle hlášení.');

        $api = json_encode(PurchaseInvoiceReviewNeeds::toApi($result[$filled]), JSON_THROW_ON_ERROR);
        self::assertSame('{"reasons":[],"details":{}}', $api, 'Prázdné details je v JSON objekt.');
    }

    // ── dimenze z podání → hlavička faktury ──────────────────────────────────

    public function testSubmissionDimensionsArePropagatedToCreatedInvoiceHeader(): void
    {
        $vendor = $this->client('SUB-VENDOR');
        $chosen = $this->value($this->centerType, 'SC-CHOSEN');
        $default = $this->value($this->centerType, 'SC-DEFAULT');
        $this->dimensions->saveEntityDefaults($this->supplierId, 'client', $vendor, [$this->centerType => $default]);

        $submissionDims = $this->container->get(SubmissionDimensions::class);
        $submission = $this->submission('sub-propagate');
        $submissionDims->save($this->supplierId, $submission, $submissionDims->validate($this->supplierId, [$this->centerType => $chosen], true));
        self::assertSame([$this->centerType => $chosen], $submissionDims->forSubmissions($this->supplierId, [$submission])[$submission]);
        self::assertSame([], $submissionDims->forSubmissions($this->otherSupplierId, [$submission]), 'Podání cizí firmy není vidět.');

        $invoice = $this->purchase('SUB-1', $vendor, self::YEAR . '-06-15', 'draft');
        $repo = $this->container->get(PurchaseInvoiceSubmissionRepository::class);
        self::assertTrue($repo->claimForManual($submission, $this->supplierId));
        $this->container->get(PurchaseInvoiceSubmissionCompletionService::class)
            ->complete($submission, $this->supplierId, $invoice, $this->userId, 'manual');

        $header = (new DimensionAssignmentRepository($this->db))->documentDimensions($this->supplierId, 'purchase_invoice', $invoice)['header'];
        self::assertSame([$this->centerType => $chosen], $header, 'Volba z podání má přednost před výchozím střediskem dodavatele.');
    }

    public function testSubmissionDimensionDoesNotOverwriteExplicitInvoiceHeader(): void
    {
        $chosen = $this->value($this->centerType, 'SC-SUB');
        $explicit = $this->value($this->centerType, 'SC-EXPL');
        $project = $this->value($this->projectType, 'SP-SUB');
        $submissionDims = $this->container->get(SubmissionDimensions::class);
        $submission = $this->submission('sub-explicit');
        $submissionDims->save($this->supplierId, $submission, [$this->centerType => $chosen, $this->projectType => $project]);

        $invoice = $this->purchase('SUB-2', $this->client('SUB-EXPL'), self::YEAR . '-06-15', 'draft');
        $this->dimensions->saveDocument($this->supplierId, 'purchase_invoice', $invoice, [$this->centerType => $explicit], []);

        self::assertSame([$this->projectType => $project], $submissionDims->applyToInvoice($this->supplierId, $submission, $invoice));
        $header = (new DimensionAssignmentRepository($this->db))->documentDimensions($this->supplierId, 'purchase_invoice', $invoice)['header'];
        self::assertEquals([$this->centerType => $explicit, $this->projectType => $project], $header);
    }

    public function testPortalOffersAndAcceptsOnlyActiveCostCenters(): void
    {
        $active = $this->value($this->centerType, 'PC-ACTIVE');
        $closed = $this->value($this->centerType, 'PC-CLOSED');
        $this->dimensions->updateValue($this->supplierId, $closed, ['is_active' => false]);
        $project = $this->value($this->projectType, 'PP-1');

        $action = $this->container->get(PortalPurchaseInvoiceSubmissionAction::class);
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/api/portal/purchase-invoice-submissions/dimensions')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'client'])
            ->withAttribute('auth.effective_role', new EffectiveRole(9, 'Klient', 'client', true, ['documents.submit' => 2]));
        $response = $action->dimensions($request, (new ResponseFactory())->createResponse());
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $types = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR)['types'];
        self::assertSame([$this->centerType], array_column($types, 'id'), 'Portál nabízí jen středisko.');
        $valueIds = array_column($types[0]['values'], 'id');
        self::assertContains($active, $valueIds);
        self::assertNotContains($closed, $valueIds);
        self::assertSame(['id', 'code', 'name', 'parent_id'], array_keys($types[0]['values'][0]), 'Bez interních údajů číselníku.');

        $submissionDims = $this->container->get(SubmissionDimensions::class);
        foreach ([[$this->projectType => $project], [$this->centerType => $closed]] as $raw) {
            try {
                $submissionDims->validate($this->supplierId, $raw, true);
                self::fail('Portál nesmí přijmout ' . json_encode($raw));
            } catch (DimensionException $e) {
                self::assertContains($e->errorCode, ['invalid_dimension', 'dimension_closed']);
            }
        }
        self::assertSame([$this->projectType => $project], $submissionDims->validate($this->supplierId, [$this->projectType => $project], false), 'Účetní smí zvolit i projekt.');
    }

    public function testBulkDimensionsMergeIntoQueuedSubmissionsAndSkipProcessed(): void
    {
        $center = $this->value($this->centerType, 'BC-1');
        $project = $this->value($this->projectType, 'BP-1');
        $submissionDims = $this->container->get(SubmissionDimensions::class);
        $kept = $this->submission('bulk-kept');
        $submissionDims->save($this->supplierId, $kept, [$this->projectType => $project]);
        $plain = $this->submission('bulk-plain');
        $processed = $this->submission('bulk-processed');
        $this->db->pdo()->prepare("UPDATE purchase_invoice_submissions SET status = 'processed' WHERE id = ?")->execute([$processed]);

        $action = $this->container->get(PurchaseInvoiceSubmissionAction::class);
        $body = ['ids' => [$kept, $plain, $processed], 'dimensions' => [$this->centerType => $center]];

        $denied = $action->bulkDimensions($this->bulkRequest($body, ['documents.inbox' => 2]), (new ResponseFactory())->createResponse());
        self::assertSame(403, $denied->getStatusCode(), 'Bez práva na účetnictví dimenze nastavit nejde.');

        $response = $action->bulkDimensions(
            $this->bulkRequest($body, ['documents.inbox' => 2, 'accounting' => 2]),
            (new ResponseFactory())->createResponse(),
        );
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $result = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame([$kept, $plain], $result['updated']);
        self::assertSame([$processed], $result['skipped']);

        $map = $submissionDims->forSubmissions($this->supplierId, [$kept, $plain, $processed]);
        self::assertEquals([$this->projectType => $project, $this->centerType => $center], $map[$kept], 'Ostatní dimenze podání zůstanou.');
        self::assertSame([$this->centerType => $center], $map[$plain]);
        self::assertArrayNotHasKey($processed, $map, 'Zpracované podání se nemění.');
    }

    /**
     * @param array<string,mixed> $body
     * @param array<string,int> $permissions
     */
    private function bulkRequest(array $body, array $permissions): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest('POST', '/api/purchase-invoice-submissions/dimensions')
            ->withParsedBody($body)
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'accountant'])
            ->withAttribute('auth.effective_role', new EffectiveRole(8, 'Účetní', 'staff', true, $permissions));
    }

    // ── pomocné ──────────────────────────────────────────────────────────────

    /** @return array<string,mixed> */
    private function getInvoice(int $id): array
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/api/purchase-invoices/' . $id)
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId);
        $response = ($this->container->get(GetPurchaseInvoiceAction::class))($request, (new ResponseFactory())->createResponse(), ['id' => (string) $id]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        return (array) json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return array<string,mixed> */
    private function row(int $id): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM purchase_invoices WHERE id = ?');
        $stmt->execute([$id]);
        return (array) $stmt->fetch(PDO::FETCH_ASSOC);
    }

    private function value(int $typeId, string $code): int
    {
        return (int) $this->dimensions->createValue($this->supplierId, $typeId, ['code' => $code, 'name' => 'Hodnota ' . $code])['id'];
    }

    private function client(string $name): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id, dic, main_email,
                                  language, currency_default_id, is_customer, is_vendor)
             VALUES (?, ?, "Test 1", "Praha", "11000", ?, "CZ12345678", "klient@example.invalid", "cs", ?, 1, 1)'
        )->execute([$this->supplierId, 'Dodavatel ' . $name, $this->czId, $this->currencyId]);
        return (int) $pdo->lastInsertId();
    }

    private function purchase(string $number, int $vendorId, string $issue, string $status = 'received', string $kind = 'invoice'): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO purchase_invoices
                (supplier_id, vendor_id, vendor_invoice_number, document_kind, issue_date, tax_date, due_date,
                 received_at, currency_id, reverse_charge, vendor_snapshot, total_without_vat, total_vat,
                 total_with_vat, status, vat_classification_code, vat_deduction, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, "{}", 1000, 210, 1210, ?, "40", "full", ?)'
        )->execute([$this->supplierId, $vendorId, $number, $kind, $issue, $issue, $issue, $issue, $this->currencyId, $status, $this->userId]);
        $id = (int) $pdo->lastInsertId();
        $pdo->prepare(
            "INSERT INTO purchase_invoice_items
                (purchase_invoice_id, description, quantity, unit, unit_price_without_vat, vat_rate_id,
                 vat_rate_snapshot, total_without_vat, total_vat, total_with_vat, order_index)
             VALUES (?, 'Položka', 1, 'ks', 1000, ?, 21.00, 1000, 210, 1210, 0)"
        )->execute([$id, $this->vatRateId]);
        return $id;
    }

    private function submission(string $name): int
    {
        $pdo = $this->db->pdo();
        $sha = hash('sha256', $name . '-' . bin2hex(random_bytes(6)));
        $pdo->prepare(
            "INSERT INTO documents (supplier_id, title, original_name, filename, sha256, mime_type, size_bytes, doc_type, source, uploaded_by)
             VALUES (?, 'Syntetická účtenka', ?, ?, ?, 'application/pdf', 32, 'pdf', 'manual', ?)"
        )->execute([$this->supplierId, $name . '.pdf', $sha . '.pdf', $sha, $this->userId]);
        $documentId = (int) $pdo->lastInsertId();
        return $this->container->get(PurchaseInvoiceSubmissionRepository::class)->create($this->supplierId, [
            'document_id' => $documentId,
            'document_sha256' => $sha,
            'submitted_by' => $this->userId,
            'submitted_via' => 'portal',
            'note' => null,
            'document_kind_hint' => 'receipt',
            'bank_transaction_id' => null,
            'supersedes_submission_id' => null,
        ]);
    }
}
