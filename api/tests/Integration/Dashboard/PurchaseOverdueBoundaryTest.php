<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Dashboard;

use DI\Container;
use MyInvoice\Action\Dashboard\PurchaseSummaryAction;
use MyInvoice\Action\Dashboard\SummaryAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\PurchaseInvoiceRepository;
use MyInvoice\Service\Crm\CrmAggregationService;
use MyInvoice\Service\Invoice\OverduePolicy;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Přijaté faktury „po splatnosti" mají jednu hranici: tu, kterou drží seznam
 * /purchase-invoices?overdue=1 podle OverduePolicy (`overdue_includes_today`).
 * Výzva „Zaplať dodavatelům" a počty na dashboardu dřív počítaly natvrdo
 * `< dnes`, takže se zapnutou volbou ukazovaly jiné číslo než seznam po prokliku.
 */
#[Group('integration')]
final class PurchaseOverdueBoundaryTest extends TestCase
{
    use IsolatedSupplierTrait;

    private ?Connection $db = null;

    protected function tearDown(): void
    {
        if ($this->db !== null) {
            if ($this->db->pdo()->inTransaction()) {
                $this->db->pdo()->rollBack();
            }
            $this->db->close();
        }
    }

    /** @return array<string,array{bool,int}> */
    public static function boundaries(): array
    {
        return ['výchozí: jen před dneškem' => [false, 1], 'včetně dneška' => [true, 2]];
    }

    #[DataProvider('boundaries')]
    public function testActionItemAndDashboardsMatchTheOverdueList(bool $includesToday, int $expected): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            self::markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $container = Bootstrap::buildContainer();
        } catch (\Throwable $e) {
            self::markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }
        self::assertInstanceOf(Container::class, $container);
        $container->set(OverduePolicy::class, new OverduePolicy(
            new Config(['invoices' => ['overdue_includes_today' => $includesToday]]),
        ));
        $this->db = $container->get(Connection::class);
        $pdo = $this->db->pdo();
        $sourceSupplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $czId = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        $czkId = (int) ($pdo->query(
            "SELECT id FROM currencies WHERE supplier_id = {$sourceSupplierId} AND code = 'CZK' ORDER BY id LIMIT 1"
        )->fetchColumn() ?: 0);
        if ($sourceSupplierId === 0 || $userId === 0 || $czId === 0 || $czkId === 0) {
            self::markTestSkipped('Chybí supplier, uživatel, země nebo CZK.');
        }

        $pdo->beginTransaction();
        $supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $pdo->prepare(
            'INSERT INTO clients
                (supplier_id, company_name, street, city, zip, country_id, dic, main_email,
                 language, currency_default_id, is_customer, is_vendor)
             VALUES (?, "Syntetický dodavatel", "Test 1", "Praha", "11000", ?, "CZ12345678",
                     "vendor@example.invalid", "cs", ?, 0, 1)'
        )->execute([$supplierId, $czId, $czkId]);
        $vendorId = (int) $pdo->lastInsertId();
        $insert = $pdo->prepare(
            'INSERT INTO purchase_invoices
                (supplier_id, vendor_id, varsymbol, vendor_invoice_number, document_kind,
                 issue_date, tax_date, due_date, received_at, currency_id, exchange_rate, reverse_charge,
                 vendor_snapshot, total_without_vat, total_vat, total_with_vat, status,
                 vat_classification_code, vat_deduction, created_by)
             VALUES (?, ?, ?, ?, "invoice", ?, ?, ?, ?, ?, 1, 0, "{}", 100, 21, 121, "received", "40", "full", ?)'
        );
        $issue = (new \DateTimeImmutable('today'))->modify('-20 days')->format('Y-m-d');
        foreach ([-1, 0, 1] as $offset) {
            $due = (new \DateTimeImmutable('today'))->modify("{$offset} days")->format('Y-m-d');
            $insert->execute([
                $supplierId, $vendorId, 'OVD' . ($offset + 1), 'OVD-' . ($offset + 1),
                $issue, $issue, $due, $issue, $czkId, $userId,
            ]);
        }

        $list = $container->get(PurchaseInvoiceRepository::class)
            ->listGroupedByMonth(['supplier_id' => $supplierId, 'overdue' => 1]);
        self::assertSame($expected, (int) $list['meta']['total'], 'Seznam po prokliku.');

        $crm = $container->get(CrmAggregationService::class);
        $items = array_values(array_filter(
            $crm->actionItems($supplierId, $userId)['items'],
            static fn (array $item): bool => $item['type'] === 'overdue_payables',
        ));
        self::assertCount(1, $items);
        self::assertSame('/purchase-invoices?overdue=1', $items[0]['link']);
        self::assertSame($expected, $items[0]['count'], 'Výzva „Zaplať dodavatelům".');
        self::assertCount($expected, (new \ReflectionMethod($crm, 'snapshotCurrentIds'))
            ->invoke($crm, $supplierId, 'overdue_payables'));

        $year = (int) date('Y');
        $summary = $container->get(SummaryAction::class);
        self::assertSame($expected, (new \ReflectionMethod($summary, 'kpi'))
            ->invoke($summary, $pdo, $year, $year - 1, $supplierId, true)['purchase_overdue_count']);

        $costs = $container->get(PurchaseSummaryAction::class);
        self::assertSame($expected, (new \ReflectionMethod($costs, 'kpi'))
            ->invoke($costs, $pdo, $year, $year - 1, $supplierId, true)['overdue_count']);
        self::assertCount($expected, (new \ReflectionMethod($costs, 'overdue'))
            ->invoke($costs, $pdo, $supplierId));
    }
}
