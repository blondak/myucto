<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Bank;

use MyInvoice\Action\Bank\BankStatementAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

/**
 * Ruční spárování odchozí platby s vydaným dobropisem musí doklad vyrovnat stejně
 * jako automatické párování (StatementMatcher::matchIssuedCreditRefund): alokace
 * v payment_matches (z ní se účtuje 311/221), matched_invoice_id a stav `paid`.
 * Dřív zůstala jen vazba matched_invoice_id, dobropis visel otevřený a bankovní
 * zápis padal na chybějící alokaci.
 */
#[Group('integration')]
final class ManualCreditRefundMatchTest extends TestCase
{
    private const FILE_MARKER = '__manual_credit_refund_match_test__';
    private const CLIENT_MARKER = '__manual_credit_refund_match_client__';
    private const TEST_VS = ['209980001', '209980002', '209980003', '209980004'];

    private Connection $db;
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
            $container = Bootstrap::buildContainer();
            $this->db = $container->get(Connection::class);
            $this->action = $container->get(BankStatementAction::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $account = $pdo->query(
            "SELECT id, supplier_id, account_number, bank_code FROM currencies
              WHERE code = 'CZK' AND account_number IS NOT NULL AND account_number <> ''
              ORDER BY id LIMIT 1"
        )->fetch(PDO::FETCH_ASSOC);
        if (!$account) {
            $this->markTestSkipped('Chybí CZK měna s bankovním účtem.');
        }
        $this->supplierId = (int) $account['supplier_id'];
        $this->currencyId = (int) $account['id'];
        $this->account = (string) $account['account_number'];
        $this->bankCode = $account['bank_code'] !== null ? (string) $account['bank_code'] : null;
        $this->countryId = (int) ($pdo->query('SELECT id FROM countries ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($this->countryId === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí country/user pro integrační test.');
        }

        $this->cleanup();
        $pdo->prepare(
            'INSERT INTO clients
                (supplier_id, company_name, street, city, zip, country_id, main_email,
                 language, currency_default_id, is_customer, is_vendor)
             VALUES (?, ?, "Test 1", "Praha", "11000", ?, "", "cs", ?, 1, 0)'
        )->execute([$this->supplierId, self::CLIENT_MARKER, $this->countryId, $this->currencyId]);
        $this->clientId = (int) $pdo->lastInsertId();
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
                AND entity_id IN (SELECT id FROM invoices WHERE supplier_id = ? AND varsymbol IN ($placeholders))"
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
        $pdo->prepare(
            "DELETE pm FROM payment_matches pm
               JOIN bank_transactions bt ON bt.id = pm.bank_transaction_id
               JOIN bank_statements bs ON bs.id = bt.statement_id
              WHERE bs.file_name LIKE ?"
        )->execute(['%' . self::FILE_MARKER . '%']);
        $pdo->prepare('DELETE FROM bank_statements WHERE file_name LIKE ?')
            ->execute(['%' . self::FILE_MARKER . '%']);
        $pdo->prepare("DELETE FROM invoices WHERE supplier_id = ? AND varsymbol IN ($placeholders)")
            ->execute($invoiceParams);
        $pdo->prepare('DELETE FROM clients WHERE supplier_id = ? AND company_name = ?')
            ->execute([$this->supplierId, self::CLIENT_MARKER]);
        $this->clientId = 0;
    }

    /** @return array{invoice_id:int,statement_id:int,transaction_id:int} */
    private function seed(string $varsymbol, float $documentTotal, float $txAmount, string $status = 'issued'): array
    {
        $pdo = $this->db->pdo();
        $date = '2099-07-15';
        $pdo->prepare(
            "INSERT INTO invoices
                (invoice_type, varsymbol, client_id, supplier_id, issue_date, tax_date, due_date,
                 currency_id, exchange_rate, exchange_rate_date, status, paid_at,
                 total_without_vat, total_with_vat, paid_total, created_by)
             VALUES ('credit_note', ?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?, ?, ?, 0, ?)"
        )->execute([
            $varsymbol, $this->clientId, $this->supplierId, $date, $date, $date,
            $this->currencyId, $date, $status, $status === 'paid' ? $date : null,
            $documentTotal, $documentTotal, $this->userId,
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
                (statement_id, posted_at, amount, currency, variable_symbol, counterparty_account, counterparty_bank)
             VALUES (?, ?, ?, "CZK", ?, "1000000005", "0100")'
        )->execute([$statementId, $date, $txAmount, $varsymbol]);

        return [
            'invoice_id' => $invoiceId,
            'statement_id' => $statementId,
            'transaction_id' => (int) $pdo->lastInsertId(),
        ];
    }

    /** @return array{status:int,body:array<string,mixed>} */
    private function call(string $method, int $transactionId, array $body = []): array
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/bank-transactions/' . $transactionId . '/' . $method)
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin'])
            ->withParsedBody($body);
        $response = $method === 'match'
            ? $this->action->manualMatch($request, new Psr7Response(), ['id' => (string) $transactionId])
            : $this->action->unmatch($request, new Psr7Response(), ['id' => (string) $transactionId]);
        $response->getBody()->rewind();
        $decoded = json_decode((string) $response->getBody(), true);
        return ['status' => $response->getStatusCode(), 'body' => is_array($decoded) ? $decoded : []];
    }

    /** @return array<string,mixed> */
    private function invoice(int $id): array
    {
        return $this->db->pdo()->query("SELECT status, paid_at FROM invoices WHERE id = {$id}")->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return list<array<string,mixed>> */
    private function refundMatches(int $transactionId): array
    {
        return $this->db->pdo()->query(
            "SELECT invoice_id, purchase_invoice_id, amount, match_type FROM payment_matches WHERE bank_transaction_id = {$transactionId}"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function testOutgoingPaymentMarksOpenCreditNoteRefunded(): void
    {
        $seed = $this->seed(self::TEST_VS[0], -608.0, -608.0);

        $result = $this->call('match', $seed['transaction_id'], ['invoice_id' => $seed['invoice_id']]);

        self::assertSame(200, $result['status'], json_encode($result['body']));
        self::assertArrayNotHasKey('partial_payment', $result['body']);
        $invoice = $this->invoice($seed['invoice_id']);
        self::assertSame('paid', $invoice['status'] ?? null);
        self::assertSame('2099-07-15', substr((string) ($invoice['paid_at'] ?? ''), 0, 10));
        $matches = $this->refundMatches($seed['transaction_id']);
        self::assertCount(1, $matches);
        self::assertSame($seed['invoice_id'], (int) $matches[0]['invoice_id']);
        self::assertNull($matches[0]['purchase_invoice_id']);
        self::assertSame(608.0, (float) $matches[0]['amount']);
        self::assertSame('manual', $matches[0]['match_type']);
        $tx = $this->db->pdo()->query(
            "SELECT matched_invoice_id, match_status FROM bank_transactions WHERE id = {$seed['transaction_id']}"
        )->fetch(PDO::FETCH_ASSOC);
        self::assertSame($seed['invoice_id'], (int) $tx['matched_invoice_id']);
        self::assertSame('manual', $tx['match_status']);
        self::assertSame(
            0,
            (int) $this->db->pdo()->query("SELECT COUNT(*) FROM invoice_payments WHERE invoice_id = {$seed['invoice_id']}")->fetchColumn(),
            'Vratka se neeviduje v invoice_payments.',
        );

        $again = $this->call('match', $seed['transaction_id'], ['invoice_id' => $seed['invoice_id']]);
        self::assertSame(200, $again['status'], json_encode($again['body']));
        self::assertCount(1, $this->refundMatches($seed['transaction_id']), 'Opakované párování nesmí zdvojit alokaci.');
    }

    public function testUnmatchReopensRefundedCreditNote(): void
    {
        $seed = $this->seed(self::TEST_VS[1], -608.0, -608.0);
        self::assertSame(200, $this->call('match', $seed['transaction_id'], ['invoice_id' => $seed['invoice_id']])['status']);

        $result = $this->call('unmatch', $seed['transaction_id']);

        self::assertSame(200, $result['status'], json_encode($result['body']));
        $invoice = $this->invoice($seed['invoice_id']);
        self::assertSame('issued', $invoice['status'] ?? null);
        self::assertNull($invoice['paid_at'] ?? null);
        self::assertSame([], $this->refundMatches($seed['transaction_id']));
    }

    public function testSmallerOutgoingPaymentLeavesCreditNoteOpen(): void
    {
        $seed = $this->seed(self::TEST_VS[2], -608.0, -300.0);

        $result = $this->call('match', $seed['transaction_id'], ['invoice_id' => $seed['invoice_id']]);

        self::assertSame(200, $result['status'], json_encode($result['body']));
        self::assertTrue($result['body']['partial_payment'] ?? false);
        self::assertSame('issued', $this->invoice($seed['invoice_id'])['status'] ?? null);
        $matches = $this->refundMatches($seed['transaction_id']);
        self::assertCount(1, $matches);
        self::assertSame(300.0, (float) $matches[0]['amount']);
    }

    public function testIncomingPaymentCannotSettleCreditNote(): void
    {
        $seed = $this->seed(self::TEST_VS[3], -608.0, 608.0);

        $result = $this->call('match', $seed['transaction_id'], ['invoice_id' => $seed['invoice_id']]);

        self::assertSame(409, $result['status'], json_encode($result['body']));
        self::assertSame('issued', $this->invoice($seed['invoice_id'])['status'] ?? null);
        self::assertSame([], $this->refundMatches($seed['transaction_id']));
        $tx = $this->db->pdo()->query(
            "SELECT matched_invoice_id FROM bank_transactions WHERE id = {$seed['transaction_id']}"
        )->fetch(PDO::FETCH_ASSOC);
        self::assertNull($tx['matched_invoice_id']);
    }
}
