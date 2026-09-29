<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Portfolio;

use MyInvoice\Action\Dashboard\PurchaseSummaryAction;
use MyInvoice\Action\Dashboard\SummaryAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Security\EffectiveRole;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\OtherItemScheduleService;
use MyInvoice\Service\Accounting\OtherItemService;
use MyInvoice\Service\Portfolio\GroupDashboardForecast;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Psr7\Factory\ServerRequestFactory;

#[Group('integration')]
final class GroupDashboardForecastTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private PDO $pdo;
    private GroupDashboardForecast $forecast;
    private OtherItemService $items;
    private OtherItemScheduleService $schedules;
    private SummaryAction $revenue;
    private PurchaseSummaryAction $costs;
    private int $supplierId;

    protected function setUp(): void
    {
        $container = Bootstrap::buildContainer();
        $this->db = $container->get(Connection::class);
        $this->pdo = $this->db->pdo();
        $this->pdo->beginTransaction();
        $source = (int) $this->pdo->query('SELECT MIN(id) FROM supplier')->fetchColumn();
        self::assertGreaterThan(0, $source);
        $this->supplierId = $this->createIsolatedSupplier($this->pdo, $source);
        $this->pdo->prepare("UPDATE supplier SET company_name = 'Synthetic annual forecast', is_vat_payer = 1, accounting_mode = 'double_entry' WHERE id = ?")
            ->execute([$this->supplierId]);
        $container->get(ChartOfAccountsSeeder::class)->seedForSupplier($this->supplierId);
        $this->forecast = $container->get(GroupDashboardForecast::class);
        $this->items = $container->get(OtherItemService::class);
        $this->schedules = $container->get(OtherItemScheduleService::class);
        $this->revenue = $container->get(SummaryAction::class);
        $this->costs = $container->get(PurchaseSummaryAction::class);
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) $this->pdo->rollBack();
        if (isset($this->db)) $this->db->close();
    }

    public function testAnnualModelsRemainNativeVatAwareAndOtherItemsAreAddedOnlyOnce(): void
    {
        $this->documents();
        $this->item('receivable', '602', 30);
        $this->item('payable', '518', 10);
        $rows = $this->forecast->read($this->request(), ['id' => $this->supplierId]);
        self::assertCount(1, $rows);
        $row = $rows[0];
        $today = new \DateTimeImmutable('today');
        $expectedRevenue = round(100 / ((int) $today->format('z') + 1) * ($today->format('L') === '1' ? 366 : 365), 2);
        self::assertSame('CZK', $row['currency']);
        self::assertSame((int) date('Y'), $row['year']);
        self::assertEqualsWithDelta($expectedRevenue, $row['revenue_model'], 0.01);
        self::assertSame(20.0, $row['costs_model']);
        self::assertSame(100.0, $row['revenue_current_year']);
        self::assertSame(20.0, $row['costs_current_year']);
        self::assertSame(30.0, $row['other_revenue']);
        self::assertSame(10.0, $row['other_costs']);
        self::assertEqualsWithDelta($expectedRevenue + 30, $row['revenue'], 0.01);
        self::assertSame(30.0, $row['costs']);
        self::assertEqualsWithDelta($expectedRevenue, $row['profit'], 0.01);
        self::assertSame($row['profit'], $row['profit_low']);
        self::assertSame($row['profit'], $row['profit_high']);
        self::assertSame(40.0, $row['other_draft']);
        self::assertSame(0.0, $row['other_posted']);
        self::assertSame($row['revenue_model'], $this->revenue->annualRevenueForecast($this->supplierId)[0]['forecast']);
        self::assertSame($row['costs_model'], $this->costs->annualCostsForecast($this->supplierId)[0]['forecast']);
    }

    public function testInstallmentsDoNotSplitOrDuplicateTheAnnualResultAndLoanPrincipalIsExcluded(): void
    {
        $expense = $this->item('payable', '518', 1200);
        $this->schedules->setInstallments($this->supplierId, (int) $expense['id'], [
            ['due_on' => date('Y-m-d', strtotime('+5 days')), 'amount' => 600],
            ['due_on' => date('Y-m-d', strtotime('+10 days')), 'amount' => 600],
        ]);
        $this->item('payable', '461', 8000);
        $row = $this->forecast->read($this->request(), ['id' => $this->supplierId])[0];
        self::assertSame(1200.0, $row['other_costs']);
        self::assertSame(1200.0, $row['costs']);
        self::assertSame(-1200.0, $row['profit']);
        self::assertSame(1200.0, $row['other_draft']);
    }

    public function testOtherItemOnlyCurrencyWithoutCodebookIsKeptSeparate(): void
    {
        $this->item('receivable', '602', 15, 'USD', 22);
        $this->item('payable', '518', 20, 'EUR', 25);
        $rows = $this->forecast->read($this->request(), ['id' => $this->supplierId]);
        self::assertSame(['EUR', 'USD'], array_column($rows, 'currency'));
        self::assertSame(-20.0, $rows[0]['profit']);
        self::assertSame(15.0, $rows[1]['profit']);
        self::assertSame(0.0, $rows[0]['revenue_model']);
        self::assertSame(0.0, $rows[1]['costs_model']);
    }

    public function testAllThreeReadPermissionsAreRequiredAndAnEmptySuccessfulProjectionIsKnown(): void
    {
        foreach (['invoices', 'purchase_invoices', 'other_items'] as $missing) {
            self::assertNull($this->forecast->read($this->request($missing), ['id' => $this->supplierId]), $missing);
        }
        self::assertSame([], $this->forecast->read($this->request(), ['id' => $this->supplierId]));
    }

    public function testResolvedSupplierMismatchCannotReadAnotherCompany(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->forecast->read($this->request(), ['id' => $this->supplierId + 1]);
    }

    private function request(?string $missing = null): Request
    {
        $permissions = ['invoices' => 1, 'purchase_invoices' => 1, 'other_items' => 1];
        if ($missing !== null) unset($permissions[$missing]);
        return (new ServerRequestFactory())->createServerRequest('GET', '/api/portfolio/group-dashboard')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute('auth.effective_role', new EffectiveRole(0, 'Synthetic read role', 'staff', true, $permissions));
    }

    private function item(string $side, string $account, float $amount, string $currency = 'CZK', float $rate = 1): array
    {
        $item = $this->items->create($this->supplierId, [
            'side' => $side, 'kind' => 'other', 'title' => 'Synthetic annual item',
            'issued_on' => date('Y-m-d'), 'accounting_on' => date('Y-m-d'),
            'due_on' => date('Y-m-d', strtotime('+10 days')), 'currency' => 'CZK',
            'amount' => $amount, 'exchange_rate' => $rate, 'counter_account_code' => $account,
        ], null);
        if ($currency !== 'CZK') {
            $this->pdo->prepare('UPDATE other_items SET currency = ?, exchange_rate = ?, amount_czk = ? WHERE supplier_id = ? AND id = ?')
                ->execute([$currency, $rate, $amount * $rate, $this->supplierId, $item['id']]);
        }
        return $item;
    }

    private function documents(): void
    {
        $this->pdo->prepare("INSERT INTO currencies (supplier_id, code, label, symbol, name_cs, name_en, decimals, is_active, is_default) VALUES (?, 'CZK', 'Synthetic CZK', 'CZK', 'Synthetic CZK', 'Synthetic CZK', 2, 1, 1)")
            ->execute([$this->supplierId]);
        $currencyId = (int) $this->pdo->lastInsertId();
        $country = (int) $this->pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn();
        $this->pdo->prepare("INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id, main_email, language, currency_default_id, is_customer, is_vendor)
            VALUES (?, 'Synthetic forecast counterparty', 'Synthetic street', 'Synthetic city', '00000', ?, 'synthetic@example.invalid', 'cs', ?, 1, 1)")
            ->execute([$this->supplierId, $country, $currencyId]);
        $partyId = (int) $this->pdo->lastInsertId();
        $userId = (int) $this->pdo->query('SELECT MIN(id) FROM users')->fetchColumn();
        $date = date('Y-m-d');
        $this->pdo->prepare("INSERT INTO invoices (supplier_id, varsymbol, invoice_type, client_id, issue_date, tax_date, due_date, currency_id, exchange_rate, total_without_vat, total_vat, total_with_vat, status, created_by)
            VALUES (?, ?, 'invoice', ?, ?, ?, ?, ?, 1, 100, 21, 121, 'issued', ?)")
            ->execute([$this->supplierId, 'SYN-' . bin2hex(random_bytes(4)), $partyId, $date, $date, $date, $currencyId, $userId]);
        $this->pdo->prepare("INSERT INTO purchase_invoices (supplier_id, vendor_id, varsymbol, vendor_invoice_number, document_kind, issue_date, tax_date, due_date, received_at, currency_id, exchange_rate, vendor_snapshot, total_without_vat, total_vat, total_with_vat, status, created_by)
            VALUES (?, ?, ?, ?, 'invoice', ?, ?, ?, ?, ?, 1, '{}', 20, 4.2, 24.2, 'received', ?)")
            ->execute([$this->supplierId, $partyId, 'SYN-C-' . bin2hex(random_bytes(4)), 'SYNTH-COST', $date, $date, $date, $date, $currencyId, $userId]);
    }
}
