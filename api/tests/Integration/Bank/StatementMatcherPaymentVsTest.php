<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Bank;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Bank\StatementMatcher;
use MyInvoice\Service\Invoice\FinalFromProformaCreator;
use MyInvoice\Service\Invoice\InvoicePaymentService;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Párování příchozí platby podle samostatného platebního VS vydané faktury (#249).
 *
 * Číslo dokladu (`varsymbol`) zůstává unikátní, platební VS (`payment_variable_symbol`)
 * se smí opakovat — typicky u pravidelné fakturace s trvalým příkazem. Při víc fakturách
 * se shodným platebním VS rozhoduje částka mezi nezaplacenými; když nerozhodne, platba
 * zůstane k ručnímu spárování.
 *
 * Izolace: rok 2099, vlastní výpis/transakce/faktury, úklid v tearDown.
 */
#[Group('integration')]
final class StatementMatcherPaymentVsTest extends TestCase
{
    private Connection $db;
    private StatementMatcher $matcher;
    private int $supplierId = 0;
    private int $clientId = 0;
    private int $currencyId = 0;
    private int $userId = 0;
    private string $account = '';
    private ?string $bankCode = null;
    private string $date = '2099-06-15';
    private int $statementId = 0;

    private const FILE_MARKER = '__vs249_test__';
    private const DOC_PREFIX = '2099-249';

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $c = Bootstrap::buildApp()->getContainer();
            $this->db = $c->get(Connection::class);
            $this->matcher = new StatementMatcher(
                $this->db,
                $c->get(FinalFromProformaCreator::class),
                null,
                $c->get(InvoicePaymentService::class),
            );
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $cur = $pdo->query(
            "SELECT id, supplier_id, account_number, bank_code FROM currencies
              WHERE code = 'CZK' AND account_number IS NOT NULL AND account_number <> ''
              ORDER BY id LIMIT 1"
        )->fetch(PDO::FETCH_ASSOC);
        if (!$cur) {
            $this->markTestSkipped('Chybí CZK currency s account_number.');
        }
        $this->currencyId = (int) $cur['id'];
        $this->supplierId = (int) $cur['supplier_id'];
        $this->account = (string) $cur['account_number'];
        $this->bankCode = $cur['bank_code'] !== null ? (string) $cur['bank_code'] : null;
        $this->clientId = (int) ($pdo->query("SELECT id FROM clients WHERE supplier_id = {$this->supplierId} ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($this->clientId === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí client/user pro supplier.');
        }

        $this->cleanup();
        $pdo->prepare(
            "INSERT INTO bank_statements (file_name, file_hash, account_number, bank_code, currency, statement_date)
             VALUES (?, ?, ?, ?, 'CZK', ?)"
        )->execute([self::FILE_MARKER . '.gpc', hash('sha256', self::FILE_MARKER . microtime()), $this->account, $this->bankCode, $this->date]);
        $this->statementId = (int) $pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $this->cleanup();
        }
    }

    private function cleanup(): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare('DELETE FROM bank_statements WHERE file_name LIKE ?')->execute(['%' . self::FILE_MARKER . '%']);
        $pdo->prepare('DELETE FROM invoices WHERE supplier_id = ? AND varsymbol LIKE ?')
            ->execute([$this->supplierId, self::DOC_PREFIX . '%']);
    }

    private function invoice(string $number, ?string $paymentVs, float $amount, string $status = 'issued'): int
    {
        $pdo = $this->db->pdo();
        $paid = $status === 'paid' ? $amount : 0.0;
        $pdo->prepare(
            "INSERT INTO invoices
                (invoice_type, varsymbol, payment_variable_symbol, client_id, supplier_id, issue_date, tax_date, due_date,
                 currency_id, status, total_without_vat, total_with_vat, paid_total, created_by)
             VALUES ('invoice', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        )->execute([
            self::DOC_PREFIX . $number, $paymentVs, $this->clientId, $this->supplierId,
            $this->date, $this->date, $this->date, $this->currencyId, $status, $amount, $amount, $paid, $this->userId,
        ]);
        return (int) $pdo->lastInsertId();
    }

    private function transaction(string $vs, float $amount): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            "INSERT INTO bank_transactions (statement_id, posted_at, amount, currency, variable_symbol)
             VALUES (?, ?, ?, 'CZK', ?)"
        )->execute([$this->statementId, $this->date, $amount, $vs]);
        return (int) $pdo->lastInsertId();
    }

    public function testSeparatePaymentVsMatchesInvoice(): void
    {
        $id = $this->invoice('001', '8249000017', 1210.00);

        $res = $this->matcher->match($this->transaction('8249000017', 1210.00));

        self::assertSame('auto_exact', $res['status'] ?? null);
        self::assertSame($id, $res['invoice_id'] ?? null);
        self::assertSame('paid', $this->db->pdo()->query("SELECT status FROM invoices WHERE id = {$id}")->fetchColumn());
    }

    public function testDocumentNumberStillMatchesInvoiceWithPaymentVs(): void
    {
        $id = $this->invoice('002', '8249000017', 500.00);

        $res = $this->matcher->match($this->transaction('2099249002', 500.00));

        self::assertSame('auto_exact', $res['status'] ?? null);
        self::assertSame($id, $res['invoice_id'] ?? null);
    }

    public function testPaymentVsWithLeadingZerosMatches(): void
    {
        $id = $this->invoice('003', '0082490019', 750.00);

        $res = $this->matcher->match($this->transaction('82490019', 750.00));

        self::assertSame('auto_exact', $res['status'] ?? null);
        self::assertSame($id, $res['invoice_id'] ?? null);
    }

    public function testSharedPaymentVsIsResolvedByAmountAmongUnpaid(): void
    {
        $this->invoice('004', '8249000018', 1500.00, 'paid');
        $this->invoice('005', '8249000018', 1000.00);
        $target = $this->invoice('006', '8249000018', 1500.00);

        $res = $this->matcher->match($this->transaction('8249000018', 1500.00));

        self::assertSame('auto_exact', $res['status'] ?? null, 'Zaplacený doklad se stejnou částkou nesmí kolidovat s nezaplaceným.');
        self::assertSame($target, $res['invoice_id'] ?? null);
    }

    public function testSharedPaymentVsWithEqualAmountsStaysForManualMatch(): void
    {
        $first = $this->invoice('007', '8249000020', 1000.00);
        $second = $this->invoice('008', '8249000020', 1000.00);
        $txId = $this->transaction('8249000020', 1000.00);

        $res = $this->matcher->match($txId);

        self::assertSame('unmatched', $res['status'] ?? null);
        self::assertSame('ambiguous_vs', $res['reason'] ?? null);
        $pdo = $this->db->pdo();
        self::assertSame('issued', $pdo->query("SELECT status FROM invoices WHERE id = {$first}")->fetchColumn());
        self::assertSame('issued', $pdo->query("SELECT status FROM invoices WHERE id = {$second}")->fetchColumn());
        self::assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM invoice_payments WHERE bank_transaction_id = {$txId}")->fetchColumn());
    }

    public function testSharedPaymentVsWithoutMatchingAmountStaysForManualMatch(): void
    {
        $this->invoice('009', '8249000021', 1000.00);
        $this->invoice('010', '8249000021', 2000.00);

        $res = $this->matcher->match($this->transaction('8249000021', 1234.00));

        self::assertSame('unmatched', $res['status'] ?? null);
        self::assertSame('ambiguous_vs', $res['reason'] ?? null);
    }
}
