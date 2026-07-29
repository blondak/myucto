<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration;

use MyInvoice\Action\Document\DocumentJobsAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\ImportJobRepository;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

/**
 * HIGH-1 regrese (Epic F7, §4.2): background joby sekce Dokumenty (zejm. ZIP export)
 * jsou vázané na svého tvůrce (import_jobs.created_by). Export může obsahovat cizí
 * user-scoped doklady (admin balí složku), takže bez vlastnické brány by libovolný
 * non-admin téhož dodavatele viděl/stáhl cizí osobní dokumenty přes list/status/download.
 *
 * Volá DocumentJobsAction přímo (z DI) s Requestem nesoucím ATTR_USER / ATTR_CURRENT_ID.
 * Soft-skip bez cfg.php.
 */
#[Group('integration')]
final class DocumentJobsAuthzTest extends TestCase
{
    private Connection $db;
    private DocumentJobsAction $action;
    private ImportJobRepository $jobs;
    private int $supplierId = 0;
    private int $ownerId = 0;
    /** @var int[] */
    private array $created = [];

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 3);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $c = Bootstrap::buildApp()->getContainer();
            $this->db     = $c->get(Connection::class);
            $this->action = $c->get(DocumentJobsAction::class);
            $this->jobs   = $c->get(ImportJobRepository::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }
        $pdo = $this->db->pdo();
        $this->supplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->ownerId    = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($this->supplierId === 0 || $this->ownerId === 0) {
            $this->markTestSkipped('Chybí supplier/user.');
        }
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) return;
        foreach ($this->created as $id) {
            $this->db->pdo()->prepare('DELETE FROM import_jobs WHERE id = ?')->execute([$id]);
        }
        $this->db->close();
    }

    /** Vytvoří export job vlastněný $ownerId. */
    private function makeExportJob(): int
    {
        $id = $this->jobs->create($this->supplierId, 'document_zip_export',
            ['ids' => [1], 'folder_ids' => [], 'viewer_is_admin' => true], $this->ownerId);
        $this->created[] = $id;
        return $id;
    }

    /** Request s identitou (role + id) v attributech, jako to dělá middleware stack. */
    private function req(string $method, string $role, int $userId): ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest($method, '/api/documents/jobs')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $userId, 'role' => $role]);
    }

    /** @return array<string,mixed> */
    private function body(\Psr\Http\Message\ResponseInterface $r): array
    {
        $decoded = json_decode((string) $r->getBody(), true);
        return is_array($decoded) ? $decoded : [];
    }

    public function testListFiltersJobsToCreatorOrAdmin(): void
    {
        $jobId = $this->makeExportJob();
        $otherId = $this->ownerId + 999999; // cizí non-admin

        $idsIn = function (ServerRequestInterface $req): array {
            $res = $this->action->list($req, new Psr7Response());
            $body = $this->body($res);
            return array_map(static fn($j) => (int) $j['id'], $body['jobs'] ?? []);
        };

        self::assertContains($jobId, $idsIn($this->req('GET', 'user', $this->ownerId)), 'tvůrce svůj job vidí');
        self::assertNotContains($jobId, $idsIn($this->req('GET', 'user', $otherId)), 'cizí non-admin job NEvidí');
        self::assertContains($jobId, $idsIn($this->req('GET', 'admin', $otherId)), 'admin vidí vše tenanta');
    }

    public function testStatusIsNotFoundForNonCreatorNonAdmin(): void
    {
        $jobId = $this->makeExportJob();
        $otherId = $this->ownerId + 999999;

        $owner = $this->action->status($this->req('GET', 'user', $this->ownerId), new Psr7Response(), ['id' => $jobId]);
        self::assertSame(200, $owner->getStatusCode(), 'tvůrce dostane status');

        $other = $this->action->status($this->req('GET', 'user', $otherId), new Psr7Response(), ['id' => $jobId]);
        self::assertSame(404, $other->getStatusCode(), 'cizí non-admin dostane 404 (not-found shape)');
        self::assertSame('not_found', $this->body($other)['error']['code'] ?? null);

        $admin = $this->action->status($this->req('GET', 'admin', $otherId), new Psr7Response(), ['id' => $jobId]);
        self::assertSame(200, $admin->getStatusCode(), 'admin dostane status');
    }

    public function testCancelAndDeleteRejectNonCreatorNonAdmin(): void
    {
        $jobId = $this->makeExportJob();
        $otherId = $this->ownerId + 999999;

        $cancel = $this->action->cancel($this->req('POST', 'user', $otherId), new Psr7Response(), ['id' => $jobId]);
        self::assertSame(404, $cancel->getStatusCode(), 'cizí non-admin nesmí cancel');

        $delete = $this->action->delete($this->req('DELETE', 'user', $otherId), new Psr7Response(), ['id' => $jobId]);
        self::assertSame(404, $delete->getStatusCode(), 'cizí non-admin nesmí delete');

        // Job musí pořád existovat (delete cizímu neprošel).
        self::assertNotNull($this->jobs->find($jobId, $this->supplierId), 'job nesmazán cizím uživatelem');
    }
}
