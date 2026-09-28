<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Invoice;

use MyInvoice\Action\Invoice\IssueInvoiceAction;
use MyInvoice\Action\Invoice\MarkPaidAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Service\Invoice\InvoicePaymentService;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

/**
 * Vyúčtování s výsledkem k vyplacení (supplier.allow_refund_invoices): faktura vratných
 * obalů 892 Kč − vrácený sud 1 500 Kč = −608 Kč. Bez volby se vystavit nesmí (dnešní
 * chování), s volbou ano, při vystavení se sama neoznačí jako zaplacená a ruční
 * „vyplaceno" ji uzavře stavem, bez řádku v invoice_payments.
 */
#[Group('integration')]
final class RefundInvoiceIssueTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private IssueInvoiceAction $issueAction;
    private MarkPaidAction $markPaidAction;
    private InvoicePaymentService $payments;

    private int $supplierId = 0;
    private int $clientId = 0;
    private int $currencyId = 0;
    private int $vat21Id = 0;
    private int $vat0Id = 0;
    private int $userId = 0;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $container = Bootstrap::buildContainer();
            $this->db = $container->get(Connection::class);
            $this->issueAction = $container->get(IssueInvoiceAction::class);
            $this->markPaidAction = $container->get(MarkPaidAction::class);
            $this->payments = $container->get(InvoicePaymentService::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $sourceSupplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->currencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->vat21Id = (int) ($pdo->query(
            'SELECT id FROM vat_rates WHERE rate_percent = 21 AND is_reverse_charge = 0 ORDER BY valid_from DESC LIMIT 1'
        )->fetchColumn() ?: 0);
        $this->vat0Id = (int) ($pdo->query(
            'SELECT id FROM vat_rates WHERE rate_percent = 0 AND is_reverse_charge = 0 ORDER BY valid_from DESC LIMIT 1'
        )->fetchColumn() ?: 0);
        $czId = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        if ($sourceSupplierId === 0 || $this->userId === 0 || $this->currencyId === 0
            || $this->vat21Id === 0 || $this->vat0Id === 0 || $czId === 0) {
            $this->markTestSkipped('Chybí základní data (supplier/user/currency/vat_rate/country).');
        }

        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $pdo->prepare(
            "UPDATE supplier SET stock_enabled = 0, accounting_mode = 'tax_evidence', allow_refund_invoices = 0 WHERE id = ?"
        )->execute([$this->supplierId]);
        $this->setVatPayerAt($pdo, $this->supplierId, '1900-01-01', true);

        $pdo->prepare(
            'INSERT INTO clients
                (supplier_id, company_name, street, city, zip, country_id, currency_default_id,
                 main_email, language, is_customer, is_vendor)
             VALUES (?, "Hospoda U Sudu s.r.o.", "Testovací 1", "Praha", "11000", ?, ?, "", "cs", 1, 0)'
        )->execute([$this->supplierId, $czId, $this->currencyId]);
        $this->clientId = (int) $pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        if (!isset($this->db) || $this->supplierId === 0) {
            return;
        }
        $pdo = $this->db->pdo();
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $sid = $this->supplierId;
        $pdo->prepare('DELETE ii FROM invoice_items ii JOIN invoices i ON i.id = ii.invoice_id WHERE i.supplier_id = ?')
            ->execute([$sid]);
        $pdo->prepare('DELETE FROM invoice_payments WHERE supplier_id = ?')->execute([$sid]);
        $pdo->prepare('DELETE FROM invoices WHERE supplier_id = ?')->execute([$sid]);
        $pdo->prepare('DELETE FROM invoice_counters WHERE supplier_id = ?')->execute([$sid]);
        $pdo->prepare('DELETE FROM clients WHERE supplier_id = ?')->execute([$sid]);
        $pdo->prepare('DELETE FROM supplier WHERE id = ?')->execute([$sid]);
        $this->db->close();
    }

    private function enableRefunds(): void
    {
        $this->db->pdo()->prepare('UPDATE supplier SET allow_refund_invoices = 1 WHERE id = ?')
            ->execute([$this->supplierId]);
    }

    /**
     * @param list<array{0:string,1:float,2:float,3:bool}> $lines popis, množství, cena bez DPH, 21 %?
     */
    private function draft(string $type, array $lines): int
    {
        $pdo = $this->db->pdo();
        $date = date('Y-m-d');
        $base = 0.0;
        $vat = 0.0;
        foreach ($lines as [, $qty, $price, $vat21]) {
            $lineBase = round($qty * $price, 2);
            $base += $lineBase;
            $vat += $vat21 ? round($lineBase * 0.21, 2) : 0.0;
        }
        $pdo->prepare(
            'INSERT INTO invoices
                (supplier_id, varsymbol, invoice_type, client_id, issue_date, tax_date, due_date,
                 currency_id, language, status, total_without_vat, total_vat, total_with_vat, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, "cs", "draft", ?, ?, ?, ?)'
        )->execute([
            $this->supplierId,
            'RFD-' . substr(bin2hex(random_bytes(6)), 0, 12),
            $type,
            $this->clientId,
            $date,
            $type === 'proforma' ? null : $date,
            $date,
            $this->currencyId,
            round($base, 2),
            round($vat, 2),
            round($base + $vat, 2),
            $this->userId,
        ]);
        $id = (int) $pdo->lastInsertId();

        $item = $pdo->prepare(
            'INSERT INTO invoice_items
                (invoice_id, description, quantity, unit, unit_price_without_vat,
                 vat_rate_id, vat_rate_snapshot, total_without_vat, total_vat, total_with_vat, order_index)
             VALUES (?, ?, ?, "ks", ?, ?, ?, ?, ?, ?, ?)'
        );
        foreach ($lines as $i => [$description, $qty, $price, $vat21]) {
            $lineBase = round($qty * $price, 2);
            $lineVat = $vat21 ? round($lineBase * 0.21, 2) : 0.0;
            $item->execute([
                $id, $description, $qty, $price,
                $vat21 ? $this->vat21Id : $this->vat0Id, $vat21 ? 21 : 0,
                $lineBase, $lineVat, round($lineBase + $lineVat, 2), $i,
            ]);
        }
        return $id;
    }

    /** 24× limonáda 21 % + 24× záloha lahev + přepravka − sud = −608 Kč. */
    private function bottleSettlement(string $type = 'invoice'): int
    {
        return $this->draft($type, [
            ['Limonáda 0,5 l', 24, 25.0, true],
            ['Záloha lahev', 24, 3.0, false],
            ['Záloha přepravka', 1, 94.0, false],
            ['Vrácený sud', 1, -1500.0, false],
        ]);
    }

    /** @return array{status:int, body:array<string,mixed>} */
    private function call(object $action, string $path, int $id, array $body = []): array
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/invoices/' . $id . '/' . $path)
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin'])
            ->withParsedBody($body);
        $response = $action($request, new Psr7Response(), ['id' => (string) $id]);
        $response->getBody()->rewind();
        $decoded = json_decode((string) $response->getBody(), true);
        return ['status' => $response->getStatusCode(), 'body' => is_array($decoded) ? $decoded : []];
    }

    /** @return array<string,mixed> */
    private function row(int $id): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT status, paid_at, amount_to_pay, paid_total FROM invoices WHERE id = ?');
        $stmt->execute([$id]);
        return (array) $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function testNegativeInvoiceIsRejectedWithoutSwitch(): void
    {
        $id = $this->bottleSettlement();
        self::assertSame(-608.0, (float) $this->row($id)['amount_to_pay']);

        $res = $this->call($this->issueAction, 'issue', $id);

        self::assertSame(409, $res['status'], json_encode($res['body'], JSON_UNESCAPED_UNICODE));
        self::assertSame('invalid_amount', $res['body']['error']['code'] ?? null);
        self::assertSame('draft', $this->row($id)['status']);
    }

    public function testRefundInvoiceIssuesWithSwitchAndStaysOpen(): void
    {
        $this->enableRefunds();
        $id = $this->bottleSettlement();

        $res = $this->call($this->issueAction, 'issue', $id);

        self::assertSame(200, $res['status'], json_encode($res['body'], JSON_UNESCAPED_UNICODE));
        $row = $this->row($id);
        self::assertSame('issued', $row['status'], 'Faktura k vyplacení se při vystavení nesmí sama uzavřít.');
        self::assertNull($row['paid_at']);
        self::assertSame(-608.0, (float) $row['amount_to_pay']);
    }

    public function testOnlyNegativeLinesStayRejectedWithSwitch(): void
    {
        $this->enableRefunds();
        $id = $this->draft('invoice', [['Vrácený sud', 1, -1500.0, false]]);

        $res = $this->call($this->issueAction, 'issue', $id);

        self::assertSame(409, $res['status'], json_encode($res['body'], JSON_UNESCAPED_UNICODE));
        self::assertSame('draft', $this->row($id)['status']);
    }

    public function testNegativeProformaStaysRejectedWithSwitch(): void
    {
        $this->enableRefunds();
        $id = $this->bottleSettlement('proforma');

        $res = $this->call($this->issueAction, 'issue', $id);

        self::assertSame(409, $res['status'], json_encode($res['body'], JSON_UNESCAPED_UNICODE));
        self::assertSame('draft', $this->row($id)['status']);
    }

    public function testMarkPaidRefundsInvoiceWithoutPaymentRecord(): void
    {
        $this->enableRefunds();
        $id = $this->bottleSettlement();
        self::assertSame(200, $this->call($this->issueAction, 'issue', $id)['status']);

        $res = $this->call($this->markPaidAction, 'mark-paid', $id, ['paid_at' => date('Y-m-d')]);

        self::assertSame(200, $res['status'], json_encode($res['body'], JSON_UNESCAPED_UNICODE));
        $row = $this->row($id);
        self::assertSame('paid', $row['status']);
        self::assertSame(date('Y-m-d'), substr((string) $row['paid_at'], 0, 10));
        self::assertSame(0.0, (float) $row['paid_total']);
        $count = $this->db->pdo()->prepare('SELECT COUNT(*) FROM invoice_payments WHERE invoice_id = ?');
        $count->execute([$id]);
        self::assertSame(0, (int) $count->fetchColumn(), 'Vrácení peněz se neeviduje jako přijatá platba.');
    }

    public function testIncomingPaymentCannotSettleRefundInvoice(): void
    {
        $this->enableRefunds();
        $id = $this->bottleSettlement();
        self::assertSame(200, $this->call($this->issueAction, 'issue', $id)['status']);

        try {
            $this->payments->recordPayment($id, 100.0, date('Y-m-d'), ['source' => 'manual']);
            self::fail('Přijatá platba nesmí fakturu k vyplacení uhradit.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('nepřijímá platby', $e->getMessage());
        }
        self::assertSame('issued', $this->row($id)['status']);
    }
}
