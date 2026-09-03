<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Backup;

use DateTimeImmutable;
use DateTimeZone;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Backup\Company\CompanyBackupArchiveWriteResult;
use MyInvoice\Service\Backup\Company\CompanyBackupArtifactRootResolver;
use MyInvoice\Service\Backup\Company\CompanyBackupArtifactStorage;
use MyInvoice\Service\Backup\Company\CompanyBackupDownloadException;
use MyInvoice\Service\Backup\Company\CompanyBackupDownloadService;
use MyInvoice\Service\Backup\Company\CompanyBackupJobException;
use MyInvoice\Service\Backup\Company\CompanyBackupJobManagementService;
use MyInvoice\Service\Backup\Company\CompanyBackupJobRetentionPolicy;
use MyInvoice\Service\Backup\Company\CompanyBackupJobStatus;
use MyInvoice\Service\Backup\Company\CompanyBackupJobStore;
use MyInvoice\Service\Backup\Company\CompanyBackupManagementException;
use MyInvoice\Service\Backup\Company\CompanyBackupRetentionCleanup;
use MyInvoice\Service\Backup\Company\CompanyBackupStoredArtifact;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

#[Group('integration')]
final class CompanyBackupJobStoreTest extends TestCase
{
    private const PASSWORD = 'synthetic-job-password-42';
    private const FINGERPRINT =
        'sha256:0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';

    private Connection $db;
    private CompanyBackupJobStore $jobs;
    private int $supplierId = 0;
    private int $foreignSupplierId = 0;
    private int $userId = 0;
    private bool $connected = false;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $container = Bootstrap::buildApp()->getContainer();
            if ($container === null) {
                throw new \RuntimeException('Aplikace nemá DI kontejner.');
            }
            $connection = $container->get(Connection::class);
            if (!$connection instanceof Connection) {
                throw new \RuntimeException('DI nevrátilo databázové spojení.');
            }
            $this->db = $connection;
            $pdo = $this->db->pdo();
            $this->connected = true;
        } catch (\Throwable $e) {
            $this->markTestSkipped('Testovací DB není dostupná: ' . $e->getMessage());
        }
        if (!$this->tableExists($pdo, 'company_backup_jobs')) {
            self::fail('Chybí migrace tabulky company_backup_jobs.');
        }

        $countryId = $this->scalarInt(
            $pdo,
            "SELECT id FROM countries WHERE iso2 = 'CZ' ORDER BY id LIMIT 1",
        );
        $currencyId = $this->scalarInt(
            $pdo,
            "SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1",
        );
        $vatRateId = $this->scalarInt(
            $pdo,
            'SELECT id FROM vat_rates ORDER BY id LIMIT 1',
        );
        $this->userId = $this->scalarInt(
            $pdo,
            'SELECT id FROM users ORDER BY id LIMIT 1',
        );
        if ($countryId < 1 || $currencyId < 1 || $vatRateId < 1 || $this->userId < 1) {
            $this->markTestSkipped('Testovací DB nemá základní syntetická data.');
        }

        $pdo->beginTransaction();
        $this->supplierId = $this->createSupplier(
            $pdo,
            'Company backup job vlastník s.r.o.',
            'company-backup-job-owner@example.test',
            $countryId,
            $currencyId,
            $vatRateId,
        );
        $this->foreignSupplierId = $this->createSupplier(
            $pdo,
            'Company backup job cizí s.r.o.',
            'company-backup-job-foreign@example.test',
            $countryId,
            $currencyId,
            $vatRateId,
        );
        $this->jobs = new CompanyBackupJobStore(
            $this->db,
            new SecretEncryption(new Config([
                'app' => [
                    'secret_encryption_key' => base64_encode(str_repeat('j', 32)),
                ],
            ])),
        );
    }

    protected function tearDown(): void
    {
        if (!$this->connected) {
            return;
        }
        $pdo = $this->db->pdo();
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $this->db->close();
    }

    public function testCreatesEncryptedTenantScopedJobWithoutExposingCiphertext(): void
    {
        $backupId = $this->createJob($this->supplierId);
        $job = $this->jobs->find($backupId, $this->supplierId);

        self::assertNotNull($job);
        self::assertSame($backupId, $job['backup_id']);
        self::assertSame('queued', $job['status']);
        self::assertArrayNotHasKey('password_ciphertext', $job);
        self::assertNull($this->jobs->find($backupId, $this->foreignSupplierId));
        self::assertSame(self::PASSWORD, $this->jobs->passwordForWorker($backupId));

        $statement = $this->db->pdo()->prepare(
            'SELECT password_ciphertext FROM company_backup_jobs WHERE backup_id = ?',
        );
        $statement->execute([$backupId]);
        $stored = $statement->fetchColumn();
        self::assertIsString($stored);
        self::assertStringStartsWith('enc:v2:', $stored);
        self::assertStringNotContainsString(self::PASSWORD, $stored);
    }

    public function testCreateRequiresDedicatedValidApplicationEncryptionKey(): void
    {
        $fallbackOnly = new CompanyBackupJobStore(
            $this->db,
            new SecretEncryption(new Config([
                'app' => ['pepper' => 'synthetic-fallback-is-not-enough'],
            ])),
        );

        try {
            $fallbackOnly->create(
                $this->supplierId,
                $this->userId,
                self::FINGERPRINT,
                self::PASSWORD,
            );
            self::fail('Nový citlivý job nesmí spoléhat na legacy HKDF fallback.');
        } catch (CompanyBackupJobException $e) {
            self::assertSame('job_secret_key_unavailable', $e->errorCode);
        }

        self::assertSame([], $this->jobs->listForSupplier($this->supplierId));
    }

    public function testOneActiveJobPerSupplierDoesNotBlockAnotherSupplier(): void
    {
        $first = $this->createJob($this->supplierId);

        try {
            $this->createJob($this->supplierId);
            self::fail('Druhý aktivní backup job stejné firmy musí být odmítnut.');
        } catch (CompanyBackupJobException $e) {
            self::assertSame('already_running', $e->errorCode);
        }

        $foreign = $this->createJob($this->foreignSupplierId);
        self::assertNotSame($first, $foreign);
    }

    public function testCompletesOnlyOrderedLifecycleAndClearsTransientPassword(): void
    {
        $backupId = $this->createJob($this->supplierId);
        self::assertFalse($this->jobs->startSnapshotting($backupId));
        self::assertTrue($this->jobs->startChecking($backupId));
        self::assertFalse($this->jobs->startPackaging($backupId));
        self::assertTrue($this->jobs->startSnapshotting($backupId));
        self::assertTrue($this->jobs->startPackaging($backupId));

        $completedAt = new DateTimeImmutable('2026-09-02T10:00:00+00:00');
        $artifact = new CompanyBackupStoredArtifact(
            $this->supplierId,
            $backupId,
            'sup-' . $this->supplierId . '/' . $backupId . '.zip',
            'myucto-company-backup-' . $backupId . '.zip',
            12_345,
            str_repeat('a', 64),
            77,
        );
        self::assertTrue($this->jobs->complete(
            $backupId,
            $artifact,
            $completedAt,
            new CompanyBackupJobRetentionPolicy(24),
        ));

        $job = $this->jobs->find($backupId, $this->supplierId);
        self::assertNotNull($job);
        self::assertSame(CompanyBackupJobStatus::Completed->value, $job['status']);
        self::assertSame($artifact->relativePath, $job['artifact_path']);
        self::assertSame(12_345, $job['artifact_bytes']);
        self::assertSame('2026-09-03 12:00:00.000000', $job['expires_at']);
        self::assertFalse($this->jobs->startPackaging($backupId));
        $this->assertPasswordUnavailable($backupId);
    }

    public function testPasswordCiphertextCannotMoveBetweenTenantJobs(): void
    {
        $ownId = $this->createJob($this->supplierId);
        $foreignId = $this->createJob($this->foreignSupplierId);
        $statement = $this->db->pdo()->prepare(
            'SELECT password_ciphertext FROM company_backup_jobs WHERE backup_id = ?',
        );
        $statement->execute([$ownId]);
        $ownCiphertext = $statement->fetchColumn();
        self::assertIsString($ownCiphertext);

        $this->db->pdo()->prepare(
            'UPDATE company_backup_jobs SET password_ciphertext = ? WHERE backup_id = ?',
        )->execute([$ownCiphertext, $foreignId]);

        $this->assertPasswordUnavailable($foreignId, expectNullInDatabase: false);
        self::assertSame(self::PASSWORD, $this->jobs->passwordForWorker($ownId));
    }

    public function testDatabaseRejectsPlaintextAndTerminalStateWithPassword(): void
    {
        $backupId = $this->createJob($this->supplierId);

        try {
            $this->db->pdo()->prepare(
                'UPDATE company_backup_jobs SET password_ciphertext = ?'
                . ' WHERE backup_id = ?',
            )->execute(['plaintext-password', $backupId]);
            self::fail('DB nesmí přijmout plaintext heslo aktivního jobu.');
        } catch (\PDOException) {
            self::addToAssertionCount(1);
        }

        try {
            $this->db->pdo()->prepare(
                'UPDATE company_backup_jobs SET status = ?, finished_at = CURRENT_TIMESTAMP(6)'
                . ' WHERE backup_id = ?',
            )->execute([CompanyBackupJobStatus::Failed->value, $backupId]);
            self::fail('DB nesmí ukončit job bez atomického odstranění hesla.');
        } catch (\PDOException) {
            self::addToAssertionCount(1);
        }

        self::assertSame(self::PASSWORD, $this->jobs->passwordForWorker($backupId));
    }

    public function testCancelAndFailureClearPasswordAndReleaseActiveSlot(): void
    {
        $cancelledId = $this->createJob($this->supplierId);
        self::assertTrue($this->jobs->requestCancel(
            $cancelledId,
            $this->supplierId,
        ));
        self::assertFalse(
            $this->jobs->requestCancel($cancelledId, $this->supplierId),
            'Opakované storno nesmí atomicky ohlásit další změnu.',
        );
        self::assertFalse($this->jobs->requestCancel(
            $cancelledId,
            $this->foreignSupplierId,
        ));
        self::assertFalse(
            $this->jobs->startChecking($cancelledId),
            'Po přijetí storna worker nesmí zahájit další fázi.',
        );
        self::assertTrue($this->jobs->markCancelled($cancelledId));
        $this->assertPasswordUnavailable($cancelledId);

        $failedId = $this->createJob($this->supplierId);
        self::assertTrue($this->jobs->startChecking($failedId));
        self::assertTrue($this->jobs->markFailed(
            $failedId,
            'snapshot_failed',
            'Syntetická chyba snapshotu.',
        ));
        $this->assertPasswordUnavailable($failedId);

        $nextId = $this->createJob($this->supplierId);
        self::assertNotSame($failedId, $nextId);
    }

    public function testStaleJobFailsClosedClearsPasswordAndReleasesSlot(): void
    {
        $staleId = $this->createJob($this->supplierId);
        self::assertTrue($this->jobs->startChecking($staleId));
        $this->db->pdo()->prepare(
            'UPDATE company_backup_jobs'
            . ' SET updated_at = CURRENT_TIMESTAMP(6) - INTERVAL 2 HOUR'
            . ' WHERE backup_id = ?',
        )->execute([$staleId]);

        self::assertSame(1, $this->jobs->reapStale($this->supplierId, 60));
        $job = $this->jobs->find($staleId, $this->supplierId);
        self::assertNotNull($job);
        self::assertSame(CompanyBackupJobStatus::Failed->value, $job['status']);
        self::assertSame('worker_stale', $job['last_error_code']);
        $this->assertPasswordUnavailable($staleId);

        self::assertNotSame($staleId, $this->createJob($this->supplierId));
    }

    public function testProcessingJobMayExpireWithoutLeavingPassword(): void
    {
        $backupId = $this->createJob($this->supplierId);
        self::assertTrue($this->jobs->startChecking($backupId));

        self::assertTrue($this->jobs->expireProcessing($backupId));
        $job = $this->jobs->find($backupId, $this->supplierId);
        self::assertNotNull($job);
        self::assertSame(CompanyBackupJobStatus::Expired->value, $job['status']);
        $this->assertPasswordUnavailable($backupId);
        self::assertNotSame($backupId, $this->createJob($this->supplierId));
    }

    public function testExpiredCompletedArtifactLosesMetadataAndDownloadability(): void
    {
        $backupId = $this->completedJob($this->supplierId);
        $expired = $this->jobs->expiredArtifacts(
            new DateTimeImmutable('2026-09-04T00:00:00+00:00'),
        );
        self::assertSame([$backupId], array_column($expired, 'backup_id'));

        $completed = $this->jobs->find($backupId, $this->supplierId);
        self::assertNotNull($completed);
        self::assertTrue($this->jobs->markArtifactRemoved(
            $this->artifactFromJob($completed),
        ));
        $job = $this->jobs->find($backupId, $this->supplierId);
        self::assertNotNull($job);
        self::assertSame(CompanyBackupJobStatus::Expired->value, $job['status']);
        self::assertNull($job['artifact_path']);
        self::assertNull($job['artifact_sha256']);
        self::assertNull($job['expires_at']);
    }

    public function testRetentionCleanupDeletesFileBeforeExpiringMetadata(): void
    {
        [$backupId, , $storage, $path, $root] = $this->completedStoredJob(
            $this->supplierId,
        );
        try {
            $cleanup = new CompanyBackupRetentionCleanup($this->jobs, $storage);

            $early = $cleanup->run(
                new DateTimeImmutable('2026-09-03T09:59:59+00:00'),
            );
            self::assertSame(0, $early->candidateCount);
            self::assertNotNull($this->jobs->findDownloadable(
                $backupId,
                $this->supplierId,
                new DateTimeImmutable('2026-09-03T09:59:59+00:00'),
            ));
            self::assertFileExists($path);

            self::assertNull($this->jobs->findDownloadable(
                $backupId,
                $this->supplierId,
                new DateTimeImmutable('2026-09-03T10:00:00+00:00'),
            ));
            $result = $cleanup->run(
                new DateTimeImmutable('2026-09-03T10:00:00+00:00'),
            );
            self::assertSame(1, $result->candidateCount);
            self::assertSame(1, $result->expiredCount);
            self::assertSame(0, $result->deferredCount);
            self::assertFileDoesNotExist($path);

            $job = $this->jobs->find($backupId, $this->supplierId);
            self::assertNotNull($job);
            self::assertSame(CompanyBackupJobStatus::Expired->value, $job['status']);
            self::assertNull($job['artifact_path']);
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function testRetentionCleanupKeepsMetadataWhenRemovalIsUnsafe(): void
    {
        [$backupId, , $storage, $path, $root] = $this->completedStoredJob(
            $this->supplierId,
        );
        try {
            chmod($path, 0640);
            self::assertTrue(unlink($path));
            self::assertTrue(mkdir($path, 0750));
            $cleanup = new CompanyBackupRetentionCleanup($this->jobs, $storage);

            $deferred = $cleanup->run(
                new DateTimeImmutable('2026-09-03T10:00:00+00:00'),
            );
            self::assertSame(1, $deferred->candidateCount);
            self::assertSame(0, $deferred->expiredCount);
            self::assertSame(1, $deferred->deferredCount);
            $job = $this->jobs->find($backupId, $this->supplierId);
            self::assertNotNull($job);
            self::assertSame(CompanyBackupJobStatus::Completed->value, $job['status']);
            self::assertNotNull($job['artifact_path']);
            self::assertNull($this->jobs->findDownloadable(
                $backupId,
                $this->supplierId,
                new DateTimeImmutable('2026-09-03T10:00:00+00:00'),
            ));

            self::assertTrue(rmdir($path));
            $retried = $cleanup->run(
                new DateTimeImmutable('2026-09-03T10:00:01+00:00'),
            );
            self::assertSame(1, $retried->expiredCount);
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function testDownloadServiceKeepsTenantStatusAndArtifactBoundaries(): void
    {
        [$backupId, $artifact, $storage, $path, $root] =
            $this->completedStoredJob($this->supplierId);
        $clock = new MockClock('2026-09-03T09:00:00+00:00');
        $downloads = new CompanyBackupDownloadService(
            $this->jobs,
            $storage,
            $clock,
        );

        try {
            $prepared = $downloads->prepare(
                $backupId,
                $this->supplierId,
                'bytes=10-19',
                '"sha256:' . $artifact->sha256 . '"',
            );

            self::assertSame(206, $prepared->plan->statusCode);
            self::assertSame(
                'bytes 10-19/' . $artifact->bytes,
                $prepared->plan->contentRange(),
            );
            self::assertSame(
                substr('synthetic-expiring-company-backup', 10, 10),
                $prepared->stream->getContents(),
            );

            $this->assertDownloadError(
                fn () => $downloads->prepare(
                    $backupId,
                    $this->foreignSupplierId,
                    null,
                    null,
                ),
                'not_found',
            );

            $pendingId = $this->createJob($this->supplierId);
            $this->assertDownloadError(
                fn () => $downloads->prepare(
                    $pendingId,
                    $this->supplierId,
                    null,
                    null,
                ),
                'not_ready',
            );

            chmod($path, 0640);
            self::assertTrue(unlink($path));
            $this->assertDownloadError(
                fn () => $downloads->prepare(
                    $backupId,
                    $this->supplierId,
                    null,
                    null,
                ),
                'artifact_unavailable',
            );

            $expired = new CompanyBackupDownloadService(
                $this->jobs,
                $storage,
                new MockClock('2026-09-03T10:00:00+00:00'),
            );
            $this->assertDownloadError(
                fn () => $expired->prepare(
                    $backupId,
                    $this->supplierId,
                    null,
                    null,
                ),
                'artifact_expired',
            );
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function testManagementServiceListsOnlySanitizedTenantJobs(): void
    {
        $completedId = $this->completedJob($this->supplierId);
        $queuedId = $this->createJob($this->supplierId);
        $foreignId = $this->createJob($this->foreignSupplierId);
        $management = new CompanyBackupJobManagementService(
            $this->jobs,
            new CompanyBackupArtifactStorage(),
            new MockClock('2026-09-03T11:59:59+02:00'),
        );

        $items = $management->list($this->supplierId, 20);

        self::assertSame([$queuedId, $completedId], array_column($items, 'backup_id'));
        self::assertNotContains($foreignId, array_column($items, 'backup_id'));
        $completed = $management->detail($completedId, $this->supplierId);
        self::assertTrue($completed['downloadable']);
        self::assertTrue($completed['deletable']);
        self::assertFalse($completed['cancellable']);
        self::assertSame(str_repeat('b', 64), $completed['sha256']);
        self::assertSame(
            '2026-09-03T12:00:00.000000+02:00',
            $completed['expires_at'],
        );
        foreach ([
            'supplier_id',
            'registry_fingerprint',
            'password_ciphertext',
            'artifact_path',
            'last_error_message',
            'created_by',
            'expires_at_epoch',
            'started_at_epoch',
            'finished_at_epoch',
            'created_at_epoch',
            'updated_at_epoch',
        ] as $privateKey) {
            self::assertArrayNotHasKey($privateKey, $completed);
        }

        $expiredView = new CompanyBackupJobManagementService(
            $this->jobs,
            new CompanyBackupArtifactStorage(),
            new MockClock('2026-09-03T12:00:00+02:00'),
        );
        self::assertFalse(
            $expiredView->detail($completedId, $this->supplierId)['downloadable'],
            'Přesná expirační hranice už nesmí být prezentovaná ke stažení.',
        );
        $this->assertManagementError(
            fn () => $management->detail($completedId, $this->foreignSupplierId),
            'not_found',
        );
    }

    public function testManagementServiceRequestsCancellationIdempotently(): void
    {
        $backupId = $this->createJob($this->supplierId);
        $management = new CompanyBackupJobManagementService(
            $this->jobs,
            new CompanyBackupArtifactStorage(),
            new MockClock('2026-09-02T12:00:00+02:00'),
        );

        $first = $management->cancel($backupId, $this->supplierId);
        self::assertTrue($first['changed']);
        self::assertTrue($first['job']['cancel_requested']);
        self::assertFalse($first['job']['cancellable']);

        $second = $management->cancel($backupId, $this->supplierId);
        self::assertFalse($second['changed']);
        self::assertTrue($second['job']['cancel_requested']);
        $this->assertManagementError(
            fn () => $management->cancel($backupId, $this->foreignSupplierId),
            'not_found',
        );

        self::assertTrue($this->jobs->markCancelled($backupId));
        $this->assertManagementError(
            fn () => $management->cancel($backupId, $this->supplierId),
            'not_cancellable',
        );
    }

    public function testManagementServiceKeepsFirstDstFoldExpiryInstant(): void
    {
        $backupId = $this->createJob($this->supplierId);
        self::assertTrue($this->jobs->startChecking($backupId));
        self::assertTrue($this->jobs->startSnapshotting($backupId));
        self::assertTrue($this->jobs->startPackaging($backupId));
        self::assertTrue($this->jobs->complete(
            $backupId,
            new CompanyBackupStoredArtifact(
                $this->supplierId,
                $backupId,
                'sup-' . $this->supplierId . '/' . $backupId . '.zip',
                'myucto-company-backup-' . $backupId . '.zip',
                1_024,
                str_repeat('c', 64),
                5,
            ),
            new DateTimeImmutable('2026-10-24T00:30:00+00:00'),
            new CompanyBackupJobRetentionPolicy(24),
        ));
        $prague = new DateTimeZone('Europe/Prague');
        $before = new CompanyBackupJobManagementService(
            $this->jobs,
            new CompanyBackupArtifactStorage(),
            new MockClock(
                new DateTimeImmutable('2026-10-25T00:29:59+00:00'),
                $prague,
            ),
        );
        $atCutoff = new CompanyBackupJobManagementService(
            $this->jobs,
            new CompanyBackupArtifactStorage(),
            new MockClock(
                new DateTimeImmutable('2026-10-25T00:30:00+00:00'),
                $prague,
            ),
        );

        $beforeJob = $before->detail($backupId, $this->supplierId);
        self::assertSame(
            '2026-10-25T02:30:00.000000+02:00',
            $beforeJob['expires_at'],
        );
        self::assertTrue($beforeJob['downloadable']);
        self::assertFalse(
            $atCutoff->detail($backupId, $this->supplierId)['downloadable'],
        );
    }

    public function testManagementServiceDeletesOwnedArtifactBeforeExpiringMetadata(): void
    {
        [$backupId, $artifact, $storage, $path, $root] =
            $this->completedStoredJob($this->supplierId);
        $management = new CompanyBackupJobManagementService(
            $this->jobs,
            $storage,
            new MockClock('2026-09-02T12:30:00+02:00'),
        );

        try {
            $this->assertManagementError(
                fn () => $management->deleteArtifact(
                    $backupId,
                    $this->foreignSupplierId,
                ),
                'not_found',
            );
            self::assertFileExists($path);

            $deleted = $management->deleteArtifact($backupId, $this->supplierId);
            self::assertTrue($deleted['changed']);
            self::assertSame($artifact->sha256, $deleted['sha256']);
            self::assertSame('expired', $deleted['job']['status']);
            self::assertNull($deleted['job']['sha256']);
            self::assertFileDoesNotExist($path);

            $again = $management->deleteArtifact($backupId, $this->supplierId);
            self::assertFalse($again['changed']);
            self::assertNull($again['sha256']);

            $activeId = $this->createJob($this->supplierId);
            $this->assertManagementError(
                fn () => $management->deleteArtifact(
                    $activeId,
                    $this->supplierId,
                ),
                'not_deletable',
            );
        } finally {
            $this->removeDirectory($root);
        }
    }

    private function createJob(int $supplierId): string
    {
        return $this->jobs->create(
            $supplierId,
            $this->userId,
            self::FINGERPRINT,
            self::PASSWORD,
        );
    }

    private function completedJob(int $supplierId): string
    {
        $backupId = $this->createJob($supplierId);
        self::assertTrue($this->jobs->startChecking($backupId));
        self::assertTrue($this->jobs->startSnapshotting($backupId));
        self::assertTrue($this->jobs->startPackaging($backupId));
        self::assertTrue($this->jobs->complete(
            $backupId,
            new CompanyBackupStoredArtifact(
                $supplierId,
                $backupId,
                'sup-' . $supplierId . '/' . $backupId . '.zip',
                'myucto-company-backup-' . $backupId . '.zip',
                1_024,
                str_repeat('b', 64),
                5,
            ),
            new DateTimeImmutable('2026-09-02T10:00:00+00:00'),
            new CompanyBackupJobRetentionPolicy(24),
        ));
        return $backupId;
    }

    /**
     * @return array{
     *   string,
     *   CompanyBackupStoredArtifact,
     *   CompanyBackupArtifactStorage,
     *   string,
     *   string
     * }
     */
    private function completedStoredJob(int $supplierId): array
    {
        $root = sys_get_temp_dir() . '/company-backup-cleanup-'
            . bin2hex(random_bytes(8));
        if (!mkdir($root, 0750)) {
            self::fail('Nepodařilo se vytvořit syntetické artifact storage.');
        }
        $storageRoot = $root . '/company-backups';
        $storage = new CompanyBackupArtifactStorage(
            new class ($storageRoot) implements CompanyBackupArtifactRootResolver {
                public function __construct(private readonly string $root) {}

                public function root(): string
                {
                    return $this->root;
                }
            },
        );
        $backupId = $this->createJob($supplierId);
        $path = $storage->prepareDestination($supplierId, $backupId);
        $contents = 'synthetic-expiring-company-backup';
        file_put_contents($path, $contents);
        $artifact = $storage->capture(
            $supplierId,
            $backupId,
            new CompanyBackupArchiveWriteResult(
                $path,
                hash('sha256', $contents),
                strlen($contents),
                3,
            ),
        );
        self::assertTrue($this->jobs->startChecking($backupId));
        self::assertTrue($this->jobs->startSnapshotting($backupId));
        self::assertTrue($this->jobs->startPackaging($backupId));
        self::assertTrue($this->jobs->complete(
            $backupId,
            $artifact,
            new DateTimeImmutable('2026-09-02T10:00:00+00:00'),
            new CompanyBackupJobRetentionPolicy(24),
        ));

        return [$backupId, $artifact, $storage, $path, $root];
    }

    /** @param array<string,mixed> $job */
    private function artifactFromJob(array $job): CompanyBackupStoredArtifact
    {
        return new CompanyBackupStoredArtifact(
            (int) $job['supplier_id'],
            (string) $job['backup_id'],
            (string) $job['artifact_path'],
            (string) $job['artifact_name'],
            (int) $job['artifact_bytes'],
            (string) $job['artifact_sha256'],
            (int) $job['artifact_entry_count'],
        );
    }

    private function assertPasswordUnavailable(
        string $backupId,
        bool $expectNullInDatabase = true,
    ): void {
        try {
            $this->jobs->passwordForWorker($backupId);
            self::fail('Koncový job nesmí nadále zpřístupnit heslo archivu.');
        } catch (CompanyBackupJobException $e) {
            self::assertSame('password_unavailable', $e->errorCode);
        }

        $statement = $this->db->pdo()->prepare(
            'SELECT password_ciphertext FROM company_backup_jobs WHERE backup_id = ?',
        );
        $statement->execute([$backupId]);
        if ($expectNullInDatabase) {
            self::assertNull($statement->fetchColumn());
        } else {
            self::assertIsString($statement->fetchColumn());
        }
    }

    /** @param callable():mixed $operation */
    private function assertDownloadError(callable $operation, string $code): void
    {
        try {
            $operation();
            self::fail("Stažení mělo skončit chybou {$code}.");
        } catch (CompanyBackupDownloadException $e) {
            self::assertSame($code, $e->errorCode);
        }
    }

    /** @param callable():mixed $operation */
    private function assertManagementError(callable $operation, string $code): void
    {
        try {
            $operation();
            self::fail("Správa jobu měla skončit chybou {$code}.");
        } catch (CompanyBackupManagementException $e) {
            self::assertSame($code, $e->errorCode);
        }
    }

    private function tableExists(PDO $pdo, string $table): bool
    {
        $statement = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.tables'
            . ' WHERE table_schema = DATABASE() AND table_name = ?',
        );
        $statement->execute([$table]);
        return (int) $statement->fetchColumn() === 1;
    }

    private function scalarInt(PDO $pdo, string $sql): int
    {
        $statement = $pdo->query($sql);
        if ($statement === false) {
            return 0;
        }
        $value = $statement->fetchColumn();
        return $value === false ? 0 : (int) $value;
    }

    private function createSupplier(
        PDO $pdo,
        string $name,
        string $email,
        int $countryId,
        int $currencyId,
        int $vatRateId,
    ): int {
        $statement = $pdo->prepare(
            'INSERT INTO supplier ('
            . 'company_name, street, city, zip, country_id, email,'
            . ' default_currency_id, default_vat_rate_id'
            . ') VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
        );
        $statement->execute([
            $name,
            'Testovací 1',
            'Praha',
            '11000',
            $countryId,
            $email,
            $currencyId,
            $vatRateId,
        ]);
        return (int) $pdo->lastInsertId();
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $directory . '/' . $entry;
            if (is_dir($path) && !is_link($path)) {
                $this->removeDirectory($path);
            } else {
                @chmod($path, 0640);
                @unlink($path);
            }
        }
        @rmdir($directory);
    }
}
