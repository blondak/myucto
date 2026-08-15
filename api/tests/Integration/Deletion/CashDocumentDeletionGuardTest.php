<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Deletion;

use MyInvoice\Action\Accounting\Cash\CashDocumentAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use MyInvoice\Tests\Support\PayrollPaymentEvidenceTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * DELETE /api/accounting/cash-documents/{id} — kontrola cizích vazeb.
 *
 * Větev `?force=1` mazala doklad i s účetními zápisy a nekontrolovala nic.
 * `payroll_payment_matches.cash_document_id` je RESTRICT, takže doklad, kterým
 * se mzda vyplatila v hotovosti, skončil syrovou FK chybou — a protože
 * `mapPostingError()` netypované výjimky přehazuje dál, viděl uživatel HTTP 500.
 */
#[Group('integration')]
final class CashDocumentDeletionGuardTest extends TestCase
{
    use IsolatedSupplierTrait;
    use PayrollPaymentEvidenceTrait;

    private Connection $db;
    private CashDocumentAction $action;
    private int $supplierId;
    private int $otherSupplierId;
    private int $userId;
    private int $registerId;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        $container = Bootstrap::buildApp()->getContainer();
        $this->db = $container->get(Connection::class);
        if (!$this->db->hasTable('payroll_payment_matches') || !$this->db->hasTable('cash_documents')) {
            $this->markTestSkipped('Mzdové nebo pokladní migrace neproběhly.');
        }
        $this->action = $container->get(CashDocumentAction::class);

        $pdo = $this->db->pdo();
        $sourceSupplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($sourceSupplierId === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí výchozí firma nebo uživatel.');
        }

        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $this->otherSupplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $pdo->prepare("UPDATE supplier SET accounting_mode = 'double_entry' WHERE id IN (?, ?)")
            ->execute([$this->supplierId, $this->otherSupplierId]);
        $this->registerId = $this->seedCashRegister($pdo, $this->supplierId, 'guard');
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

    public function testDraftWithoutForeignLinksIsDeleted(): void
    {
        $id = $this->seedCashDocument($this->db->pdo(), $this->supplierId, $this->registerId, 'draft', 'draft');

        $response = $this->delete($id);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame(0, $this->rowCount($id));
    }

    public function testPostedDocumentWithoutForeignLinksIsDeletedWithForce(): void
    {
        $id = $this->seedCashDocument($this->db->pdo(), $this->supplierId, $this->registerId, 'posted');

        $response = $this->delete($id, force: true);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame(0, $this->rowCount($id));
    }

    public function testDocumentUsedAsPayrollPaymentEvidenceIsRefusedWithExplanation(): void
    {
        $pdo = $this->db->pdo();
        $id = $this->seedCashDocument($pdo, $this->supplierId, $this->registerId, 'payroll');
        $allocationId = $this->seedAllocation($pdo, $this->supplierId, 'cash-payroll', 'cash');
        $this->seedCashPaymentMatch($pdo, $this->supplierId, $allocationId, $id, 'payroll');

        $response = $this->delete($id, force: true);

        self::assertSame(409, $response->getStatusCode(), (string) $response->getBody());
        $error = $this->json($response)['error'];
        self::assertSame('cash.error.has_dependencies', $error['code']);
        self::assertStringContainsString('mezd', $error['message']);
        self::assertStringContainsString('Mzdy → Platby', $error['message']);
        self::assertStringNotContainsStringIgnoringCase('foreign key', $error['message']);
        self::assertStringNotContainsStringIgnoringCase('payroll_payment_matches', $error['message']);
        self::assertSame(1, (int) $error['blocked_by']['payroll_payment_evidence']);
        self::assertSame(1, $this->rowCount($id));
    }

    /** Rozhodovat musí existence vazby, ne stav dokladu — draft větev se chová stejně. */
    public function testDraftBranchIsGuardedToo(): void
    {
        $pdo = $this->db->pdo();
        $id = $this->seedCashDocument($pdo, $this->supplierId, $this->registerId, 'draft-payroll');
        $allocationId = $this->seedAllocation($pdo, $this->supplierId, 'cash-draft', 'cash');
        $this->seedCashPaymentMatch($pdo, $this->supplierId, $allocationId, $id, 'draft-payroll');

        $response = $this->delete($id);

        self::assertSame(409, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('cash.error.has_dependencies', $this->json($response)['error']['code']);
        self::assertSame(1, $this->rowCount($id));
    }

    public function testForeignTenantCannotDelete(): void
    {
        $id = $this->seedCashDocument($this->db->pdo(), $this->supplierId, $this->registerId, 'foreign');

        $response = $this->action->delete(
            $this->request($id, true, $this->otherSupplierId),
            new Response(),
            ['id' => (string) $id],
        );

        self::assertSame(404, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame(1, $this->rowCount($id));
    }

    /** Cizí tenant nesmí z 409 vyčíst, že blokovaný doklad vůbec existuje. */
    public function testForeignTenantGetsNotFoundEvenWhenDocumentIsBlocked(): void
    {
        $pdo = $this->db->pdo();
        $id = $this->seedCashDocument($pdo, $this->supplierId, $this->registerId, 'foreign-blocked');
        $allocationId = $this->seedAllocation($pdo, $this->supplierId, 'cash-foreign', 'cash');
        $this->seedCashPaymentMatch($pdo, $this->supplierId, $allocationId, $id, 'foreign-blocked');

        $response = $this->action->delete(
            $this->request($id, true, $this->otherSupplierId),
            new Response(),
            ['id' => (string) $id],
        );

        self::assertSame(404, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame(1, $this->rowCount($id));
    }

    // ── Pomocné ──────────────────────────────────────────────────────────────

    private function delete(int $id, bool $force = false): ResponseInterface
    {
        return $this->action->delete(
            $this->request($id, $force),
            new Response(),
            ['id' => (string) $id],
        );
    }

    private function request(int $id, bool $force = false, ?int $supplierId = null): ServerRequestInterface
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('DELETE', "/api/accounting/cash-documents/{$id}")
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $supplierId ?? $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin'])
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session');

        return $force ? $request->withQueryParams(['force' => '1']) : $request;
    }

    /** @return array<string,mixed> */
    private function json(ResponseInterface $response): array
    {
        $response->getBody()->rewind();
        $decoded = json_decode((string) $response->getBody(), true);
        self::assertIsArray($decoded);

        return $decoded;
    }

    private function rowCount(int $id): int
    {
        $stmt = $this->db->pdo()->prepare('SELECT COUNT(*) FROM cash_documents WHERE id = ?');
        $stmt->execute([$id]);

        return (int) $stmt->fetchColumn();
    }
}
