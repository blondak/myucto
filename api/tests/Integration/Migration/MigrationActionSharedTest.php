<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Migration;

use MyInvoice\Action\Admin\Import\AbstractMigrationAction;
use MyInvoice\Action\Admin\Import\MoneyS3MigrationAction;
use MyInvoice\Action\Admin\Import\PohodaMigrationAction;
use MyInvoice\Action\Admin\Import\PremierMigrationAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\ImportJobRepository;
use MyInvoice\Security\EffectiveRole;
use MyInvoice\Service\Migration\MoneyS3\MoneyS3ImportJobService;
use MyInvoice\Service\Migration\MoneyS3\MoneyS3Uploads;
use MyInvoice\Service\Migration\Pohoda\PohodaImportJobService;
use MyInvoice\Service\Migration\Pohoda\PohodaUploads;
use MyInvoice\Service\Migration\Premier\PremierImportJobService;
use MyInvoice\Service\Migration\Premier\PremierUploads;
use MyInvoice\Service\Migration\Shared\MigrationUploadLimits;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\UploadedFile;

/**
 * Společný základ průvodců převodem ({@see AbstractMigrationAction}) nad všemi zdroji:
 * přípony a limity zdroje, texty chyb nahrávání, dokončení jen celého souboru, hlídka
 * „jiný soubor se zpracovává" a stav po mrtvém jobu. Transakce s rollbackem v tearDown.
 */
#[Group('integration')]
final class MigrationActionSharedTest extends TestCase
{
    private \Psr\Container\ContainerInterface $container;
    private Connection $db;
    private int $supplierId = 0;
    private int $userId = 0;
    private string $tmp = '';

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
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->supplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($this->userId === 0 || $this->supplierId === 0) {
            $this->markTestSkipped('Chybí dodavatel nebo uživatel.');
        }
        $this->tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'migshared_' . bin2hex(random_bytes(5));
        mkdir($this->tmp, 0755, true);
        $pdo->beginTransaction();
    }

    protected function tearDown(): void
    {
        foreach ([MoneyS3Uploads::class, PohodaUploads::class, PremierUploads::class] as $uploads) {
            foreach ($this->tokens[$uploads] ?? [] as $token) {
                $uploads::purge($this->supplierId, $token);
            }
        }
        if (isset($this->db) && $this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
        }
        foreach (glob($this->tmp . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->tmp);
    }

    /** @var array<class-string,list<string>> */
    private array $tokens = [];

    /** @return iterable<string,array{class-string<AbstractMigrationAction>,class-string,class-string,string,int,string,string}> */
    public static function sources(): iterable
    {
        yield 'money_s3' => [MoneyS3MigrationAction::class, MoneyS3Uploads::class, MoneyS3ImportJobService::class, 'agenda.lz',
            MigrationUploadLimits::MONEY_S3_MAX_BYTES, 'Chybí část zálohy.', 'Jiná záloha Money S3 se právě zpracovává, počkejte na její dokončení.'];
        yield 'pohoda' => [PohodaMigrationAction::class, PohodaUploads::class, PohodaImportJobService::class, 'export.zip',
            MigrationUploadLimits::POHODA_MAX_BYTES, 'Chybí část exportu.', 'Jiný export z POHODY se právě zpracovává, počkejte na jeho dokončení.'];
        yield 'premier' => [PremierMigrationAction::class, PremierUploads::class, PremierImportJobService::class, 'zaloha.izip',
            MigrationUploadLimits::PREMIER_MAX_BYTES, 'Chybí část zálohy.', 'Jiná záloha PREMIER se právě zpracovává, počkejte na jeho dokončení.'];
    }

    /**
     * @param class-string<AbstractMigrationAction> $actionClass
     * @param class-string $uploads
     * @param class-string $jobService
     */
    #[DataProvider('sources')]
    public function testChunkedUploadFlowKeepsSourceLimitsAndTexts(string $actionClass, string $uploads, string $jobService, string $fileName, int $maxBytes, string $chunkMissing, string $alreadyProcessing): void
    {
        $action = $this->container->get($actionClass);

        self::assertSame(422, $this->call($action, 'initChunked', ['file_name' => 'data.xlsx', 'size' => 10])->getStatusCode());
        $tooLarge = $this->call($action, 'initChunked', ['file_name' => $fileName, 'size' => $maxBytes + 1]);
        self::assertSame([413, 'upload_too_large'], [$tooLarge->getStatusCode(), $this->json($tooLarge)['error']['code']]);

        $init = $this->call($action, 'initChunked', ['file_name' => $fileName, 'size' => 6]);
        self::assertSame(201, $init->getStatusCode());
        $token = (string) $this->json($init)['token'];
        $this->tokens[$uploads][] = $token;
        self::assertSame(MigrationUploadLimits::CHUNK_BYTES, $this->json($init)['chunk_size']);

        $missing = $this->call($action, 'chunk', ['offset' => '0'], $token);
        self::assertSame([400, $chunkMissing], [$missing->getStatusCode(), $this->json($missing)['error']['message']]);
        self::assertSame(3, $this->json($this->chunk($action, $token, 0, 'abc'))['received']);
        $mismatch = $this->chunk($action, $token, 0, 'abc');
        self::assertSame([409, 'chunk_offset_mismatch', 3], [$mismatch->getStatusCode(), $this->json($mismatch)['error']['code'], $this->json($mismatch)['error']['received']]);
        self::assertSame(422, $this->call($action, 'complete', [], $token)->getStatusCode());

        // Jiný soubor téže firmy se zpracovává: dokončení tohohle počká.
        $jobs = $this->container->get(ImportJobRepository::class);
        $other = $jobs->create($this->supplierId, $jobService::SOURCE, ['token' => str_repeat('b', 16), 'mode' => 'prepare'], $this->userId);
        self::assertSame(6, $this->json($this->chunk($action, $token, 3, 'def'))['received']);
        $busy = $this->call($action, 'complete', [], $token);
        self::assertSame([409, $alreadyProcessing, $other], [$busy->getStatusCode(), $this->json($busy)['error']['message'], $this->json($busy)['error']['existing_job_id']]);
        $jobs->delete($other, $this->supplierId);

        $uploads::updateState($this->supplierId, $token, ['status' => 'processing', 'job_id' => 999999999]);
        $dead = $this->json($this->call($action, 'show', [], $token));
        self::assertSame('failed', $dead['status'], 'Job, který zmizel, se hlásí jako chyba, ne jako věčné zpracování.');
        self::assertNotSame('', (string) $dead['error']);
    }

    /** @param array<string,mixed> $body */
    private function call(object $action, string $method, array $body = [], ?string $token = null): ResponseInterface
    {
        $request = $this->request($method === 'show' ? 'GET' : 'POST', $body);
        $response = (new ResponseFactory())->createResponse();
        return $token === null ? $action->{$method}($request, $response) : $action->{$method}($request, $response, ['token' => $token]);
    }

    private function chunk(object $action, string $token, int $offset, string $data): ResponseInterface
    {
        $path = $this->tmp . '/chunk-' . bin2hex(random_bytes(4));
        file_put_contents($path, $data);
        $request = $this->request('POST', ['offset' => (string) $offset])
            ->withUploadedFiles(['chunk' => new UploadedFile($path, 'part.bin', 'application/octet-stream', strlen($data))]);
        return $action->chunk($request, (new ResponseFactory())->createResponse(), ['token' => $token]);
    }

    /** @param array<string,mixed> $body */
    private function request(string $method, array $body): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest($method, '/api/admin/imports')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute('auth.effective_role', new EffectiveRole(9, 'Test', 'staff', true, ['utilities.import' => 2]))
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId])
            ->withParsedBody($body);
    }

    /** @return array<string,mixed> */
    private function json(ResponseInterface $response): array
    {
        return (array) json_decode((string) $response->getBody(), true);
    }
}
