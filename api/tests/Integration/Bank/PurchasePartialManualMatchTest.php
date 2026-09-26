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
 * Ruční spárování odchozí platby s přijatou fakturou, jejíž částka se od zbytku liší.
 *
 * Reálný nález: faktura na 236,84 EUR ručně spárovaná s platbou 233,17 EUR skončila
 * jako `paid`. Banka zaúčtovala jen skutečnou platbu, na 321 zůstal nedoplatek a doklad
 * ani saldokonto ho neukázaly. Platba menší o víc než toleranci dorovnání (1,00 v měně
 * dokladu, stejná jako 548/648 v bance) doklad uzavřít nesmí — zůstane částečně
 * uhrazený a odpověď řekne, kolik zbývá, aby UI mohlo nabídnout vyrovnání rozdílu.
 *
 * Izolace: rok 2099, vlastní doklad + výpis, úklid v tearDown.
 */
#[Group('integration')]
final class PurchasePartialManualMatchTest extends TestCase
{
    private const FILE_MARKER = '__purchase_partial_manual2099__';
    private const VS = '20998177';

    private Connection $db;
    private BankStatementAction $action;
    private int $supplierId = 0;
    private int $vendorId = 0;
    private int $currencyId = 0;
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
            $c = Bootstrap::buildContainer();
            $this->db = $c->get(Connection::class);
            $this->action = $c->get(BankStatementAction::class);
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

        $this->vendorId = (int) ($pdo->query("SELECT id FROM clients WHERE supplier_id = {$this->supplierId} ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($this->vendorId === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí client/user pro supplier.');
        }

        $this->cleanup();
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $this->cleanup();
        }
    }

    public function testShortfallKeepsPurchaseOpenAndReportsRemaining(): void
    {
        $piId = $this->seedPurchase(1000.00);
        $txId = $this->seedTransaction(950.00);

        $body = $this->match($txId, $piId);

        self::assertSame('received', $this->docStatus($piId), 'Nedoplatek 50 Kč doklad neuzavře.');
        self::assertTrue($body['partial_payment'] ?? false);
        self::assertEqualsWithDelta(50.00, (float) ($body['remaining'] ?? 0), 0.001);
        self::assertSame('CZK', $body['currency'] ?? null);
        self::assertSame('950.00', $this->scalar("SELECT amount FROM payment_matches WHERE bank_transaction_id = {$txId}"));
    }

    public function testDifferenceWithinRoundingToleranceSettles(): void
    {
        $piId = $this->seedPurchase(1000.80);
        $txId = $this->seedTransaction(1000.00);

        $body = $this->match($txId, $piId);

        self::assertSame('paid', $this->docStatus($piId), 'Haléřový rozdíl dorovná banka na 548 — doklad je uhrazený.');
        self::assertArrayNotHasKey('partial_payment', $body);
    }

    public function testSecondPaymentCoveringTheRestSettles(): void
    {
        $piId = $this->seedPurchase(1000.00);
        $this->match($this->seedTransaction(600.00), $piId);
        self::assertSame('received', $this->docStatus($piId));

        $body = $this->match($this->seedTransaction(400.00), $piId);

        self::assertSame('paid', $this->docStatus($piId), 'Doplatek zbytku doklad uzavře.');
        self::assertArrayNotHasKey('partial_payment', $body);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /** @return array<string,mixed> */
    private function match(int $txId, int $piId): array
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/bank-transactions/' . $txId . '/match')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin'])
            ->withParsedBody(['purchase_invoice_id' => $piId]);
        $response = $this->action->manualMatch($request, new Psr7Response(), ['id' => (string) $txId]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        return (array) json_decode((string) $response->getBody(), true);
    }

    private function docStatus(int $piId): string
    {
        return (string) $this->scalar("SELECT status FROM purchase_invoices WHERE id = {$piId}");
    }

    private function scalar(string $sql): mixed
    {
        return $this->db->pdo()->query($sql)->fetchColumn();
    }

    private function cleanup(): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            "DELETE pm FROM payment_matches pm
               JOIN purchase_invoices pi ON pi.id = pm.purchase_invoice_id
              WHERE pi.supplier_id = ? AND pi.varsymbol = ?"
        )->execute([$this->supplierId, self::VS]);
        $pdo->prepare(
            "DELETE je FROM journal_entries je
               JOIN bank_transactions bt ON je.source_type = 'bank' AND je.source_id = bt.id
               JOIN bank_statements bs ON bs.id = bt.statement_id
              WHERE bs.file_name LIKE ?"
        )->execute(['%' . self::FILE_MARKER . '%']);
        $pdo->prepare('DELETE FROM bank_statements WHERE file_name LIKE ?')->execute(['%' . self::FILE_MARKER . '%']);
        $pdo->prepare('DELETE FROM purchase_invoices WHERE supplier_id = ? AND varsymbol = ?')
            ->execute([$this->supplierId, self::VS]);
    }

    private function seedPurchase(float $amount): int
    {
        $d = '2099-06-15';
        $this->db->pdo()->prepare(
            "INSERT INTO purchase_invoices
                (supplier_id, vendor_id, varsymbol, vendor_invoice_number, document_kind,
                 issue_date, tax_date, due_date, received_at, currency_id, vendor_snapshot,
                 total_without_vat, total_with_vat, status, created_by)
             VALUES (?, ?, ?, 'FV-2099-8177', 'invoice', ?, ?, ?, ?, ?, '{}', ?, ?, 'received', ?)"
        )->execute([
            $this->supplierId, $this->vendorId, self::VS,
            $d, $d, $d, $d, $this->currencyId, $amount, $amount, $this->userId,
        ]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    private function seedTransaction(float $amount): int
    {
        $pdo = $this->db->pdo();
        $d = '2099-06-15';
        $marker = self::FILE_MARKER . '-' . bin2hex(random_bytes(4));
        $pdo->prepare(
            "INSERT INTO bank_statements
                (file_name, file_hash, account_number, bank_code, currency, statement_date)
             VALUES (?, ?, ?, ?, 'CZK', ?)"
        )->execute([$marker . '.gpc', hash('sha256', $marker), $this->account, $this->bankCode, $d]);
        $statementId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            "INSERT INTO bank_transactions
                (statement_id, posted_at, amount, currency, variable_symbol)
             VALUES (?, ?, ?, 'CZK', ?)"
        )->execute([$statementId, $d, -$amount, self::VS]);
        return (int) $pdo->lastInsertId();
    }
}
