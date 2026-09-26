<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Migration;

use MyInvoice\Action\Admin\Import\MoneyS3MigrationAction;
use MyInvoice\Action\Admin\Import\PohodaMigrationAction;
use MyInvoice\Action\Admin\Import\PremierMigrationAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\AbstractMigrationImportRepository;
use MyInvoice\Repository\MoneyS3ImportRepository;
use MyInvoice\Repository\PohodaImportRepository;
use MyInvoice\Repository\PremierImportRepository;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\EffectiveRole;
use MyInvoice\Security\RoutePermissionMap;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * Protokol zkoušky nanečisto jde smazat u všech převodů (Money S3, POHODA, PREMIER),
 * stejným právem jako spuštění převodu. Protokol ostrého převodu a běžící zkouška
 * zůstávají. Transakce s rollbackem v tearDown.
 */
#[Group('integration')]
final class MigrationRunDeleteTest extends TestCase
{
    private \Psr\Container\ContainerInterface $container;
    private Connection $db;
    private int $supplierId = 0;
    private int $userId = 0;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php missing');
        }
        try {
            $this->container = Bootstrap::buildContainer();
            $this->db = $this->container->get(Connection::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI unavailable: ' . $e->getMessage());
        }
        $pdo = $this->db->pdo();
        $this->supplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($this->supplierId === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí dodavatel nebo uživatel.');
        }
        $pdo->beginTransaction();
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
        }
    }

    /** @return iterable<string,array{string,class-string,class-string<AbstractMigrationImportRepository>}> */
    public static function sources(): iterable
    {
        yield 'money_s3' => ['money-s3', MoneyS3MigrationAction::class, MoneyS3ImportRepository::class];
        yield 'pohoda' => ['pohoda', PohodaMigrationAction::class, PohodaImportRepository::class];
        yield 'premier' => ['premier', PremierMigrationAction::class, PremierImportRepository::class];
    }

    /**
     * @param class-string $actionClass
     * @param class-string<AbstractMigrationImportRepository> $repoClass
     */
    #[DataProvider('sources')]
    public function testOnlyFinishedDryRunIsDeleted(string $slug, string $actionClass, string $repoClass): void
    {
        $action = $this->container->get($actionClass);
        $runs = $this->container->get($repoClass);
        $dry = $this->createRun($runs, 'dry_run', 'completed');
        $live = $this->createRun($runs, 'import', 'completed');
        $running = $this->createRun($runs, 'dry_run', 'running');

        $deleted = $this->delete($action, $dry);
        self::assertSame(200, $deleted->getStatusCode(), (string) $deleted->getBody());
        self::assertNull($runs->findRun($dry, $this->supplierId));

        foreach ([$live, $running] as $kept) {
            $refused = $this->delete($action, $kept);
            self::assertSame(409, $refused->getStatusCode());
            self::assertSame('run_not_deletable', json_decode((string) $refused->getBody(), true)['error']['code']);
            self::assertNotNull($runs->findRun($kept, $this->supplierId));
        }
        self::assertSame(404, $this->delete($action, $dry)->getStatusCode());
    }

    /** Účetní s právem k importům smaže zkoušku stejně, jako ji spustí (dřív jen superadmin u POHODY). */
    #[DataProvider('sources')]
    public function testDeleteNeedsImportWriteRight(string $slug, string $actionClass, string $repoClass): void
    {
        $permission = (new RoutePermissionMap())->match('DELETE', "/api/admin/imports/{$slug}/runs/12");
        self::assertNotNull($permission);
        self::assertSame(['utilities.import', AccessLevel::WRITE], [$permission->key, $permission->minimum]);
    }

    private function createRun(AbstractMigrationImportRepository $runs, string $mode, string $status): int
    {
        $id = $runs->startRun($this->supplierId, null, $mode, ['ico' => '12345678', 'year' => 2025, 'agenda_ico' => '12345678'], null);
        if ($status !== 'running') {
            $runs->finishRun($id, $this->supplierId, $status, ['status' => $status, 'steps' => []]);
        }
        return $id;
    }

    private function delete(object $action, int $id): ResponseInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest('DELETE', '/api/admin/imports')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute('auth.effective_role', new EffectiveRole(9, 'Test', 'staff', true, ['utilities.import' => 2]))
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId]);
        return $action->deleteRun($request, (new ResponseFactory())->createResponse(), ['id' => (string) $id]);
    }
}
