<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Import;

use MyInvoice\Action\Admin\Import\StartFileImportAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\NamedLockName;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Service\Import\FileImportJobService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;
use Slim\Psr7\UploadedFile;

/**
 * Import na pozadí po částech: tisíce souborů jedním požadavkem neprojdou přes PHP
 * (`max_file_uploads`, `post_max_size`), proto klient nahrává `stage=1` a dávku skládá
 * pod jedním `upload_token`. Poslední požadavek (založení jobu a spuštění workeru) tu
 * neběží — ověřuje se skládání dávky a ochrana tokenu.
 */
#[Group('integration')]
final class StartFileImportChunkedTest extends TestCase
{
    private Connection $db;
    private StartFileImportAction $action;
    private int $supplierId = 0;
    private int $userId = 0;
    /** @var list<string> */
    private array $tokens = [];
    /** @var list<string> */
    private array $tmpFiles = [];

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $c = Bootstrap::buildContainer();
            $this->db = $c->get(Connection::class);
            $this->action = $c->get(StartFileImportAction::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }
        $pdo = $this->db->pdo();
        $this->supplierId = (int) ($pdo->query('SELECT MIN(id) FROM supplier')->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT MIN(id) FROM users')->fetchColumn() ?: 0);
        if ($this->supplierId === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí supplier/user.');
        }
        $running = $pdo->prepare("SELECT COUNT(*) FROM import_jobs WHERE supplier_id = ? AND source = 'file_import' AND status IN ('queued', 'running')");
        $running->execute([$this->supplierId]);
        if ((int) $running->fetchColumn() > 0) {
            $this->markTestSkipped('Firma má rozběhnutý import.');
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->tokens as $token) {
            $dir = FileImportJobService::stagingDir($this->supplierId, $token);
            foreach ((array) glob($dir . '/*') as $f) {
                if (is_file($f)) @unlink($f);
            }
            @rmdir($dir);
        }
        foreach ($this->tmpFiles as $f) {
            if (is_file($f)) @unlink($f);
        }
        if (isset($this->db)) $this->db->close();
    }

    public function testChunksAreCollectedUnderOneToken(): void
    {
        [$status, $first] = $this->post(['stage' => '1', 'kind' => 'purchase'], 2);
        self::assertSame(202, $status, json_encode($first) ?: '');
        $token = (string) $first['upload_token'];
        $this->tokens[] = $token;
        self::assertSame(2, $first['files']);

        [$status, $second] = $this->post(['stage' => '1', 'upload' => $token], 3);
        self::assertSame(202, $status, json_encode($second) ?: '');
        self::assertSame($token, $second['upload_token']);
        self::assertSame(5, $second['files']);

        $dir = FileImportJobService::stagingDir($this->supplierId, $token);
        self::assertCount(5, (array) glob($dir . '/*.bin'));
        $manifest = json_decode((string) file_get_contents($dir . '/manifest.json'), true);
        self::assertSame('purchase', $manifest['kind']);
        self::assertSame(['doklad-0.xml', 'doklad-1.xml', 'doklad-0.xml', 'doklad-1.xml', 'doklad-2.xml'], array_column($manifest['files'], 'name'));
    }

    public function testForeignOrUnknownTokenIsRejected(): void
    {
        [, $first] = $this->post(['stage' => '1'], 1);
        $this->tokens[] = (string) $first['upload_token'];

        [$status] = $this->post(['stage' => '1', 'upload' => (string) $first['upload_token']], 1, $this->userId + 100000);
        self::assertSame(404, $status, 'Cizí uživatel do rozpracované dávky nepřidá.');
        [$status] = $this->post(['stage' => '1', 'upload' => '../../etc'], 1);
        self::assertSame(404, $status);
        [$status] = $this->post(['stage' => '1', 'upload' => str_repeat('0', 16)], 1);
        self::assertSame(404, $status);
    }

    public function testRejectedFinalChunkKeepsStagedFilesWithoutStartingAJob(): void
    {
        [, $first] = $this->post(['stage' => '1'], 1);
        $token = (string) $first['upload_token'];
        $this->tokens[] = $token;
        $dir = FileImportJobService::stagingDir($this->supplierId, $token);
        $before = file_get_contents($dir . '/manifest.json');
        $jobs = (int) $this->db->pdo()->query('SELECT COUNT(*) FROM import_jobs')->fetchColumn();

        foreach ([UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_PARTIAL] as $error) {
            [$status] = $this->post(['upload' => $token, 'file_count' => '1'], 1, uploadError: $error);
            self::assertSame($error === UPLOAD_ERR_INI_SIZE ? 413 : 400, $status);
            self::assertSame($before, file_get_contents($dir . '/manifest.json'));
        }
        [$status] = $this->post(['upload' => $token, 'file_count' => '2'], 1);
        self::assertSame(400, $status);
        self::assertSame($before, file_get_contents($dir . '/manifest.json'));
        self::assertSame($jobs, (int) $this->db->pdo()->query('SELECT COUNT(*) FROM import_jobs')->fetchColumn());
        self::assertCount(1, (array) glob($dir . '/*.bin'));
    }

    public function testInvalidUtf8FilenameDoesNotBreakTheStagedBatch(): void
    {
        [$status, $first] = $this->post(['stage' => '1'], 1, filename: "synthetic-\xFF.xml");
        self::assertSame(202, $status);
        $token = (string) $first['upload_token'];
        $this->tokens[] = $token;
        [$status, $second] = $this->post(['stage' => '1', 'upload' => $token], 1);
        self::assertSame(202, $status);
        self::assertSame(2, $second['files']);
        $manifest = json_decode((string) file_get_contents(FileImportJobService::stagingDir($this->supplierId, $token) . '/manifest.json'), true, flags: JSON_THROW_ON_ERROR);
        self::assertTrue(mb_check_encoding($manifest['files'][0]['name'], 'UTF-8'));
        self::assertStringEndsWith('.xml', $manifest['files'][0]['name']);
    }

    public function testConcurrentChunkCannotOverwriteTheStagedBatch(): void
    {
        [, $first] = $this->post(['stage' => '1'], 1);
        $token = (string) $first['upload_token'];
        $this->tokens[] = $token;
        $dir = FileImportJobService::stagingDir($this->supplierId, $token);
        $before = file_get_contents($dir . '/manifest.json');
        $other = Connection::withoutSharedTestConnection(static function (): Connection {
            $connection = Bootstrap::buildContainer()->get(Connection::class);
            $connection->pdo();
            return $connection;
        });
        $lockName = NamedLockName::for($this->db, 'file_import_upload', $this->supplierId);
        $lock = $other->pdo()->prepare('SELECT GET_LOCK(?, 0)');
        $lock->execute([$lockName]);
        self::assertSame(1, (int) $lock->fetchColumn());
        try {
            [$status] = $this->post(['stage' => '1', 'upload' => $token], 1);
            self::assertSame(409, $status);
            self::assertSame($before, file_get_contents($dir . '/manifest.json'));
            self::assertCount(1, (array) glob($dir . '/*.bin'));
        } finally {
            $other->pdo()->prepare('SELECT RELEASE_LOCK(?)')->execute([$lockName]);
            $other->close();
        }
        [$status, $result] = $this->post(['stage' => '1', 'upload' => $token], 1);
        self::assertSame(202, $status);
        self::assertSame(2, $result['files']);
    }

    public function testDiscardedRequestBodyDoesNotFinalizeTheBatch(): void
    {
        $limit = ini_parse_quantity((string) ini_get('post_max_size'));
        if ($limit <= 0) self::markTestSkipped('PHP má neomezenou velikost POST.');
        [, $first] = $this->post(['stage' => '1'], 1);
        $token = (string) $first['upload_token'];
        $this->tokens[] = $token;
        [$status] = $this->post(['upload' => $token], 0, server: ['CONTENT_LENGTH' => $limit + 1]);
        self::assertSame(413, $status);
        self::assertFileExists(FileImportJobService::stagingDir($this->supplierId, $token) . '/manifest.json');
    }

    /**
     * @param array<string,string> $query
     * @return array{0:int,1:array<string,mixed>}
     */
    private function post(array $query, int $files, ?int $userId = null, int $uploadError = UPLOAD_ERR_OK, array $server = [], ?string $filename = null): array
    {
        $uploads = [];
        for ($i = 0; $i < $files; $i++) {
            $tmp = (string) tempnam(sys_get_temp_dir(), 'imp-chunk-');
            file_put_contents($tmp, '<Invoice/>');
            $this->tmpFiles[] = $tmp;
            $uploads[] = new UploadedFile($tmp, $filename ?? ('doklad-' . $i . '.xml'), 'application/xml', 10, $uploadError);
        }
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/api/admin/import/start', $server)
            ->withQueryParams($query)
            ->withUploadedFiles(['files' => $uploads])
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $userId ?? $this->userId, 'role' => 'admin']);
        $response = ($this->action)($request, new Psr7Response());
        return [$response->getStatusCode(), (array) json_decode((string) $response->getBody(), true)];
    }
}
