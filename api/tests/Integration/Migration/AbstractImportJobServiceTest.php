<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Migration;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\ImportJobRepository;
use MyInvoice\Repository\PremierImportRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Migration\Premier\PremierException;
use MyInvoice\Service\Migration\Premier\PremierUploads;
use MyInvoice\Service\Migration\Shared\AbstractImportJobService;
use MyInvoice\Service\Migration\Shared\ChunkedUploadStore;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Kostra jobu převodu ({@see AbstractImportJobService}) na zdroji, který nic nečte:
 * roky vzestupně s vlastním během, zastavení po chybě, text chyby zdroje vs. obecná
 * hláška, průběh s rokem a počty zpráv. Transakce s rollbackem v tearDown.
 */
#[Group('integration')]
final class AbstractImportJobServiceTest extends TestCase
{
    private Connection $db;
    private ImportJobRepository $jobs;
    private FakeImportJobService $service;
    private int $supplierId = 0;
    private int $userId = 0;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php missing');
        }
        try {
            $container = Bootstrap::buildApp()->getContainer();
            $this->db = $container->get(Connection::class);
            $this->jobs = $container->get(ImportJobRepository::class);
            $this->service = new FakeImportJobService($this->jobs, $container->get(PremierImportRepository::class), $container->get(ActivityLogger::class));
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

    public function testYearsRunAscendingAndStopAfterFailure(): void
    {
        $jobId = $this->job(['mode' => 'import', 'years' => [2023, 2021, 2022]]);
        $this->service->outcomes = [2021 => 'completed_with_warnings', 2022 => 'failed', 2023 => 'completed'];

        $this->service->run($jobId);

        self::assertSame([2021, 2022], $this->service->ran);
        $job = $this->jobs->findById($jobId);
        self::assertSame('failed', $job['status']);
        self::assertStringStartsWith('Převod roku 2022 nedoběhl nebo nesedí rekonciliace - podrobnosti v protokolu #', (string) $job['last_error']);
        $log = (string) $job['log_text'];
        self::assertStringContainsString('Převádí se 3 roky vzestupně: 2021, 2022, 2023.', $log);
        self::assertStringContainsString('Rok 2021 (1 z 3): protokol #', $log);
        self::assertStringContainsString(', 1 chyb, 1 upozornění.', $log);
        self::assertStringContainsString('Rok 2022 skončil chybou, roky 2023 se nespouštějí.', $log);
        self::assertSame(2, (int) $job['failed_count']);
        self::assertSame(3 * 2, (int) $job['total_items']);
    }

    public function testSourceErrorIsShownAndUnexpectedErrorIsGeneric(): void
    {
        $jobId = $this->job(['mode' => 'dry_run', 'years' => [2021, 2022]]);
        $this->service->outcomes = [2021 => new PremierException('agenda_not_found', 'Rok v záloze chybí.')];
        $this->service->run($jobId);
        self::assertSame('Rok v záloze chybí.', $this->jobs->findById($jobId)['last_error']);
        self::assertStringContainsString('Rok 2021 (1 z 2): Rok v záloze chybí.', (string) $this->jobs->findById($jobId)['log_text']);

        $jobId = $this->job(['mode' => 'dry_run', 'years' => [2021]]);
        $this->service->outcomes = [2021 => new \LogicException('interní detail')];
        $previous = ini_set('error_log', (string) tempnam(sys_get_temp_dir(), 'job'));
        try {
            $this->service->run($jobId);
        } finally {
            ini_set('error_log', (string) $previous);
        }
        $job = $this->jobs->findById($jobId);
        self::assertSame('Falešný převod selhal.', $job['last_error']);
        self::assertStringNotContainsString('interní detail', (string) $job['log_text']);
    }

    public function testPrepareJobDoesNotTakeCompanyLock(): void
    {
        self::assertTrue(FakeImportJobService::isPrepareJob(['params' => ['mode' => 'prepare']]));
        self::assertFalse(FakeImportJobService::isPrepareJob(['params' => ['mode' => 'import']]));
        self::assertFalse(FakeImportJobService::isPrepareJob(['params' => null]));
    }

    public function testMessageCounts(): void
    {
        self::assertSame([2, 1], AbstractImportJobService::messageCounts(['steps' => [
            ['messages' => [['level' => 'error'], ['level' => 'info']]],
            ['messages' => [['level' => 'warning'], ['level' => 'error']]],
        ]]));
    }

    /** @param array<string,mixed> $params */
    private function job(array $params): int
    {
        return $this->jobs->create($this->supplierId, 'premier_import', $params + ['token' => str_repeat('a', 16)], $this->userId);
    }
}

/** Zdroj bez souborů: každý rok vrátí předepsaný výsledek nebo vyhodí výjimku. */
final class FakeImportJobService extends AbstractImportJobService
{
    protected const LOG_PREFIX = 'Test';
    protected const UPLOAD_NOUN = 'zálohy';
    protected const EXCEPTION_CLASS = PremierException::class;
    protected const RUN_FAILED = 'Falešný převod selhal.';
    protected const STEP_LABELS = ['one' => 'První', 'two' => 'Druhý'];

    /** @var array<int,string|\Throwable> */
    public array $outcomes = [];
    /** @var list<int> */
    public array $ran = [];

    protected function uploads(): ChunkedUploadStore
    {
        return PremierUploads::store();
    }

    protected function extractUpload(string $part, int $supplierId, string $token): mixed
    {
        return null;
    }

    protected function describeUpload(mixed $extracted, int $supplierId, string $token): array
    {
        return ['meta' => [], 'activity' => [], 'log' => ''];
    }

    protected function runLocked(int $jobId, array $job, int $supplierId): void
    {
        $params = (array) $job['params'];
        $years = \MyInvoice\Service\Migration\ImportYears::fromParams($params);
        $this->runYears($jobId, $years, ($params['mode'] ?? '') !== 'import', ['one', 'two'],
            fn (int $index, array &$totals): array => $this->runYear($jobId, $params, $supplierId, $index, $years, ['one', 'two'], $totals,
                function () use ($jobId, $supplierId, $years, $index): array {
                    $year = $years[$index];
                    $this->ran[] = $year;
                    $outcome = $this->outcomes[$year] ?? 'completed';
                    if ($outcome instanceof \Throwable) {
                        throw $outcome;
                    }
                    $runId = $this->runs->startRun($supplierId, $jobId, 'dry_run', ['year' => $year], null);
                    return [
                        'run_id' => $runId,
                        'log' => "Rok {$year} začíná.",
                        'kind' => 'accounting',
                        'import' => static function (?callable $progress, ?callable $cancel) use ($outcome): object {
                            $progress !== null && $progress('two', 1, 2);
                            return new class ($outcome) {
                                public function __construct(private readonly string $status) {}

                                public function status(): string
                                {
                                    return $this->status;
                                }

                                /** @return array<string,mixed> */
                                public function toArray(): array
                                {
                                    return ['failure' => null, 'steps' => [
                                        ['key' => 'journal', 'counts' => ['entries' => 3, 'existing' => 1], 'messages' => [['level' => 'error'], ['level' => 'warning']]],
                                    ]];
                                }
                            };
                        },
                    ];
                }));
    }
}
