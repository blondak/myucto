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
 * Jedna odchozí platba uhrazuje více přijatých faktur. Test používá dva různé
 * dodavatele, protože marketplace může jednu objednávku rozdělit do více dokladů.
 */
#[Group('integration')]
final class PurchaseSplitPaymentTest extends TestCase
{
    private Connection $db;
    private BankStatementAction $action;
    private int $supplierId;
    private int $userId;
    private int $countryId;
    private int $eurId;
    private string $account;
    private ?string $bankCode;
    private int $vendorA;
    private int $vendorB;
    private int $purchaseA;
    private int $purchaseB;
    private int $statementId;
    private int $transactionId;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje - test vyžaduje DB.');
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
            "SELECT supplier_id, account_number, bank_code FROM currencies
              WHERE code = 'CZK' AND account_number IS NOT NULL AND account_number <> ''
              ORDER BY id LIMIT 1"
        )->fetch(PDO::FETCH_ASSOC);
        if (!$account) {
            $this->markTestSkipped('Chybí CZK měna s účtem.');
        }
        $this->supplierId = (int) $account['supplier_id'];
        $this->account = (string) $account['account_number'];
        $this->bankCode = $account['bank_code'] !== null ? (string) $account['bank_code'] : null;
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->countryId = (int) ($pdo->query('SELECT id FROM countries ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($this->userId === 0 || $this->countryId === 0) {
            $this->markTestSkipped('Chybí user/country pro integrační test.');
        }

        $pdo->beginTransaction();
        $this->eurId = $this->currency('EUR');
        $this->vendorA = $this->vendor('__purchase_split_vendor_a__');
        $this->vendorB = $this->vendor('__purchase_split_vendor_b__');
        $this->purchaseA = $this->purchase('PS-EUR-A', $this->vendorA, 1000.00);
        $this->purchaseB = $this->purchase('PS-EUR-B', $this->vendorB, 500.00);
        $this->statementId = $this->statement('EUR');
        $this->transactionId = $this->transaction($this->statementId, -1500.00, 'EUR');
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $pdo = $this->db->pdo();
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $this->db->close();
        }
    }

    public function testEurSuggestionsAndManualMatchSupportDifferentVendors(): void
    {
        $suggestions = $this->suggestions([]);
        self::assertTrue($this->containsPair($suggestions), 'EUR odchozí pohyb musí nabídnout součet dvou přijatých faktur.');
        $pair = $this->pairSuggestion($suggestions);
        self::assertSame('purchase_invoice', $pair['document_type'] ?? null);
        self::assertNull($pair['client_id'] ?? null, 'Různí dodavatelé nesmí být vydáváni za jednoho klienta.');
        self::assertNotEmpty($pair['invoices'][0]['vendor_name'] ?? null);
        self::assertNotEmpty($pair['invoices'][1]['vendor_name'] ?? null);

        $result = $this->match([$this->purchaseA, $this->purchaseB]);
        self::assertSame(200, $result['status'], json_encode($result['body']));
        self::assertTrue($result['body']['split'] ?? false);
        self::assertEqualsCanonicalizing(
            [$this->purchaseA, $this->purchaseB],
            array_map('intval', $result['body']['purchase_invoice_ids'] ?? []),
        );
        self::assertSame(2, $this->matchCount());
        self::assertSame(['paid', 'paid'], $this->purchaseStatuses());

        $detail = $this->detail();
        self::assertCount(2, $detail['transactions'][0]['matched_purchase_invoices'] ?? []);
    }

    public function testPurchaseAnchorFindsRestWithoutVendorRestriction(): void
    {
        $suggestions = $this->suggestions(['purchase_invoice_id' => $this->purchaseA]);
        self::assertTrue($this->containsPair($suggestions));
        foreach ($suggestions as $suggestion) {
            self::assertContains($this->purchaseA, self::ids($suggestion));
        }
    }

    public function testPriorPartialPaymentUsesOnlyRemainingBalance(): void
    {
        $priorStatement = $this->statement('EUR');
        $priorTx = $this->transaction($priorStatement, -200.00, 'EUR');
        $this->db->pdo()->prepare(
            "INSERT INTO payment_matches
                (supplier_id, bank_transaction_id, purchase_invoice_id, amount, match_type)
             VALUES (?, ?, ?, 200.00, 'manual')"
        )->execute([$this->supplierId, $priorTx, $this->purchaseA]);
        $this->db->pdo()->prepare('UPDATE bank_transactions SET amount = -1300.00 WHERE id = ?')
            ->execute([$this->transactionId]);

        self::assertTrue($this->containsPair($this->suggestions([])), 'Návrh musí použít zbytek 800 + 500.');
        $result = $this->match([$this->purchaseA, $this->purchaseB]);
        self::assertSame(200, $result['status'], json_encode($result['body']));

        $alloc = $this->db->pdo()->prepare(
            'SELECT purchase_invoice_id, amount FROM payment_matches WHERE bank_transaction_id = ? ORDER BY purchase_invoice_id'
        );
        $alloc->execute([$this->transactionId]);
        $rows = $alloc->fetchAll(PDO::FETCH_ASSOC);
        self::assertSame(800.0, (float) $rows[0]['amount']);
        self::assertSame(500.0, (float) $rows[1]['amount']);
        self::assertSame(['paid', 'paid'], $this->purchaseStatuses());
    }

    public function testConfirmationTakesOverOwnAutoPartialSubset(): void
    {
        $this->db->pdo()->prepare(
            "INSERT INTO payment_matches
                (supplier_id, bank_transaction_id, purchase_invoice_id, amount, match_type, match_confidence)
             VALUES (?, ?, ?, 1000.00, 'auto', 0.80)"
        )->execute([$this->supplierId, $this->transactionId, $this->purchaseA]);
        $this->db->pdo()->prepare("UPDATE bank_transactions SET match_status = 'auto_partial' WHERE id = ?")
            ->execute([$this->transactionId]);

        self::assertTrue($this->containsPair($this->suggestions([])));
        $result = $this->match([$this->purchaseA, $this->purchaseB]);
        self::assertSame(200, $result['status'], json_encode($result['body']));
        self::assertSame(2, $this->matchCount());
        $types = $this->db->pdo()->query(
            "SELECT DISTINCT match_type FROM payment_matches WHERE bank_transaction_id = {$this->transactionId}"
        )->fetchAll(PDO::FETCH_COLUMN);
        self::assertSame(['manual'], $types);
        self::assertSame(['paid', 'paid'], $this->purchaseStatuses());
    }

    public function testSameCurrencyDifferenceWithinToleranceSettlesFullBalances(): void
    {
        $this->db->pdo()->prepare('UPDATE bank_transactions SET amount = -1499.50 WHERE id = ?')
            ->execute([$this->transactionId]);

        self::assertTrue($this->containsPair($this->suggestions([])));
        $result = $this->match([$this->purchaseA, $this->purchaseB]);
        self::assertSame(200, $result['status'], json_encode($result['body']));
        $sum = (float) $this->db->pdo()->query(
            "SELECT SUM(amount) FROM payment_matches WHERE bank_transaction_id = {$this->transactionId}"
        )->fetchColumn();
        self::assertSame(1500.0, $sum, 'Stejná měna eviduje plné zbytky dokladů; rozdíl řeší 548/648.');
        self::assertSame(['paid', 'paid'], $this->purchaseStatuses());
    }

    public function testCzkCardPaymentAllocatesActualAmountAcrossEurInvoices(): void
    {
        $this->db->pdo()->prepare(
            'UPDATE purchase_invoices SET exchange_rate = 25.00, exchange_rate_date = ? WHERE id IN (?, ?)'
        )->execute(['2099-06-10', $this->purchaseA, $this->purchaseB]);
        $this->db->pdo()->prepare("UPDATE bank_statements SET currency = 'CZK' WHERE id = ?")
            ->execute([$this->statementId]);
        $this->db->pdo()->prepare("UPDATE bank_transactions SET amount = -37000.00, currency = 'CZK' WHERE id = ?")
            ->execute([$this->transactionId]);

        self::assertTrue($this->containsPair($this->suggestions([])), 'CZK karta musí nabídnout EUR doklady podle jejich kurzů.');
        $result = $this->match([$this->purchaseA, $this->purchaseB]);
        self::assertSame(200, $result['status'], json_encode($result['body']));
        $sum = (float) $this->db->pdo()->query(
            "SELECT SUM(amount) FROM payment_matches WHERE bank_transaction_id = {$this->transactionId}"
        )->fetchColumn();
        self::assertSame(37000.0, $sum, 'Křížová měna musí rozdělit skutečnou částku pohybu beze zbytku.');
        self::assertSame(['paid', 'paid'], $this->purchaseStatuses());
    }

    public function testCzkPaymentRejectsPriorForeignCurrencyPartialAtomically(): void
    {
        $this->db->pdo()->prepare(
            'UPDATE purchase_invoices SET exchange_rate = 25.00, exchange_rate_date = ? WHERE id IN (?, ?)'
        )->execute(['2099-06-10', $this->purchaseA, $this->purchaseB]);
        $this->db->pdo()->prepare("UPDATE bank_statements SET currency = 'CZK' WHERE id = ?")
            ->execute([$this->statementId]);
        $this->db->pdo()->prepare("UPDATE bank_transactions SET amount = -32500.00, currency = 'CZK' WHERE id = ?")
            ->execute([$this->transactionId]);
        $priorStatement = $this->statement('EUR');
        $priorTx = $this->transaction($priorStatement, -200.00, 'EUR');
        $this->db->pdo()->prepare(
            "INSERT INTO payment_matches
                (supplier_id, bank_transaction_id, purchase_invoice_id, amount, match_type)
             VALUES (?, ?, ?, 200.00, 'manual')"
        )->execute([$this->supplierId, $priorTx, $this->purchaseA]);

        self::assertFalse($this->containsPair($this->suggestions([])));
        $result = $this->match([$this->purchaseA, $this->purchaseB]);
        self::assertSame(409, $result['status'], json_encode($result['body']));
        self::assertSame('cross_currency_partial_unsupported', $result['body']['error']['code'] ?? null);
        self::assertSame(0, $this->matchCount());
        self::assertSame(['received', 'received'], $this->purchaseStatuses());
    }

    public function testUnmatchReleasesEveryPurchaseAllocation(): void
    {
        self::assertSame(200, $this->match([$this->purchaseA, $this->purchaseB])['status']);
        $result = $this->unmatch();

        self::assertSame(200, $result['status'], json_encode($result['body']));
        self::assertSame(0, $this->matchCount());
        self::assertSame(['received', 'received'], $this->purchaseStatuses());
    }

    public function testPaidCashDocumentIsNeverOfferedOrMatchedAgain(): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            "INSERT INTO cash_registers (supplier_id, name, currency_code, account_code)
             VALUES (?, ?, 'EUR', ?)"
        )->execute([
            $this->supplierId,
            '__purchase_split_cash__' . bin2hex(random_bytes(4)),
            '211' . random_int(100000, 999999),
        ]);
        $registerId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            "INSERT INTO cash_documents
                (supplier_id, register_id, doc_type, purpose, doc_number, issue_date, description,
                 vat_mode, total_amount, purchase_invoice_id, status)
             VALUES (?, ?, 'out', 'purchase_payment', ?, '2099-06-10', 'Synthetic cash settlement',
                     'none', 1000.00, ?, 'posted')"
        )->execute([$this->supplierId, $registerId, 'VPD-' . bin2hex(random_bytes(4)), $this->purchaseA]);
        $pdo->prepare("UPDATE purchase_invoices SET status = 'paid', paid_at = '2099-06-10' WHERE id = ?")
            ->execute([$this->purchaseA]);

        self::assertFalse($this->containsPair($this->suggestions([])));
        $result = $this->match([$this->purchaseA, $this->purchaseB]);
        self::assertSame(409, $result['status'], json_encode($result['body']));
        self::assertSame('cannot_reconcile', $result['body']['error']['code'] ?? null);
        self::assertSame(0, $this->matchCount());
        self::assertSame('received', $this->purchaseStatus($this->purchaseB));
    }

    public function testForeignTenantDocumentRejectsWholeMatchAtomically(): void
    {
        $otherSupplier = (int) ($this->db->pdo()->query(
            "SELECT id FROM supplier WHERE id <> {$this->supplierId} ORDER BY id LIMIT 1"
        )->fetchColumn() ?: 0);
        if ($otherSupplier === 0) {
            $otherSupplier = $this->otherSupplier();
        }
        $this->db->pdo()->prepare('UPDATE purchase_invoices SET supplier_id = ? WHERE id = ?')
            ->execute([$otherSupplier, $this->purchaseB]);

        $result = $this->match([$this->purchaseA, $this->purchaseB]);
        self::assertSame(404, $result['status'], json_encode($result['body']));
        self::assertSame(0, $this->matchCount(), 'Při cizím dokladu nesmí vzniknout ani první alokace.');
        self::assertSame('received', $this->purchaseStatus($this->purchaseA));
    }

    private function currency(string $code): int
    {
        $pdo = $this->db->pdo();
        $id = (int) ($pdo->query(
            "SELECT id FROM currencies WHERE supplier_id = {$this->supplierId} AND code = " . $pdo->quote($code) . ' ORDER BY id LIMIT 1'
        )->fetchColumn() ?: 0);
        if ($id > 0) {
            return $id;
        }
        $pdo->prepare(
            'INSERT INTO currencies (supplier_id, code, label, symbol, name_cs, name_en, decimals, is_active, is_default)
             VALUES (?, ?, ?, ?, ?, ?, 2, 1, 0)'
        )->execute([$this->supplierId, $code, $code, $code, $code, $code]);
        return (int) $pdo->lastInsertId();
    }

    private function vendor(string $name): int
    {
        $this->db->pdo()->prepare(
            'INSERT INTO clients
                (supplier_id, company_name, street, city, zip, country_id, main_email,
                 language, currency_default_id, is_customer, is_vendor)
             VALUES (?, ?, "Test 1", "Testov", "10000", ?, "", "cs", ?, 0, 1)'
        )->execute([$this->supplierId, $name, $this->countryId, $this->eurId]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    private function purchase(string $number, int $vendorId, float $amount): int
    {
        $date = '2099-06-10';
        $this->db->pdo()->prepare(
            'INSERT INTO purchase_invoices
                (supplier_id, vendor_id, vendor_invoice_number, vendor_snapshot, document_kind, vat_deduction,
                 issue_date, tax_date, due_date, received_at, currency_id, exchange_rate, exchange_rate_date,
                 reverse_charge, is_fixed_asset, total_without_vat, total_vat, total_with_vat, status, created_by)
             VALUES (?, ?, ?, "{}", "invoice", "full", ?, ?, ?, ?, ?, 1, ?, 0, 0, ?, 0, ?, "received", ?)'
        )->execute([
            $this->supplierId, $vendorId, $number, $date, $date, $date, $date,
            $this->eurId, $date, $amount, $amount, $this->userId,
        ]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    private function statement(string $currency): int
    {
        $this->db->pdo()->prepare(
            'INSERT INTO bank_statements
                (supplier_id, file_name, file_hash, account_number, bank_code, currency, statement_date, imported_by)
             VALUES (?, ?, ?, ?, ?, ?, "2099-06-15", ?)'
        )->execute([
            $this->supplierId,
            '__purchase_split__' . bin2hex(random_bytes(6)) . '.gpc',
            hash('sha256', bin2hex(random_bytes(16))),
            $this->account,
            $this->bankCode,
            $currency,
            $this->userId,
        ]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    private function transaction(int $statementId, float $amount, string $currency): int
    {
        $this->db->pdo()->prepare(
            'INSERT INTO bank_transactions
                (statement_id, source, posted_at, amount, currency, variable_symbol, counterparty_name, match_status)
             VALUES (?, "statement", "2099-06-15", ?, ?, "0", "Marketplace", "unmatched")'
        )->execute([$statementId, $amount, $currency]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    /** @return list<array<string,mixed>> */
    private function suggestions(array $query): array
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/bank-transactions/' . $this->transactionId . '/split-suggestions')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin'])
            ->withQueryParams($query);
        $response = $this->action->splitSuggestions($request, new Psr7Response(), ['id' => (string) $this->transactionId]);
        $response->getBody()->rewind();
        $body = json_decode((string) $response->getBody(), true) ?: [];
        return $body['suggestions'] ?? [];
    }

    /** @return array{status:int,body:array<string,mixed>} */
    private function match(array $ids): array
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/bank-transactions/' . $this->transactionId . '/match')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin'])
            ->withParsedBody(['purchase_invoice_ids' => $ids]);
        $response = $this->action->manualMatch($request, new Psr7Response(), ['id' => (string) $this->transactionId]);
        $response->getBody()->rewind();
        $body = json_decode((string) $response->getBody(), true) ?: [];
        return ['status' => $response->getStatusCode(), 'body' => is_array($body) ? $body : []];
    }

    /** @return array<string,mixed> */
    private function detail(): array
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/bank-statements/' . $this->statementId)
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin']);
        $response = $this->action->detail($request, new Psr7Response(), ['id' => (string) $this->statementId]);
        $response->getBody()->rewind();
        $body = json_decode((string) $response->getBody(), true) ?: [];
        return is_array($body) ? $body : [];
    }

    /** @param list<array<string,mixed>> $suggestions */
    private function containsPair(array $suggestions): bool
    {
        return $this->pairSuggestion($suggestions) !== [];
    }

    /** @param list<array<string,mixed>> $suggestions @return array<string,mixed> */
    private function pairSuggestion(array $suggestions): array
    {
        $expected = [$this->purchaseA, $this->purchaseB];
        sort($expected);
        foreach ($suggestions as $suggestion) {
            $ids = self::ids($suggestion);
            sort($ids);
            if ($ids === $expected) {
                return $suggestion;
            }
        }
        return [];
    }

    /** @param array<string,mixed> $suggestion @return list<int> */
    private static function ids(array $suggestion): array
    {
        return array_map(static fn (array $invoice): int => (int) $invoice['id'], $suggestion['invoices'] ?? []);
    }

    private function matchCount(): int
    {
        return (int) $this->db->pdo()->query(
            "SELECT COUNT(*) FROM payment_matches WHERE bank_transaction_id = {$this->transactionId}"
        )->fetchColumn();
    }

    /** @return list<string> */
    private function purchaseStatuses(): array
    {
        return [$this->purchaseStatus($this->purchaseA), $this->purchaseStatus($this->purchaseB)];
    }

    private function purchaseStatus(int $id): string
    {
        return (string) $this->db->pdo()->query("SELECT status FROM purchase_invoices WHERE id = $id")->fetchColumn();
    }

    /** @return array{status:int,body:array<string,mixed>} */
    private function unmatch(): array
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/bank-transactions/' . $this->transactionId . '/unmatch')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin']);
        $response = $this->action->unmatch($request, new Psr7Response(), ['id' => (string) $this->transactionId]);
        $response->getBody()->rewind();
        $body = json_decode((string) $response->getBody(), true) ?: [];
        return ['status' => $response->getStatusCode(), 'body' => is_array($body) ? $body : []];
    }

    private function otherSupplier(): int
    {
        $base = $this->db->pdo()->query(
            "SELECT default_currency_id, default_vat_rate_id FROM supplier WHERE id = {$this->supplierId}"
        )->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($base);
        $this->db->pdo()->prepare(
            'INSERT INTO supplier
                (company_name, street, city, zip, country_id, email, default_currency_id, default_vat_rate_id)
             VALUES (?, "Test 2", "Testov", "10000", ?, "", ?, ?)'
        )->execute([
            '__purchase_split_other_tenant__',
            $this->countryId,
            (int) $base['default_currency_id'],
            (int) $base['default_vat_rate_id'],
        ]);
        return (int) $this->db->pdo()->lastInsertId();
    }
}
