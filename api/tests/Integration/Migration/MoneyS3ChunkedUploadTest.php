<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Migration;

use MyInvoice\Action\Admin\Import\MoneyS3MigrationAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\ImportJobRepository;
use MyInvoice\Repository\MoneyS3ImportRepository;
use MyInvoice\Security\EffectiveRole;
use MyInvoice\Service\Migration\MoneyS3\MoneyS3ImportJobService;
use MyInvoice\Service\Migration\MoneyS3\MoneyS3Uploads;
use MyInvoice\Tests\Fixtures\MoneyS3\SyntheticAgenda;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\UploadedFile;

/**
 * Záloha agendy nahraná po částech: založení → části s kontrolou navázání →
 * dokončení → zpracování jobem na pozadí → náhled. Job se tu pouští synchronně.
 * Izolovaná firma, transakce s rollbackem v tearDown.
 */
#[Group('integration')]
final class MoneyS3ChunkedUploadTest extends TestCase
{
    private \Psr\Container\ContainerInterface $container;
    private Connection $db;
    private MoneyS3MigrationAction $action;
    private string $tmp = '';
    private int $userId = 0;
    private int $supplierId = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $this->container = Bootstrap::buildContainer();
            $this->db = $this->container->get(Connection::class);
            $this->action = $this->container->get(MoneyS3MigrationAction::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }
        $pdo = $this->db->pdo();
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $currencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $vatRateId = (int) ($pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $czId = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        if ($this->userId === 0 || $currencyId === 0 || $vatRateId === 0 || $czId === 0) {
            $this->markTestSkipped('Chybí základní data (user/currency/vat_rate/country) v DB.');
        }

        $this->tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ms3chunk_' . bin2hex(random_bytes(5));
        mkdir($this->tmp, 0755, true);
        SyntheticAgenda::writeLz($this->tmp . '/agenda.lz');

        $pdo->beginTransaction();
        $this->inTx = true;
        $pdo->prepare(
            'INSERT INTO supplier (company_name, street, city, zip, country_id, email, ic, default_currency_id, default_vat_rate_id, accounting_mode)
             VALUES (?, "Účetní 12", "Brno", "60200", ?, "prevod@example.invalid", ?, ?, ?, "tax_evidence")'
        )->execute([SyntheticAgenda::NAME, $czId, SyntheticAgenda::ICO, $currencyId, $vatRateId]);
        $this->supplierId = (int) $pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        if ($this->supplierId > 0) {
            foreach (glob(MoneyS3Uploads::base($this->supplierId) . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
                MoneyS3Uploads::purge($this->supplierId, basename($dir));
            }
            @rmdir(MoneyS3Uploads::base($this->supplierId));
        }
        if (isset($this->db) && $this->inTx) {
            $pdo = $this->db->pdo();
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $this->db->close();
        }
        if ($this->tmp !== '' && is_dir($this->tmp)) {
            foreach (glob($this->tmp . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($this->tmp);
        }
    }

    public function testChunkedUploadIsProcessedInBackgroundJob(): void
    {
        $bytes = (string) file_get_contents($this->tmp . '/agenda.lz');
        $half = intdiv(strlen($bytes), 2);

        self::assertSame(422, $this->call('initChunked', ['file_name' => 'agenda.xlsx', 'size' => strlen($bytes)])->getStatusCode());
        self::assertSame(413, $this->call('initChunked', ['file_name' => 'agenda.lz', 'size' => 5 * 1024 * 1024 * 1024])->getStatusCode());

        $init = $this->call('initChunked', ['file_name' => '12345678ag001.lz', 'size' => strlen($bytes)]);
        self::assertSame(201, $init->getStatusCode());
        $token = (string) $this->json($init)['token'];
        self::assertSame(MoneyS3MigrationAction::CHUNK_BYTES, $this->json($init)['chunk_size']);

        $first = $this->chunk($token, 0, substr($bytes, 0, $half));
        self::assertSame(200, $first->getStatusCode());
        self::assertSame($half, $this->json($first)['received']);

        $repeated = $this->chunk($token, 0, substr($bytes, 0, $half));
        self::assertSame(409, $repeated->getStatusCode(), 'Opakovaná část se nepřipojí podruhé.');
        self::assertSame($half, $this->json($repeated)['error']['received']);

        $early = $this->call('complete', [], $token);
        self::assertSame(422, $early->getStatusCode());
        self::assertSame('upload_incomplete', $this->json($early)['error']['code']);

        $uploading = $this->json($this->call('show', [], $token));
        self::assertSame(['uploading', $half], [$uploading['status'], $uploading['received']]);

        self::assertSame(strlen($bytes), $this->json($this->chunk($token, $half, substr($bytes, $half)))['received']);

        $complete = $this->call('complete', [], $token);
        self::assertSame(202, $complete->getStatusCode());
        $jobId = (int) $this->json($complete)['job_id'];
        self::assertGreaterThan(0, $jobId);
        self::assertSame($jobId, (int) $this->json($this->call('complete', [], $token))['job_id'], 'Dvojí dokončení nezaloží druhý job.');

        $processing = $this->json($this->call('show', [], $token));
        self::assertSame(['processing', $jobId], [$processing['status'], $processing['job_id']]);

        $this->container->get(MoneyS3ImportJobService::class)->run($jobId);

        $jobs = $this->container->get(ImportJobRepository::class);
        self::assertSame('completed', $jobs->find($jobId, $this->supplierId)['status']);
        self::assertFileExists(MoneyS3Uploads::dir($this->supplierId, $token) . '/meta.json');
        self::assertFileDoesNotExist(MoneyS3Uploads::partPath($this->supplierId, $token), 'Nahraná záloha se po rozbalení smaže.');
        $meta = MoneyS3Uploads::meta($this->supplierId, $token);
        self::assertSame(hash('sha256', $bytes), $meta['sha256']);
        self::assertSame('12345678ag001.lz', $meta['file_name']);
        self::assertSame($this->userId, $meta['uploaded_by']);

        $show = $this->call('show', [], $token);
        self::assertSame(200, $show->getStatusCode());
        $ready = $this->json($show);
        self::assertSame('ready', $ready['status']);
        self::assertSame(SyntheticAgenda::ICO, $ready['agenda']['ico']);
        self::assertIsArray($ready['preflight']);
        self::assertSame([], $this->container->get(MoneyS3ImportRepository::class)->listRuns($this->supplierId),
            'Načtení zálohy není převod — protokol nevzniká.');

        // Načítání jiné zálohy firmy nesmí blokovat spuštění převodu nad touhle.
        $jobs->create($this->supplierId, MoneyS3ImportJobService::SOURCE, ['token' => str_repeat('b', 16), 'mode' => MoneyS3ImportJobService::MODE_PREPARE], $this->userId);
        $start = $this->call('start', ['mode' => 'dry_run'], $token);
        self::assertSame(201, $start->getStatusCode(), (string) $start->getBody());
    }

    public function testFailedProcessingIsReportedWithItsMessage(): void
    {
        $garbage = str_repeat('není to ZIP ', 50);
        $token = (string) $this->json($this->call('initChunked', ['file_name' => 'agenda.lz', 'size' => strlen($garbage)]))['token'];
        $this->chunk($token, 0, $garbage);
        $jobId = (int) $this->json($this->call('complete', [], $token))['job_id'];

        $this->container->get(MoneyS3ImportJobService::class)->run($jobId);

        $job = $this->container->get(ImportJobRepository::class)->find($jobId, $this->supplierId);
        self::assertSame('failed', $job['status']);
        $show = $this->call('show', [], $token);
        self::assertSame(200, $show->getStatusCode());
        $state = $this->json($show);
        self::assertSame('failed', $state['status']);
        self::assertStringContainsString('není záloha agendy Money S3', (string) $state['error']);
        self::assertSame($state['error'], $job['last_error']);
        self::assertFileDoesNotExist(MoneyS3Uploads::partPath($this->supplierId, $token));
        self::assertSame(409, $this->call('complete', [], $token)->getStatusCode());
    }

    public function testOneShotUploadOverServerLimitIsReportedAsTooLarge(): void
    {
        $file = new UploadedFile($this->tmp . '/agenda.lz', 'agenda.lz', 'application/octet-stream', 0, UPLOAD_ERR_INI_SIZE);
        $request = $this->request('POST', '/api/admin/imports/money-s3/uploads', [])->withUploadedFiles(['backup' => $file]);

        $response = $this->action->upload($request, (new ResponseFactory())->createResponse());

        self::assertSame(413, $response->getStatusCode());
        self::assertSame('upload_too_large', $this->json($response)['error']['code']);
    }

    /** @param array<string,mixed> $body */
    private function call(string $method, array $body = [], ?string $token = null): ResponseInterface
    {
        $request = $this->request($method === 'show' ? 'GET' : 'POST', '/api/admin/imports/money-s3/uploads', $body);
        $response = (new ResponseFactory())->createResponse();
        return $token === null
            ? $this->action->{$method}($request, $response)
            : $this->action->{$method}($request, $response, ['token' => $token]);
    }

    private function chunk(string $token, int $offset, string $data): ResponseInterface
    {
        $path = $this->tmp . '/chunk-' . bin2hex(random_bytes(4));
        file_put_contents($path, $data);
        $request = $this->request('POST', '/api/admin/imports/money-s3/uploads/' . $token . '/chunks', ['offset' => (string) $offset])
            ->withUploadedFiles(['chunk' => new UploadedFile($path, 'agenda.lz', 'application/octet-stream', strlen($data))]);
        return $this->action->chunk($request, (new ResponseFactory())->createResponse(), ['token' => $token]);
    }

    /** @param array<string,mixed> $body */
    private function request(string $method, string $path, array $body): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest($method, $path)
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
