<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Deletion;

use MyInvoice\Action\Bank\BankStatementAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use MyInvoice\Tests\Support\PayrollPaymentEvidenceTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * DELETE /api/bank-statements/{id} — kontrola cizích vazeb.
 *
 * Routa kontrolovala jen výpisy ze zdroje `email_notice`/`idoklad` (a to na
 * spárované faktury). GPC výpisy — tedy naprostá většina — neměly kontrolu
 * žádnou, takže první výpis použitý jako doklad o vyplacení mezd
 * (`payroll_payment_matches`, cizí klíč RESTRICT) skončil HTTP 500 se syrovou
 * hláškou databáze.
 */
#[Group('integration')]
final class BankStatementDeletionGuardTest extends TestCase
{
    use IsolatedSupplierTrait;
    use PayrollPaymentEvidenceTrait;

    private Connection $db;
    private BankStatementAction $action;
    private int $supplierId;
    private int $otherSupplierId;
    private int $userId;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        $container = Bootstrap::buildApp()->getContainer();
        $this->db = $container->get(Connection::class);
        if (!$this->db->hasTable('payroll_payment_matches')) {
            $this->markTestSkipped('Mzdové migrace neproběhly.');
        }
        $this->action = $container->get(BankStatementAction::class);

        $pdo = $this->db->pdo();
        $sourceSupplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($sourceSupplierId === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí výchozí firma nebo uživatel.');
        }

        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $this->otherSupplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            if ($this->db->pdo()->inTransaction()) {
                $this->db->pdo()->rollBack();
            }
            $this->db->close();
        }
    }

    public function testStatementWithoutForeignLinksIsDeleted(): void
    {
        $pdo = $this->db->pdo();
        $statementId = $this->seedBankStatement($pdo, $this->supplierId, 'plain');
        $this->seedBankTransaction($pdo, $statementId, 'plain');

        $response = $this->delete($statementId);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame(0, $this->rowCount('bank_statements', 'id', $statementId));
    }

    public function testStatementUsedAsPayrollPaymentEvidenceIsRefusedWithExplanation(): void
    {
        $pdo = $this->db->pdo();
        $statementId = $this->seedBankStatement($pdo, $this->supplierId, 'payroll');
        $transactionId = $this->seedBankTransaction($pdo, $statementId, 'payroll');
        $allocationId = $this->seedAllocation($pdo, $this->supplierId, 'payroll', 'bank');
        $this->seedBankPaymentMatch($pdo, $this->supplierId, $allocationId, $statementId, $transactionId, 'payroll');

        $response = $this->delete($statementId);

        self::assertSame(409, $response->getStatusCode(), (string) $response->getBody());
        $error = $this->json($response)['error'];
        self::assertSame('has_dependencies', $error['code']);
        // Hláška musí JMENOVAT, co brání, a naznačit, co s tím — ne vypsat tabulku.
        self::assertStringContainsString('mezd', $error['message']);
        self::assertStringContainsString('Mzdy → Platby', $error['message']);
        self::assertStringNotContainsStringIgnoringCase('foreign key', $error['message']);
        self::assertStringNotContainsStringIgnoringCase('payroll_payment_matches', $error['message']);
        self::assertSame(1, (int) $error['blocked_by']['payroll_payment_evidence']);
        self::assertSame(1, $this->rowCount('bank_statements', 'id', $statementId));
    }

    public function testForeignTenantCannotDeleteAndSeesNotFound(): void
    {
        $pdo = $this->db->pdo();
        $statementId = $this->seedBankStatement($pdo, $this->supplierId, 'foreign');
        $this->seedBankTransaction($pdo, $statementId, 'foreign');

        $response = $this->action->delete(
            $this->request($statementId, $this->otherSupplierId),
            new Response(),
            ['id' => (string) $statementId],
        );

        self::assertSame(404, $response->getStatusCode());
        self::assertSame(1, $this->rowCount('bank_statements', 'id', $statementId));
    }

    /**
     * Cizí tenant nesmí z odpovědi vyčíst ani to, že cizí výpis je na něco
     * navázaný — 409 by prozradilo, že id existuje.
     */
    public function testForeignTenantGetsNotFoundEvenWhenStatementIsBlocked(): void
    {
        $pdo = $this->db->pdo();
        $statementId = $this->seedBankStatement($pdo, $this->supplierId, 'foreign-blocked');
        $transactionId = $this->seedBankTransaction($pdo, $statementId, 'foreign-blocked');
        $allocationId = $this->seedAllocation($pdo, $this->supplierId, 'foreign-blocked', 'bank');
        $this->seedBankPaymentMatch(
            $pdo, $this->supplierId, $allocationId, $statementId, $transactionId, 'foreign-blocked'
        );

        $response = $this->action->delete(
            $this->request($statementId, $this->otherSupplierId),
            new Response(),
            ['id' => (string) $statementId],
        );

        self::assertSame(404, $response->getStatusCode());
        self::assertSame(1, $this->rowCount('bank_statements', 'id', $statementId));
    }

    // ── Regrese: zdroj, který kontrolu měl už dřív, se chová jako dosud ───────

    public function testEmailNoticeWithMatchedTransactionIsStillRefused(): void
    {
        $pdo = $this->db->pdo();
        $statementId = $this->seedBankStatement($pdo, $this->supplierId, 'notice-matched', 'email_notice');
        $transactionId = $this->seedBankTransaction($pdo, $statementId, 'notice-matched');
        $pdo->prepare("UPDATE bank_transactions SET match_status = 'manual' WHERE id = ?")
            ->execute([$transactionId]);

        $response = $this->delete($statementId);

        self::assertSame(409, $response->getStatusCode());
        self::assertSame('has_matches', $this->json($response)['error']['code']);
        self::assertSame(1, $this->rowCount('bank_statements', 'id', $statementId));
    }

    public function testEmailNoticeWithoutMatchesIsStillDeleted(): void
    {
        $pdo = $this->db->pdo();
        $statementId = $this->seedBankStatement($pdo, $this->supplierId, 'notice-clean', 'email_notice');
        $this->seedBankTransaction($pdo, $statementId, 'notice-clean');

        $response = $this->delete($statementId);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame(0, $this->rowCount('bank_statements', 'id', $statementId));
    }

    // ── Zdrojový výpis měsíční evidence API ─────────────────────────────────

    /**
     * Duplicitní pohyb z opakovaného stažení leží ve zdrojovém výpisu, který
     * zobrazuje měsíční výpis API. Smazání ho odpojí od měsíce, smaže jen jeho
     * vlastní pohyby a měsíc přepočte; ostatní zdroje měsíce zůstanou.
     */
    public function testApiEvidenceStatementIsDetachedFromMonthAndDeleted(): void
    {
        $pdo = $this->db->pdo();
        [$monthId, $keptId, $keptTx, $duplicateId, $duplicateTx] = $this->seedApiMonth($pdo, 'evidence');

        $response = $this->delete($duplicateId);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame(0, $this->rowCount('bank_statements', 'id', $duplicateId));
        self::assertSame(0, $this->rowCount('bank_transactions', 'id', $duplicateTx));
        self::assertSame(1, $this->rowCount('bank_transactions', 'id', $keptTx));
        self::assertSame(1, $this->rowCount('bank_api_evidence_months', 'evidence_statement_id', $keptId));
        self::assertSame(1, $this->rowCount('bank_statements', 'id', $monthId));
        $month = $pdo->query('SELECT transaction_count, debit_total FROM bank_statements WHERE id = ' . $monthId)->fetch(PDO::FETCH_ASSOC);
        self::assertSame(1, (int) $month['transaction_count']);
        self::assertEqualsWithDelta(1000.0, (float) $month['debit_total'], 0.001);
    }

    /** Pohyb doložený i jiným importem nesmí zmizet; odpojení od měsíce se vrátí. */
    public function testApiEvidenceStatementBackedByAnotherImportStaysLinked(): void
    {
        $pdo = $this->db->pdo();
        [, , , $duplicateId, $duplicateTx] = $this->seedApiMonth($pdo, 'evidence-alias');
        $otherId = $this->seedBankStatement($pdo, $this->supplierId, 'evidence-alias-other');
        $this->linkImport($pdo, $otherId, $duplicateTx, $duplicateId, 'evidence-alias-other');

        $response = $this->delete($duplicateId);

        self::assertSame(409, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('has_dependencies', $this->json($response)['error']['code']);
        self::assertSame(1, $this->rowCount('bank_api_evidence_months', 'evidence_statement_id', $duplicateId));
        self::assertSame(1, $this->rowCount('bank_transactions', 'id', $duplicateTx));
    }

    // ── Pomocné ──────────────────────────────────────────────────────────────

    /** @return array{int,int,int,int,int} měsíc, ponechaný výpis a pohyb, duplicitní výpis a pohyb */
    private function seedApiMonth(PDO $pdo, string $seed): array
    {
        $monthId = $this->seedBankStatement($pdo, $this->supplierId, "{$seed}-month", 'bank_api');
        $pdo->prepare("INSERT INTO bank_api_months (supplier_id, account_key, currency, month_start, statement_id) VALUES (?, ?, 'CZK', '2099-01-01', ?)")
            ->execute([$this->supplierId, '0100:' . str_pad('1000000005', 16, '0', STR_PAD_LEFT), $monthId]);
        $result = [$monthId];
        foreach (['kept', 'duplicate'] as $part) {
            $statementId = $this->seedBankStatement($pdo, $this->supplierId, "{$seed}-{$part}", 'bank_api');
            $transactionId = $this->seedBankTransaction($pdo, $statementId, "{$seed}-{$part}");
            $pdo->prepare('INSERT INTO bank_api_evidence_months (evidence_statement_id, monthly_statement_id, supplier_id) VALUES (?, ?, ?)')
                ->execute([$statementId, $monthId, $this->supplierId]);
            $this->linkImport($pdo, $monthId, $transactionId, $statementId, "{$seed}-{$part}");
            array_push($result, $statementId, $transactionId);
        }
        return $result;
    }

    private function linkImport(PDO $pdo, int $statementId, int $transactionId, int $originalId, string $seed): void
    {
        $pdo->prepare('INSERT INTO bank_transaction_imports (statement_id, bank_transaction_id, import_fingerprint, supplier_id, original_statement_id) VALUES (?, ?, ?, ?, ?)')
            ->execute([$statementId, $transactionId, hash('sha256', "fkguard-link-{$seed}"), $this->supplierId, $originalId]);
    }

    private function delete(int $statementId): ResponseInterface
    {
        return $this->action->delete(
            $this->request($statementId),
            new Response(),
            ['id' => (string) $statementId],
        );
    }

    private function request(int $statementId, ?int $supplierId = null): ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest('DELETE', "/api/bank-statements/{$statementId}")
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $supplierId ?? $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin'])
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session');
    }

    /** @return array<string,mixed> */
    private function json(ResponseInterface $response): array
    {
        $response->getBody()->rewind();
        $decoded = json_decode((string) $response->getBody(), true);
        self::assertIsArray($decoded);

        return $decoded;
    }

    private function rowCount(string $table, string $column, int $value): int
    {
        $stmt = $this->db->pdo()->prepare("SELECT COUNT(*) FROM {$table} WHERE {$column} = ?");
        $stmt->execute([$value]);

        return (int) $stmt->fetchColumn();
    }
}
