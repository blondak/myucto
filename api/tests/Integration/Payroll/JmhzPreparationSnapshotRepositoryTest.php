<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\JmhzPreparationSnapshotRepository;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzPreparationSnapshotException;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzPreparationSnapshotService;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class JmhzPreparationSnapshotRepositoryTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private JmhzPreparationSnapshotRepository $repository;
    private JmhzPreparationSnapshotService $service;
    private int $supplierId;
    private int $runId;
    private int $revisionId;

    protected function setUp(): void
    {
        $container = Bootstrap::buildContainer();
        $db = $container->get(Connection::class);
        self::assertInstanceOf(Connection::class, $db);
        if (!$db->hasTable('payroll_jmhz_preparation_snapshots')) {
            $this->markTestSkipped('Migrace 1360 neproběhla.');
        }
        $this->db = $db;
        $this->repository = new JmhzPreparationSnapshotRepository($db);
        $service = $container->get(JmhzPreparationSnapshotService::class);
        self::assertInstanceOf(JmhzPreparationSnapshotService::class, $service);
        $this->service = $service;
        $pdo = $db->pdo();
        $pdo->beginTransaction();
        $sourceSupplierId = (int) $pdo->query(
            'SELECT MIN(id) FROM supplier',
        )->fetchColumn();
        $this->supplierId = $this->createIsolatedSupplier(
            $pdo,
            $sourceSupplierId,
        );
        [$this->runId, $this->revisionId] = $this->createRevision($pdo);
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
        }
    }

    public function testCurrentApprovedPreparationIsInsertedAndImmutable(): void
    {
        $id = $this->repository->insert($this->record());
        $stored = $this->repository->find($this->supplierId, 'test', $id);
        self::assertIsArray($stored);
        self::assertSame('blocked', $stored['readiness_status']);
        self::assertSame(1, $stored['issue_count']);

        $this->expectException(PDOException::class);
        $this->db->pdo()->prepare(
            'UPDATE payroll_jmhz_preparation_snapshots
                SET issue_count = 2 WHERE supplier_id = ? AND id = ?',
        )->execute([$this->supplierId, $id]);
    }

    public function testBlockedPreparationIsEncryptedAndIdempotent(): void
    {
        $first = $this->service->freeze(
            $this->supplierId,
            $this->revisionId,
            'test',
            'synthetic-jmhz-preparation',
            null,
        );
        $second = $this->service->freeze(
            $this->supplierId,
            $this->revisionId,
            'test',
            'synthetic-jmhz-preparation',
            null,
        );

        self::assertTrue($first['created']);
        self::assertFalse($second['created']);
        self::assertSame($first['id'], $second['id']);
        self::assertSame('blocked', $first['readiness_status']);
        self::assertFalse($first['official_submission_supported']);
        self::assertNotEmpty($first['issues']);
        $statement = $this->db->pdo()->prepare(
            'SELECT snapshot_ciphertext, readiness_json
               FROM payroll_jmhz_preparation_snapshots
              WHERE supplier_id = ? AND id = ?',
        );
        $statement->execute([$this->supplierId, $first['id']]);
        $stored = $statement->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($stored);
        self::assertStringStartsWith('enc:v2:', (string) $stored['snapshot_ciphertext']);
        self::assertStringNotContainsString('entity_id', (string) $stored['readiness_json']);
        self::assertStringNotContainsString('snapshot_ciphertext', CanonicalJson::encode($first));
    }

    public function testDifferentIdempotencyKeysAreBothBoundToRequestDeduplication(): void
    {
        $first = $this->service->freeze(
            $this->supplierId,
            $this->revisionId,
            'test',
            'synthetic-jmhz-request-a',
            null,
        );
        $second = $this->service->freeze(
            $this->supplierId,
            $this->revisionId,
            'test',
            'synthetic-jmhz-request-b',
            null,
        );
        $replay = $this->service->freeze(
            $this->supplierId,
            $this->revisionId,
            'test',
            'synthetic-jmhz-request-b',
            null,
        );

        self::assertTrue($first['created']);
        self::assertFalse($second['created']);
        self::assertFalse($replay['created']);
        self::assertSame($first['id'], $second['id']);
        self::assertSame($first['id'], $replay['id']);
        $statement = $this->db->pdo()->prepare(
            'SELECT COUNT(*)
               FROM payroll_jmhz_preparation_idempotency_claims
              WHERE supplier_id = ? AND environment = "test"
                AND preparation_snapshot_id = ?'
        );
        $statement->execute([$this->supplierId, $first['id']]);
        self::assertSame(2, (int) $statement->fetchColumn());
    }

    public function testIdempotencyClaimRejectsDifferentSourceRevision(): void
    {
        $this->service->freeze(
            $this->supplierId,
            $this->revisionId,
            'test',
            'synthetic-jmhz-stable-scope',
            null,
        );
        $otherRevisionId = $this->createSecondRevision(
            $this->db->pdo(),
            $this->runId,
        );

        try {
            $this->service->freeze(
                $this->supplierId,
                $otherRevisionId,
                'test',
                'synthetic-jmhz-stable-scope',
                null,
            );
            self::fail('Stejný idempotency klíč nesmí změnit zdrojovou revizi.');
        } catch (JmhzPreparationSnapshotException $exception) {
            self::assertSame(
                'jmhz_preparation_idempotency_scope_mismatch',
                $exception->validationCode,
            );
        }
    }

    public function testUnknownRevisionReturnsDomainErrorWithoutClaim(): void
    {
        $key = 'synthetic-jmhz-unknown-revision';
        try {
            $this->service->freeze(
                $this->supplierId,
                PHP_INT_MAX,
                'test',
                $key,
                null,
            );
            self::fail('Neexistující revize musí být odmítnuta doménově.');
        } catch (JmhzPreparationSnapshotException $exception) {
            self::assertSame('jmhz_revision_not_found', $exception->validationCode);
        }
        $statement = $this->db->pdo()->prepare(
            'SELECT COUNT(*)
               FROM payroll_jmhz_preparation_idempotency_claims
              WHERE supplier_id = ? AND environment = "test"
                AND idempotency_key_hash = ?',
        );
        $statement->execute([
            $this->supplierId,
            hash('sha256', $key, true),
        ]);
        self::assertSame(0, (int) $statement->fetchColumn());
    }

    public function testInsertGuardRejectsMismatchedPeriod(): void
    {
        $record = $this->record();
        $record['period_start'] = '2026-08-01';

        $this->expectException(PDOException::class);
        $this->expectExceptionMessage('current approved revision');
        $this->repository->insert($record);
    }

    /** @return array<string,mixed> */
    private function record(): array
    {
        $manifest = CanonicalJson::encode([
            'schema_reference' => 'synthetic-jmhz-preparation-manifest.v1',
        ]);
        $readiness = CanonicalJson::encode([
            'schema_reference' => 'payroll-jmhz-preparation-readiness.v1',
            'status' => 'blocked',
            'issue_count' => 1,
            'issues' => [[
                'code' => 'jmhz_synthetic_blocker',
                'entity_type' => 'revision',
                'count' => 1,
                'attribute_ids' => [],
            ]],
            'official_submission_supported' => false,
        ]);
        return [
            'supplier_id' => $this->supplierId,
            'environment' => 'test',
            'run_id' => $this->runId,
            'source_revision_id' => $this->revisionId,
            'period_start' => '2026-07-01',
            'scenario_key' => 'scenario_1',
            'builder_version' => 'jmhz-preparation-source.v1',
            'readiness_status' => 'blocked',
            'issue_count' => 1,
            'source_manifest_json' => $manifest,
            'source_manifest_sha256' => hash('sha256', $manifest),
            'readiness_json' => $readiness,
            'readiness_sha256' => hash('sha256', $readiness),
            'snapshot_ciphertext' => 'enc:v2:synthetic',
            'snapshot_fingerprint' => str_repeat('a', 64),
            'request_fingerprint' => str_repeat('b', 64),
            'idempotency_key_hash' => hash('sha256', 'synthetic-idempotency', true),
            'created_by' => null,
        ];
    }

    /** @return array{int,int} */
    private function createRevision(PDO $pdo): array
    {
        $pdo->prepare(
            'INSERT INTO payroll_runs
                (supplier_id, period_start, payment_date, status,
                 current_revision_no)
             VALUES (?, "2026-07-01", "2026-08-10", "approved", 1)',
        )->execute([$this->supplierId]);
        $runId = (int) $pdo->lastInsertId();
        $input = CanonicalJson::encode([
            'schema_version' => 'payroll-run-input.v2',
            'supplier_id' => $this->supplierId,
            'period_start' => '2026-07-01',
            'people' => [],
        ]);
        $result = CanonicalJson::encode([
            'schema_version' => 'payroll-run-result.v2',
            'source_snapshot_hash' => hash('sha256', $input),
            'people' => [],
        ]);
        $pdo->prepare(
            'INSERT INTO payroll_run_revisions
                (supplier_id, run_id, revision_no, revision_kind, status,
                 schema_version, ruleset_manifest_hash, input_snapshot_json,
                 input_snapshot_hash, result_snapshot_json,
                 result_snapshot_hash, idempotency_key_hash, approved_at)
             VALUES (?, ?, 1, "regular", "approved",
                     "payroll-run-input.v2", ?, ?, ?, ?, ?, ?, NOW())',
        )->execute([
            $this->supplierId,
            $runId,
            str_repeat('c', 64),
            $input,
            hash('sha256', $input),
            $result,
            hash('sha256', $result),
            hash('sha256', "jmhz-preparation:{$this->supplierId}", true),
        ]);
        return [$runId, (int) $pdo->lastInsertId()];
    }

    private function createSecondRevision(PDO $pdo, int $runId): int
    {
        $input = CanonicalJson::encode([
            'schema_version' => 'payroll-run-input.v2',
            'supplier_id' => $this->supplierId,
            'period_start' => '2026-07-01',
            'people' => [],
        ]);
        $result = CanonicalJson::encode([
            'schema_version' => 'payroll-run-result.v2',
            'source_snapshot_hash' => hash('sha256', $input),
            'people' => [],
        ]);
        $pdo->prepare(
            'INSERT INTO payroll_run_revisions
                (supplier_id, run_id, revision_no, revision_kind, status,
                 schema_version, ruleset_manifest_hash, input_snapshot_json,
                 input_snapshot_hash, result_snapshot_json,
                 result_snapshot_hash, idempotency_key_hash, approved_at)
             VALUES (?, ?, 2, "regular", "approved",
                     "payroll-run-input.v2", ?, ?, ?, ?, ?, ?, NOW())',
        )->execute([
            $this->supplierId,
            $runId,
            str_repeat('d', 64),
            $input,
            hash('sha256', $input),
            $result,
            hash('sha256', $result),
            hash('sha256', "jmhz-preparation-second:{$this->supplierId}", true),
        ]);
        $revisionId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'UPDATE payroll_runs SET current_revision_no = 2
              WHERE supplier_id = ? AND id = ?',
        )->execute([$this->supplierId, $runId]);
        return $revisionId;
    }
}
