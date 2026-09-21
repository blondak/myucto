<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Action\Accounting\DimensionDefaultsAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\DimensionAssignmentRepository;
use MyInvoice\Repository\DimensionDefaultRepository;
use MyInvoice\Repository\JournalEntryRepository;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\EffectiveRole;
use MyInvoice\Security\RoutePermissionMap;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\Dimension\DimensionException;
use MyInvoice\Service\Accounting\Dimension\DimensionService;
use MyInvoice\Service\Accounting\PostingService;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * Výchozí dimenze klienta a zakázky (migrace 1861): uložení a validace, izolace
 * firem, práva API a náhradní hlavička při účtování (zakázka > klient > nic,
 * dimenze dokladu vždy vyhrává). Vše v jedné transakci, tearDown rollbackne.
 */
#[Group('integration')]
final class DimensionDefaultsTest extends TestCase
{
    private const YEAR = 2096;

    private Connection $db;
    private PostingService $posting;
    private JournalEntryRepository $journal;
    private DimensionService $dimensions;
    private DimensionAssignmentRepository $assignments;
    private DimensionDefaultsAction $action;

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
            $container = Bootstrap::buildApp()->getContainer();
            $this->db = $container->get(Connection::class);
            $this->posting = $container->get(PostingService::class);
            $this->journal = $container->get(JournalEntryRepository::class);
            $this->dimensions = $container->get(DimensionService::class);
            $this->assignments = $container->get(DimensionAssignmentRepository::class);
            $this->action = $container->get(DimensionDefaultsAction::class);
            $periods = $container->get(AccountingPeriodRepository::class);
            $seeder = $container->get(ChartOfAccountsSeeder::class);
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
        $periods->create($this->supplierId, self::YEAR, self::YEAR . '-01-01', self::YEAR . '-12-31');
        $pdo->prepare("UPDATE supplier SET accounting_mode = 'double_entry', supplier_group_id = NULL WHERE id IN (?, ?)")
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

    // ── uložení a validace ───────────────────────────────────────────────────

    public function testSaveAndReadClientAndProjectDefaults(): void
    {
        $client = $this->client('DEF-CLIENT');
        $project = $this->project($client);
        $p = $this->value($this->projectType, 'DP-1');
        $c = $this->value($this->centerType, 'DC-1');

        $saved = $this->dimensions->saveEntityDefaults($this->supplierId, 'client', $client, [$this->projectType => $p, $this->centerType => $c]);
        self::assertEquals([$this->projectType => $p, $this->centerType => $c], $saved);
        self::assertEquals($saved, $this->dimensions->entityDefaults($this->supplierId, 'client', $client));

        $this->dimensions->saveEntityDefaults($this->supplierId, 'project', $project, [$this->centerType => $c]);
        self::assertEquals([$this->centerType => $c], $this->dimensions->entityDefaults($this->supplierId, 'project', $project));
        self::assertEquals($saved, $this->dimensions->entityDefaults($this->supplierId, 'client', $client), 'Zakázka klienta nepřepíše.');

        // Prázdná hodnota typ vypustí, prázdná mapa smaže vše.
        $this->dimensions->saveEntityDefaults($this->supplierId, 'client', $client, [$this->projectType => $p, $this->centerType => null]);
        self::assertEquals([$this->projectType => $p], $this->dimensions->entityDefaults($this->supplierId, 'client', $client));
        $this->dimensions->saveEntityDefaults($this->supplierId, 'client', $client, []);
        self::assertSame([], $this->dimensions->entityDefaults($this->supplierId, 'client', $client));
    }

    public function testValueMustBelongToTypeAndBeOpen(): void
    {
        $client = $this->client('DEF-VALID');
        $center = $this->value($this->centerType, 'DC-V');

        $this->assertDimensionError('invalid_dimension', fn () => $this->dimensions->saveEntityDefaults(
            $this->supplierId, 'client', $client, [$this->projectType => $center],
        ));

        $closed = $this->value($this->projectType, 'DP-CLOSED');
        $this->dimensions->updateValue($this->supplierId, $closed, ['is_active' => false]);
        $this->assertDimensionError('dimension_closed', fn () => $this->dimensions->saveEntityDefaults(
            $this->supplierId, 'client', $client, [$this->projectType => $closed],
        ));
    }

    public function testDisabledCompanyCannotSaveDefaults(): void
    {
        $client = $this->client('DEF-OFF');
        $this->dimensions->setEnabled($this->supplierId, false);
        $this->assertDimensionError('dimensions_disabled', fn () => $this->dimensions->saveEntityDefaults(
            $this->supplierId, 'client', $client, [],
        ));
    }

    public function testGlobalValueIsAllowedAndDropsOutWhenCompanyLeavesGroup(): void
    {
        $client = $this->client('DEF-GLOBAL');
        $this->dimensions->createGroup($this->supplierId, 'Skupina výchozích dimenzí');
        $type = $this->dimensions->createType($this->supplierId, ['code' => 'lok_def', 'name' => 'Lokalita', 'kind' => 'location', 'level' => 'global']);
        $value = $this->value((int) $type['id'], 'LOK-1');

        $this->dimensions->saveEntityDefaults($this->supplierId, 'client', $client, [$type['id'] => $value]);
        self::assertSame([(int) $type['id'] => $value], $this->dimensions->prefill($this->supplierId, $client, null)['header']);

        $this->dimensions->leaveGroup($this->supplierId);
        self::assertSame([], $this->dimensions->prefill($this->supplierId, $client, null)['header'], 'Neviditelná globální hodnota se nepoužije.');
    }

    public function testDeletingClientOrValueRemovesDefaults(): void
    {
        $client = $this->client('DEF-DEL');
        $project = $this->project($client);
        $p = $this->value($this->projectType, 'DP-DEL');
        $this->dimensions->saveEntityDefaults($this->supplierId, 'project', $project, [$this->projectType => $p]);
        $this->dimensions->saveEntityDefaults($this->supplierId, 'client', $client, [$this->projectType => $p]);

        self::assertTrue($this->dimensions->deleteValue($this->supplierId, $p)['deleted'], 'Výchozí nastavení hodnotu „nepoužívá".');
        self::assertSame([], $this->dimensions->entityDefaults($this->supplierId, 'client', $client));

        $p2 = $this->value($this->projectType, 'DP-DEL2');
        $this->dimensions->saveEntityDefaults($this->supplierId, 'project', $project, [$this->projectType => $p2]);
        $this->dimensions->saveEntityDefaults($this->supplierId, 'client', $client, [$this->projectType => $p2]);
        $pdo = $this->db->pdo();
        $pdo->prepare('DELETE FROM projects WHERE id = ?')->execute([$project]);
        $pdo->prepare('DELETE FROM clients WHERE id = ?')->execute([$client]);
        $left = $pdo->prepare('SELECT COUNT(*) FROM dimension_defaults WHERE supplier_id = ? AND dimension_value_id = ?');
        $left->execute([$this->supplierId, $p2]);
        self::assertSame(0, (int) $left->fetchColumn(), 'Smazání klienta/zakázky výchozí dimenze uklidí.');
    }

    // ── izolace firem ────────────────────────────────────────────────────────

    public function testForeignClientAndProjectAreInvisible(): void
    {
        $client = $this->client('DEF-TENANT');
        $project = $this->project($client);
        $p = $this->value($this->projectType, 'DP-T');
        $this->dimensions->saveEntityDefaults($this->supplierId, 'client', $client, [$this->projectType => $p]);
        $this->dimensions->saveEntityDefaults($this->supplierId, 'project', $project, [$this->projectType => $p]);

        foreach (['client' => $client, 'project' => $project] as $entity => $id) {
            $this->assertDimensionError('not_found', fn () => $this->dimensions->entityDefaults($this->otherSupplierId, $entity, $id));
            $this->assertDimensionError('not_found', fn () => $this->dimensions->saveEntityDefaults($this->otherSupplierId, $entity, $id, []));
        }
        self::assertSame([], $this->dimensions->prefill($this->otherSupplierId, $client, $project)['header']);

        // Cizí firma nesmí uložit hodnotu, kterou nevidí, ani na svého klienta.
        $foreignClient = $this->client('DEF-FOREIGN', $this->otherSupplierId);
        $this->assertDimensionError('invalid_dimension', fn () => $this->dimensions->saveEntityDefaults(
            $this->otherSupplierId, 'client', $foreignClient, [$this->projectType => $p],
        ));

        $repo = new DimensionDefaultRepository($this->db);
        self::assertSame([], $repo->forEntity($this->otherSupplierId, 'client', $client));
    }

    // ── API a práva ──────────────────────────────────────────────────────────

    public function testRoutesUseClientAndProjectPermissions(): void
    {
        $map = new RoutePermissionMap();
        foreach (['clients', 'projects'] as $key) {
            $read = $map->match('GET', "/api/{$key}/5/dimensions");
            $write = $map->match('PUT', "/api/{$key}/5/dimensions");
            self::assertSame([$key, AccessLevel::READ], [$read?->key, $read?->minimum]);
            self::assertSame([$key, AccessLevel::WRITE], [$write?->key, $write?->minimum]);
        }
        $prefill = $map->match('GET', '/api/accounting/dimensions/prefill');
        self::assertSame('accounting', $prefill?->key);
        self::assertSame(AccessLevel::READ, $prefill?->minimum);
    }

    public function testActionEnforcesPermissionsAndTenant(): void
    {
        $client = $this->client('DEF-API');
        $project = $this->project($client);
        $p = $this->value($this->projectType, 'DP-API');
        $c = $this->value($this->centerType, 'DC-API');

        $readOnly = ['clients' => 1, 'projects' => 1];
        $writer = ['clients' => 2, 'projects' => 2, 'accounting' => 1];

        $denied = $this->action->saveClient($this->request('PUT', $this->supplierId, $readOnly, ['dimensions' => [$this->projectType => $p]]), $this->response(), ['id' => (string) $client]);
        self::assertSame(403, $denied->getStatusCode(), 'Čtenář klientů výchozí dimenze neuloží.');
        $noRead = $this->action->getProject($this->request('GET', $this->supplierId, ['accounting' => 2]), $this->response(), ['id' => (string) $project]);
        self::assertSame(403, $noRead->getStatusCode(), 'Bez práva na zakázky je nečte.');

        $saved = $this->action->saveClient($this->request('PUT', $this->supplierId, $writer, ['dimensions' => [$this->projectType => $p, $this->centerType => $c]]), $this->response(), ['id' => (string) $client]);
        self::assertSame(200, $saved->getStatusCode(), (string) $saved->getBody());
        $savedProject = $this->action->saveProject($this->request('PUT', $this->supplierId, $writer, ['dimensions' => [$this->projectType => $this->value($this->projectType, 'DP-API2')]]), $this->response(), ['id' => (string) $project]);
        self::assertSame(200, $savedProject->getStatusCode(), (string) $savedProject->getBody());

        $read = $this->action->getClient($this->request('GET', $this->supplierId, $readOnly), $this->response(), ['id' => (string) $client]);
        self::assertSame(200, $read->getStatusCode());
        self::assertEquals([(string) $this->projectType => $p, (string) $this->centerType => $c], $this->json($read)['dimensions']);

        $foreign = $this->action->getClient($this->request('GET', $this->otherSupplierId, $readOnly), $this->response(), ['id' => (string) $client]);
        self::assertSame(404, $foreign->getStatusCode(), 'Klient cizí firmy neexistuje.');
        $foreignWrite = $this->action->saveProject($this->request('PUT', $this->otherSupplierId, $writer, ['dimensions' => []]), $this->response(), ['id' => (string) $project]);
        self::assertSame(404, $foreignWrite->getStatusCode());

        $prefillDenied = $this->action->prefill($this->request('GET', $this->supplierId, $readOnly, null, ['client_id' => $client]), $this->response());
        self::assertSame(403, $prefillDenied->getStatusCode(), 'Předvyplnění patří k účetnictví.');
        $prefill = $this->action->prefill($this->request('GET', $this->supplierId, $writer, null, ['client_id' => $client, 'project_id' => $project]), $this->response());
        $body = $this->json($prefill);
        self::assertSame('project', $body['sources'][(string) $this->projectType]);
        self::assertSame('client', $body['sources'][(string) $this->centerType]);
    }

    // ── účtování ─────────────────────────────────────────────────────────────

    public function testPostingFallbackPrefersProjectThenClientAndExplicitWins(): void
    {
        $vendor = $this->client('DEF-VENDOR');
        $customer = $this->client('DEF-CUSTOMER');
        $project = $this->project($customer);
        $clientProject = $this->value($this->projectType, 'DP-CLIENT');
        $projectProject = $this->value($this->projectType, 'DP-PROJECT');
        $clientCenter = $this->value($this->centerType, 'DC-CLIENT');
        $explicit = $this->value($this->projectType, 'DP-EXPLICIT');
        $this->dimensions->saveEntityDefaults($this->supplierId, 'client', $vendor, [$this->projectType => $clientProject, $this->centerType => $clientCenter]);
        $this->dimensions->saveEntityDefaults($this->supplierId, 'project', $project, [$this->projectType => $projectProject]);

        // Jen klient.
        $onlyClient = $this->purchase('DEF-1', $vendor, null);
        self::assertEquals([$this->projectType => $clientProject, $this->centerType => $clientCenter], $this->postedDims($onlyClient));

        // Zakázka přebije klienta typ po typu, ostatní typy doplní klient.
        $withProject = $this->purchase('DEF-2', $vendor, $project);
        self::assertEquals([$this->projectType => $projectProject, $this->centerType => $clientCenter], $this->postedDims($withProject));

        // Dimenze uvedená na dokladu vždy vyhrává.
        $withExplicit = $this->purchase('DEF-3', $vendor, $project);
        $this->dimensions->saveDocument($this->supplierId, 'purchase_invoice', $withExplicit, [$this->projectType => $explicit], []);
        self::assertEquals([$this->projectType => $explicit, $this->centerType => $clientCenter], $this->postedDims($withExplicit));

        // Bez výchozích nic.
        $plain = $this->purchase('DEF-4', $this->client('DEF-PLAIN'), null);
        self::assertSame([], $this->postedDims($plain));
    }

    public function testClosedDefaultIsSkippedAndSavingDefaultsDoesNotRestampPostedEntries(): void
    {
        $vendor = $this->client('DEF-HIST');
        $purchase = $this->purchase('DEF-HIST', $vendor, null);
        $entryId = $this->postPurchase($purchase);
        self::assertSame([], $this->assignments->entryLineDimensions($this->supplierId, $entryId));

        $p = $this->value($this->projectType, 'DP-HIST');
        $this->dimensions->saveEntityDefaults($this->supplierId, 'client', $vendor, [$this->projectType => $p]);
        self::assertSame([], $this->assignments->entryLineDimensions($this->supplierId, $entryId), 'Zaúčtované doklady se samy nepřepisují.');

        $this->dimensions->updateValue($this->supplierId, $p, ['is_active' => false]);
        self::assertSame([], $this->postedDims($this->purchase('DEF-CLOSED', $vendor, null)), 'Uzavřená hodnota se nedoplní.');
    }

    public function testPrefillUsesLinkedDocumentForPayments(): void
    {
        $vendor = $this->client('DEF-PAY');
        $p = $this->value($this->projectType, 'DP-PAY');
        $c = $this->value($this->centerType, 'DC-PAY');
        $this->dimensions->saveEntityDefaults($this->supplierId, 'client', $vendor, [$this->centerType => $c]);
        $purchase = $this->purchase('DEF-PAY', $vendor, null);
        $this->dimensions->saveDocument($this->supplierId, 'purchase_invoice', $purchase, [$this->projectType => $p], []);

        $result = $this->dimensions->prefill($this->supplierId, null, null, 'purchase_invoice', $purchase);
        self::assertEquals([$this->projectType => $p, $this->centerType => $c], $result['header']);
        self::assertEquals([$this->projectType => 'document', $this->centerType => 'document'], $result['sources']);
        self::assertSame([], $this->dimensions->prefill($this->otherSupplierId, null, null, 'purchase_invoice', $purchase)['header']);
    }

    // ── pomocné ──────────────────────────────────────────────────────────────

    private function assertDimensionError(string $code, callable $fn): void
    {
        try {
            $fn();
            self::fail("Očekávána DimensionException {$code}.");
        } catch (DimensionException $e) {
            self::assertSame($code, $e->errorCode);
        }
    }

    /** @return array<int,int> dimenze nákladového řádku zápisu */
    private function postedDims(int $purchaseId): array
    {
        $entryId = $this->postPurchase($purchaseId);
        $dims = $this->assignments->entryLineDimensions($this->supplierId, $entryId);
        $lines = $this->journal->linesForEntry($entryId, $this->supplierId);
        $first = $dims[$lines[0]['id']] ?? [];
        foreach ($lines as $line) {
            self::assertSame($first, $dims[$line['id']] ?? [], 'Hlavička platí pro všechny řádky zápisu.');
        }
        ksort($first);
        return $first;
    }

    private function postPurchase(int $purchaseId): int
    {
        return $this->posting->postDocument(
            $this->supplierId,
            'purchase_invoice',
            $purchaseId,
            $this->posting->buildFromPurchaseInvoice($this->supplierId, $purchaseId),
            ['entry_date' => self::YEAR . '-06-15', 'posted_by' => $this->userId],
        );
    }

    private function value(int $typeId, string $code): int
    {
        return (int) $this->dimensions->createValue($this->supplierId, $typeId, ['code' => $code, 'name' => 'Hodnota ' . $code])['id'];
    }

    private function client(string $name, ?int $supplierId = null): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id, dic, main_email,
                                  language, currency_default_id, is_customer, is_vendor)
             VALUES (?, ?, "Test 1", "Praha", "11000", ?, "CZ12345678", "klient@example.invalid", "cs", ?, 1, 1)'
        )->execute([$supplierId ?? $this->supplierId, 'Klient ' . $name, $this->czId, $this->currencyId]);
        return (int) $pdo->lastInsertId();
    }

    private function project(int $clientId): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare('INSERT INTO projects (client_id, name, currency_id) VALUES (?, ?, ?)')
            ->execute([$clientId, 'Zakázka výchozích dimenzí', $this->currencyId]);
        return (int) $pdo->lastInsertId();
    }

    private function purchase(string $number, int $vendorId, ?int $projectId): int
    {
        $pdo = $this->db->pdo();
        $issue = self::YEAR . '-06-15';
        $pdo->prepare(
            'INSERT INTO purchase_invoices
                (supplier_id, vendor_id, project_id, vendor_invoice_number, document_kind, issue_date, tax_date, due_date,
                 received_at, currency_id, reverse_charge, vendor_snapshot, total_without_vat, total_vat,
                 total_with_vat, status, vat_classification_code, vat_deduction, created_by)
             VALUES (?, ?, ?, ?, "invoice", ?, ?, ?, ?, ?, 0, "{}", 1000, 210, 1210, "received", "40", "full", ?)'
        )->execute([$this->supplierId, $vendorId, $projectId, $number, $issue, $issue, $issue, $issue, $this->currencyId, $this->userId]);
        $id = (int) $pdo->lastInsertId();
        $pdo->prepare(
            "INSERT INTO purchase_invoice_items
                (purchase_invoice_id, description, quantity, unit, unit_price_without_vat, vat_rate_id,
                 vat_rate_snapshot, total_without_vat, total_vat, total_with_vat, order_index)
             VALUES (?, 'Položka', 1, 'ks', 1000, ?, 21.00, 1000, 210, 1210, 0)"
        )->execute([$id, $this->vatRateId]);
        return $id;
    }

    /**
     * @param array<string,int> $permissions
     * @param array<string,mixed>|null $body
     * @param array<string,mixed> $query
     */
    private function request(string $method, int $supplierId, array $permissions, ?array $body = null, array $query = []): \Psr\Http\Message\ServerRequestInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest($method, '/api/test')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $supplierId)
            ->withAttribute('auth.effective_role', new EffectiveRole(9, 'Test', 'staff', true, $permissions))
            ->withQueryParams($query);
        return $body === null ? $request : $request->withParsedBody($body);
    }

    private function response(): ResponseInterface
    {
        return (new ResponseFactory())->createResponse();
    }

    /** @return array<string,mixed> */
    private function json(ResponseInterface $response): array
    {
        return (array) json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }
}
