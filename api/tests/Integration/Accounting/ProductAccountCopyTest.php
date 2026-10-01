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
use MyInvoice\Repository\RecurringTemplateRepository;
use MyInvoice\Service\Invoice\RecurringInvoiceGenerator;
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
    /** @var list<int> */
    private array $templateIds = [];
    private int $productId = 0;
    private RecurringTemplateRepository $templates;
    private RecurringInvoiceGenerator $generator;

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
            $this->templates = $container->get(RecurringTemplateRepository::class);
            $this->generator = $container->get(RecurringInvoiceGenerator::class);
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
        foreach ($this->templateIds as $id) {
            $this->templates->delete($id);
        }
        if ($this->productId > 0) {
            $pdo->prepare('DELETE FROM stock_items WHERE id = ?')->execute([$this->productId]);
        }
    }

    public function testCloneAndCreditNoteCarryItemAccountAndDimensions(): void
    {
        $source = $this->issuedInvoice();
        [$typeId, $valueId] = $this->dimensionValue();
        $this->assignments->replaceDocumentDimensions($this->supplierId, 'invoice', $source, [$typeId => $valueId], [2 => [$typeId => $valueId]]);

        $cloneId = $this->bulk->cloneOne($source, date('Y-m-d'), false, $this->userId);
        $this->invoiceIds[] = $cloneId;
        $this->assertCarried($cloneId, $typeId, $valueId, 'Kopie', ['604', null]);

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
        // Účet položky 2 vzešel z produktu — dobropis ho zapíše na svou položku, aby pozdější
        // změna karty nepřesměrovala vratku jinam než původní výnos.
        $this->assertCarried($creditNoteId, $typeId, $valueId, 'Dobropis', ['604', '601']);
    }

    public function testRecurringTemplateCarriesAccountStockLinkAndDimensions(): void
    {
        [$typeId, $valueId] = $this->dimensionValue();
        $currency = (int) $this->db->pdo()->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn();
        $today = date('Y-m-d');
        $tplId = $this->templates->create([
            'supplier_id' => $this->supplierId, 'client_id' => $this->clientId, 'project_id' => null,
            'name' => 'F1 šablona (PHPUnit)', 'frequency' => 'monthly', 'day_of_month' => null, 'end_of_month' => false,
            'anchor_date' => $today, 'next_run_date' => $today, 'end_date' => null, 'invoice_type' => 'invoice',
            'currency_id' => $currency, 'language' => 'cs', 'payment_method' => 'bank_transfer', 'reverse_charge' => false,
            'payment_due_days' => 14, 'note_above_items' => null, 'note_below_items' => null,
            'increment_month_in_descriptions' => false, 'auto_issue' => false, 'auto_send_email' => false, 'status' => 'active',
        ], $this->userId);
        $this->templateIds[] = $tplId;
        $this->templates->replaceItems($tplId, [
            ['description' => 'Paušál', 'quantity' => 1.0, 'unit' => 'ks', 'unit_price_without_vat' => 500.00,
             'vat_rate_id' => $this->vatRateId, 'order_index' => 0, 'revenue_account_code' => '604'],
            ['description' => 'Zboží', 'quantity' => 1.0, 'unit' => 'ks', 'unit_price_without_vat' => 100.00,
             'vat_rate_id' => $this->vatRateId, 'order_index' => 1, 'stock_item_id' => $this->product()],
        ]);
        self::assertSame(['604', null], array_column($this->templates->find($tplId)['items'], 'revenue_account_code'));
        self::assertSame([null, $this->productId], array_column($this->templates->find($tplId)['items'], 'stock_item_id'),
            'Šablona si skladovou kartu položky uloží.');
        $this->assignments->replaceDocumentDimensions($this->supplierId, 'recurring_template', $tplId, [$typeId => $valueId], [2 => [$typeId => $valueId]]);

        $result = $this->generator->generate($tplId, $today, $this->userId, '127.0.0.1', 'phpunit', true, false);
        $invoiceId = (int) $result['invoice_id'];
        $this->invoiceIds[] = $invoiceId;

        $items = $this->invoices->itemsFor($invoiceId);
        self::assertSame(['604', null], array_column($items, 'revenue_account_code'));
        self::assertSame([null, $this->productId], array_column($items, 'stock_item_id'));
        $dims = $this->assignments->documentDimensions($this->supplierId, 'invoice', $invoiceId);
        self::assertEquals([$typeId => $valueId], $dims['header'], 'Faktura dostane dimenze hlavičky šablony.');
        self::assertEquals([2 => [$typeId => $valueId]], $dims['items'], 'Faktura dostane dimenze položek šablony.');

        $this->templates->delete($tplId);
        $this->templateIds = [];
        self::assertSame([], $this->assignments->documentDimensions($this->supplierId, 'recurring_template', $tplId)['items'],
            'Smazání šablony uklidí její dimenze.');
    }

    /** @param list<?string> $accounts */
    private function assertCarried(int $invoiceId, int $typeId, int $valueId, string $label, array $accounts): void
    {
        $items = $this->invoices->itemsFor($invoiceId);
        self::assertSame($accounts, array_column($items, 'revenue_account_code'), "{$label} přenáší výnosový účet položky.");
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
        foreach ([[100.00, '604', null], [200.00, null, $this->product()]] as $i => [$net, $account, $product]) {
            $pdo->prepare(
                'INSERT INTO invoice_items
                    (invoice_id, description, quantity, unit, unit_price_without_vat, vat_rate_id,
                     vat_rate_snapshot, total_without_vat, total_vat, total_with_vat, order_index,
                     vat_classification_code, revenue_account_code, stock_item_id)
                 VALUES (?, "Kopírovaná položka", 1, "ks", ?, ?, 21.00, ?, ?, ?, ?, "1", ?, ?)'
            )->execute([$id, $net, $this->vatRateId, $net, round($net * 0.21, 2), round($net * 1.21, 2), $i, $account, $product]);
        }
        return $id;
    }

    /** Skladová karta s výchozím účtem výnosů 601 (jedna na test). */
    private function product(): int
    {
        if ($this->productId === 0) {
            $this->db->pdo()->prepare('INSERT INTO stock_items (supplier_id, sku, name, revenue_account_code) VALUES (?, ?, ?, "601")')
                ->execute([$this->supplierId, 'F1COPY-' . random_int(100000, 999999), 'Produkt kopie F1']);
            $this->productId = (int) $this->db->pdo()->lastInsertId();
        }
        return $this->productId;
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
