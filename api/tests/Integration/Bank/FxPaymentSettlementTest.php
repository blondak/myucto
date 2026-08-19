<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Bank;

use MyInvoice\Action\Bank\BankStatementAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Service\Bank\StatementMatcher;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

/**
 * Regrese: úplná EUR faktura zaplacená na CZK účet se nesmí po spárování
 * tvářit jako částečně uhrazená jen proto, že banka použila jiný kurz než doklad.
 * Skutečná CZK částka zůstává v bank_transactions; invoice_payments eviduje
 * vypořádání v měně faktury.
 */
#[Group('integration')]
final class FxPaymentSettlementTest extends TestCase
{
    private const FILE_MARKER = '__fx_payment_settlement_test__';
    private const CLIENT_MARKER = '__fx_payment_settlement_client__';
    private const CURRENCY_MARKER = 'TEST EUR FX settlement';
    private const TEST_VS = ['209970001', '209970002', '209970003'];

    private Connection $db;
    private StatementMatcher $matcher;
    private BankStatementAction $action;
    private int $supplierId = 0;
    private int $clientId = 0;
    private int $currencyId = 0;
    private int $countryId = 0;
    private int $userId = 0;
    private string $account = '';
    private ?string $bankCode = null;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $container = Bootstrap::buildApp()->getContainer();
            $this->db = $container->get(Connection::class);
            $this->matcher = $container->get(StatementMatcher::class);
            $this->action = $container->get(BankStatementAction::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $account = $pdo->query(
            "SELECT supplier_id, account_number, bank_code FROM currencies
              WHERE code = 'CZK' AND account_number IS NOT NULL AND account_number <> ''
              ORDER BY id LIMIT 1"
        )->fetch(PDO::FETCH_ASSOC);
        if (!$account) {
            $this->markTestSkipped('Chybí CZK měna s bankovním účtem.');
        }
        $this->supplierId = (int) $account['supplier_id'];
        $this->account = (string) $account['account_number'];
        $this->bankCode = $account['bank_code'] !== null ? (string) $account['bank_code'] : null;
        $this->countryId = (int) ($pdo->query('SELECT id FROM countries ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($this->countryId === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí country/user pro integrační test.');
        }

        $this->cleanup();
        $this->seedPartyAndCurrency();
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
        $placeholders = implode(',', array_fill(0, count(self::TEST_VS), '?'));
        $invoiceParams = [$this->supplierId, ...self::TEST_VS];

        $pdo->prepare(
            "DELETE FROM activity_log
              WHERE entity_type = 'invoice'
                AND entity_id IN (
                    SELECT id FROM invoices WHERE supplier_id = ? AND varsymbol IN ($placeholders)
                )"
        )->execute($invoiceParams);
        $pdo->prepare(
            "DELETE FROM activity_log
              WHERE entity_type = 'bank_transaction'
                AND entity_id IN (
                    SELECT bt.id FROM bank_transactions bt
                    JOIN bank_statements bs ON bs.id = bt.statement_id
                    WHERE bs.file_name LIKE ?
                )"
        )->execute(['%' . self::FILE_MARKER . '%']);
        $pdo->prepare('DELETE FROM bank_statements WHERE file_name LIKE ?')
            ->execute(['%' . self::FILE_MARKER . '%']);
        $pdo->prepare("DELETE FROM invoices WHERE supplier_id = ? AND varsymbol IN ($placeholders)")
            ->execute($invoiceParams);
        $pdo->prepare('DELETE FROM clients WHERE supplier_id = ? AND company_name = ?')
            ->execute([$this->supplierId, self::CLIENT_MARKER]);
        $pdo->prepare('DELETE FROM currencies WHERE supplier_id = ? AND label = ?')
            ->execute([$this->supplierId, self::CURRENCY_MARKER]);

        $this->clientId = $this->currencyId = 0;
    }

    private function seedPartyAndCurrency(): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO currencies
                (supplier_id, code, label, symbol, name_cs, name_en, decimals, is_active, is_default)
             VALUES (?, "EUR", ?, "€", "euro", "euro", 2, 0, 0)'
        )->execute([$this->supplierId, self::CURRENCY_MARKER]);
        $this->currencyId = (int) $pdo->lastInsertId();

        // Prázdný e-mail zaručí, že případně zapnuté poděkování neodešle SMTP zprávu.
        $pdo->prepare(
            'INSERT INTO clients
                (supplier_id, company_name, street, city, zip, country_id, main_email,
                 language, currency_default_id, is_customer, is_vendor)
             VALUES (?, ?, "Test 1", "Praha", "11000", ?, "", "cs", ?, 1, 0)'
        )->execute([$this->supplierId, self::CLIENT_MARKER, $this->countryId, $this->currencyId]);
        $this->clientId = (int) $pdo->lastInsertId();
    }

    /** @return array{invoice_id:int,statement_id:int,transaction_id:int} */
    private function seedPayment(
        string $varsymbol,
        float $txAmount,
        float $invoiceAmount = 1000.0,
        float $exchangeRate = 24.5,
    ): array
    {
        $pdo = $this->db->pdo();
        $date = '2099-07-15';
        $pdo->prepare(
            "INSERT INTO invoices
                (invoice_type, varsymbol, client_id, supplier_id, issue_date, tax_date, due_date,
                 currency_id, exchange_rate, exchange_rate_date, status,
                 total_without_vat, total_with_vat, paid_total, created_by)
             VALUES ('invoice', ?, ?, ?, ?, ?, ?, ?, ?, ?, 'issued', ?, ?, 0, ?)"
        )->execute([
            $varsymbol, $this->clientId, $this->supplierId, $date, $date, $date,
            $this->currencyId, $exchangeRate, $date, $invoiceAmount, $invoiceAmount, $this->userId,
        ]);
        $invoiceId = (int) $pdo->lastInsertId();

        $pdo->prepare(
            'INSERT INTO bank_statements
                (file_name, file_hash, account_number, bank_code, currency, statement_date)
             VALUES (?, ?, ?, ?, "CZK", ?)'
        )->execute([
            self::FILE_MARKER . $varsymbol . '.gpc',
            hash('sha256', self::FILE_MARKER . $varsymbol),
            $this->account,
            $this->bankCode,
            $date,
        ]);
        $statementId = (int) $pdo->lastInsertId();

        $pdo->prepare(
            'INSERT INTO bank_transactions
                (statement_id, posted_at, amount, currency, variable_symbol)
             VALUES (?, ?, ?, "CZK", ?)'
        )->execute([$statementId, $date, $txAmount, $varsymbol]);

        return [
            'invoice_id' => $invoiceId,
            'statement_id' => $statementId,
            'transaction_id' => (int) $pdo->lastInsertId(),
        ];
    }

    /** @return array{status:int,body:array<string,mixed>} */
    private function manualMatch(int $transactionId, int $invoiceId): array
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/bank-transactions/' . $transactionId . '/match')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin'])
            ->withParsedBody(['invoice_id' => $invoiceId]);
        $response = $this->action->manualMatch(
            $request,
            new Psr7Response(),
            ['id' => (string) $transactionId],
        );
        $response->getBody()->rewind();
        $body = json_decode((string) $response->getBody(), true) ?: [];
        return ['status' => $response->getStatusCode(), 'body' => is_array($body) ? $body : []];
    }

    /** @return array<string,mixed> */
    private function paymentRow(int $transactionId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT amount, currency, bank_transaction_id FROM invoice_payments
              WHERE bank_transaction_id = ?'
        );
        $stmt->execute([$transactionId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    public function testAutomaticFullFxMatchSettlesWholeInvoice(): void
    {
        $seed = $this->seedPayment(self::TEST_VS[0], 24300.00);

        $result = $this->matcher->match($seed['transaction_id']);

        self::assertSame('auto_exact', $result['status'] ?? null);
        self::assertSame($seed['invoice_id'], $result['invoice_id'] ?? null);
        $invoice = $this->db->pdo()->query(
            "SELECT status, paid_total, exchange_rate FROM invoices WHERE id = {$seed['invoice_id']}"
        )->fetch(PDO::FETCH_ASSOC);
        self::assertSame('paid', $invoice['status'] ?? null);
        self::assertSame(1000.0, (float) ($invoice['paid_total'] ?? 0));
        self::assertSame(24.5, (float) ($invoice['exchange_rate'] ?? 0), 'Kurz dokladu se nesmí změnit.');

        $payment = $this->paymentRow($seed['transaction_id']);
        self::assertSame(1000.0, (float) ($payment['amount'] ?? 0));
        self::assertSame('EUR', $payment['currency'] ?? null);
        $bankAmount = (float) $this->db->pdo()->query(
            "SELECT amount FROM bank_transactions WHERE id = {$seed['transaction_id']}"
        )->fetchColumn();
        self::assertSame(24300.0, $bankAmount, 'Skutečná CZK částka musí zůstat na bankovní transakci.');
    }

    public function testManualFullFxMatchSettlesWholeInvoice(): void
    {
        // 123,45 × 24,50 = 3 024,525 CZK; skutečný pohyb 3 018,41 CZK nelze
        // zpětně vyjádřit zaokrouhleným kurzem tak, aby součin seděl na haléř.
        $seed = $this->seedPayment(self::TEST_VS[1], 3018.41, 123.45);

        $result = $this->manualMatch($seed['transaction_id'], $seed['invoice_id']);

        self::assertSame(200, $result['status'], json_encode($result['body']));
        self::assertArrayNotHasKey('partial_payment', $result['body']);
        $payment = $this->paymentRow($seed['transaction_id']);
        self::assertSame(123.45, (float) ($payment['amount'] ?? 0));
        self::assertSame(
            'paid',
            $this->db->pdo()->query("SELECT status FROM invoices WHERE id = {$seed['invoice_id']}")->fetchColumn(),
        );
    }

    public function testManualPartialFxMatchStillUsesInvoiceRate(): void
    {
        $seed = $this->seedPayment(self::TEST_VS[2], 10000.00);

        $result = $this->manualMatch($seed['transaction_id'], $seed['invoice_id']);

        self::assertSame(200, $result['status'], json_encode($result['body']));
        self::assertTrue($result['body']['partial_payment'] ?? false);
        $payment = $this->paymentRow($seed['transaction_id']);
        self::assertSame(408.16, (float) ($payment['amount'] ?? 0));
        self::assertSame(
            'issued',
            $this->db->pdo()->query("SELECT status FROM invoices WHERE id = {$seed['invoice_id']}")->fetchColumn(),
        );
    }
}
