<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Action\Invoice\BulkReissueAction;
use MyInvoice\Action\Invoice\CancelInvoiceAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\DimensionAssignmentRepository;
use MyInvoice\Repository\InvoiceRepository;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

/**
 * Kopie a dobropis přenášejí výnosový účet položky i dimenze dokladu a položek
 * (Účtování podle dimenzí, F1). Obě cesty si otevírají vlastní transakci, takže test
 * neběží v rollbacku — co založí, to v tearDown smaže.
 */
#[Group('integration')]
final class ProductAccountCopyTest extends TestCase
{
    private Connection $db;
    private BulkReissueAction $bulk;
    private CancelInvoiceAction $cancel;
    private InvoiceRepository $invoices;
    private DimensionAssignmentRepository $assignments;

    private int $supplierId = 0;
    private int $userId = 0;
    private int $clientId = 0;
    private int $vatRateId = 0;
    /** @var list<int> */
    private array $invoiceIds = [];
    /** @var list<array{0:int,1:int}> */
    private array $dims = [];
    /** @var list<int> */
    private array $types = [];

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $container = Bootstrap::buildContainer();
            $this->db = $container->get(Connection::class);
            $this->bulk = $container->get(BulkReissueAction::class);
            $this->cancel = $container->get(CancelInvoiceAction::class);
            $this->invoices = $container->get(InvoiceRepository::class);
            $this->assignments = $container->get(DimensionAssignmentRepository::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }
        $pdo = $this->db->pdo();
        $row = $pdo->query(
            "SELECT c.supplier_id, c.id AS client_id
               FROM clients c
               JOIN supplier s ON s.id = c.supplier_id AND s.accounting_mode = 'double_entry'
              ORDER BY c.id LIMIT 1"
        )->fetch(PDO::FETCH_ASSOC) ?: [];
        $this->supplierId = (int) ($row['supplier_id'] ?? 0);
        $this->clientId = (int) ($row['client_id'] ?? 0);
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->vatRateId = (int) ($pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $hasAccount = $pdo->prepare("SELECT 1 FROM chart_of_accounts WHERE supplier_id = ? AND account_code = '604' AND is_active = 1");
        $hasAccount->execute([$this->supplierId]);
        if ($this->supplierId === 0 || $this->userId === 0 || $this->vatRateId === 0 || $hasAccount->fetchColumn() === false) {
            $this->markTestSkipped('Chybí podvojná firma s klientem, uživatel, sazba DPH nebo účet 604.');
        }
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) {
            return;
        }
        $pdo = $this->db->pdo();
        foreach (array_reverse($this->invoiceIds) as $id) {
            $this->assignments->deleteDocument($this->supplierId, 'invoice', $id);
            $pdo->prepare('DELETE FROM invoices WHERE id = ?')->execute([$id]);
        }
        foreach ($this->dims as [$typeId, $valueId]) {
            $pdo->prepare('DELETE FROM dimension_values WHERE id = ? AND type_id = ?')->execute([$valueId, $typeId]);
        }
        foreach ($this->types as $typeId) {
            $pdo->prepare('DELETE FROM dimension_types WHERE id = ?')->execute([$typeId]);
        }
    }

    public function testCloneAndCreditNoteCarryItemAccountAndDimensions(): void
    {
        $source = $this->issuedInvoice();
        [$typeId, $valueId] = $this->dimensionValue();
        $this->assignments->replaceDocumentDimensions($this->supplierId, 'invoice', $source, [$typeId => $valueId], [2 => [$typeId => $valueId]]);

        $cloneId = $this->bulk->cloneOne($source, date('Y-m-d'), false, $this->userId);
        $this->invoiceIds[] = $cloneId;
        $this->assertCarried($cloneId, $typeId, $valueId, 'Kopie');

        $request = (new ServerRequestFactory())->createServerRequest('POST', '/api/test')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin'])
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session')
            ->withParsedBody(['mode' => 'credit_note', 'reason' => 'Test F1']);
        $response = ($this->cancel)($request, new Psr7Response(), ['id' => (string) $source]);
        $response->getBody()->rewind();
        $body = (array) json_decode((string) $response->getBody(), true);
        $creditNoteId = (int) ($body['credit_note_id'] ?? 0);
        if ($creditNoteId > 0) {
            $this->invoiceIds[] = $creditNoteId;
        }
        self::assertSame(201, $response->getStatusCode(), (string) json_encode($body));
        self::assertGreaterThan(0, $creditNoteId);
        $this->assertCarried($creditNoteId, $typeId, $valueId, 'Dobropis');
    }

    private function assertCarried(int $invoiceId, int $typeId, int $valueId, string $label): void
    {
        $items = $this->invoices->itemsFor($invoiceId);
        self::assertSame(['604', null], array_column($items, 'revenue_account_code'), "{$label} přenáší výnosový účet položky.");
        $dims = $this->assignments->documentDimensions($this->supplierId, 'invoice', $invoiceId);
        self::assertEquals([$typeId => $valueId], $dims['header'], "{$label} přenáší dimenze hlavičky.");
        self::assertEquals([2 => [$typeId => $valueId]], $dims['items'], "{$label} přenáší dimenze položky.");
    }

    private function issuedInvoice(): int
    {
        $pdo = $this->db->pdo();
        $currency = (int) $pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn();
        $issue = date('Y-m-d');
        $pdo->prepare(
            'INSERT INTO invoices
                (supplier_id, varsymbol, invoice_type, client_id, issue_date, tax_date, due_date,
                 currency_id, reverse_charge, total_without_vat, total_vat, total_with_vat,
                 status, vat_classification_code, created_by)
             VALUES (?, ?, "invoice", ?, ?, ?, ?, ?, 0, 300, 63, 363, "issued", "1", ?)'
        )->execute([$this->supplierId, 'F1COPY' . random_int(100000, 999999), $this->clientId, $issue, $issue, $issue, $currency, $this->userId]);
        $id = (int) $pdo->lastInsertId();
        $this->invoiceIds[] = $id;
        foreach ([[100.00, '604'], [200.00, null]] as $i => [$net, $account]) {
            $pdo->prepare(
                'INSERT INTO invoice_items
                    (invoice_id, description, quantity, unit, unit_price_without_vat, vat_rate_id,
                     vat_rate_snapshot, total_without_vat, total_vat, total_with_vat, order_index,
                     vat_classification_code, revenue_account_code)
                 VALUES (?, "Kopírovaná položka", 1, "ks", ?, ?, 21.00, ?, ?, ?, ?, "1", ?)'
            )->execute([$id, $net, $this->vatRateId, $net, round($net * 0.21, 2), round($net * 1.21, 2), $i, $account]);
        }
        return $id;
    }

    /** @return array{0:int,1:int} */
    private function dimensionValue(): array
    {
        $pdo = $this->db->pdo();
        $pdo->prepare('INSERT INTO dimension_types (supplier_id, code, name) VALUES (?, ?, ?)')
            ->execute([$this->supplierId, 'f1c' . random_int(1000, 9999), 'Kopie F1']);
        $typeId = (int) $pdo->lastInsertId();
        $this->types[] = $typeId;
        $pdo->prepare('INSERT INTO dimension_values (supplier_id, type_id, code, name) VALUES (?, ?, ?, ?)')
            ->execute([$this->supplierId, $typeId, 'F1C' . random_int(1000, 9999), 'Kopie F1']);
        $valueId = (int) $pdo->lastInsertId();
        $this->dims[] = [$typeId, $valueId];
        return [$typeId, $valueId];
    }
}
