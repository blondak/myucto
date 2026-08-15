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

    // ── Pomocné ──────────────────────────────────────────────────────────────

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
