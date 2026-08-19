<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Action\Accounting\Closing\DocumentSeriesAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * /api/accounting/document-series vs. účetní režim firmy.
 *
 * Celá routa byla zavřená na `requireDoubleEntry`, jenže pokladní doklady se
 * z týchž řad číslují i v daňové evidenci: DE firma si vlastní řadu pokladny
 * zapnula (`CashRegisterService::update` režim nekontroluje), ale prefix už
 * nikdy nespravila — a hláška `series_prefix_unavailable` ji přitom posílala
 * „nastavte jej ručně v Nástrojích → Číselné řady".
 */
#[Group('integration')]
final class DocumentSeriesModeAccessTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private DocumentSeriesAction $action;
    private int $deSupplierId;
    private int $userId;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        $container = Bootstrap::buildApp()->getContainer();
        $this->db = $container->get(Connection::class);
        if (!$this->db->hasTable('accounting_document_series')) {
            $this->markTestSkipped('Migrace číselných řad neproběhly.');
        }
        $this->action = $container->get(DocumentSeriesAction::class);

        $pdo = $this->db->pdo();
        $sourceSupplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($sourceSupplierId === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí výchozí firma nebo uživatel.');
        }

        $pdo->beginTransaction();
        $this->deSupplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $pdo->prepare("UPDATE supplier SET accounting_mode = 'tax_evidence' WHERE id = ?")
            ->execute([$this->deSupplierId]);
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

    public function testTaxEvidenceCanSetCashSeriesPrefix(): void
    {
        $year = (int) date('Y');
        $response = $this->action->update(
            $this->request()->withParsedBody(['prefix' => 'PPD2']),
            new Response(),
            ['code' => 'cash_in', 'year' => (string) $year],
        );

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $stmt = $this->db->pdo()->prepare(
            'SELECT prefix FROM accounting_document_series
              WHERE supplier_id = ? AND series_code = ? AND fiscal_year = ?'
        );
        $stmt->execute([$this->deSupplierId, 'cash_in', $year]);
        self::assertSame('PPD2', (string) $stmt->fetchColumn());
    }

    public function testTaxEvidenceStillCannotTouchJournalSeries(): void
    {
        $response = $this->action->update(
            $this->request()->withParsedBody(['prefix' => 'ID2']),
            new Response(),
            ['code' => 'manual', 'year' => (string) date('Y')],
        );

        self::assertSame(403, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('wrong_accounting_mode', $this->json($response)['error']['code']);
    }

    /** L-10: bez FK by `register_id` na cizí/neexistující pokladnu založil osiřelý řádek řady. */
    public function testUnknownRegisterIdIsRejected(): void
    {
        $response = $this->action->update(
            $this->request()->withParsedBody(['prefix' => 'PPD9', 'register_id' => 999999]),
            new Response(),
            ['code' => 'cash_in', 'year' => (string) date('Y')],
        );

        self::assertSame(422, $response->getStatusCode(), (string) $response->getBody());
        $stmt = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM accounting_document_series WHERE supplier_id = ? AND register_id = ?'
        );
        $stmt->execute([$this->deSupplierId, 999999]);
        self::assertSame(0, (int) $stmt->fetchColumn());
    }

    public function testListInTaxEvidenceHidesJournalSeries(): void
    {
        // Řádek deníkové řady může existovat z doby, kdy firma jela podvojně.
        $this->db->pdo()->prepare(
            'INSERT INTO accounting_document_series (supplier_id, series_code, fiscal_year, register_id, prefix, next_number)
             VALUES (?, ?, ?, 0, ?, 1)'
        )->execute([$this->deSupplierId, 'manual', (int) date('Y'), 'ID']);

        $response = $this->action->list($this->request(), new Response());

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $codes = array_column($this->json($response), 'series_code');
        self::assertNotContains('manual', $codes);
    }

    private function request(): ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest('PUT', '/api/accounting/document-series')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->deSupplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin'])
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session');
    }

    /** @return array<mixed> */
    private function json(ResponseInterface $response): array
    {
        $response->getBody()->rewind();
        $decoded = json_decode((string) $response->getBody(), true);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
